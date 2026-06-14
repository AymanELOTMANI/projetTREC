<?php
require_once 'config.php';

// 1. Récupération du flux JSON envoyé par TTN
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

// 2. Extraction de l'UID (on cherche 'uid' car c'est ce que ton decodeUplink renvoie)
$uid = $data["uplink_message"]["decoded_payload"]["uid"] ?? null;

if ($uid) {
    try {
        // On utilise REPLACE pour mettre à jour l'ID 1 sans créer de nouvelles lignes
        // Note : Assure-toi que config.php définit bien $pdo (PDO) et non $conn (mysqli)
        $stmt = $pdo->prepare("REPLACE INTO dernier_scan (id, uid_hex) VALUES (1, ?)");
        $stmt->execute([$uid]);
        
        // Log de succès
        file_put_contents("access_log.txt", date("Y-m-d H:i:s") . " - SUCCESS : UID $uid enregistré\n", FILE_APPEND);
        echo "OK - UID $uid enregistré";
        
    } catch (Exception $e) {
        // Log d'erreur SQL
        file_put_contents("access_log.txt", date("Y-m-d H:i:s") . " - SQL ERROR : " . $e->getMessage() . "\n", FILE_APPEND);
        http_response_code(500);
        echo "Erreur SQL";
    }
} else {
    // Log d'erreur de parsing JSON
    file_put_contents("access_log.txt", date("Y-m-d H:i:s") . " - JSON ERROR : Donnée reçue mais UID absent. Vérifiez le décodeur.\n", FILE_APPEND);
    echo "UID non trouvé dans le payload";
}