<?php
// Démarre la session pour pouvoir utiliser les variables de session
session_start();

// Connexion à la base de données
require_once '../config.php';

// Charge les bibliothèques installées avec Composer
require_once __DIR__ . '/../vendor/autoload.php';

// Import des classes nécessaires pour l'authentification à deux facteurs
use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;

// Vérifie que les variables temporaires existent dans la session
// Si elles n'existent pas, on redirige vers la page de connexion
if (!isset($_SESSION['temp_user_id']) || !isset($_SESSION['temp_secret'])) {
    header('Location: login.php');
    exit();
}

// Récupère le code TOTP envoyé par l'utilisateur
$code = $_POST['code_totp'];

// Récupère la clé secrète stockée temporairement en session
$secret = $_SESSION['temp_secret'];

// Création de l'objet pour gérer la double authentification
$tfa = new TwoFactorAuth(new EndroidQrCodeProvider(), 'TREC');

// Vérifie si le code entré par l'utilisateur est valide
if ($tfa->verifyCode($secret, $code)) {

    // Code correct → on enregistre le secret en base et on active le 2FA
    $stmt = mysqli_prepare($conn, "UPDATE utilisateur SET secret_totp = ?, totp_active = 1 WHERE id_utilisateur = ?");
    mysqli_stmt_bind_param($stmt, "si", $secret, $_SESSION['temp_user_id']);
    mysqli_stmt_execute($stmt);

    // Finalisation de la session : on transfère les données temporaires
    $_SESSION['id_utilisateur'] = $_SESSION['temp_user_id'];
    $_SESSION['role']           = $_SESSION['temp_role']   ?? null;
    $_SESSION['nom']            = $_SESSION['temp_nom']    ?? null;
    $_SESSION['prenom']         = $_SESSION['temp_prenom'] ?? null;
    $_SESSION['2fa_valide']     = true;

    // Nettoyage
    unset($_SESSION['temp_secret'], $_SESSION['temp_user_id'], $_SESSION['temp_role'], $_SESSION['temp_nom'], $_SESSION['temp_prenom']);

    // Régénération de l'ID de session
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();

    $redirect = $_SESSION['2fa_redirect'] ?? 'espace_cavalier.php';
    $allowed_redirects = ['espace_admin.php', 'espace_chef.php', 'espace_organisateur.php', 'espace_cavalier.php'];
    if (!in_array($redirect, $allowed_redirects)) {
        $redirect = 'espace_cavalier.php';
    }
    unset($_SESSION['2fa_redirect']);

    // Si mot de passe temporaire → forcer le changement avant d'accéder à l'espace
    if (!empty($_SESSION['mdp_temporaire'])) {
        $expiration = time() + 3600;
        $stmt_mail  = mysqli_prepare($conn, "SELECT mail FROM utilisateur WHERE id_utilisateur = ?");
        mysqli_stmt_bind_param($stmt_mail, "i", $_SESSION['id_utilisateur']);
        mysqli_stmt_execute($stmt_mail);
        $row_mail   = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_mail));
        $signature  = hash_hmac('sha256', $_SESSION['id_utilisateur'] . '|' . $row_mail['mail'] . '|' . $expiration, 'projetTREC_reset_2026_secret_key');
        $token      = base64_encode($_SESSION['id_utilisateur'] . '|' . $expiration . '|' . $signature);
        header('Location: login.php?action=reset&token=' . urlencode($token) . '&from=temp');
        exit;
    }

    header('Location: ' . $redirect);

} else {

    // Si le code est incorrect, on renvoie vers la page d'activation avec une erreur
    header('Location: activation2fa.php?error=invalid');
    exit();
}