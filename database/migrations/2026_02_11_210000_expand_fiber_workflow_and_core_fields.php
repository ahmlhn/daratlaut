<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            if (Schema::hasTable('noci_fo_cables')) {
                Schema::table('noci_fo_cables', function (Blueprint $table) {
                    if (!Schema::hasColumn('noci_fo_cables', 'reserved_cores')) {
                        // JSON array of reserved core numbers, e.g. [3,4,5]
                        $table->longText('reserved_cores')->nullable();
                    }
                });
            }
        } catch (\Throwable) {
            // Best-effort for legacy DBs.
        }

        try {
            if (Schema::hasTable('noci_fo_breaks')) {
                Schema::table('noci_fo_breaks', function (Blueprint $table) {
                    if (!Schema::hasColumn('noci_fo_breaks', 'core_no')) {
                        $table->unsignedInteger('core_no')->nullable()->index();
                    }
                    if (!Schema::hasColumn('noci_fo_breaks', 'technician_name')) {
                        $table->string('technician_name', 120)->nullable();
                    }
                    if (!Schema::hasColumn('noci_fo_breaks', 'repair_started_at')) {
                        $table->dateTime('repair_started_at')->nullable()->index();
                    }
                    if (!Schema::hasColumn('noci_fo_breaks', 'verified_at')) {
                        $table->dateTime('verified_at')->nullable()->index();
                    }
                    if (!Schema::hasColumn('noci_fo_breaks', 'verified_by_name')) {
                        $table->string('verified_by_name', 120)->nullable();
                    }
                    if (!Schema::hasColumn('noci_fo_breaks', 'repair_photos')) {
                        // JSON array of image URLs/paths
                        $table->longText('repair_photos')->nullable();
                    }
                    if (!Schema::hasColumn('noci_fo_breaks', 'repair_materials')) {
                        // JSON array of material names
                        $table->longText('repair_materials')->nullable();
                    }
                    if (!Schema::hasColumn('noci_fo_breaks', 'closure_point_id')) {
                        $table->unsignedBigInteger('closure_point_id')->nullable()->index();
                    }
                });
            }
        } catch (\Throwable) {
            // Best-effort for legacy DBs.
        }
    }

    public function down(): void
    {
        try {
            if (Schema::hasTable('noci_fo_cables') && Schema::hasColumn('noci_fo_cables', 'reserved_cores')) {
                Schema::table('noci_fo_cables', function (Blueprint $table) {
                    $table->dropColumn('reserved_cores');
                });
            }
        } catch (\Throwable) {
            // Best-effort for legacy DBs.
        }

        try {
            if (!Schema::hasTable('noci_fo_breaks')) return;

            Schema::table('noci_fo_breaks', function (Blueprint $table) {
                $toDrop = [
                    'core_no',
                    'technician_name',
                    'repair_started_at',
                    'verified_at',
                    'verified_by_name',
                    'repair_photos',
                    'repair_materials',
                    'closure_point_id',
                ];

                foreach ($toDrop as $col) {
                    if (Schema::hasColumn('noci_fo_breaks', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        } catch (\Throwable) {
            // Best-effort for legacy DBs.
        }
    }
};

