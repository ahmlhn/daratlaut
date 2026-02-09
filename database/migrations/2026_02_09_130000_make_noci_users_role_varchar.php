<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('noci_users') || !Schema::hasColumn('noci_users', 'role')) {
            return;
        }

        $driver = null;
        try {
            $driver = DB::getDriverName();
        } catch (\Throwable) {
            return;
        }

        // This migration is MySQL-specific (native app runs on MySQL/MariaDB).
        if ($driver !== 'mysql') {
            return;
        }

        $col = DB::selectOne("SHOW COLUMNS FROM noci_users WHERE Field = 'role'");
        $type = strtolower((string) ($col->Type ?? ''));

        // If role is an enum, convert to varchar to support dynamic roles (Spatie).
        if (str_starts_with($type, 'enum(')) {
            DB::statement("ALTER TABLE noci_users MODIFY role VARCHAR(50) NOT NULL DEFAULT 'teknisi'");
        }
    }

    public function down(): void
    {
        // No-op: converting back to enum is not safely reversible without data loss.
    }
};

