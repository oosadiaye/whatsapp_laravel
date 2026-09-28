<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BoardFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Board extends Model
{
    /** @use HasFactory<BoardFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['user_id', 'name', 'slug', 'description', 'archived_at'];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The live boards, i.e. the ones a person is meant to work in.
     *
     * The single definition of "live", used by the board list, the default-board
     * redirect, the Livewire switcher and the create form's board count. A
     * scope rather than a flag on the model because "not archived" is a
     * condition, and four separate `whereNull('archived_at')` calls is four
     * chances to forget one — which is how an archived board ends up as the
     * board /tasks drops you onto.
     *
     * Soft-deleted boards are excluded too: the default scope already does that,
     * and archiving is for a board that still exists.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position');
    }

    /**
     * Take the board out of circulation without touching what is on it.
     *
     * No cascade, and that is the whole point: archiving a board with 400 cards
     * must not mark 400 cards deleted, because restoring it then has to bring
     * all of them back and anything missed stays lost.
     */
    public function archive(): void
    {
        $this->forceFill(['archived_at' => now()])->save();
    }

    public function restoreFromArchive(): void
    {
        $this->forceFill(['archived_at' => null])->save();
    }

    /**
     * Cascade the soft delete.
     *
     * The schema's onDelete('cascade') is a HARD-delete rule and never fires
     * for soft deletes, so without this the board's cards would survive as
     * live rows and resurface in counts/queries. Only the delete path does this
     * — see archive() for why archiving must not.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $board): void {
            $board->tasks()->each(fn (Task $task) => $task->delete());
        });
    }

    public function routeKeyName(): string
    {
        return 'id';
    }
}
