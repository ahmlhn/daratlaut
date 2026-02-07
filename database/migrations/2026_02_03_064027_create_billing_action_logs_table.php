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
        Schema::create('noci_billing_action_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tenant_id')->default(1);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action_name', 50)->comment('CREATE, UPDATE, DELETE, PAYMENT, SUSPEND, ACTIVATE');
            $table->string('ref_type', 50)->comment('Customer, Invoice, Payment, etc');
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'ref_type', 'ref_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('noci_billing_action_logs');
    }
};
