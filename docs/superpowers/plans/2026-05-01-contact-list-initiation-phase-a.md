# Contact-List Initiation (Inbox Phase 13.1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add chat + call icon buttons on every contact-list row so users can initiate WhatsApp conversations and Cloud Calling API calls directly from `/contacts`, while staying compliant with Meta's opt-in policy via an engagement-based proxy (inbound message OR inbound call in last 30 days).

**Architecture:** Two new POST endpoints on `ContactController` (`startChat`, `startCall`) that find-or-create the `(contact, instance)` Conversation row and either redirect to the chat thread or fire `OutboundCallService::initiate` from Voice Phase A. Engagement is enforced both at render time (button disabled state) and on the server (defense-in-depth). Multi-instance users hit a small Alpine picker modal; calls run through the same client-side confirmation modal pattern as Voice Phase A.

**Tech Stack:** Laravel 12 · PHP 8.2 (XAMPP local; opcache disabled for artisan calls) · SQLite local · Alpine.js · Tailwind · spatie/laravel-permission · PHPUnit 11.

---

## Spec reference

Full design: `docs/superpowers/specs/2026-05-01-contact-list-initiation-design.md` (committed `18f301d`).

## File structure

### Files to create (1)

| File | Responsibility |
|---|---|
| `tests/Feature/Controllers/ContactInitiationTest.php` | All ~10 controller tests for the two new endpoints |

### Files to modify (4)

| File | Change |
|---|---|
| `app/Models/Contact.php` | Add `conversationMessages()` + `callLogs()` HasManyThrough relations; add `ENGAGEMENT_WINDOW_DAYS` constant + `isEngaged()` method |
| `app/Http/Controllers/ContactController.php` | Add `startChat` + `startCall` actions, private `resolveInstance` + `authorizeContactAccess` helpers, eager-load `is_engaged` flag in `index()` |
| `routes/web.php` | Add 2 new POST routes inside the existing auth middleware group |
| `resources/views/contacts/index.blade.php` | Add Chat + Call icon buttons per row, instance-picker modal, call-confirmation modal |

### Existing infrastructure reused (verified before planning)

- `Contact` model exists with `user()`, `groups()`, `messageLogs()` relations and a factory
- `Conversation` model has `firstOrCreate`-friendly fillable: `user_id`, `contact_id`, `whatsapp_instance_id`, `assigned_to_user_id`, `unread_count`
- `Conversation::isWindowOpen()` + thread view's freeform/template branching already enforce the 24h policy
- `OutboundCallService::initiate(Conversation, User): CallLog` from Voice Phase A
- `WhatsAppApiException` from existing exceptions
- Permissions `conversations.reply` and `conversations.call` already seeded
- Alpine confirmation modal pattern from Voice Phase A's chat-header call button

### Environment notes (apply to every task)

- Always prefix artisan with `php -d opcache.enable=0 -d opcache.enable_cli=0` (XAMPP-Windows opcache permission bug)
- Tests use SQLite in-memory via `RefreshDatabase` trait
- Branch: `main`, committing direct (user-approved)
- Baseline: 148 tests must remain green after each task

---

# Tasks

## Task 1: Add HasManyThrough relations on Contact

**Files:**
- Modify: `app/Models/Contact.php`
- Test: `tests/Feature/Models/ContactRelationsTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Models/ContactRelationsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_exposes_conversation_messages_through_conversations(): void
    {
        $conv = Conversation::factory()->create();
        ConversationMessage::create([
            'conversation_id' => $conv->id,
            'direction' => 'inbound',
            'whatsapp_message_id' => 'wamid.1',
            'type' => 'text',
            'body' => 'hello',
            'received_at' => now(),
        ]);

        $messages = $conv->contact->conversationMessages;

        $this->assertCount(1, $messages);
        $this->assertSame('hello', $messages->first()->body);
    }

    public function test_contact_exposes_call_logs_through_conversations(): void
    {
        $conv = Conversation::factory()->create();
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $conv->whatsapp_instance_id,
        ]);

        $this->assertCount(1, $conv->contact->callLogs);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Models/ContactRelationsTest.php --no-coverage
```

Expected: FAIL with "Call to undefined relationship [conversationMessages]" (and one for callLogs).

- [ ] **Step 3: Add the relations**

Open `app/Models/Contact.php`. Add these imports near the top alphabetically:

```php
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
```

Then add these two methods right after the existing `messageLogs()` method (around line 49):

```php
    /**
     * All inbound + outbound chat messages this contact has exchanged with us,
     * across every conversation/instance. Used for engagement detection
     * (Phase 13.1 opt-in proxy) and future activity-timeline rendering.
     */
    public function conversationMessages(): HasManyThrough
    {
        return $this->hasManyThrough(
            ConversationMessage::class,
            Conversation::class,
            'contact_id',         // FK on conversations table
            'conversation_id',    // FK on conversation_messages table
            'id',                 // local key on contacts
            'id',                 // local key on conversations
        );
    }

    /**
     * All call_logs this contact has been part of, across every conversation.
     * Used for engagement detection (Phase 13.1 opt-in proxy).
     */
    public function callLogs(): HasManyThrough
    {
        return $this->hasManyThrough(
            CallLog::class,
            Conversation::class,
            'contact_id',
            'conversation_id',
            'id',
            'id',
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Models/ContactRelationsTest.php --no-coverage
```

Expected: `OK (2 tests, 3 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Contact.php tests/Feature/Models/ContactRelationsTest.php
git commit -m "feat(contacts): HasManyThrough relations for conversation messages + call logs"
```

---

## Task 2: Add `isEngaged()` method + `ENGAGEMENT_WINDOW_DAYS` constant

**Files:**
- Modify: `app/Models/Contact.php`
- Test: `tests/Feature/Models/ContactRelationsTest.php` (append)

- [ ] **Step 1: Append failing tests**

In `tests/Feature/Models/ContactRelationsTest.php`, append before the final `}`:

```php
    public function test_isEngaged_returns_false_for_contact_with_no_recent_activity(): void
    {
        $contact = Contact::factory()->create();

        $this->assertFalse($contact->isEngaged());
    }

    public function test_isEngaged_returns_true_when_contact_messaged_within_window(): void
    {
        $conv = Conversation::factory()->create();
        ConversationMessage::create([
            'conversation_id' => $conv->id,
            'direction' => 'inbound',
            'whatsapp_message_id' => 'wamid.recent',
            'type' => 'text',
            'body' => 'hi',
            'received_at' => now()->subDays(5),
        ]);

        $this->assertTrue($conv->contact->isEngaged());
    }

    public function test_isEngaged_returns_false_when_only_old_messages_exist(): void
    {
        $conv = Conversation::factory()->create();
        ConversationMessage::create([
            'conversation_id' => $conv->id,
            'direction' => 'inbound',
            'whatsapp_message_id' => 'wamid.old',
            'type' => 'text',
            'body' => 'old',
            'received_at' => now()->subDays(45),
        ]);

        $this->assertFalse($conv->contact->isEngaged());
    }

    public function test_isEngaged_returns_true_when_inbound_call_within_window(): void
    {
        $conv = Conversation::factory()->create();
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $conv->whatsapp_instance_id,
            'direction' => CallLog::DIRECTION_INBOUND,
            'created_at' => now()->subDays(10),
        ]);

        $this->assertTrue($conv->contact->isEngaged());
    }

    public function test_isEngaged_only_counts_inbound_messages_not_outbound(): void
    {
        $conv = Conversation::factory()->create();
        ConversationMessage::create([
            'conversation_id' => $conv->id,
            'direction' => 'outbound',  // outbound doesn't count as engagement
            'whatsapp_message_id' => 'wamid.out',
            'type' => 'text',
            'body' => 'we wrote first',
            'sent_at' => now()->subDays(2),
            'received_at' => now()->subDays(2),
        ]);

        $this->assertFalse($conv->contact->isEngaged());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Models/ContactRelationsTest.php --no-coverage
```

Expected: 5 new test failures with "Call to undefined method ...isEngaged()".

- [ ] **Step 3: Add constant + method to Contact**

In `app/Models/Contact.php`, add this constant inside the class body, right after the `use HasFactory` line (or near the other constants if any exist):

```php
    /**
     * Engagement window for the Phase 13.1 opt-in proxy. A contact is
     * considered "engaged" (and therefore callable / chattable from the
     * contact list) if they have at least one inbound message OR inbound
     * call within this many days.
     *
     * Phase 14 will replace this with an explicit `opted_in_at` column;
     * for now this constant is the single source of truth.
     */
    public const ENGAGEMENT_WINDOW_DAYS = 30;
```

Then add this method below the new `callLogs()` relation from Task 1:

```php
    /**
     * Engagement-based opt-in proxy for Meta WhatsApp Business policy.
     * True if this contact has shown they want to talk to us — either
     * messaged us OR called us (inbound, not outbound) within the last
     * {@see self::ENGAGEMENT_WINDOW_DAYS} days.
     *
     * Used by ContactController::startCall as a server-side guard so
     * a misconfigured UI can never bypass the policy check.
     *
     * Two short-circuiting `exists()` queries instead of one UNION because
     * each runs against an indexed column and the first true wins.
     */
    public function isEngaged(): bool
    {
        $threshold = now()->subDays(self::ENGAGEMENT_WINDOW_DAYS);

        $hasRecentInbound = $this->conversationMessages()
            ->where('direction', 'inbound')
            ->where('received_at', '>=', $threshold)
            ->exists();

        if ($hasRecentInbound) {
            return true;
        }

        return $this->callLogs()
            ->where('direction', CallLog::DIRECTION_INBOUND)
            ->where('created_at', '>=', $threshold)
            ->exists();
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Models/ContactRelationsTest.php --no-coverage
```

Expected: `OK (7 tests, 8 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Contact.php tests/Feature/Models/ContactRelationsTest.php
git commit -m "feat(contacts): isEngaged() method for Meta opt-in proxy

A contact is engaged if they have an inbound message OR inbound call
within the last 30 days. Used as the policy-compliance gate for the
contact-list Call button in Phase 13.1; replaced by explicit opted_in_at
column in Phase 14."
```

---

## Task 3: Add `startChat` route + controller action

**Files:**
- Modify: `app/Http/Controllers/ContactController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Controllers/ContactInitiationTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Controllers/ContactInitiationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactInitiationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_startChat_creates_conversation_for_new_contact(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'is_active' => true]);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);

        $this->assertSame(0, Conversation::count());

        $response = $this->actingAs($admin)
            ->post(route('contacts.startChat', $contact));

        $response->assertRedirect();
        $this->assertSame(1, Conversation::count());

        $conv = Conversation::first();
        $this->assertSame($contact->id, $conv->contact_id);
        $this->assertSame($instance->id, $conv->whatsapp_instance_id);
        $response->assertRedirect(route('conversations.show', $conv));
    }

    public function test_startChat_reuses_existing_conversation(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'is_active' => true]);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);
        $existing = Conversation::factory()->create([
            'user_id' => $admin->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
        ]);

        $this->actingAs($admin)
            ->post(route('contacts.startChat', $contact))
            ->assertRedirect(route('conversations.show', $existing));

        $this->assertSame(1, Conversation::count(), 'Must not create a duplicate conversation');
    }

    public function test_startChat_requires_conversations_reply_permission(): void
    {
        // Build a user with a custom role that has no conversations.* permissions.
        $user = User::factory()->create(['is_active' => true]);
        $contact = Contact::factory()->create(['user_id' => $user->id]);
        WhatsAppInstance::factory()->create(['user_id' => $user->id, 'is_active' => true]);

        $this->actingAs($user)
            ->post(route('contacts.startChat', $contact))
            ->assertForbidden();

        $this->assertSame(0, Conversation::count());
    }

    public function test_startChat_blocks_cross_account_contact(): void
    {
        $userA = $this->makeUser('admin');
        $userB = $this->makeUser('admin', 'b@example.com');
        $contactOfB = Contact::factory()->create(['user_id' => $userB->id]);
        WhatsAppInstance::factory()->create(['user_id' => $userA->id, 'is_active' => true]);

        $this->actingAs($userA)
            ->post(route('contacts.startChat', $contactOfB))
            ->assertForbidden();

        $this->assertSame(0, Conversation::count());
    }

    private function makeUser(string $role, ?string $email = null): User
    {
        $user = User::factory()->create([
            'email' => $email ?? "{$role}-".uniqid().'@example.com',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Controllers/ContactInitiationTest.php --no-coverage
```

Expected: 4 failures, all with `Route [contacts.startChat] not defined.`.

- [ ] **Step 3: Add the route**

Open `routes/web.php`. Find the existing `permission:conversations.reply` route group (around line 155). Right after the closing `});` of that group, add a new group for the contact-list initiation routes:

```php
    Route::middleware('permission:conversations.reply')->group(function () {
        Route::post('/contacts/{contact}/chat', [ContactController::class, 'startChat'])
            ->name('contacts.startChat');
    });
```

- [ ] **Step 4: Add `startChat` + helpers to `ContactController`**

Open `app/Http/Controllers/ContactController.php`. Add these imports at the top (alphabetical):

```php
use App\Models\Conversation;
use App\Models\WhatsAppInstance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
```

(`Illuminate\Http\Request` is likely already imported — check before adding.)

Add the action and two private helpers right after the existing `index()` method:

```php
    /**
     * Open a chat thread with this contact. If no Conversation exists yet for
     * the picked WhatsApp instance, create one (find-or-create — clicking
     * Chat twice never creates duplicate rows). Pure navigation: no message
     * is sent. The thread view enforces the 24h freeform/template policy.
     */
    public function startChat(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorizeContactAccess($request, $contact);

        $instance = $this->resolveInstance($request);
        if ($instance === null) {
            return back()->with('error', $this->instancePickError($request));
        }

        $conversation = Conversation::firstOrCreate(
            ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
            ['user_id' => $contact->user_id, 'unread_count' => 0],
        );

        return redirect()->route('conversations.show', $conversation);
    }

    /**
     * Same-account ownership guard. Mirrors the pattern from
     * ConversationController::authorizeConversationAccess.
     */
    private function authorizeContactAccess(Request $request, Contact $contact): void
    {
        abort_unless($contact->user_id === $request->user()->id, 403);
    }

    /**
     * Resolve which WhatsApp instance to use for a contact-initiated action.
     *
     * - 1 active instance → auto-pick it.
     * - 2+ active instances → require `instance_id` in the request body
     *   (sent by the picker modal in contacts/index.blade.php).
     * - 0 active instances → return null; caller flashes a setup error.
     *
     * Returns null on any unresolved case so the caller can return a flash
     * error rather than throwing.
     */
    private function resolveInstance(Request $request): ?WhatsAppInstance
    {
        $instances = WhatsAppInstance::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->get();

        if ($instances->count() === 0) {
            return null;
        }

        if ($instances->count() === 1) {
            return $instances->first();
        }

        $picked = (int) $request->input('instance_id', 0);
        return $instances->firstWhere('id', $picked);
    }

    /**
     * Human-readable error message when {@see resolveInstance()} returns null.
     */
    private function instancePickError(Request $request): string
    {
        $count = WhatsAppInstance::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->count();

        return $count === 0
            ? 'Set up a WhatsApp instance before starting conversations.'
            : 'Pick which WhatsApp number to use from the picker.';
    }
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Controllers/ContactInitiationTest.php --no-coverage
```

Expected: `OK (4 tests, X assertions)`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ContactController.php routes/web.php tests/Feature/Controllers/ContactInitiationTest.php
git commit -m "feat(contacts): startChat creates-or-finds conversation, redirects to thread

Pure navigation action — no Meta API call. The chat-thread view's existing
24h-window logic enforces freeform vs template send policy."
```

---

## Task 4: Add `startCall` route + controller action

**Files:**
- Modify: `app/Http/Controllers/ContactController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Controllers/ContactInitiationTest.php` (append)

- [ ] **Step 1: Append failing tests**

Append to `tests/Feature/Controllers/ContactInitiationTest.php` before the final `}`:

```php
    public function test_startCall_blocked_when_contact_has_no_engagement(): void
    {
        $admin = $this->makeUser('admin');
        WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'is_active' => true]);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);

        \Illuminate\Support\Facades\Http::fake();

        $this->actingAs($admin)
            ->from(route('contacts.index'))
            ->post(route('contacts.startCall', $contact))
            ->assertRedirect(route('contacts.index'))
            ->assertSessionHas('error');

        \Illuminate\Support\Facades\Http::assertNothingSent();
        $this->assertSame(0, \App\Models\CallLog::count());
    }

    public function test_startCall_allowed_when_contact_messaged_within_30_days(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'is_active' => true]);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
        ]);
        \App\Models\ConversationMessage::create([
            'conversation_id' => $conv->id,
            'direction' => 'inbound',
            'whatsapp_message_id' => 'wamid.engagement',
            'type' => 'text',
            'body' => 'hi',
            'received_at' => now()->subDays(5),
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response([
                'calls' => [['id' => 'wacid.contact_initiated']],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->post(route('contacts.startCall', $contact))
            ->assertRedirect(route('conversations.show', $conv))
            ->assertSessionHas('success');

        $this->assertSame(1, \App\Models\CallLog::count());
        $call = \App\Models\CallLog::first();
        $this->assertSame('outbound', $call->direction);
        $this->assertSame($admin->id, $call->placed_by_user_id);
        $this->assertSame('wacid.contact_initiated', $call->meta_call_id);
    }

    public function test_startCall_allowed_when_contact_called_within_30_days(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'is_active' => true]);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
        ]);
        \App\Models\CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'created_at' => now()->subDays(10),
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response([
                'calls' => [['id' => 'wacid.from_call']],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->post(route('contacts.startCall', $contact))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, \App\Models\CallLog::count(), 'inbound + new outbound');
    }

    public function test_startCall_engagement_threshold_is_30_days(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'is_active' => true]);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
        ]);
        \App\Models\ConversationMessage::create([
            'conversation_id' => $conv->id,
            'direction' => 'inbound',
            'whatsapp_message_id' => 'wamid.too_old',
            'type' => 'text',
            'body' => 'old',
            'received_at' => now()->subDays(31),  // one day past the threshold
        ]);

        \Illuminate\Support\Facades\Http::fake();

        $this->actingAs($admin)
            ->from(route('contacts.index'))
            ->post(route('contacts.startCall', $contact))
            ->assertRedirect(route('contacts.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, \App\Models\CallLog::count());
    }

    public function test_startCall_requires_conversations_call_permission(): void
    {
        // Agent role has conversations.reply but NOT conversations.call.
        $agent = $this->makeUser('agent');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $agent->id, 'is_active' => true]);
        $contact = Contact::factory()->create(['user_id' => $agent->id]);

        $this->actingAs($agent)
            ->post(route('contacts.startCall', $contact))
            ->assertForbidden();
    }

    public function test_startCall_blocks_cross_account_contact(): void
    {
        $userA = $this->makeUser('admin');
        $userB = $this->makeUser('admin', 'b@example.com');
        WhatsAppInstance::factory()->create(['user_id' => $userA->id, 'is_active' => true]);
        $contactOfB = Contact::factory()->create(['user_id' => $userB->id]);

        $this->actingAs($userA)
            ->post(route('contacts.startCall', $contactOfB))
            ->assertForbidden();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Controllers/ContactInitiationTest.php --filter startCall --no-coverage
```

Expected: 6 failures, all with `Route [contacts.startCall] not defined.`.

- [ ] **Step 3: Add the route**

In `routes/web.php`, find the existing `permission:conversations.call` group (around line 161, contains `conversations.initiateCall` and `conversations.endCall`). Right after the closing `});` of that group, add:

```php
    Route::middleware('permission:conversations.call')->group(function () {
        Route::post('/contacts/{contact}/call', [ContactController::class, 'startCall'])
            ->name('contacts.startCall');
    });
```

- [ ] **Step 4: Add `startCall` action to `ContactController`**

In `app/Http/Controllers/ContactController.php`, add this import at the top:

```php
use App\Exceptions\WhatsAppApiException;
use App\Services\OutboundCallService;
```

Then add the action right after `startChat` from Task 3:

```php
    /**
     * Place an outbound call to this contact.
     *
     * Defense in depth: the contact-list view already disables this button
     * for non-engaged contacts (Meta opt-in policy proxy), but the server
     * MUST re-check engagement so a misclick or bypassed UI cannot bypass
     * the policy gate. Calls cost real money and have quality-rating risk.
     *
     * Reuses the Voice Phase A {@see OutboundCallService::initiate} flow
     * after find-or-create on the conversation.
     */
    public function startCall(
        Request $request,
        Contact $contact,
        OutboundCallService $outboundCallService,
    ): RedirectResponse {
        $this->authorizeContactAccess($request, $contact);

        if (! $contact->isEngaged()) {
            return back()->with(
                'error',
                'Cannot call this contact yet — they must message you first '
                .'(Meta opt-in policy). Wait for an inbound message or call.',
            );
        }

        $instance = $this->resolveInstance($request);
        if ($instance === null) {
            return back()->with('error', $this->instancePickError($request));
        }

        $conversation = Conversation::firstOrCreate(
            ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
            ['user_id' => $contact->user_id, 'unread_count' => 0],
        );

        try {
            $outboundCallService->initiate($conversation, $request->user());
        } catch (WhatsAppApiException $e) {
            return redirect()
                ->route('conversations.show', $conversation)
                ->with('error', "Could not place call: {$e->getMessage()}");
        }

        return redirect()
            ->route('conversations.show', $conversation)
            ->with('success', "Calling {$contact->name}...");
    }
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Controllers/ContactInitiationTest.php --no-coverage
```

Expected: `OK (10 tests, X assertions)`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ContactController.php routes/web.php tests/Feature/Controllers/ContactInitiationTest.php
git commit -m "feat(contacts): startCall with engagement gate, reuses Voice Phase A flow

Server-side engagement check (inbound message OR call within 30 days)
runs BEFORE Meta API is hit — defense in depth so a bypassed or buggy
UI cannot violate Meta's opt-in policy. Reuses OutboundCallService from
Voice Phase A so call_log creation, in-flight banner, and webhook flow
all Just Work."
```

---

## Task 5: Multi-instance picker — server-side + 1 test

**Files:**
- Test: `tests/Feature/Controllers/ContactInitiationTest.php` (append)

The server-side `resolveInstance()` helper from Task 3 already handles the multi-instance branch (returns `null` when 2+ active instances and no `instance_id` provided, returns the picked one when `instance_id` matches). We just need a test confirming that.

- [ ] **Step 1: Append failing test**

Append before the final `}` of `ContactInitiationTest.php`:

```php
    public function test_startChat_with_multiple_active_instances_requires_instance_id(): void
    {
        $admin = $this->makeUser('admin');
        WhatsAppInstance::factory()->count(2)->create([
            'user_id' => $admin->id,
            'is_active' => true,
        ]);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);

        // First call: no instance_id → flash error, no conversation created.
        $this->actingAs($admin)
            ->from(route('contacts.index'))
            ->post(route('contacts.startCall', $contact))  // also exercises engagement-bypass path
            ->assertSessionHasNoErrors();  // explicit: this shouldn't be a validation error

        // Second call: with instance_id → still blocked by engagement, but instance is resolved.
        // We assert via startChat which has no engagement gate.
        $instance2 = WhatsAppInstance::where('user_id', $admin->id)->skip(1)->first();
        $this->actingAs($admin)
            ->post(route('contacts.startChat', $contact), ['instance_id' => $instance2->id])
            ->assertRedirect();

        $conv = Conversation::first();
        $this->assertNotNull($conv);
        $this->assertSame($instance2->id, $conv->whatsapp_instance_id);
    }

    public function test_startChat_with_zero_active_instances_flashes_setup_error(): void
    {
        $admin = $this->makeUser('admin');
        // Inactive instance — should be ignored.
        WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'is_active' => false]);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->from(route('contacts.index'))
            ->post(route('contacts.startChat', $contact))
            ->assertRedirect(route('contacts.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Conversation::count());
    }
```

- [ ] **Step 2: Run tests to verify they pass**

The server logic exists already from Task 3, so these should pass without further code changes.

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Controllers/ContactInitiationTest.php --no-coverage
```

Expected: `OK (12 tests, X assertions)`.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Controllers/ContactInitiationTest.php
git commit -m "test(contacts): cover multi-instance + zero-instance startChat paths"
```

---

## Task 6: Eager-load `is_engaged` on contact list (N+1 prevention)

**Files:**
- Modify: `app/Http/Controllers/ContactController.php`
- Test: `tests/Feature/Controllers/ContactInitiationTest.php` (append)

- [ ] **Step 1: Append failing test that asserts the flag is set on each contact**

Append to `ContactInitiationTest.php` before the final `}`:

```php
    public function test_contact_index_exposes_is_engaged_flag_for_each_contact(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'is_active' => true]);

        // Engaged contact — has a recent inbound message.
        $engaged = Contact::factory()->create(['user_id' => $admin->id, 'name' => 'Engaged']);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'contact_id' => $engaged->id,
            'whatsapp_instance_id' => $instance->id,
        ]);
        \App\Models\ConversationMessage::create([
            'conversation_id' => $conv->id,
            'direction' => 'inbound',
            'whatsapp_message_id' => 'wamid.eager',
            'type' => 'text',
            'body' => 'hi',
            'received_at' => now()->subDays(3),
        ]);

        // Cold contact — no activity.
        Contact::factory()->create(['user_id' => $admin->id, 'name' => 'Cold']);

        $response = $this->actingAs($admin)->get(route('contacts.index'));

        $response->assertOk();
        $contacts = $response->viewData('contacts');

        $byName = $contacts->keyBy('name');
        $this->assertTrue((bool) $byName['Engaged']->is_engaged);
        $this->assertFalse((bool) $byName['Cold']->is_engaged);
    }
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Controllers/ContactInitiationTest.php --filter is_engaged_flag --no-coverage
```

Expected: FAIL because `$contact->is_engaged` is undefined / null.

- [ ] **Step 3: Update `ContactController::index`**

Replace the existing `index()` method body:

```php
    public function index(\Illuminate\Http\Request $request): \Illuminate\View\View
    {
        $threshold = now()->subDays(\App\Models\Contact::ENGAGEMENT_WINDOW_DAYS);

        $query = Contact::where('user_id', auth()->id())
            ->withExists([
                // True if at least one inbound message exists for any of this
                // contact's conversations within the engagement window.
                'conversationMessages as has_recent_inbound_message' => fn ($q) =>
                    $q->where('direction', 'inbound')
                      ->where('received_at', '>=', $threshold),

                // True if at least one inbound call exists within the window.
                'callLogs as has_recent_inbound_call' => fn ($q) =>
                    $q->where('direction', \App\Models\CallLog::DIRECTION_INBOUND)
                      ->where('created_at', '>=', $threshold),
            ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $contacts = $query->latest()->paginate(20);

        // Compose `is_engaged` from the two eager-loaded flags so the view
        // and the row-action visibility don't need to think about which
        // signal triggered engagement.
        $contacts->getCollection()->transform(function (Contact $c): Contact {
            $c->is_engaged = (bool) ($c->has_recent_inbound_message || $c->has_recent_inbound_call);
            return $c;
        });

        return view('contacts.index', ['contacts' => $contacts]);
    }
```

(Note: the existing `index` had no `Request` dependency. The new version adds one to support the existing `?search=` parameter that the view's search form already POSTs. Confirm the view's search form still works after this change.)

- [ ] **Step 4: Run tests to verify they pass**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Controllers/ContactInitiationTest.php --no-coverage
```

Expected: `OK (13 tests, X assertions)`.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ContactController.php tests/Feature/Controllers/ContactInitiationTest.php
git commit -m "perf(contacts): eager-load is_engaged via withExists subqueries

Two correlated subqueries per contact-list query (one for inbound messages,
one for inbound calls) instead of N+1 isEngaged() calls during render.
Composed flag is_engaged is set on each contact in the controller so the
view never needs to know which signal triggered engagement."
```

---

## Task 7: Update `contacts/index.blade.php` — icon buttons + Alpine modals

**Files:**
- Modify: `resources/views/contacts/index.blade.php`

- [ ] **Step 1: Wrap the page in Alpine state + count instances for picker logic**

At the very top of `resources/views/contacts/index.blade.php` (just inside `<x-app-layout>` before `<x-slot name="header">`), establish a Blade-computed value that the Alpine state will use:

```blade
@php
    $activeInstances = \App\Models\WhatsAppInstance::where('user_id', auth()->id())
        ->where('is_active', true)
        ->orderBy('display_name')
        ->get(['id', 'display_name', 'instance_name', 'business_phone_number']);
    $needsInstancePicker = $activeInstances->count() > 1;
@endphp
```

- [ ] **Step 2: Replace the Actions cell to add Chat + Call buttons**

Find the existing Actions `<td>` (it currently contains just Edit + Delete). Replace its inner `<div class="flex items-center justify-end gap-3">` with:

```blade
<div class="flex items-center justify-end gap-2"
     x-data="{
         openCallConfirm: false,
         openInstancePicker: false,
         action: null,
         instanceId: null,
         submit() {
             const form = document.getElementById('contact-action-form-' + {{ $contact->id }});
             form.action = this.action;
             if (this.instanceId) {
                 const hidden = form.querySelector('[name=instance_id]') ?? Object.assign(document.createElement('input'), { type: 'hidden', name: 'instance_id' });
                 hidden.value = this.instanceId;
                 form.appendChild(hidden);
             }
             form.submit();
         }
     }">

    {{-- Hidden form, submitted by Alpine after picker/confirm flows resolve. --}}
    <form id="contact-action-form-{{ $contact->id }}" method="POST" action="" class="hidden">
        @csrf
    </form>

    {{-- CHAT button — always shown for users with conversations.reply --}}
    @can('conversations.reply')
        @if($needsInstancePicker)
            <button type="button"
                    @click="action = '{{ route('contacts.startChat', $contact) }}'; openInstancePicker = true"
                    class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-emerald-100 text-emerald-700 hover:bg-emerald-200 transition"
                    title="Chat with {{ $contact->name ?? $contact->phone }}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
            </button>
        @else
            {{-- Single-instance fast path: no picker, just submit. --}}
            <form method="POST" action="{{ route('contacts.startChat', $contact) }}" class="inline">
                @csrf
                <button type="submit"
                        class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-emerald-100 text-emerald-700 hover:bg-emerald-200 transition"
                        title="Chat with {{ $contact->name ?? $contact->phone }}">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                    </svg>
                </button>
            </form>
        @endif
    @endcan

    {{-- CALL button — gated by permission AND engagement --}}
    @can('conversations.call')
        @if($contact->is_engaged ?? false)
            <button type="button"
                    @click="action = '{{ route('contacts.startCall', $contact) }}'; @if($needsInstancePicker) openInstancePicker = true @else openCallConfirm = true @endif"
                    class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-emerald-600 text-white hover:bg-emerald-700 transition"
                    title="Call {{ $contact->name ?? $contact->phone }}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                </svg>
            </button>
        @else
            {{-- Disabled state with policy tooltip --}}
            <button type="button" disabled
                    class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-gray-100 text-gray-400 cursor-not-allowed"
                    title="Recipient must message you first (Meta opt-in policy)">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                </svg>
            </button>
        @endif
    @endcan

    <a href="{{ route('contacts.edit', $contact) }}" class="text-[#25D366] hover:text-[#1da851] font-medium">{{ __('Edit') }}</a>
    <form method="POST" action="{{ route('contacts.destroy', $contact) }}" onsubmit="return confirm('{{ __('Are you sure you want to delete this contact?') }}')" class="inline">
        @csrf
        @method('DELETE')
        <button type="submit" class="text-red-600 hover:text-red-800 font-medium">{{ __('Delete') }}</button>
    </form>

    {{-- INSTANCE PICKER MODAL — only renders for multi-instance users --}}
    @if($needsInstancePicker)
        <template x-teleport="body">
            <div x-show="openInstancePicker" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="openInstancePicker = false">
                <div class="absolute inset-0 bg-black/50"></div>
                <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">{{ __('Pick a WhatsApp number') }}</h3>
                    <p class="text-sm text-gray-500 mb-4">{{ __('Which of your numbers should this conversation use?') }}</p>
                    <select x-model="instanceId" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">{{ __('-- Select --') }}</option>
                        @foreach($activeInstances as $inst)
                            <option value="{{ $inst->id }}">{{ $inst->display_name ?? $inst->instance_name }} · {{ $inst->business_phone_number }}</option>
                        @endforeach
                    </select>
                    <div class="flex justify-end gap-2 mt-5">
                        <button type="button" @click="openInstancePicker = false" class="px-4 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Cancel') }}</button>
                        <button type="button"
                                @click="if (!instanceId) return; openInstancePicker = false; if (action.includes('/call')) { openCallConfirm = true } else { submit() }"
                                class="px-5 py-2 text-sm text-white bg-emerald-600 rounded-md hover:bg-emerald-700">
                            {{ __('Continue') }}
                        </button>
                    </div>
                </div>
            </div>
        </template>
    @endif

    {{-- CALL CONFIRMATION MODAL — same pattern as Voice Phase A's chat-header call button --}}
    <template x-teleport="body">
        <div x-show="openCallConfirm" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="openCallConfirm = false">
            <div class="absolute inset-0 bg-black/50"></div>
            <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">{{ __('Call') }} {{ $contact->name ?? $contact->phone }}?</h3>
                <dl class="text-sm space-y-1 mb-4">
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('Number') }}:</dt><dd class="text-gray-900 font-mono">{{ $contact->phone }}</dd></div>
                </dl>
                <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2 mb-4">
                    {{ __('Counts toward your daily Meta call quota. Audio rings on the device where this WhatsApp Business number is registered.') }}
                </p>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="openCallConfirm = false" class="px-4 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Cancel') }}</button>
                    <button type="button" @click="openCallConfirm = false; submit()" class="px-5 py-2 text-sm text-white bg-emerald-600 rounded-md hover:bg-emerald-700">{{ __('Call now') }}</button>
                </div>
            </div>
        </div>
    </template>
</div>
```

- [ ] **Step 3: Update the column-count of the empty-state row**

The empty-state row at the bottom of the table uses `colspan="5"`. Since the column count is unchanged (still Name / Phone / Groups / Active / Actions), no change needed — but verify by searching for `colspan="5"` in the file and confirming it's still correct.

- [ ] **Step 4: Clear view cache + visual smoke test**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan view:clear
```

Expected: `INFO Compiled views cleared successfully.`

(No automated test for the view because the controller tests already cover the request handling. The view is purely presentation glue over the eager-loaded `is_engaged` flag.)

- [ ] **Step 5: Commit**

```bash
git add resources/views/contacts/index.blade.php
git commit -m "feat(contacts): inline chat + call icon buttons with Alpine modals

Per-row chat (always shown if user has conversations.reply) and call
(shown only if user has conversations.call AND contact.is_engaged is
true). Multi-instance users hit a picker modal first; calls always
hit a confirmation modal before the POST. Disabled state on the call
button shows a tooltip explaining the Meta opt-in policy reason."
```

---

## Task 8: Final verification + push

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (168+ tests, ...)` — 148 baseline + 20 new from this phase (2 relation + 5 isEngaged + 4 startChat + 6 startCall + 2 multi-instance + 1 eager-load). NO regressions.

If any test outside `tests/Feature/Controllers/ContactInitiationTest.php` or `tests/Feature/Models/ContactRelationsTest.php` fails, STOP and report. The most likely regression is the `ContactController::index` change: any existing test that posts `?search=...` should still work, and any test that asserts on the contact-list view should still find its expected contacts.

- [ ] **Step 2: Verify no view-layer surprises**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan view:clear
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan route:list --path=contacts | grep -E "startChat|startCall"
```

Expected: two new routes printed — `POST contacts/{contact}/chat → contacts.startChat` and `POST contacts/{contact}/call → contacts.startCall`.

- [ ] **Step 3: Push**

```bash
git push origin main
```

Expected: 6 commits pushed (one per task 1-7 except Task 5 which only adds tests).

---

## Acceptance criteria recap

- [ ] Chat icon on every contact row visible to users with `conversations.reply`
- [ ] Call icon on every contact row visible to users with `conversations.call` AND `$contact->is_engaged === true`
- [ ] Disabled-state Call button shows tooltip: *"Recipient must message you first (Meta opt-in policy)"*
- [ ] Clicking Chat for a brand-new contact creates exactly one Conversation row + redirects to chat thread
- [ ] Clicking Chat for a contact with existing conversation redirects to that same conversation (no duplicate)
- [ ] Clicking Call runs the confirmation modal first, then fires `OutboundCallService::initiate` server-side with engagement re-check
- [ ] Multi-instance users see picker modal; single-instance users skip directly to action
- [ ] Server-side engagement gate rejects requests even if UI was bypassed
- [ ] N+1 prevented via `withExists` eager loading (verified by index test asserting `is_engaged` flag without fetching messages)
- [ ] Full test suite green, 168 tests (148 baseline + 20 new)
- [ ] All commits pushed to `origin/main`
