<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$utilisateurConnecte = !empty($_SESSION['id_utilisateur']);
$roleUtilisateur = $_SESSION['role'] ?? '';

$estAdmin = $roleUtilisateur === 'admin';
$estOrganisateur = $roleUtilisateur === 'organisateur';
$estChef = $roleUtilisateur === 'chef_piste';
$estCavalier = $roleUtilisateur === 'cavalier';

$nomComplet = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TREC – Cheniménil</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Police -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&display=swap" rel="stylesheet">

    <!-- Style du site -->
    <link href="/projetTREC/styles.css" rel="stylesheet">

    <link rel="icon" type="image/png" href="/projetTREC/assets/equitation.png">

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
</head>

<body class="d-flex flex-column min-vh-100">

<!-- Ajout de py-3 pour augmenter l'épaisseur (padding vertical) -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm py-3">
    <!-- Remplacement de "container" par "container-fluid px-lg-5" pour occuper toute la largeur avec une marge sur les côtés -->
    <div class="container-fluid px-lg-5">

        <a class="navbar-brand fw-bold font-title" href="/index.php">
            <i class="bi bi-compass-fill me-2"></i>TREC CHENIMÉNIL
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
    <!-- 1. Ce bloc va occuper tout l'espace central et centrer les boutons -->
    <ul class="navbar-nav flex-grow-1 justify-content-center">

        <!-- Accueil -->
        <li class="nav-item">
            <a class="nav-link btn btn-outline-warning text-white fw-bold px-3" href="/index.php">
                <i class="bi bi-house"></i> Accueil
            </a>
        </li>
        <!-- Classement -->
        <li class="nav-item">
            <a class="nav-link btn btn-outline-warning text-white fw-bold px-3" href="/projetTREC/classement/classement.php">
                <i class="bi bi-award"></i> Classement
            </a>
        </li>


        <?php if ($utilisateurConnecte): ?>
            <?php 
            $btnClass = "nav-link btn btn-outline-warning ms-lg-2 text-white fw-bold px-3";
            $btnActionClass = "nav-link btn btn-warning ms-lg-2 text-white fw-bold px-3";
            ?>

            <?php if ($estAdmin): ?>
                <li class="nav-item"><a class="<?= $btnClass ?>" href="/projetTREC/classement/troncons.php"><i class="bi bi-flag"></i> Tronçons</a></li>
                <li class="nav-item"><a class="<?= $btnClass ?>" href="/projetTREC/classement/competition.php"><i class="bi bi-trophy"></i> Compétition</a></li>
                <li class="nav-item"><a class="<?= $btnClass ?>" href="/projetTREC/associer/associer_dossard.php"><i class="bi bi-link"></i> Dossard</a></li>
                <li class="nav-item"><a class="<?= $btnClass ?>" href="/projetTREC/associer/associer.php"><i class="bi bi-broadcast-pin"></i> RFID</a></li>
                <li class="nav-item"><a class="<?= $btnClass ?>" href="/projetTREC/parcours/tracerParcours.php"><i class="bi bi-pencil"></i> Parcours</a></li>
                <li class="nav-item"><a class="<?= $btnClass ?>" href="/projetTREC/competition/affectation_depart.php"><i class="bi bi-diagram-3"></i> Affectation</a></li>
                <li class="nav-item"><a class="<?= $btnActionClass ?>" href="/projetTREC/auth/espace_admin.php"><i class="bi bi-gear"></i> Admin</a></li>

            <?php elseif ($estChef): ?>
                <li class="nav-item"><a class="<?= $btnClass ?>" href="/projetTREC/classement/troncons.php"><i class="bi bi-flag"></i> Tronçons</a></li>
                <li class="nav-item"><a class="<?= $btnClass ?>" href="/projetTREC/associer/associer_dossard.php"><i class="bi bi-link"></i> Dossard</a></li>
                <li class="nav-item"><a class="<?= $btnClass ?>" href="/projetTREC/associer/associer.php"><i class="bi bi-broadcast-pin"></i> RFID</a></li>
                <li class="nav-item"><a class="<?= $btnClass ?>" href="/projetTREC/parcours/tracerParcours.php"><i class="bi bi-pencil"></i> Parcours</a></li>
                <li class="nav-item"><a class="<?= $btnClass ?>" href="/projetTREC/competition/affectation_depart.php"><i class="bi bi-diagram-3"></i> Affectation</a></li>
                <li class="nav-item"><a class="<?= $btnActionClass ?>" href="/projetTREC/auth/espace_chef.php"><i class="bi bi-person-gear"></i> Espace chef</a></li>

            <?php elseif ($estOrganisateur): ?>
                <li class="nav-item"><a class="<?= $btnClass ?>" href="/projetTREC/classement/troncons.php"><i class="bi bi-flag"></i> Tronçons</a></li>
                <li class="nav-item"><a class="<?= $btnClass ?>" href="/projetTREC/classement/competition.php"><i class="bi bi-trophy"></i> Compétition</a></li>
                <li class="nav-item"><a class="<?= $btnClass ?>" href="/projetTREC/associer/associer.php"><i class="bi bi-broadcast-pin"></i> RFID</a></li>
                <li class="nav-item"><a class="<?= $btnClass ?>" href="/projetTREC/parcours/tracerParcours.php"><i class="bi bi-pencil"></i> Parcours</a></li>
                <li class="nav-item"><a class="<?= $btnClass ?>" href="/projetTREC/competition/affectation_depart.php"><i class="bi bi-diagram-3"></i> Affectation</a></li>
                <li class="nav-item"><a class="<?= $btnActionClass ?>" href="/projetTREC/auth/espace_organisateur.php"><i class="bi bi-person-gear"></i> Espace Organisateur</a></li>

            <?php elseif ($estCavalier): ?>
                <li class="nav-item"><a class="<?= $btnActionClass ?>" href="/projetTREC/auth/espace_cavalier.php"><i class="bi bi-person"></i> Mon espace</a></li>
            <?php endif; ?>

        <?php endif; ?>
    </ul>

    <!-- NOTIFICATIONS -->
<?php if ($utilisateurConnecte): ?>
    <li class="nav-item me-lg-3">
        <a href="#" class="nav-link position-relative" id="notificationBell" data-bs-toggle="dropdown">
            <i class="bi bi-bell-fill" style="font-size: 1.25rem;"></i>
            <!-- Badge avec le nombre de notifications -->
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="notificationBadge" style="display: none;">
                <span id="notificationCount">0</span>
            </span>
        </a>
        
        <!-- Dropdown des notifications -->
        <ul class="dropdown-menu dropdown-menu-end p-0" style="min-width: 350px;">
            <li class="dropdown-header bg-light">
                <i class="bi bi-bell"></i> Notifications
            </li>
            <li><hr class="dropdown-divider m-0"></li>
            <div id="notificationList" class="notification-list p-2" style="max-height: 400px; overflow-y: auto;">
                <p class="text-muted text-center py-3">Aucune notification</p>
            </div>
            <li><hr class="dropdown-divider m-0"></li>
            <li class="dropdown-footer text-center p-2">
                <a href="#" class="text-decoration-none small">Voir toutes les notifications</a>
            </li>
        </ul>
    </li>
<?php endif; ?>

    <!-- BLOC DE DROITE : Compte & Déconnexion -->
    <ul class="navbar-nav ms-auto align-items-center">
        <?php if ($utilisateurConnecte): ?>
            <!-- Bouton Déconnexion à l'extérieur -->
            <li class="nav-item">
                <a class="nav-link btn btn-sm btn-outline-danger fw-bold px-3 me-lg-3" href="/projetTREC/auth/logout.php" title="Déconnexion">Déconnexion
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </li>
            
            <!-- Pseudo de l'utilisateur -->
            <li class="nav-item">
                <span class="nav-link text-warning fw-bold border-start ps-lg-3">
                    <i class="bi bi-person-check me-1"></i> <?= htmlspecialchars($nomComplet) ?>
                </span>
            </li>
        <?php else: ?>
            <!-- Si non connecté, les boutons Connexion/Inscription restent à droite -->
            <li class="nav-item"><a class="nav-link btn btn-warning ms-lg-2 text-white fw-bold px-3" href="/projetTREC/auth/login.php">Connexion</a></li>
            <li class="nav-item"><a class="nav-link btn btn-outline-warning ms-lg-2 text-white fw-bold px-3" href="/projetTREC/auth/signup.php">Inscription</a></li>
        <?php endif; ?>
</div>

            </ul>
        </div>
    </div>
</nav>