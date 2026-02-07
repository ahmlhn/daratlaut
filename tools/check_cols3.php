<?php
$conn = new mysqli('153.92.15.11', 'u429122506_isolir', 'E7^+ag;$l~@y', 'u429122506_isolir');
if ($conn->connect_error) { echo 'FAIL: ' . $conn->connect_error; exit(1); }

$tables = ['noci_fin_payroll', 'noci_fin_tx', 'noci_fin_coa', 'noci_fin_branches', 'noci_fin_settings', 'noci_olts', 'noci_olt_onu', 'noci_customers', 'noci_msg_templates', 'noci_conf_wa', 'noci_conf_tg', 'noci_wa_gateways', 'noci_wa_tenant_gateways', 'noci_recap_groups'];
foreach ($tables as $t) {
    echo "\n=== $t ===\n";
    $r = $conn->query("SHOW COLUMNS FROM `$t`");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            echo $row['Field'] . "\n";
        }
    } else {
        echo "TABLE NOT FOUND\n";
    }
}
$conn->close();
