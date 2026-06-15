<?php
header('Content-Type: application/json');

include '../config.php';

// Récupère l'id de l'épreuve sélectionnée dans l'URL
$idEpreuve = (int)($_GET['id_epreuve'] ?? 0);

if (!$idEpreuve) {
    echo json_encode([
        'success' => false,
        'message' => 'Épreuve manquante.'
    ]);
    exit;
}

// Récupère les cavaliers inscrits et confirmés à cette épreuve
$sql = "
    SELECT DISTINCT
        c.id_cavalier,
        c.nom_cavalier,
        c.prenom_cavalier
    FROM inscription_epreuve ie
    INNER JOIN inscription i ON i.id_inscription = ie.id_inscription
    INNER JOIN cavalier c ON c.id_cavalier = i.id_cavalier
    WHERE ie.id_epreuve = ?
    AND ie.statut_epreuve = 'confirmee'
    ORDER BY c.nom_cavalier ASC, c.prenom_cavalier ASC
";

// Prépare puis exécute la requête avec l'id de l'épreuve
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'i', $idEpreuve);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

// Tableau qui va contenir les cavaliers trouvés
$cavaliers = [];

// Ajout des cavaliers dans le tableau
while ($row = mysqli_fetch_assoc($result)) {
    $cavaliers[] = [
        'id_cavalier' => (int)$row['id_cavalier'],
        'nom_cavalier' => $row['nom_cavalier'],
        'prenom_cavalier' => $row['prenom_cavalier']
    ];
}

// Envoi de la réponse au format JSON
echo json_encode([
    'success' => true,
    'cavaliers' => $cavaliers
]);