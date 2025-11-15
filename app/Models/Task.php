<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class Task extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'category_id',
        'title',
        'description',
        'priority',
        'status',
        'previous_status',
        'due_date',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    /**
     * The attributes that should be cast.
     *
     * Using the $casts property ensures Eloquent will cast attributes
     * to the appropriate types (including backed enums) when getting/setting.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'due_date' => 'date',
        'priority' => \App\Enums\PriorityEnum::class,
        'status' => \App\Enums\StatusEnum::class,
        'previous_status' => \App\Enums\StatusEnum::class,
    ];

    /**
     * Get the user that owns the task.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the category that owns the task.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Scope a query to only include non-archived tasks.
     */
    public function scopeActive(Builder $query)
    {
        return $query->where('status', '!=', 'archived');
    }

    /**
     * Scope a query to only include archived tasks.
     */
    public function scopeArchived(Builder $query)
    {
        return $query->where('status', 'archived');
    }
}
