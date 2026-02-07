<?php 
$pdo = new PDO('mysql:host=localhost;dbname=u429122506_isolir', 'root', ''); 
$stmt = $pdo->query('SHOW TABLES'); 
while($row = $stmt->fetch()) { echo $row[0] . PHP_EOL; }
