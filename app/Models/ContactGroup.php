<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactGroup extends Model
{
    use SoftDeletes, OwnedByUser;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'contact_count',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_group', 'group_id', 'contact_id');
    }

    public function getContactCountAttribute($value): int
    {
        return $value ?? $this->contacts()->count();
    }
}
