<?php
// Suppression des avertissements de dépréciation PHP 8.x
error_reporting(E_ALL & ~E_DEPRECATED);

// Démarrage de la session
session_start();

// Si l'utilisateur n'a pas complété l'étape du login (mot de passe),
// on le redirige vers la page de connexion
if (!isset($_SESSION['temp_user_id'])) {
    header('Location: login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TREC - Vérification 2FA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; }
        /* Carte centrée avec coins arrondis */
        .card-2fa { border-radius: 20px; border: none; max-width: 450px; margin: 80px auto; }
    </style>
</head>
<body>
<div class="container">
    <div class="card card-2fa shadow-lg p-4 text-center">

        <!-- Icône et titre de la page -->
        <div class="mb-3">
            <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:70px;height:70px;">
                <i class="bi bi-phone-fill fs-2"></i>
            </div>
            <h2 class="fw-bold">Vérification 2FA</h2>
            <p class="text-muted small">Ouvrez Google Authenticator et entrez le code à 6 chiffres.</p>
        </div>

        <!-- Alerte affichée si le code TOTP soumis était incorrect -->
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger small">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>Code incorrect, réessayez.
            </div>
        <?php endif; ?>

        <!-- Formulaire d'envoi du code TOTP vers verif2fa.php -->
        <form action="verif2fa.php" method="POST">
            <div class="mb-4">
                <!-- autocomplete="off" empêche le navigateur de mémoriser le code -->
                <!-- autofocus place le curseur directement dans le champ au chargement -->
                <input type="text" name="code_totp"
                       class="form-control form-control-lg text-center fw-bold fs-4 letter-spacing-3"
                       placeholder="000000" maxlength="6" required autofocus autocomplete="off">
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-lg fw-bold py-2">
                    <i class="bi bi-check-circle me-2"></i>Vérifier
                </button>
            </div>
        </form>

        <!-- Lien de retour vers la page de connexion -->
        <div class="mt-4">
            <a href="login.php" class="text-decoration-none small text-muted">
                <i class="bi bi-arrow-left me-1"></i>Retour à la connexion
            </a>
        </div>

    </div>
</div>
</body>
</html>