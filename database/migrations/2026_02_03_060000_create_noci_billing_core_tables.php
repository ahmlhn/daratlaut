<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('noci_billing_plans')) {
            Schema::create('noci_billing_plans', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id');
                $table->string('code', 50);
                $table->string('name', 120);
                $table->decimal('price', 18, 2)->default(0);
                $table->enum('billing_cycle', ['MONTHLY', 'WEEKLY', 'ONETIME'])->default('MONTHLY');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'code'], 'uq_billing_plan_code');
                $table->index(['tenant_id', 'is_active'], 'idx_billing_plan_active');
            });
        }

        if (!Schema::hasTable('noci_billing_customers')) {
            Schema::create('noci_billing_customers', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id');
                $table->unsignedBigInteger('legacy_customer_id')->nullable();
                $table->string('customer_code', 60);
                $table->string('full_name', 150);
                $table->string('phone', 40)->nullable();
                $table->string('email', 120)->nullable();
                $table->text('address')->nullable();
                $table->unsignedBigInteger('plan_id')->nullable();
                $table->string('profile_name', 120)->nullable();
                $table->string('nas_name', 120)->nullable();
                $table->string('pop_name', 120)->nullable();
                $table->string('odp_name', 120)->nullable();
                $table->string('reseller_name', 120)->nullable();
                $table->enum('service_status', ['AKTIF', 'SUSPEND', 'NONAKTIF'])->default('AKTIF');
                $table->unsignedTinyInteger('billing_day')->default(1);
                $table->unsignedSmallInteger('grace_days')->default(0);
                $table->date('next_invoice_date')->nullable();
                $table->date('started_at')->nullable();
                $table->date('ended_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'customer_code'], 'uq_billing_customer_code');
                $table->index(['tenant_id', 'service_status'], 'idx_billing_customer_status');
                $table->index(['tenant_id', 'next_invoice_date'], 'idx_billing_customer_next_invoice');
                $table->index(['tenant_id', 'plan_id'], 'idx_billing_customer_plan');
                $table->foreign('plan_id', 'fk_billing_customer_plan')
                    ->references('id')
                    ->on('noci_billing_plans')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('noci_billing_customers')) {
            Schema::drop('noci_billing_customers');
        }

        if (Schema::hasTable('noci_billing_plans')) {
            Schema::drop('noci_billing_plans');
        }
    }
};
