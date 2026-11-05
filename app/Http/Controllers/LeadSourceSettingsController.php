<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Leads\LeadSourceException;
use App\Services\Leads\LeadSourceRegistry;
use App\Support\LeadConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeadSourceSettingsController extends Controller
{
    public function index(LeadSourceRegistry $registry): View
    {
        return view('lead-sources.index', [
            'sources' => $registry->all(),
            'killSwitch' => LeadConfig::killSwitchOn(),
        ]);
    }

    public function update(Request $request, string $source, LeadSourceRegistry $registry): RedirectResponse
    {
        abort_unless($registry->has($source), 404);

        $data = $request->validate([
            'api_key' => ['required', 'string', 'min:10', 'max:512'],
        ]);

        LeadConfig::setKey($source, $data['api_key']);

        return back()->with('success', $registry->get($source)->label().' API key saved. Use "Test connection" to check it.');
    }

    public function test(string $source, LeadSourceRegistry $registry): RedirectResponse
    {
        abort_unless($registry->has($source), 404);

        $connector = $registry->get($source);

        if (! $connector->isConfigured()) {
            return back()->with('warning', 'Save an API key for '.$connector->label().' before testing.');
        }

        try {
            $connector->testConnection();
        } catch (LeadSourceException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $connector->label().' connection works.');
    }

    public function killSwitch(Request $request): RedirectResponse
    {
        $on = $request->boolean('on');

        LeadConfig::setKillSwitch($on);

        return back()->with($on ? 'warning' : 'success', $on
            ? 'Lead automation is paused. Nothing will be pulled or sent until you resume it.'
            : 'Lead automation resumed.');
    }
}
