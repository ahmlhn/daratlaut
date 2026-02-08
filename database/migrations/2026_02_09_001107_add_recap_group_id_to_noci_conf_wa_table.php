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
        Schema::table('noci_conf_wa', function (Blueprint $table) {
            $table->string('recap_group_id', 100)->nullable()->after('group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('noci_conf_wa', function (Blueprint $table) {
            $table->dropColumn('recap_group_id');
        });
    }
};
