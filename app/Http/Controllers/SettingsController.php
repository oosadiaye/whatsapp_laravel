<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    /**
     * Keys whose database value is ciphertext (encrypted via Crypt::encryptString
     * in {@see update()}). The form NEVER pre-fills these — it just shows a
     * "•••" placeholder when one is present — so the view only needs a boolean
     * "is set" signal, not the value. We replace the ciphertext with the literal
     * string '1' here so $settings[$key] stays truthy in the blade without ever
     * leaking ciphertext into the rendered HTML.
     *
     * If a future field reads $settings[<encrypted_key>] expecting plaintext,
     * it will get '1' instead — failing loudly instead of silently echoing
     * encrypted bytes into the page source.
     */
    private const ENCRYPTED_SETTING_KEYS = ['africastalking_api_key'];

    public function index(): View
    {
        $settings = Setting::all()->pluck('value', 'key');

        foreach (self::ENCRYPTED_SETTING_KEYS as $encryptedKey) {
            if ($settings->has($encryptedKey)) {
                $settings[$encryptedKey] = '1';
            }
        }

        return view('settings.index', ['settings' => $settings]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'default_rate_per_minute' => ['nullable', 'integer', 'min:1', 'max:60'],
            'default_delay_min' => ['nullable', 'integer', 'min:1', 'max:30'],
            'default_delay_max' => ['nullable', 'integer', 'min:1', 'max:60'],
            'round_robin_cap_per_agent' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'africastalking_username' => ['nullable', 'string', 'max:64'],
            'africastalking_api_key' => ['nullable', 'string', 'min:10', 'max:512'],
            'africastalking_virtual_number' => ['nullable', 'string', 'regex:/^\+\d{10,15}$/'],
            'africastalking_rate_per_minute_kobo' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        foreach ($validated as $key => $value) {
            if ($value === null || $value === '') {
                // Don't overwrite existing values with empty input — protects the API key
                // password field's "leave blank to keep existing" UX.
                continue;
            }

            if ($key === 'africastalking_api_key') {
                Setting::setEncrypted($key, (string) $value);
            } else {
                Setting::set($key, (string) $value);
            }
        }

        return redirect()->back()->with('success', 'Settings updated successfully.');
    }
}
