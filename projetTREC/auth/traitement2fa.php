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

    // Si le code est correct, on active la double authentification dans la base de données
    $stmt = mysqli_prepare($conn, "UPDATE utilisateur SET secret_totp = ?, totp_active = 1 WHERE id_utilisateur = ?");

    // On lie les paramètres à la requête SQL
    mysqli_stmt_bind_param($stmt, "si", $secret, $_SESSION['temp_user_id']);

    // On exécute la requête
    mysqli_stmt_execute($stmt);

    // On indique dans la session que la double authentification est validée
    $_SESSION['2fa_valide'] = true;

    // On supprime les variables temporaires
    unset($_SESSION['temp_secret']);
    unset($_SESSION['temp_user_id']);

    // Redirection selon le rôle de l'utilisateur
    $redirect = $_SESSION['2fa_redirect'] ?? 'espace_cavalier.php';
    
    // Vérifier que la page existe selon le rôle
    $allowed_redirects = [
        'espace_admin.php',
        'espace_chef.php',
        'espace_organisateur.php',
        'espace_cavalier.php'
    ];
    
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