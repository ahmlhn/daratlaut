<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('noci_conf_maps')) {
            Schema::create('noci_conf_maps', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->string('google_maps_api_key', 255)->nullable();
                $table->timestamps();

                $table->unique(['tenant_id'], 'uniq_noci_conf_maps_tenant');
                $table->index('tenant_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('noci_conf_maps');
    }
};

