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
        Schema::table('noci_team', function (Blueprint $table) {
            if (!Schema::hasColumn('noci_team', 'email')) {
                $table->string('email', 100)->nullable()->after('phone');
            }
            if (!Schema::hasColumn('noci_team', 'can_login')) {
                $table->boolean('can_login')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('noci_team', 'notes')) {
                $table->text('notes')->nullable()->after('can_login');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('noci_team', function (Blueprint $table) {
            $table->dropColumn(['email', 'can_login', 'notes']);
        });
    }
};
