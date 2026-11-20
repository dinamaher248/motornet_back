<?php
namespace App\Http\Controllers\api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class VendorController extends Controller {
    public function store(Request $r) {
        $o = app(MerchantOwnerController::class);
        $d = ($o->profile()->getData(true))["data"] ?? [];
        $p = !empty($d["merchant"]) ? (($o->paymentMethods()->getData(true))["data"] ?? []) : [];
        return response()->json(["status_code"=>200, "data"=>array_merge($d, ["payment_methods"=>$p])]);
    }
    public function categories(Request $r) { return app(MerchantOwnerController::class)->categories($r); }
    public function updateStore(Request $r) {
        $o = app(MerchantOwnerController::class);
        if ($r->filled("hub_section") && !$r->filled("category_id")) { $o->persistUserHubSection(Auth::user(), strtolower(trim((string)$r->input("hub_section")))); return $this->store($r); }
        $res = $o->saveProfile($r);
        if ($res->getStatusCode() >= 400) return $res;
        if ($r->has("pay_on_delivery") || $r->has("pay_wallet")) { $pr = $o->savePaymentMethods($r); if ($pr->getStatusCode() >= 400) return $pr; }
        return $this->store($r);
    }
    public function products() { return app(MerchantOwnerController::class)->products(); }
    public function storeProduct(Request $r) { return app(MerchantOwnerController::class)->storeProduct($r); }
    public function updateProduct(Request $r, int $id) { return app(MerchantOwnerController::class)->updateProduct($r, $id); }
    public function deleteProduct(int $id) { return app(MerchantOwnerController::class)->deleteProduct($id); }
    public function toggleProduct(int $id) {
        $o = app(MerchantOwnerController::class);
        $m = (new \ReflectionMethod($o, 'ownedMerchant'));
        $m->setAccessible(true);
        $merchant = $m->invoke($o);
        $product = $merchant->products()->findOrFail($id);
        $product->update(['is_active' => !$product->is_active]);
        $f = (new \ReflectionMethod($o, 'formatProduct'));
        $f->setAccessible(true);
        return response()->json(['status_code'=>200, 'message'=>'تم تحديث حالة المنتج', 'data'=>$f->invoke($o, $product->fresh())]);
    }
    public function orderStatus(Request $r, int $id) {
        $o = app(MerchantOwnerController::class);
        $st = (string) $r->input('status');
        if (in_array($st, ['completed', 'delivered'], true)) { return $o->markDelivered($id); }
        if ($st === 'ready') { return $o->markReady($id); }
        if ($st === 'rejected' || $st === 'cancelled') { return $o->rejectOrder($id); }
        return $o->acceptOrder($id);
    }
    public function orders(Request $r) { return app(MerchantOwnerController::class)->orders($r); }
    public function showOrder(int $id) { return app(MerchantOwnerController::class)->showOrder($id); }
    public function sendOffer(Request $r, int $id) { return app(MerchantOwnerController::class)->sendOffer($r, $id); }
    public function assignDriver(Request $r, int $id) { return app(MerchantOwnerController::class)->assignDriver($r, $id); }
    public function availableDrivers(int $id) { return app(MerchantOwnerController::class)->availableDriversForOrder($id); }
    public function dues() { return app(MerchantOwnerController::class)->duesSummary(); }
    public function submitSettlement(Request $r) { return app(MerchantOwnerController::class)->submitSettlement($r); }
}
