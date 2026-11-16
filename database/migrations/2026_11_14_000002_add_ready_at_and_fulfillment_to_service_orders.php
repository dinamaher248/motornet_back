<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table("service_orders", function (Blueprint $t) {
            if (!Schema::hasColumn("service_orders", "fulfillment_type")) $t->string("fulfillment_type", 32)->nullable();
            if (!Schema::hasColumn("service_orders", "ready_at")) $t->timestamp("ready_at")->nullable();
        });
    }
    public function down(): void {}
};
