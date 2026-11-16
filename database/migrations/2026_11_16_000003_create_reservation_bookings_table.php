<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reservation_bookings')) {
            return;
        }

        Schema::create('reservation_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('merchant_products')->nullOnDelete();
            // pending_quote | unavailable | quoted | rejected | confirmed
            $table->string('status', 30)->default('pending_quote')->index();
            $table->text('customer_notes')->nullable();
            $table->decimal('quoted_price', 10, 2)->nullable();
            $table->text('quote_details')->nullable();
            $table->boolean('commission_eligible')->default(false);
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_bookings');
    }
};
