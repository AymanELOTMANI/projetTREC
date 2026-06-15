<?php
session_start();

// Vérifie que l'utilisateur est authentifié ET que le 2FA est validé
// Sans ces deux conditions, toute page protégée redirige vers le login
if (!isset($_SESSION['id_utilisateur']) || $_SESSION['2fa_valide'] !== true) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit();
}