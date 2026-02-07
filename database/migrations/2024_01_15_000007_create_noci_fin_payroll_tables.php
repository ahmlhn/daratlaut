<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - creates noci_fin_payroll and noci_fin_payroll_items tables.
     */
    public function up(): void
    {
        // Payroll runs
        if (!Schema::hasTable('noci_fin_payroll')) {
            Schema::create('noci_fin_payroll', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(0)->index();
                $table->string('period', 20); // YYYY-MM
                $table->string('run_name', 120)->nullable();
                $table->enum('status', ['draft', 'pending', 'approved', 'posted', 'rejected'])->default('draft');
                $table->decimal('total_gross', 16, 2)->default(0);
                $table->decimal('total_deductions', 16, 2)->default(0);
                $table->decimal('total_net', 16, 2)->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('created_by_name', 80)->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->string('approved_by_name', 80)->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('fin_tx_id')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                
                $table->index(['tenant_id', 'period']);
                $table->index(['tenant_id', 'status']);
            });
        }
        
        // Payroll line items
        if (!Schema::hasTable('noci_fin_payroll_items')) {
            Schema::create('noci_fin_payroll_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(0)->index();
                $table->unsignedBigInteger('payroll_id');
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->string('employee_name', 120);
                $table->string('employee_role', 50)->nullable();
                $table->decimal('base_salary', 16, 2)->default(0);
                $table->decimal('overtime', 16, 2)->default(0);
                $table->decimal('bonus', 16, 2)->default(0);
                $table->decimal('technician_fee', 16, 2)->default(0);
                $table->decimal('sales_fee', 16, 2)->default(0);
                $table->decimal('deductions', 16, 2)->default(0);
                $table->decimal('net_pay', 16, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->useCurrent();
                
                $table->index(['tenant_id', 'payroll_id']);
                
                $table->foreign('payroll_id')->references('id')->on('noci_fin_payroll')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('noci_fin_payroll_items');
        Schema::dropIfExists('noci_fin_payroll');
    }
};
