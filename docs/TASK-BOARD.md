# Task Board — design, implementation status, and roadmap

The **Task Board** (`/tasks`, or `/boards/{id}`) is a Trello-style kanban for
the team: boards hold cards, cards move between configurable columns by
drag-and-drop, and a change pushes to every other open board while emailing the
people on the card who asked to hear about it.

This document is the operator/developer guide for what shipped, why it is built
the way it is, and what is deliberately still missing.

---

## 1. What shipped

### Data model

| table             | purpose                                                                     |
|-------------------|-----------------------------------------------------------------------------|
| `boards`          | a kanban board (name, slug, soft-deleted, archivable)                   |
| `tasks`           | a card (title, description, `status`, `position`, `priority`, `due_at`, `estimate_minutes`, `board_id`, soft-deleted) |
| `task_assignees`  | who is doing the work (composite PK on task + user)                         |
| `task_watchers`   | who asked to be kept informed (composite PK on task + user)                 |
| `task_activity`   | append-only history: who did what to which card, and when (no FKs, no `updated_at`) |
| `task_activity_notifications` | per-recipient ledger: which people an activity has already been mailed to (unique on activity + user) |
| `task_comments`   | threaded discussion on a card                                               |
| `task_statuses`   | the **columns**, editable at runtime                                        |

Ordering is `position` (integer) per column, with a unique `(board_id, status,
position)` index so two cards in the same column can never share a slot, and a
plain `(board_id, position)` index for the common "load this column" query.

### Three separate notions of "who"

`tasks.user_id` is the **creator** — audit metadata only. It is deliberately
*not* the assignee, because collapsing them makes "who raised this"
unanswerable the moment a card is handed to someone. The two pivots carry the
working relationships:

- **assignee** — who is doing it. Drives the "Only mine" filter, so the board
  can answer "what is mine" without a separate screen.
- **watcher** — who wants telling. Opt-in, and read by the notification audience
  resolver alongside owner and assignees. A watcher is not a privileged role:
  watching only controls what you are told, so it grants no access the
  permission system would not already allow.

Both pivots use a composite primary key, so the database itself prevents the
same person being attached twice and a concurrent double-toggle cannot create a
duplicate row.

### Statuses are data, not config

Columns live in `task_statuses` so a manager can add or retire a stage from
**Task Board → Statuses** (`/task-statuses`) with no deploy, no config edit and
no `config:clear`.

| column        | meaning                                                        |
|---------------|----------------------------------------------------------------|
| `name`        | display label ("In Review")                                    |
| `slug`        | machine key persisted on every card — the column's real identity |
| `color`       | one of 13 fixed tokens (`TaskStatus::COLORS`)                 |
| `position`    | left-to-right order                                            |
| `is_default`  | where new cards land; exactly one row may hold this           |
| `is_completed`| marks a "done"-like column for reporting                      |
| `wip_limit`   | optional advisory ceiling on how many cards the column should hold; `null` = none |

Three invariants are enforced in `TaskStatusController`, not in the schema,
because `tasks.status` is a denormalised string:

1. **exactly one default** — promoting one demotes the rest;
2. **the default, and any column that still holds cards, cannot be deleted or
   have its `slug` changed.** The `slug` *is* `tasks.status`, so renaming it
   under live cards would strand them in a column that no longer exists.
3. **a completed column carries no `wip_limit`**, and flagging a column
   completed clears any limit it had — see [WIP limits](#wip-limits--shipped).

`config/task-statuses.php` is retained **only** as a read-only fallback. If
`task_statuses` is empty (an install whose seeder has not run),
`TaskStatus::columns()` falls back to it so a board still renders instead of
throwing. Once the table has rows, the database is the single source of truth.

### Permissions

| permission             | grants                                            | default roles              |
|------------------------|---------------------------------------------------|----------------------------|
| `tasks.view`           | see boards and the board screen                   | agent, manager, admin, super |
| `tasks.create`         | add cards, and stand up new boards                | agent, manager, admin, super |
| `tasks.edit`           | **move/reorder** cards, rename boards             | manager, admin, super       |
| `tasks.delete`         | delete cards and boards                           | manager, admin, super       |
| `tasks.status.manage`  | add/rename/reorder/retire **columns**             | manager, admin, super       |
| `tasks.report`         | the board's numbers (3.7)                         | manager, admin, super       |

`tasks.edit` is deliberately separate from `tasks.create`: moving a card is an
edit to a shared artefact, so an agent can work their own cards without being
able to reshuffle the team's board. Board *creation* stays on `tasks.create`
(creating is creating), but renaming a board is `tasks.edit` — an agent can
start a board and then cannot silently repurpose it. Likewise
`tasks.status.manage` is withheld from agents — working a card is not the same
authority as redefining the workflow. `tasks.report` is withheld for a third
reason: the report is about the *team* (per-person load, how long work sits in a
column), which is a manager's view rather than a view of the board, and handing
it to every agent would mean handing out everyone's workload alongside the right
to create their own cards.

The card drawer's actions follow the same reasoning, and these are the lines to
change if the balance is wrong for your team:

| action                | requires                 | why                                                            |
|-----------------------|--------------------------|----------------------------------------------------------------|
| open a card           | `tasks.view`             | you already had to be able to see it to click it               |
| post a comment        | `tasks.view`             | the board is a shared communication surface; discussing a card is not a board mutation |
| watch a card          | `tasks.view`             | self-service, affects only your own notifications               |
| assign / unassign     | `tasks.create`           | putting work into the system is the same act as creating a card — an agent who can raise a card must be able to hand it to someone |
| edit the description  | `tasks.edit`             | shared content, same authority as moving the card               |
| delete a comment      | author, or `tasks.delete`| retraction, or moderation with the power to remove the card     |

Boards and cards are **company-wide** (single tenant), matching the existing
`CampaignController` / conversation convention. `user_id` on both tables is
creator/audit metadata, **not** an owner boundary.

### Authorisation invariants

- Routes are gated by `permission:` middleware.
- Livewire re-authorises in `render()` **and in every mutating action**,
  because Livewire update requests never pass route middleware. This is an
  app-wide invariant (see `CampaignStatus`).
- `selectBoard()` needs only `tasks.view` — switching columns is filtering,
  not mutation.
- Status values supplied by the browser are re-validated against
  `TaskStatus::columns()` on every drop, so a crafted payload cannot write an
  arbitrary status string, and a column an admin just retired stops accepting
  drops immediately.
- `GET /tasks` never writes. With no boards it redirects to the create screen
  (if the user may create) or the board list — a GET must not insert a row.
- Deleting a board soft-deletes its cards (`Board::booted()`); the schema's
  `onDelete('cascade')` is a *hard*-delete rule and would never fire.

### Notifications

```
card created / assigned / commented / description edited
  '-> TaskBoardChanged (ShouldBroadcast)
        ├─-> SendTaskActivityEmail (queued)  '-> only "all" subscribers
        '-> private channel boards.{id}       '-> every other open board re-renders

card dropped
  '-> TaskStatusChanged (ShouldBroadcast)
        ├─-> SendTaskStatusEmail (queued)     '-> transition subscribers
        '-> private channel boards.{id}       '-> every other open board re-renders

card deleted
  '-> TaskBoardChanged (REASON_DELETED)
        ├─-> SendTaskActivityEmail (queued)  '-> reads the soft-deleted row
        '-> private channel boards.{id}
```

The two events are split deliberately. A move has two jobs — mail the interested
people and push the board — so `TaskStatusChanged` does both. The other changes
do both too now that activity email exists. A move is delivered as
`.task.status.changed` and **never** also as `.task.board.changed`, so one drag
causes exactly one re-render rather than two. The client registers one handler
for both; the reason is in the payload so the listeners can filter on it.

- **Real-time** uses the app's existing Reverb/Echo stack. `boards/{boardId}`
  is authorised on `tasks.view`, so a private channel can never widen access
  beyond the board's own route middleware. Subscribers simply re-render — the
  component re-reads from the database and does not merge remote changes.
- **Creating** a card sends no email, and a **reorder inside the same column**
  broadcasts nothing at all. Only genuine transitions email, and only genuine
  changes broadcast, so nobody's board flickers over a no-op.

### Who gets emailed

`app/Support/TaskNotificationRecipients.php` resolves the audience for any card
event. It is the single place the rules live; the two listeners just call it.

```
        board owner  ┐
         assignees   ├─→ dedupe by id ─→ drop the actor ─→ drop inactive
         watchers    ┘                   drop empty email ─→ apply the level
```

- **Union, not list.** The three groups overlap, so a user who is the owner
  *and* an assignee *and* a watcher is collected once. Dedupe is a `keyBy('id')`,
  which makes "one email per person" true by construction instead of by
  remembering to check.
- **The actor is never emailed.** The board owner dragging their own card is the
  single most common action on the board; mailing them about it is pure noise.
  The event carries `actorId` for exactly this. It is `null` for a change made
  outside a request (console, queued job), which simply excludes nobody.
- **Inactive accounts are dropped.** Mail to a departed employee's inbox is
  useless and a deliverability risk for the sending domain.
- **One queued job per recipient.** Both listeners call `Mail::queue()`, not
  `Mail::send()` — the same per-recipient granularity as the app's existing
  `SendCampaignEmail` job — so one undeliverable address cannot fail the batch
  or cause duplicates on retry. Tests assert with `assertQueued`, not
  `assertSent`.

### The three levels

`users.task_notifications`, editable on `/profile`. `User::taskNotificationLevel()`
normalises it, so a hand-edited or null value falls back to `transitions` instead
of throwing.

| Level            | Column moves | Comments / assignment / description / delete |
| ---------------- | ------------ | -------------------------------------------- |
| `off`            | no           | no                                           |
| `transitions` *(default)* | yes | no                                  |
| `all`            | yes          | yes                                          |

`transitions` is the default deliberately: it preserves the behaviour that
shipped with the board (a move notified the owner) without retroactively mailing
anyone who had not asked for it.

- **A new card emails nobody.** `TaskBoardChanged::REASON_CREATED` is not in
  `EMAILABLE_REASONS`. The person who raised it already knows, and "a card now
  exists" is exactly the announcement that teaches a team to mute notifications.
- **A deleted card does email.** Deletion is read back with `withTrashed()` —
  the row is still there, it just has a `deleted_at` — and a card disappearing
  under someone who was working on it is worth a message.
- **Status labels in email come from the runtime table** via
  `TaskStatus::label()`, not `config('task-statuses')`. The config is only the
  empty-table fallback, so renaming a column used to leave emails announcing the
  old name.

---

## 2. Operating it

```bash
php artisan migrate                       # boards, tasks, comments, statuses, pivots, activity, notification ledger, users.task_notifications
php artisan db:seed --class=TaskStatusSeeder   # the four default columns
php artisan db:seed --class=RolesAndPermissionsSeeder  # tasks.* + tasks.status.manage
```

`DatabaseSeeder` calls both, so `migrate --seed` is enough for a fresh install.
`users.task_notifications` defaults to `transitions` at the database level, so
existing users keep the behaviour they had and need no backfill.
`task_statuses.wip_limit` is nullable with no default, so no column acquires a
ceiling on upgrade — every board starts unconstrained until a manager sets one.
`boards.archived_at` is nullable with no default too, so the upgrade leaves every
existing board live.
`task_activity` is empty on upgrade and fills in as cards are touched — there is
no backfill, because the dates it would pretend to know are not recoverable.
Cards moved before the upgrade show no history until they move again.

### Columns

Add a stage at **Task Board → Statuses**. Only the *name*, *colour*, *order* and
the two flags are freely editable; the *key* unlocks only while the column is
empty. `colour` is validated against a fixed token list, so a stored value can
never inject a class or markup.

### Real-time

The board only goes live if Reverb is running and `BROADCAST_CONNECTION=reverb`
(see `docs/REVERB-SETUP.md`). If a client is offline or on the `null`
broadcaster, the board still works — it just needs a refresh.

### The history log

`task_activity` records what happened to a card, and the drawer shows the last
20 entries. It is written by **listeners on the board events**, not by the
component, which is the whole point: the log is a property of the event, so a
future write path — a console command, an API endpoint, a second component — is
captured for free instead of having to remember to call something.

Four decisions are worth stating outright.

**No foreign keys, deliberately.** Every other table here cascades. A log must
survive what it records: an FK from `task_id` would take a card's entire history
the moment that card was hard-deleted, which is the one thing the table is for.
`board_id` is denormalised for the same reason — a per-board report still works
once the card is gone.

**No `updated_at`.** There is no update path in the app, and a timestamp column
that is never written is a lie waiting to be believed by a report. `TaskActivity`
sets `UPDATED_AT = null` and a test asserts the column is absent from the schema,
so the guarantee is checked rather than promised in a comment.

**The recorders are synchronous, the notification listeners are not.** The
drawer renders history in the same Livewire response that performed the action,
so a queued write would show someone their own comment missing until the worker
drained — the sort of "it didn't save" that makes people click twice. One extra
`INSERT` buys read-your-writes. The mail listeners stay queued because nobody is
staring at a spinner waiting for an email.

**An unrecognised event reason is dropped, not guessed at.** If a future reason
is added to `TaskBoardChanged` and the recorder is not updated, the log has a
gap. That is the right failure: a gap is visible, whereas filing it under
"updated the card details" would be a confident lie sitting in a record meant to
be evidence.

The granularity is coarser than it could be in one place and *not* in another,
and the difference is deliberate. One `reason` still covers both "a comment was
added" and "an assignee was removed", because `TaskBoardChanged` is the board's
*broadcast* contract — splitting those would make every realtime consumer grow a
case for a distinction only the history cares about. Assignment entries snapshot
the resulting user ids, so the entry can still explain itself after the assignees
have moved on.

What the log *does* separate is anything a recipient would be misled by. A
comment retraction is `comment_removed`, not `commented`, and a deadline change
is `triage_updated`, not `updated`. That split came from a real bug: both were
reusing an existing reason, so deleting a comment mailed every subscriber
"Card title: new comment", and moving a due date was announced as "updated the
description". An audit trail that reports a retraction as an arrival is worse
than no trail, because it stops being evidence.

`test_every_emailable_reason_is_recorded_in_the_log` closes the authoring trap.
The recorder drops reasons it does not recognise (a visible gap, by design), so
adding a reason to `TaskBoardChanged` and forgetting the recorder would otherwise
produce a silent hole. The test walks `EMAILABLE_REASONS` and asserts each one
produces exactly one row, so the mistake fails the suite.

---
## 3. Known gaps and the roadmap

**Already shipped** since the first draft of this document: assignees and
watchers (3.1), the card detail drawer with comments and description editing
(3.3), the notification policy — preference levels, the owner/assignee/watcher
audience, dedupe and actor exclusion (3.2) — due dates, priority, estimates
with overdue styling and column sorting (3.4), the per-card activity log,
advisory WIP limits and board archiving (3.5), and board reporting (3.7). What
follows is what is still missing, ordered by value, with the design decision
each one needs.

### 3.2 Notification policy — shipped, including durable idempotency

The audience, the three preference levels, dedupe and actor exclusion all landed
(see [Who gets emailed](#who-gets-emailed)), and so did the durable idempotency
key that was the last gap here:

- **A retried listener cannot re-mail anyone.** Both mail listeners are queued
  and both fan out in a loop over recipients, so a throw partway round that loop
  retries the entire job — and without a guard everyone already handled gets a
  second copy. This was not hypothetical: it is the most likely way a duplicate
  email actually occurs in this app.
- **`task_activity.event_key`** is a UUID minted once, when the event is
  constructed. The synchronous recorder writes it with the log row; the queued
  mail listener looks the row up by it. Correlation is therefore exact rather
  than inferred — reading a key off another listener would depend on
  registration order, and "the newest matching row" is racy the moment two
  people touch one card at once.
- **`task_activity_notifications`** is a per-recipient ledger, one row per
  (activity, user), with a unique index on that pair. The index *is* the
  guarantee: a second attempt collides instead of duplicating, and the collision
  is caught rather than allowed to fail the job.
- **A missing log row still sends.** If no activity row can be found there is
  nothing to dedupe against, and the mail goes out anyway. Bookkeeping must never
  be able to silence a product feature.

The guard is per activity, not per person. A watcher told twice about two real
moves has been told correctly, and a move made twice by hand is two genuinely
distinct events that produce two notifications — the honest count. Dedupe does
not quietly become "one email per card, forever".

**What this is not:** exactly-once delivery. The ledger row is written *after* the
mailable is handed to the queue, so a crash in that window duplicates. The
ordering is deliberate — claiming the row first would close that window but open
a worse one, a failure while queueing leaving a "sent" that never sent, and the
retry skipping it forever. A lost notification is worse than a duplicate, because
nobody knows to go looking for it. See `TaskNotificationLedger` for the full
reasoning.

Still out:

- **No Slack/WhatsApp sink.** The listeners are the only thing that would need
  to change, but the audience rules were built for one channel at a time and
  have not been tested against a second.

### 3.3 Card detail — shipped

The drawer has description, assignees, watchers, comments, "Only mine", and the
triage fields from 3.4.

- Watcher toggling is **strictly self-service as of 2026-09-26**: `toggleWatcher()`
  accepts only the authenticated user's own id and returns 403 for anyone else's,
  and the drawer is a single "Notify me about updates to this card" checkbox rather
  than a list of people. Watching stays a notification preference, not an access
  grant, so a viewer can never set someone else's alerts. A second channel (the
  WhatsApp/Slack fan-out from 3.2) is the natural extension point, not a reopen of
  this gate.

### 3.4 Due dates, priority — shipped; labels — not shipped

`tasks` gained `due_at`, `priority` and `estimate_minutes`, all edited from the
drawer rather than as inline card controls, plus overdue styling and a sort
selector.

- **Priority is a 1–4 integer rank, not a string enum.** Its only real job is to
  be sorted, and `ORDER BY` on a string enum puts `urgent` between `normal` and
  `low` without a `CASE` expression that has to be kept in sync with the
  constant list by hand. `Task::PRIORITY_LABELS` is the single source for both
  the labels and the `Rule::in()` validation. `priorityLabel()` tolerates an
  unrecognised stored value rather than rendering blank.
- **`estimate_minutes`, not `estimate`.** The unit is in the name because a bare
  "estimate" column invites one person storing hours and the next storing days.
- **`due_at` is nullable and means nullable.** "No deadline" is a real state,
  not the epoch. An empty `datetime-local` posts `''`, which is stored as null.
- **A card in a *completed* column is never overdue,** however old its deadline.
  Marking finished work red is how a board stops being believed. The completed
  slugs come from `TaskStatus::completedSlugs()`.
- **Overdue is computed in `render()`, not the query,** because
  `TaskStatus::columns()` is a query and calling it per card would make a
  50-card board 50 queries slower. The slugs are derived from the already-loaded
  columns and passed to both `isOverdue()` and the `overdue` scope.
- **Sorting is a view, never a write.** `manual` reads `position`; `due` and
  `priority` reorder by their own column and leave the stored positions
  untouched, so switching back does not lose the arrangement the team built by
  dragging. A drop in a sorted view therefore **changes the column but not the
  order** — honouring a drop index that the screen is not sorted by would
  silently scramble the manual order. The banner above the board says so.
- **The sort select uses `wire:change`, not `wire:model`.** `wire:model` would
  write the property directly and bypass `setSortMode()`'s validation, leaving
  two paths to set a value that decides the `ORDER BY` on every render.
- **Not shipped:** labels/tags. A `task_labels` pivot plus an inline picker is a
  third relationship on a drawer that already has description, assignees,
  watchers, comments, history and triage. The overdue filter and sort selector
  are what make the two new fields usable; tags have no equivalent payoff yet.

### 3.5 Board ergonomics

- Per-board status sets. Today columns are global, which is right for a
  single-team app and wrong the moment a board needs a different vocabulary.
  This is also the blocker on *per-board* WIP limits — see below.
- **Board templates / duplicate board — shipped.** `POST /boards/{board}/duplicate`
  (`tasks.create`) stands up a fresh board carrying the source's name, slug stem
  and description, and nothing else. It is deliberately a *template*, not a fork:
  copying the cards would drag assignees, watchers, comments and activity history
  along silently, so the source keeps every card and the copy starts empty. The
  duplicate's slug is generated unique per owner and, because two people can click
  duplicate at once, the unique `(user_id, slug)` index is the backstop — a
  collision regenerates once rather than 500-ing. `tests/Feature/BoardDuplicateTest.php`
  asserts both the no-fork and the slug-collision behaviour.

#### Board archiving — shipped

`boards.archived_at`, nullable. Archived boards leave the working list and the
board switcher; everything on them — cards, history, activity log, slug — is
untouched, and restoring is one action.

Deleting a board was already a soft delete, and deleting a card was reversible
from the drawer, so the asymmetry was not storage: it was that **nothing in the
UI could bring a board or its cards back**. `withTrashed()` is a developer's
escape hatch, not a recovery plan, and "the board with 400 cards is gone" is not
a state a manager should be able to reach in one click. So the fix is not a
trash screen for everything — it is that the reversible action is the one the UI
offers first.

- **Archiving does not cascade.** Deleting a board soft-deletes every card on it
  (see `Board::booted()`); archiving must not, because then restoring would have
  to bring back hundreds of cards and anything missed stays lost. The test suite
  asserts the no-cascade directly rather than inferring it from a restore.
- **Archive and delete both take `tasks.delete`.** Archiving hides shared work
  from everyone at once, which is the same blast radius as removing it — the
  difference is only that it can be undone. A permission that cannot undo its
  own change would let a manager make a mistake only a developer could fix.
- **An archived board still renders at its own URL**, behind a banner, rather
  than redirecting. A stale bookmark should land somewhere that says why the
  board is not in the list, not somewhere that silently bounces.
- **It is hidden from the *default* landing board.** `live()` is applied in
  `BoardController::showDefault()` and in `TaskBoard::mount()` as well as in the
  listings, because the failure mode without it is quietly nasty: archive the
  oldest board and `/tasks` drops the whole team onto an archived one.
- **Archiving is idempotent.** Re-archiving from a stale link does not move the
  timestamp, so "archived 3 March" keeps meaning 3 March.
- **An archived board keeps its slug**, the same as a soft-deleted one does
  today, so you cannot create a new board with an archived board's key. That is
  deliberate — it is what makes restoring total — and the way out is to rename
  or unarchive first.

#### WIP limits — shipped

`task_statuses.wip_limit`, nullable, set from Settings. A column header shows
`4/3` when a limit exists, amber when the column is over, and nothing at all
when no limit is set — so a board nobody has constrained looks exactly as it
did before. Moving or creating a card into an over-limit column raises a
dismissible notice saying so.

**The limit is advisory and never blocks a move.** That is the whole design
decision, and it is the kind that looks like an oversight and gets "fixed"
later, so the reasoning is recorded in `TaskStatusController` as well as here:

- A hard limit is **unrecoverable when set below current occupancy**. The column
  is already over, nobody may add to it, and the only way out is an admin
  noticing and editing the number. A limit meant to prompt a conversation
  becomes a locked door.
- It **punishes whoever reports the problem**. With Review capped at 3 and four
  cards in it, a hard limit blocks filing the fifth card — which is exactly the
  card that documents that Review is overflowing.
- The job of a WIP limit is to make a pile-up visible **at the moment it grows**,
  so somebody finishes work instead of starting more. That is a prompt, not a
  gate. Trello and Jira both ship warn-only for the same reason.

Three details that follow from taking it seriously:

- **The count is unfiltered.** The badge and the notice use a separate grouped
  count of the whole column, not the visible card list. Otherwise "only mine"
  would make a nine-card Review column read `2/3` — a filtered subset is a much
  friendlier number than the real one, and the friendlier one is the one that
  defeats the feature.
- **A finished column cannot carry a limit** — "at most 3 in Done" is a
  misconfiguration, and a permanently red Done column teaches people to ignore
  the badge everywhere else. Setting one is rejected with an explanation rather
  than silently dropped, and flagging a column completed *clears* any stored
  limit so re-opening it later does not resurrect a ceiling set months ago.
- **`0` is rejected** as a typo. There is no such thing as "nothing may ever
  enter this column".

The limit is per column and company-wide, matching `task_statuses` itself. A
per-board ceiling needs per-board column sets, which is the first bullet above
— an app that gave each board its own vocabulary would hang the limit off that
instead of off the shared status.

#### Activity history — shipped

`task_activity` is an append-only log of what happened to a card, and the drawer
renders the last 20 entries. See [The history log](#the-history-log) for the
design decisions; 3.7 reads the same table.

### 3.6 Scale and correctness

- `position` — **shipped as of 2026-09-26.** Card ordering is a per-column integer
  with a real unique index on `(board_id, status, position)`, so two cards in the
  same column can never share a slot. `TaskBoard::moveTask()`/`createTask()` reserve
  a free slot by pushing the suffix (`position >= slot + 1`) up one before inserting
  (gap-rebalancing), wrapped in a `DB::transaction` that retries up to five times on a
  `23000`/`23505` unique-violation — the backstop for a concurrent drag that read a
  stale slot. The unit of position is the *column card count* (the slot the frontend
  drops into), not an absolute ordinal, so a moved card lands exactly where it was
  dropped and never on a slot already held; gaps are tolerated on read. Sorting is a
  view only (see §2), so `manual` reads `position` while `due`/`priority` order by their
  own columns and never rewrite stored positions.
- `boards.slug` — **shipped as of 2026-09-26.** The column now carries a real
  unique index on `(user_id, slug)`, so the database is the source of truth and
  a race cannot create duplicates. Validation still catches the duplicate for the
  normal request, and `StoreBoardRequest`/`UpdateBoardRequest` scope it to
  `user_id` so two boards owned by *different* users may share a slug. The
  controller wraps the create/update in a `QueryException` catch; a DB rejection
  (the concurrent race that validation cannot see) becomes a `slug` field error
  rather than a 500. `tests/Feature/BoardSlugTest.php` covers both layers.
- Soft-deleted cards keep their `task_assignees` / `task_watchers` rows (the
  pivots only cascade on a *hard* delete). Harmless — every read goes through
  the `Task` model, which applies the soft-delete scope — and it means
  restoring a card restores its team. Worth a cleanup job if the table grows.
- `task_activity` grows one row per board change and is never pruned. Fine at
  this volume; when it is not, partition by month and keep the window a report
  actually asks for.

### 3.7 Reporting — shipped

`/boards/{board}/report`, gated on its own `tasks.report` permission rather than
`tasks.view`: the page answers "who is carrying what" and "how long does work sit
here", which is a manager's view of the team rather than a view of the board. An
agent who can work cards does not get it. Four reports, all read-only:

- **Cards per column.** Every configured column appears, including the empty
  ones — an absent column reads as "deleted" when the useful fact is "nobody has
  put anything here yet", which is the signal that makes a WIP limit worth
  setting. Cards sitting in a column that has since been deleted get their own
  flagged row rather than being dropped, so the column totals still add up to the
  card total.
- **Finished per week.** Counted as *distinct cards per week*, not distinct
  transitions — a card finished, reopened and finished again is one card
  finished. Empty weeks are present as zeros, because a series that omits them
  draws a straight line across the gap and reads as steady output.
- **Average time in column.** The careful one, and the reason is worth keeping:
  "for each transition, the gap to the next" only ever closes an interval, so it
  measures the columns a card has *left* and silently drops the column a card is
  sitting in right now — usually the one people most want to watch. Every card
  therefore contributes its final, open interval too, measured up to now. The
  view shows how many of a column's intervals are still open, because a column
  whose average is mostly open intervals is a different measurement from one
  that is mostly closed.
- **Load by assignee.** Open / overdue / finished per person, **plus a row for
  unassigned cards** — work with nobody on it is a real failure state, and
  burying it under the named people is how it stays invisible. A card with three
  assignees counts once for each, so the rows sum to more than the board's card
  count; that is effort-weighted rather than card-weighted load, and the view
  says so rather than quietly normalising it away.

Two things the report refuses to guess:

- **Time before a card's first logged move is not back-filled** from
  `tasks.created_at`. A card that predates the log has an unknowable entry time
  for its first column, so that time is left out rather than invented. The
  report counts those cards and says how many.
- **Coverage is stated on the page.** `task_activity` starts empty on an
  upgraded install, so a board with six months of work can show a fortnight of
  throughput. The page carries a banner naming the number of cards with no
  recorded moves and the date movement history begins. A report that cannot be
  wrong is not the claim being made; that banner is the receipt for what it saw.

Still out: no comparison between boards. **CSV export shipped** and **custom date
ranges shipped as of 2026-09-26** — the report and its export accept `from`/`to`
(`Y-m-d`) that bound the history-based reports (throughput, time-in-column,
coverage) to a window; a lone `from` runs to today, a lone `to` runs back the
default window, and a malformed date falls back to the weeks selector rather than
500-ing. The window is clamped to 52 weeks so a year-long range does not render a
thousand rows. Status counts and assignee load stay "as of now" — they are current
snapshots, not history, so the range never touches them. `GET
/boards/{board}/report/export` (`tasks.report`, same as the page) returns the
four reports as a flat CSV, one section per table with a title row so a downstream
job can find each by name. CSV deliberately, not a styled format: the data is four
flat tables and the consumer is a spreadsheet. `tests/Feature/BoardReportExportTest.php`
covers the shape, the slug-derived filename, and the permission gate.

---

## 4. Test coverage

| file                                        | covers                                                                 |
|---------------------------------------------|------------------------------------------------------------------------|
| `tests/Feature/TaskBoardRoutesTest.php`          | the real route chain: page renders, permission middleware, guest redirects, and that `GET /tasks` never writes |
| `tests/Feature/Livewire/TaskBoardTest.php`       | permissions per action, create/move/delete, status validation, soft deletes, dynamic columns, broadcast payload |
| `tests/Feature/Livewire/TaskCardDetailTest.php`  | the drawer: assignees, watchers, description, comments, per-action permissions, that a spoofed `$openTaskId` cannot reach a card on another board, and that watching is strictly self-service (your own id toggles, anyone else's is a 403) |
| `tests/Feature/BoardArchiveTest.php` | that archiving hides a board from the list and the switcher but **keeps every card and the board row itself**, that it stays reachable by URL behind its banner, that archived boards are one query away and the two lists partition, that an archived board never becomes the default landing board, that archiving is idempotent, restore is total, agent and viewer are both refused, and that **deleting still cascades** — the one that loses the cards |
| `tests/Feature/BoardSlugTest.php`               | that the unique `(user_id, slug)` index actually refuses a duplicate row (the race safety net), that a different owner may reuse a slug, that store/update validation rejects a rename onto another own board's slug while allowing reuse of another user's, and that a duplicate is a `slug` field error rather than a 500 |
| `tests/Feature/BoardDuplicateTest.php`          | that duplicate is a template not a fork — the copy starts empty while the source keeps every card — that it copies name/slug-stem/description, generates a unique slug on collision (`support-team-copy` → `support-team-copy-2`), and that an agent may duplicate while a viewer is refused |
| `tests/Feature/BoardReportExportTest.php`       | that the CSV export returns a real CSV carrying all four sections (cards per column, finished per week, average time in column, load by assignee, coverage), that the filename is `board-{slug}-report.csv`, and that a viewer without `tasks.report` is refused while a guest is sent to login |
| `tests/Feature/TaskStatusManagementTest.php`     | status CRUD, default/slug/deleting invariants, colour validation, config fallback, and the WIP limit: set, cleared, `0` rejected, refused on a finished column, retired when a column is flagged finished, and ignored on a finished column even if the row somehow holds one |
| `tests/Feature/Livewire/WipLimitTest.php` | that a limit set shows a badge and an unconstrained column shows none, that **an agent sees it too**, that the count is unfiltered (a filtered board still reads `2/3`), and — the negative cases that matter — that moving or creating into an over-limit column **warns and still moves**, that a move within the limit says nothing, that a later move clears a stale notice, that another board's cards and soft-deleted cards do not count, and that Done never warns |
| `tests/Feature/TaskBoardChannelTest.php`         | private channel authorisation (incl. guest and unprivileged refusal) |
| `tests/Feature/TaskNotificationPolicyTest.php`   | the audience: owner/assignee/watcher union, cross-group dedupe, actor exclusion, inactive users, all three levels, that creating a card stays silent, and — end-to-end through the real Livewire action — that a retracted comment and a changed deadline are *not* announced as something else |
| `tests/Feature/ProfileNotificationPreferenceTest.php` | changing the level, rejecting an invalid one, and that omitting the field does not reset a deliberate choice |
| `tests/Feature/Livewire/TaskTriageTest.php` | due date / priority / estimate saving and validation, overdue detection incl. the completed-column exclusion, the overdue filter, both sort modes, and that a drop in a sorted view moves the column but not the order |
| `tests/Feature/Livewire/TaskActivityTest.php` | one entry per event kind, the actor on each, the assignee snapshot, that a *rejected* or cross-board action writes **no** row, the newest-first cap, no cross-card leakage, no-actor rendering, that every `EMAILABLE_REASON` is mapped to a log type, and that `task_activity` has no `updated_at` column |
| `tests/Feature/TaskPositioningTest.php` | that moving cards across and within columns never produces a shared position in any column, that the frontend's append semantics drop at the end (slot → `slot + 1`) rather than on an occupied slot, and that the unique `(board_id, status, position)` index actually refuses a duplicate row — the race safety net |
| `tests/Feature/TaskNotificationIdempotencyTest.php` | that re-delivering the same event object (what a queue retry does) mails nobody twice, that a retry does not duplicate the history row either, that a partial-failure retry delivers the recipient who never got one, that the unique index turns a duplicate ledger write into a no-op instead of a failed job, that a missing log row still sends, and that the guard is per activity so two real moves still produce two mails |
| `tests/Feature/BoardReportTest.php` | the four reports: zero columns still counted, cards in a deleted column still totalled, one card finished twice counting once, empty weeks present, **time in a column a card is still sitting in**, unlogged pre-history time left unmeasured rather than guessed, soft-deleted cards excluded, unassigned load, multi-assignee load, that a finished card is never counted overdue, that a column carries its limit and flags being over while a finished or retired column never does, and that **a comment is not counted as a movement** when working out which cards have measurable history; plus the custom date range: throughput counts only completions inside `from`/`to` (a completion before the window is excluded), a multi-year range clamps to 52 weeks, the range also bounds the movement history behind time-in-column and coverage, and the route honours `from`/`to` while a malformed date falls back to the weeks window |
| `tests/Feature/TaskBoardRoutesTest.php` | also covers `/boards/{board}/report`: manager 200, agent 403 (the whole reason `tasks.report` is its own permission), no-permission 403, guest redirect, the weeks window clamped rather than trusted, and that the report never writes |

The channel test deliberately re-points `broadcasting.default` at Reverb:
`phpunit.xml` pins `null`, and `NullBroadcaster` answers every auth request
with an empty 200 without consulting a channel callback — it can assert
nothing. It also re-requires `routes/channels.php`, because `Broadcast::channel()`
registers on whichever driver is resolved at boot.

### A note on `send()` vs `queue()` inside a queued listener

`SendTaskStatusEmail` and `SendTaskActivityEmail` are `ShouldQueue`, and they
call `Mail::to($user)->queue(...)` rather than `Mail::send()`. That is a second
queue hop on purpose: the unit of work becomes one job per recipient, matching
the app's existing `SendCampaignEmail` (which takes a single `logId` for the same
reason). With `send()` a dead address on recipient 3 of 5 fails the job, and the
retry re-mails recipients 1 and 2. Tests assert with `assertQueued` accordingly.

### A note on what a listener must not own

Both listeners resolve the audience *inside* `handle()`, not at dispatch time.
The listener may run minutes after the event, and by then an assignee can have
been added or a user deactivated. Resolving at dispatch would notify people who
opted out while the job sat in the queue.

### A note on a view that must not become a write

`sortMode` looks like a filter but is the closest thing here to a query-builder
risk: it decides the `ORDER BY` applied to every render, and it is set from a
`<select>` in the browser. Two things keep that honest — the select uses
`wire:change="setSortMode(...)"` rather than `wire:model`, so the validated
action is the only write path; and `applySort()` ends in a `match` with a
`default` arm, so an unrecognised value degrades to manual order rather than
throwing mid-render.

The same reasoning drives the drop rule: in a sorted view the browser sends a
drop index for a list the screen is not ordered by, so `moveTask()` honours the
column and discards the position. Writing it would be a silent corruption of an
ordering the user cannot see.

### A note on `revokePermissionTo()`

It strips only *directly assigned* permissions — it cannot remove a grant that
came from a role. Negative-permission tests must use a **role-less** user.
Several tests here failed until that was corrected.

### A note on `abort()` vs `findOrFail()` in Livewire

Livewire turns `abort()`/`abort_unless()` into a real HTTP status (which is why
`assertForbidden()` works here), but a `ModelNotFoundException` from
`findOrFail()` escapes the component as an unhandled error instead of becoming
a 404. So a Livewire action that must return 404 for a missing record should use
`Model::find()` + `abort_if(... === null, 404)`, not `findOrFail()`.

`moveTask()` is the reason that note now has teeth. It was the one remaining
`findOrFail()` on a task id supplied by the browser, and because `canMove()` is a
*global* permission it never checked the board — so a crafted payload could
move a card on a board the user was not viewing, and (once the log existed)
write a history row for it. `taskOnCurrentBoardOrFail()` scopes it, returning
**404 rather than 403**: the card does exist, just not here, and a 403 would
confirm to someone that it exists somewhere they cannot see.

### A note on ordering: check state before input

Drawer actions resolve the open card *before* running validation. Otherwise a
call with no card open fails validation first and reports "description is
required" — pointing the user at the empty field instead of the actual problem.

### An untestable guard

There is no "the drawer requires `tasks.view`" test. A user without that
permission cannot mount the board at all (`render()` aborts 403), so Livewire
never produces a snapshot to fire an action at. The mount guard is covered once
in `TaskBoardTest`, and every drawer action re-checks independently.
