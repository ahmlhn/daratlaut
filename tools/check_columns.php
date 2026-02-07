<?php
// Quick tool to check table columns
$m = new mysqli('153.92.15.11', 'u429122506_isolir', "E7^+ag;\$l~@y", 'u429122506_isolir');
if ($m->connect_error) { die('Connect failed: ' . $m->connect_error); }
foreach (['noci_logs', 'noci_teknisi_expenses'] as $tbl) {
    echo "== $tbl ==\n";
    $r = $m->query("SHOW COLUMNS FROM $tbl");
    if (!$r) { echo "TABLE NOT FOUND\n"; continue; }
    while ($row = $r->fetch_assoc()) echo $row['Field'] . "\n";
}
$m->close();
