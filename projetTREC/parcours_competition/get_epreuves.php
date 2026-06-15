<?php
header('Content-Type: application/json');

include '../config.php';

// Récupère l'id de la compétition sélectionnée dans l'URL
$idCompetition = (int)($_GET['id_competition'] ?? 0);

// Récupère les épreuves liées à la compétition choisie
$sql = "
    SELECT id_epreuve, nom_epreuve, id_parcours
    FROM epreuve
    WHERE id_competition = ?
    ORDER BY nom_epreuve ASC
";

// Prépare puis exécute la requête avec l'id de la compétition
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'i', $idCompetition);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

// Tableau qui va contenir les épreuves trouvées
$epreuves = [];

// Ajout des épreuves dans le tableau
while ($row = mysqli_fetch_assoc($result)) {
    $epreuves[] = [
        'id_epreuve' => (int)$row['id_epreuve'],
        'nom_epreuve' => $row['nom_epreuve'],
        'id_parcours' => $row['id_parcours'] ? (int)$row['id_parcours'] : null
    ];
}

// Envoi de la réponse au format JSON
echo json_encode([
    'success' => true,
    'epreuves' => $epreuves
]);