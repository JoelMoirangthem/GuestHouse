<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest's rating of a completed stay — SCHEMA.md section 13.
 *
 * Nothing here is mass-assignable except the answers themselves; who submitted
 * it, for which request and when are set by FeedbackService.
 */
class Feedback extends Model
{
    protected $table = 'feedback';

    /** The four rated aspects, in display order, with their labels. */
    public const ASPECTS = [
        'rating_cleanliness' => 'Cleanliness',
        'rating_staff' => 'Staff courtesy and service',
        'rating_facilities' => 'Facilities and amenities',
        'rating_overall' => 'Overall experience',
    ];

    protected $fillable = [
        'rating_cleanliness', 'rating_staff', 'rating_facilities', 'rating_overall', 'comments',
    ];

    protected function casts(): array
    {
        return [
            'rating_cleanliness' => 'integer',
            'rating_staff' => 'integer',
            'rating_facilities' => 'integer',
            'rating_overall' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public function bookingRequest(): BelongsTo
    {
        return $this->belongsTo(BookingRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
