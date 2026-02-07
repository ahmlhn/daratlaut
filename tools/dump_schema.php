<?php

// Small helper script for local debugging (no app routes involved).
// Usage: php tools/dump_schema.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;

$tables = [
    'noci_installations',
    'noci_installation_changes',
    'noci_pops',
    'noci_users',
    'noci_conf_wa',
    'noci_wa_gateways',
    'noci_wa_tenant_gateways',
    'noci_wa_outbox',
    'noci_wa_logs',
];

foreach ($tables as $table) {
    echo "== {$table} ==" . PHP_EOL;
    if (!Schema::hasTable($table)) {
        echo "(missing)" . PHP_EOL . PHP_EOL;
        continue;
    }

    try {
        $cols = Schema::getColumnListing($table);
        foreach ($cols as $c) {
            echo "- {$c}" . PHP_EOL;
        }
    } catch (Throwable $e) {
        echo "(error) {$e->getMessage()}" . PHP_EOL;
    }

    echo PHP_EOL;
}

