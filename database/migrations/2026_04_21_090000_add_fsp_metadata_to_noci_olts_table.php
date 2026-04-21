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
            if (!Schema::hasColumn('noci_olts', 'fsp_metadata')) {
                $table->text('fsp_metadata')->nullable()->after('fsp_cache');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('noci_olts')) {
            return;
        }

        Schema::table('noci_olts', function (Blueprint $table) {
            if (Schema::hasColumn('noci_olts', 'fsp_metadata')) {
                $table->dropColumn('fsp_metadata');
            }
        });
    }
};
