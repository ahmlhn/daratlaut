<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations - creates noci_fin_settings table.
     */
    public function up(): void
    {
        if (!Schema::hasTable('noci_fin_settings')) {
            Schema::create('noci_fin_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(0)->index();
                $table->string('setting_key', 80);
                $table->text('setting_value')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                
                $table->unique(['tenant_id', 'setting_key']);
            });
            
            // Seed default settings
            $defaults = [
                ['tenant_id' => 0, 'setting_key' => 'fiscal_year_start', 'setting_value' => '01-01'],
                ['tenant_id' => 0, 'setting_key' => 'currency_code', 'setting_value' => 'IDR'],
                ['tenant_id' => 0, 'setting_key' => 'approval_required', 'setting_value' => '1'],
                ['tenant_id' => 0, 'setting_key' => 'attachment_required', 'setting_value' => '0'],
            ];
            
            foreach ($defaults as $row) {
                DB::table('noci_fin_settings')->insert($row);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('noci_fin_settings');
    }
};
