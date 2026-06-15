<?php
// Démarrage de la session
session_start();

// Inclusion de la configuration et du chargeur de librairies Composer
require_once '../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Import des classes de la librairie 2FA
use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;

// Si l'utilisateur n'a pas complété l'étape du login, on le redirige
if (!isset($_SESSION['temp_user_id'])) {
    header('Location: login.php');
    exit();
}

// Récupération du code TOTP soumis par le formulaire
$code = $_POST['code_totp'];

// Récupération du secret TOTP stocké en base pour cet utilisateur
$stmt = mysqli_prepare($conn, "SELECT secret_totp FROM utilisateur WHERE id_utilisateur = ?");
mysqli_stmt_bind_param($stmt, "i", $_SESSION['temp_user_id']);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user   = mysqli_fetch_assoc($result);

// Initialisation de la librairie 2FA
$tfa = new TwoFactorAuth(new EndroidQrCodeProvider(), 'TREC');

// Vérification du code saisi par rapport au secret stocké
if ($tfa->verifyCode($user['secret_totp'], $code)) {
    // Code correct : on finalise la session en transférant les données temporaires
    $_SESSION['id_utilisateur'] = $_SESSION['temp_user_id'];
    $_SESSION['role']           = $_SESSION['temp_role']   ?? null;
    $_SESSION['nom']            = $_SESSION['temp_nom']    ?? null;
    $_SESSION['prenom']         = $_SESSION['temp_prenom'] ?? null;
    $_SESSION['2fa_valide']     = true;

    // Nettoyage des variables temporaires
    unset($_SESSION['temp_user_id'], $_SESSION['temp_role'], $_SESSION['temp_nom'], $_SESSION['temp_prenom']);

    // Régénération de l'ID de session pour éviter la fixation de session
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();

    $redirect = $_SESSION['2fa_redirect'] ?? 'espace_cavalier.php';
    $allowed_redirects = ['espace_admin.php', 'espace_chef.php', 'espace_organisateur.php', 'espace_cavalier.php'];
    if (!in_array($redirect, $allowed_redirects)) {
        $redirect = 'espace_cavalier.php';
    }
    unset($_SESSION['2fa_redirect']);

    header('Location: ' . $redirect);
} else {
    // Code incorrect : retour sur la page de vérification avec un message d'erreur
    header('Location: login2fa.php?error=1');
}
?>