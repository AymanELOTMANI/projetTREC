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
    $_SESSION['2fa_valide'] = true;
    unset($_SESSION['temp_user_id']);

    // Si mot de passe temporaire → forcer le changement
    if (!empty($_SESSION['mdp_temporaire'])) {
        $expiration  = time() + 3600;
        $stmt_mail   = mysqli_prepare($conn, "SELECT mail FROM utilisateur WHERE id_utilisateur = ?");
        mysqli_stmt_bind_param($stmt_mail, "i", $_SESSION['id_utilisateur']);
        mysqli_stmt_execute($stmt_mail);
        $row_mail    = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_mail));
        $signature   = hash_hmac('sha256', $_SESSION['id_utilisateur'] . '|' . $row_mail['mail'] . '|' . $expiration, 'projetTREC_reset_2026_secret_key');
        $token       = base64_encode($_SESSION['id_utilisateur'] . '|' . $expiration . '|' . $signature);
        header('Location: login.php?action=reset&token=' . urlencode($token) . '&from=temp');
        exit();
    }

    header('Location: ' . $_SESSION['2fa_redirect']);
    exit();
}
   
?>