<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reservation_booking_answers')) {
            return;
        }

        Schema::create('reservation_booking_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('reservation_bookings')->cascadeOnDelete();
            // الحقل قد يُحذف لاحقاً من الأدمن، فنحتفظ بالإجابة ونسخة من عنوان الحقل ونوعه.
            $table->foreignId('field_id')->nullable()->constrained('reservation_category_fields')->nullOnDelete();
            $table->string('field_label')->nullable();
            $table->string('field_type', 20)->nullable();
            // نص / رقم / الاختيار / مسار الصورة المرفوعة
            $table->text('value')->nullable();
            $table->timestamps();

            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_booking_answers');
    }
};
