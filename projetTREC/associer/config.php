<?php
// config.php - Paramètres de connexion
define('DB_HOST', 'mysql-trec88000.alwaysdata.net');
define('DB_NAME', 'trec88000_bdd');
define('DB_USER', 'trec88000');
define('DB_PASS', 'Projet#88000');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    die("Erreur de connexion : " . $e->getMessage());
}