<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('noci_olts')) {
            return;
        }

        Schema::table('noci_olts', function (Blueprint $table) {
            if (!Schema::hasColumn('noci_olts', 'teknisi_onu_rx_max_dbm')) {
                $table->decimal('teknisi_onu_rx_max_dbm', 8, 2)->default(-11.00);
            }

            if (!Schema::hasColumn('noci_olts', 'teknisi_onu_rx_min_dbm')) {
                $table->decimal('teknisi_onu_rx_min_dbm', 8, 2)->default(-24.99);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('noci_olts')) {
            return;
        }

        Schema::table('noci_olts', function (Blueprint $table) {
            if (Schema::hasColumn('noci_olts', 'teknisi_onu_rx_max_dbm')) {
                $table->dropColumn('teknisi_onu_rx_max_dbm');
            }

            if (Schema::hasColumn('noci_olts', 'teknisi_onu_rx_min_dbm')) {
                $table->dropColumn('teknisi_onu_rx_min_dbm');
            }
        });
    }
};
