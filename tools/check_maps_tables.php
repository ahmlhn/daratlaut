<?php
$conn = new mysqli('153.92.15.11', 'u429122506_isolir', 'E7^+ag;$l~@y', 'u429122506_isolir');
if ($conn->connect_error) { echo 'FAIL: ' . $conn->connect_error; exit(1); }

$tables = ['noci_user_locations', 'noci_user_location_latest', 'noci_user_location_logs'];
foreach ($tables as $t) {
    echo "\n=== $t ===\n";
    $r = $conn->query("SHOW COLUMNS FROM `$t`");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            echo $row['Field'] . " | " . $row['Type'] . "\n";
        }
    } else {
        echo "TABLE NOT FOUND\n";
    }
}
$conn->close();
