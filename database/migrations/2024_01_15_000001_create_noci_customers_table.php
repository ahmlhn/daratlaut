<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - creates noci_customers (leads/prospects table).
     */
    public function up(): void
    {
        if (!Schema::hasTable('noci_customers')) {
            Schema::create('noci_customers', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(0)->index();
                $table->string('visit_id', 50)->nullable();
                $table->string('customer_name', 120)->nullable();
                $table->string('customer_phone', 30)->nullable();
                $table->text('customer_address')->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 30)->default('NEW');
                $table->string('source', 50)->nullable();
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->timestamp('last_seen')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                
                $table->index(['tenant_id', 'status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('noci_customers');
    }
};
