<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * Hosts the /team route. The actual data work happens in the
 * App\Livewire\TeamLoad component mounted by the wrapper view.
 */
class TeamLoadController extends Controller
{
    public function index(): View
    {
        return view('team.index');
    }
}
