<?php
// Informations de connexion à la base de données
$hote = "mysql-trec88000.alwaysdata.net";
$utilisateur = "trec88000";
$motdepasse = "Projet#88000";
$base = "trec88000_bdd";

// Connexion à la base de données MySQL
$conn = mysqli_connect($hote, $utilisateur, $motdepasse, $base);

// Vérification de la connexion
if ($conn === false) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'db connect failed',
        'detail' => mysqli_connect_error(),
        'code' => mysqli_connect_errno()
    ]);
    exit;
}

// Utilisation de l'encodage utf8mb4 pour bien gérer les accents et caractères spéciaux
mysqli_set_charset($conn, "utf8mb4");

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
?>