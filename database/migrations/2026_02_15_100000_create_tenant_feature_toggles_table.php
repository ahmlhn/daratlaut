<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_feature_toggles')) {
            Schema::create('tenant_feature_toggles', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id');
                $table->string('feature_key', 80);
                $table->boolean('is_enabled')->default(true);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'feature_key'], 'uq_tenant_feature_toggle');
                $table->index(['tenant_id', 'is_enabled'], 'idx_tenant_feature_enabled');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_feature_toggles');
    }
};
