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
        if (Schema::hasTable('noci_olt_rx_logs')) {
            return;
        }

        Schema::create('noci_olt_rx_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tenant_id')->default(1);
            $table->unsignedBigInteger('olt_id');
            $table->string('fsp', 20)->comment('Frame/Slot/Port');
            $table->unsignedSmallInteger('onu_id');
            $table->string('sn', 50)->nullable();
            $table->decimal('rx_power', 8, 2);
            $table->timestamp('sampled_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'olt_id', 'fsp', 'onu_id', 'sampled_at'], 'idx_olt_rx_lookup');
            $table->index(['tenant_id', 'sampled_at'], 'idx_olt_rx_retention');
            $table->index(['tenant_id', 'olt_id', 'sampled_at'], 'idx_olt_rx_tenant_olt_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('noci_olt_rx_logs');
    }
};
