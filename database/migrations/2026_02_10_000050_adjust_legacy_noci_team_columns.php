<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy DBs often have `noci_team.name` (varchar 50) and `noci_team.phone` (varchar 20 NOT NULL).
     * The Laravel Team module expects longer fields and allows missing phone numbers.
     *
     * We use raw SQL to avoid requiring doctrine/dbal for `change()`.
     */
    public function up(): void
    {
        if (!Schema::hasTable('noci_team')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver !== 'mysql') {
            // MariaDB uses the "mysql" driver.
            return;
        }

        // Best-effort: if the column types differ in a given install, ignore errors.
        if (Schema::hasColumn('noci_team', 'name')) {
            try {
                DB::statement("ALTER TABLE `noci_team` MODIFY `name` VARCHAR(100) NOT NULL");
            } catch (\Throwable) {
                // ignore
            }
        }

        if (Schema::hasColumn('noci_team', 'phone')) {
            try {
                DB::statement("ALTER TABLE `noci_team` MODIFY `phone` VARCHAR(50) NULL");
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    public function down(): void
    {
        // Intentionally left blank (non-destructive migration).
    }
};

