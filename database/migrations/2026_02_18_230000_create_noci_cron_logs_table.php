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
        if (Schema::hasTable('noci_cron_logs')) {
            return;
        }

        Schema::create('noci_cron_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tenant_id');
            $table->string('job_key', 50);
            $table->string('command', 120);
            $table->string('status', 20)->default('success');
            $table->string('message', 255)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->longText('meta_json')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'started_at'], 'idx_cron_logs_tenant_started');
            $table->index(['tenant_id', 'status'], 'idx_cron_logs_tenant_status');
            $table->index(['tenant_id', 'job_key'], 'idx_cron_logs_tenant_job');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('noci_cron_logs');
    }
};

