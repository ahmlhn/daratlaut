<?php
$conn = new mysqli('153.92.15.11', 'u429122506_isolir', 'E7^+ag;$l~@y', 'u429122506_isolir');
if ($conn->connect_error) { echo 'FAIL: ' . $conn->connect_error; exit(1); }

// Check if billing tables exist
$tables = ['noci_billing_payments', 'noci_billing_invoices', 'noci_billing_plans', 'noci_billing_customers'];
foreach ($tables as $t) {
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    echo "$t: " . ($r->num_rows > 0 ? 'EXISTS' : 'MISSING') . "\n";
}

// Also check noci_user_locations and noci_recap_groups
$extras = ['noci_user_locations', 'noci_user_location_logs', 'noci_recap_groups', 'noci_teknisi_expenses', 'noci_teknisi_installation_fees'];
foreach ($extras as $t) {
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    echo "$t: " . ($r->num_rows > 0 ? 'EXISTS' : 'MISSING') . "\n";
}
$conn->close();
?>
