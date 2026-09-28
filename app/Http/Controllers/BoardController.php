<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreBoardRequest;
use App\Http\Requests\UpdateBoardRequest;
use App\Models\Board;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BoardController extends Controller
{
    public function index(Request $request): View
    {
        // Single-tenant — boards are company-wide (same convention as
        // CampaignController::index(); `user_id` is creator audit metadata).
        //
        // Archived boards are hidden by default because the list is "what is the
        // team working on", and an archive is a statement that this is not that
        // any more. They are one query away, not deleted: ?archived=1.
        $showArchived = $request->boolean('archived');

        $boards = Board::query()
            ->when(! $showArchived, fn ($q) => $q->live())
            ->when($showArchived, fn ($q) => $q->archived())
            ->withCount('tasks')
            ->latest('id')
            ->get();

        return view('boards.index', [
            'boards' => $boards,
            'showArchived' => $showArchived,
            'archivedCount' => $showArchived ? null : Board::query()->archived()->count(),
        ]);
    }

    /**
     * /tasks convenience entry point — renders the first board in the
     * workspace.
     *
     * A GET must never write. If the workspace has no boards yet we send the
     * user somewhere useful (create form when they may create, otherwise the
     * board list) rather than silently inserting a starter row — otherwise a
     * crawler's GET, or a user holding only tasks.view, would create boards
     * they lack permission to manage.
     *
     * `live()` matters here more than anywhere else: without it, archiving the
     * oldest board would make /tasks drop everyone onto an archived board.
     */
    public function showDefault(): RedirectResponse|View
    {
        $board = Board::query()->live()->orderBy('id')->first();

        if ($board === null) {
            if (auth()->user()?->can('tasks.create')) {
                return redirect()
                    ->route('boards.create')
                    ->with('info', 'Create your first board to start tracking tasks.');
            }

            return redirect()
                ->route('boards.index')
                ->with('info', 'No task boards have been created yet.');
        }

        return view('boards.show', ['board' => $board]);
    }

    public function show(Board $board): View
    {
        return view('boards.show', ['board' => $board]);
    }

    public function create(): View
    {
        return view('boards.create');
    }

    public function store(StoreBoardRequest $request): RedirectResponse
    {
        try {
            $board = Board::create([
                ...$request->validated(),
                'user_id' => auth()->id(),
            ]);
        } catch (QueryException $e) {
            // The unique index is the source of truth; validation can still race
            // on concurrent requests, so a DB rejection becomes a slug error
            // rather than a 500.
            if ($this->isDuplicateBoardSlug($e)) {
                return back()
                    ->withInput()
                    ->withErrors(['slug' => 'That board key is already in use.']);
            }

            throw $e;
        }

        return redirect()
            ->route('boards.show', $board)
            ->with('success', 'Board created.');
    }

    public function edit(Board $board): View
    {
        return view('boards.edit', ['board' => $board]);
    }

    public function update(UpdateBoardRequest $request, Board $board): RedirectResponse
    {
        try {
            $board->update($request->validated());
        } catch (QueryException $e) {
            if ($this->isDuplicateBoardSlug($e)) {
                return back()
                    ->withInput()
                    ->withErrors(['slug' => 'That board key is already in use.']);
            }

            throw $e;
        }

        return redirect()
            ->route('boards.show', $board)
            ->with('success', 'Board updated.');
    }

    /**
     * Whether the exception is the boards slug uniqueness violation. Tested on
     * the SQLSTATE rather than the message so it survives driver wording.
     */
    private function isDuplicateBoardSlug(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? '');

        return in_array($state, ['23000', '23505'], true);
    }

    /**
     * Stand up a fresh board that copies the source's name, slug stem and
     * description — and nothing else.
     *
     * Duplicating a board is deliberately a *template*, not a fork. Copying the
     * cards would mean deciding, per card, whether to copy assignees, watchers,
     * comments and activity history — each a separate product decision, and all
     * of them silent data proliferation the duplicator did not ask for. A board
     * with no cards is a usable starting point ("another Support board"); a board
     * that quietly clones 400 cards' worth of relationships is a surprise. The
     * source board keeps every card it had, which the test asserts directly.
     *
     * The slug is generated unique per owner and, because two people can click
     * duplicate at once, the unique index is the backstop: a collision regenerates
     * once rather than 500-ing.
     */
    public function duplicate(Board $board): RedirectResponse
    {
        $user = auth()->user();

        $attributes = [
            'user_id' => $user->id,
            'name' => $board->name.' (copy)',
            'slug' => $this->duplicateSlug($user, $board->slug),
            'description' => $board->description,
        ];

        try {
            $copy = Board::create($attributes);
        } catch (QueryException $e) {
            // The index is the source of truth; a concurrent duplicate can still
            // land the same generated slug. Regenerate once and retry.
            if ($this->isDuplicateBoardSlug($e)) {
                $copy = Board::create([
                    ...$attributes,
                    'slug' => $this->duplicateSlug($user, $board->slug.'-'.time()),
                ]);
            } else {
                throw $e;
            }
        }

        return redirect()
            ->route('boards.show', $copy)
            ->with('success', 'Board duplicated. It starts empty — duplicate copies the board, not its cards.');
    }

    /**
     * A slug unique to this owner, derived from the source's slug. Tries
     * `{slug}-copy`, then `{slug}-copy-2`, `-3`, … until one is free.
     */
    private function duplicateSlug(User $user, string $base): string
    {
        $stem = Str::slug($base).'-copy';

        if (! Board::where('user_id', $user->id)->where('slug', $stem)->exists()) {
            return $stem;
        }

        $i = 2;
        do {
            $candidate = $stem.'-'.$i++;
        } while (Board::where('user_id', $user->id)->where('slug', $candidate)->exists());

        return $candidate;
    }

    /**
     * Take a board out of circulation, keeping everything on it.
     *
     * Gated on tasks.delete rather than tasks.edit, and that is the one place
     * the choice is worth defending: archiving hides shared work from everyone
     * at once, which is the same blast radius as removing it — the difference is
     * only that it can be undone. A permission that cannot undo its own change
     * would let a manager make a mistake that only a developer could fix.
     *
     * Idempotent. Re-archiving a stale link should not move the timestamp, or
     * "archived 3 March" becomes "archived 3 March" every time somebody clicks
     * the wrong button in a stale email.
     */
    public function archive(Board $board): RedirectResponse
    {
        if ($board->archived_at === null) {
            $board->archive();
        }

        return redirect()
            ->route('boards.index')
            ->with('success', 'Board archived. Its cards and history are untouched.');
    }

    /**
     * Put an archived board back in circulation.
     *
     * Total by construction: archiving never touched the cards, so restoring
     * never has to bring anything back.
     */
    public function unarchive(Board $board): RedirectResponse
    {
        if ($board->archived_at !== null) {
            $board->restoreFromArchive();
        }

        return redirect()
            ->route('boards.show', $board)
            ->with('success', 'Board restored.');
    }

    public function destroy(Board $board): RedirectResponse
    {
        // Archive first, so the delete is always a two-step decision. The card
        // cascade below is not reversible from the UI, and a manager clearing
        // out old boards should have to mean it twice.
        //
        // Redirects to the list rather than the board, because the board is
        // about to stop existing.
        $board->archive();

        // Free the (user_id, slug) pair before soft-deleting. The unique index
        // counts soft-deleted rows, so leaving the slug intact would block the
        // owner from ever creating a new board with the same name. Mangle the
        // trashed board's slug (still recoverable — a developer restoring it can
        // rename), which releases the original slug for reuse.
        $board->update(['slug' => $board->slug.'-deleted-'.now()->timestamp]);

        $board->delete();

        return redirect()
            ->route('boards.index')
            ->with('success', 'Board deleted. It is soft-deleted, so a developer can still recover it.');
    }
}
