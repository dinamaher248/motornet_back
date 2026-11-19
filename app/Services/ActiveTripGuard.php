<?php

namespace App\Services;

use App\Models\BiddingTrip;
use App\Models\ServiceOrder;
use App\Models\TripRequest;

/** يمنع التداخل: عميل/سائق عنده رحلة شغّالة لا يقبل/يقدّم رحلة جديدة. */
class ActiveTripGuard
{
    public const ACTIVE = ['accepted', 'in_progress', 'handed_to_driver', 'going_to_pickup', 'picked_up', 'driver_assigned', 'in_delivery'];

    public const CUSTOMER_MSG = 'لديك رحلة جارية حالياً. أنهِ رحلتك الحالية أو ألغِها أولاً قبل قبول طلب جديد.';
    public const DRIVER_MSG = 'لديك رحلة جارية حالياً. أنهِ الرحلة الحالية أولاً قبل قبول طلب جديد.';

    public static function customerBusy(int $userId, ?int $exceptBiddingId = null, ?int $exceptTripRequestId = null): bool
    {
        try {
            if (BiddingTrip::where('user_id', $userId)->whereIn('status', self::ACTIVE)
                ->where(fn ($q) => $q->whereNull('notes')->orWhere(fn ($w) => $w->where('notes', 'not like', 'service_order:%')->where('notes', 'not like', 'custom_order:%')))
                ->when($exceptBiddingId, fn ($q) => $q->where('id', '!=', $exceptBiddingId))->exists()) {
                return true;
            }

            return TripRequest::where('user_id', $userId)->whereIn('status', self::ACTIVE)
                ->when($exceptTripRequestId, fn ($q) => $q->where('id', '!=', $exceptTripRequestId))->exists();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public static function driverBusy(int $driverId): bool
    {
        try {
            if (BiddingTrip::assignedTo($driverId)->whereIn('status', self::ACTIVE)->exists()) {
                return true;
            }
            if (TripRequest::whereIn('status', self::ACTIVE)
                ->whereHas('acceptedOffer', fn ($q) => $q->where('driver_id', $driverId))->exists()) {
                return true;
            }

            return ServiceOrder::where('driver_id', $driverId)->whereIn('status', self::ACTIVE)->exists();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public static function deny(string $msg)
    {
        return response()->json(['status' => false, 'status_code' => 409, 'message' => $msg], 409);
    }
}
