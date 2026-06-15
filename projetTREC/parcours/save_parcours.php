<?php
session_start();

header('Content-Type: application/json');
include '../config.php';

// Récupération des données JSON envoyées par le JavaScript
$data = json_decode(file_get_contents('php://input'), true);

// Vérification des données obligatoires
if (
    empty($data['nom_parcours']) ||
    empty($data['points'])
) {
    echo json_encode([
        'success' => false,
        'message' => 'Données manquantes.'
    ]);
    exit;
}

// Récupération des informations du parcours
$nomParcours = trim($data['nom_parcours']);
$points = $data['points'];
$distanceKm = (float)($data['distance_km'] ?? 0);

try {
    // Démarre une transaction pour enregistrer le parcours et ses points ensemble
    mysqli_begin_transaction($conn);

    // Enregistrement du parcours officiel
    $stmt = mysqli_prepare($conn, "
        INSERT INTO parcours_theorique (nom_parcours, distance_km)
        VALUES (?, ?)
    ");

    mysqli_stmt_bind_param($stmt, 'sd', $nomParcours, $distanceKm);
    mysqli_stmt_execute($stmt);

    // Récupération de l'id du parcours créé
    $idParcours = mysqli_insert_id($conn);

    // Préparation de l'ajout des points du parcours
    $stmtPoint = mysqli_prepare($conn, "
        INSERT INTO point_parcours (id_parcours, ordre_point, latitude, longitude)
        VALUES (?, ?, ?, ?)
    ");

    // Enregistrement de chaque point du parcours dans l'ordre
    foreach ($points as $index => $point) {
        $ordrePoint = $index + 1;
        $latitude = (float)$point['lat'];
        $longitude = (float)$point['lng'];

        mysqli_stmt_bind_param(
            $stmtPoint,
            'iidd',
            $idParcours,
            $ordrePoint,
            $latitude,
            $longitude
        );

        mysqli_stmt_execute($stmtPoint);
    }

    // Valide l'enregistrement du parcours et de ses points
    mysqli_commit($conn);

    echo json_encode([
        'success' => true,
        'id_parcours' => $idParcours,
        'message' => 'Parcours enregistré.'
    ]);

} catch (Exception $e) {
    // Annule l'enregistrement si une erreur arrive
    mysqli_rollback($conn);

    echo json_encode([
        'success' => false,
        'message' => 'Erreur pendant l’enregistrement du parcours.'
    ]);
}