<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TaskStatusFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A board column.
 *
 * Statuses are company-wide (single-tenant, matching the boards/tasks
 * convention) so every board shares one vocabulary.
 */
class TaskStatus extends Model
{
    /** @use HasFactory<TaskStatusFactory> */
    use HasFactory;

    /** Tailwind-safe tokens, so a value can never inject markup/classes. */
    public const COLORS = [
        'gray',
        'red',
        'amber',
        'yellow',
        'green',
        'emerald',
        'teal',
        'sky',
        'blue',
        'indigo',
        'violet',
        'pink',
        'rose',
    ];

    protected $fillable = [
        'name',
        'slug',
        'color',
        'position',
        'is_default',
        'is_completed',
        'wip_limit',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_completed' => 'boolean',
            'position' => 'integer',
            'wip_limit' => 'integer',
        ];
    }

    /**
     * The ceiling actually in force for this column, or null when there is none.
     *
     * A completed column never has one, whatever is stored: "at most 3 cards in
     * Done" is not a constraint, it is a misconfiguration, and a permanently
     * red Done column teaches people to ignore the badge everywhere else.
     * TaskStatusController clears the stored value when a column is flagged
     * completed, so this is the second line of defence for a row written before
     * that rule existed, or straight to the database.
     */
    public function effectiveLimit(): ?int
    {
        return $this->is_completed ? null : $this->wip_limit;
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'status', 'slug');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /**
     * The live columns, as [slug => definition] keyed for the board view.
     *
     * Falls back to config/task-statuses.php only when the table is empty, so
     * a board still renders on an install whose seeder has not run instead of
     * throwing. Once seeded, the database is the single source of truth.
     *
     * @return array<string, array{name: string, color: string, completed: bool, limit: int|null}>
     */
    public static function columns(): array
    {
        $rows = static::query()->ordered()->get();

        if ($rows->isEmpty()) {
            return static::fallbackColumns();
        }

        return $rows->mapWithKeys(fn (self $s): array => [
            $s->slug => [
                'name' => $s->name,
                'color' => $s->color,
                'completed' => $s->is_completed,
                'limit' => $s->effectiveLimit(),
            ],
        ])->all();
    }

    /**
     * Slugs of every column flagged as completed.
     *
     * Used to decide that a card is no longer at risk: an overdue date on a
     * card sitting in "done" must not render as overdue.
     */
    public static function completedSlugs(): array
    {
        $columns = static::columns();

        return array_keys(array_filter($columns, fn (array $column): bool => (bool) ($column['completed'] ?? false)));
    }

    /**
     * Human label for a slug, from the runtime table.
     *
     * Consumers outside the board itself (notification email, for one) used to
     * read config('task-statuses...'), which is only the empty-table fallback -
     * so renaming a column at runtime left emails announcing the old name. All
     * label lookups now go through the same source as the board header.
     */
    public static function label(string $slug): string
    {
        $columns = static::columns();

        if (isset($columns[$slug]['name']) && filled($columns[$slug]['name'])) {
            return (string) $columns[$slug]['name'];
        }

        // Unknown or retired slug: title-case it rather than leak the raw key,
        // so a card that moved onto a since-deleted column still reads sanely.
        return ucfirst(str_replace('_', ' ', $slug));
    }

    /**
     * The slug new cards land in, falling back to the first column so the
     * create form always has a valid selection.
     */
    public static function defaultSlug(): string
    {
        $default = static::query()->where('is_default', true)->ordered()->value('slug')
            ?? static::query()->ordered()->value('slug');

        return (string) ($default
            ?? config('task-statuses.default_status', 'todo'));
    }

    /**
     * Column definitions used only when task_statuses is empty.
     *
     * A limit may be set here as a bootstrap default, since the shape has to
     * match columns() either way - but it is still only a fallback.
     *
     * @return array<string, array{name: string, color: string, completed: bool, limit: int|null}>
     */
    private static function fallbackColumns(): array
    {
        $configured = (array) config('task-statuses.statuses', []);

        return collect($configured)->mapWithKeys(fn (array $c, string $slug): array => [
            $slug => [
                'name' => (string) ($c['name'] ?? $slug),
                'color' => (string) ($c['color'] ?? 'gray'),
                'completed' => in_array($slug, (array) config('task-statuses.completed', []), true),
                'limit' => isset($c['limit']) ? (int) $c['limit'] : null,
            ],
        ])->all();
    }
}
