<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('noci_public_redirect_links')) {
            return;
        }

        $duplicates = DB::table('noci_public_redirect_links')
            ->select('code')
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('code')
            ->all();

        if (!empty($duplicates)) {
            $sample = implode(', ', array_slice($duplicates, 0, 5));
            throw new RuntimeException(
                'Tidak bisa mengaktifkan unique code global pada noci_public_redirect_links. ' .
                'Masih ada code duplikat lintas tenant: ' . $sample
            );
        }

        if ($this->hasIndex('noci_public_redirect_links', 'noci_pub_redirect_links_tenant_code_uq')) {
            Schema::table('noci_public_redirect_links', function (Blueprint $table) {
                $table->dropUnique('noci_pub_redirect_links_tenant_code_uq');
            });
        }

        if (!$this->hasIndex('noci_public_redirect_links', 'noci_pub_redirect_links_code_uq')) {
            Schema::table('noci_public_redirect_links', function (Blueprint $table) {
                $table->unique('code', 'noci_pub_redirect_links_code_uq');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('noci_public_redirect_links')) {
            return;
        }

        if ($this->hasIndex('noci_public_redirect_links', 'noci_pub_redirect_links_code_uq')) {
            Schema::table('noci_public_redirect_links', function (Blueprint $table) {
                $table->dropUnique('noci_pub_redirect_links_code_uq');
            });
        }

        if (!$this->hasIndex('noci_public_redirect_links', 'noci_pub_redirect_links_tenant_code_uq')) {
            Schema::table('noci_public_redirect_links', function (Blueprint $table) {
                $table->unique(['tenant_id', 'code'], 'noci_pub_redirect_links_tenant_code_uq');
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
            return !empty($rows);
        } catch (\Throwable) {
            return false;
        }
    }
};
