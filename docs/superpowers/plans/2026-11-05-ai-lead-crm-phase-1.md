# AI Lead CRM, Phase 1: Lead Sources, Profiles and Pulling. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An admin creates a Lead Profile (plain-English brief plus hard filters), the system turns it into a reviewable search query, and a scheduled job pulls matching leads from Apollo into a separate `leads` table with de-duplication and daily caps.

**Architecture:** One `LeadSource` interface per platform (Apollo first). `LeadQueryBuilder` (AI) derives a structured `LeadQuery` once per profile save; hard filters are merged in code and always win. `LeadPuller` runs one page per source per run, ingests through `LeadIngestor` (dedupe on source + source id), and is queued hourly by `leads:pull`. Leads stay out of `contacts` until a later phase promotes qualified ones.

**Tech Stack:** Laravel 12, PHP 8.2+/8.4, Blade + Tailwind tokens (Signal Desk), spatie/laravel-permission, existing `AiClient`, `Setting` (encrypted), PHPUnit with `RefreshDatabase`, `Http::fake`.

**Spec:** `docs/superpowers/specs/2026-11-05-ai-lead-crm-design.md`

**Out of scope for this phase (later plans):** AI qualification, credit-spending `reveal` orchestration, promotion to Contacts, outreach, reply handling, other connectors.

## Verified Apollo facts (docs.apollo.io, checked 2026-11-05)

- Search: `POST https://api.apollo.io/api/v1/mixed_people/api_search`, header `x-api-key`, parameters go in the **query string**, arrays as `name[]=value`. **0 credits.** Max 100 per page, 500 pages. 401 bad key, 403 key not allowed (needs master key), 422 bad parameters, 429 rate limit.
- Search returns `people[]` with `id`, `first_name`, `last_name_obfuscated`, `title`, `has_email`, `organization.name`; **no email, no domain**. Top-level `total_entries`.
- Enrichment (reveal): `POST /api/v1/people/match?id=<id>`; about 1 credit per email; returns `person.email`, `person.email_status`, `person.organization`.
- Filters used: `person_titles[]`, `person_not_titles[]`, `person_seniorities[]`, `person_locations[]`, `organization_locations[]`, `organization_num_employees_ranges[]` (e.g. `11,50`), `q_keywords`, `contact_email_status[]=verified`, `include_similar_titles`, `page`, `per_page`.

## Conventions to follow

- Every new PHP file starts with `declare(strict_types=1);`.
- The Windows shell collapses backslashes inside heredocs and Python strings; use the Write/Edit tools for PHP, not shell heredocs.
- Local PHP for tests: `C:/xamppp/php/php.exe vendor/bin/phpunit <path>`.
- If `git commit -m` is blocked by a hook false positive, write the message to a file and use `git commit -F <file>`. End commit messages with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Blade: do NOT use the inline `@php(...)` form with nested parentheses; use `@php ... @endphp` blocks.
- Flash keys used by the layout: `success`, `error`, `warning`, `info`.

## File structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_11_05_000001_create_lead_profiles_table.php` | profiles table |
| `database/migrations/2026_11_05_000002_create_leads_table.php` | leads table |
| `database/migrations/2026_11_05_000003_create_lead_events_table.php` | event timeline |
| `app/Models/LeadProfile.php`, `Lead.php`, `LeadEvent.php` | models |
| `database/factories/LeadProfileFactory.php`, `LeadFactory.php` | test data |
| `config/leads.php` | source registry, page size, pull interval |
| `app/Services/Leads/LeadQuery.php` | structured, sanitised search query value object |
| `app/Services/Leads/LeadData.php`, `LeadPage.php` | normalised lead and result page |
| `app/Services/Leads/LeadSource.php` | connector interface |
| `app/Services/Leads/LeadSourceException.php` | connector failure with `retryable` flag |
| `app/Services/Leads/LeadSourceRegistry.php` | resolves connectors from config |
| `app/Services/Leads/Apollo/ApolloLeadSource.php` | Apollo connector |
| `app/Services/Leads/LeadQueryBuilder.php`, `LeadQueryException.php` | brief to `LeadQuery` via AI |
| `app/Services/Leads/LeadIngestor.php` | de-duplicate and store |
| `app/Services/Leads/LeadPuller.php` | one pull pass for a profile |
| `app/Support/LeadConfig.php` | API keys, kill switch, page size |
| `app/Jobs/PullLeadProfileJob.php`, `app/Console/Commands/PullLeads.php` | scheduling |
| `app/Http/Controllers/LeadSourceSettingsController.php` | keys, test, kill switch |
| `app/Http/Controllers/LeadProfileController.php`, `app/Http/Requests/LeadProfileRequest.php` | profile CRUD and actions |
| `app/Http/Controllers/LeadController.php` | leads list |
| `resources/views/lead-sources/index.blade.php`, `lead-profiles/*.blade.php`, `leads/index.blade.php` | screens |
| `tests/Support/FakeAiClient.php` | AI test double |
| `tests/Feature/Leads/*`, `tests/Unit/Leads/*` | tests |

---

### Task 1: Tables, models, factories

**Files:**
- Create: the three migrations, three models, two factories above
- Test: `tests/Feature/Leads/LeadModelsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Leads/LeadModelsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LeadModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_casts_json_columns_and_reads_source_state(): void
    {
        $profile = LeadProfile::factory()->create([
            'hard_filters' => ['countries' => ['Nigeria']],
            'sources' => ['apollo'],
            'pull_state' => ['apollo' => ['next_page' => 3, 'exhausted' => false]],
        ]);

        $fresh = $profile->fresh();
        $this->assertSame(['Nigeria'], $fresh->hard_filters['countries']);
        $this->assertSame(['next_page' => 3, 'exhausted' => false], $fresh->sourceState('apollo'));
        $this->assertSame(['next_page' => 1, 'exhausted' => false], $fresh->sourceState('hunter'));
        $this->assertFalse($fresh->hasQuery());
    }

    public function test_leads_are_unique_per_source_and_source_id(): void
    {
        Lead::factory()->create(['source' => 'apollo', 'source_id' => 'abc']);

        $this->expectException(UniqueConstraintViolationException::class);
        Lead::factory()->create(['source' => 'apollo', 'source_id' => 'abc']);
    }

    public function test_many_leads_may_have_no_email(): void
    {
        Lead::factory()->count(2)->create(['email' => null]);

        $this->assertSame(2, Lead::whereNull('email')->count());
    }

    public function test_deleting_a_profile_keeps_its_leads(): void
    {
        $profile = LeadProfile::factory()->create();
        $lead = Lead::factory()->create(['lead_profile_id' => $profile->id]);

        $profile->delete();

        $this->assertNull($lead->fresh()->lead_profile_id);
    }

    public function test_events_record_type_payload_and_actor(): void
    {
        $lead = Lead::factory()->create();

        $event = LeadEvent::record($lead, 'pulled', ['profile_id' => 7], 'system');

        $this->assertSame('pulled', $event->type);
        $this->assertSame(['profile_id' => 7], $event->fresh()->payload);
        $this->assertSame($lead->id, $lead->events()->first()->lead_id);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/LeadModelsTest.php`
Expected: errors, class `App\Models\LeadProfile` not found.

- [ ] **Step 3: Create the migrations**

`database/migrations/2026_11_05_000001_create_lead_profiles_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(false);
            $table->text('brief');
            $table->json('hard_filters')->nullable();
            $table->json('sources');
            $table->json('lead_query')->nullable();
            $table->unsignedInteger('daily_pull_cap')->default(50);
            // Per source: ['apollo' => ['next_page' => 1, 'exhausted' => false]]
            $table->json('pull_state')->nullable();
            $table->timestamp('last_pulled_at')->nullable();
            $table->text('last_pull_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_profiles');
    }
};
```

`database/migrations/2026_11_05_000002_create_leads_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_profile_id')->nullable()->constrained('lead_profiles')->nullOnDelete();
            $table->string('source', 32);
            $table->string('source_id', 128);
            // Null until a platform reveals it; MySQL and SQLite both allow many NULLs in a unique index.
            $table->string('email')->nullable()->unique();
            $table->string('email_status', 32)->default('unrevealed');
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('company')->nullable();
            $table->string('domain')->nullable();
            $table->string('country')->nullable();
            $table->string('phone')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->json('raw')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('tier', 16)->nullable();
            $table->text('qualification_reason')->nullable();
            $table->string('status', 24)->default('new');
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_id']);
            $table->index(['lead_profile_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
```

`database/migrations/2026_11_05_000003_create_lead_events_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('type', 32);
            $table->json('payload')->nullable();
            // 'system', 'ai' or 'user:<id>'
            $table->string('actor', 32)->default('system');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_events');
    }
};
```

- [ ] **Step 4: Create the models**

`app/Models/LeadProfile.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LeadProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What an admin is hunting for: a plain-English brief, hard filters that always
 * win over the AI, the platforms to search and a daily pull cap.
 */
class LeadProfile extends Model
{
    /** @use HasFactory<LeadProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'is_active', 'brief', 'hard_filters', 'sources', 'lead_query',
        'daily_pull_cap', 'pull_state', 'last_pulled_at', 'last_pull_error', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'hard_filters' => 'array',
            'sources' => 'array',
            'lead_query' => 'array',
            'daily_pull_cap' => 'integer',
            'pull_state' => 'array',
            'last_pulled_at' => 'datetime',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function hasQuery(): bool
    {
        return ! empty($this->lead_query);
    }

    /**
     * @return array{next_page: int, exhausted: bool}
     */
    public function sourceState(string $source): array
    {
        $state = ($this->pull_state ?? [])[$source] ?? [];

        return [
            'next_page' => max(1, (int) ($state['next_page'] ?? 1)),
            'exhausted' => (bool) ($state['exhausted'] ?? false),
        ];
    }
}
```

`app/Models/Lead.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A prospect pulled from a lead platform. Deliberately NOT a Contact: unvetted
 * scraped data must never be reachable by a WhatsApp or email campaign. Only a
 * qualified lead is promoted to a Contact (later phase).
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    public const STATUS_NEW = 'new';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_REPLIED = 'replied';

    public const STATUS_MEETING = 'meeting';

    public const STATUS_WON = 'won';

    public const STATUS_LOST = 'lost';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    public const STATUSES = [
        self::STATUS_NEW, self::STATUS_QUALIFIED, self::STATUS_REJECTED, self::STATUS_CONTACTED,
        self::STATUS_REPLIED, self::STATUS_MEETING, self::STATUS_WON, self::STATUS_LOST, self::STATUS_UNSUBSCRIBED,
    ];

    /** Platform says an email exists but it has not been revealed (revealing costs credits). */
    public const EMAIL_UNREVEALED = 'unrevealed';

    public const EMAIL_UNAVAILABLE = 'unavailable';

    protected $fillable = [
        'lead_profile_id', 'source', 'source_id', 'email', 'email_status', 'name', 'title', 'company',
        'domain', 'country', 'phone', 'linkedin_url', 'raw', 'score', 'tier', 'qualification_reason',
        'status', 'contact_id', 'owner_user_id', 'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'score' => 'integer',
            'last_activity_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(LeadProfile::class, 'lead_profile_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LeadEvent::class);
    }
}
```

`app/Models/LeadEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in a lead's timeline: what happened, to whom, and who or what did it. */
class LeadEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['lead_id', 'type', 'payload', 'actor'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(Lead $lead, string $type, array $payload = [], string $actor = 'system'): self
    {
        return static::create([
            'lead_id' => $lead->id,
            'type' => $type,
            'payload' => $payload === [] ? null : $payload,
            'actor' => $actor,
        ]);
    }
}
```

- [ ] **Step 5: Create the factories**

`database/factories/LeadProfileFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LeadProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadProfile>
 */
class LeadProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Profile '.fake()->unique()->numberBetween(1, 999999),
            'is_active' => false,
            'brief' => 'Founders and CEOs of small fintech companies in Lagos.',
            'hard_filters' => [],
            'sources' => ['apollo'],
            'lead_query' => null,
            'daily_pull_cap' => 50,
        ];
    }

    public function withQuery(array $query = ['titles' => ['CEO']]): static
    {
        return $this->state(fn () => ['lead_query' => $query]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }
}
```

`database/factories/LeadFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_profile_id' => null,
            'source' => 'apollo',
            'source_id' => uniqid('src_', true),
            'email' => null,
            'email_status' => Lead::EMAIL_UNREVEALED,
            'name' => fake()->name(),
            'title' => 'CEO',
            'company' => fake()->company(),
            'status' => Lead::STATUS_NEW,
        ];
    }
}
```

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/LeadModelsTest.php`
Expected: `OK (5 tests, ...)`

- [ ] **Step 7: Commit**

```bash
git add database app/Models tests/Feature/Leads
git commit -m "feat(leads): lead profiles, leads and events tables with models and factories"
```

---

### Task 2: Value objects, connector interface and registry

**Files:**
- Create: `config/leads.php`, `app/Services/Leads/{LeadQuery,LeadData,LeadPage,LeadSource,LeadSourceException,LeadSourceRegistry}.php`
- Test: `tests/Unit/Leads/LeadQueryTest.php`, `tests/Unit/Leads/LeadDataTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Leads/LeadQueryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Leads;

use App\Services\Leads\LeadQuery;
use PHPUnit\Framework\TestCase;

final class LeadQueryTest extends TestCase
{
    public function test_from_array_trims_dedupes_and_drops_junk(): void
    {
        $query = LeadQuery::fromArray([
            'titles' => [' CEO ', 'ceo', '', 42, 'Founder'],
            'seniorities' => ['C_Suite', 'wizard', 'founder'],
            'employee_ranges' => ['11,50', 'lots', '51,200'],
            'keywords' => '  fintech  ',
        ]);

        $this->assertSame(['CEO', 'Founder'], $query->titles);
        $this->assertSame(['c_suite', 'founder'], $query->seniorities);
        $this->assertSame(['11,50', '51,200'], $query->employeeRanges);
        $this->assertSame('fintech', $query->keywords);
    }

    public function test_from_array_caps_list_length(): void
    {
        $titles = array_map(fn (int $i) => "Title $i", range(1, 80));

        $this->assertCount(30, LeadQuery::fromArray(['titles' => $titles])->titles);
    }

    public function test_hard_filters_override_locations_and_ranges_and_add_titles(): void
    {
        $query = LeadQuery::fromArray([
            'titles' => ['CEO'],
            'person_locations' => ['Ghana'],
            'employee_ranges' => ['1,10'],
        ])->withHardFilters([
            'countries' => ['Nigeria'],
            'titles_include' => ['Founder'],
            'titles_exclude' => ['Intern'],
            'employee_ranges' => ['11,50'],
            'require_verified_email' => true,
        ]);

        $this->assertSame(['Nigeria'], $query->personLocations);
        $this->assertSame(['CEO', 'Founder'], $query->titles);
        $this->assertSame(['Intern'], $query->excludeTitles);
        $this->assertSame(['11,50'], $query->employeeRanges);
        $this->assertTrue($query->requireVerifiedEmail);
    }

    public function test_hard_filters_that_are_empty_leave_the_ai_values_alone(): void
    {
        $query = LeadQuery::fromArray(['person_locations' => ['Ghana']])->withHardFilters([]);

        $this->assertSame(['Ghana'], $query->personLocations);
    }

    public function test_is_empty_and_round_trip(): void
    {
        $this->assertTrue((new LeadQuery)->isEmpty());

        $query = LeadQuery::fromArray(['titles' => ['CEO'], 'require_verified_email' => true]);
        $this->assertFalse($query->isEmpty());
        $this->assertEquals($query, LeadQuery::fromArray($query->toArray()));
    }
}
```

`tests/Unit/Leads/LeadDataTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Leads;

use App\Services\Leads\LeadData;
use PHPUnit\Framework\TestCase;

final class LeadDataTest extends TestCase
{
    public function test_with_returns_a_changed_copy_and_leaves_the_original(): void
    {
        $lead = new LeadData(source: 'apollo', sourceId: '1', name: 'Ada Ob***e', title: 'CEO', company: 'Acme');

        $revealed = $lead->with(['name' => 'Ada Obi', 'email' => 'ada@acme.test', 'emailStatus' => 'verified']);

        $this->assertSame('Ada Ob***e', $lead->name);
        $this->assertNull($lead->email);
        $this->assertSame('Ada Obi', $revealed->name);
        $this->assertSame('ada@acme.test', $revealed->email);
        $this->assertSame('Acme', $revealed->company);
    }
}
```

- [ ] **Step 2: Run and confirm failure**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Unit/Leads`
Expected: class not found errors.

- [ ] **Step 3: Create the classes**

`config/leads.php`:

```php
<?php

declare(strict_types=1);

return [

    /*
    | Lead platforms. key => connector class (implements App\Services\Leads\LeadSource).
    | Adding a platform is one class plus one line here.
    */
    'sources' => [
        'apollo' => \App\Services\Leads\Apollo\ApolloLeadSource::class,
    ],

    // People requested per search page. Search is free on Apollo; this only sets batch size.
    'page_size' => (int) env('LEADS_PAGE_SIZE', 25),

    // A profile is pulled at most once per this many minutes by the scheduler.
    'pull_interval_minutes' => (int) env('LEADS_PULL_INTERVAL_MINUTES', 50),
];
```

`app/Services/Leads/LeadQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Leads;

/**
 * The structured search a profile runs. Built once from the brief (AI) plus the
 * hard filters (code) and stored on the profile so an admin can read and trust it.
 * Everything is sanitised here: the AI's output is untrusted.
 */
final readonly class LeadQuery
{
    public const SENIORITIES = [
        'owner', 'founder', 'c_suite', 'partner', 'vp', 'head', 'director', 'manager', 'senior', 'entry', 'intern',
    ];

    private const MAX_ITEMS = 30;

    private const MAX_LENGTH = 80;

    /**
     * @param  list<string>  $titles
     * @param  list<string>  $excludeTitles
     * @param  list<string>  $seniorities
     * @param  list<string>  $personLocations
     * @param  list<string>  $organizationLocations
     * @param  list<string>  $employeeRanges  "min,max" strings
     */
    public function __construct(
        public array $titles = [],
        public array $excludeTitles = [],
        public array $seniorities = [],
        public array $personLocations = [],
        public array $organizationLocations = [],
        public array $employeeRanges = [],
        public string $keywords = '',
        public bool $requireVerifiedEmail = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            titles: self::strings($data['titles'] ?? []),
            excludeTitles: self::strings($data['exclude_titles'] ?? []),
            seniorities: array_values(array_intersect(
                array_map('mb_strtolower', self::strings($data['seniorities'] ?? [])),
                self::SENIORITIES,
            )),
            personLocations: self::strings($data['person_locations'] ?? []),
            organizationLocations: self::strings($data['organization_locations'] ?? []),
            employeeRanges: self::ranges($data['employee_ranges'] ?? []),
            keywords: mb_substr(trim((string) ($data['keywords'] ?? '')), 0, 200),
            requireVerifiedEmail: (bool) ($data['require_verified_email'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'titles' => $this->titles,
            'exclude_titles' => $this->excludeTitles,
            'seniorities' => $this->seniorities,
            'person_locations' => $this->personLocations,
            'organization_locations' => $this->organizationLocations,
            'employee_ranges' => $this->employeeRanges,
            'keywords' => $this->keywords,
            'require_verified_email' => $this->requireVerifiedEmail,
        ];
    }

    /**
     * Apply the admin's hard filters. They always win over what the AI inferred:
     * countries and employee ranges replace, titles are added, exclusions are added.
     *
     * @param  array<string, mixed>  $filters
     */
    public function withHardFilters(array $filters): self
    {
        $countries = self::strings($filters['countries'] ?? []);
        $ranges = self::ranges($filters['employee_ranges'] ?? []);

        return new self(
            titles: self::strings([...$this->titles, ...self::strings($filters['titles_include'] ?? [])]),
            excludeTitles: self::strings([...$this->excludeTitles, ...self::strings($filters['titles_exclude'] ?? [])]),
            seniorities: $this->seniorities,
            personLocations: $countries !== [] ? $countries : $this->personLocations,
            organizationLocations: $this->organizationLocations,
            employeeRanges: $ranges !== [] ? $ranges : $this->employeeRanges,
            keywords: $this->keywords,
            requireVerifiedEmail: $this->requireVerifiedEmail || (bool) ($filters['require_verified_email'] ?? false),
        );
    }

    /** True when there is nothing to search for (a search would match the whole database). */
    public function isEmpty(): bool
    {
        return $this->titles === []
            && $this->seniorities === []
            && $this->personLocations === []
            && $this->organizationLocations === []
            && $this->employeeRanges === []
            && $this->keywords === '';
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $out[mb_strtolower($item)] ??= mb_substr($item, 0, self::MAX_LENGTH);
        }

        return array_slice(array_values($out), 0, self::MAX_ITEMS);
    }

    /**
     * @return list<string>
     */
    private static function ranges(mixed $value): array
    {
        return array_values(array_filter(
            self::strings($value),
            fn (string $range): bool => preg_match('/^\d+,\d+$/', $range) === 1,
        ));
    }
}
```

`app/Services/Leads/LeadData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Leads;

/** A lead as a platform describes it, normalised so every connector returns the same shape. */
final readonly class LeadData
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $source,
        public string $sourceId,
        public string $name,
        public ?string $title = null,
        public ?string $company = null,
        public ?string $email = null,
        public string $emailStatus = 'unrevealed',
        public ?string $domain = null,
        public ?string $country = null,
        public ?string $phone = null,
        public ?string $linkedinUrl = null,
        public array $raw = [],
    ) {}

    /**
     * A copy with some fields replaced (this object is immutable).
     *
     * @param  array<string, mixed>  $changes  keyed by property name
     */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
```

`app/Services/Leads/LeadPage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Leads;

/** One page of search results. */
final readonly class LeadPage
{
    /**
     * @param  list<LeadData>  $leads
     */
    public function __construct(
        public array $leads,
        public int $total,
        public bool $hasMore,
    ) {}
}
```

`app/Services/Leads/LeadSourceException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Leads;

use RuntimeException;
use Throwable;

/**
 * A platform call failed. `retryable` tells the puller whether trying again later
 * can help (rate limit, outage) or whether an admin must fix something (bad key).
 */
final class LeadSourceException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
```

`app/Services/Leads/LeadSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Leads;

interface LeadSource
{
    /** Stable machine key, e.g. "apollo". Matches the key in config/leads.php. */
    public function key(): string;

    public function label(): string;

    /** True when credentials are present. */
    public function isConfigured(): bool;

    /**
     * Make a cheap call that proves the credentials work.
     *
     * @throws LeadSourceException
     */
    public function testConnection(): void;

    /**
     * @throws LeadSourceException
     */
    public function search(LeadQuery $query, int $page, int $perPage): LeadPage;

    /**
     * Fetch the contact details a search hides (usually spends the platform's credits).
     *
     * @throws LeadSourceException
     */
    public function reveal(LeadData $lead): LeadData;
}
```

`app/Services/Leads/LeadSourceRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Leads;

use InvalidArgumentException;

/** Resolves connectors from config/leads.php. */
final class LeadSourceRegistry
{
    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys((array) config('leads.sources', []));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, (array) config('leads.sources', []));
    }

    public function get(string $key): LeadSource
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Unknown lead source [{$key}].");
        }

        return app(config("leads.sources.{$key}"));
    }

    /**
     * @return array<string, LeadSource>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->keys() as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Unit/Leads`
Expected: `OK (6 tests, ...)`. (`ApolloLeadSource` is referenced only as a string in config, so it need not exist yet.)

- [ ] **Step 5: Commit**

```bash
git add config/leads.php app/Services/Leads tests/Unit/Leads
git commit -m "feat(leads): lead query, lead data, connector interface and registry"
```

---

### Task 3: Lead configuration (API keys and kill switch)

**Files:**
- Create: `app/Support/LeadConfig.php`
- Modify: `config/services.php` (add `apollo`)
- Test: `tests/Feature/Leads/LeadConfigTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Setting;
use App\Support\LeadConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LeadConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_key_is_null_when_nothing_is_set(): void
    {
        config(['services.apollo.key' => null]);

        $this->assertNull(LeadConfig::key('apollo'));
    }

    public function test_a_saved_key_is_stored_encrypted_and_read_back(): void
    {
        LeadConfig::setKey('apollo', 'secret-key-123456');

        $this->assertSame('secret-key-123456', LeadConfig::key('apollo'));
        $this->assertNotSame('secret-key-123456', Setting::get('lead_source_apollo_key'));
    }

    public function test_saved_key_beats_the_environment_default(): void
    {
        config(['services.apollo.key' => 'env-key-0000000000']);
        LeadConfig::setKey('apollo', 'db-key-11111111111');

        $this->assertSame('db-key-11111111111', LeadConfig::key('apollo'));
    }

    public function test_environment_key_is_used_when_nothing_is_saved(): void
    {
        config(['services.apollo.key' => 'env-key-0000000000']);

        $this->assertSame('env-key-0000000000', LeadConfig::key('apollo'));
    }

    public function test_kill_switch_defaults_off_and_toggles(): void
    {
        $this->assertFalse(LeadConfig::killSwitchOn());

        LeadConfig::setKillSwitch(true);
        $this->assertTrue(LeadConfig::killSwitchOn());

        LeadConfig::setKillSwitch(false);
        $this->assertFalse(LeadConfig::killSwitchOn());
    }
}
```

- [ ] **Step 2: Run and confirm failure**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/LeadConfigTest.php`
Expected: `App\Support\LeadConfig` not found.

- [ ] **Step 3: Implement**

`app/Support/LeadConfig.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\QueryException;

/**
 * Where lead-platform keys and the global kill switch live. Same pattern as
 * GeminiConfig / AiConfig: a key saved in Settings (encrypted) wins, the
 * environment is the fallback.
 */
final class LeadConfig
{
    public static function key(string $source): ?string
    {
        try {
            $saved = Setting::getEncrypted(self::keySetting($source));
            if (filled($saved)) {
                return $saved;
            }
        } catch (QueryException) {
            // No settings table (isolated test, pre-migration): fall back to env.
        }

        $env = (string) config("services.{$source}.key");

        return $env !== '' ? $env : null;
    }

    public static function setKey(string $source, string $key): void
    {
        Setting::setEncrypted(self::keySetting($source), $key);
    }

    /** One switch that halts pulling, qualifying and every automatic send. */
    public static function killSwitchOn(): bool
    {
        try {
            return Setting::get('leads_kill_switch') === '1';
        } catch (QueryException) {
            return false;
        }
    }

    public static function setKillSwitch(bool $on): void
    {
        Setting::set('leads_kill_switch', $on ? '1' : '0');
    }

    public static function pageSize(): int
    {
        return max(1, min(100, (int) config('leads.page_size', 25)));
    }

    private static function keySetting(string $source): string
    {
        return "lead_source_{$source}_key";
    }
}
```

In `config/services.php`, directly after the closing `],` of the `'gemini'` entry, add:

```php
    'apollo' => [
        'key' => env('APOLLO_API_KEY'),
    ],
```

- [ ] **Step 4: Run and confirm pass**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/LeadConfigTest.php`
Expected: `OK (5 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add app/Support/LeadConfig.php config/services.php tests/Feature/Leads/LeadConfigTest.php
git commit -m "feat(leads): encrypted lead-source keys and global kill switch"
```

---

### Task 4: Apollo connector

**Files:**
- Create: `app/Services/Leads/Apollo/ApolloLeadSource.php`
- Test: `tests/Feature/Leads/ApolloLeadSourceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Services\Leads\Apollo\ApolloLeadSource;
use App\Services\Leads\LeadData;
use App\Services\Leads\LeadQuery;
use App\Services\Leads\LeadSourceException;
use App\Support\LeadConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ApolloLeadSourceTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH = 'https://api.apollo.io/api/v1/mixed_people/api_search*';

    private const MATCH = 'https://api.apollo.io/api/v1/people/match*';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.apollo.key' => null]);
        LeadConfig::setKey('apollo', 'apollo-test-key-123');
    }

    private function source(): ApolloLeadSource
    {
        return new ApolloLeadSource;
    }

    private function person(array $overrides = []): array
    {
        return array_merge([
            'id' => 'p1',
            'first_name' => 'Ada',
            'last_name_obfuscated' => 'Ob***i',
            'title' => 'CEO',
            'has_email' => true,
            'organization' => ['name' => 'Acme Pay'],
        ], $overrides);
    }

    public function test_search_normalises_people_and_never_invents_an_email(): void
    {
        Http::fake([self::SEARCH => Http::response([
            'total_entries' => 60,
            'people' => [$this->person(), $this->person(['id' => 'p2', 'has_email' => false, 'title' => null])],
        ])]);

        $page = $this->source()->search(new LeadQuery(titles: ['CEO']), 1, 25);

        $this->assertCount(2, $page->leads);
        $this->assertSame(60, $page->total);
        $this->assertTrue($page->hasMore);

        $first = $page->leads[0];
        $this->assertSame('apollo', $first->source);
        $this->assertSame('p1', $first->sourceId);
        $this->assertSame('Ada Ob***i', $first->name);
        $this->assertSame('Acme Pay', $first->company);
        $this->assertNull($first->email);
        $this->assertSame(Lead::EMAIL_UNREVEALED, $first->emailStatus);

        $this->assertSame(Lead::EMAIL_UNAVAILABLE, $page->leads[1]->emailStatus);
        $this->assertNull($page->leads[1]->title);
    }

    public function test_search_sends_filters_as_bracketed_query_params_with_the_api_key_header(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 0, 'people' => []])]);

        $this->source()->search(new LeadQuery(
            titles: ['CEO', 'Founder'],
            excludeTitles: ['Intern'],
            seniorities: ['c_suite'],
            personLocations: ['Lagos, Nigeria'],
            employeeRanges: ['11,50'],
            keywords: 'fintech',
            requireVerifiedEmail: true,
        ), 2, 25);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'POST'
                && ($request->header('x-api-key')[0] ?? null) === 'apollo-test-key-123'
                && str_contains($url, 'person_titles[]=CEO')
                && str_contains($url, 'person_titles[]=Founder')
                && str_contains($url, 'person_not_titles[]=Intern')
                && str_contains($url, 'person_seniorities[]=c_suite')
                && str_contains($url, 'person_locations[]=Lagos, Nigeria')
                && str_contains($url, 'organization_num_employees_ranges[]=11,50')
                && str_contains($url, 'q_keywords=fintech')
                && str_contains($url, 'contact_email_status[]=verified')
                && str_contains($url, 'page=2')
                && str_contains($url, 'per_page=25');
        });
    }

    public function test_has_more_is_false_on_the_last_page(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 60, 'people' => [$this->person()]])]);

        $page = $this->source()->search(new LeadQuery(titles: ['CEO']), 3, 25);

        $this->assertFalse($page->hasMore);
    }

    public function test_people_without_an_id_are_skipped(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 2, 'people' => [$this->person(['id' => '']), $this->person()]])]);

        $this->assertCount(1, $this->source()->search(new LeadQuery(titles: ['CEO']), 1, 25)->leads);
    }

    /**
     * @return array<string, array{int, bool, string}>
     */
    public static function failures(): array
    {
        return [
            'bad key' => [401, false, 'API key'],
            'not allowed' => [403, false, 'master'],
            'bad filters' => [422, false, 'filters'],
            'rate limited' => [429, true, 'rate limit'],
            'outage' => [503, true, 'unavailable'],
        ];
    }

    /**
     * @dataProvider failures
     */
    public function test_http_failures_become_typed_exceptions(int $status, bool $retryable, string $needle): void
    {
        Http::fake([self::SEARCH => Http::response('nope', $status)]);

        try {
            $this->source()->search(new LeadQuery(titles: ['CEO']), 1, 25);
            $this->fail('Expected LeadSourceException');
        } catch (LeadSourceException $e) {
            $this->assertSame($retryable, $e->retryable);
            $this->assertStringContainsStringIgnoringCase($needle, $e->getMessage());
        }
    }

    public function test_missing_key_is_a_non_retryable_error_and_sends_nothing(): void
    {
        Http::fake();
        LeadConfig::setKey('apollo', '');

        $this->assertFalse($this->source()->isConfigured());

        try {
            $this->source()->search(new LeadQuery(titles: ['CEO']), 1, 25);
            $this->fail('Expected LeadSourceException');
        } catch (LeadSourceException $e) {
            $this->assertFalse($e->retryable);
        }

        Http::assertNothingSent();
    }

    public function test_test_connection_succeeds_on_200_and_throws_on_401(): void
    {
        Http::fake([self::SEARCH => Http::sequence()
            ->push(['total_entries' => 1, 'people' => []])
            ->push('no', 401)]);

        $this->source()->testConnection();

        $this->expectException(LeadSourceException::class);
        $this->source()->testConnection();
    }

    public function test_reveal_fills_in_the_email_and_company_details(): void
    {
        Http::fake([self::MATCH => Http::response(['person' => [
            'name' => 'Ada Obi',
            'title' => 'Chief Executive Officer',
            'email' => 'Ada@AcmePay.test ',
            'email_status' => 'verified',
            'linkedin_url' => 'https://linkedin.com/in/adaobi',
            'country' => 'Nigeria',
            'organization' => ['name' => 'Acme Pay', 'primary_domain' => 'acmepay.test'],
        ]])]);

        $lead = new LeadData(source: 'apollo', sourceId: 'p1', name: 'Ada Ob***i', title: 'CEO', company: 'Acme Pay');

        $revealed = $this->source()->reveal($lead);

        $this->assertSame('ada@acmepay.test', $revealed->email);
        $this->assertSame('verified', $revealed->emailStatus);
        $this->assertSame('Ada Obi', $revealed->name);
        $this->assertSame('acmepay.test', $revealed->domain);
        $this->assertSame('Nigeria', $revealed->country);
        $this->assertSame('https://linkedin.com/in/adaobi', $revealed->linkedinUrl);

        Http::assertSent(fn (Request $r): bool => str_contains(urldecode($r->url()), 'id=p1')
            && str_contains(urldecode($r->url()), 'reveal_personal_emails=false'));
    }

    public function test_reveal_treats_a_locked_placeholder_email_as_unavailable(): void
    {
        Http::fake([self::MATCH => Http::response(['person' => ['email' => 'email_not_unlocked@acmepay.test']])]);

        $revealed = $this->source()->reveal(new LeadData(source: 'apollo', sourceId: 'p1', name: 'Ada'));

        $this->assertNull($revealed->email);
        $this->assertSame(Lead::EMAIL_UNAVAILABLE, $revealed->emailStatus);
    }

    public function test_reveal_with_no_person_marks_the_email_unavailable(): void
    {
        Http::fake([self::MATCH => Http::response(['person' => null])]);

        $revealed = $this->source()->reveal(new LeadData(source: 'apollo', sourceId: 'p1', name: 'Ada'));

        $this->assertNull($revealed->email);
        $this->assertSame(Lead::EMAIL_UNAVAILABLE, $revealed->emailStatus);
    }
}
```

- [ ] **Step 2: Run and confirm failure**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/ApolloLeadSourceTest.php`
Expected: `ApolloLeadSource` not found.

- [ ] **Step 3: Implement**

`app/Services/Leads/Apollo/ApolloLeadSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Leads\Apollo;

use App\Models\Lead;
use App\Services\Leads\LeadData;
use App\Services\Leads\LeadPage;
use App\Services\Leads\LeadQuery;
use App\Services\Leads\LeadSource;
use App\Services\Leads\LeadSourceException;
use App\Support\LeadConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Apollo.io connector.
 *
 * Search (mixed_people/api_search) is free but returns only a first name, an
 * obfuscated last name, title and company, never an email. reveal() (people/match)
 * returns the email and costs about one credit, so callers decide when to spend.
 * Parameters travel in the query string with array keys written `name[]=value`.
 */
final class ApolloLeadSource implements LeadSource
{
    private const BASE = 'https://api.apollo.io/api/v1';

    /** Apollo caps search results at 500 pages of 100. */
    private const MAX_RESULTS = 50000;

    public function key(): string
    {
        return 'apollo';
    }

    public function label(): string
    {
        return 'Apollo.io';
    }

    public function isConfigured(): bool
    {
        return filled(LeadConfig::key('apollo'));
    }

    public function testConnection(): void
    {
        // A one-row search: free, and exercises exactly the permission the pull needs.
        $this->search(new LeadQuery(titles: ['CEO']), 1, 1);
    }

    public function search(LeadQuery $query, int $page, int $perPage): LeadPage
    {
        $response = $this->post('/mixed_people/api_search', $this->searchParams($query, $page, $perPage));

        $people = (array) $response->json('people', []);
        $leads = [];
        foreach ($people as $person) {
            $lead = is_array($person) ? $this->toLead($person) : null;
            if ($lead !== null) {
                $leads[] = $lead;
            }
        }

        $total = min((int) $response->json('total_entries', count($leads)), self::MAX_RESULTS);

        return new LeadPage($leads, $total, $people !== [] && $page * $perPage < $total);
    }

    public function reveal(LeadData $lead): LeadData
    {
        $response = $this->post('/people/match', [
            'id' => $lead->sourceId,
            'reveal_personal_emails' => false,
        ]);

        $person = $response->json('person');
        if (! is_array($person)) {
            return $lead->with(['emailStatus' => Lead::EMAIL_UNAVAILABLE]);
        }

        $email = $person['email'] ?? null;
        if (! is_string($email) || trim($email) === '' || str_contains($email, 'not_unlocked')) {
            return $lead->with(['emailStatus' => Lead::EMAIL_UNAVAILABLE]);
        }

        $organization = is_array($person['organization'] ?? null) ? $person['organization'] : [];

        return $lead->with([
            'name' => $this->text($person['name'] ?? null) ?? $lead->name,
            'title' => $this->text($person['title'] ?? null) ?? $lead->title,
            'company' => $this->text($organization['name'] ?? null) ?? $lead->company,
            'email' => strtolower(trim($email)),
            'emailStatus' => (string) ($person['email_status'] ?? 'unknown'),
            'domain' => $this->domainOf($organization),
            'country' => $this->text($person['country'] ?? null),
            'linkedinUrl' => $this->text($person['linkedin_url'] ?? null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function searchParams(LeadQuery $query, int $page, int $perPage): array
    {
        return [
            'person_titles' => $query->titles,
            'include_similar_titles' => $query->titles !== [] ? true : null,
            'person_not_titles' => $query->excludeTitles,
            'person_seniorities' => $query->seniorities,
            'person_locations' => $query->personLocations,
            'organization_locations' => $query->organizationLocations,
            'organization_num_employees_ranges' => $query->employeeRanges,
            'q_keywords' => $query->keywords,
            'contact_email_status' => $query->requireVerifiedEmail ? ['verified'] : [],
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function toLead(array $person): ?LeadData
    {
        $id = (string) ($person['id'] ?? '');
        if ($id === '') {
            return null;
        }

        $name = trim(((string) ($person['first_name'] ?? '')).' '.((string) ($person['last_name_obfuscated'] ?? $person['last_name'] ?? '')));

        return new LeadData(
            source: 'apollo',
            sourceId: $id,
            name: $name !== '' ? $name : 'Unknown',
            title: $this->text($person['title'] ?? null),
            company: $this->text($person['organization']['name'] ?? null),
            emailStatus: ($person['has_email'] ?? false) ? Lead::EMAIL_UNREVEALED : Lead::EMAIL_UNAVAILABLE,
            raw: $person,
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function post(string $path, array $params): Response
    {
        $key = LeadConfig::key('apollo');
        if ($key === null) {
            throw new LeadSourceException('Apollo is not configured: add an API key under Lead Sources.');
        }

        try {
            $response = $this->http($key)->post(self::BASE.$path.'?'.$this->queryString($params));
        } catch (ConnectionException $e) {
            throw new LeadSourceException('Could not reach Apollo: '.$e->getMessage(), true, $e);
        }

        $this->guard($response);

        return $response;
    }

    private function http(string $key): PendingRequest
    {
        return Http::timeout(30)->acceptJson()->withHeaders([
            'x-api-key' => $key,
            'Cache-Control' => 'no-cache',
        ]);
    }

    private function guard(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        throw match (true) {
            $status === 401 => new LeadSourceException('Apollo rejected the API key (401). Check the key under Lead Sources.'),
            $status === 403 => new LeadSourceException('This Apollo key is not allowed to use people search (403). Use a master API key.'),
            $status === 422 => new LeadSourceException('Apollo rejected the search filters (422). Edit the profile and rebuild the search.'),
            $status === 429 => new LeadSourceException('Apollo rate limit reached (429); will retry on the next run.', true),
            $status >= 500 => new LeadSourceException("Apollo is unavailable ({$status}); will retry on the next run.", true),
            default => new LeadSourceException("Apollo returned an unexpected response ({$status})."),
        };
    }

    /**
     * Apollo wants arrays as `name[]=a&name[]=b`; Guzzle's own builder would index them.
     *
     * @param  array<string, mixed>  $params
     */
    private function queryString(array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    $pairs[] = $key.'[]='.rawurlencode((string) $item);
                }

                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $pairs[] = $key.'='.rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $organization
     */
    private function domainOf(array $organization): ?string
    {
        $domain = $this->text($organization['primary_domain'] ?? null);
        if ($domain !== null) {
            return strtolower($domain);
        }

        $host = parse_url((string) ($organization['website_url'] ?? ''), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower(preg_replace('/^www\./i', '', $host)) : null;
    }
}
```

- [ ] **Step 4: Run and confirm pass**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/ApolloLeadSourceTest.php`
Expected: `OK (...)`. If a bracketed-param assertion fails, print `urldecode($request->url())` and compare with the expected substrings before changing the code.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Leads/Apollo tests/Feature/Leads/ApolloLeadSourceTest.php
git commit -m "feat(leads): Apollo connector (free people search, credit-costing reveal)"
```

---

### Task 5: Brief to search query (AI)

**Files:**
- Create: `tests/Support/FakeAiClient.php`, `app/Services/Leads/LeadQueryException.php`, `app/Services/Leads/LeadQueryBuilder.php`
- Test: `tests/Feature/Leads/LeadQueryBuilderTest.php`

- [ ] **Step 1: Create the AI test double**

`tests/Support/FakeAiClient.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Ai\AiClient;
use App\Services\Ai\AiResult;

/** Returns a canned reply (or null, like a failed provider) and remembers the prompt. */
final class FakeAiClient implements AiClient
{
    /** @var list<array<int, array{role: string, content: string}>> */
    public array $calls = [];

    public function __construct(private readonly ?string $reply) {}

    public function chat(array $messages, array $opts = []): ?AiResult
    {
        $this->calls[] = $messages;

        return $this->reply === null ? null : new AiResult($this->reply, 'fake-model', 10, 10);
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/Leads/LeadQueryBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Services\Leads\LeadQueryBuilder;
use App\Services\Leads\LeadQueryException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeAiClient;

final class LeadQueryBuilderTest extends TestCase
{
    public function test_turns_the_ai_json_into_a_sanitised_query(): void
    {
        $ai = new FakeAiClient(json_encode([
            'titles' => ['Founder', 'CEO'],
            'seniorities' => ['c_suite', 'bogus'],
            'person_locations' => ['Lagos, Nigeria'],
            'keywords' => 'fintech',
        ]));

        $query = (new LeadQueryBuilder($ai))->build('Fintech founders in Lagos', []);

        $this->assertSame(['Founder', 'CEO'], $query->titles);
        $this->assertSame(['c_suite'], $query->seniorities);
        $this->assertSame(['Lagos, Nigeria'], $query->personLocations);
        $this->assertSame('fintech', $query->keywords);
    }

    public function test_tolerates_code_fences_around_the_json(): void
    {
        $ai = new FakeAiClient("```json\n{\"titles\":[\"CTO\"]}\n```");

        $this->assertSame(['CTO'], (new LeadQueryBuilder($ai))->build('CTOs', [])->titles);
    }

    public function test_hard_filters_win_over_the_ai(): void
    {
        $ai = new FakeAiClient(json_encode(['titles' => ['CEO'], 'person_locations' => ['Ghana']]));

        $query = (new LeadQueryBuilder($ai))->build('CEOs', ['countries' => ['Nigeria'], 'titles_exclude' => ['Intern']]);

        $this->assertSame(['Nigeria'], $query->personLocations);
        $this->assertSame(['Intern'], $query->excludeTitles);
    }

    public function test_the_brief_is_sent_to_the_model(): void
    {
        $ai = new FakeAiClient(json_encode(['titles' => ['CEO']]));

        (new LeadQueryBuilder($ai))->build('Heads of procurement at hospitals', []);

        $this->assertStringContainsString('Heads of procurement at hospitals', $ai->calls[0][0]['content']);
    }

    public function test_a_failed_ai_call_throws(): void
    {
        $this->expectException(LeadQueryException::class);

        (new LeadQueryBuilder(new FakeAiClient(null)))->build('CEOs', []);
    }

    public function test_non_json_throws(): void
    {
        $this->expectException(LeadQueryException::class);

        (new LeadQueryBuilder(new FakeAiClient('Sure, here you go!')))->build('CEOs', []);
    }

    public function test_a_result_with_nothing_to_search_for_throws(): void
    {
        $this->expectException(LeadQueryException::class);

        (new LeadQueryBuilder(new FakeAiClient('{}')))->build('stuff', []);
    }
}
```

(This test uses no database, so it extends `PHPUnit\Framework\TestCase`.)

- [ ] **Step 3: Run and confirm failure**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/LeadQueryBuilderTest.php`
Expected: `LeadQueryBuilder` not found.

- [ ] **Step 4: Implement**

`app/Services/Leads/LeadQueryException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Leads;

use RuntimeException;

/** The AI could not produce a usable search from the brief. */
final class LeadQueryException extends RuntimeException {}
```

`app/Services/Leads/LeadQueryBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Services\Ai\AiClient;

/**
 * Turns an admin's plain-English brief into a structured LeadQuery once, when the
 * profile is saved. Pulls then run with no AI cost, and the admin can read the
 * result. Hard filters are applied in code afterwards so they can never be
 * overridden by the model.
 */
final class LeadQueryBuilder
{
    public function __construct(private readonly AiClient $ai) {}

    /**
     * @param  array<string, mixed>  $hardFilters
     *
     * @throws LeadQueryException
     */
    public function build(string $brief, array $hardFilters): LeadQuery
    {
        $result = $this->ai->chat(
            messages: [['role' => 'user', 'content' => $this->prompt($brief)]],
            opts: ['tier' => 'classify', 'temperature' => 0.1, 'max_tokens' => 600],
        );

        if ($result === null) {
            throw new LeadQueryException('The AI provider is not configured or did not answer. Check Settings, then rebuild the search.');
        }

        $data = $this->decode($result->text);
        $query = LeadQuery::fromArray($data)->withHardFilters($hardFilters);

        if ($query->isEmpty()) {
            throw new LeadQueryException('Could not derive any search filters from that brief. Add job titles, a location or an industry.');
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $text): array
    {
        if (preg_match('/\{.*\}/s', $text, $match) !== 1) {
            throw new LeadQueryException('The AI did not return search filters. Try rebuilding.');
        }

        $data = json_decode($match[0], true);

        if (! is_array($data)) {
            throw new LeadQueryException('The AI returned filters that could not be read. Try rebuilding.');
        }

        return $data;
    }

    private function prompt(string $brief): string
    {
        return <<<PROMPT
You turn a sales-lead brief into search filters for a B2B contact database.
Reply with ONE JSON object and nothing else. Every key is optional; values are arrays of short strings unless stated.
- "titles": job titles to match, e.g. ["CEO","Managing Director"]
- "exclude_titles": job titles to exclude
- "seniorities": any of owner, founder, c_suite, partner, vp, head, director, manager, senior, entry, intern
- "person_locations": where the person is based, e.g. ["Lagos, Nigeria"]
- "organization_locations": where the company is headquartered
- "employee_ranges": strings "min,max", e.g. ["11,50","51,200"]
- "keywords": ONE short string of industry or topic words (not an array)
Only include what the brief clearly asks for. Do not invent constraints.

BRIEF:
{$brief}
PROMPT;
    }
}
```

- [ ] **Step 5: Run and confirm pass**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/LeadQueryBuilderTest.php`
Expected: `OK (7 tests, ...)`

- [ ] **Step 6: Commit**

```bash
git add tests/Support app/Services/Leads/LeadQueryBuilder.php app/Services/Leads/LeadQueryException.php tests/Feature/Leads/LeadQueryBuilderTest.php
git commit -m "feat(leads): AI turns a plain-English brief into a reviewable search query"
```

---

### Task 6: Ingest and de-duplicate

**Files:**
- Create: `app/Services/Leads/LeadIngestor.php`
- Test: `tests/Feature/Leads/LeadIngestorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadProfile;
use App\Services\Leads\LeadData;
use App\Services\Leads\LeadIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LeadIngestorTest extends TestCase
{
    use RefreshDatabase;

    private function data(string $id, array $overrides = []): LeadData
    {
        return (new LeadData(
            source: 'apollo',
            sourceId: $id,
            name: 'Ada Ob***i',
            title: 'CEO',
            company: 'Acme Pay',
            raw: ['id' => $id],
        ))->with($overrides);
    }

    public function test_new_leads_are_stored_against_the_profile_with_a_pulled_event(): void
    {
        $profile = LeadProfile::factory()->create();

        $result = app(LeadIngestor::class)->ingest($profile, [$this->data('a'), $this->data('b')]);

        $this->assertSame(['created' => 2, 'skipped' => 0], $result);

        $lead = Lead::where('source_id', 'a')->firstOrFail();
        $this->assertSame($profile->id, $lead->lead_profile_id);
        $this->assertSame(Lead::STATUS_NEW, $lead->status);
        $this->assertSame(Lead::EMAIL_UNREVEALED, $lead->email_status);
        $this->assertSame('Acme Pay', $lead->company);
        $this->assertSame('pulled', $lead->events()->first()->type);
        $this->assertSame($profile->id, $lead->events()->first()->payload['profile_id']);
    }

    public function test_a_lead_already_stored_is_skipped_not_duplicated(): void
    {
        $profile = LeadProfile::factory()->create();
        $ingestor = app(LeadIngestor::class);
        $ingestor->ingest($profile, [$this->data('a')]);

        $result = $ingestor->ingest($profile, [$this->data('a'), $this->data('c')]);

        $this->assertSame(['created' => 1, 'skipped' => 1], $result);
        $this->assertSame(2, Lead::count());
    }

    public function test_the_same_person_found_by_another_profile_is_not_pulled_twice(): void
    {
        $first = LeadProfile::factory()->create();
        $second = LeadProfile::factory()->create();
        $ingestor = app(LeadIngestor::class);
        $ingestor->ingest($first, [$this->data('a')]);

        $result = $ingestor->ingest($second, [$this->data('a')]);

        $this->assertSame(['created' => 0, 'skipped' => 1], $result);
        $this->assertSame($first->id, Lead::firstOrFail()->lead_profile_id);
    }

    public function test_an_empty_batch_is_fine(): void
    {
        $this->assertSame(['created' => 0, 'skipped' => 0], app(LeadIngestor::class)->ingest(LeadProfile::factory()->create(), []));
    }
}
```

- [ ] **Step 2: Run and confirm failure**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/LeadIngestorTest.php`
Expected: `LeadIngestor` not found.

- [ ] **Step 3: Implement**

`app/Services/Leads/LeadIngestor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadProfile;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Stores pulled leads. De-duplication is on (source, source id): the same person
 * is never stored twice, even when two profiles find them. Email-based dedupe
 * (against Contacts and the suppression list) happens when the email is revealed,
 * in a later phase, because search results carry no email.
 */
final class LeadIngestor
{
    /**
     * @param  list<LeadData>  $leads
     * @return array{created: int, skipped: int}
     */
    public function ingest(LeadProfile $profile, array $leads): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($leads as $data) {
            if (Lead::query()->where('source', $data->source)->where('source_id', $data->sourceId)->exists()) {
                $skipped++;

                continue;
            }

            try {
                $lead = Lead::create([
                    'lead_profile_id' => $profile->id,
                    'source' => $data->source,
                    'source_id' => $data->sourceId,
                    'email' => $data->email,
                    'email_status' => $data->emailStatus,
                    'name' => $data->name,
                    'title' => $data->title,
                    'company' => $data->company,
                    'domain' => $data->domain,
                    'country' => $data->country,
                    'phone' => $data->phone,
                    'linkedin_url' => $data->linkedinUrl,
                    'raw' => $data->raw === [] ? null : $data->raw,
                    'status' => Lead::STATUS_NEW,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent pull stored it between the check and the insert.
                $skipped++;

                continue;
            }

            LeadEvent::record($lead, 'pulled', ['profile_id' => $profile->id]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
```

- [ ] **Step 4: Run and confirm pass**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/LeadIngestorTest.php`
Expected: `OK (4 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add app/Services/Leads/LeadIngestor.php tests/Feature/Leads/LeadIngestorTest.php
git commit -m "feat(leads): ingest pulled leads with de-duplication and a timeline event"
```

---

### Task 7: Puller (caps, cursor, kill switch, errors)

**Files:**
- Create: `app/Services/Leads/LeadPuller.php`
- Test: `tests/Feature/Leads/LeadPullerTest.php`

The daily cap is **soft**: it is checked before each page, so one page (25 by default) can overshoot it. Search is free on Apollo, so the cap exists to bound later AI-qualification cost, not API spend.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadProfile;
use App\Services\Leads\LeadPuller;
use App\Support\LeadConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LeadPullerTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH = 'https://api.apollo.io/api/v1/mixed_people/api_search*';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.apollo.key' => null]);
        LeadConfig::setKey('apollo', 'apollo-test-key-123');
    }

    private function profile(array $overrides = []): LeadProfile
    {
        return LeadProfile::factory()->active()->withQuery()->create($overrides);
    }

    private function people(int $count, int $offset = 0): array
    {
        $people = [];
        for ($i = 1; $i <= $count; $i++) {
            $people[] = ['id' => 'p'.($offset + $i), 'first_name' => 'Person', 'last_name_obfuscated' => 'A***'.$i, 'title' => 'CEO', 'has_email' => true, 'organization' => ['name' => 'Co '.$i]];
        }

        return $people;
    }

    public function test_a_pull_stores_leads_and_advances_the_cursor(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 100, 'people' => $this->people(3)])]);
        $profile = $this->profile();

        $result = app(LeadPuller::class)->pull($profile);

        $this->assertSame(3, $result['created']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(3, Lead::where('lead_profile_id', $profile->id)->count());

        $profile->refresh();
        $this->assertSame(['next_page' => 2, 'exhausted' => false], $profile->sourceState('apollo'));
        $this->assertNotNull($profile->last_pulled_at);
        $this->assertNull($profile->last_pull_error);
    }

    public function test_the_next_pull_asks_for_the_next_page(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 100, 'people' => $this->people(1)])]);
        $profile = $this->profile(['pull_state' => ['apollo' => ['next_page' => 4, 'exhausted' => false]]]);

        app(LeadPuller::class)->pull($profile);

        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'page=4'));
    }

    public function test_a_source_is_marked_exhausted_when_there_are_no_more_pages(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 2, 'people' => $this->people(2)])]);
        $profile = $this->profile();
        $puller = app(LeadPuller::class);

        $puller->pull($profile);
        $this->assertTrue($profile->fresh()->sourceState('apollo')['exhausted']);

        Http::fake();
        $puller->pull($profile->fresh());
        Http::assertNothingSent();
    }

    public function test_no_request_is_made_once_todays_cap_is_reached(): void
    {
        Http::fake();
        $profile = $this->profile(['daily_pull_cap' => 25]);
        Lead::factory()->count(25)->create(['lead_profile_id' => $profile->id, 'source' => 'apollo']);

        $result = app(LeadPuller::class)->pull($profile);

        $this->assertSame(0, $result['created']);
        Http::assertNothingSent();
    }

    public function test_leads_pulled_yesterday_do_not_count_against_today(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 100, 'people' => $this->people(2, 100)])]);
        $profile = $this->profile(['daily_pull_cap' => 25]);
        Lead::factory()->count(25)->create(['lead_profile_id' => $profile->id, 'source' => 'apollo', 'created_at' => now()->subDay()]);

        $this->assertSame(2, app(LeadPuller::class)->pull($profile)['created']);
    }

    public function test_the_kill_switch_stops_everything(): void
    {
        Http::fake();
        LeadConfig::setKillSwitch(true);

        $result = app(LeadPuller::class)->pull($this->profile());

        $this->assertSame(0, $result['created']);
        Http::assertNothingSent();
    }

    public function test_inactive_profiles_and_profiles_without_a_query_are_skipped(): void
    {
        Http::fake();

        app(LeadPuller::class)->pull(LeadProfile::factory()->withQuery()->create(['is_active' => false]));
        app(LeadPuller::class)->pull(LeadProfile::factory()->active()->create(['lead_query' => null]));

        Http::assertNothingSent();
    }

    public function test_a_platform_error_is_recorded_and_the_cursor_does_not_move(): void
    {
        Http::fake([self::SEARCH => Http::response('no', 401)]);
        $profile = $this->profile(['pull_state' => ['apollo' => ['next_page' => 2, 'exhausted' => false]]]);

        $result = app(LeadPuller::class)->pull($profile);

        $this->assertSame(0, $result['created']);
        $this->assertCount(1, $result['errors']);

        $profile->refresh();
        $this->assertStringContainsString('API key', $profile->last_pull_error);
        $this->assertSame(2, $profile->sourceState('apollo')['next_page']);
    }

    public function test_an_unconfigured_source_is_reported_not_called(): void
    {
        Http::fake();
        LeadConfig::setKey('apollo', '');

        $result = app(LeadPuller::class)->pull($this->profile());

        $this->assertStringContainsString('not configured', $result['errors'][0]);
        Http::assertNothingSent();
    }

    public function test_an_unknown_source_key_is_reported(): void
    {
        $result = app(LeadPuller::class)->pull($this->profile(['sources' => ['nope']]));

        $this->assertStringContainsString('nope', $result['errors'][0]);
    }

    public function test_a_successful_pull_clears_an_old_error(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 1, 'people' => $this->people(1)])]);
        $profile = $this->profile(['last_pull_error' => 'old failure']);

        app(LeadPuller::class)->pull($profile);

        $this->assertNull($profile->fresh()->last_pull_error);
    }
}
```

- [ ] **Step 2: Run and confirm failure**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/LeadPullerTest.php`
Expected: `LeadPuller` not found.

- [ ] **Step 3: Implement**

`app/Services/Leads/LeadPuller.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadProfile;
use App\Support\LeadConfig;

/**
 * One pull pass for a profile: for each of its sources, fetch ONE page (if the
 * source is configured, not exhausted and today's cap is not reached), ingest it
 * and advance that source's cursor. The scheduler calls this hourly, so a profile
 * fills its daily cap gradually and a rate limit only delays the next page.
 *
 * Errors are recorded on the profile and never advance the cursor, so nothing is
 * skipped; the next run simply retries the same page.
 */
final class LeadPuller
{
    public function __construct(
        private readonly LeadSourceRegistry $sources,
        private readonly LeadIngestor $ingestor,
    ) {}

    /**
     * @return array{created: int, skipped: int, errors: list<string>}
     */
    public function pull(LeadProfile $profile): array
    {
        $result = ['created' => 0, 'skipped' => 0, 'errors' => []];

        if (LeadConfig::killSwitchOn() || ! $profile->is_active || ! $profile->hasQuery()) {
            return $result;
        }

        $query = LeadQuery::fromArray($profile->lead_query);
        $state = $profile->pull_state ?? [];

        foreach ($profile->sources as $key) {
            if (! $this->sources->has($key)) {
                $result['errors'][] = "Unknown lead source \"{$key}\".";

                continue;
            }

            $source = $this->sources->get($key);
            if (! $source->isConfigured()) {
                $result['errors'][] = "{$source->label()} is not configured: add its API key under Lead Sources.";

                continue;
            }

            $cursor = $profile->sourceState($key);
            if ($cursor['exhausted'] || $this->pulledToday($profile, $key) >= $profile->daily_pull_cap) {
                continue;
            }

            try {
                $page = $source->search($query, $cursor['next_page'], LeadConfig::pageSize());
            } catch (LeadSourceException $e) {
                $result['errors'][] = $e->getMessage();

                continue;
            }

            $ingested = $this->ingestor->ingest($profile, $page->leads);
            $result['created'] += $ingested['created'];
            $result['skipped'] += $ingested['skipped'];

            $state[$key] = [
                'next_page' => $cursor['next_page'] + 1,
                'exhausted' => ! $page->hasMore || $page->leads === [],
            ];
        }

        $profile->forceFill([
            'pull_state' => $state === [] ? null : $state,
            'last_pulled_at' => now(),
            'last_pull_error' => $result['errors'] === [] ? null : implode(' ', $result['errors']),
        ])->save();

        return $result;
    }

    private function pulledToday(LeadProfile $profile, string $source): int
    {
        return Lead::query()
            ->where('lead_profile_id', $profile->id)
            ->where('source', $source)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }
}
```

- [ ] **Step 4: Run and confirm pass**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/LeadPullerTest.php`
Expected: `OK (11 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add app/Services/Leads/LeadPuller.php tests/Feature/Leads/LeadPullerTest.php
git commit -m "feat(leads): puller with soft daily cap, per-source cursor, kill switch and error recording"
```

---

### Task 8: Job, command and schedule

**Files:**
- Create: `app/Jobs/PullLeadProfileJob.php`, `app/Console/Commands/PullLeads.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Leads/PullLeadsCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Jobs\PullLeadProfileJob;
use App\Models\LeadProfile;
use App\Support\LeadConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PullLeadsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_only_active_profiles_with_a_query_that_are_due(): void
    {
        Queue::fake();
        $due = LeadProfile::factory()->active()->withQuery()->create(['last_pulled_at' => now()->subHours(2)]);
        $never = LeadProfile::factory()->active()->withQuery()->create(['last_pulled_at' => null]);
        LeadProfile::factory()->active()->withQuery()->create(['last_pulled_at' => now()->subMinutes(5)]);
        LeadProfile::factory()->withQuery()->create(['is_active' => false]);
        LeadProfile::factory()->active()->create(['lead_query' => null]);

        $this->artisan('leads:pull')->assertSuccessful();

        Queue::assertPushed(PullLeadProfileJob::class, 2);
        Queue::assertPushed(PullLeadProfileJob::class, fn (PullLeadProfileJob $job) => $job->profileId === $due->id);
        Queue::assertPushed(PullLeadProfileJob::class, fn (PullLeadProfileJob $job) => $job->profileId === $never->id);
    }

    public function test_the_profile_option_forces_one_profile_even_if_recently_pulled(): void
    {
        Queue::fake();
        $recent = LeadProfile::factory()->active()->withQuery()->create(['last_pulled_at' => now()->subMinutes(1)]);

        $this->artisan('leads:pull', ['--profile' => $recent->id])->assertSuccessful();

        Queue::assertPushed(PullLeadProfileJob::class, 1);
    }

    public function test_the_kill_switch_queues_nothing(): void
    {
        Queue::fake();
        LeadConfig::setKillSwitch(true);
        LeadProfile::factory()->active()->withQuery()->create();

        $this->artisan('leads:pull')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_job_runs_the_puller_for_its_profile(): void
    {
        config(['services.apollo.key' => null]);
        LeadConfig::setKey('apollo', 'apollo-test-key-123');
        Http::fake(['https://api.apollo.io/*' => Http::response([
            'total_entries' => 1,
            'people' => [['id' => 'p1', 'first_name' => 'A', 'last_name_obfuscated' => 'B**', 'has_email' => true, 'organization' => ['name' => 'Co']]],
        ])]);
        $profile = LeadProfile::factory()->active()->withQuery()->create();

        (new PullLeadProfileJob($profile->id))->handle(app(\App\Services\Leads\LeadPuller::class));

        $this->assertSame(1, $profile->leads()->count());
    }

    public function test_the_job_ignores_a_profile_that_was_deleted(): void
    {
        Bus::fake();

        (new PullLeadProfileJob(999999))->handle(app(\App\Services\Leads\LeadPuller::class));

        $this->assertTrue(true);
    }

    public function test_the_pull_is_on_the_schedule(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('leads:pull')->assertSuccessful();
    }
}
```

- [ ] **Step 2: Run and confirm failure**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/PullLeadsCommandTest.php`
Expected: `PullLeadProfileJob` not found.

- [ ] **Step 3: Implement**

`app/Jobs/PullLeadProfileJob.php`:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LeadProfile;
use App\Services\Leads\LeadPuller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Pulls one page per source for one profile. Takes the id so a deleted profile is a no-op. */
class PullLeadProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $profileId) {}

    public function handle(LeadPuller $puller): void
    {
        $profile = LeadProfile::find($this->profileId);

        if ($profile === null) {
            return;
        }

        $puller->pull($profile);
    }
}
```

`app/Console/Commands/PullLeads.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PullLeadProfileJob;
use App\Models\LeadProfile;
use App\Support\LeadConfig;
use Illuminate\Console\Command;

class PullLeads extends Command
{
    protected $signature = 'leads:pull {--profile= : Pull only this lead profile id, even if it was pulled recently}';

    protected $description = 'Queue a pull for every active lead profile that is due.';

    public function handle(): int
    {
        if (LeadConfig::killSwitchOn()) {
            $this->warn('Lead kill switch is ON; nothing queued.');

            return self::SUCCESS;
        }

        $dueBefore = now()->subMinutes((int) config('leads.pull_interval_minutes', 50));
        $only = $this->option('profile');

        $profiles = LeadProfile::query()
            ->active()
            ->whereNotNull('lead_query')
            ->when($only, fn ($query) => $query->whereKey($only))
            ->when(! $only, fn ($query) => $query->where(
                fn ($due) => $due->whereNull('last_pulled_at')->orWhere('last_pulled_at', '<=', $dueBefore)
            ))
            ->get();

        foreach ($profiles as $profile) {
            PullLeadProfileJob::dispatch($profile->id);
        }

        $this->info("Queued {$profiles->count()} lead pull(s).");

        return self::SUCCESS;
    }
}
```

Append to `routes/console.php` (after the existing `email:score-leads` schedule entry):

```php
// AI lead CRM: pull a page of new leads for every due, active lead profile.
Schedule::command('leads:pull')->hourly()->withoutOverlapping()->onOneServer();
```

- [ ] **Step 4: Run and confirm pass**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/PullLeadsCommandTest.php`
Expected: `OK (6 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/PullLeadProfileJob.php app/Console/Commands/PullLeads.php routes/console.php tests/Feature/Leads/PullLeadsCommandTest.php
git commit -m "feat(leads): hourly leads:pull command queues a pull per due profile"
```

---

### Task 9: Permissions

**Files:**
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Test: `tests/Feature/Leads/LeadPermissionsTest.php`

`leads.view` and `leads.manage` are added to the full permission list. `super_admin` and `admin` get everything; `manager` gets everything except the explicit exclusions, so it receives both; `agent` uses an allowlist and gets neither.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LeadPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function roles(): array
    {
        return [
            'super_admin' => ['super_admin', true],
            'admin' => ['admin', true],
            'manager' => ['manager', true],
            'agent' => ['agent', false],
        ];
    }

    /**
     * @dataProvider roles
     */
    public function test_which_roles_can_view_and_manage_leads(string $role, bool $allowed): void
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertSame($allowed, $user->can('leads.view'));
        $this->assertSame($allowed, $user->can('leads.manage'));
    }
}
```

- [ ] **Step 2: Run and confirm failure**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/LeadPermissionsTest.php`
Expected: failures, permissions do not exist.

- [ ] **Step 3: Implement**

In `database/seeders/RolesAndPermissionsSeeder.php`, in `createPermissions()`, directly after the `'tasks.report',` line (and its preceding comment block) add:

```php

            // AI lead CRM. view = see leads and their pipeline; manage = create lead
            // profiles, store platform API keys, run pulls and the kill switch.
            // Both are in $allPermissions (admin + manager); the agent allowlist omits them.
            'leads.view',
            'leads.manage',
```

- [ ] **Step 4: Run and confirm pass, plus the existing role tests**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit --filter "LeadPermissions|Role|Permission|AllCallStaff"`
Expected: all pass. If an existing test hard-codes the total permission count, update that number by 2 and say why in the commit message.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/RolesAndPermissionsSeeder.php tests/Feature/Leads/LeadPermissionsTest.php
git commit -m "feat(leads): leads.view and leads.manage permissions for admin and manager"
```

---

### Task 10: Lead Sources screen (keys, test connection, kill switch)

**Files:**
- Create: `app/Http/Controllers/LeadSourceSettingsController.php`, `resources/views/lead-sources/index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Leads/LeadSourceSettingsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\User;
use App\Support\LeadConfig;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LeadSourceSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH = 'https://api.apollo.io/api/v1/mixed_people/api_search*';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['services.apollo.key' => null]);
    }

    private function as(string $role): static
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $this->actingAs($user);
    }

    public function test_managers_can_open_the_page_and_agents_cannot(): void
    {
        $this->as('manager')->get(route('lead-sources.index'))->assertOk()->assertSee('Apollo.io');
        $this->as('agent')->get(route('lead-sources.index'))->assertForbidden();
    }

    public function test_the_page_shows_whether_a_key_is_saved_but_never_the_key(): void
    {
        LeadConfig::setKey('apollo', 'super-secret-key-999');

        $this->as('admin')->get(route('lead-sources.index'))
            ->assertOk()
            ->assertSee('Key saved')
            ->assertDontSee('super-secret-key-999');
    }

    public function test_saving_a_key_stores_it_encrypted(): void
    {
        $this->as('admin')->put(route('lead-sources.update', 'apollo'), ['api_key' => 'new-key-1234567890'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('new-key-1234567890', LeadConfig::key('apollo'));
    }

    public function test_a_short_or_missing_key_is_rejected(): void
    {
        $this->as('admin')->put(route('lead-sources.update', 'apollo'), ['api_key' => 'x'])
            ->assertSessionHasErrors('api_key');
    }

    public function test_an_unknown_source_is_a_404(): void
    {
        $this->as('admin')->put(route('lead-sources.update', 'nope'), ['api_key' => 'new-key-1234567890'])->assertNotFound();
    }

    public function test_agents_cannot_save_a_key(): void
    {
        $this->as('agent')->put(route('lead-sources.update', 'apollo'), ['api_key' => 'new-key-1234567890'])->assertForbidden();
    }

    public function test_connection_test_reports_success(): void
    {
        LeadConfig::setKey('apollo', 'apollo-test-key-123');
        Http::fake([self::SEARCH => Http::response(['total_entries' => 1, 'people' => []])]);

        $this->as('admin')->post(route('lead-sources.test', 'apollo'))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_connection_test_reports_the_platform_error(): void
    {
        LeadConfig::setKey('apollo', 'apollo-test-key-123');
        Http::fake([self::SEARCH => Http::response('no', 401)]);

        $this->as('admin')->post(route('lead-sources.test', 'apollo'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'API key'));
    }

    public function test_connection_test_without_a_key_asks_for_one_and_sends_nothing(): void
    {
        Http::fake();

        $this->as('admin')->post(route('lead-sources.test', 'apollo'))->assertSessionHas('warning');

        Http::assertNothingSent();
    }

    public function test_the_kill_switch_can_be_turned_on_and_off(): void
    {
        $this->as('admin')->post(route('lead-sources.kill-switch'), ['on' => 1])->assertRedirect();
        $this->assertTrue(LeadConfig::killSwitchOn());

        $this->post(route('lead-sources.kill-switch'), ['on' => 0])->assertRedirect();
        $this->assertFalse(LeadConfig::killSwitchOn());
    }
}
```

- [ ] **Step 2: Run and confirm failure**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/LeadSourceSettingsTest.php`
Expected: route `lead-sources.index` not defined.

- [ ] **Step 3: Add the routes**

In `routes/web.php`, add these imports with the other controller imports:

```php
use App\Http\Controllers\LeadSourceSettingsController;
```

and, directly above the `// ─── User Management ───` comment, add:

```php
    // ─── AI Lead CRM: platform keys, kill switch ────────────────────────────
    // `kill-switch` is registered before the {source} wildcard (and the wildcard is
    // constrained to [a-z0-9_]+, which excludes the hyphen) so it cannot be shadowed.
    Route::middleware('permission:leads.manage')->group(function () {
        Route::get('/lead-sources', [LeadSourceSettingsController::class, 'index'])->name('lead-sources.index');
        Route::post('/lead-sources/kill-switch', [LeadSourceSettingsController::class, 'killSwitch'])->name('lead-sources.kill-switch');
        Route::put('/lead-sources/{source}', [LeadSourceSettingsController::class, 'update'])
            ->where('source', '[a-z0-9_]+')->name('lead-sources.update');
        Route::post('/lead-sources/{source}/test', [LeadSourceSettingsController::class, 'test'])
            ->where('source', '[a-z0-9_]+')->name('lead-sources.test');
    });

```

- [ ] **Step 4: Create the controller**

`app/Http/Controllers/LeadSourceSettingsController.php`:

```php
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
```

- [ ] **Step 5: Create the view**

`resources/views/lead-sources/index.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Lead sources</h2>
    </x-slot>

    <div class="py-6 max-w-4xl mx-auto sm:px-4 lg:px-8 space-y-6">

        {{-- Global kill switch: one button halts pulling, qualifying and every automatic send. --}}
        <section class="rounded-xl border p-5 {{ $killSwitch ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-white' }} shadow-sm">
            <form method="POST" action="{{ route('lead-sources.kill-switch') }}" class="flex flex-wrap items-center justify-between gap-4">
                @csrf
                <input type="hidden" name="on" value="{{ $killSwitch ? 0 : 1 }}">
                <div class="min-w-0">
                    <h3 class="font-display text-base font-bold text-gray-900">
                        {{ $killSwitch ? 'Lead automation is PAUSED' : 'Lead automation is running' }}
                    </h3>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ $killSwitch
                            ? 'Nothing is being pulled, qualified or sent. Resume when you are ready.'
                            : 'Pause to stop all pulling and automatic sending immediately, for every lead profile.' }}
                    </p>
                </div>
                <button type="submit"
                        class="rounded-lg px-4 py-2 text-sm font-semibold {{ $killSwitch ? 'bg-brand text-white' : 'border border-red-300 bg-white text-red-700 hover:bg-red-50' }}">
                    {{ $killSwitch ? 'Resume automation' : 'Pause all automation' }}
                </button>
            </form>
        </section>

        @foreach($sources as $key => $source)
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm" aria-labelledby="source-{{ $key }}">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h3 id="source-{{ $key }}" class="font-display text-lg font-bold text-gray-900">{{ $source->label() }}</h3>
                    @if($source->isConfigured())
                        <span class="rounded-full bg-brand-soft px-2.5 py-0.5 text-xs font-semibold text-brand-text">Key saved</span>
                    @else
                        <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600">No key</span>
                    @endif
                </div>

                <form method="POST" action="{{ route('lead-sources.update', $key) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    @method('PUT')
                    <div class="min-w-[16rem] flex-1">
                        <label for="key-{{ $key }}" class="mb-1 block text-xs font-medium text-gray-600">API key</label>
                        <input id="key-{{ $key }}" type="password" name="api_key" autocomplete="off"
                               placeholder="{{ $source->isConfigured() ? 'Leave blank to keep the saved key' : 'Paste the key' }}"
                               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                        @error('api_key') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">Save key</button>
                </form>

                {{-- Separate form: a form nested inside the one above is discarded by browsers. --}}
                <form method="POST" action="{{ route('lead-sources.test', $key) }}" class="mt-3">
                    @csrf
                    <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Test connection</button>
                </form>

                <p class="mt-3 text-xs text-gray-500">The key is stored encrypted and is never shown again. Search is free; revealing an email spends the platform's credits.</p>
            </section>
        @endforeach
    </div>
</x-app-layout>
```

- [ ] **Step 6: Run and confirm pass**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/LeadSourceSettingsTest.php`
Expected: `OK (10 tests, ...)`. Note `test_saving_a_key...` posts `api_key` of 18 chars (passes `min:10`).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/LeadSourceSettingsController.php resources/views/lead-sources routes/web.php tests/Feature/Leads/LeadSourceSettingsTest.php
git commit -m "feat(leads): Lead Sources screen with encrypted keys, connection test and kill switch"
```

---

### Task 11: Lead Profiles (create, edit, rebuild search, pull now)

**Files:**
- Create: `app/Http/Requests/LeadProfileRequest.php`, `app/Http/Controllers/LeadProfileController.php`, `resources/views/lead-profiles/{index,create,edit,_form}.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Leads/LeadProfileControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadProfile;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Support\LeadConfig;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

final class LeadProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH = 'https://api.apollo.io/api/v1/mixed_people/api_search*';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['services.apollo.key' => null]);
    }

    private function as(string $role = 'manager'): static
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $this->actingAs($user);
    }

    private function ai(?string $reply): FakeAiClient
    {
        $fake = new FakeAiClient($reply);
        $this->app->instance(AiClient::class, $fake);

        return $fake;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Lagos fintech founders',
            'brief' => 'Founders and CEOs of small fintech companies based in Lagos.',
            'sources' => ['apollo'],
            'daily_pull_cap' => 50,
            'hf_countries' => 'Nigeria',
        ], $overrides);
    }

    public function test_agents_are_forbidden_and_managers_can_list(): void
    {
        $this->as('agent')->get(route('lead-profiles.index'))->assertForbidden();
        $this->as('manager')->get(route('lead-profiles.index'))->assertOk();
    }

    public function test_creating_a_profile_builds_and_saves_the_search_but_stays_inactive(): void
    {
        $this->ai(json_encode(['titles' => ['Founder', 'CEO'], 'keywords' => 'fintech']));

        $this->as()->post(route('lead-profiles.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $profile = LeadProfile::firstOrFail();
        $this->assertFalse($profile->is_active);
        $this->assertSame(['Founder', 'CEO'], $profile->lead_query['titles']);
        $this->assertSame(['Nigeria'], $profile->lead_query['person_locations']);
        $this->assertSame(['Nigeria'], $profile->hard_filters['countries']);
        $this->assertSame(['apollo'], $profile->sources);
        $this->assertNotNull($profile->created_by);
    }

    public function test_if_the_ai_fails_the_profile_is_still_saved_with_a_warning(): void
    {
        $this->ai(null);

        $this->as()->post(route('lead-profiles.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('warning');

        $profile = LeadProfile::firstOrFail();
        $this->assertNull($profile->lead_query);
        $this->assertFalse($profile->hasQuery());
    }

    public function test_validation_rejects_bad_input(): void
    {
        $this->ai('{}');

        $this->as()->post(route('lead-profiles.store'), $this->payload(['name' => '', 'brief' => 'short', 'sources' => ['nope'], 'daily_pull_cap' => 0, 'hf_employee_ranges' => 'abc']))
            ->assertSessionHasErrors(['name', 'brief', 'sources.0', 'daily_pull_cap', 'hf_employee_ranges']);

        $this->assertSame(0, LeadProfile::count());
    }

    public function test_hard_filters_are_parsed_from_the_form(): void
    {
        $this->ai(json_encode(['titles' => ['CEO']]));

        $this->as()->post(route('lead-profiles.store'), $this->payload([
            'hf_countries' => 'Nigeria, Ghana',
            'hf_titles_exclude' => "Intern\nStudent",
            'hf_employee_ranges' => '11, 50; 51,200',
            'hf_require_verified_email' => '1',
        ]));

        $filters = LeadProfile::firstOrFail()->hard_filters;
        $this->assertSame(['Nigeria', 'Ghana'], $filters['countries']);
        $this->assertSame(['Intern', 'Student'], $filters['titles_exclude']);
        $this->assertSame(['11,50', '51,200'], $filters['employee_ranges']);
        $this->assertTrue($filters['require_verified_email']);
    }

    public function test_changing_the_brief_rebuilds_the_search_and_restarts_the_cursor(): void
    {
        $fake = $this->ai(json_encode(['titles' => ['CTO']]));
        $profile = LeadProfile::factory()->active()->withQuery()->create([
            'pull_state' => ['apollo' => ['next_page' => 9, 'exhausted' => true]],
            'hard_filters' => ['countries' => ['Nigeria']],
        ]);

        $this->as()->put(route('lead-profiles.update', $profile), $this->payload([
            'brief' => 'A completely different brief about CTOs at banks.',
            'is_active' => '1',
        ]))->assertRedirect();

        $profile->refresh();
        $this->assertCount(1, $fake->calls);
        $this->assertSame(['CTO'], $profile->lead_query['titles']);
        $this->assertNull($profile->pull_state);
        $this->assertTrue($profile->is_active);
    }

    public function test_saving_without_changing_the_brief_does_not_call_the_ai(): void
    {
        $fake = $this->ai(json_encode(['titles' => ['CTO']]));
        $profile = LeadProfile::factory()->withQuery(['titles' => ['CEO']])->create([
            'brief' => 'Founders and CEOs of small fintech companies based in Lagos.',
            'hard_filters' => ['countries' => ['Nigeria'], 'titles_include' => [], 'titles_exclude' => [], 'employee_ranges' => [], 'require_verified_email' => false],
        ]);

        $this->as()->put(route('lead-profiles.update', $profile), $this->payload(['name' => 'Renamed']))->assertRedirect();

        $this->assertCount(0, $fake->calls);
        $this->assertSame('Renamed', $profile->fresh()->name);
        $this->assertSame(['CEO'], $profile->fresh()->lead_query['titles']);
    }

    public function test_a_profile_without_a_generated_search_cannot_be_activated(): void
    {
        $this->ai(null);
        $profile = LeadProfile::factory()->create(['lead_query' => null]);

        $this->as()->put(route('lead-profiles.update', $profile), $this->payload(['is_active' => '1']))
            ->assertSessionHas('warning');

        $this->assertFalse($profile->fresh()->is_active);
    }

    public function test_rebuild_query_action(): void
    {
        $this->ai(json_encode(['titles' => ['Head of Sales']]));
        $profile = LeadProfile::factory()->create(['lead_query' => null]);

        $this->as()->post(route('lead-profiles.rebuild-query', $profile))->assertSessionHas('success');

        $this->assertSame(['Head of Sales'], $profile->fresh()->lead_query['titles']);
    }

    public function test_pull_now_stores_leads_and_reports_the_count(): void
    {
        LeadConfig::setKey('apollo', 'apollo-test-key-123');
        Http::fake([self::SEARCH => Http::response([
            'total_entries' => 1,
            'people' => [['id' => 'p1', 'first_name' => 'Ada', 'last_name_obfuscated' => 'O**', 'has_email' => true, 'organization' => ['name' => 'Co']]],
        ])]);
        $profile = LeadProfile::factory()->active()->withQuery()->create();

        $this->as()->post(route('lead-profiles.pull', $profile))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, '1 new lead'));

        $this->assertSame(1, Lead::count());
    }

    public function test_pull_now_refuses_an_inactive_profile_and_the_kill_switch(): void
    {
        Http::fake();

        $this->as()->post(route('lead-profiles.pull', LeadProfile::factory()->withQuery()->create(['is_active' => false])))
            ->assertSessionHas('warning');

        LeadConfig::setKillSwitch(true);
        $this->post(route('lead-profiles.pull', LeadProfile::factory()->active()->withQuery()->create()))
            ->assertSessionHas('warning');

        Http::assertNothingSent();
    }

    public function test_reset_pull_clears_the_cursor_and_error(): void
    {
        $profile = LeadProfile::factory()->create([
            'pull_state' => ['apollo' => ['next_page' => 5, 'exhausted' => true]],
            'last_pull_error' => 'boom',
        ]);

        $this->as()->post(route('lead-profiles.reset-pull', $profile))->assertRedirect();

        $profile->refresh();
        $this->assertNull($profile->pull_state);
        $this->assertNull($profile->last_pull_error);
    }

    public function test_deleting_a_profile_keeps_its_leads(): void
    {
        $profile = LeadProfile::factory()->create();
        $lead = Lead::factory()->create(['lead_profile_id' => $profile->id]);

        $this->as()->delete(route('lead-profiles.destroy', $profile))->assertRedirect(route('lead-profiles.index'));

        $this->assertNull(LeadProfile::find($profile->id));
        $this->assertNotNull($lead->fresh());
    }

    public function test_the_edit_page_shows_the_generated_search_for_review(): void
    {
        $profile = LeadProfile::factory()->withQuery(['titles' => ['Chief Technology Officer'], 'keywords' => 'logistics'])->create();

        $this->as()->get(route('lead-profiles.edit', $profile))
            ->assertOk()
            ->assertSee('Chief Technology Officer')
            ->assertSee('logistics');
    }
}
```

- [ ] **Step 2: Run and confirm failure**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/LeadProfileControllerTest.php`
Expected: route `lead-profiles.index` not defined.

- [ ] **Step 3: Add the routes**

In `routes/web.php` add the import `use App\Http\Controllers\LeadProfileController;` and, inside the existing `permission:leads.manage` group created in Task 10 (after the `lead-sources.test` route), add:

```php
        // Static segments (/create) before the {leadProfile} wildcards.
        Route::get('/lead-profiles', [LeadProfileController::class, 'index'])->name('lead-profiles.index');
        Route::get('/lead-profiles/create', [LeadProfileController::class, 'create'])->name('lead-profiles.create');
        Route::post('/lead-profiles', [LeadProfileController::class, 'store'])->name('lead-profiles.store');
        Route::get('/lead-profiles/{leadProfile}/edit', [LeadProfileController::class, 'edit'])->name('lead-profiles.edit');
        Route::put('/lead-profiles/{leadProfile}', [LeadProfileController::class, 'update'])->name('lead-profiles.update');
        Route::post('/lead-profiles/{leadProfile}/rebuild-query', [LeadProfileController::class, 'rebuildQuery'])->name('lead-profiles.rebuild-query');
        Route::post('/lead-profiles/{leadProfile}/pull', [LeadProfileController::class, 'pullNow'])->name('lead-profiles.pull');
        Route::post('/lead-profiles/{leadProfile}/reset-pull', [LeadProfileController::class, 'resetPull'])->name('lead-profiles.reset-pull');
        Route::delete('/lead-profiles/{leadProfile}', [LeadProfileController::class, 'destroy'])->name('lead-profiles.destroy');
```

- [ ] **Step 4: Create the form request**

`app/Http/Requests/LeadProfileRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Leads\LeadSourceRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware (permission:leads.manage) is the gate.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'brief' => ['required', 'string', 'min:20', 'max:2000'],
            'sources' => ['required', 'array', 'min:1'],
            'sources.*' => ['string', Rule::in(app(LeadSourceRegistry::class)->keys())],
            'daily_pull_cap' => ['required', 'integer', 'min:1', 'max:500'],
            'hf_countries' => ['nullable', 'string', 'max:500'],
            'hf_titles_include' => ['nullable', 'string', 'max:500'],
            'hf_titles_exclude' => ['nullable', 'string', 'max:500'],
            'hf_employee_ranges' => ['nullable', 'string', 'max:200', 'regex:/^\s*\d+\s*,\s*\d+\s*(;\s*\d+\s*,\s*\d+\s*)*$/'],
        ];
    }

    /**
     * The hard filters as the stored array shape (see LeadQuery::withHardFilters).
     *
     * @return array{countries: list<string>, titles_include: list<string>, titles_exclude: list<string>, employee_ranges: list<string>, require_verified_email: bool}
     */
    public function hardFilters(): array
    {
        return [
            'countries' => $this->list('hf_countries'),
            'titles_include' => $this->list('hf_titles_include'),
            'titles_exclude' => $this->list('hf_titles_exclude'),
            'employee_ranges' => array_values(array_filter(array_map(
                fn (string $range): string => (string) preg_replace('/\s+/', '', $range),
                explode(';', (string) $this->input('hf_employee_ranges')),
            ), fn (string $range): bool => $range !== '')),
            'require_verified_email' => $this->boolean('hf_require_verified_email'),
        ];
    }

    /**
     * @return list<string>
     */
    private function list(string $field): array
    {
        $parts = preg_split('/[,\n]+/', (string) $this->input($field)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== ''));
    }
}
```

- [ ] **Step 5: Create the controller**

`app/Http/Controllers/LeadProfileController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LeadProfileRequest;
use App\Models\LeadProfile;
use App\Services\Leads\LeadPuller;
use App\Services\Leads\LeadQuery;
use App\Services\Leads\LeadQueryBuilder;
use App\Services\Leads\LeadQueryException;
use App\Services\Leads\LeadSourceRegistry;
use App\Support\LeadConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LeadProfileController extends Controller
{
    public function index(): View
    {
        return view('lead-profiles.index', [
            'profiles' => LeadProfile::query()->withCount('leads')->orderByDesc('id')->get(),
            'killSwitch' => LeadConfig::killSwitchOn(),
        ]);
    }

    public function create(LeadSourceRegistry $registry): View
    {
        return view('lead-profiles.create', ['sourceKeys' => $registry->keys()]);
    }

    public function store(LeadProfileRequest $request, LeadQueryBuilder $builder): RedirectResponse
    {
        $profile = LeadProfile::create([
            'name' => $request->input('name'),
            'brief' => $request->input('brief'),
            'sources' => array_values($request->input('sources')),
            'daily_pull_cap' => (int) $request->input('daily_pull_cap'),
            'hard_filters' => $request->hardFilters(),
            'is_active' => false,
            'created_by' => auth()->id(),
        ]);

        $failure = $this->rebuild($profile, $builder);

        if ($failure !== null) {
            return redirect()->route('lead-profiles.edit', $profile)
                ->with('warning', 'Profile saved, but the search could not be generated: '.$failure);
        }

        return redirect()->route('lead-profiles.edit', $profile)
            ->with('success', 'Profile created. Review the generated search below, then switch the profile on.');
    }

    public function edit(LeadProfile $leadProfile, LeadSourceRegistry $registry): View
    {
        return view('lead-profiles.edit', [
            'profile' => $leadProfile,
            'sourceKeys' => $registry->keys(),
            'query' => $leadProfile->hasQuery() ? LeadQuery::fromArray($leadProfile->lead_query) : null,
        ]);
    }

    public function update(LeadProfileRequest $request, LeadProfile $leadProfile, LeadQueryBuilder $builder): RedirectResponse
    {
        $hardFilters = $request->hardFilters();
        $searchInputsChanged = $leadProfile->brief !== $request->input('brief')
            || ($leadProfile->hard_filters ?? []) != $hardFilters;

        $leadProfile->fill([
            'name' => $request->input('name'),
            'brief' => $request->input('brief'),
            'sources' => array_values($request->input('sources')),
            'daily_pull_cap' => (int) $request->input('daily_pull_cap'),
            'hard_filters' => $hardFilters,
        ])->save();

        $failure = ($searchInputsChanged || ! $leadProfile->hasQuery())
            ? $this->rebuild($leadProfile, $builder)
            : null;

        $leadProfile->refresh();
        $wantsActive = $request->boolean('is_active');
        $leadProfile->update(['is_active' => $wantsActive && $leadProfile->hasQuery()]);

        if ($failure !== null) {
            return back()->with('warning', 'Saved, but the search could not be regenerated: '.$failure);
        }

        if ($wantsActive && ! $leadProfile->is_active) {
            return back()->with('warning', 'Saved, but the profile cannot be switched on until it has a generated search.');
        }

        return back()->with('success', 'Lead profile saved.');
    }

    public function rebuildQuery(LeadProfile $leadProfile, LeadQueryBuilder $builder): RedirectResponse
    {
        $failure = $this->rebuild($leadProfile, $builder);

        return $failure === null
            ? back()->with('success', 'Search regenerated from the brief.')
            : back()->with('error', $failure);
    }

    public function pullNow(LeadProfile $leadProfile, LeadPuller $puller): RedirectResponse
    {
        if (LeadConfig::killSwitchOn()) {
            return back()->with('warning', 'The lead automation kill switch is on; nothing will be pulled.');
        }

        if (! $leadProfile->is_active || ! $leadProfile->hasQuery()) {
            return back()->with('warning', 'Switch the profile on (it needs a generated search) before pulling.');
        }

        $result = $puller->pull($leadProfile);

        if ($result['errors'] !== []) {
            return back()->with('error', implode(' ', $result['errors']));
        }

        return back()->with('success', "Pulled {$result['created']} new lead(s); {$result['skipped']} already known.");
    }

    public function resetPull(LeadProfile $leadProfile): RedirectResponse
    {
        $leadProfile->update(['pull_state' => null, 'last_pull_error' => null]);

        return back()->with('success', 'Pull position reset; the next pull starts from the first page.');
    }

    public function destroy(LeadProfile $leadProfile): RedirectResponse
    {
        $leadProfile->delete();

        return redirect()->route('lead-profiles.index')->with('success', 'Lead profile deleted. Leads it already pulled were kept.');
    }

    /**
     * Regenerate and store the search. Returns the failure message, or null on success.
     * A new query invalidates the old cursor, so the pull restarts from page one.
     */
    private function rebuild(LeadProfile $profile, LeadQueryBuilder $builder): ?string
    {
        try {
            $query = $builder->build($profile->brief, $profile->hard_filters ?? []);
        } catch (LeadQueryException $e) {
            return $e->getMessage();
        }

        $profile->update([
            'lead_query' => $query->toArray(),
            'pull_state' => null,
            'last_pull_error' => null,
        ]);

        return null;
    }
}
```

- [ ] **Step 6: Create the views**

`resources/views/lead-profiles/index.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Lead profiles</h2>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto sm:px-4 lg:px-8 space-y-4">
        @if($killSwitch)
            <div class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
                Lead automation is paused. <a href="{{ route('lead-sources.index') }}" class="font-semibold underline">Resume it</a> to pull again.
            </div>
        @endif

        <div class="flex items-center justify-between gap-3">
            <p class="text-sm text-gray-500">Each profile describes one kind of lead. Pulled leads wait in <a href="{{ route('leads.index') }}" class="text-brand-text underline">Leads</a>.</p>
            <a href="{{ route('lead-profiles.create') }}" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">New profile</a>
        </div>

        @if($profiles->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center">
                <p class="font-display text-lg font-semibold text-gray-900">No lead profiles yet</p>
                <p class="mt-1 text-sm text-gray-500">Describe the leads you want in plain English and BlastIQ builds the search for you.</p>
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Profile</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Sources</th>
                            <th class="px-4 py-3 text-right">Leads</th>
                            <th class="px-4 py-3">Last pulled</th>
                            <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($profiles as $profile)
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('lead-profiles.edit', $profile) }}" class="font-medium text-gray-900 hover:underline">{{ $profile->name }}</a>
                                    @if($profile->last_pull_error)
                                        <p class="mt-0.5 max-w-md truncate text-xs text-red-600" title="{{ $profile->last_pull_error }}">{{ $profile->last_pull_error }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if(! $profile->hasQuery())
                                        <span class="rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-semibold text-yellow-700">Needs search</span>
                                    @elseif($profile->is_active)
                                        <span class="rounded-full bg-brand-soft px-2.5 py-0.5 text-xs font-semibold text-brand-text">On</span>
                                    @else
                                        <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600">Off</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ implode(', ', $profile->sources) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $profile->leads_count }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $profile->last_pulled_at?->diffForHumans() ?? 'never' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('lead-profiles.edit', $profile) }}" class="text-sm font-medium text-brand-text hover:underline">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
```

`resources/views/lead-profiles/_form.blade.php`:

```blade
@php
    $filters = $profile?->hard_filters ?? [];
    $selectedSources = old('sources', $profile?->sources ?? ['apollo']);
@endphp

<div class="space-y-5">
    <div>
        <label for="lp-name" class="mb-1 block text-xs font-medium text-gray-600">Profile name</label>
        <input id="lp-name" type="text" name="name" value="{{ old('name', $profile?->name) }}" required maxlength="120"
               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="lp-brief" class="mb-1 block text-xs font-medium text-gray-600">Who are you looking for?</label>
        <textarea id="lp-brief" name="brief" rows="4" required minlength="20" maxlength="2000"
                  placeholder="e.g. Founders and CEOs of small fintech companies in Lagos, 10 to 50 staff"
                  class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">{{ old('brief', $profile?->brief) }}</textarea>
        <p class="mt-1 text-xs text-gray-500">Plain English. The AI turns this into the search, and later uses it to judge each lead.</p>
        @error('brief') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <fieldset>
        <legend class="mb-1 block text-xs font-medium text-gray-600">Lead platforms</legend>
        <div class="flex flex-wrap gap-4">
            @foreach($sourceKeys as $key)
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="sources[]" value="{{ $key }}" @checked(in_array($key, $selectedSources, true))>
                    {{ ucfirst($key) }}
                </label>
            @endforeach
        </div>
        @error('sources') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('sources.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </fieldset>

    <div class="max-w-xs">
        <label for="lp-cap" class="mb-1 block text-xs font-medium text-gray-600">New leads per day (soft limit)</label>
        <input id="lp-cap" type="number" name="daily_pull_cap" min="1" max="500" value="{{ old('daily_pull_cap', $profile?->daily_pull_cap ?? 50) }}"
               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
        @error('daily_pull_cap') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <fieldset class="rounded-xl border border-gray-200 p-4">
        <legend class="px-1 text-xs font-bold uppercase tracking-wide text-gray-500">Hard filters (always win over the AI)</legend>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="hf-countries" class="mb-1 block text-xs font-medium text-gray-600">Countries or cities (comma separated)</label>
                <input id="hf-countries" type="text" name="hf_countries" value="{{ old('hf_countries', implode(', ', $filters['countries'] ?? [])) }}"
                       placeholder="Nigeria, Ghana" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
            </div>
            <div>
                <label for="hf-ranges" class="mb-1 block text-xs font-medium text-gray-600">Company size ranges (min,max; separate with ;)</label>
                <input id="hf-ranges" type="text" name="hf_employee_ranges" value="{{ old('hf_employee_ranges', implode('; ', $filters['employee_ranges'] ?? [])) }}"
                       placeholder="11,50; 51,200" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                @error('hf_employee_ranges') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="hf-include" class="mb-1 block text-xs font-medium text-gray-600">Always include titles</label>
                <input id="hf-include" type="text" name="hf_titles_include" value="{{ old('hf_titles_include', implode(', ', $filters['titles_include'] ?? [])) }}"
                       class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
            </div>
            <div>
                <label for="hf-exclude" class="mb-1 block text-xs font-medium text-gray-600">Never include titles</label>
                <input id="hf-exclude" type="text" name="hf_titles_exclude" value="{{ old('hf_titles_exclude', implode(', ', $filters['titles_exclude'] ?? [])) }}"
                       placeholder="Intern, Student" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
            </div>
        </div>
        <label class="mt-4 inline-flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="hf_require_verified_email" value="1" @checked(old('hf_require_verified_email', $filters['require_verified_email'] ?? false))>
            Only leads whose email the platform has verified
        </label>
    </fieldset>

    @if($profile)
        <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-800">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $profile->is_active))>
            Profile is on (pulls new leads automatically)
        </label>
    @endif
</div>
```

`resources/views/lead-profiles/create.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">New lead profile</h2>
    </x-slot>

    <div class="py-6 max-w-3xl mx-auto sm:px-4 lg:px-8">
        <form method="POST" action="{{ route('lead-profiles.store') }}" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @include('lead-profiles._form', ['profile' => null])
            <div class="mt-6 flex gap-3">
                <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">Create and build search</button>
                <a href="{{ route('lead-profiles.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
```

`resources/views/lead-profiles/edit.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ $profile->name }}</h2>
    </x-slot>

    <div class="py-6 max-w-3xl mx-auto sm:px-4 lg:px-8 space-y-6">

        <form method="POST" action="{{ route('lead-profiles.update', $profile) }}" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @method('PUT')
            @include('lead-profiles._form', ['profile' => $profile])
            <div class="mt-6">
                <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">Save profile</button>
            </div>
        </form>

        {{-- The generated search, for the admin to read and trust. Its own section: forms must not nest. --}}
        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm" aria-labelledby="gen-search">
            <h3 id="gen-search" class="font-display text-lg font-bold text-gray-900">Generated search</h3>

            @if($query)
                <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[10rem_1fr]">
                    @foreach([
                        'Job titles' => $query->titles,
                        'Excluded titles' => $query->excludeTitles,
                        'Seniority' => $query->seniorities,
                        'Person location' => $query->personLocations,
                        'Company location' => $query->organizationLocations,
                        'Company size' => $query->employeeRanges,
                        'Keywords' => $query->keywords === '' ? [] : [$query->keywords],
                    ] as $label => $values)
                        @continue($values === [])
                        <dt class="font-medium text-gray-600">{{ $label }}</dt>
                        <dd class="text-gray-900">{{ implode(', ', $values) }}</dd>
                    @endforeach
                    @if($query->requireVerifiedEmail)
                        <dt class="font-medium text-gray-600">Email</dt>
                        <dd class="text-gray-900">Verified only</dd>
                    @endif
                </dl>
            @else
                <p class="mt-2 text-sm text-gray-600">No search yet. Rebuild it from the brief; the profile cannot be switched on without one.</p>
            @endif

            <div class="mt-5 flex flex-wrap gap-3">
                <form method="POST" action="{{ route('lead-profiles.rebuild-query', $profile) }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Rebuild search from brief</button>
                </form>
                <form method="POST" action="{{ route('lead-profiles.pull', $profile) }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Pull a page now</button>
                </form>
                <form method="POST" action="{{ route('lead-profiles.reset-pull', $profile) }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Start from page one</button>
                </form>
            </div>

            @if($profile->last_pull_error)
                <p class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $profile->last_pull_error }}</p>
            @endif
        </section>

        <section class="rounded-xl border border-red-200 bg-white p-6 shadow-sm">
            <h3 class="font-display text-base font-bold text-gray-900">Delete this profile</h3>
            <p class="mt-1 text-sm text-gray-600">Leads it already pulled are kept.</p>
            <form method="POST" action="{{ route('lead-profiles.destroy', $profile) }}" class="mt-3"
                  onsubmit="return confirm('Delete this lead profile?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">Delete profile</button>
            </form>
        </section>
    </div>
</x-app-layout>
```

- [ ] **Step 7: Run and confirm pass**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/LeadProfileControllerTest.php`
Expected: `OK (14 tests, ...)`. Note: the index view links to `route('leads.index')`, which Task 12 defines; if this task runs before Task 12, add the `leads.index` route and controller first (Task 12 Steps 3-4), or run Task 12 immediately after. The suite for this task passes once the route exists.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/LeadProfileRequest.php app/Http/Controllers/LeadProfileController.php resources/views/lead-profiles routes/web.php tests/Feature/Leads/LeadProfileControllerTest.php
git commit -m "feat(leads): lead profile screens with AI-built reviewable search, pull now and reset"
```

---

### Task 12: Leads list and sidebar

**Files:**
- Create: `app/Http/Controllers/LeadController.php`, `resources/views/leads/index.blade.php`
- Modify: `routes/web.php`, `resources/views/layouts/navigation.blade.php`
- Test: `tests/Feature/Leads/LeadIndexTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LeadIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function as(string $role): static
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $this->actingAs($user);
    }

    public function test_managers_see_leads_and_agents_are_forbidden(): void
    {
        Lead::factory()->create(['name' => 'Ada Ob***i', 'company' => 'Acme Pay']);

        $this->as('manager')->get(route('leads.index'))->assertOk()->assertSee('Ada Ob***i')->assertSee('Acme Pay');
        $this->as('agent')->get(route('leads.index'))->assertForbidden();
    }

    public function test_unrevealed_emails_are_not_shown(): void
    {
        Lead::factory()->create(['name' => 'Hidden Person', 'email' => null]);
        Lead::factory()->create(['name' => 'Known Person', 'email' => 'known@acme.test', 'email_status' => 'verified']);

        $this->as('manager')->get(route('leads.index'))
            ->assertSee('known@acme.test')
            ->assertSee('Email hidden until qualified');
    }

    public function test_filters_by_status_and_profile(): void
    {
        $profile = LeadProfile::factory()->create(['name' => 'Fintech']);
        Lead::factory()->create(['name' => 'In Profile', 'lead_profile_id' => $profile->id, 'status' => Lead::STATUS_QUALIFIED]);
        Lead::factory()->create(['name' => 'Other One', 'status' => Lead::STATUS_NEW]);

        $this->as('manager')->get(route('leads.index', ['status' => 'qualified']))
            ->assertSee('In Profile')->assertDontSee('Other One');

        $this->get(route('leads.index', ['profile' => $profile->id]))
            ->assertSee('In Profile')->assertDontSee('Other One');
    }

    public function test_an_unknown_status_filter_is_ignored(): void
    {
        Lead::factory()->create(['name' => 'Visible Lead']);

        $this->as('manager')->get(route('leads.index', ['status' => 'bogus']))->assertOk()->assertSee('Visible Lead');
    }

    public function test_empty_state(): void
    {
        $this->as('manager')->get(route('leads.index'))->assertOk()->assertSee('No leads yet');
    }

    public function test_the_list_is_paginated(): void
    {
        Lead::factory()->count(60)->create();

        $this->as('manager')->get(route('leads.index'))->assertOk()->assertSee('page=2', false);
    }

    public function test_sidebar_shows_the_leads_section_to_managers_only(): void
    {
        $this->as('manager')->get(route('dashboard'))
            ->assertSee(route('leads.index'), false)
            ->assertSee(route('lead-profiles.index'), false)
            ->assertSee(route('lead-sources.index'), false);

        $this->as('agent')->get(route('dashboard'))
            ->assertDontSee(route('leads.index'), false)
            ->assertDontSee(route('lead-profiles.index'), false);
    }
}
```

- [ ] **Step 2: Run and confirm failure**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads/LeadIndexTest.php`
Expected: route `leads.index` not defined.

- [ ] **Step 3: Add the route**

In `routes/web.php` add `use App\Http\Controllers\LeadController;` and, after the `permission:leads.manage` group from Task 10, add:

```php
    Route::middleware('permission:leads.view')->group(function () {
        Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
    });
```

- [ ] **Step 4: Create the controller**

`app/Http/Controllers/LeadController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadProfile;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeadController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), Lead::STATUSES, true) ? (string) $request->query('status') : null;
        $profileId = $request->integer('profile') ?: null;

        $leads = Lead::query()
            ->with('profile:id,name')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($profileId, fn ($query) => $query->where('lead_profile_id', $profileId))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('leads.index', [
            'leads' => $leads,
            'status' => $status,
            'profileId' => $profileId,
            'statuses' => Lead::STATUSES,
            'profiles' => LeadProfile::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
```

- [ ] **Step 5: Create the view**

`resources/views/leads/index.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Leads</h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-4 lg:px-8 space-y-4">
        <form method="GET" action="{{ route('leads.index') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label for="f-status" class="mb-1 block text-xs font-medium text-gray-600">Status</label>
                <select id="f-status" name="status" class="rounded-lg border-gray-300 text-sm shadow-sm">
                    <option value="">All</option>
                    @foreach($statuses as $option)
                        <option value="{{ $option }}" @selected($status === $option)>{{ ucfirst($option) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="f-profile" class="mb-1 block text-xs font-medium text-gray-600">Profile</label>
                <select id="f-profile" name="profile" class="rounded-lg border-gray-300 text-sm shadow-sm">
                    <option value="">All</option>
                    @foreach($profiles as $profile)
                        <option value="{{ $profile->id }}" @selected($profileId === $profile->id)>{{ $profile->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Filter</button>
        </form>

        @if($leads->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center">
                <p class="font-display text-lg font-semibold text-gray-900">No leads yet</p>
                <p class="mt-1 text-sm text-gray-500">Create a lead profile and switch it on; matching leads appear here as they are pulled.</p>
                @can('leads.manage')
                    <a href="{{ route('lead-profiles.create') }}" class="mt-4 inline-block text-sm font-medium text-brand-text underline">New lead profile</a>
                @endcan
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Title</th>
                            <th class="px-4 py-3">Company</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Source</th>
                            <th class="px-4 py-3">Profile</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Pulled</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($leads as $lead)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $lead->name }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $lead->title ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $lead->company ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if($lead->email)
                                        <span class="font-data text-xs text-gray-800">{{ $lead->email }}</span>
                                    @else
                                        <span class="text-xs text-gray-500">Email hidden until qualified</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ ucfirst($lead->source) }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $lead->profile?->name ?? '—' }}</td>
                                <td class="px-4 py-3"><span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700">{{ ucfirst($lead->status) }}</span></td>
                                <td class="px-4 py-3 text-gray-500">{{ $lead->created_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $leads->links() }}
        @endif
    </div>
</x-app-layout>
```

- [ ] **Step 6: Add the sidebar section**

In `resources/views/layouts/navigation.blade.php`, directly above the line `{{-- Section: Tasks (Trello-style board) --}}`, insert:

```blade
            {{-- Section: Leads (AI lead CRM) --}}
            @canany(['leads.view', 'leads.manage'])
                <div>
                    <h3 class="px-3 mb-2 text-[11px] font-bold text-gray-500 uppercase tracking-wider">{{ __('Leads') }}</h3>
                    <div class="space-y-1">
                        @can('leads.view')
                            <x-sidebar-link :href="route('leads.index')" :active="request()->routeIs('leads.*')">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>
                                </svg>
                                {{ __('Leads') }}
                            </x-sidebar-link>
                        @endcan
                        @can('leads.manage')
                            <x-sidebar-link :href="route('lead-profiles.index')" :active="request()->routeIs('lead-profiles.*')">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                                </svg>
                                {{ __('Lead Profiles') }}
                            </x-sidebar-link>
                            <x-sidebar-link :href="route('lead-sources.index')" :active="request()->routeIs('lead-sources.*')">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"/>
                                </svg>
                                {{ __('Lead Sources') }}
                            </x-sidebar-link>
                        @endcan
                    </div>
                </div>
            @endcanany

```

- [ ] **Step 7: Run and confirm pass, then re-run Task 11's tests**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit tests/Feature/Leads`
Expected: all Leads tests pass (Task 11's index view now finds `leads.index`).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/LeadController.php resources/views/leads resources/views/layouts/navigation.blade.php routes/web.php tests/Feature/Leads/LeadIndexTest.php
git commit -m "feat(leads): Leads list with status/profile filters and a Leads sidebar section"
```

---

### Task 13: Docs, full verification, browser check

**Files:**
- Create: `docs/AI-LEAD-CRM.md`

- [ ] **Step 1: Write the doc**

Create `docs/AI-LEAD-CRM.md`:

```markdown
# AI Lead CRM

Design: `docs/superpowers/specs/2026-11-05-ai-lead-crm-design.md`.

## Built so far (phase 1)

- **Lead Sources** (`/lead-sources`): store a platform API key (encrypted), test the connection,
  and the global **pause all automation** switch.
- **Lead Profiles** (`/lead-profiles`): a plain-English brief plus hard filters. The AI turns the
  brief into a search once, on save; you can read it on the profile page. Hard filters always win.
- **Pulling**: `leads:pull` runs hourly and queues one page per active profile and source. The daily
  limit is soft (checked before each page). A failed page is retried next run; the cursor never skips.
- **Leads** (`/leads`): pulled leads with status and profile filters. Leads are NOT contacts: nothing
  here is reachable by a WhatsApp or email campaign.

## Apollo notes

- Search is free but returns only first name, obfuscated last name, title and company, no email.
- Revealing an email (`people/match`) costs about one credit. Phase 1 never reveals; phase 2 will,
  only for leads that pass qualification.
- The key must be allowed to call people search (a master key is the safe choice).

## Operating it

- Needs the scheduler (`schedule:run` every minute) and a queue worker.
- Needs an AI provider configured in Settings, or profile creation saves without a search.
- Permissions: `leads.view` (see leads), `leads.manage` (profiles, keys, kill switch); admin and manager.

## Not built yet

AI qualification, revealing emails, promoting to Contacts, email outreach, reply handling, the
autonomy levels, and the Hunter, Lusha, Apify and ZoomInfo connectors.
```

- [ ] **Step 2: Run the whole PHP suite and the JS tests**

Run: `C:/xamppp/php/php.exe vendor/bin/phpunit` (about five minutes; run it in the background)
Expected: all tests pass. Then `npm run test:js` (no JS changed; expect unchanged pass count).

- [ ] **Step 3: Verify in a real browser against the local server**

Seed a profile that needs no AI, then drive the screens. Apollo cannot be called without a real key, so this checks the screens and the error path; the Apollo calls themselves are covered by the `Http::fake` tests.

```bash
C:/xamppp/php/php.exe artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder --force
C:/xamppp/php/php.exe artisan tinker --execute='$p = App\Models\LeadProfile::create(["name"=>"Demo","brief"=>"Fintech founders in Lagos, small teams.","sources"=>["apollo"],"lead_query"=>["titles"=>["Founder"],"keywords"=>"fintech"],"is_active"=>true,"hard_filters"=>["countries"=>["Nigeria"]]]); foreach (range(1,3) as $i) { App\Models\Lead::factory()->create(["lead_profile_id"=>$p->id,"name"=>"Demo Lead $i"]); }'
(php -d opcache.enable=0 -S 127.0.0.1:8001 -t public server.php &)
```

Then with the Chrome harness (`scratchpad/bt`), log in as `admin@blastiq.com` and check, in both light and dark themes:
- the sidebar shows a **Leads** section (Leads, Lead Profiles, Lead Sources);
- `/lead-sources` renders, saving a short key shows a validation error, **Pause all automation** flips the banner;
- `/lead-profiles` lists "Demo" with status On and 3 leads; the edit page shows the generated search and the three action buttons;
- **Pull a page now** shows the "not configured" error (no key saved) and no exception;
- `/leads` lists the three demo leads with "Email hidden until qualified" and the filters work;
- no console errors and no horizontal overflow at 1440 px and 375 px.

- [ ] **Step 4: Clean up local data**

```bash
C:/xamppp/php/php.exe artisan tinker --execute='App\Models\Lead::query()->delete(); App\Models\LeadProfile::query()->delete(); App\Models\Setting::where("key","like","lead_%")->delete();'
taskkill //F //IM php.exe
```

- [ ] **Step 5: Commit**

```bash
git add docs/AI-LEAD-CRM.md
git commit -m "docs: AI lead CRM phase 1"
```

---

## Plan self-review

**Spec coverage (phase 1 scope):** lead profiles with brief, hard filters, sources, cap (Tasks 1, 11); `LeadSource` interface, registry, Apollo connector incl. reveal (2, 4); encrypted keys and kill switch (3, 10); AI brief to reviewable query with hard filters winning (5, 11); two-stage design groundwork: search stores partial leads, `reveal` implemented and tested but not orchestrated, which is phase 2 (4, 7); de-duplication (6); caps, cursor, errors, kill switch (7); schedule (8); `leads.*` permissions (9); screens and sidebar (10 to 12); events table with `pulled` events (1, 6). Deferred by design and listed in the doc: qualification, promotion, outreach, reply detection and handling, autonomy levels, connectors 2 to 5.

**Placeholder scan:** none; every step has code or an exact command.

**Type consistency:** `LeadQuery::fromArray/toArray/withHardFilters/isEmpty`, `LeadData::with`, `LeadPage($leads,$total,$hasMore)`, `LeadSource::{key,label,isConfigured,testConnection,search,reveal}`, `LeadConfig::{key,setKey,killSwitchOn,setKillSwitch,pageSize}`, `LeadPuller::pull` returning `{created,skipped,errors}`, `LeadIngestor::ingest` returning `{created,skipped}`, `LeadProfile::sourceState` / `hasQuery`, `PullLeadProfileJob::$profileId`, route names `lead-sources.*`, `lead-profiles.*`, `leads.index` are used consistently across tasks.

**Known ordering note:** Task 11's index view links to `leads.index` (Task 12). Execute Task 12 immediately after Task 11, or add the route first, as noted in Task 11 Step 7.
