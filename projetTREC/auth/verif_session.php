<?php
session_start();

// Si la session n'existe pas ou que le 2FA n'est pas validé
if (!isset($_SESSION['id_utilisateur']) || $_SESSION['2fa_valide'] !== true) {
    header('Location: login.php');
    exit();
}

// Régénération de l'ID de session pour éviter la fixation de session
if (!isset($_SESSION['last_regeneration'])) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}
?>