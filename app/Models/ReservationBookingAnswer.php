<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationBookingAnswer extends Model
{
    protected $fillable = [
        'booking_id',
        'field_id',
        'field_label',
        'field_type',
        'value',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(ReservationBooking::class, 'booking_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ReservationCategoryField::class, 'field_id');
    }
}
