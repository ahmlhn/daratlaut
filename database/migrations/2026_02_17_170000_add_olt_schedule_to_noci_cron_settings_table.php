<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('noci_cron_settings')) {
            return;
        }

        Schema::table('noci_cron_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('noci_cron_settings', 'olt_enabled')) {
                $table->boolean('olt_enabled')->default(false)->after('reminder_base_url');
            }
            if (!Schema::hasColumn('noci_cron_settings', 'olt_time')) {
                $table->string('olt_time', 5)->default('02:15')->after('olt_enabled');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('noci_cron_settings')) {
            return;
        }

        Schema::table('noci_cron_settings', function (Blueprint $table) {
            if (Schema::hasColumn('noci_cron_settings', 'olt_time')) {
                $table->dropColumn('olt_time');
            }
            if (Schema::hasColumn('noci_cron_settings', 'olt_enabled')) {
                $table->dropColumn('olt_enabled');
            }
        });
    }
};
