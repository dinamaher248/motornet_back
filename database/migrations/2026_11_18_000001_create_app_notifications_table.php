<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_notifications')) {
            return;
        }

        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            // صاحب الإشعار — كل مستخدم يرى إشعاراته فقط.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('action', 60)->default('general');
            $table->string('ref_id', 255)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
