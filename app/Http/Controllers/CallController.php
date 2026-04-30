<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CallLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cross-conversation call feed (page at /calls).
 *
 * Visibility mirrors the inbox:
 *   - users with conversations.view_all see all calls in their account
 *   - users with conversations.view_assigned see calls only in conversations
 *     assigned to them
 *
 * Filterable by direction, status, and date range via query params.
 */
class CallController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $query = CallLog::query()->with(['contact', 'conversation', 'whatsappInstance', 'placedBy']);

        if ($user->can('conversations.view_all')) {
            // Account-wide visibility — restrict to calls whose conversation
            // belongs to the current user's account.
            $query->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id));
        } else {
            // Agent visibility — only conversations assigned to me
            $query->whereHas('conversation', fn ($q) => $q->where('assigned_to_user_id', $user->id));
        }

        if ($direction = $request->query('direction')) {
            if (in_array($direction, ['inbound', 'outbound'], true)) {
                $query->where('direction', $direction);
            }
        }

        if ($status = $request->query('status')) {
            if (in_array($status, ['ended', 'missed', 'declined', 'failed'], true)) {
                $query->where('status', $status);
            }
        }

        $calls = $query->latest()->paginate(50);

        return view('calls.index', [
            'calls' => $calls,
            'currentDirection' => $request->query('direction'),
            'currentStatus' => $request->query('status'),
        ]);
    }
}
