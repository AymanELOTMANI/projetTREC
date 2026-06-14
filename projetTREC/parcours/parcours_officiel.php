<?php
header('Content-Type: application/json');

include '../config.php';

// Récupération de l'id du parcours envoyé dans l'URL
$idParcours = (int)($_GET['id_parcours'] ?? 0);

// Vérification du paramètre obligatoire
if ($idParcours === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Paramètre manquant.'
    ]);
    exit;
}

// Récupération des points du parcours officiel
$stmt = mysqli_prepare($conn, "
    SELECT latitude, longitude
    FROM point_parcours
    WHERE id_parcours = ?
    ORDER BY ordre_point ASC
");

mysqli_stmt_bind_param($stmt, 'i', $idParcours);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

// Tableau qui va contenir les points du parcours
$points = [];

// Ajout des points dans le tableau
while ($row = mysqli_fetch_assoc($result)) {
    $points[] = [
        'lat' => (float)$row['latitude'],
        'lng' => (float)$row['longitude']
    ];
}

// Vérifie qu'il y a assez de points pour tracer une ligne sur la carte
if (count($points) < 2) {
    echo json_encode([
        'success' => false,
        'message' => 'Pas assez de points pour afficher le parcours officiel.'
    ]);
    exit;
}

// Envoi des points au JavaScript pour afficher le parcours officiel
echo json_encode([
    'success' => true,
    'points' => $points
]);