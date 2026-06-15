<?php
// 1. Configuration et Sécurité
require '../config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'organisateur') {
    header('Location: ../auth/login.php'); 
    exit;
}

// 2. Fonctions utilitaires
function prepare_exec($conn, $sql, $types, ...$params) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    return $stmt;
}

function redirect($url) { 
    header("Location: $url"); 
    exit; 
}

// 3. Fonctions d'envoi d'emails
function send_mail(string $to, string $sujet, string $html_body): void {
    $boundary = md5(uniqid());
    $headers  = implode("\r\n", [
        'From: TREC Cheniménil <noreply@trec-competition.fr>',
        'Reply-To: noreply@trec-competition.fr',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: PHP/' . PHP_VERSION,
    ]);
    $text_body = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html_body));
    $body  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text_body)) . "\r\n";
    $body .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html_body)) . "\r\n--$boundary--";
    mail($to, '=?UTF-8?B?' . base64_encode($sujet) . '?=', $body, $headers);
}

function mail_validation(string $prenom, string $nom): string {
    return '<!DOCTYPE html><html lang="fr"><body style="font-family:Arial,sans-serif;background:#f4f6f8;padding:20px;">
    <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;">
        <div style="background:#1a1a2e;padding:28px 32px;text-align:center;"><h1 style="color:#f9c74f;margin:0;">🏇 TREC Cheniménil</h1></div>
        <div style="padding:32px;"><h2 style="color:#1a1a2e;margin-top:0;">Compte activé ✅</h2>
            <p>Bonjour <strong>' . htmlspecialchars($prenom . ' ' . $nom) . '</strong>,</p>
            <p>Votre compte a été <strong style="color:#10b981;">validé</strong>.</p>
            <div style="text-align:center;margin:28px 0;"><a href="https://trec88000.alwaysdata.net/projetTREC/auth/login.php" style="background:#f59e0b;color:#fff;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:bold;">Se connecter</a></div>
        </div>
    </div></body></html>';
}

function mail_refus(string $prenom, string $nom): string {
    return '<!DOCTYPE html><html lang="fr"><body style="font-family:Arial,sans-serif;background:#f4f6f8;padding:20px;">
    <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;">
        <div style="background:#1a1a2e;padding:28px 32px;text-align:center;"><h1 style="color:#f9c74f;margin:0;">🏇 TREC Cheniménil</h1></div>
        <div style="padding:32px;"><h2 style="color:#1a1a2e;margin-top:0;">Demande refusée ❌</h2>
            <p>Bonjour <strong>' . htmlspecialchars($prenom . ' ' . $nom) . '</strong>,</p>
            <p>Votre demande n\'a <strong style="color:#ef4444;">pas été acceptée</strong>.</p>
            <div style="background:#fef2f2;border-left:4px solid #ef4444;padding:12px 16px;"><p style="color:#b91c1c;margin:0;">📧 contact@trec-competition.fr</p></div>
        </div>
    </div></body></html>';
}

// 4. Endpoint AJAX : poll messages éphémères (appelé depuis cette même page)
if (isset($_GET['action']) && $_GET['action'] === 'poll_messages_ephemeres') {
    header('Content-Type: application/json; charset=utf-8');
    $fichier  = sys_get_temp_dir() . '/trec_messages_ephemeres.json';
    $nouveaux = [];
    if (file_exists($fichier)) {
        $fp = fopen($fichier, 'c+');
        if (flock($fp, LOCK_EX)) {
            $contenu = '';
            rewind($fp);
            while (!feof($fp)) $contenu .= fread($fp, 8192);
            $messages = json_decode($contenu, true);
            if (is_array($messages)) {
                $now    = time();
                $garder = [];
                foreach ($messages as $m) {
                    if (($now - ($m['timestamp'] ?? 0)) >= 300) continue; // expirés > 5 min
                    if (empty($m['lu'])) {
                        $nouveaux[] = $m;
                        $m['lu']    = true;
                    }
                    $garder[] = $m;
                }
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($garder, JSON_UNESCAPED_UNICODE));
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
    echo json_encode(['ok' => true, 'messages' => $nouveaux, 'count' => count($nouveaux)]);
    exit;
}

// Endpoint AJAX : effacer tous les messages (organisateur uniquement)
if (isset($_GET['action']) && $_GET['action'] === 'clear_messages_ephemeres') {
    header('Content-Type: application/json; charset=utf-8');
    $fichier = sys_get_temp_dir() . '/trec_messages_ephemeres.json';
    if (file_exists($fichier)) {
        file_put_contents($fichier, json_encode([], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// Endpoint AJAX : poll comptes en attente
if (isset($_GET['action']) && $_GET['action'] === 'poll_comptes_attente') {
    header('Content-Type: application/json; charset=utf-8');
    $result = mysqli_query($conn, "
        SELECT COUNT(*) AS nb 
        FROM utilisateur 
        WHERE statut = 'en_attente'
    ");
    $row = mysqli_fetch_assoc($result);
    echo json_encode(['ok' => true, 'nb' => intval($row['nb'])]);
    exit;
}

// Endpoint AJAX : historique complet (lus + non lus)
if (isset($_GET['action']) && $_GET['action'] === 'poll_historique') {
    header('Content-Type: application/json; charset=utf-8');
    $fichier = sys_get_temp_dir() . '/trec_messages_ephemeres.json';
    $messages = [];
    if (file_exists($fichier)) {
        $decoded = json_decode(file_get_contents($fichier), true);
        if (is_array($decoded)) {
            $now = time();
            $messages = array_values(array_filter(
                $decoded,
                fn($m) => ($now - ($m['timestamp'] ?? 0)) < 300
            ));
        }
    }
    echo json_encode(['ok' => true, 'messages' => $messages]);
    exit;
}

// 5. Endpoint AJAX : envoi message éphémère (appelé depuis espace_cavalier via fetch)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message_ephemere') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['id_utilisateur'])) {
        echo json_encode(['ok' => false, 'error' => 'Non connecté']); exit;
    }
    $sujets_ok = ['urgence', 'question', 'probleme', 'information'];
    $sujet     = trim($_POST['sujet']   ?? '');
    $message   = trim($_POST['message'] ?? '');
    if (!in_array($sujet, $sujets_ok, true)) {
        echo json_encode(['ok' => false, 'error' => 'Sujet invalide']); exit;
    }
    if (mb_strlen($message) < 3 || mb_strlen($message) > 500) {
        echo json_encode(['ok' => false, 'error' => 'Message invalide (3–500 caractères)']); exit;
    }
    $id_util = intval($_SESSION['id_utilisateur']);
    $stmt = mysqli_prepare($conn, "SELECT nom_cavalier, prenom_cavalier FROM cavalier WHERE id_utilisateur = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id_util);
    mysqli_stmt_execute($stmt);
    $cav = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $nom_cav = $cav
        ? htmlspecialchars(trim(($cav['prenom_cavalier'] ?? '') . ' ' . ($cav['nom_cavalier'] ?? '')))
        : 'Cavalier #' . $id_util;
    $sujet_labels = ['urgence'=>'🚨 Urgence','question'=>'❓ Question','probleme'=>'⚠️ Problème','information'=>'ℹ️ Information'];
    $fichier  = sys_get_temp_dir() . '/trec_messages_ephemeres.json';
    $messages = [];
    if (file_exists($fichier)) {
        $decoded = json_decode(file_get_contents($fichier), true);
        if (is_array($decoded)) {
            $messages = array_values(array_filter($decoded, fn($m) => (time() - ($m['timestamp'] ?? 0)) < 300));
        }
    }
    $messages[] = [
        'id'          => uniqid('msg_', true),
        'timestamp'   => time(),
        'sujet'       => $sujet,
        'sujet_label' => $sujet_labels[$sujet],
        'message'     => htmlspecialchars($message),
        'cavalier'    => $nom_cav,
        'lu'          => false,
    ];
    if (file_put_contents($fichier, json_encode($messages, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) {
        echo json_encode(['ok' => true, 'message' => 'Message envoyé à l\'organisateur.']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Erreur serveur, réessayez.']);
    }
    exit;
}

// 6. Traitement des requêtes (CRUD utilisateurs)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'edit_user') {
        prepare_exec($conn, "UPDATE utilisateur SET nom=?, prenom=?, mail=?, role=? WHERE id_utilisateur=?", "ssssi", trim($_POST['nom']), trim($_POST['prenom']), trim($_POST['mail']), $_POST['role'], intval($_POST['id_utilisateur']));
        redirect('espace_organisateur.php?succes=1');
    }
    if ($action === 'reset_password') {
        prepare_exec($conn, "UPDATE utilisateur SET password_hash=? WHERE id_utilisateur=?", "si", password_hash(trim($_POST['new_password']), PASSWORD_DEFAULT), intval($_POST['id_utilisateur']));
        redirect('espace_organisateur.php?succes=1');
    }
    if ($action === 'valider_compte') {
        $id = intval($_POST['id_utilisateur']);
        prepare_exec($conn, "UPDATE utilisateur SET statut = 'actif' WHERE id_utilisateur = ?", "i", $id);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT mail, prenom, nom FROM utilisateur WHERE id_utilisateur = $id"));
        if ($row) send_mail($row['mail'], 'Votre compte TREC a été activé', mail_validation($row['prenom'], $row['nom']));
        redirect('espace_organisateur.php?succes=1');
    }
    if ($action === 'refuser_compte') {
        $id = intval($_POST['id_utilisateur']);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT mail, prenom, nom FROM utilisateur WHERE id_utilisateur = $id"));
        prepare_exec($conn, "UPDATE utilisateur SET statut = 'refuse' WHERE id_utilisateur = ?", "i", $id);
        if ($row) send_mail($row['mail'], 'Votre demande de compte TREC', mail_refus($row['prenom'], $row['nom']));
        redirect('espace_organisateur.php?succes=1');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete_user') {
    $id_util = intval($_GET['id']);

    // Récupérer le cavalier lié à cet utilisateur
    $stmt = mysqli_prepare($conn, "SELECT id_cavalier FROM cavalier WHERE id_utilisateur = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id_util);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $cav = mysqli_fetch_assoc($res);

    if ($cav) {
        $id_cavalier = $cav['id_cavalier'];
        // 1. Supprimer les inscriptions du cavalier
        prepare_exec($conn, "DELETE FROM inscription WHERE id_cavalier = ?", "i", $id_cavalier);
        // 2. Supprimer le cavalier
        prepare_exec($conn, "DELETE FROM cavalier WHERE id_cavalier = ?", "i", $id_cavalier);
    }

    // 3. Supprimer l'utilisateur
    prepare_exec($conn, "DELETE FROM utilisateur WHERE id_utilisateur = ?", "i", $id_util);
    redirect('espace_organisateur.php?succes=1');
}

// 7. Récupération des données
$competitions = mysqli_fetch_all(mysqli_query($conn, "
    SELECT c.*, 
           COUNT(DISTINCT i.id_inscription) AS nb_inscrits,
           COUNT(DISTINCT CASE WHEN i.statut_inscription = 'confirmee' THEN i.id_inscription END) AS nb_confirmes
    FROM competition c 
    LEFT JOIN inscription i ON c.id_competition = i.id_competition 
    GROUP BY c.id_competition 
    ORDER BY c.date_competition DESC
"), MYSQLI_ASSOC);

$nb_competitions        = count($competitions);
$nb_competitions_ouvertes = count(array_filter($competitions, fn($c) => ($c['statut'] ?? 'draft') === 'open'));

$tous_utilisateurs = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM utilisateur ORDER BY nom ASC"), MYSQLI_ASSOC);
$nb_utilisateurs   = count($tous_utilisateurs);
$nb_cavaliers      = count(array_filter($tous_utilisateurs, fn($u) => $u['role'] === 'cavalier'));
$users_attente     = array_filter($tous_utilisateurs, fn($u) => ($u['statut'] ?? 'actif') === 'en_attente');
$nb_users_attente  = count($users_attente);
?>
<?php include '../header.php'; ?>

<style>
/* ── Messages éphémères ───────────────────────────────────────────────────── */
#btnClocheOrga {
    position: fixed;
    bottom: 28px; right: 28px;
    z-index: 1060;
    width: 54px; height: 54px;
    border-radius: 50%;
    background: #1a6b3c;
    border: none;
    box-shadow: 0 4px 18px rgba(26,107,60,.4);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem; color: #fff;
    cursor: pointer;
    transition: transform .2s, background .2s;
}
#btnClocheOrga:hover { background: #0f4226; transform: scale(1.07); }
#btnClocheOrga .pos-rel { position: relative; display: inline-flex; }
#badgeMsgEphemere {
    display: none;
    position: absolute;
    top: -5px; right: -5px;
    background: #dc3545; color: #fff;
    border-radius: 50%;
    width: 18px; height: 18px;
    font-size: .62rem; font-weight: 700;
    line-height: 18px; text-align: center;
}

#panneauHistorique {
    position: fixed;
    bottom: 92px; right: 28px;
    z-index: 1059;
    width: 340px;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 8px 32px rgba(0,0,0,.16);
    display: none;
    max-height: 420px;
    overflow-y: auto;
}
#panneauHistorique .panneau-header {
    padding: .85rem 1rem .65rem;
    font-weight: 700; font-size: .92rem;
    border-bottom: 1px solid #f0f0f0;
    display: flex; align-items: center; gap: .4rem;
    position: sticky; top: 0; background: #fff;
}
#listeHistorique .hist-item {
    padding: .65rem 1rem;
    border-bottom: 1px solid #f8f8f8;
    font-size: .83rem;
    border-left: 4px solid transparent;
}
#listeHistorique .hist-item.sujet-urgence     { border-left-color: #dc3545; }
#listeHistorique .hist-item.sujet-probleme    { border-left-color: #ffc107; }
#listeHistorique .hist-item.sujet-question    { border-left-color: #0d6efd; }
#listeHistorique .hist-item.sujet-information { border-left-color: #6c757d; }
#listeHistorique .hist-item:last-child        { border-bottom: none; }
#listeHistorique .hist-cavalier { font-weight: 700; }
#listeHistorique .hist-time     { color: #aaa; font-size: .72rem; }

#toastMsgContainer {
    position: fixed;
    bottom: 92px; right: 92px;
    z-index: 1058;
    display: flex; flex-direction: column-reverse;
    gap: 10px;
    max-width: 340px; width: 100%;
}
.toast-msg-ephemere {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 6px 24px rgba(0,0,0,.16);
    overflow: hidden;
    animation: toastIn .22s ease;
    border-left: 5px solid #6c757d;
}
.toast-msg-ephemere.sujet-urgence     { border-left-color: #dc3545; }
.toast-msg-ephemere.sujet-probleme    { border-left-color: #ffc107; }
.toast-msg-ephemere.sujet-question    { border-left-color: #0d6efd; }
.toast-msg-ephemere.sujet-information { border-left-color: #6c757d; }
@keyframes toastIn {
    from { opacity:0; transform: translateX(20px); }
    to   { opacity:1; transform: translateX(0); }
}
.toast-header-msg {
    display: flex; align-items: center; gap: .5rem;
    padding: .55rem 1rem .4rem;
    border-bottom: 1px solid #f0f0f0;
}
.toast-header-msg .cav-name  { font-weight: 700; font-size: .87rem; flex-grow: 1; }
.toast-header-msg .t-time    { font-size: .72rem; color: #aaa; }
.toast-header-msg .t-close   { background:none; border:none; font-size:1rem; color:#aaa; cursor:pointer; padding:0 0 0 .4rem; line-height:1; }
.toast-body-msg { padding: .55rem 1rem .75rem; font-size: .84rem; color: #333; }
.sujet-pill {
    display: inline-block; font-size: .7rem;
    padding: 1px 8px; border-radius: 20px;
    background: #f0f0f0; color: #555;
    margin-bottom: .3rem; font-weight: 600;
}
</style>

<main class="flex-grow-1 bg-light">

    <section class="py-5 bg-dark text-white text-center shadow-sm">
        <div class="container">
            <h1 class="display-5 fw-bold">Espace organisateur</h1>
            <p class="lead mb-0">Gérer les compétitions et valider les comptes utilisateurs</p>
        </div>
    </section>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 main-content">

                <div class="mb-4">
                    <h4 class="fw-bold">Bonjour, <?= htmlspecialchars(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')) ?> 👋</h4>
                    <p class="text-muted mb-0">Bienvenue sur le panneau d'administration TREC.</p>
                </div>

                <?php if (isset($_GET['succes'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle-fill me-2"></i>Opération effectuée avec succès.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- KPIs -->
                <div class="mb-5">
                    <h4 class="mb-3 text-secondary">Tableau de bord</h4>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="card stat-card shadow-sm p-3 text-center">
                                <i class="bi bi-trophy-fill text-warning fs-2"></i>
                                <h3 class="fw-bold mt-2"><?= $nb_competitions ?></h3>
                                <p class="text-muted mb-0">Compétitions</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card stat-card shadow-sm p-3 text-center">
                                <i class="bi bi-check-circle-fill text-success fs-2"></i>
                                <h3 class="fw-bold mt-2"><?= $nb_competitions_ouvertes ?></h3>
                                <p class="text-muted mb-0">Ouvertes</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card stat-card shadow-sm p-3 text-center">
                                <i class="bi bi-people-fill text-primary fs-2"></i>
                                <h3 class="fw-bold mt-2"><?= array_sum(array_map(fn($c) => intval($c['nb_inscrits']), $competitions)) ?></h3>
                                <p class="text-muted mb-0">Inscrits total</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Compétitions -->
                <div class="mb-5">
                    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                        <h4 class="text-secondary mb-0">Compétitions</h4>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary active" onclick="sortCompetitions('date')"><i class="bi bi-calendar me-1"></i>Par date</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="sortCompetitions('nom')"><i class="bi bi-sort-alpha-down me-1"></i>Par nom</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="sortCompetitions('statut')"><i class="bi bi-filter me-1"></i>Par statut</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="sortCompetitions('inscrits')"><i class="bi bi-people me-1"></i>Par inscrits</button>
                        </div>
                    </div>
                    <div class="card card-custom shadow-sm p-0">
                        <table class="table table-hover align-middle mb-0" id="competitionsTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Nom</th><th>Période</th><th>Inscrits</th><th>Confirmés</th><th>Statut</th><th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($competitions)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Aucune compétition.</td></tr>
                            <?php else: foreach ($competitions as $c):
                                $badge_statut = match($c['statut'] ?? 'draft') {
                                    'draft'  => ['bg-secondary', '🔒 Brouillon'],
                                    'open'   => ['bg-success',   '✅ Inscriptions ouvertes'],
                                    'closed' => ['bg-dark',      '🏁 Terminée'],
                                    default  => ['bg-secondary', $c['statut']]
                                };
                                $date_debut = date('d/m/Y H:i', strtotime($c['date_competition'] . ' ' . ($c['heure_debut'] ?? '09:00')));
                                $date_fin   = $c['date_fin_competition'] ? date('d/m/Y H:i', strtotime($c['date_fin_competition'] . ' ' . ($c['heure_fin'] ?? '17:00'))) : '—';
                            ?>
                                <tr data-date="<?= strtotime($c['date_competition']) ?>"
                                    data-nom="<?= htmlspecialchars($c['nom_competition'] ?? '') ?>"
                                    data-statut="<?= $c['statut'] ?? 'draft' ?>"
                                    data-inscrits="<?= intval($c['nb_inscrits']) ?>">
                                    <td class="ps-4 fw-bold"><?= htmlspecialchars($c['nom_competition'] ?? '') ?></td>
                                    <td>
                                        <small class="text-muted"><?= $date_debut ?></small><br>
                                        <small class="text-muted">→ <?= $date_fin ?></small>
                                    </td>
                                    <td><span class="badge bg-primary"><?= intval($c['nb_inscrits']) ?></span></td>
                                    <td><span class="badge bg-success"><?= intval($c['nb_confirmes']) ?></span></td>
                                    <td><span class="badge <?= $badge_statut[0] ?>"><?= $badge_statut[1] ?></span></td>
                                    <td class="text-end pe-4">
                                        <a href="../classement/competition.php?tab=config&edit=<?= $c['id_competition'] ?>" class="btn btn-sm btn-outline-primary" title="Modifier">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Comptes en attente -->
                <div class="mb-5">
                    <div class="d-flex align-items-center gap-2 mb-3" id="comptes-attente">
                    <h4 class="text-secondary mb-0">Comptes en attente de validation</h4>
                    <span id="badgeComptesAttente" 
                        class="badge bg-warning text-dark fs-6"
                        style="display:<?= $nb_users_attente > 0 ? 'inline-block' : 'none' ?>">
                        <?= $nb_users_attente ?>
                    </span>
                </div>
                    <div class="card card-custom shadow-sm <?= $nb_users_attente > 0 ? 'border-warning border-2' : '' ?> p-0">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light" <?= $nb_users_attente > 0 ? 'style="background:#fff8e1;"' : '' ?>>
                                <tr>
                                    <th class="ps-4">Nom</th><th>Prénom</th><th>Login</th><th>Mail</th><th>Rôle</th><th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($users_attente)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Aucun compte en attente.</td></tr>
                            <?php else: foreach ($users_attente as $u): ?>
                                <tr>
                                    <td class="ps-4 fw-bold"><?= htmlspecialchars($u['nom'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($u['prenom'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($u['login']) ?></td>
                                    <td><?= htmlspecialchars($u['mail'] ?? '') ?></td>
                                    <td><span class="badge bg-primary"><?= htmlspecialchars($u['role']) ?></span></td>
                                    <td class="text-end pe-4 d-flex gap-2 justify-content-end">
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Valider ce compte ?')">
                                            <input type="hidden" name="action" value="valider_compte">
                                            <input type="hidden" name="id_utilisateur" value="<?= $u['id_utilisateur'] ?>">
                                            <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Valider</button>
                                        </form>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Refuser ce compte ?')">
                                            <input type="hidden" name="action" value="refuser_compte">
                                            <input type="hidden" name="id_utilisateur" value="<?= $u['id_utilisateur'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg me-1"></i>Refuser</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</main>

<!-- ══ CLOCHE FLOTTANTE ══════════════════════════════════════════════════════ -->
<button id="btnClocheOrga" onclick="toggleHistorique()" title="Messages éphémères des cavaliers">
    <span class="pos-rel">
        <i class="bi bi-bell-fill"></i>
        <span id="badgeMsgEphemere">0</span>
    </span>
</button>

<!-- ══ PANNEAU HISTORIQUE ════════════════════════════════════════════════════ -->
<div id="panneauHistorique">
    <div class="panneau-header">
        <i class="bi bi-megaphone-fill text-danger"></i>
        Messages cavaliers
        <button type="button" class="btn btn-outline-danger btn-sm ms-auto py-0 px-2"
                style="font-size:.7rem" onclick="effacerMessages()"
                title="Effacer tous les messages">
            <i class="bi bi-trash3"></i>
        </button>
        <button type="button" class="btn-close ms-1" style="font-size:.7rem"
                onclick="document.getElementById('panneauHistorique').style.display='none'"></button>
    </div>
    <div id="listeHistorique">
        <div class="text-muted text-center py-3 small">Aucun message reçu</div>
    </div>
</div>

<!-- ══ CONTENEUR TOASTS ══════════════════════════════════════════════════════ -->
<div id="toastMsgContainer"></div>

<!-- ══ MODALES EXISTANTES ════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalFilterUsers" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="espace_organisateur.php" method="GET">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-funnel me-2"></i>Filtrer les utilisateurs</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Rôle</label>
                        <select class="form-select" name="filtre_role">
                            <option value="">— Tous —</option>
                            <option value="cavalier">Cavalier</option>
                            <option value="chef_piste">Chef de piste</option>
                            <option value="organisateur">Organisateur</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Statut du compte</label>
                        <select class="form-select" name="filtre_statut_user">
                            <option value="">— Tous —</option>
                            <option value="actif">Actif</option>
                            <option value="en_attente">En attente</option>
                            <option value="refuse">Refusé</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="espace_organisateur.php" class="btn btn-outline-secondary">Réinitialiser</a>
                    <button type="submit" class="btn btn-warning">Appliquer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditUser" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="espace_organisateur.php" method="POST">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="id_utilisateur" id="user_id">
                <div class="modal-header"><h5 class="modal-title">Modifier l'utilisateur</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label small fw-bold">Nom</label><input type="text" class="form-control" name="nom" id="user_nom"></div>
                    <div class="mb-3"><label class="form-label small fw-bold">Prénom</label><input type="text" class="form-control" name="prenom" id="user_prenom"></div>
                    <div class="mb-3"><label class="form-label small fw-bold">Mail</label><input type="email" class="form-control" name="mail" id="user_mail"></div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Rôle</label>
                        <select class="form-select" name="role" id="user_role">
                            <option value="cavalier">Cavalier</option>
                            <option value="chef_piste">Chef de piste</option>
                            <option value="organisateur">Organisateur</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                    <button type="submit" class="btn btn-warning">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalResetPassword" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="espace_organisateur.php" method="POST">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id_utilisateur" id="reset_user_id">
                <div class="modal-header"><h5 class="modal-title">Réinitialiser le mot de passe</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="text-muted small">Utilisateur : <strong id="reset_user_login"></strong></p>
                    <div class="mb-3"><label class="form-label small fw-bold">Nouveau mot de passe</label><input type="password" class="form-control" name="new_password" minlength="4" required></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                    <button type="submit" class="btn btn-warning">Réinitialiser</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Modales existantes ────────────────────────────────────────────────────────
document.getElementById('modalEditUser').addEventListener('show.bs.modal', e => {
    const b = e.relatedTarget;
    document.getElementById('user_id').value     = b.dataset.id;
    document.getElementById('user_nom').value    = b.dataset.nom;
    document.getElementById('user_prenom').value = b.dataset.prenom;
    document.getElementById('user_mail').value   = b.dataset.mail;
    document.getElementById('user_role').value   = b.dataset.role;
});
document.getElementById('modalResetPassword').addEventListener('show.bs.modal', e => {
    const b = e.relatedTarget;
    document.getElementById('reset_user_id').value          = b.dataset.id;
    document.getElementById('reset_user_login').textContent = b.dataset.login;
});

// ── Tri compétitions ──────────────────────────────────────────────────────────
function sortCompetitions(sortType) {
    const table   = document.getElementById('competitionsTable');
    const tbody   = table.querySelector('tbody');
    const allRows = Array.from(tbody.querySelectorAll('tr[data-date]'));
    document.querySelectorAll('.btn-group .btn').forEach(btn => btn.classList.remove('active'));
    event.target.closest('.btn').classList.add('active');
    let groupedRows = {};
    switch(sortType) {
        case 'date':
            allRows.forEach(row => {
                const month = new Date(parseInt(row.dataset.date)*1000).toLocaleDateString('fr-FR',{month:'long',year:'numeric'});
                if (!groupedRows[month]) groupedRows[month] = [];
                groupedRows[month].push(row);
            }); break;
        case 'nom':
            allRows.forEach(row => {
                const l = row.dataset.nom.charAt(0).toUpperCase();
                if (!groupedRows[l]) groupedRows[l] = [];
                groupedRows[l].push(row);
            }); break;
        case 'statut':
            const sl = {'open':'✅ Inscriptions ouvertes','draft':'🔒 Brouillon','closed':'🏁 Terminée'};
            allRows.forEach(row => {
                const lbl = sl[row.dataset.statut] || row.dataset.statut;
                if (!groupedRows[lbl]) groupedRows[lbl] = [];
                groupedRows[lbl].push(row);
            }); break;
        case 'inscrits':
            allRows.forEach(row => {
                const n = parseInt(row.dataset.inscrits);
                const r = n===0?'0 inscrit':n<=3?'1-3 inscrits':n<=6?'4-6 inscrits':'7+ inscrits';
                if (!groupedRows[r]) groupedRows[r] = [];
                groupedRows[r].push(row);
            }); break;
    }
    tbody.innerHTML = '';
    Object.keys(groupedRows).sort().forEach(group => {
        const hr = document.createElement('tr');
        hr.style.backgroundColor = '#f0f0f0';
        hr.innerHTML = `<td colspan="6" class="fw-bold text-secondary ps-4">${group}</td>`;
        tbody.appendChild(hr);
        groupedRows[group].forEach(row => tbody.appendChild(row));
    });
}

// ── Messages éphémères — polling ──────────────────────────────────────────────
const POLL_URL      = 'espace_organisateur.php?action=poll_messages_ephemeres';
const POLL_INTERVAL = 10000;

let historiqueMessages = [];
let totalNonLus        = 0;

const sujetLabels = {
    urgence:     '🚨 Urgence',
    probleme:    '⚠️ Problème',
    question:    '❓ Question',
    information: 'ℹ️ Information',
};

// ── Polling comptes en attente ─────────────────────────────────────────────
const POLL_ATTENTE_URL = 'espace_organisateur.php?action=poll_comptes_attente';
let dernier_nb_attente = <?= $nb_users_attente ?>; // valeur initiale connue au chargement

function pollComptesAttente() {
    fetch(POLL_ATTENTE_URL, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;
            const nb = data.nb;

            // Nouveau(x) compte(s) arrivé(s) depuis le dernier check
            if (nb > dernier_nb_attente) {
                const diff = nb - dernier_nb_attente;
                afficherToastAttente(diff);
            }
            dernier_nb_attente = nb;

            // Mettre à jour le badge dans le titre de la section si besoin
            const badgeSection = document.getElementById('badgeComptesAttente');
            if (badgeSection) {
                badgeSection.textContent = nb;
                badgeSection.style.display = nb > 0 ? 'inline-block' : 'none';
            }
        })
        .catch(() => {});
}

function afficherToastAttente(nb) {
    const toast = document.createElement('div');
    toast.className = 'toast-msg-ephemere sujet-urgence';
    toast.innerHTML = `
        <div class="toast-header-msg">
            <span class="cav-name"><i class="bi bi-person-plus-fill me-1"></i>Nouveau compte en attente</span>
            <button class="t-close" onclick="this.closest('.toast-msg-ephemere').remove()">×</button>
        </div>
        <div class="toast-body-msg">
            <div class="sujet-pill">👤 Validation requise</div>
            <div>${nb} nouveau${nb > 1 ? 'x' : ''} compte${nb > 1 ? 's' : ''} 
            en attente — <a href="espace_organisateur.php#comptes-attente">voir</a></div>
        </div>`;
    document.getElementById('toastMsgContainer').prepend(toast);
    setTimeout(() => { if (toast.parentNode) toast.remove(); }, 15000);
}

pollComptesAttente();
setInterval(pollComptesAttente, 30000); // toutes les 30s

function pollMessages() {
    fetch(POLL_URL, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (!data.ok || data.count === 0) return;
            data.messages.forEach(afficherMessage);
        })
        .catch(() => {});
}

function afficherMessage(msg) {
    historiqueMessages.unshift(msg);
    totalNonLus++;
    majBadge();
    majHistorique();

    const toast = document.createElement('div');
    toast.className = 'toast-msg-ephemere sujet-' + msg.sujet;
    const heure = new Date(msg.timestamp * 1000).toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'});
    toast.innerHTML = `
        <div class="toast-header-msg">
            <span class="cav-name"><i class="bi bi-person-fill me-1"></i>${msg.cavalier}</span>
            <span class="t-time">${heure}</span>
            <button class="t-close" onclick="this.closest('.toast-msg-ephemere').remove()">×</button>
        </div>
        <div class="toast-body-msg">
            <div class="sujet-pill">${sujetLabels[msg.sujet] ?? msg.sujet_label}</div>
            <div>${msg.message}</div>
        </div>`;
    document.getElementById('toastMsgContainer').prepend(toast);
    const ttl = msg.sujet === 'urgence' ? 20000 : 12000;
    setTimeout(() => { if (toast.parentNode) toast.remove(); }, ttl);
}

function majBadge() {
    const badge = document.getElementById('badgeMsgEphemere');
    const icon  = document.querySelector('#btnClocheOrga i');
    if (totalNonLus > 0) {
        badge.textContent    = totalNonLus > 9 ? '9+' : totalNonLus;
        badge.style.display  = 'block';
        icon.style.animation = 'none'; // pas de rotation, juste le badge suffit
    } else {
        badge.style.display = 'none';
    }
}

function majHistorique() {
    const liste = document.getElementById('listeHistorique');
    if (historiqueMessages.length === 0) {
        liste.innerHTML = '<div class="text-muted text-center py-3 small">Aucun message reçu</div>';
        return;
    }
    liste.innerHTML = historiqueMessages.map(msg => {
        const heure = new Date(msg.timestamp * 1000).toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'});
        return `<div class="hist-item sujet-${msg.sujet}">
            <div class="d-flex justify-content-between align-items-center">
                <span class="hist-cavalier">${msg.cavalier}</span>
                <span class="hist-time">${heure}</span>
            </div>
            <div class="sujet-pill mt-1">${sujetLabels[msg.sujet] ?? msg.sujet_label}</div>
            <div class="mt-1">${msg.message}</div>
        </div>`;
    }).join('');
}

function effacerMessages() {
    if (!confirm('Effacer tous les messages ?')) return;
    fetch('espace_organisateur.php?action=clear_messages_ephemeres', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(() => {
            historiqueMessages = [];
            totalNonLus        = 0;
            majBadge();
            majHistorique();
            // Supprimer les toasts visibles
            document.querySelectorAll('.toast-msg-ephemere').forEach(t => t.remove());
        });
}

function toggleHistorique() {
    const p       = document.getElementById('panneauHistorique');
    const visible = p.style.display === 'block';
    p.style.display = visible ? 'none' : 'block';
    if (!visible) { totalNonLus = 0; majBadge(); }
}

document.addEventListener('click', function(e) {
    const p   = document.getElementById('panneauHistorique');
    const btn = document.getElementById('btnClocheOrga');
    if (p.style.display === 'block' && !p.contains(e.target) && !btn.contains(e.target)) {
        p.style.display = 'none';
    }
});

fetch('espace_organisateur.php?action=poll_historique', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) return;
        data.messages.forEach(msg => {
            historiqueMessages.push(msg);
            if (!msg.lu) totalNonLus++;
        });
        historiqueMessages.sort((a, b) => b.timestamp - a.timestamp);
        majBadge();
        majHistorique();
    })
    .catch(() => {});

pollMessages();
setInterval(pollMessages, POLL_INTERVAL);
</script>
</body>
</html>