<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ImportGoogleCalendar;
use App\Models\GoogleConnection;
use App\Models\Meeting;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleCalendarClient;
use App\Services\Google\GoogleCalendarSync;
use App\Services\Google\GoogleConfig;
use App\Services\Google\GoogleOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Connect, test, refresh and disconnect the signed-in person's Google Calendar. The OAuth client itself (ID and
 * secret) is configured once in Settings -> Google Calendar; each person then connects their own account.
 */
class GoogleCalendarController extends Controller
{
    private const RETURN_TARGETS = ['meetings', 'settings'];

    /** Send the person to Google's consent screen. */
    public function connect(Request $request, GoogleOAuth $oauth): RedirectResponse
    {
        $return = $this->returnTarget($request->query('return'));

        if (! GoogleConfig::isConfigured()) {
            return $this->back($return)->with('error', 'Google Calendar is not set up yet. An administrator must enter the Google Client ID and secret in Settings -> Google Calendar first.');
        }

        $state = Str::random(40);
        $request->session()->put('google_oauth', ['state' => $state, 'return' => $return, 'user' => $request->user()->id]);

        return redirect()->away($oauth->authorizationUrl($state, $request->user()->email));
    }

    /** Google sends the person back here with a one-time code. */
    public function callback(Request $request, GoogleOAuth $oauth): RedirectResponse
    {
        $saved = (array) $request->session()->pull('google_oauth', []);
        $return = $this->returnTarget($saved['return'] ?? null);

        // The state must match what we issued to this very person, or the request is not ours to act on.
        if (! isset($saved['state'], $saved['user'])
            || ! is_string($request->query('state'))
            || ! hash_equals((string) $saved['state'], $request->query('state'))
            || (int) $saved['user'] !== (int) $request->user()->id) {
            return $this->back($return)->with('error', 'That Google sign-in could not be verified. Please press Connect Google Calendar again.');
        }

        if ($request->query('error') !== null) {
            $denied = $request->query('error') === 'access_denied';

            return $this->back($return)->with($denied ? 'info' : 'error', $denied
                ? 'Google Calendar was not connected: you chose not to allow access.'
                : 'Google could not connect the calendar ('.Str::limit((string) $request->query('error'), 60, '').').');
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return $this->back($return)->with('error', 'Google did not send back a sign-in code. Please try again.');
        }

        try {
            $tokens = $oauth->exchangeCode($code);
        } catch (GoogleApiException $e) {
            return $this->back($return)->with('error', $e->getMessage());
        }

        if (! str_contains($tokens['scopes'], GoogleConfig::CALENDAR_SCOPE)) {
            return $this->back($return)->with('error', 'Google Calendar was not connected: the calendar permission was not ticked on Google\'s screen. Press Connect again and allow it.');
        }

        $existing = GoogleConnection::query()->where('user_id', $request->user()->id)->first();
        $refresh = $tokens['refresh_token'] ?: $existing?->refresh_token;
        if (blank($refresh)) {
            return $this->back($return)->with('error', 'Google connected but did not give a long-lived permission, so meetings could not keep syncing. Remove BlastIQ at myaccount.google.com/permissions, then connect again.');
        }

        $connection = GoogleConnection::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'google_email' => $tokens['email'],
                'access_token' => $tokens['access_token'],
                'refresh_token' => $refresh,
                'expires_at' => $tokens['expires_at'],
                'scopes' => $tokens['scopes'],
                'status' => GoogleConnection::STATUS_ACTIVE,
                'last_error' => null,
            ],
        );

        // Fill the calendar in the background (a large calendar must not hold up this request).
        ImportGoogleCalendar::dispatch($connection->id);

        return $this->back($return)->with('success', 'Google Calendar connected as '.$connection->google_email.'. Your events are loading and will appear on the calendar within a minute (use Sync now to refresh).');
    }

    /** "Test connection": a real read against the person's Google calendar, reported in plain words. */
    public function test(Request $request): RedirectResponse
    {
        $return = $this->returnTarget($request->input('return'));

        if (! GoogleConfig::isConfigured()) {
            return $this->back($return)->with('error', 'Not set up: enter the Google Client ID and secret in Settings -> Google Calendar and save.');
        }
        $connection = GoogleConnection::query()->where('user_id', $request->user()->id)->first();
        if ($connection === null) {
            return $this->back($return)->with('error', 'Client ID and secret are saved, but you have not connected a Google account yet. Press Connect Google Calendar.');
        }

        try {
            $probe = GoogleCalendarClient::for($connection)->probe();
        } catch (GoogleApiException $e) {
            $connection->forceFill(['last_error' => Str::limit($e->getMessage(), 300, '')])->save();

            return $this->back($return)->with('error', 'Connection test failed: '.$e->getMessage());
        }

        $connection->forceFill(['last_error' => null])->save();
        $mirrored = Meeting::query()->where('organizer_id', $request->user()->id)->where('external_provider', 'google')->where('imported', true)->scheduled()->count();

        return $this->back($return)->with('success', 'Connection works. Signed in as '.$connection->google_email
            .($probe['timeZone'] ? ' (calendar time zone '.$probe['timeZone'].')' : '')
            .'. '.$mirrored.' '.Str::plural('meeting', $mirrored).' from Google currently on your BlastIQ calendar.');
    }

    /** Read the calendar now instead of waiting for the next scheduled sync. */
    public function sync(Request $request, GoogleCalendarSync $sync): RedirectResponse
    {
        $return = $this->returnTarget($request->input('return'));
        $connection = GoogleConnection::query()->where('user_id', $request->user()->id)->first();
        if ($connection === null) {
            return $this->back($return)->with('error', 'Connect Google Calendar first.');
        }

        try {
            $counts = $sync->import($connection);
        } catch (GoogleApiException $e) {
            $connection->forceFill(['last_error' => Str::limit($e->getMessage(), 300, '')])->save();

            return $this->back($return)->with('error', 'Sync failed: '.$e->getMessage());
        }

        return $this->back($return)->with('success', 'Synced with Google Calendar: '.$counts['created'].' new, '.$counts['updated'].' updated, '.$counts['cancelled'].' removed.');
    }

    public function disconnect(Request $request, GoogleOAuth $oauth): RedirectResponse
    {
        $return = $this->returnTarget($request->input('return'));
        $connection = GoogleConnection::query()->where('user_id', $request->user()->id)->first();

        if ($connection !== null) {
            $oauth->revoke($connection);
            // Mirrored events are only copies; take them off the calendar. Meetings made in BlastIQ stay.
            Meeting::query()->where('organizer_id', $request->user()->id)->where('external_provider', 'google')->where('imported', true)->delete();
            $connection->delete();
        }

        return $this->back($return)->with('success', 'Google Calendar disconnected. Meetings imported from Google were removed from this calendar; meetings you made here are untouched.');
    }

    private function returnTarget(mixed $value): string
    {
        return is_string($value) && in_array($value, self::RETURN_TARGETS, true) ? $value : 'meetings';
    }

    private function back(string $target): RedirectResponse
    {
        return redirect()->route($target === 'settings' ? 'settings.index' : 'meetings.index');
    }
}
