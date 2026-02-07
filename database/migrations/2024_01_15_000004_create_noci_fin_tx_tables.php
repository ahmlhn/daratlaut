<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - creates noci_fin_tx and noci_fin_tx_lines tables.
     */
    public function up(): void
    {
        // Main transaction table
        if (!Schema::hasTable('noci_fin_tx')) {
            Schema::create('noci_fin_tx', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(0)->index();
                $table->string('tx_no', 30)->nullable();
                $table->date('tx_date');
                $table->text('description')->nullable();
                $table->string('ref_no', 80)->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('method', 20)->nullable();
                $table->enum('status', ['draft', 'pending', 'approved', 'posted', 'rejected'])->default('pending');
                $table->decimal('total_debit', 16, 2)->default(0);
                $table->decimal('total_credit', 16, 2)->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('created_by_name', 80)->nullable();
                $table->string('created_role', 40)->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->string('approved_by_name', 80)->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->string('source', 20)->default('manual');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                
                $table->index(['tenant_id', 'tx_date']);
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'branch_id']);
            });
        }
        
        // Transaction line items
        if (!Schema::hasTable('noci_fin_tx_lines')) {
            Schema::create('noci_fin_tx_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(0)->index();
                $table->unsignedBigInteger('tx_id');
                $table->unsignedBigInteger('coa_id')->nullable();
                $table->string('coa_code', 20)->nullable();
                $table->string('line_desc', 255)->nullable();
                $table->decimal('debit', 16, 2)->default(0);
                $table->decimal('credit', 16, 2)->default(0);
                $table->string('party_type', 30)->nullable();
                $table->string('party_name', 80)->nullable();
                $table->string('party_ref', 80)->nullable();
                $table->timestamp('created_at')->useCurrent();
                
                $table->index(['tenant_id', 'tx_id']);
                $table->index(['tenant_id', 'coa_id']);
                
                $table->foreign('tx_id')->references('id')->on('noci_fin_tx')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('noci_fin_tx_lines');
        Schema::dropIfExists('noci_fin_tx');
    }
};
