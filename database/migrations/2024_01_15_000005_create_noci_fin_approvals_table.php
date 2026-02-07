<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - creates noci_fin_approvals table.
     */
    public function up(): void
    {
        if (!Schema::hasTable('noci_fin_approvals')) {
            Schema::create('noci_fin_approvals', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(0)->index();
                $table->unsignedBigInteger('tx_id');
                $table->string('action', 20); // approve, reject
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('actor_name', 80)->nullable();
                $table->string('actor_role', 40)->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->useCurrent();
                
                $table->index(['tenant_id', 'tx_id']);
                
                $table->foreign('tx_id')->references('id')->on('noci_fin_tx')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('noci_fin_approvals');
    }
};
