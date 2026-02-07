<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('noci_user_locations', function (Blueprint $table) {
            $table->decimal('accuracy', 8, 2)->nullable()->after('longitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('noci_user_locations', function (Blueprint $table) {
            $table->dropColumn('accuracy');
        });
    }
};
