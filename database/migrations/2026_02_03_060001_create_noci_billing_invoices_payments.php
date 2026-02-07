<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('noci_billing_invoices')) {
            Schema::create('noci_billing_invoices', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id');
                $table->unsignedBigInteger('customer_id');
                $table->string('invoice_no', 80);
                $table->char('period_key', 7)->comment('YYYY-MM');
                $table->date('issue_date');
                $table->date('due_date');
                $table->enum('status', ['DRAFT', 'OPEN', 'PARTIAL', 'PAID', 'OVERDUE', 'VOID'])->default('OPEN');
                $table->decimal('subtotal', 18, 2)->default(0);
                $table->decimal('discount_amount', 18, 2)->default(0);
                $table->decimal('penalty_amount', 18, 2)->default(0);
                $table->decimal('tax_amount', 18, 2)->default(0);
                $table->decimal('total_amount', 18, 2)->default(0);
                $table->decimal('paid_amount', 18, 2)->default(0);
                $table->datetime('paid_at')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'invoice_no'], 'uq_billing_invoice_no');
                $table->unique(['tenant_id', 'customer_id', 'period_key'], 'uq_billing_invoice_period');
                $table->index(['tenant_id', 'status'], 'idx_billing_invoice_status');
                $table->index(['tenant_id', 'due_date'], 'idx_billing_invoice_due');
                $table->foreign('customer_id', 'fk_billing_invoice_customer')
                    ->references('id')
                    ->on('noci_billing_customers')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
            });
        }

        if (!Schema::hasTable('noci_billing_invoice_items')) {
            Schema::create('noci_billing_invoice_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id');
                $table->unsignedBigInteger('invoice_id');
                $table->enum('item_type', ['PLAN', 'DISCOUNT', 'PENALTY', 'OTHER'])->default('PLAN');
                $table->string('description', 255);
                $table->decimal('quantity', 12, 2)->default(1);
                $table->decimal('unit_price', 18, 2)->default(0);
                $table->decimal('amount', 18, 2)->default(0);
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->index(['tenant_id', 'invoice_id'], 'idx_billing_item_invoice');
                $table->foreign('invoice_id', 'fk_billing_item_invoice')
                    ->references('id')
                    ->on('noci_billing_invoices')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
            });
        }

        if (!Schema::hasTable('noci_billing_payments')) {
            Schema::create('noci_billing_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id');
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('invoice_id')->nullable();
                $table->string('receipt_no', 80);
                $table->decimal('amount', 18, 2)->default(0);
                $table->string('payment_method', 50);
                $table->string('payment_channel', 100)->nullable();
                $table->string('reference_no', 100)->nullable();
                $table->datetime('paid_at');
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'receipt_no'], 'uq_billing_receipt_no');
                $table->index(['tenant_id', 'customer_id'], 'idx_billing_payment_customer');
                $table->index(['tenant_id', 'paid_at'], 'idx_billing_payment_date');
                $table->foreign('customer_id', 'fk_billing_payment_customer')
                    ->references('id')
                    ->on('noci_billing_customers')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('invoice_id', 'fk_billing_payment_invoice')
                    ->references('id')
                    ->on('noci_billing_invoices')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('noci_billing_payments');
        Schema::dropIfExists('noci_billing_invoice_items');
        Schema::dropIfExists('noci_billing_invoices');
    }
};
