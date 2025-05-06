<?php
$db_host = 'mysql';
$db_user = 'noteapp_user';
$db_pass = 'YourStrong@Passw0rd';
$db_name = 'noteapp';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>