<?php
header('Content-Type: application/json');

include __DIR__ . '/../config.php';

// Récupération de l'id de l'épreuve envoyé dans l'URL
$id_epreuve = (int)($_GET['id_epreuve'] ?? 0);

// Récupère les boîtiers qui ne sont pas encore affectés à cette épreuve
$stmt = $conn->prepare("
    SELECT b.id_boitier, b.nom_boitier
    FROM boitier b
    WHERE b.id_boitier NOT IN (
        SELECT a.id_boitier
        FROM affectation_boitier a
        WHERE a.id_epreuve = ?
    )
");

$stmt->bind_param("i", $id_epreuve);
$stmt->execute();

$result = $stmt->get_result();

// Tableau qui va contenir les boîtiers disponibles
$boitiers = [];

// Ajout des boîtiers dans le tableau
while ($row = $result->fetch_assoc()) {
    $boitiers[] = $row;
}

// Envoi des boîtiers au JavaScript au format JSON
echo json_encode($boitiers);