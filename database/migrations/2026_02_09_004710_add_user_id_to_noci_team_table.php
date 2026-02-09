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
        if (!Schema::hasTable('noci_team')) {
            return;
        }

        Schema::table('noci_team', function (Blueprint $table) {
            if (!Schema::hasColumn('noci_team', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('noci_team')) {
            return;
        }

        Schema::table('noci_team', function (Blueprint $table) {
            if (Schema::hasColumn('noci_team', 'user_id')) {
                $table->dropColumn('user_id');
            }
        });
    }
};
