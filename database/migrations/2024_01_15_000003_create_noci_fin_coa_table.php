<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations - creates noci_fin_coa table (Chart of Accounts).
     */
    public function up(): void
    {
        if (!Schema::hasTable('noci_fin_coa')) {
            Schema::create('noci_fin_coa', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(0)->index();
                $table->string('code', 20);
                $table->string('name', 120);
                $table->enum('category', ['asset', 'liability', 'equity', 'revenue', 'expense', 'other']);
                $table->enum('type', ['header', 'detail'])->default('detail');
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->enum('normal_balance', ['debit', 'credit'])->default('debit');
                $table->boolean('is_active')->default(true);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                
                $table->unique(['tenant_id', 'code']);
            });
            
            // Seed default COA (SAK EMKM)
            $coa = [
                ['tenant_id' => 0, 'code' => '1000', 'name' => 'ASET', 'category' => 'asset', 'type' => 'header', 'parent_id' => null, 'normal_balance' => 'debit'],
                ['tenant_id' => 0, 'code' => '1100', 'name' => 'Kas', 'category' => 'asset', 'type' => 'detail', 'parent_id' => 1, 'normal_balance' => 'debit'],
                ['tenant_id' => 0, 'code' => '1110', 'name' => 'Bank', 'category' => 'asset', 'type' => 'detail', 'parent_id' => 1, 'normal_balance' => 'debit'],
                ['tenant_id' => 0, 'code' => '1120', 'name' => 'Piutang Usaha', 'category' => 'asset', 'type' => 'detail', 'parent_id' => 1, 'normal_balance' => 'debit'],
                ['tenant_id' => 0, 'code' => '1200', 'name' => 'Persediaan', 'category' => 'asset', 'type' => 'detail', 'parent_id' => 1, 'normal_balance' => 'debit'],
                
                ['tenant_id' => 0, 'code' => '2000', 'name' => 'LIABILITAS', 'category' => 'liability', 'type' => 'header', 'parent_id' => null, 'normal_balance' => 'credit'],
                ['tenant_id' => 0, 'code' => '2100', 'name' => 'Hutang Usaha', 'category' => 'liability', 'type' => 'detail', 'parent_id' => 6, 'normal_balance' => 'credit'],
                ['tenant_id' => 0, 'code' => '2200', 'name' => 'Hutang Gaji', 'category' => 'liability', 'type' => 'detail', 'parent_id' => 6, 'normal_balance' => 'credit'],
                
                ['tenant_id' => 0, 'code' => '3000', 'name' => 'EKUITAS', 'category' => 'equity', 'type' => 'header', 'parent_id' => null, 'normal_balance' => 'credit'],
                ['tenant_id' => 0, 'code' => '3100', 'name' => 'Modal', 'category' => 'equity', 'type' => 'detail', 'parent_id' => 9, 'normal_balance' => 'credit'],
                ['tenant_id' => 0, 'code' => '3200', 'name' => 'Laba Ditahan', 'category' => 'equity', 'type' => 'detail', 'parent_id' => 9, 'normal_balance' => 'credit'],
                
                ['tenant_id' => 0, 'code' => '4000', 'name' => 'PENDAPATAN', 'category' => 'revenue', 'type' => 'header', 'parent_id' => null, 'normal_balance' => 'credit'],
                ['tenant_id' => 0, 'code' => '4100', 'name' => 'Pendapatan Jasa', 'category' => 'revenue', 'type' => 'detail', 'parent_id' => 12, 'normal_balance' => 'credit'],
                
                ['tenant_id' => 0, 'code' => '5000', 'name' => 'HPP', 'category' => 'expense', 'type' => 'header', 'parent_id' => null, 'normal_balance' => 'debit'],
                ['tenant_id' => 0, 'code' => '5100', 'name' => 'Harga Pokok Penjualan', 'category' => 'expense', 'type' => 'detail', 'parent_id' => 14, 'normal_balance' => 'debit'],
                
                ['tenant_id' => 0, 'code' => '6000', 'name' => 'BEBAN', 'category' => 'expense', 'type' => 'header', 'parent_id' => null, 'normal_balance' => 'debit'],
                ['tenant_id' => 0, 'code' => '6100', 'name' => 'Beban Gaji', 'category' => 'expense', 'type' => 'detail', 'parent_id' => 16, 'normal_balance' => 'debit'],
                ['tenant_id' => 0, 'code' => '6200', 'name' => 'Beban Operasional', 'category' => 'expense', 'type' => 'detail', 'parent_id' => 16, 'normal_balance' => 'debit'],
                ['tenant_id' => 0, 'code' => '6300', 'name' => 'Beban Listrik/Internet', 'category' => 'expense', 'type' => 'detail', 'parent_id' => 16, 'normal_balance' => 'debit'],
                
                ['tenant_id' => 0, 'code' => '7000', 'name' => 'PEND/BEBAN LAIN', 'category' => 'other', 'type' => 'header', 'parent_id' => null, 'normal_balance' => 'credit'],
                ['tenant_id' => 0, 'code' => '7100', 'name' => 'Pendapatan Lain', 'category' => 'other', 'type' => 'detail', 'parent_id' => 20, 'normal_balance' => 'credit'],
                ['tenant_id' => 0, 'code' => '7200', 'name' => 'Beban Lain', 'category' => 'other', 'type' => 'detail', 'parent_id' => 20, 'normal_balance' => 'debit'],
            ];
            
            foreach ($coa as $row) {
                DB::table('noci_fin_coa')->insert($row);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('noci_fin_coa');
    }
};
