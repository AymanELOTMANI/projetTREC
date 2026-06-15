<?php
header('Content-Type: application/json');

include __DIR__ . '/../config.php';

// Récupération du nom du boîtier envoyé par le formulaire
$nom_boitier = trim($_POST['nom_boitier'] ?? '');

// Préparation de l'ajout du boîtier GPS dans la base de données
$stmt = $conn->prepare("
    INSERT INTO boitier (nom_boitier)
    VALUES (?)
");

$stmt->bind_param("s", $nom_boitier);

// Exécute la requête et renvoie l'id du boîtier ajouté
if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Boîtier GPS ajouté avec succès.',
        'id_boitier' => $conn->insert_id
    ]);
    exit;
}

// Message d'erreur si l'insertion échoue
echo json_encode([
    'success' => false,
    'message' => 'Erreur lors de l’ajout du boîtier GPS.'
]);