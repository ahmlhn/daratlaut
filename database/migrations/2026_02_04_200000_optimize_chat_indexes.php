<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $db = DB::getDatabaseName();
            $row = DB::selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$db, $table, $indexName]
            );
            return (bool) $row;
        } catch (\Throwable) {
            return false;
        }
    }

    private function addIndexIfMissing(string $table, string $indexName, array $columns): void
    {
        if (!Schema::hasTable($table)) return;
        foreach ($columns as $col) {
            if (!Schema::hasColumn($table, $col)) return;
        }
        if ($this->indexExists($table, $indexName)) return;

        Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
            $t->index($columns, $indexName);
        });
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table)) return;
        if (!$this->indexExists($table, $indexName)) return;

        Schema::table($table, function (Blueprint $t) use ($indexName) {
            $t->dropIndex($indexName);
        });
    }

    public function up(): void
    {
        // Improve chat polling performance on shared hosting DBs (lower latency + fewer full scans).
        $this->addIndexIfMissing('noci_chat', 'idx_chat_tenant_visit_id_id', ['tenant_id', 'visit_id', 'id']);
        $this->addIndexIfMissing('noci_chat', 'idx_chat_tenant_visit_sender_read', ['tenant_id', 'visit_id', 'sender', 'is_read']);
        $this->addIndexIfMissing('noci_chat', 'idx_chat_tenant_updated_at', ['tenant_id', 'updated_at']);
        $this->addIndexIfMissing('noci_chat', 'idx_chat_tenant_visit_created_at', ['tenant_id', 'visit_id', 'created_at']);

        $this->addIndexIfMissing('noci_customers', 'idx_customers_tenant_last_seen', ['tenant_id', 'last_seen']);
        $this->addIndexIfMissing('noci_customers', 'idx_customers_tenant_visit_id', ['tenant_id', 'visit_id']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('noci_customers', 'idx_customers_tenant_visit_id');
        $this->dropIndexIfExists('noci_customers', 'idx_customers_tenant_last_seen');

        $this->dropIndexIfExists('noci_chat', 'idx_chat_tenant_visit_created_at');
        $this->dropIndexIfExists('noci_chat', 'idx_chat_tenant_updated_at');
        $this->dropIndexIfExists('noci_chat', 'idx_chat_tenant_visit_sender_read');
        $this->dropIndexIfExists('noci_chat', 'idx_chat_tenant_visit_id_id');
    }
};

