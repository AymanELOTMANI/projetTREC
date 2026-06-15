<?php
header('Content-Type: application/json');

include '../config.php';

date_default_timezone_set('Europe/Paris');

// Récupération des données envoyées par l'ESP32
$id_boitier = (int)($_POST['id_boitier'] ?? 0);
$nom_session = $_POST['nom_session'] ?? '';
$csv = $_POST['csv'] ?? '';

// Vérifie que toutes les données nécessaires sont présentes
if ($id_boitier === 0 || $nom_session === '' || $csv === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Paramètres manquants'
    ]);
    exit;
}

try {
    // Démarre une transaction pour éviter d'insérer des données incomplètes
    mysqli_begin_transaction($conn);

    // Recherche le cavalier et l'épreuve associés au boîtier GPS
    $stmt = mysqli_prepare($conn, "
        SELECT id_cavalier, id_epreuve
        FROM affectation_boitier
        WHERE id_boitier = ?
        ORDER BY date_affectation DESC
        LIMIT 1
    ");

    mysqli_stmt_bind_param($stmt, 'i', $id_boitier);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $affectation = mysqli_fetch_assoc($result);

    // Si le boîtier n'est pas affecté, on arrête le traitement
    if (!$affectation) {
        throw new Exception("Aucune affectation trouvée pour ce boîtier.");
    }

    $id_cavalier = (int)$affectation['id_cavalier'];
    $id_epreuve = (int)$affectation['id_epreuve'];
    $now = date('Y-m-d H:i:s');

    // Crée une nouvelle session GPS pour cet enregistrement
    $stmt = mysqli_prepare($conn, "
        INSERT INTO session_gps
        (id_cavalier, id_boitier, id_epreuve, date_heure_transfert, nom_session)
        VALUES (?, ?, ?, ?, ?)
    ");

    mysqli_stmt_bind_param(
        $stmt,
        'iiiss',
        $id_cavalier,
        $id_boitier,
        $id_epreuve,
        $now,
        $nom_session
    );

    mysqli_stmt_execute($stmt);

    $id_sessionGPS = mysqli_insert_id($conn);

    // Prépare l'ajout des points GPS dans la base
    $stmtPoint = mysqli_prepare($conn, "
        INSERT INTO pointGPS
        (id_sessionGPS, timestamp_gps, latitude, longitude)
        VALUES (?, ?, ?, ?)
    ");

    // Sépare le fichier CSV ligne par ligne
    $lines = preg_split("/\r\n|\n|\r/", trim($csv));

    $inserted = 0;
    $ignored = 0;

    foreach ($lines as $i => $line) {
        $line = trim($line);

        // Ignore les lignes vides
        if ($line === '') {
            $ignored++;
            continue;
        }

        // Ignore la première ligne si c'est l'en-tête du CSV
        if ($i === 0 && stripos($line, 'ms_since_boot') !== false) {
            continue;
        }

        $data = explode(',', $line);

        // Vérifie que la ligne contient bien les 4 valeurs attendues
        if (count($data) < 4) {
            $ignored++;
            continue;
        }

        $utc = trim($data[1]);
        $lat = trim($data[2]);
        $lon = trim($data[3]);

        // Ignore la ligne si une donnée GPS est manquante
        if ($utc === '' || $lat === '' || $lon === '') {
            $ignored++;
            continue;
        }

        $lat = (float)$lat;
        $lon = (float)$lon;

        // Vérifie que les coordonnées GPS sont valides
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            $ignored++;
            continue;
        }

        // Convertit l'heure UTC reçue en heure française
        $dateUtc = DateTime::createFromFormat(
            'Y-m-d H:i:s',
            gmdate('Y-m-d') . ' ' . $utc,
            new DateTimeZone('UTC')
        );

        if (!$dateUtc) {
            $ignored++;
            continue;
        }

        $dateUtc->setTimezone(new DateTimeZone('Europe/Paris'));
        $timestamp_gps = $dateUtc->format('Y-m-d H:i:s');

        // Ajoute le point GPS à la session actuelle
        mysqli_stmt_bind_param(
            $stmtPoint,
            'isdd',
            $id_sessionGPS,
            $timestamp_gps,
            $lat,
            $lon
        );

        mysqli_stmt_execute($stmtPoint);

        $inserted++;
    }

    // Valide toutes les insertions
    mysqli_commit($conn);

    echo json_encode([
        'ok' => true,
        'id_sessionGPS' => $id_sessionGPS,
        'id_boitier' => $id_boitier,
        'id_cavalier' => $id_cavalier,
        'id_epreuve' => $id_epreuve,
        'inserted' => $inserted,
        'ignored' => $ignored,
        'lines_total' => count($lines)
    ]);

} catch (Exception $e) {
    // Annule les insertions si une erreur se produit
    mysqli_rollback($conn);

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}