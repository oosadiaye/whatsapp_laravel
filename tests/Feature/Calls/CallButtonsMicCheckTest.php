<?php

declare(strict_types=1);

namespace Tests\Feature\Calls;

use App\Livewire\CallInsightsPanel;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every button that starts an outbound call must ask for the microphone BEFORE it
 * dials the customer, and must mount the call banner immediately.
 *
 * Why: the Africa's Talking SDK only asks for the microphone inside answer(), while
 * AT's call timer is already running, and it fails silently when the site is blocked.
 * So a blocked microphone looked like "rings, then nothing" and the customer was dialed
 * (and billed) anyway. And the banner that auto-answers AT's voice leg used to mount on
 * a 15s poll, after the customer had already answered.
 *
 * These buttons are inline Alpine `x-data="{ ... }"` attributes, where a single stray
 * double quote silently truncates the whole component and the button stops working
 * with no error (it has happened before). So the tests parse the REAL rendered
 * attribute instead of grepping the page.
 */
class CallButtonsMicCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    /** The decoded x-data attribute of the first element that declares $needle. */
    private function xDataContaining(string $html, string $needle): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        foreach ($dom->getElementsByTagName('*') as $element) {
            $attr = $element->getAttribute('x-data');
            if ($attr !== '' && str_contains($attr, $needle)) {
                return $attr;
            }
        }

        $this->fail("No x-data attribute containing [{$needle}] was found; the component may have been truncated.");
    }

    private function assertIntactAndSafe(string $xData): void
    {
        // Intact: a stray double quote ends the attribute early and cuts the object short.
        $this->assertSame(
            substr_count($xData, '{'),
            substr_count($xData, '}'),
            'the x-data braces are unbalanced; a stray double quote truncated the component'
        );

        // Mic check first: it must come before the request that dials the customer.
        // The dial is the request to the outbound-call URL (Quick Dial also fetches its
        // contact list earlier, so "the first fetch" would be the wrong marker).
        $this->assertSame(1, preg_match('~calls.{1,2}outbound~', $xData, $found, PREG_OFFSET_CAPTURE), 'the dial request was not found');
        $dial = $found[0][1];
        $mic = strpos($xData, 'bqEnsureMic');
        $this->assertNotFalse($mic, 'the call button never asks for the microphone');
        $this->assertLessThan($dial, $mic, 'the microphone must be checked BEFORE the customer is dialed');

        // A refusal is reported to the agent in words, not swallowed.
        $this->assertStringContainsString('bqMicMessage', $xData);
    }

    public function test_the_workspace_offers_the_voice_check_to_every_call_user(): void
    {
        // Receiving calls is exactly when this matters, so it is not limited to people who
        // can place calls (Quick Dial is). The logic lives in the unit-tested voice-check.js;
        // the view only mounts it, so a typo in the name would silently show a dead button.
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole('agent');

        $html = $this->actingAs($agent)->get(route('calls.workspace'))->assertOk()->getContent();

        $this->assertStringContainsString('Check my voice setup', $html);
        $this->assertStringContainsString('x-data="voiceCheck()"', $html);
    }

    public function test_the_conversation_call_button_checks_the_microphone_then_mounts_the_banner(): void
    {
        $admin = $this->admin();
        $conversation = Conversation::factory()->create(['assigned_to_user_id' => $admin->id]);

        $html = $this->actingAs($admin)->get(route('conversations.show', $conversation))->assertOk()->getContent();
        $xData = $this->xDataContaining($html, 'async placeCall');

        $this->assertIntactAndSafe($xData);
        $this->assertStringContainsString("Livewire.dispatch('calls:refresh')", $xData, 'the banner must mount at once, not on the 15s poll');
    }

    public function test_quick_dial_checks_the_microphone_before_dialing(): void
    {
        $admin = $this->admin();
        \App\Models\Setting::set('africastalking_username', 'test-user');
        \App\Models\Setting::set('africastalking_virtual_number', '+254700000000');
        \App\Models\Setting::setEncrypted('africastalking_api_key', 'test-key');

        $html = $this->actingAs($admin)->get(route('calls.workspace'))->assertOk()->getContent();
        $xData = $this->xDataContaining($html, 'async placeCall');

        $this->assertIntactAndSafe($xData);
    }

    public function test_the_call_back_button_checks_the_microphone_then_mounts_the_banner(): void
    {
        $admin = $this->admin();
        $conversation = Conversation::factory()->create(['assigned_to_user_id' => $admin->id]);
        $call = CallLog::factory()->create(['conversation_id' => $conversation->id, 'contact_id' => $conversation->contact_id]);

        $html = Livewire::actingAs($admin)
            ->test(CallInsightsPanel::class, ['callId' => $call->id])
            ->html();
        $xData = $this->xDataContaining($html, 'async callBack');

        $this->assertIntactAndSafe($xData);
        $this->assertStringContainsString("Livewire.dispatch('calls:refresh')", $xData);
    }
}
