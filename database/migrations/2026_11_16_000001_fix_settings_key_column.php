<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Setting model queries `key`, but the original migration created `key_name`.
 * Makes the table match the model, safe on any state of the live DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
            return;
        }

        if (Schema::hasColumn('settings', 'key_name') && !Schema::hasColumn('settings', 'key')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->renameColumn('key_name', 'key');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'key') && !Schema::hasColumn('settings', 'key_name')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->renameColumn('key', 'key_name');
            });
        }
    }
};
