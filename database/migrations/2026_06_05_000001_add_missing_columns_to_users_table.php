<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $cols = [
            'username' => fn (Blueprint $t) => $t->string('username', 100)->nullable(),
            'type' => fn (Blueprint $t) => $t->string('type', 50)->nullable(),
            'title' => fn (Blueprint $t) => $t->string('title')->nullable(),
            'coins' => fn (Blueprint $t) => $t->integer('coins')->default(0),
            'is_admin' => fn (Blueprint $t) => $t->boolean('is_admin')->default(false),
            'nid_image' => fn (Blueprint $t) => $t->string('nid_image')->nullable(),
            'license_image' => fn (Blueprint $t) => $t->string('license_image')->nullable(),
            'image' => fn (Blueprint $t) => $t->string('image')->nullable(),
            'vehicle_type' => fn (Blueprint $t) => $t->string('vehicle_type')->nullable(),
            'vehicle_type_id' => fn (Blueprint $t) => $t->unsignedBigInteger('vehicle_type_id')->nullable(),
            'area' => fn (Blueprint $t) => $t->string('area')->nullable(),
            'is_paid' => fn (Blueprint $t) => $t->boolean('is_paid')->default(false),
            'is_approved' => fn (Blueprint $t) => $t->boolean('is_approved')->default(false),
            'subscription_end_date' => fn (Blueprint $t) => $t->dateTime('subscription_end_date')->nullable(),
            'is_online' => fn (Blueprint $t) => $t->boolean('is_online')->default(false),
            'current_lat' => fn (Blueprint $t) => $t->decimal('current_lat', 10, 7)->nullable(),
            'current_lng' => fn (Blueprint $t) => $t->decimal('current_lng', 10, 7)->nullable(),
            'location_updated_at' => fn (Blueprint $t) => $t->timestamp('location_updated_at')->nullable(),
            'governorate' => fn (Blueprint $t) => $t->string('governorate')->nullable(),
            'governorate_id' => fn (Blueprint $t) => $t->unsignedBigInteger('governorate_id')->nullable(),
            'area_id' => fn (Blueprint $t) => $t->unsignedBigInteger('area_id')->nullable(),
            'location_id' => fn (Blueprint $t) => $t->unsignedBigInteger('location_id')->nullable(),
            'avg_rating' => fn (Blueprint $t) => $t->decimal('avg_rating', 3, 2)->default(0),
            'ratings_count' => fn (Blueprint $t) => $t->unsignedInteger('ratings_count')->default(0),
        ];

        foreach ($cols as $name => $define) {
            if (!Schema::hasColumn('users', $name)) {
                Schema::table('users', function (Blueprint $table) use ($define) {
                    $define($table);
                });
            }
        }
    }

    public function down(): void
    {
    }
};
