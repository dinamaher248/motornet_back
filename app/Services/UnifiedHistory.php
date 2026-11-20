<?php

namespace App\Services;

use App\Models\BiddingTrip;
use App\Models\ReservationBooking;
use App\Models\ServiceOrder;
use App\Models\TripRequest;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/** سجل موحّد لعمليات العميل: رحلات مزايدة، طلبات رحلات، طلبات متاجر/مخصصة، حجوزات. */
class UnifiedHistory
{
    /** يحوّل حالة أي عملية إلى إحدى: pending | accepted | refused | completed */
    public static function bucket(string $type, string $status): string
    {
        $s = strtolower($status);
        if (in_array($s, ['completed', 'delivered', 'finished'], true)) {
            return 'completed';
        }
        if (in_array($s, ['cancelled', 'canceled', 'rejected', 'refused', 'offer_rejected', 'offer_unavailable', 'unavailable', 'expired', 'declined'], true)) {
            return 'refused';
        }
        $pending = ['pending', 'awaiting_offers', 'pending_quote', 'quoted', 'offer_sent', 'new', 'priced', 'awaiting_merchant', 'awaiting_payment'];
        if (in_array($s, $pending, true)) {
            return 'pending';
        }

        return 'accepted';
    }

    public static function forCustomer(User $user, ?string $from = null, ?string $to = null): array
    {
        $items = [];
        $date = function ($q) use ($from, $to) {
            if ($from) {
                $q->whereDate('created_at', '>=', $from);
            }
            if ($to) {
                $q->whereDate('created_at', '<=', $to);
            }

            return $q;
        };
        $fmt = fn ($d) => optional($d)->format('Y-m-d H:i');

        try {
            foreach ($date(BiddingTrip::where('user_id', $user->id))->orderByDesc('id')->limit(100)->get() as $t) {
                $items[] = self::item('bidding_trip', $t->id, 'رحلة', $t->status, $t->pickup_address, $t->dropoff_address, $t->final_price ?? $t->customer_price, $fmt($t->created_at), $t->created_at);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            foreach ($date(TripRequest::where('user_id', $user->id))->orderByDesc('id')->limit(100)->get() as $t) {
                $items[] = self::item('trip_request', $t->id, 'طلب مشوار', $t->status, $t->pick_loc, $t->target_loc, null, $fmt($t->created_at), $t->created_at);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            foreach ($date(ServiceOrder::with('merchant')->where('user_id', $user->id))->orderByDesc('id')->limit(100)->get() as $o) {
                $custom = $o->order_type === 'custom';
                $title = ($custom ? 'طلب مخصص' : 'طلب') . ' — ' . ($o->merchant?->name ?? 'متجر');
                $items[] = self::item('service_order', $o->id, $title, $o->status, $o->merchant?->name, $o->delivery_address, $o->total_price, $fmt($o->created_at), $o->created_at, $custom ? ($o->custom_request_text ?? $o->product_name) : $o->product_name);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            if (Schema::hasTable('reservation_bookings')) {
                foreach ($date(ReservationBooking::with('merchant')->where('user_id', $user->id))->orderByDesc('id')->limit(100)->get() as $b) {
                    $items[] = self::item('reservation_booking', $b->id, 'حجز — ' . ($b->merchant?->name ?? 'مكان'), $b->status, $b->merchant?->name, null, $b->quoted_price, $fmt($b->created_at), $b->created_at, $b->quote_details ?: $b->customer_notes, ReservationBooking::STATUS_LABELS[$b->status] ?? null);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $items;
    }

    private static function item(string $type, int $id, string $title, ?string $rawStatus, ?string $pick, ?string $target, $amount, ?string $createdAt, $createdObj, ?string $msg = null, ?string $label = null): array
    {
        $raw = (string) $rawStatus;

        return [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'amount' => $amount,
            'seat_count' => null,
            'msg' => $msg,
            'pick_loc' => $pick,
            'target_loc' => $target,
            'status' => self::bucket($type, $raw),
            'raw_status' => $raw,
            'status_label' => $label,
            'created_at' => $createdAt,
            '_ts' => optional($createdObj)->getTimestamp() ?? 0,
            'other_party' => ['id' => null, 'name' => null, 'phone' => null, 'title' => null],
        ];
    }
}
