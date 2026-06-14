<?php
// Initialisation de la session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$showHeroVideo = true;

// Inclusion de l'en-tête
include './projetTREC/header.php';
?>

<link rel="stylesheet" href="/projetTREC/assets/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

<style>
    html {
        scroll-behavior: smooth;
    }

    .scroll-indicator {
        position: absolute;
        bottom: 28px;
        left: 50%;
        transform: translateX(-50%);
        color: white;
        text-decoration: none;
        text-align: center;
        z-index: 3;
        opacity: 0.95;
    }

    .scroll-indicator span {
        display: block;
        font-size: 0.9rem;
        font-weight: 600;
        margin-bottom: 4px;
        letter-spacing: 0.5px;
    }

    .scroll-indicator i {
        font-size: 2rem;
        animation: bounce 1.5s infinite;
    }

    .scroll-indicator:hover {
        color: #ffc107;
    }

    @keyframes bounce {
        0%, 100% {
            transform: translateY(0);
        }

        50% {
            transform: translateY(10px);
        }
    }

    .hover-card {
        transition: 0.2s;
    }

    .hover-card:hover {
        transform: translateY(-5px);
    }

    .pillar-card {
        min-height: 175px;
    }
</style>

<?php if ($showHeroVideo): ?>
<header class="position-relative vh-100 w-100 overflow-hidden d-flex align-items-center justify-content-center text-white">

    <!-- Vidéo de fond -->
    <video autoplay muted loop playsinline class="position-absolute w-100 h-100 object-fit-cover z-0">
        <source src="/projetTREC/assets/TREC3.mp4" type="video/mp4">
    </video>

    <!-- Filtre sombre -->
    <div class="position-absolute w-100 h-100 bg-dark opacity-50 z-1"></div>

    <!-- Texte principal -->
    <div class="container position-relative z-2 text-center">

        <h1 class="display-2 fw-bold mb-3">
            Gestion des épreuves de TREC
        </h1>

        <p class="lead fs-3 mb-4">
            Une plateforme web pour organiser, suivre et consulter les compétitions
        </p>


        <?php if (!isset($_SESSION['user_id'])): ?>
            <a href="/projetTREC/auth/login.php" class="btn btn-warning btn-lg px-5 py-3 shadow-lg fw-bold">
                <i class="bi bi-box-arrow-in-right me-2"></i>Se connecter
            </a>
        <?php else: ?>
            <a href="/projetTREC/dashboard.php" class="btn btn-light btn-lg px-5 py-3 shadow-lg fw-bold">
                <i class="bi bi-speedometer2 me-2"></i>Accéder à mon tableau de bord
            </a>
        <?php endif; ?>
    </div>

    <!-- Indication pour faire défiler -->
    <a href="#contenu" class="scroll-indicator">
        <span>Faire défiler</span>
        <i class="bi bi-chevron-double-down"></i>
    </a>

</header>
<?php endif; ?>

<main id="contenu" class="flex-grow-1">

    <!-- Présentation simple -->
    <section class="container my-5">

        <div class="text-center mb-5">
            <h2 class="fw-bold">Plateforme de gestion TREC</h2>
            <p class="lead text-muted">
                Une application web pour organiser la compétition, suivre les cavaliers et consulter les résultats.
            </p>
        </div>

        <!-- Cartes principales -->
        <div class="row justify-content-center g-4">

            <!-- Espace Cavalier -->
            <div class="col-md-4">
                <div class="card h-100 shadow border-0 hover-card text-center p-4">
                    <div class="card-body">
                        <i class="bi bi-person-badge display-4 text-success mb-3 d-block"></i>

                        <h3 class="h4 fw-bold">Espace Cavalier</h3>

                        <p class="text-muted">
                            Connexion, inscription et consultation des informations personnelles.
                        </p>

                        <?php if (isset($_SESSION['user_id'])): ?>
                            <a href="/projetTREC/dashboard.php" class="btn btn-success w-100">
                                Mon compte
                            </a>
                        <?php else: ?>
                            <a href="/projetTREC/auth/login.php" class="btn btn-outline-success w-100">
                                <i class="bi bi-person me-2"></i>Se connecter
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Classement -->
            <div class="col-md-4">
                <div class="card h-100 shadow border-0 hover-card text-center p-4">
                    <div class="card-body">
                        <i class="bi bi-trophy display-4 text-danger mb-3 d-block"></i>

                        <h3 class="h4 fw-bold">Classements</h3>

                        <p class="text-muted">
                            Affichage des temps, pénalités et scores des cavaliers.
                        </p>

                        <a href="/projetTREC/classement/classement.php" class="btn btn-outline-danger w-100">
                            <i class="bi bi-eye me-2"></i>Voir le classement
                        </a>
                    </div>
                </div>
            </div>

            <!-- Parcours -->
            <div class="col-md-4">
                <div class="card h-100 shadow border-0 hover-card text-center p-4">
                    <div class="card-body">
                        <i class="bi bi-map display-4 text-primary mb-3 d-block"></i>

                        <h3 class="h4 fw-bold">Parcours</h3>

                        <p class="text-muted">
                            Consultation du parcours officiel et du trajet GPS réalisé.
                        </p>

                        <a href="/projetTREC/parcours/tracerParcours.php" class="btn btn-outline-primary w-100">
                            <i class="bi bi-map me-2"></i>Accéder aux parcours
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- Qu'est-ce que le TREC ? -->
    <section class="bg-light py-5 border-top">
        <div class="container">

            <!-- Titre découverte -->
            <div class="row align-items-center mb-5 text-center">
                <div class="col-lg-8 mx-auto">
                    <span class="badge bg-warning text-dark mb-2">DÉCOUVERTE</span>

                    <h2 class="display-5 fw-bold font-title mb-3">
                        Qu'est-ce que le TREC ?
                    </h2>

                    <p class="lead text-secondary">
                        Techniques de Randonnée Équestre de Compétition.<br>
                        Une discipline qui évalue l’autonomie du couple cheval-cavalier en extérieur.
                    </p>
                </div>
            </div>

            <!-- Les 4 Piliers -->
            <h3 class="h4 fw-bold font-title text-center mb-4">
                Les 4 piliers de la discipline
            </h3>

            <div class="row g-4 mb-5">
                <?php
                $piliers = [
                    [
                        'icon' => 'compass',
                        'color' => 'primary',
                        'title' => '1. Le POR',
                        'desc' => "<strong>Orientation et régularité.</strong><br>Le cavalier doit suivre un itinéraire précis, respecter une vitesse moyenne et passer par les bons points de contrôle."
                    ],
                    [
                        'icon' => 'tree',
                        'color' => 'success',
                        'title' => '2. Le PTV',
                        'desc' => "<strong>Parcours en terrain varié.</strong><br>Le couple cheval-cavalier franchit des difficultés naturelles ou simulées, comme un tronc, une passerelle ou un gué."
                    ],
                    [
                        'icon' => 'speedometer2',
                        'color' => 'warning',
                        'title' => '3. La MA',
                        'desc' => "<strong>Maîtrise des allures.</strong><br>Le cavalier doit montrer qu’il contrôle les allures de son cheval : galop lent et pas rapide sur un tracé défini."
                    ],
                    [
                        'icon' => 'shield-check',
                        'color' => 'info',
                        'title' => '4. Le contrôle',
                        'desc' => "<strong>Sécurité et matériel.</strong><br>Le matériel du cavalier et du cheval est vérifié afin de garantir une épreuve réalisée dans de bonnes conditions."
                    ]
                ];

                foreach ($piliers as $p): ?>
                    <div class="col-md-6 col-lg-3">
                        <div class="card h-100 border-0 shadow-sm bg-white hover-card pillar-card">
                            <div class="card-body">
                                <h5 class="fw-bold text-<?= $p['color'] ?>">
                                    <i class="bi bi-<?= $p['icon'] ?> me-2"></i><?= $p['title'] ?>
                                </h5>

                                <p class="small text-muted mb-0">
                                    <?= $p['desc'] ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    </section>

</main>

<?php include './projetTREC/footer.php'; ?>