<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReservationBooking extends Model
{
    public const STATUS_PENDING_QUOTE = 'pending_quote';
    public const STATUS_UNAVAILABLE = 'unavailable';
    public const STATUS_QUOTED = 'quoted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUSES = [
        self::STATUS_PENDING_QUOTE,
        self::STATUS_UNAVAILABLE,
        self::STATUS_QUOTED,
        self::STATUS_REJECTED,
        self::STATUS_CONFIRMED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_PENDING_QUOTE => 'بانتظار السعر',
        self::STATUS_UNAVAILABLE => 'غير متوفر حالياً',
        self::STATUS_QUOTED => 'تم إرسال السعر',
        self::STATUS_REJECTED => 'تم رفض العرض',
        self::STATUS_CONFIRMED => 'مؤكد',
    ];

    protected $fillable = [
        'user_id',
        'merchant_id',
        'category_id',
        'product_id',
        'status',
        'customer_notes',
        'quoted_price',
        'quote_details',
        'commission_eligible',
        'quoted_at',
        'responded_at',
        'confirmed_at',
    ];

    protected $casts = [
        'quoted_price' => 'float',
        'commission_eligible' => 'boolean',
        'quoted_at' => 'datetime',
        'responded_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MerchantProduct::class, 'product_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ReservationBookingAnswer::class, 'booking_id')->orderBy('id');
    }
}
