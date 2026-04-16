<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noci_olts', function (Blueprint $table) {
            if (!Schema::hasColumn('noci_olts', 'write_config_pending')) {
                $table->boolean('write_config_pending')->default(false)->after('service_port_id_default');
            }

            if (!Schema::hasColumn('noci_olts', 'write_config_pending_at')) {
                $table->dateTime('write_config_pending_at')->nullable()->after('write_config_pending');
            }
        });
    }

    public function down(): void
    {
        Schema::table('noci_olts', function (Blueprint $table) {
            if (Schema::hasColumn('noci_olts', 'write_config_pending_at')) {
                $table->dropColumn('write_config_pending_at');
            }

            if (Schema::hasColumn('noci_olts', 'write_config_pending')) {
                $table->dropColumn('write_config_pending');
            }
        });
    }
};
