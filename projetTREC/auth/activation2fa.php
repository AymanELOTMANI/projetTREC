<?php
// Suppression des avertissements de dépréciation PHP 8.x pour éviter les warnings inutiles
error_reporting(E_ALL & ~E_DEPRECATED);

// Inclusion de la configuration (connexion BDD)
require_once '../config.php';

// Chargement automatique des librairies installées via Composer (dont TwoFactorAuth)
require_once __DIR__ . '/../vendor/autoload.php';

// Démarrage de la session si elle n'est pas déjà active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Seul un utilisateur en cours de connexion peut accéder à cette page
// temp_user_id est défini dans traitement_auth.php après vérification du mot de passe
if (!isset($_SESSION['temp_user_id'])) {
    header('Location: login.php');
    exit();
}

// Import des classes de la librairie RobThree/TwoFactorAuth
use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;

// Récupère le nom et le rôle depuis la session (déjà définis dans traitement_auth.php)
$nom_compte = trim(($_SESSION['temp_prenom'] ?? '') . ' ' . ($_SESSION['temp_nom'] ?? '')) ?: 'Compte inconnu';
$role_utilisateur = $_SESSION['temp_role'] ?? 'inconnu';


// Initialisation de la librairie 2FA avec le nom affiché dans Google Authenticator
$tfa = new TwoFactorAuth(new EndroidQrCodeProvider(), 'TREC88000');

// On génère un secret TOTP une seule fois et on le garde en session
// pour ne pas en créer un nouveau à chaque rechargement de la page
if (!isset($_SESSION['temp_secret'])) {
    $_SESSION['temp_secret'] = $tfa->createSecret();
}
$secret = $_SESSION['temp_secret'];

// Génération du QR Code : le label "Jean Dupont (admin)" s'affiche dans l'appli authenticator
$qrCodeUrl = $tfa->getQRCodeImageAsDataUri($nom_compte . ' (' . $role_utilisateur . ')', $secret);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>TREC - Activation Sécurité Forte</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; }
        /* Carte centrée avec coins arrondis pour un rendu moderne */
        .card-2fa { border-radius: 20px; border: none; max-width: 500px; margin: 50px auto; }
        /* Cadre blanc autour du QR Code pour améliorer la lisibilité */
        .qr-frame { background: white; padding: 20px; border-radius: 15px; display: inline-block; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    </style>
</head>
<body>

<div class="container">
    <div class="card card-2fa shadow-lg p-4 text-center">
        <i class="bi bi-shield-lock-fill text-success display-4 mb-3"></i>
        <h2 class="fw-bold">Sécurisez votre compte</h2>
        <p class="text-muted">Le Projet TREC impose une authentification forte pour les utilisateurs.</p>

        <div class="my-4">
            <p class="small fw-bold">1. Scannez ce QR Code avec l'application Google Authenticator</p>

            <!-- Affichage du QR Code généré en base64 directement dans la balise img -->
            <div class="qr-frame mb-3">
                <img src="<?php echo $qrCodeUrl; ?>" alt="QR Code TOTP">
            </div>

            <!-- Clé manuelle affichée en clair pour les utilisateurs ne pouvant pas scanner -->
            <p class="text-muted small">Ou saisissez manuellement la clé : <br><code><?php echo $secret; ?></code></p>
        </div>

        <hr>

        <!-- Étape 2 : saisie du code TOTP pour vérifier que le scan a bien fonctionné -->
        <div class="mt-4 text-start">
            <p class="small fw-bold text-center">2. Entrez le code à 6 chiffres généré par l'application</p>
            <form action="traitement2fa.php" method="POST">
                <div class="mb-3">
                    <!-- autocomplete="off" empêche le navigateur de proposer des suggestions -->
                    <input type="text" name="code_totp" class="form-control form-control-lg text-center fw-bold"
                           placeholder="000 000" maxlength="6" required autofocus autocomplete="off">
                </div>
                <button type="submit" class="btn btn-success w-100 py-2 fw-bold">
                    <i class="bi bi-check-circle me-2"></i>Activer la protection
                </button>
            </form>
        </div>
    </div>
</div>

</body>
</html>