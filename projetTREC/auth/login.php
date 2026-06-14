<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config.php';

// Clé secrète pour signer les tokens (à définir dans config.php si possible, sinon elle est ici)
define('RESET_SECRET', 'projetTREC_reset_2026_secret_key');

$showHeroVideo = false;
$action = $_GET['action'] ?? 'login';

// ─── FONCTIONS TOKEN ──────────────────────────────────────────────────────────

function generer_token(int $id_utilisateur, string $mail) : string {
    $expiration = time() + 3600;
    $signature  = hash_hmac('sha256', $id_utilisateur . '|' . $mail . '|' . $expiration, RESET_SECRET);
    return base64_encode($id_utilisateur . '|' . $expiration . '|' . $signature);
}

function verifier_token(string $token_b64, $conn) : ?array {
    $decoded = base64_decode($token_b64, true);
    if (!$decoded) return null;

    $parts = explode('|', $decoded);
    if (count($parts) !== 3) return null;

    [$id_utilisateur, $expiration, $signature] = $parts;

    if (time() > (int)$expiration) return null;

    // Récupérer le mail depuis la BDD pour reconstruire la signature
    $stmt = mysqli_prepare($conn, "SELECT id_utilisateur, mail FROM utilisateur WHERE id_utilisateur = ? AND statut = 'actif' LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $id_utilisateur);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$user) return null;

    $signature_attendue = hash_hmac('sha256', $id_utilisateur . '|' . $user['mail'] . '|' . $expiration, RESET_SECRET);
    if (!hash_equals($signature_attendue, $signature)) return null;

    return $user;
}

// ─── TRAITEMENT : MOT DE PASSE OUBLIÉ (POST) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_submit'])) {

    $login_ou_mail = trim($_POST['login_ou_mail'] ?? '');

    if (!empty($login_ou_mail)) {
        $stmt = mysqli_prepare($conn, "
            SELECT id_utilisateur, nom, prenom, mail
            FROM utilisateur
            WHERE (login = ? OR mail = ?) AND statut = 'actif'
            LIMIT 1
        ");
        mysqli_stmt_bind_param($stmt, 'ss', $login_ou_mail, $login_ou_mail);
        mysqli_stmt_execute($stmt);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if ($user && !empty($user['mail'])) {
            $token = generer_token((int)$user['id_utilisateur'], $user['mail']);

            $protocole = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $lien      = $protocole . '://' . $_SERVER['HTTP_HOST'] . '/projetTREC/auth/login.php?action=reset&token=' . urlencode($token);

            $sujet = "Réinitialisation de votre mot de passe – Projet TREC";
            $corps = "Bonjour {$user['prenom']} {$user['nom']},\n\n"
                   . "Vous avez demandé la réinitialisation de votre mot de passe.\n\n"
                   . "Cliquez sur le lien ci-dessous (valable 1 heure) :\n"
                   . $lien . "\n\n"
                   . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message.\n\n"
                   . "L'équipe Projet TREC";

            $headers = "From: no-reply@projettrec.fr\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            mail($user['mail'], $sujet, $corps, $headers);
        }
    }
    header('Location: login.php?action=forgot&status=envoye');
    exit;
}

// ─── TRAITEMENT : RÉINITIALISATION DU MOT DE PASSE (POST) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_submit'])) {

    $token_b64 = trim($_POST['token'] ?? '');
    $mdp1      = $_POST['mdp1'] ?? '';
    $mdp2      = $_POST['mdp2'] ?? '';

    $erreur_reset = null;

    if (empty($token_b64) || empty($mdp1) || empty($mdp2)) {
        $erreur_reset = 'Tous les champs sont requis.';
    } elseif ($mdp1 !== $mdp2) {
        $erreur_reset = 'Les mots de passe ne correspondent pas.';
    } elseif (strlen($mdp1) < 8) {
        $erreur_reset = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif (str_starts_with($mdp1, 'TREC-')) {
        $erreur_reset = 'Vous ne pouvez pas réutiliser un mot de passe temporaire.';
    } else {
        $user = verifier_token($token_b64, $conn);
        if (!$user) {
            $erreur_reset = 'Ce lien est invalide ou a expiré.';
        } else {
            $hash    = password_hash($mdp1, PASSWORD_BCRYPT);
            $stmtUpd = mysqli_prepare($conn, "UPDATE utilisateur SET password_hash = ? WHERE id_utilisateur = ?");
            mysqli_stmt_bind_param($stmtUpd, 'si', $hash, $user['id_utilisateur']);
            mysqli_stmt_execute($stmtUpd);

            // Nettoyer le flag de mot de passe temporaire
            unset($_SESSION['mdp_temporaire']);

            $suffix = (($_POST['from'] ?? '') === 'temp') ? '&from=temp' : '';
            header('Location: login.php?reset=ok' . $suffix);
            exit;

            $suffix = (($_POST['from'] ?? '') === 'temp') ? '&from=temp' : '';
            header('Location: login.php?reset=ok' . $suffix);
            exit;
        }
    }

    $action = 'reset';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php
        if ($action === 'forgot') echo 'Mot de passe oublié';
        elseif ($action === 'reset') echo 'Nouveau mot de passe';
        else echo 'Authentification';
    ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link href="/projetTREC/styles.css" rel="stylesheet">
</head>

<body class="d-flex flex-column min-vh-100 bg-light">

    <?php include '../header.php'; ?>

    <main class="container d-flex align-items-center justify-content-center flex-grow-1 py-5">
        <div class="col-12 col-md-8 col-lg-5 col-xl-4">
            <div class="card shadow border-0 rounded-4">
                <div class="card-body p-4 p-md-5">

<?php if ($action === 'forgot'): ?>
    <!-- ═══════════════════ VUE : MOT DE PASSE OUBLIÉ ════════════════════ -->

                    <div class="text-center mb-4">
                        <div class="bg-warning-subtle text-warning rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 70px; height: 70px;">
                            <i class="bi bi-envelope-open-fill fs-2"></i>
                        </div>
                        <h2 class="fw-bold font-title text-dark">Mot de passe oublié</h2>
                        <p class="text-muted small">Entrez votre identifiant ou adresse e-mail pour recevoir un lien de réinitialisation.</p>
                    </div>

                    <?php if (isset($_GET['status']) && $_GET['status'] === 'envoye'): ?>
                        <div class="alert alert-success d-flex align-items-center small mb-4" role="alert">
                            <i class="bi bi-check-circle-fill me-2 fs-5"></i>
                            <div>Si un compte correspond, un e-mail vient d'être envoyé. Vérifiez votre boîte mail.</div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="login.php?action=forgot">
                        <div class="mb-4">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-secondary">
                                    <i class="bi bi-person"></i>
                                </span>
                                <input type="text" name="login_ou_mail"
                                       class="form-control border-start-0 ps-0 shadow-none"
                                       placeholder="Identifiant ou e-mail" required autofocus>
                            </div>
                        </div>

                        <div class="d-grid mb-3">
                            <button type="submit" name="forgot_submit" class="btn btn-warning btn-lg fw-bold shadow-sm py-2 text-dark">
                                <i class="bi bi-send me-2"></i>Envoyer le lien
                            </button>
                        </div>
                    </form>

                    <div class="text-center mt-3">
                        <a href="login.php" class="text-decoration-none small text-muted">
                            <i class="bi bi-arrow-left me-1"></i>Retour à la connexion
                        </a>
                    </div>

<?php elseif ($action === 'reset'): ?>
    <!-- ═══════════════════ VUE : NOUVEAU MOT DE PASSE ══════════════════ -->

                    <?php
                    $token_url    = $_GET['token'] ?? $_POST['token'] ?? '';
                    $token_valide = false;
                    if (!empty($token_url) && !isset($erreur_reset)) {
                        $token_valide = (bool) verifier_token($token_url, $conn);
                    } elseif (isset($erreur_reset)) {
                        $token_valide = true;
                    }
                    ?>

                    <div class="text-center mb-4">
                        <div class="bg-warning-subtle text-warning rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 70px; height: 70px;">
                            <i class="bi bi-shield-lock-fill fs-2"></i>
                        </div>
                        <h2 class="fw-bold font-title text-dark">Nouveau mot de passe</h2>
                        <p class="text-muted small">Choisissez un nouveau mot de passe sécurisé.</p>
                    </div>

                    <?php if (isset($_GET['from']) && $_GET['from'] === 'temp'): ?>
                        <div class="alert alert-warning d-flex align-items-center small mb-4" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                            <div><strong>Mot de passe temporaire détecté.</strong><br>Vous devez définir un nouveau mot de passe personnel avant de continuer.</div>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($erreur_reset)): ?>
                        <div class="alert alert-danger d-flex align-items-center small mb-4" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                            <div><?= htmlspecialchars($erreur_reset) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!$token_valide): ?>
                        <div class="alert alert-danger d-flex align-items-center small mb-4" role="alert">
                            <i class="bi bi-x-circle-fill me-2 fs-5"></i>
                            <div>Ce lien est invalide ou a expiré. <a href="login.php?action=forgot" class="alert-link">Faire une nouvelle demande</a>.</div>
                        </div>
                    <?php else: ?>

                    <form method="POST" action="login.php?action=reset">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token_url) ?>">
                        <?php if (isset($_GET['from']) && $_GET['from'] === 'temp'): ?>
                            <input type="hidden" name="from" value="temp">
                    <?php endif; ?>

                        <div class="mb-3">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-secondary">
                                    <i class="bi bi-key"></i>
                                </span>
                                <input type="password" name="mdp1" id="mdp1"
                                       class="form-control border-start-0 ps-0 shadow-none"
                                       placeholder="Nouveau mot de passe" minlength="8" required autofocus
                                       oninput="evaluerMdp(this.value)">
                                <button class="btn btn-outline-secondary border-start-0" type="button" onclick="toggleMdp('mdp1', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>

                            <!-- Barre de force -->
                            <div class="mt-2" id="force-wrap" style="display:none;">
                                <div class="progress" style="height:6px; border-radius:4px;">
                                    <div id="force-bar" class="progress-bar" role="progressbar" style="width:0%; transition:width .3s;"></div>
                                </div>
                                <div class="d-flex justify-content-between mt-1">
                                    <small id="force-label" class="fw-semibold"></small>
                                    <small id="force-manque" class="text-muted"></small>
                                </div>
                                <!-- Critères détaillés -->
                                <ul class="list-unstyled mt-2 mb-0 small" id="force-criteres">
                                    <li id="c-long">  <i class="bi bi-x-circle-fill text-danger me-1"></i>8 caractères minimum</li>
                                    <li id="c-maj">   <i class="bi bi-x-circle-fill text-danger me-1"></i>Une majuscule</li>
                                    <li id="c-chiffre"><i class="bi bi-x-circle-fill text-danger me-1"></i>Un chiffre</li>
                                    <li id="c-spec">  <i class="bi bi-x-circle-fill text-danger me-1"></i>Un caractère spécial (!@#$...)</li>
                                    <li id="c-notemp"><i class="bi bi-x-circle-fill text-danger me-1"></i>Ne commence pas par TREC-</li>
                                </ul>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-secondary">
                                    <i class="bi bi-key-fill"></i>
                                </span>
                                <input type="password" name="mdp2" id="mdp2"
                                       class="form-control border-start-0 ps-0 shadow-none"
                                       placeholder="Confirmer le mot de passe" minlength="8" required>
                                <button class="btn btn-outline-secondary border-start-0" type="button" onclick="toggleMdp('mdp2', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="d-grid mb-3">
                            <button type="submit" name="reset_submit" class="btn btn-dark btn-lg fw-bold shadow-sm py-2">
                                <i class="bi bi-check-lg me-2"></i>Enregistrer le mot de passe
                            </button>
                        </div>
                    </form>

                    <?php endif; ?>

                    <div class="text-center mt-3">
                        <a href="login.php" class="text-decoration-none small text-muted">
                            <i class="bi bi-arrow-left me-1"></i>Retour à la connexion
                        </a>
                    </div>

<?php else: ?>
    <!-- ═══════════════════ VUE : CONNEXION (par défaut) ════════════════ -->

                    <div class="text-center mb-4">
                        <div class="bg-warning-subtle text-warning rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 70px; height: 70px;">
                            <i class="bi bi-shield-lock-fill fs-2"></i>
                        </div>
                        <h2 class="fw-bold font-title text-dark">Connexion</h2>
                        <p class="text-muted small">Accès réservé aux membres</p>
                    </div>

                    <?php if (isset($_GET['inscription']) && $_GET['inscription'] === 'succes'): ?>
                        <div class="alert alert-warning d-flex align-items-center small mb-4" role="alert">
                            <i class="bi bi-hourglass-split me-2 fs-5"></i>
                            <div>
                                <strong>Inscription enregistrée !</strong><br>
                                Votre compte est en attente de validation par un or. Vous recevrez un mail dès qu'il sera activé.
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_GET['reset']) && $_GET['reset'] === 'ok'): ?>
                        <div class="alert alert-success d-flex align-items-center small mb-4" role="alert">
                            <i class="bi bi-check-circle-fill me-2 fs-5"></i>
                            <div>
                                <?php if (isset($_GET['from']) && $_GET['from'] === 'temp'): ?>
                                    <strong>Mot de passe mis à jour !</strong> Vous pouvez maintenant vous connecter avec votre nouveau mot de passe.
                                <?php else: ?>
                                    <strong>Mot de passe mis à jour !</strong> Vous pouvez maintenant vous connecter.
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php
                    if (isset($_GET['error'])) {
                        $error_messages = [
                            '1'              => 'Identifiant ou mot de passe incorrect.',
                            '2'              => 'Compte utilisateur introuvable.',
                            '3'              => 'Accès restreint. Veuillez vous identifier.',
                            'compte_attente' => 'Votre compte est en attente de validation par un organisateur.',
                            'compte_refuse'  => 'Votre demande de compte a été refusée. Contactez l\'organisateur.',
                            'totp'           => 'Code d\'authentification incorrect. Réessayez.',
                        ];
                        $error_code  = htmlspecialchars($_GET['error']);
                        $message     = $error_messages[$error_code] ?? 'Erreur inconnue.';
                        $is_warning  = in_array($error_code, ['compte_attente', 'compte_refuse']);
                        $alert_class = $is_warning ? 'alert-warning' : 'alert-danger';
                        $icon_class  = $is_warning ? 'bi-hourglass-split' : 'bi-exclamation-triangle-fill';
                        echo '
                        <div class="alert ' . $alert_class . ' d-flex align-items-center justify-content-center small mb-4" role="alert">
                            <i class="bi ' . $icon_class . ' me-2"></i>
                            <div>' . $message . '</div>
                        </div>';
                    }
                    ?>

                    <form method="POST" action="traitement_auth.php">
                        <div class="mb-3">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-secondary">
                                    <i class="bi bi-person"></i>
                                </span>
                                <input type="text" id="login" name="login"
                                       class="form-control border-start-0 ps-0 shadow-none"
                                       placeholder="Identifiant" required autofocus>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-secondary">
                                    <i class="bi bi-key"></i>
                                </span>
                                <input type="password" id="mdp" name="password_hash"
                                    class="form-control border-start-0 ps-0 shadow-none"
                                    placeholder="Mot de passe" required>
                                <button class="btn btn-outline-secondary border-start-0" type="button" onclick="toggleMdp('mdp', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="d-grid mb-3">
                            <button type="submit" name="connexion" class="btn btn-dark btn-lg fw-bold shadow-sm py-2">
                                Se connecter
                            </button>
                        </div>

                        <div class="text-center mb-2">
                            <a href="login.php?action=forgot" class="text-decoration-none small text-warning fw-semibold">
                                <i class="bi bi-question-circle me-1"></i>Mot de passe oublié ?
                            </a>
                        </div>

                        <div class="text-center mt-3">
                            <a href="../../index.php" class="text-decoration-none small text-muted">
                                <i class="bi bi-arrow-left me-1"></i>Retour à l'accueil
                            </a>
                        </div>
                    </form>

<?php endif; ?>

                </div>
            </div>

            <div class="text-center mt-4 text-muted small">
                Projet TREC &copy; 2026 – BTS CIEL
            </div>
        </div>
    </main>

<?php include '../footer.php'; ?>

<script>
function toggleMdp(id, btn) {
    const input = document.getElementById(id);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
}

function evaluerMdp(val) {
    const wrap = document.getElementById('force-wrap');
    if (!val) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'block';

    // Critères
    const criteres = {
        'c-long':   val.length >= 8,
        'c-maj':    /[A-Z]/.test(val),
        'c-chiffre':/[0-9]/.test(val),
        'c-spec':   /[^A-Za-z0-9]/.test(val),
        'c-notemp': !val.startsWith('TREC-'),
    };

    // Mise à jour des icônes
    for (const [id, ok] of Object.entries(criteres)) {
        const li = document.getElementById(id);
        const icon = li.querySelector('i');
        if (ok) {
            icon.className = 'bi bi-check-circle-fill text-success me-1';
        } else {
            icon.className = 'bi bi-x-circle-fill text-danger me-1';
        }
    }

    // Score
    const score = Object.values(criteres).filter(Boolean).length;
    const bar   = document.getElementById('force-bar');
    const label = document.getElementById('force-label');
    const manque = document.getElementById('force-manque');

    const niveaux = [
        { pct: 20,  color: 'bg-danger',  texte: 'Très faible' },
        { pct: 40,  color: 'bg-danger',  texte: 'Faible'      },
        { pct: 60,  color: 'bg-warning', texte: 'Moyen'       },
        { pct: 80,  color: 'bg-info',    texte: 'Bon'         },
        { pct: 100, color: 'bg-success', texte: 'Excellent'   },
    ];

    const n = niveaux[score - 1] ?? niveaux[0];
    bar.style.width    = n.pct + '%';
    bar.className      = 'progress-bar ' + n.color;
    label.textContent  = n.texte;
    label.className    = 'fw-semibold ' + n.color.replace('bg-', 'text-');

    const restants = 5 - score;
    manque.textContent = restants > 0 ? restants + ' critère(s) manquant(s)' : '✓ Tous les critères sont remplis';
}
</script>

</body>
</html>