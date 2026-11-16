<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationCategoryField extends Model
{
    public const TYPES = ['text', 'number', 'image', 'select', 'radio'];

    public const TYPE_LABELS = [
        'text' => 'نص',
        'number' => 'رقم',
        'image' => 'صورة',
        'select' => 'قائمة منسدلة',
        'radio' => 'اختيار واحد (Radio)',
    ];

    protected $fillable = [
        'category_id',
        'label',
        'field_type',
        'options',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function hasOptions(): bool
    {
        return in_array($this->field_type, ['select', 'radio'], true);
    }

    public function toSchemaArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'field_type' => $this->field_type,
            'options' => $this->hasOptions() ? array_values($this->options ?? []) : [],
            'is_required' => (bool) $this->is_required,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
