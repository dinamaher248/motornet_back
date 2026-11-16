<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable("users") && !Schema::hasColumn("users", "vendor_hub_section")) {
            Schema::table("users", fn (Blueprint $t) => $t->string("vendor_hub_section", 32)->nullable());
        }
    }
    public function down(): void {}
};
