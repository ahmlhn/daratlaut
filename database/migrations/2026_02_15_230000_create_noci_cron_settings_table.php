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
            Schema::create('noci_cron_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id');
                $table->boolean('nightly_enabled')->default(false);
                $table->string('nightly_time', 5)->default('21:30');
                $table->boolean('reminders_enabled')->default(false);
                $table->string('reminders_time', 5)->default('07:00');
                $table->string('reminder_base_url', 255)->nullable();
                $table->timestamps();

                $table->unique('tenant_id');
                $table->index(['nightly_enabled', 'nightly_time'], 'idx_cron_nightly');
                $table->index(['reminders_enabled', 'reminders_time'], 'idx_cron_reminders');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('noci_cron_settings');
    }
};
