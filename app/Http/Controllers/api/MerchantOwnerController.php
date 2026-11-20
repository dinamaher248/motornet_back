<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantProduct;
use App\Models\ServiceCategory;
use App\Models\ServiceOrder;
use App\Models\SettlementRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\FcmService;
use App\Services\MerchantOrderNotificationService;
use App\Support\VendorOrderBilling;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class MerchantOwnerController extends Controller
{
    public function profile()
    {
        $user = $this->merchantUser();

        $merchant = Merchant::with('category')
            ->where('owner_user_id', $user->id)
            ->first();

        $categories = \App\Support\CategoryCatalog::all();
        if ($categories === []) {
            $categories = ServiceCategory::query()
                ->orderBy('id')
                ->get()
                ->map(function (ServiceCategory $c) {
                    return [
                        'id' => $c->id,
                        'slug' => $c->slug ?: ('category-'.$c->id),
                        'name' => $c->name ?: ($c->slug ?: 'تصنيف'),
                        'icon' => $c->icon,
                        'image' => $c->icon,
                        'hub_section' => $c->billingSection(),
                        'max_images' => $c->maxImages(),
                    ];
                })
                ->values()
                ->all();
        }

        return response()->json([
            'status_code' => 200,
            'data' => [
                'merchant' => $merchant ? $this->formatMerchant($merchant) : null,
                'categories' => $categories,
                // نرجعه فقط لو المتجر له تصنيف فعلي — عشان ما نمسّحش اختيار صاحب النشاط المحلي
                'vendor_hub_section' => $this->storedVendorHubSection($user, $merchant),
            ],
        ]);
    }

    public function categories(Request $request) { $this->merchantUser(); $s=strtolower(trim((string)$request->query("section",""))); $s=in_array($s,["orders","bookings","services","transport"],true)?$s:null; $i=\App\Support\CategoryCatalog::all($s,$s==="transport"); return response()->json(["status_code"=>200,"data"=>$i,"categories"=>$i]); }
    public function persistUserHubSection($u, $s): void { if(in_array($s,["orders","bookings","services"],true)&&\Illuminate\Support\Facades\Schema::hasColumn("users","vendor_hub_section")){ \App\Models\User::where("id",$u->id)->update(["vendor_hub_section"=>$s]); $u->vendor_hub_section=$s; } }
    private function storedVendorHubSection($u, $m): ?string { $fc=$m?->category?->billingSection(); if($fc)return $fc; if(\Illuminate\Support\Facades\Schema::hasColumn("users","vendor_hub_section")){ $s=strtolower(trim((string)($u->vendor_hub_section??""))); if(in_array($s,["orders","bookings","services"],true))return $s; } return null; }
    public function saveProfile(Request $request)
    {
        $user = $this->merchantUser();

        $data = $request->validate([
            'category_id' => 'required|exists:service_categories,id',
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:500',
            'phone' => 'required|string|max:30',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'working_hours' => 'nullable|json',
            'image' => 'nullable|image|max:4096',
        ]);

        $workingHours = null;
        if ($request->filled('working_hours')) {
            $decoded = json_decode($request->input('working_hours'), true);
            if (!is_array($decoded)) {
                return response()->json([
                    'status_code' => 422,
                    'message' => 'صيغة مواعيد العمل غير صحيحة',
                ], 422);
            }
            $workingHours = $decoded;
        }

        $merchant = Merchant::where('owner_user_id', $user->id)->first();

        if ($request->hasFile('image')) {
            if ($merchant?->image) {
                Storage::disk('public')->delete($merchant->image);
            }
            $data['image'] = $request->file('image')->store('merchants', 'public');
        }

        unset($data['working_hours']);

        $payload = [
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'address' => $data['address'],
            'phone' => $data['phone'],
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'working_hours' => $workingHours,
            'owner_user_id' => $user->id,
            'is_open' => true,
        ];

        if (isset($data['image'])) {
            $payload['image'] = $data['image'];
        }

        if ($merchant) {
            $merchant->update($payload);
            $merchant = $merchant->fresh(['category']);
        } else {
            $payload['is_active'] = true;
            $payload['pay_cash'] = false;
            $payload['pay_insta'] = false;
            $payload['pay_on_delivery'] = true;
            $payload['pay_wallet'] = false;
            $merchant = Merchant::create($payload);
            $merchant->load('category');
        }

        return response()->json([
            'status_code' => 200,
            'message' => 'تم حفظ بيانات النشاط بنجاح',
            'data' => $this->formatMerchant($merchant),
        ]);
    }

    public function paymentMethods()
    {
        $merchant = $this->ownedMerchant();

        return response()->json([
            'status_code' => 200,
            'data' => $this->formatPaymentMethods($merchant),
        ]);
    }

    public function savePaymentMethods(Request $request)
    {
        $merchant = $this->ownedMerchant();

        // طرق الدفع إلزامية لقسم الطلبات فقط؛ الحجوزات وخدمات الصنايعية لا تتطلب دفعًا داخل التطبيق.
        $requiresPaymentMethod = $this->resolveVendorHubSection($this->merchantUser(), $merchant) === 'orders';

        $data = $request->validate([
            'pay_on_delivery' => ($requiresPaymentMethod ? 'required' : 'nullable') . '|boolean',
            'pay_wallet' => ($requiresPaymentMethod ? 'required' : 'nullable') . '|boolean',
            'wallet_number' => 'nullable|string|max:30',
            'wallet_owner_name' => 'nullable|string|max:255',
            'wallet_type' => 'nullable|string|max:100',
        ]);

        $payWallet = $request->boolean('pay_wallet');
        $payOnDelivery = $request->boolean('pay_on_delivery');

        if ($requiresPaymentMethod && !$payOnDelivery && !$payWallet) {
            return response()->json([
                'status_code' => 422,
                'message' => 'يجب تفعيل طريقة دفع واحدة على الأقل',
            ], 422);
        }

        if ($payWallet) {
            if (empty(trim((string) ($data['wallet_number'] ?? '')))) {
                return response()->json([
                    'status_code' => 422,
                    'message' => 'رقم المحفظة مطلوب عند تفعيل المحفظة الإلكترونية',
                ], 422);
            }
            if (empty(trim((string) ($data['wallet_owner_name'] ?? '')))) {
                return response()->json([
                    'status_code' => 422,
                    'message' => 'اسم صاحب المحفظة مطلوب عند تفعيل المحفظة الإلكترونية',
                ], 422);
            }
        }

        $merchant->update([
            'pay_on_delivery' => $payOnDelivery,
            'pay_wallet' => $payWallet,
            'pay_cash' => false,
            'pay_insta' => false,
            'wallet_number' => $payWallet ? trim($data['wallet_number']) : $merchant->wallet_number,
            'wallet_owner_name' => $payWallet ? trim($data['wallet_owner_name']) : $merchant->wallet_owner_name,
            'wallet_type' => $payWallet ? ($data['wallet_type'] ?? null) : $merchant->wallet_type,
        ]);

        return response()->json([
            'status_code' => 200,
            'message' => 'تم حفظ طرق الدفع',
            'data' => $this->formatPaymentMethods($merchant->fresh()),
        ]);
    }

    public function products()
    {
        $merchant = $this->ownedMerchant();

        $products = $merchant->products()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (MerchantProduct $p) => $this->formatProduct($p, $this->isBookingsMerchant($merchant)));

        return response()->json([
            'status_code' => 200,
            'data' => [
                'merchant_id' => $merchant->id,
                'merchant_name' => $merchant->name,
                'max_products' => $this->maxImagesFor($merchant),
                'max_images' => $this->maxImagesFor($merchant),
                'count' => $products->count(),
                'products' => $products,
            ],
        ]);
    }

    public function storeProduct(Request $request)
    {
        $merchant = $this->ownedMerchant();

        $limit = $this->maxImagesFor($merchant);
        if ($merchant->products()->count() >= $limit) {
            return response()->json([
                'status_code' => 422,
                'message' => 'الحد الأقصى ' . $limit . ' صورة لهذا القسم',
            ], 422);
        }

        $isBookings = $this->isBookingsMerchant($merchant);

        // الحجوزات (معرض صور المكان): الصورة والعنوان والوصف اختيارية ولا يوجد سعر.
        $data = $request->validate($isBookings ? [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
            'image' => 'nullable|image|max:4096',
        ] : [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
            'image' => 'required|image|max:4096',
        ]);

        if ($isBookings) {
            // أعمدة name/price غير nullable في الجدول، فنخزن قيماً فارغة بدل تغيير الـ schema.
            $data['name'] = trim((string) ($data['name'] ?? ''));
            $data['price'] = 0;
        }

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('merchant_products', 'public');
        } else {
            unset($data['image']);
        }
        $data['is_active'] = $request->boolean('is_active', true);
        $data['sort_order'] = $merchant->products()->count() + 1;

        $product = $merchant->products()->create($data);

        return response()->json([
            'status_code' => 201,
            'message' => 'تم إضافة المنتج',
            'data' => $this->formatProduct($product, $isBookings),
        ], 201);
    }

    public function updateProduct(Request $request, int $productId)
    {
        $merchant = $this->ownedMerchant();
        $product = $merchant->products()->findOrFail($productId);

        $isBookings = $this->isBookingsMerchant($merchant);

        $data = $request->validate($isBookings ? [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
            'image' => 'nullable|image|max:4096',
        ] : [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
            'image' => 'nullable|image|max:4096',
        ]);

        if ($isBookings) {
            if (array_key_exists('name', $data)) {
                $data['name'] = trim((string) $data['name']);
            }
            unset($data['price']); // لا سعر في معرض الحجوزات
        }

        if ($request->hasFile('image')) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $data['image'] = $request->file('image')->store('merchant_products', 'public');
        }

        $data['is_active'] = $request->boolean('is_active', $product->is_active);
        $product->update($data);

        return response()->json([
            'status_code' => 200,
            'message' => 'تم تحديث المنتج',
            'data' => $this->formatProduct($product->fresh(), $isBookings),
        ]);
    }

    public function deleteProduct(int $productId)
    {
        $merchant = $this->ownedMerchant();
        $product = $merchant->products()->findOrFail($productId);

        if ($product->image) {
            Storage::disk('public')->delete($product->image);
        }

        $product->delete();

        return response()->json([
            'status_code' => 200,
            'message' => 'تم حذف المنتج',
        ]);
    }

    public function orders()
    {
        $merchant = $this->ownedMerchant();

        $orders = ServiceOrder::with(['user', 'invitedDriver', 'driver'])
            ->where('merchant_id', $merchant->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (ServiceOrder $order) => $this->formatOrder($order));

        $pendingCount = ServiceOrder::where('merchant_id', $merchant->id)
            ->where(function ($q) {
                $q->whereIn('status', ['new', 'awaiting_payment_review', 'awaiting_merchant_offer'])
                    ->orWhere('status', 'offer_accepted');
            })
            ->count();

        return response()->json([
            'status_code' => 200,
            'data' => [
                'pending_count' => $pendingCount,
                'orders' => $orders,
            ],
        ]);
    }

    public function showOrder(int $orderId)
    {
        $merchant = $this->ownedMerchant();
        $order = ServiceOrder::with(['user', 'invitedDriver', 'driver'])
            ->where('merchant_id', $merchant->id)
            ->findOrFail($orderId);

        return response()->json([
            'status_code' => 200,
            'data' => $this->formatOrder($order),
        ]);
    }

    public function acceptOrder(int $orderId)
    {
        $merchant = $this->ownedMerchant();
        $order = $this->findOwnedOrder($merchant, $orderId);

        if ($order->status !== 'new') {
            return response()->json([
                'status_code' => 422,
                'message' => 'لا يمكن قبول الطلب في حالته الحالية',
            ], 422);
        }

        $this->processOrderAcceptance($order, $merchant);

        app(MerchantOrderNotificationService::class)->notifyOwner(
            $merchant,
            'تم إرسال الطلب',
            'تم قبول الطلب وإرساله للسائقين',
            'service_order_sent',
            $order->id,
        );

        return response()->json([
            'status_code' => 200,
            'message' => 'تم قبول الطلب',
            'data' => $this->formatOrder($order->fresh(['user'])),
        ]);
    }

    public function rejectOrder(int $orderId)
    {
        $merchant = $this->ownedMerchant();
        $order = $this->findOwnedOrder($merchant, $orderId);

        if ($order->status !== 'new') {
            return response()->json([
                'status_code' => 422,
                'message' => 'لا يمكن رفض الطلب في حالته الحالية',
            ], 422);
        }

        $this->processOrderRejection($order);

        return response()->json([
            'status_code' => 200,
            'message' => 'تم رفض الطلب',
            'data' => $this->formatOrder($order->fresh(['user'])),
        ]);
    }

    public function confirmPayment(int $orderId)
    {
        $merchant = $this->ownedMerchant();
        $order = $this->findOwnedOrder($merchant, $orderId);

        if ($order->status !== 'awaiting_payment_review' || $order->payment_method !== 'wallet') {
            return response()->json([
                'status_code' => 422,
                'message' => 'لا يمكن تأكيد الدفع لهذا الطلب',
            ], 422);
        }

        $this->processOrderAcceptance($order, $merchant);

        app(MerchantOrderNotificationService::class)->notifyOwner(
            $merchant,
            'تم إرسال الطلب',
            'تم قبول الطلب وإرساله للسائقين',
            'service_order_sent',
            $order->id,
        );

        return response()->json([
            'status_code' => 200,
            'message' => 'تم تأكيد استلام الدفع وقبول الطلب',
            'data' => $this->formatOrder($order->fresh(['user'])),
        ]);
    }

    public function rejectPayment(int $orderId)
    {
        $merchant = $this->ownedMerchant();
        $order = $this->findOwnedOrder($merchant, $orderId);

        if ($order->status !== 'awaiting_payment_review' || $order->payment_method !== 'wallet') {
            return response()->json([
                'status_code' => 422,
                'message' => 'لا يمكن رفض الدفع لهذا الطلب',
            ], 422);
        }

        $this->processOrderRejection($order);

        return response()->json([
            'status_code' => 200,
            'message' => 'تم رفض الطلب — لم يتم استلام الدفع',
            'data' => $this->formatOrder($order->fresh(['user'])),
        ]);
    }

    public function sendCustomOffer(Request $request, int $orderId)
    {
        $merchant = $this->ownedMerchant();
        $order = $this->findOwnedOrder($merchant, $orderId);

        if ($order->order_type !== 'custom' || $order->status !== 'awaiting_merchant_offer') {
            return response()->json([
                'status_code' => 422,
                'message' => 'لا يمكن إرسال عرض لهذا الطلب',
            ], 422);
        }

        $data = $request->validate([
            'merchant_offer_price' => 'nullable|numeric|min:0',
            'merchant_offer_notes' => 'nullable|string|max:2000',
        ]);

        $price = isset($data['merchant_offer_price'])
            ? round((float) $data['merchant_offer_price'], 2)
            : null;

        $unavailable = $price === null || $price <= 0;
        $notes = trim((string) ($data['merchant_offer_notes'] ?? ''));
        if ($unavailable && $notes === '') {
            $notes = 'غير متوفر حالياً';
        }

        $order->update([
            'merchant_offer_price' => $unavailable ? null : $price,
            'merchant_offer_notes' => $notes !== '' ? $notes : null,
            'status' => $unavailable ? 'offer_unavailable' : 'offer_sent',
        ]);

        $order = $order->fresh(['user', 'merchant']);
        if ($order->user) {
            app(MerchantOrderNotificationService::class)->notifyCustomOffer($order->user, $order);
        }

        return response()->json([
            'status_code' => 200,
            'message' => 'تم إرسال العرض للعميل',
            'data' => $this->formatOrder($order),
        ]);
    }

    public function availableDriversForOrder(int $orderId)
    {
        $merchant = $this->ownedMerchant();
        $order = $this->findOwnedOrder($merchant, $orderId);

        if (!$this->canAssignNow($order)) {
            return response()->json([
                'status_code' => 422,
                'message' => 'جهّز الطلب أولاً (تم التجهيز) ثم اختر وسيلة التوصيل',
            ], 422);
        }

        $drivers = User::query()
            ->where('title', 'driver')
            ->where('is_online', true)
            
            ->orderBy('name')
            ->limit(40)
            ->get()
            ->map(fn (User $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'phone' => $d->phone,
                'vehicle_type' => $d->vehicle_type,
                'vehicle_type_label' => \App\Models\VehicleType::query()->where('key', \App\Models\VehicleType::normalizeKey($d->vehicle_type) ?? $d->vehicle_type)->value('name_ar') ?? $d->vehicle_type,
            ]);

        return response()->json(['status_code' => 200, 'data' => $drivers]);
    }

    public function assignDriver(Request $request, int $orderId)
    {
        $merchant = $this->ownedMerchant();
        $order = $this->findOwnedOrder($merchant, $orderId);

        if (!$this->canAssignNow($order)) {
            return response()->json([
                'status_code' => 422,
                'message' => 'لا يمكن تعيين سائق لهذا الطلب',
            ], 422);
        }

        $data = $request->validate([
            'driver_id' => 'required|exists:users,id',
        ]);

        $driver = User::where('id', $data['driver_id'])
            ->where('title', 'driver')
            ->firstOrFail();

        $order->update([
            'invited_driver_id' => $driver->id,
            'status' => 'driver_invited',
        ]);

        app(FcmService::class)->sendToUser(
            $driver,
            'طلب توصيل جديد',
            'طلب مخصص من ' . ($merchant->name ?? 'متجر') . ' — بانتظار قبولك',
            'new_service_delivery',
            (string) $order->id,
        );

        return response()->json([
            'status_code' => 200,
            'message' => 'تم إرسال الطلب للسائق',
            'data' => $this->formatOrder($order->fresh(['user', 'invitedDriver'])),
        ]);
    }

    public function duesSummary()
    {
        $user = $this->merchantUser();
        $merchant = Merchant::with('category')->where('owner_user_id', $user->id)->first();
        $section = $merchant?->category?->billingSection() ?? 'orders';

        $completedOrders = 0;
        if ($merchant) {
            $completedOrders = ServiceOrder::where('merchant_id', $merchant->id)
                ->where('status', 'completed')
                ->count();
        }

        $walletNumber = Schema::hasTable('settings')
            ? (Setting::getValue('wallet_number', '') ?? '')
            : '';

        $instapayNumber = Schema::hasTable('settings')
            ? (Setting::getValue('instapay_number', $walletNumber) ?? $walletNumber)
            : $walletNumber;

        return response()->json([
            'status_code' => 200,
            'data' => [
                'dues_balance' => (float) ($user->dues_balance ?? 0),
                'commission_rate' => (float) ($user->commission_rate ?? 10),
                'completed_orders_count' => $completedOrders,
                'bookings_count' => $completedOrders,
                'wallet_number' => $walletNumber,
                'instapay_number' => $instapayNumber,
                'hub_section' => $section,
                'billing_mode' => VendorOrderBilling::resolveMode($user, $section),
                'commission_option_enabled' => VendorOrderBilling::commissionOptionEnabled($section),
                'fixed_fee_option_enabled' => VendorOrderBilling::fixedFeeOptionEnabled($section),
                'order_commission_percent' => VendorOrderBilling::commissionPercent($section),
                'order_fixed_fee' => VendorOrderBilling::fixedFeeAmount($section),
                'fixed_fee_per_order' => VendorOrderBilling::fixedFeeAmount($section),
                'debt_limit' => (float) (Setting::getValue('dues_debt_limit', Setting::getValue('debt_limit', '0')) ?? 0),
            ],
        ]);
    }

    public function submitSettlement(Request $request)
    {
        $user = $this->merchantUser();

        $request->validate([
            'payment_proof' => 'required|image|mimes:jpeg,png,jpg,webp|max:4096',
        ]);

        $balance = (float) ($user->dues_balance ?? 0);
        if ($balance <= 0) {
            return response()->json([
                'status_code' => 422,
                'message' => 'لا يوجد رصيد مستحق للسداد',
            ], 422);
        }

        $hasPending = SettlementRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            return response()->json([
                'status_code' => 422,
                'message' => 'لديك طلب سداد بانتظار مراجعة الإدارة',
            ], 422);
        }

        $proof = $request->file('payment_proof')->store('settlements', 'public');

        SettlementRequest::create([
            'user_id' => $user->id,
            'account_type' => 'merchant',
            'amount' => $balance,
            'payment_proof' => $proof,
            'status' => 'pending',
        ]);

        return response()->json([
            'status_code' => 201,
            'message' => 'تم إرسال طلب السداد وبانتظار مراجعة الإدارة',
        ], 201);
    }

    private function canAssignNow(ServiceOrder $o): bool
    {
        if ($o->status === 'offer_accepted') {
            return true;
        }

        // توصيل بواسطة المكان: بعد "تم التجهيز" يختار التاجر وسيلة التوصيل.
        return $o->fulfillment_type === 'merchant_delivery'
            && $this->isReady($o)
            && empty($o->driver_id)
            && empty($o->invited_driver_id)
            && !$this->hasActiveDispatch($o)
            && in_array($o->status, ['preparing', 'accepted', 'awaiting_driver'], true);
    }
    private function isReady(ServiceOrder $o): bool { return $o->status==="ready" || !empty($o->ready_at); }
    private function canMarkReady(ServiceOrder $o): bool { return !$this->isReady($o) && in_array($o->status, ["offer_accepted","driver_invited","awaiting_driver","driver_assigned","preparing","accepted"], true); }
    public function markReady(int $orderId)
    {
        $m = $this->ownedMerchant();
        $o = $this->findOwnedOrder($m, $orderId);
        if ($this->isReady($o)) {
            return response()->json(['status_code' => 200, 'message' => 'الطلب جاهز بالفعل', 'data' => $this->formatOrder($o->load(['driver', 'invitedDriver']))]);
        }
        if (!$this->canMarkReady($o)) {
            return response()->json(['status_code' => 422, 'message' => 'لا يمكن تعليم الطلب جاهزاً الآن'], 422);
        }
        $o->update(['ready_at' => now()]);
        $o = $o->fresh(['user', 'merchant', 'driver', 'invitedDriver']);
        $fcm = app(FcmService::class);
        $name = $o->merchant?->name ?? 'المكان';
        $phone = trim((string) ($o->merchant?->phone ?? ''));
        $callTxt = $phone !== '' ? ' — للاتصال: ' . $phone : '';

        if ($o->user) {
            if ($o->fulfillment_type === 'pickup') {
                $title = 'طلبك جاهز للاستلام';
                $body = 'تم تجهيز طلبك رقم #' . $o->id . ' — يمكنك استلامه من ' . $name . $callTxt;
            } elseif ($o->fulfillment_type === 'merchant_delivery') {
                $title = 'طلبك جاهز';
                $body = 'طلبك جاهز ومطلوب تسليمه بواسطة المكان' . $callTxt;
            } else {
                $title = 'طلبك جاهز';
                $body = 'طلبك رقم #' . $o->id . ' جاهز الآن' . $callTxt;
            }
            try {
                $fcm->sendToUser($o->user, $title, $body, 'service_order_ready', (string) $o->id);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $drv = $o->driver ?? $o->invitedDriver;
        if ($drv) {
            try {
                $fcm->sendToUser($drv, 'الطلب جاهز', 'طلب #' . $o->id . ' جاهز للاستلام من ' . $name, 'service_order_ready', (string) $o->id);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['status_code' => 200, 'message' => 'تم تعليم الطلب جاهزاً وإبلاغ العميل', 'data' => $this->formatOrder($o)]);
    }

    /** «تم التسليم» من التاجر: للاستلام من المتجر، أو كبديل احتياطي للتوصيل. */
    public function markDelivered(int $orderId)
    {
        $m = $this->ownedMerchant();
        $o = $this->findOwnedOrder($m, $orderId);
        if ($o->status === 'completed') {
            return response()->json(['status_code' => 200, 'message' => 'الطلب مكتمل بالفعل', 'data' => $this->formatOrder($o)]);
        }
        if (!$this->canDeliver($o)) {
            return response()->json(['status_code' => 422, 'message' => 'لا يمكن إنهاء الطلب في حالته الحالية'], 422);
        }
        $o->update(['status' => 'completed']);
        $o = $o->fresh(['user', 'merchant', 'driver', 'invitedDriver']);
        if ($o->user) {
            try {
                app(FcmService::class)->sendToUser($o->user, 'تم تسليم الطلب', 'تم تسليم طلبك رقم #' . $o->id . ' بنجاح. يمكنك الآن تقييم المكان', 'service_order_status', (string) $o->id);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['status_code' => 200, 'message' => 'تم تسليم الطلب', 'data' => $this->formatOrder($o)]);
    }

    private function canDeliver(ServiceOrder $o): bool
    {
        if (in_array($o->status, ['completed', 'cancelled', 'rejected', 'offer_rejected', 'offer_unavailable', 'pending', 'offer_sent'], true)) {
            return false;
        }

        return $o->fulfillment_type === 'pickup' || $o->fulfillment_type === 'merchant_delivery' || $this->isReady($o);
    }

    /** هل أُرسل الطلب لسائقين عبر شاشة المواصلات (رحلة نشطة مرتبطة)؟ */
    private function hasActiveDispatch(ServiceOrder $o): bool
    {
        try {
            return \App\Models\BiddingTrip::whereIn('notes', ['service_order:' . $o->id, 'custom_order:' . $o->id])
                ->whereNotIn('status', ['cancelled', 'canceled', 'rejected', 'expired'])->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
    private function findOwnedOrder(Merchant $merchant, int $orderId): ServiceOrder
    {
        return ServiceOrder::with(['user', 'merchant'])
            ->where('merchant_id', $merchant->id)
            ->findOrFail($orderId);
    }

    private function processOrderAcceptance(ServiceOrder $order, Merchant $merchant): void
    {
        DB::transaction(function () use ($order) {
            // لا يكتمل ولا تُحسب عمولة التاجر إلا بعد تسليم السائق للعميل.
            $handledByPlace = in_array($order->fulfillment_type, ['pickup', 'merchant_delivery'], true);
            $order->update(['status' => $handledByPlace ? 'preparing' : 'awaiting_driver']);
        });

        if ($order->user) {
            app(FcmService::class)->sendToUser(
                $order->user,
                'تم قبول طلبك',
                'جاري تجهيز طلبك من ' . ($merchant->name ?? '') . ' وبانتظار السائق',
                'service_order_accepted',
                (string) $order->id,
            );
        }

        if (!in_array($order->fulfillment_type, ['pickup', 'merchant_delivery'], true)) {
            $this->notifyDrivers($order->fresh(['merchant']));
        }
    }

    private function processOrderRejection(ServiceOrder $order): void
    {
        $order->update(['status' => 'merchant_rejected']);

        if ($order->user) {
            app(FcmService::class)->sendToUser(
                $order->user,
                'تم رفض الطلب',
                'عذرًا، المتجر رفض طلبك',
                'service_order_rejected',
                (string) $order->id,
            );
        }
    }

    private function notifyDrivers(ServiceOrder $order): void
    {
        $drivers = User::query()
            ->where('title', 'driver')
            ->where('is_online', true)
            
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '!=', '')
            ->limit(40)
            ->get();

        $fcm = app(FcmService::class);
        foreach ($drivers as $driver) {
            $fcm->sendToUser(
                $driver,
                'طلب توصيل جديد',
                'استلام من: ' . ($order->merchant->name ?? ''),
                'new_service_delivery',
                (string) $order->id,
            );
        }
    }

    private function formatOrder(ServiceOrder $order): array
    {
        $customer = $order->user;
        $isWallet = $order->payment_method === 'wallet';
        $isCatalogPending = in_array($order->status, ['new', 'awaiting_payment_review'], true);
        $isCustom = ($order->order_type ?? 'catalog') === 'custom';
        // Contact only after merchant accepted / offer accepted / delivery started.
        $showCustomer = in_array($order->status, [
            'preparing',
            'ready',
            'offer_accepted',
            'driver_invited',
            'awaiting_driver',
            'driver_assigned',
            'handed_to_driver',
            'in_delivery',
            'in_progress',
            'completed',
        ], true);

        return [
            'id' => $order->id,
            'order_type' => $order->order_type ?? 'catalog',
            'product_name' => $order->product_name,
            'product_price' => $order->product_price,
            'product_description' => $order->product_description,
            'custom_request_text' => $order->custom_request_text,
            'custom_request_image' => $order->custom_request_image,
            'quantity' => $order->quantity,
            'total_price' => $order->total_price,
            'merchant_offer_price' => $order->merchant_offer_price,
            'merchant_offer_notes' => $order->merchant_offer_notes,
            'commission_amount' => $order->commission_amount,
            'notes' => $order->notes,
            'delivery_address' => $order->delivery_address,
            'delivery_details' => $order->delivery_details,
            'delivery_lat' => $showCustomer ? $order->delivery_lat : null,
            'delivery_lng' => $showCustomer ? $order->delivery_lng : null,
            'store_address' => $order->merchant?->address,
            'store_lat' => $order->merchant?->latitude ?? $order->merchant?->lat ?? null,
            'store_lng' => $order->merchant?->longitude ?? $order->merchant?->lng ?? null,
            'payment_method' => $order->payment_method,
            'payment_method_label' => $this->paymentLabel($order->payment_method),
            'payment_proof' => $order->payment_proof,
            'status' => $order->status,
            'status_label' => $this->statusLabel($order->status), 'fulfillment_type' => $order->fulfillment_type ?: 'pending', 'fulfillment_label' => LocalServiceController::fulfillmentLabel($order->fulfillment_type), 'delivery_type' => $order->fulfillment_type ?: null, 'receiving_method' => $order->fulfillment_type ?: null, 'is_ready' => $this->isReady($order), 'can_mark_ready' => $this->canMarkReady($order),
            'customer' => $showCustomer && $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone ?? $order->customer_contact_phone,
                'email' => $customer->email,
                'avg_rating' => round((float) ($customer->avg_rating ?? 0), 1),
                'ratings_count' => (int) ($customer->ratings_count ?? 0),
            ] : ($customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'avg_rating' => round((float) ($customer->avg_rating ?? 0), 1),
                'ratings_count' => (int) ($customer->ratings_count ?? 0),
            ] : null),
            'customer_contact_phone' => $showCustomer ? $order->customer_contact_phone : null,
            'can_contact' => $showCustomer,
            'can_accept' => !$isCustom && $isCatalogPending && $order->status === 'new',
            'can_reject' => !$isCustom && $isCatalogPending && $order->status === 'new',
            'can_confirm_payment' => !$isCustom && $isCatalogPending && $isWallet && $order->status === 'awaiting_payment_review',
            'can_reject_payment' => !$isCustom && $isCatalogPending && $isWallet && $order->status === 'awaiting_payment_review',
            'can_send_offer' => $isCustom && $order->status === 'awaiting_merchant_offer',
            'can_assign_driver' => $this->canAssignNow($order),
            'can_deliver' => $this->canDeliver($order),
            'dispatched' => $this->hasActiveDispatch($order),
            'is_ready' => $this->isReady($order),
            'can_mark_ready' => $this->canMarkReady($order),
            'invited_driver' => $order->invitedDriver ? [
                'id' => $order->invitedDriver->id,
                'name' => $order->invitedDriver->name,
                'phone' => $order->invitedDriver->phone,
                'avg_rating' => round((float) ($order->invitedDriver->avg_rating ?? 0), 1),
                'ratings_count' => (int) ($order->invitedDriver->ratings_count ?? 0),
            ] : null,
            'driver' => $order->driver ? [
                'id' => $order->driver->id,
                'name' => $order->driver->name,
                'phone' => $showCustomer ? $order->driver->phone : null,
                'avg_rating' => round((float) ($order->driver->avg_rating ?? 0), 1),
                'ratings_count' => (int) ($order->driver->ratings_count ?? 0),
            ] : null,
            'created_at' => optional($order->created_at)->format('Y-m-d H:i'),
        ];
    }

    private function paymentLabel(string $method): string
    {
        return match ($method) {
            'wallet' => 'محفظة إلكترونية',
            'cash' => 'كاش',
            'insta' => 'انستا باي',
            'on_delivery' => 'دفع عند الاستلام',
            default => $method,
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'new' => 'طلب جديد',
            'awaiting_payment_review' => 'بانتظار مراجعة الدفع',
            'awaiting_merchant_offer' => 'بانتظار إرسال عرض',
            'offer_sent' => 'عرض مرسل — بانتظار العميل',
            'offer_rejected' => 'رفض العميل العرض',
            'offer_unavailable' => 'غير متوفر — تم إبلاغ العميل',
            'offer_accepted' => 'قبل العميل — بانتظار تعيين سائق',
            'merchant_rejected' => 'مرفوض',
            'preparing' => 'جاري التجهيز',
            'awaiting_driver' => 'جاري التنفيذ — بانتظار سائق',
            'driver_invited' => 'جاري التنفيذ — بانتظار قبول السائق',
            'driver_assigned' => 'جاري التنفيذ — مع السائق',
            'handed_to_driver' => 'جاري التنفيذ — تم التسليم للسائق',
            'in_delivery' => 'جاري التوصيل للعميل',
            'completed' => 'مكتمل (تم التسليم للعميل)',
            'cancelled' => 'ملغي',
            default => $status,
        };
    }

    private function ownedMerchant(): Merchant
    {
        $user = $this->merchantUser();

        $merchant = Merchant::with('category')->where('owner_user_id', $user->id)->first();

        if (!$merchant) {
            abort(422, 'يجب إكمال بيانات النشاط أولاً');
        }

        return $merchant;
    }

    /** الحد الأقصى للصور مأخوذ من إعداد القسم في لوحة الإدارة. */
    private function maxImagesFor(Merchant $merchant): int
    {
        return $merchant->category?->maxImages() ?? ServiceCategory::DEFAULT_MAX_IMAGES;
    }

    /** جزء النشاط: من تصنيف المتجر أو من عمود المستخدم إن وُجد. */
    private function resolveVendorHubSection(User $user, ?Merchant $merchant): string
    {
        $fromCategory = $merchant?->category?->billingSection();
        if ($fromCategory) {
            return $fromCategory;
        }

        if (Schema::hasColumn('users', 'vendor_hub_section')) {
            $section = strtolower(trim((string) ($user->vendor_hub_section ?? '')));
            if (in_array($section, ['orders', 'bookings', 'services'], true)) {
                return $section;
            }
        }

        return 'orders';
    }

    private function isBookingsMerchant(Merchant $merchant): bool
    {
        return ($merchant->category?->billingSection() ?? 'orders') === 'bookings';
    }

    private function formatProduct(MerchantProduct $product, bool $hidePrice = false): array
    {
        $out = [
            'id' => $product->id,
            'name' => $hidePrice && trim((string) $product->name) === '' ? null : $product->name,
            'price' => $product->price,
            'description' => $product->description,
            'image' => $product->image,
            'is_active' => $product->is_active,
            'sort_order' => $product->sort_order,
        ];

        if ($hidePrice) {
            unset($out['price']);
        }

        return $out;
    }

    private function merchantUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        if (!in_array($user->title, ['merchant', 'vendor'], true)) {
            abort(403, 'هذه الصفحة مخصصة لأصحاب الأنشطة فقط');
        }

        return $user;
    }

    private function formatMerchant(Merchant $merchant): array
    {
        return [
            'id' => $merchant->id,
            'category_id' => $merchant->category_id,
            'category_name' => $merchant->category?->name,
            'category_slug' => $merchant->category?->slug,
            'category_hub_section' => $merchant->category?->billingSection(),
            'name' => $merchant->name,
            'image' => $merchant->image,
            'address' => $merchant->address,
            'phone' => $merchant->phone,
            'lat' => $merchant->lat,
            'lng' => $merchant->lng,
            'working_hours' => $merchant->working_hours ?? [],
            'is_open' => $merchant->is_open,
            'is_active' => $merchant->is_active,
            'avg_rating' => round((float) ($merchant->avg_rating ?? 0), 1),
            'ratings_count' => (int) ($merchant->ratings_count ?? 0),
            'pay_on_delivery' => $merchant->pay_on_delivery,
            'pay_wallet' => $merchant->pay_wallet,
        ];
    }

    private function formatPaymentMethods(Merchant $merchant): array
    {
        return [
            'pay_on_delivery' => $merchant->pay_on_delivery,
            'pay_wallet' => $merchant->pay_wallet,
            'wallet_number' => $merchant->wallet_number,
            'wallet_owner_name' => $merchant->wallet_owner_name,
            'wallet_owner_display' => self::maskWalletOwnerName($merchant->wallet_owner_name),
            'wallet_type' => $merchant->wallet_type,
        ];
    }

    public static function maskWalletOwnerName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || count($parts) === 0) {
            return $name;
        }

        $result = $parts[0];
        for ($i = 1; $i < min(3, count($parts)); $i++) {
            $letter = mb_substr($parts[$i], 0, 1);
            if ($letter !== '') {
                $result .= ' ' . $letter;
            }
        }

        return $result;
    }
}
