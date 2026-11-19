<?php

namespace App\Services;

use App\Models\BiddingTrip;
use App\Models\ServiceOrder;

/** يربط رحلة المزايدة التي أنشأها التاجر (notes = service_order:ID) بطلب العميل الأصلي. */
class LinkedOrderSync
{
    public static function find(?BiddingTrip $trip): ?ServiceOrder
    {
        $notes = (string) ($trip->notes ?? '');
        if (!preg_match('/^(?:service_order|custom_order):(\d+)$/', trim($notes), $m)) {
            return null;
        }

        return ServiceOrder::with(['user', 'merchant'])->find((int) $m[1]);
    }

    public static function onAccepted(BiddingTrip $trip, ?int $driverId): void
    {
        try {
            $o = self::find($trip);
            if (!$o || in_array($o->status, ['completed', 'cancelled'], true)) {
                return;
            }
            $o->update(['status' => 'in_delivery', 'driver_id' => $driverId ?: $o->driver_id]);
            if ($o->user) {
                app(FcmService::class)->sendToUser($o->user, 'طلبك في الطريق', 'سائق في طريقه لتوصيل طلبك رقم #' . $o->id, 'service_order_status', (string) $o->id);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function onCompleted(BiddingTrip $trip): void
    {
        try {
            $o = self::find($trip);
            if (!$o || $o->status === 'completed') {
                return;
            }
            $o->update(['status' => 'completed']);
            if ($o->user) {
                app(FcmService::class)->sendToUser($o->user, 'تم تسليم الطلب', 'تم تسليم طلبك رقم #' . $o->id . ' بنجاح. يمكنك الآن تقييم السائق', 'service_order_status', (string) $o->id);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
