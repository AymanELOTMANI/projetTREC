<?php
// Démarrage de la session si elle n'est pas déjà active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Désactive la vidéo hero dans le header sur cette page
$showHeroVideo = false;
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authentification</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link href="/projetTREC/styles.css" rel="stylesheet">
</head>

<body class="d-flex flex-column min-vh-100 bg-dark">

    <?php include '../header.php'; ?>

    <main class="container d-flex align-items-center justify-content-center flex-grow-1 py-5">
        <div class="col-12 col-md-8 col-lg-5 col-xl-4">
            <div class="card shadow border-0 rounded-4">
                <div class="card-body p-10 p-md-10">

                    <div class="text-center mb-4">
                        <div class="bg-warning-subtle text-warning rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 70px; height: 70px;">
                            <i class="bi bi-shield-lock-fill fs-2"></i>
                        </div>
                        <h2 class="fw-bold font-title text-dark">Inscription</h2>
                        <p class="text-muted small">Rejoignez la cavalerie</p>
                    </div>

                    <?php
                    // Affichage du message d'erreur correspondant au code reçu en GET
                    if (isset($_GET['error'])) {
                        // Tableau associatif : code d'erreur => message lisible
                        $error_messages = [
                            '1'             => 'Identifiant ou mot de passe incorrect.',
                            '2'             => 'Compte utilisateur introuvable.',
                            '3'             => 'Accès restreint. Veuillez vous identifier.',
                            'mdp_different' => 'Les mots de passe ne correspondent pas.',
                            'login_pris'    => 'Ce nom d\'utilisateur est déjà utilisé.',
                        ];

                        $error_code = htmlspecialchars($_GET['error']); // Protection XSS
                        $message = $error_messages[$error_code] ?? 'Erreur inconnue.';

                        echo '
                        <div class="alert alert-danger d-flex align-items-center justify-content-center small mb-4" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <div>' . $message . '</div>
                        </div>';
                    }
                    ?>

                    <!-- Formulaire d'inscription envoyé en POST vers traitement_auth.php -->
                    <form method="POST" action="traitement_auth.php">

                        <div class="mb-3">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-secondary">
                                    <i class="bi bi-person"></i>
                                </span>
                                <input type="text" name="nom" class="form-control border-start-0 ps-0 shadow-none" placeholder="Nom" required autofocus>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-secondary">
                                    <i class="bi bi-person"></i>
                                </span>
                                <input type="text" name="prenom" class="form-control border-start-0 ps-0 shadow-none" placeholder="Prénom" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-secondary">
                                    <i class="bi bi-person"></i>
                                </span>
                                <!-- type="text" ici, à remplacer par type="email" pour une meilleure validation -->
                                <input type="email" name="mail" class="form-control border-start-0 ps-0 shadow-none" placeholder="mail@trec.fr" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-secondary">
                                    <i class="bi bi-tag"></i>
                                </span>
                                <select name="categorie" class="form-select border-start-0 shadow-none" required>
                                    <option value="" disabled selected>Catégorie</option>
                                    <option value="Club 1">Club 1</option>
                                    <option value="Club 2">Club 2</option>
                                    <option value="Club 3">Club 3</option>
                                    <option value="Club 4">Club 4</option>
                                    <option value="Amateur 1">Amateur 1</option>
                                    <option value="Amateur 2">Amateur 2</option>
                                    <option value="Amateur 3">Amateur 3</option>
                                    <option value="Amateur 4">Amateur 4</option>
                                    <option value="Elite">Elite</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-secondary">
                                    <i class="bi bi-person"></i>
                                </span>
                                <input type="text" name="login" class="form-control border-start-0 ps-0 shadow-none" placeholder="Identifiant" required>
                            </div>
                        </div>

                        <div class="mb-1">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-secondary">
                                    <i class="bi bi-key"></i>
                                </span>
                                <input type="password" id="password_hash" name="password_hash" class="form-control border-start-0 ps-0 shadow-none" placeholder="Mot de passe" required>
                                <button class="btn btn-outline-secondary border-start-0" type="button" id="togglePassword" tabindex="-1">
                                    <i class="bi bi-eye" id="eyeIcon"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Barre de force du mot de passe -->
                        <div class="mb-2">
                            <div class="progress" style="height: 5px;">
                                <div id="strengthBar" class="progress-bar" role="progressbar" style="width: 0%; transition: width 0.3s;"></div>
                            </div>
                            <small id="strengthLabel" class="text-muted"></small>
                        </div>

                        <!-- Critères de sécurité -->
                        <div class="mb-3 p-2 rounded-3 bg-light border small">
                            <p class="mb-1 fw-semibold text-secondary">Critères du mot de passe :</p>
                            <ul class="list-unstyled mb-0">
                                <li id="crit-length"  class="text-secondary"><i class="bi bi-x-circle me-1"></i>Au moins 8 caractères</li>
                                <li id="crit-upper"   class="text-secondary"><i class="bi bi-x-circle me-1"></i>Au moins 1 lettre majuscule</li>
                                <li id="crit-lower"   class="text-secondary"><i class="bi bi-x-circle me-1"></i>Au moins 1 lettre minuscule</li>
                                <li id="crit-digit"   class="text-secondary"><i class="bi bi-x-circle me-1"></i>Au moins 1 chiffre</li>
                                <li id="crit-special" class="text-secondary"><i class="bi bi-x-circle me-1"></i>Au moins 1 caractère spécial (!@#$…)</li>
                            </ul>
                        </div>

                        <div class="mb-3">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-secondary">
                                    <i class="bi bi-key"></i>
                                </span>
                                <input type="password" id="password_confirm" name="password_confirm" class="form-control border-start-0 ps-0 shadow-none" placeholder="Confirmez votre mot de passe" required>
                            </div>
                            <small id="matchMsg" class="d-none mt-1 small"></small>
                        </div>

                        <script>
                        (function () {
                            const pwd    = document.getElementById('password_hash');
                            const conf   = document.getElementById('password_confirm');
                            const bar    = document.getElementById('strengthBar');
                            const label  = document.getElementById('strengthLabel');
                            const matchMsg = document.getElementById('matchMsg');
                            const toggle = document.getElementById('togglePassword');
                            const eye    = document.getElementById('eyeIcon');

                            const crits = {
                                length:  { el: document.getElementById('crit-length'),  test: v => v.length >= 8 },
                                upper:   { el: document.getElementById('crit-upper'),   test: v => /[A-Z]/.test(v) },
                                lower:   { el: document.getElementById('crit-lower'),   test: v => /[a-z]/.test(v) },
                                digit:   { el: document.getElementById('crit-digit'),   test: v => /\d/.test(v) },
                                special: { el: document.getElementById('crit-special'), test: v => /[^A-Za-z0-9]/.test(v) },
                            };

                            const levels = [
                                { label: 'Très faible', color: 'bg-danger',  pct: 20 },
                                { label: 'Faible',      color: 'bg-danger',  pct: 40 },
                                { label: 'Moyen',       color: 'bg-warning', pct: 60 },
                                { label: 'Fort',        color: 'bg-info',    pct: 80 },
                                { label: 'Très fort',   color: 'bg-success', pct: 100 },
                            ];

                            pwd.addEventListener('input', function () {
                                const val = this.value;
                                let score = 0;

                                Object.values(crits).forEach(c => {
                                    const ok = c.test(val);
                                    if (ok) score++;
                                    c.el.className = ok ? 'text-success' : 'text-secondary';
                                    c.el.querySelector('i').className = ok
                                        ? 'bi bi-check-circle-fill me-1'
                                        : 'bi bi-x-circle me-1';
                                });

                                if (val.length === 0) { bar.style.width = '0%'; bar.className = 'progress-bar'; label.textContent = ''; return; }

                                const lvl = levels[score - 1] || levels[0];
                                bar.style.width = lvl.pct + '%';
                                bar.className   = 'progress-bar ' + lvl.color;
                                label.textContent = lvl.label;
                                label.className = lvl.color === 'bg-success' ? 'text-success small' : lvl.color === 'bg-warning' ? 'text-warning small' : 'text-danger small';

                                checkMatch();
                            });

                            conf.addEventListener('input', checkMatch);

                            function checkMatch() {
                                if (conf.value.length === 0) { matchMsg.className = 'd-none'; return; }
                                if (pwd.value === conf.value) {
                                    matchMsg.textContent = '✓ Les mots de passe correspondent';
                                    matchMsg.className = 'small text-success d-block';
                                } else {
                                    matchMsg.textContent = '✗ Les mots de passe ne correspondent pas';
                                    matchMsg.className = 'small text-danger d-block';
                                }
                            }

                            toggle.addEventListener('click', function () {
                                const visible = pwd.type === 'text';
                                pwd.type  = visible ? 'password' : 'text';
                                conf.type = visible ? 'password' : 'text';
                                eye.className = visible ? 'bi bi-eye' : 'bi bi-eye-slash';
                            });

                            // Bloque la soumission si les critères ne sont pas tous remplis
                            pwd.closest('form').addEventListener('submit', function (e) {
                                const allOk = Object.values(crits).every(c => c.test(pwd.value));
                                if (!allOk) {
                                    e.preventDefault();
                                    pwd.classList.add('is-invalid');
                                    pwd.focus();
                                }
                                if (pwd.value !== conf.value) {
                                    e.preventDefault();
                                    conf.classList.add('is-invalid');
                                    conf.focus();
                                }
                            });
                        })();
                        </script>

                        <!-- name="inscription" permet à traitement_auth.php de détecter quel formulaire a été soumis -->
                        <div class="d-grid mb-3">
                            <button type="submit" name="inscription" class="btn btn-dark">S'inscrire</button>
                        </div>

                        <div class="text-center mt-4">
                            <a href="../../index.php" class="text-decoration-none small text-muted">
                                <i class="bi bi-arrow-left me-1"></i>Retour à l'accueil
                            </a>
                        </div>

                    </form>
                </div>
            </div>

            <div class="text-center mt-4 text-muted small">
                Projet TREC &copy; 2026 – BTS CIEL
            </div>

        </div>
    </main>

    <?php include '../footer.php'; ?>

</body>
</html>