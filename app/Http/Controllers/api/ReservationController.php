<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\ReservationBooking;
use App\Models\ReservationBookingAnswer;
use App\Models\ReservationCategoryField;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * الحجوزات (hub_section = bookings): نموذج ديناميكي + مسار عرض السعر والتأكيد.
 *
 * pending_quote --(owner)--> unavailable
 * pending_quote --(owner)--> quoted --(customer)--> confirmed | rejected
 */
class ReservationController extends Controller
{
    /* ------------------------------------------------------------------
     | Schema (للتطبيق)
     * ------------------------------------------------------------------ */

    public function categoryFields(int $id): JsonResponse
    {
        $category = ServiceCategory::where('is_active', true)->findOrFail($id);

        // نرجع الحقول المعرّفة لهذا التصنيف أياً كان قسمه؛ التحقق من قسم الحجوزات يتم عند الإرسال فقط.
        return response()->json([
            'status_code' => 200,
            'data' => [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'fields' => $category->reservationFields->map(fn (ReservationCategoryField $f) => $f->toSchemaArray())->values(),
            ],
        ]);
    }

    /* ------------------------------------------------------------------
     | العميل
     * ------------------------------------------------------------------ */

    /** "إرسال لمعرفة السعر والتفاصيل" */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::guard('api')->user();

        $base = $request->validate([
            'merchant_id' => 'required|integer|exists:merchants,id',
            'product_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:1000',
        ]);

        $merchant = Merchant::with(['category', 'owner'])->where('is_active', true)->findOrFail($base['merchant_id']);
        $category = $merchant->category;

        if (!$category || !$category->is_active || $category->billingSection() !== 'bookings') {
            return response()->json([
                'status_code' => 422,
                'message' => 'هذا النشاط لا يقبل الحجوزات',
            ], 422);
        }

        $productId = null;
        if (!empty($base['product_id'])) {
            $productId = $merchant->products()->whereKey($base['product_id'])->value('id');
            if (!$productId) {
                return response()->json(['status_code' => 422, 'message' => 'العنصر المحدد غير موجود'], 422);
            }
        }

        $fields = $category->reservationFields;
        $errors = [];
        $values = []; // field_id => value (نص أو UploadedFile)

        foreach ($fields as $field) {
            $key = 'answers.' . $field->id;
            $error = null;
            $values[$field->id] = $this->readAnswer($request, $field, $key, $error);

            if ($error !== null) {
                $errors[$key] = [$error];
            }
        }

        if ($errors) {
            return response()->json([
                'status_code' => 422,
                'message' => 'يرجى مراجعة بيانات النموذج',
                'errors' => $errors,
            ], 422);
        }

        $storedFiles = [];
        try {
            $booking = DB::transaction(function () use ($user, $merchant, $category, $productId, $base, $fields, $values, &$storedFiles) {
                $booking = ReservationBooking::create([
                    'user_id' => $user->id,
                    'merchant_id' => $merchant->id,
                    'category_id' => $category->id,
                    'product_id' => $productId,
                    'status' => ReservationBooking::STATUS_PENDING_QUOTE,
                    'customer_notes' => $base['notes'] ?? null,
                    'commission_eligible' => false,
                ]);

                foreach ($fields as $field) {
                    $value = $values[$field->id];
                    if ($value === null || $value === '') {
                        continue;
                    }

                    if ($value instanceof \Illuminate\Http\UploadedFile) {
                        $value = $value->store('reservation_answers', 'public');
                        $storedFiles[] = $value;
                    }

                    ReservationBookingAnswer::create([
                        'booking_id' => $booking->id,
                        'field_id' => $field->id,
                        'field_label' => $field->label,
                        'field_type' => $field->field_type,
                        'value' => (string) $value,
                    ]);
                }

                return $booking;
            });
        } catch (\Throwable $e) {
            foreach ($storedFiles as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $e;
        }

        $this->notify(
            $merchant->owner,
            'طلب حجز جديد',
            'عميل يطلب معرفة السعر والتفاصيل — طلب #' . $booking->id,
            'new_reservation_booking',
            $booking->id,
        );

        return response()->json([
            'status_code' => 201,
            'message' => 'تم إرسال طلبك، سيصلك السعر والتفاصيل قريباً',
            'data' => $this->format($booking->load(['merchant', 'answers'])),
        ], 201);
    }

    public function myBookings(Request $request): JsonResponse
    {
        $user = Auth::guard('api')->user();

        $query = ReservationBooking::with(['merchant', 'answers'])
            ->where('user_id', $user->id)
            ->orderByDesc('id');

        if ($request->filled('status') && in_array($request->query('status'), ReservationBooking::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }

        return response()->json([
            'status_code' => 200,
            'data' => $query->get()->map(fn (ReservationBooking $b) => $this->format($b))->values(),
        ]);
    }

    public function showMine(int $id): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $booking = ReservationBooking::with(['merchant', 'answers'])->where('user_id', $user->id)->findOrFail($id);

        return response()->json(['status_code' => 200, 'data' => $this->format($booking)]);
    }

    public function acceptQuote(int $id): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $booking = ReservationBooking::with(['merchant.owner', 'answers'])->where('user_id', $user->id)->findOrFail($id);

        if ($booking->status !== ReservationBooking::STATUS_QUOTED) {
            return $this->invalidState();
        }

        $booking->update([
            'status' => ReservationBooking::STATUS_CONFIRMED,
            'commission_eligible' => true, // لحساب العمولة لاحقاً
            'responded_at' => now(),
            'confirmed_at' => now(),
        ]);

        $this->notify(
            $booking->merchant?->owner,
            'تم تأكيد الحجز',
            'وافق العميل على السعر — حجز #' . $booking->id,
            'reservation_confirmed',
            $booking->id,
        );

        return response()->json([
            'status_code' => 200,
            'message' => 'تم تأكيد الحجز',
            'data' => $this->format($booking->fresh(['merchant', 'answers'])),
        ]);
    }

    public function rejectQuote(int $id): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $booking = ReservationBooking::with(['merchant.owner', 'answers'])->where('user_id', $user->id)->findOrFail($id);

        if ($booking->status !== ReservationBooking::STATUS_QUOTED) {
            return $this->invalidState();
        }

        $booking->update([
            'status' => ReservationBooking::STATUS_REJECTED,
            'responded_at' => now(),
        ]);

        $this->notify(
            $booking->merchant?->owner,
            'تم رفض العرض',
            'رفض العميل السعر المقترح — حجز #' . $booking->id,
            'reservation_rejected',
            $booking->id,
        );

        return response()->json([
            'status_code' => 200,
            'message' => 'تم رفض العرض',
            'data' => $this->format($booking->fresh(['merchant', 'answers'])),
        ]);
    }

    /* ------------------------------------------------------------------
     | صاحب النشاط
     * ------------------------------------------------------------------ */

    /** ?status=pending_quote|quoted|confirmed|... (الحجوزات المؤكدة: status=confirmed) */
    public function ownerIndex(Request $request): JsonResponse
    {
        $merchant = $this->ownedMerchant();

        $query = ReservationBooking::with(['user', 'answers'])
            ->where('merchant_id', $merchant->id)
            ->orderByDesc('id');

        if ($request->filled('status') && in_array($request->query('status'), ReservationBooking::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }

        return response()->json([
            'status_code' => 200,
            'data' => $query->get()->map(fn (ReservationBooking $b) => $this->format($b, true))->values(),
        ]);
    }

    public function ownerShow(int $id): JsonResponse
    {
        $merchant = $this->ownedMerchant();
        $booking = ReservationBooking::with(['user', 'answers'])->where('merchant_id', $merchant->id)->findOrFail($id);

        return response()->json(['status_code' => 200, 'data' => $this->format($booking, true)]);
    }

    public function markUnavailable(int $id): JsonResponse
    {
        $merchant = $this->ownedMerchant();
        $booking = ReservationBooking::with(['user', 'answers'])->where('merchant_id', $merchant->id)->findOrFail($id);

        if ($booking->status !== ReservationBooking::STATUS_PENDING_QUOTE) {
            return $this->invalidState();
        }

        $booking->update([
            'status' => ReservationBooking::STATUS_UNAVAILABLE,
            'responded_at' => now(),
        ]);

        $this->notify(
            $booking->user,
            'غير متوفر حالياً',
            ($merchant->name ?: 'النشاط') . ' غير متوفر حالياً لطلبك #' . $booking->id,
            'reservation_unavailable',
            $booking->id,
        );

        return response()->json([
            'status_code' => 200,
            'message' => 'تم تحديث الحالة إلى غير متوفر حالياً',
            'data' => $this->format($booking->fresh(['user', 'answers']), true),
        ]);
    }

    public function sendQuote(Request $request, int $id): JsonResponse
    {
        $merchant = $this->ownedMerchant();
        $booking = ReservationBooking::with(['user', 'answers'])->where('merchant_id', $merchant->id)->findOrFail($id);

        $data = $request->validate([
            'quoted_price' => 'nullable|numeric|min:0|max:99999999',
            'quote_details' => 'nullable|string|max:2000',
        ]);

        if ($booking->status !== ReservationBooking::STATUS_PENDING_QUOTE) {
            return $this->invalidState();
        }

        // سعر فاضي أو صفر = غير متوفر (حالة منتهية بدون أزرار عند العميل)
        if (empty($data['quoted_price']) || (float) $data['quoted_price'] <= 0) {
            $details = trim((string) ($data['quote_details'] ?? ''));
            $booking->update([
                'status' => ReservationBooking::STATUS_UNAVAILABLE,
                'quote_details' => $details !== '' ? $details : null,
                'responded_at' => now(),
            ]);
            $this->notify(
                $booking->user,
                'غير متوفر حالياً',
                ($merchant->name ?: 'النشاط') . ' غير متوفر حالياً لطلبك #' . $booking->id . ($details !== '' ? ' — ' . $details : ''),
                'reservation_unavailable',
                $booking->id,
            );

            return response()->json([
                'status_code' => 200,
                'message' => 'تم إبلاغ العميل بعدم التوفر',
                'data' => $this->format($booking->fresh(['user', 'answers']), true),
            ]);
        }

        $booking->update([
            'status' => ReservationBooking::STATUS_QUOTED,
            'quoted_price' => $data['quoted_price'],
            'quote_details' => $data['quote_details'] ?? null,
            'quoted_at' => now(),
            'responded_at' => now(),
        ]);

        $this->notify(
            $booking->user,
            'وصلك عرض سعر',
            'السعر المقترح ' . rtrim(rtrim(number_format((float) $data['quoted_price'], 2, '.', ''), '0'), '.') . ' ج.م من ' . ($merchant->name ?: 'النشاط'),
            'reservation_quoted',
            $booking->id,
        );

        return response()->json([
            'status_code' => 200,
            'message' => 'تم إرسال السعر للعميل',
            'data' => $this->format($booking->fresh(['user', 'answers']), true),
        ]);
    }

    /* ------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    /**
     * يقرأ إجابة حقل واحد ويتحقق منها. يرجع نصاً أو UploadedFile أو null.
     */
    private function readAnswer(Request $request, ReservationCategoryField $field, string $key, ?string &$error)
    {
        $label = $field->label;
        $isEmpty = false;

        if ($field->field_type === 'image') {
            $file = $request->file($key);
            if ($file === null) {
                $isEmpty = true;
            } elseif (!$file->isValid() || !str_starts_with((string) $file->getMimeType(), 'image/') || $file->getSize() > 4096 * 1024) {
                $error = "الحقل «{$label}» يجب أن يكون صورة بحجم أقصى 4 ميجا";

                return null;
            } else {
                return $file;
            }
        } else {
            $raw = $request->input($key);
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            $isEmpty = $value === '';
        }

        if ($isEmpty) {
            if ($field->is_required) {
                $error = "الحقل «{$label}» مطلوب";
            }

            return null;
        }

        switch ($field->field_type) {
            case 'number':
                if (!is_numeric($value)) {
                    $error = "الحقل «{$label}» يجب أن يكون رقماً";

                    return null;
                }
                break;
            case 'select':
            case 'radio':
                if (!in_array($value, $field->options ?? [], true)) {
                    $error = "اختر قيمة صحيحة للحقل «{$label}»";

                    return null;
                }
                break;
            default:
                if (mb_strlen($value) > 2000) {
                    $error = "الحقل «{$label}» طويل جداً";

                    return null;
                }
        }

        return $value;
    }

    private function ownedMerchant(): Merchant
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        if (!in_array($user->title, ['merchant', 'vendor'], true)) {
            abort(403, 'هذه الصفحة مخصصة لأصحاب الأنشطة فقط');
        }

        $merchant = Merchant::where('owner_user_id', $user->id)->first();
        if (!$merchant) {
            abort(422, 'يجب إكمال بيانات النشاط أولاً');
        }

        return $merchant;
    }

    private function notify(?User $user, string $title, string $body, string $action, int $refId): void
    {
        if (!$user) {
            return;
        }

        try {
            app(FcmService::class)->sendToUser($user, $title, $body, $action, (string) $refId);
        } catch (\Throwable $e) {
            report($e); // فشل الإشعار لا يجب أن يُفشل العملية
        }
    }

    private function invalidState(): JsonResponse
    {
        return response()->json([
            'status_code' => 422,
            'message' => 'لا يمكن تنفيذ هذا الإجراء على حالة الحجز الحالية',
        ], 422);
    }

    private function format(ReservationBooking $b, bool $forOwner = false): array
    {
        $out = [
            'id' => $b->id,
            'status' => $b->status,
            'status_label' => ReservationBooking::STATUS_LABELS[$b->status] ?? $b->status,
            'merchant_id' => $b->merchant_id,
            'product_id' => $b->product_id,
            'customer_notes' => $b->customer_notes,
            'quoted_price' => $b->quoted_price,
            'quote_details' => $b->quote_details,
            'commission_eligible' => (bool) $b->commission_eligible,
            'quoted_at' => optional($b->quoted_at)->toIso8601String(),
            'confirmed_at' => optional($b->confirmed_at)->toIso8601String(),
            'created_at' => optional($b->created_at)->toIso8601String(),
            'answers' => $b->answers->map(fn (ReservationBookingAnswer $a) => [
                'field_id' => $a->field_id,
                'label' => $a->field_label,
                'field_type' => $a->field_type,
                'value' => $a->value,
            ])->values(),
        ];

        if ($forOwner) {
            $out['customer'] = [
                'id' => $b->user?->id,
                'name' => $b->user?->name,
                'phone' => $b->user?->phone,
            ];
        } else {
            $out['merchant'] = [
                'id' => $b->merchant?->id,
                'name' => $b->merchant?->name,
                'phone' => $b->merchant?->phone,
            ];
        }

        return $out;
    }
}
