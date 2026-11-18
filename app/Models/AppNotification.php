<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class AppNotification extends Model
{
    protected $table = 'app_notifications';

    protected $fillable = [
        'user_id',
        'title',
        'body',
        'action',
        'ref_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * يسجّل إشعاراً في صندوق المستخدم. لا يرمي استثناء أبداً كي لا يعطّل العملية الأصلية.
     */
    public static function record(
        User $user,
        string $title,
        string $body,
        string $action = 'general',
        ?string $refId = null
    ): ?self {
        try {
            if (!Schema::hasTable('app_notifications')) {
                return null;
            }

            return static::create([
                'user_id' => $user->id,
                'title' => $title,
                'body' => $body,
                'action' => $action,
                'ref_id' => $refId,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => (string) $this->body,
            'type' => $this->action,
            'action' => $this->action,
            'ref_id' => $this->ref_id,
            'is_read' => $this->read_at !== null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
