<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations - creates noci_fin_branches table.
     */
    public function up(): void
    {
        if (!Schema::hasTable('noci_fin_branches')) {
            Schema::create('noci_fin_branches', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(0)->index();
                $table->string('code', 20)->nullable();
                $table->string('name', 120)->nullable();
                $table->enum('mode', ['standalone', 'consolidate'])->default('standalone');
                $table->boolean('is_active')->default(true);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                
                $table->unique(['tenant_id', 'code']);
            });
            
            // Seed default branch
            DB::table('noci_fin_branches')->insert([
                'tenant_id' => 0,
                'code' => 'HQ',
                'name' => 'Kantor Pusat',
                'mode' => 'standalone',
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('noci_fin_branches');
    }
};
