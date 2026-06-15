<?php
header('Content-Type: application/json');

include '../config.php';

// Récupération des paramètres envoyés dans l'URL
$idEpreuve = (int)($_GET['id_epreuve'] ?? 0);
$idCavalier = (int)($_GET['id_cavalier'] ?? 0);

// Vérification des paramètres obligatoires
if ($idEpreuve === 0 || $idCavalier === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Paramètres manquants.'
    ]);
    exit;
}

// Recherche de la dernière session GPS du cavalier pour cette épreuve
$stmt = mysqli_prepare($conn, "
    SELECT id_sessionGPS
    FROM session_gps
    WHERE id_epreuve = ?
    AND id_cavalier = ?
    ORDER BY date_heure_transfert DESC
    LIMIT 1
");

mysqli_stmt_bind_param($stmt, 'ii', $idEpreuve, $idCavalier);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$session = mysqli_fetch_assoc($result);

// Arrêt si aucune session GPS n'existe
if (!$session) {
    echo json_encode([
        'success' => false,
        'message' => 'Aucune session GPS trouvée pour ce cavalier sur cette épreuve.'
    ]);
    exit;
}

$idSessionGPS = (int)$session['id_sessionGPS'];

// Récupération des points GPS de la session trouvée
$stmt = mysqli_prepare($conn, "
    SELECT latitude, longitude
    FROM pointGPS
    WHERE id_sessionGPS = ?
    ORDER BY id_pointGPS ASC
");

mysqli_stmt_bind_param($stmt, 'i', $idSessionGPS);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

// Tableau qui va contenir les points GPS
$points = [];

// Ajout des points GPS dans le tableau
while ($row = mysqli_fetch_assoc($result)) {
    $points[] = [
        'lat' => (float)$row['latitude'],
        'lng' => (float)$row['longitude']
    ];
}

// Envoi des points au JavaScript pour afficher le parcours réalisé sur la carte
echo json_encode([
    'success' => true,
    'points' => $points
]);