<?php
header('Content-Type: application/json');

include __DIR__ . '/../config.php';

// Récupération de l'id de l'épreuve envoyé dans l'URL
$id_epreuve = (int)($_GET['id_epreuve'] ?? 0);

// Récupère les cavaliers de l'épreuve qui ne sont pas encore affectés à un boîtier
$stmt = $conn->prepare("
    SELECT c.id_cavalier, c.nom_cavalier, c.prenom_cavalier
    FROM cavalier c
    JOIN competition comp ON c.id_competition = comp.id_competition
    JOIN epreuve e ON comp.id_competition = e.id_competition
    WHERE e.id_epreuve = ?
    AND c.id_cavalier NOT IN (
        SELECT a.id_cavalier
        FROM affectation_boitier a
        WHERE a.id_epreuve = ?
    )
");

$stmt->bind_param("ii", $id_epreuve, $id_epreuve);
$stmt->execute();

$result = $stmt->get_result();

// Tableau qui va contenir les cavaliers disponibles
$cavaliers = [];

// Ajout des cavaliers dans le tableau
while ($row = $result->fetch_assoc()) {
    $cavaliers[] = $row;
}

// Envoi des cavaliers au JavaScript au format JSON
echo json_encode($cavaliers);