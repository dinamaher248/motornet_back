<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * صندوق إشعارات المستخدم الحالي فقط (لا يرى أحد إشعارات غيره).
 */
class NotificationController extends Controller
{
    public function index()
    {
        $user = Auth::guard('api')->user();

        if (!Schema::hasTable('app_notifications')) {
            return response()->json([
                'status_code' => 200,
                'data' => [],
                'unread_count' => 0,
            ]);
        }

        $rows = AppNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'status_code' => 200,
            'data' => $rows->map(fn (AppNotification $n) => $n->toApiArray())->values(),
            'unread_count' => AppNotification::query()
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function markRead(int $id)
    {
        $user = Auth::guard('api')->user();

        // الشرط على user_id يمنع تعديل إشعار شخص آخر.
        AppNotification::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['status_code' => 200, 'message' => 'تم']);
    }

    public function markAllRead()
    {
        $user = Auth::guard('api')->user();

        AppNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['status_code' => 200, 'message' => 'تم']);
    }
}
