<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            if (!Schema::hasTable('noci_fo_cables')) {
                return;
            }

            if (!Schema::hasColumn('noci_fo_cables', 'length_m')) {
                Schema::table('noci_fo_cables', function (Blueprint $table) {
                    // Cable length in meters (optional/manual or computed).
                    $table->unsignedInteger('length_m')->nullable();
                });
            }
        } catch (\Throwable) {
            // Best-effort for legacy DBs.
        }
    }

    public function down(): void
    {
        try {
            if (!Schema::hasTable('noci_fo_cables')) {
                return;
            }

            if (Schema::hasColumn('noci_fo_cables', 'length_m')) {
                Schema::table('noci_fo_cables', function (Blueprint $table) {
                    $table->dropColumn('length_m');
                });
            }
        } catch (\Throwable) {
            // Best-effort for legacy DBs.
        }
    }
};

