<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensure `noci_team` has the columns required by the Laravel Team module.
     *
     * This repo historically stored "users" in `noci_users` and a minimal technician list in `noci_team`.
     * The Laravel Team module expects a richer `noci_team` schema (tenant_id, role, pop_id, etc).
     *
     * This migration is intentionally defensive/idempotent so it can run on legacy databases.
     */
    public function up(): void
    {
        if (!Schema::hasTable('noci_team')) {
            return;
        }

        Schema::table('noci_team', function (Blueprint $table) {
            if (!Schema::hasColumn('noci_team', 'tenant_id')) {
                $table->unsignedInteger('tenant_id')->default(1)->index();
            }

            if (!Schema::hasColumn('noci_team', 'role')) {
                $table->string('role', 50)->default('teknisi')->index();
            }

            if (!Schema::hasColumn('noci_team', 'pop_id')) {
                $table->unsignedBigInteger('pop_id')->nullable()->index();
            }

            if (!Schema::hasColumn('noci_team', 'email')) {
                $table->string('email', 100)->nullable();
            }

            if (!Schema::hasColumn('noci_team', 'can_login')) {
                $table->boolean('can_login')->default(false)->index();
            }

            if (!Schema::hasColumn('noci_team', 'notes')) {
                $table->text('notes')->nullable();
            }

            if (!Schema::hasColumn('noci_team', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->index();
            }

            if (!Schema::hasColumn('noci_team', 'is_active')) {
                $table->boolean('is_active')->default(true)->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * No-op on purpose: this migration is meant to make legacy DBs compatible, and rolling it back
     * could drop columns that might already be relied upon by data/app code.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }
};

