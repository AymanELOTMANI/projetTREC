<?php
// 1. Initialisation et Sécurité
session_start();
if (!isset($_SESSION['id_utilisateur'])) {
    header('Location: login.php');
    exit();
}
require_once __DIR__ . '/../config.php';

$id_utilisateur = intval($_SESSION['id_utilisateur']);
$message_retour = '';
$type_message   = '';

// ─── Endpoint AJAX : envoi message éphémère ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'send_message_ephemere') {

    header('Content-Type: application/json; charset=utf-8');

    $sujets_ok = ['urgence', 'question', 'probleme', 'information'];
    $sujet     = trim($_POST['sujet']   ?? '');
    $message   = trim($_POST['message'] ?? '');

    if (!in_array($sujet, $sujets_ok, true)) {
        echo json_encode(['ok' => false, 'error' => 'Sujet invalide']); exit;
    }
    if (mb_strlen($message) < 3 || mb_strlen($message) > 500) {
        echo json_encode(['ok' => false, 'error' => 'Message invalide (3–500 caractères)']); exit;
    }

    $stmt_msg = mysqli_prepare($conn, "SELECT nom_cavalier, prenom_cavalier FROM cavalier WHERE id_utilisateur = ?");
    mysqli_stmt_bind_param($stmt_msg, 'i', $id_utilisateur);
    mysqli_stmt_execute($stmt_msg);
    $cav_msg = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_msg));
    $nom_cav = $cav_msg
        ? htmlspecialchars(trim(($cav_msg['prenom_cavalier'] ?? '') . ' ' . ($cav_msg['nom_cavalier'] ?? '')))
        : 'Cavalier #' . $id_utilisateur;

    $sujet_labels = ['urgence'=>'🚨 Urgence','question'=>'❓ Question','probleme'=>'⚠️ Problème','information'=>'ℹ️ Information'];

    $fichier  = sys_get_temp_dir() . '/trec_messages_ephemeres.json';
    $messages = [];
    if (file_exists($fichier)) {
        $decoded = json_decode(file_get_contents($fichier), true);
        if (is_array($decoded)) {
            $messages = array_values(array_filter($decoded, fn($m) => (time() - ($m['timestamp'] ?? 0)) < 300));
        }
    }

    // ── Limite anti-spam : 5 messages max par cavalier dans la file ───────────
    $msgs_cavalier = array_filter($messages, fn($m) => ($m['id_utilisateur'] ?? 0) === $id_utilisateur);
    if (count($msgs_cavalier) >= 5) {
        echo json_encode(['ok' => false, 'error' => "Limite atteinte : 5 messages maximum en attente. Attendez que l'organisateur les lise."]);
        exit;
    }

    $messages[] = [
        'id_utilisateur' => $id_utilisateur,
        'id'          => uniqid('msg_', true),
        'timestamp'   => time(),
        'sujet'       => $sujet,
        'sujet_label' => $sujet_labels[$sujet],
        'message'     => htmlspecialchars($message),
        'cavalier'    => $nom_cav,
        'lu'          => false,
    ];

    if (file_put_contents($fichier, json_encode($messages, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) {
        echo json_encode(['ok' => true, 'message' => "Message envoyé à l'organisateur."]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Erreur serveur, réessayez.']);
    }
    exit;
}

// ─── Traitement : Modification du profil ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'modifier_profil') {

    $nom    = trim($_POST['nom']    ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $adresse = trim($_POST['adresse'] ?? '') ?: null;

    if (empty($nom) || empty($prenom)) {
        $message_retour = 'Le nom et le prénom sont obligatoires.';
        $type_message   = 'danger';
    } else {
        $stmt_upd = mysqli_prepare($conn,
            "UPDATE cavalier SET nom_cavalier = ?, prenom_cavalier = ?, adresse = ? WHERE id_utilisateur = ?"
        );
        mysqli_stmt_bind_param($stmt_upd, 'sssi', $nom, $prenom, $adresse, $id_utilisateur);
        mysqli_stmt_execute($stmt_upd);

        // Sync nom/prénom dans utilisateur aussi
        $stmt_upd2 = mysqli_prepare($conn,
            "UPDATE utilisateur SET nom = ?, prenom = ? WHERE id_utilisateur = ?"
        );
        mysqli_stmt_bind_param($stmt_upd2, 'ssi', $nom, $prenom, $id_utilisateur);
        mysqli_stmt_execute($stmt_upd2);

        $_SESSION['succes_profil'] = 'Profil mis à jour avec succès.';
        header('Location: espace_cavalier.php');
        exit;
    }
}

// Filtres épreuves par inscription (GET)
$filtres_types_autorises = ['ALL', 'POR', 'MA', 'PTV'];
$filtres_actifs = [];
if (isset($_GET['filtre']) && is_array($_GET['filtre'])) {
    foreach ($_GET['filtre'] as $id_ins => $type) {
        $id_ins = intval($id_ins);
        if ($id_ins > 0 && in_array($type, $filtres_types_autorises, true)) {
            $filtres_actifs[$id_ins] = $type;
        }
    }
}

// 2. Traitement : Inscription à une compétition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'demande_inscription') {
    $id_competition = intval($_POST['id_competition']);

    $stmt = mysqli_prepare($conn, "SELECT c.*, u.mail FROM cavalier c JOIN utilisateur u ON c.id_utilisateur = u.id_utilisateur WHERE c.id_utilisateur = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id_utilisateur);
    mysqli_stmt_execute($stmt);
    $cavalier_form = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$cavalier_form) {
        $message_retour = "Attendez la confirmation de votre profil";
        $type_message   = 'danger';
    } else {
        $id_cavalier = intval($cavalier_form['id_cavalier']);

        $stmt2 = mysqli_prepare($conn, "SELECT statut_inscription FROM inscription WHERE id_cavalier = ? AND id_competition = ?");
        mysqli_stmt_bind_param($stmt2, 'ii', $id_cavalier, $id_competition);
        mysqli_stmt_execute($stmt2);
        $inscrit = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt2));

        if ($inscrit) {
            $message_retour = "Vous avez déjà une inscription (statut : " . htmlspecialchars($inscrit['statut_inscription']) . ") pour cette compétition.";
            $type_message   = 'warning';
        } else {
            $stmt3 = mysqli_prepare($conn, "INSERT INTO inscription (id_competition, id_cavalier, date_inscription, statut_inscription) VALUES (?, ?, NOW(), 'en_attente')");
            mysqli_stmt_bind_param($stmt3, 'ii', $id_competition, $id_cavalier);
            mysqli_stmt_execute($stmt3);

            $stmt4 = mysqli_prepare($conn, "SELECT nom_competition, date_competition FROM competition WHERE id_competition = ?");
            mysqli_stmt_bind_param($stmt4, 'i', $id_competition);
            mysqli_stmt_execute($stmt4);
            $competition = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt4));

            $admin = mysqli_fetch_assoc(mysqli_query($conn, "SELECT mail FROM utilisateur WHERE role = 'organisateur' LIMIT 1"));

            if ($admin && $competition) {
                $sujet   = "Nouvelle demande inscription TREC - " . $competition['nom_competition'];
                $corps   = "Bonjour,\n\nLe cavalier suivant souhaite s'inscrire :\nNom : {$cavalier_form['nom_cavalier']} {$cavalier_form['prenom_cavalier']}\nAdresse : {$cavalier_form['adresse']}\nCategorie : {$cavalier_form['categorie']}\nEmail : {$cavalier_form['mail']}\n\nCompetition : {$competition['nom_competition']}\nConnectez-vous pour valider.";
                $headers = "From: noreply@trec-competition.fr\r\nReply-To: {$cavalier_form['mail']}\r\nContent-Type: text/plain; charset=UTF-8";

                if (mail($admin['mail'], $sujet, $corps, $headers)) {
                    $message_retour = "Votre demande a bien été envoyée.";
                    $type_message   = 'success';
                } else {
                    $message_retour = "Demande enregistrée, mais l'envoi email a échoué.";
                    $type_message   = 'warning';
                }
            }
        }
    }
}

// 3. Traitement : Inscription à des épreuves spécifiques
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'demande_inscription_epreuves') {
    $id_inscription    = intval($_POST['id_inscription']);
    $epreuves_choisies = isset($_POST['epreuves']) ? array_map('intval', (array)$_POST['epreuves']) : [];

    if (empty($epreuves_choisies)) {
        $message_retour = "Veuillez sélectionner au moins une épreuve.";
        $type_message   = 'warning';
    } else {
        $stmt_chk = mysqli_prepare($conn, "SELECT i.id_inscription, i.id_competition FROM inscription i JOIN cavalier c ON i.id_cavalier = c.id_cavalier WHERE i.id_inscription = ? AND c.id_utilisateur = ?");
        mysqli_stmt_bind_param($stmt_chk, 'ii', $id_inscription, $id_utilisateur);
        mysqli_stmt_execute($stmt_chk);
        $ins_ok = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_chk));

        if (!$ins_ok) {
            $message_retour = "Inscription invalide ou non autorisée.";
            $type_message   = 'danger';
        } else {
            $nb_ajout = $nb_doublon = 0;

            foreach ($epreuves_choisies as $id_ep) {
                $stmt_v = mysqli_prepare($conn, "SELECT id_epreuve FROM epreuve WHERE id_epreuve = ? AND id_competition = ?");
                mysqli_stmt_bind_param($stmt_v, 'ii', $id_ep, $ins_ok['id_competition']);
                mysqli_stmt_execute($stmt_v);

                if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_v))) {
                    $stmt_check = mysqli_prepare($conn, "SELECT id_inscription_epreuve FROM inscription_epreuve WHERE id_inscription = ? AND id_epreuve = ?");
                    mysqli_stmt_bind_param($stmt_check, 'ii', $id_inscription, $id_ep);
                    mysqli_stmt_execute($stmt_check);

                    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_check))) {
                        $nb_doublon++;
                    } else {
                        $stmt_add = mysqli_prepare($conn, "INSERT INTO inscription_epreuve (id_inscription, id_epreuve, statut_epreuve, date_inscription_epreuve) VALUES (?, ?, 'en_attente', NOW())");
                        mysqli_stmt_bind_param($stmt_add, 'ii', $id_inscription, $id_ep);
                        if (mysqli_stmt_execute($stmt_add)) $nb_ajout++;
                    }
                }
            }

            if ($nb_ajout > 0) {
                $message_retour = "$nb_ajout épreuve(s) ajoutée(s). En attente de validation." . ($nb_doublon > 0 ? " ($nb_doublon déjà inscrit)" : "");
                $type_message   = 'success';
            } elseif ($nb_doublon > 0) {
                $message_retour = "Vous êtes déjà inscrit à ces épreuves.";
                $type_message   = 'info';
            } else {
                $message_retour = "Aucune épreuve ajoutée.";
                $type_message   = 'warning';
            }
        }
    }
}


// 4. Récupération des données du Cavalier
$stmt_cav = mysqli_prepare($conn, "SELECT c.*, u.mail FROM cavalier c JOIN utilisateur u ON c.id_utilisateur = u.id_utilisateur WHERE c.id_utilisateur = ?");
mysqli_stmt_bind_param($stmt_cav, 'i', $id_utilisateur);
mysqli_stmt_execute($stmt_cav);
$cavalier = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_cav));

if (!$cavalier) {
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>TREC – Profil en attente</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    </head>
    <body class="bg-light d-flex align-items-center justify-content-center min-vh-100">
        <div class="text-center px-3" style="max-width: 480px;">
            <div class="bg-white rounded-4 shadow p-5">
                <div class="mb-3">
                    <span class="display-1">⏳</span>
                </div>
                <h2 class="fw-bold mb-2" style="color: #1a6b3c;">Profil en attente</h2>
                <p class="text-muted mb-4">
                    Votre profil cavalier est en cours de validation par l'organisateur.<br>
                    Revenez une fois votre compte confirmé.
                </p>
                <div class="alert alert-warning d-flex align-items-center gap-2 text-start" role="alert">
                    <i class="bi bi-hourglass-split fs-5 flex-shrink-0"></i>
                    <div>Attendez la confirmation de votre profil avant d'accéder à cet espace.</div>
                </div>
                <a href="login.php" class="btn btn-success mt-2 px-4">
                    <i class="bi bi-box-arrow-left me-2"></i>Retour à la connexion
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}
$id_cavalier = intval($cavalier['id_cavalier']);

// Dossard
$dossard = null;
if (!empty($cavalier['id_dossard'])) {
    $stmt_dos = mysqli_prepare($conn, "SELECT tag_rfid, numero_dossard FROM dossard WHERE id_dossard = ?");
    mysqli_stmt_bind_param($stmt_dos, 'i', $cavalier['id_dossard']);
    mysqli_stmt_execute($stmt_dos);
    $dossard = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_dos));
}

$filtre_type = isset($_GET['filtre_type']) ? strtoupper(trim($_GET['filtre_type'])) : 'ALL';

// 5. Résultats du cavalier ─────────────────────────────────────────────────────
// POR depuis passage
$resultats_por     = [];
$por_epreuves_vues = [];

$stmt_por = mysqli_prepare($conn, "
    SELECT c2.nom_competition, c2.date_competition,
           e.nom_epreuve, e.type_epreuve, e.temps_ideal,
           ROUND(TIME_TO_SEC(TIMEDIFF(p.fin, p.debut)) / 60, 4) AS temps_reel_min,
           NULL AS score, NULL AS penalites, 'passage' AS source
    FROM passage p
    JOIN dossard d      ON d.id_dossard     = p.id_dossard
    JOIN inscription i  ON i.id_dossard     = d.id_dossard
    JOIN competition c2 ON c2.id_competition = i.id_competition
    LEFT JOIN epreuve e ON e.id_epreuve      = p.id_epreuve
    WHERE i.id_cavalier = ?
      AND p.statut = 'termine'
      AND p.fin IS NOT NULL AND p.debut IS NOT NULL
      AND e.type_epreuve = 'POR'
    ORDER BY c2.date_competition DESC, e.type_epreuve ASC
");
mysqli_stmt_bind_param($stmt_por, 'i', $id_cavalier);
mysqli_stmt_execute($stmt_por);
$res_por = mysqli_stmt_get_result($stmt_por);
while ($row = mysqli_fetch_assoc($res_por)) {
    if ($row['temps_ideal'] !== null && $row['temps_reel_min'] !== null) {
        $ecart_s          = (int)round(($row['temps_reel_min'] - (float)$row['temps_ideal']) * 60);
        $pen              = (int)floor(abs($ecart_s) / 4);
        $row['score']     = max(0, 200 - $pen);
        $row['penalites'] = $pen;
        $row['ecart_s']   = $ecart_s;
    } else {
        $row['ecart_s'] = null;
    }
    $key = $row['nom_competition'] . '|' . $row['nom_epreuve'];
    $por_epreuves_vues[$key] = true;
    $resultats_por[] = $row;
}

// MA + PTV + POR fallback depuis resultat
$resultats_autres = [];
$stmt_autres = mysqli_prepare($conn, "
    SELECT c2.nom_competition, c2.date_competition,
           e.nom_epreuve, e.type_epreuve, e.temps_ideal,
           r.temps_total AS temps_reel_min, r.score, r.penalites,
           NULL AS ecart_s, 'resultat' AS source
    FROM resultat r
    JOIN epreuve e      ON e.id_epreuve      = r.id_epreuve
    JOIN inscription i  ON i.id_cavalier     = r.id_cavalier
                       AND i.id_competition  = e.id_competition
    JOIN competition c2 ON c2.id_competition = i.id_competition
    WHERE r.id_cavalier = ?
    ORDER BY c2.date_competition DESC, e.type_epreuve ASC
");
mysqli_stmt_bind_param($stmt_autres, 'i', $id_cavalier);
mysqli_stmt_execute($stmt_autres);
$res_autres = mysqli_stmt_get_result($stmt_autres);
while ($row = mysqli_fetch_assoc($res_autres)) {
    if ($row['type_epreuve'] === 'POR') {
        $key = $row['nom_competition'] . '|' . $row['nom_epreuve'];
        if (isset($por_epreuves_vues[$key])) continue;
    }
    $resultats_autres[] = $row;
}

$tous_resultats = array_merge($resultats_por, $resultats_autres);
usort($tous_resultats, function($a, $b) {
    $dc = strcmp($b['date_competition'], $a['date_competition']);
    if ($dc !== 0) return $dc;
    $ordre = ['POR' => 1, 'MA' => 2, 'PTV' => 3];
    return ($ordre[$a['type_epreuve']] ?? 9) <=> ($ordre[$b['type_epreuve']] ?? 9);
});

// Inscriptions
$inscriptions = [];
$stmt_ins = mysqli_prepare($conn, "SELECT i.*, c.nom_competition, c.date_competition FROM inscription i JOIN competition c ON i.id_competition = c.id_competition WHERE i.id_cavalier = ? ORDER BY c.date_competition DESC");
mysqli_stmt_bind_param($stmt_ins, 'i', $id_cavalier);
mysqli_stmt_execute($stmt_ins);
$res_ins = mysqli_stmt_get_result($stmt_ins);
while ($row = mysqli_fetch_assoc($res_ins)) $inscriptions[] = $row;

// Compétitions disponibles
$competitions_dispo = [];
$stmt_comps = mysqli_prepare($conn, "SELECT * FROM competition WHERE statut = 'open' AND id_competition NOT IN (SELECT id_competition FROM inscription WHERE id_cavalier = ?) ORDER BY date_competition ASC");
mysqli_stmt_bind_param($stmt_comps, 'i', $id_cavalier);
mysqli_stmt_execute($stmt_comps);
$res_comps = mysqli_stmt_get_result($stmt_comps);
while ($row = mysqli_fetch_assoc($res_comps)) $competitions_dispo[] = $row;

// Épreuves par inscription
$inscriptions_avec_epreuves = [];
foreach ($inscriptions as $ins) {
    $epreuves = [];
    $stmt_ep = mysqli_prepare($conn, "SELECT e.*, ie.statut_epreuve FROM epreuve e LEFT JOIN inscription_epreuve ie ON ie.id_epreuve = e.id_epreuve AND ie.id_inscription = ? WHERE e.id_competition = ? ORDER BY e.type_epreuve, e.nom_epreuve");
    mysqli_stmt_bind_param($stmt_ep, 'ii', $ins['id_inscription'], $ins['id_competition']);
    mysqli_stmt_execute($stmt_ep);
    $res_ep = mysqli_stmt_get_result($stmt_ep);
    while ($row = mysqli_fetch_assoc($res_ep)) $epreuves[] = $row;
    if (!empty($epreuves)) {
        $inscriptions_avec_epreuves[] = ['inscription' => $ins, 'epreuves' => $epreuves];
    }
}

// Nom du cavalier pour le message éphémère
$nom_cavalier_msg = htmlspecialchars(trim(
    ($cavalier['prenom_cavalier'] ?? '') . ' ' . ($cavalier['nom_cavalier'] ?? '')
));

mysqli_close($conn);

$badge_statut = [
    'en_attente' => '<span class="badge bg-warning text-dark">⏳ En attente</span>',
    'confirmée'  => '<span class="badge bg-success">✔ Confirmée</span>',
    'refusée'    => '<span class="badge bg-danger">✖ Refusée</span>',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TREC – Mon Espace Cavalier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root { --vert: #1a6b3c; --fond: #f5f3ef; }
        body { background-color: var(--fond); font-family: 'DM Sans', sans-serif; color: #1a1a1a; }
        .badge-rfid { background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.3); border-radius: 20px; padding: .35rem 1rem; font-size: .85rem; display: inline-block; }
        .card-trec { border: none; border-radius: 16px; box-shadow: 0 2px 12px rgba(0,0,0,.07); background: #fff; }
        .card-header-custom { background: none; border-bottom: 2px solid var(--fond); padding: 1.2rem 1.5rem .8rem; font-family: 'Playfair Display', serif; font-size: 1.1rem; color: var(--vert); display: flex; align-items: center; gap: .5rem; }
        .card-trec .card-body { padding: 1.5rem; }
        .btn-inscription { background: var(--vert); color: white; border: none; border-radius: 8px; padding: .6rem 1.5rem; font-weight: 600; transition: background .2s; }
        .btn-inscription:hover { background: #0f4226; color: white; }
        .gps-placeholder { background: linear-gradient(135deg, #e8f5e9, #f1f8e9); border-radius: 12px; padding: 3rem 1rem; text-align: center; border: 2px dashed #a5d6a7; }
        .timeline-item { display: flex; gap: 1rem; align-items: flex-start; padding: .8rem 0; border-bottom: 1px solid var(--fond); }
        .timeline-item:last-child { border-bottom: none; }
        .epreuve-item:hover:not(.disabled) { background-color: var(--fond) !important; }
        .epreuve-item.disabled { opacity: 0.6; cursor: not-allowed; background-color: #f9f9f9 !important; }

        /* ── Message éphémère ── */
        .btn-msg-ephemere {
            position: fixed;
            bottom: 28px;
            right: 28px;
            z-index: 1050;
            width: 56px; height: 56px;
            border-radius: 50%;
            background: #dc3545;
            color: #fff;
            border: none;
            box-shadow: 0 4px 18px rgba(220,53,69,.45);
            font-size: 1.4rem;
            display: flex; align-items: center; justify-content: center;
            transition: transform .2s, background .2s;
            cursor: pointer;
        }
        .btn-msg-ephemere:hover { background: #b02a37; transform: scale(1.08); }

        #msgEphemerePanel {
            position: fixed;
            bottom: 94px;
            right: 28px;
            z-index: 1049;
            width: 320px;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 8px 32px rgba(0,0,0,.18);
            padding: 1.2rem 1.3rem 1rem;
            display: none;
            animation: slideUp .2s ease;
        }
        @keyframes slideUp {
            from { opacity:0; transform: translateY(12px); }
            to   { opacity:1; transform: translateY(0); }
        }
        #msgEphemerePanel .panel-title {
            font-weight: 700;
            font-size: .95rem;
            color: #dc3545;
            display: flex;
            align-items: center;
            gap: .4rem;
            margin-bottom: .8rem;
        }
        #msgRetourEphemere { font-size: .82rem; min-height: 1.4rem; }
    </style>
</head>
<body>
<?php include '../header.php'; ?>

<main class="flex-grow-1 bg-light">
    <!-- En-tête profil -->
    <section class="py-5 bg-dark text-white text-center shadow-sm">
        <div class="container">
            <h1 class="display-5 fw-bold">Espace cavalier</h1>
            <p class="lead mb-2">S'inscrire aux compétitions, consulter ses résultats et visualiser sa trace GPS</p>
            <p class="mb-2 opacity-75">
                <?= htmlspecialchars(($cavalier['prenom_cavalier'] ?? '') . ' ' . ($cavalier['nom_cavalier'] ?? '')) ?>
                · <?= htmlspecialchars($cavalier['categorie'] ?? '') ?>
                · <?= htmlspecialchars($cavalier['adresse'] ?? '') ?>
            </p>
            <?php if ($dossard): ?>
            <div class="badge-rfid">
                <i class="bi bi-broadcast me-1"></i>
                Dossard RFID : <strong>#<?= htmlspecialchars($dossard['tag_rfid']) ?></strong>
                | N° <strong><?= htmlspecialchars($dossard['numero_dossard']) ?></strong>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="container py-4">

        <!-- Message de retour -->
        <?php if ($message_retour): ?>
        <div class="alert alert-<?= $type_message ?> alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-<?= $type_message === 'success' ? 'check-circle' : 'info-circle' ?> me-2"></i>
            <?= htmlspecialchars($message_retour) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row g-4">

            <!-- ── Colonne Principale (Gauche) ── -->
            <div class="col-lg-7">

                <!-- Inscription à une compétition -->
                <div class="card card-trec mb-4">
                    <div class="card-header-custom">
                        <i class="bi bi-person-plus-fill text-success"></i> S'inscrire à une compétition
                    </div>
                    <div class="card-body">
                        <?php if (empty($competitions_dispo)): ?>
                            <p class="text-muted mb-0">
                                <i class="bi bi-calendar-x me-1"></i> Aucune compétition disponible.
                            </p>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="demande_inscription">
                                <div class="mb-3">
                                    <label for="id_competition" class="form-label fw-semibold">Compétition souhaitée</label>
                                    <select name="id_competition" id="id_competition" class="form-select" required>
                                        <option value="" disabled selected>— Choisissez une compétition —</option>
                                        <?php foreach ($competitions_dispo as $comp): ?>
                                            <option value="<?= $comp['id_competition'] ?>">
                                                <?= htmlspecialchars($comp['nom_competition']) ?>
                                                (<?= date('d/m/Y', strtotime($comp['date_competition'])) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-inscription w-100"
                                        onclick="return confirmerDemande()">
                                    <i class="bi bi-send me-2"></i>Envoyer ma demande
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Inscription aux épreuves -->
                <div class="card card-trec mb-4">
                    <div class="card-header-custom">
                        <i class="bi bi-flag-fill text-success"></i> S'inscrire à une épreuve
                    </div>
                    <div class="card-body">
                        <?php if (empty($inscriptions_avec_epreuves)): ?>
                            <p class="text-muted mb-0">
                                <i class="bi bi-info-circle me-1"></i>
                                Inscrivez-vous d'abord à une compétition pour voir ses épreuves.
                            </p>
                        <?php else: ?>
                            <?php foreach ($inscriptions_avec_epreuves as $bloc):
                                $id_ins = $bloc['inscription']['id_inscription'];
                            ?>
                                <div class="border rounded p-3 mb-3">
                                    <div class="fw-semibold mb-2">
                                        <i class="bi bi-calendar-event me-1 text-success"></i>
                                        <?= htmlspecialchars($bloc['inscription']['nom_competition']) ?>
                                    </div>
                                    <div class="btn-group btn-group-sm mb-3">
                                        <?php foreach (['ALL' => 'Tout', 'POR' => 'POR', 'MA' => 'MA', 'PTV' => 'PTV'] as $val => $label): ?>
                                            <a href="?filtre_type=<?= $val ?>#epreuves_<?= $id_ins ?>"
                                               class="btn btn-outline-success <?= $filtre_type === $val ? 'active' : '' ?>">
                                                <?= $label ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="demande_inscription_epreuves">
                                        <input type="hidden" name="id_inscription" value="<?= $id_ins ?>">
                                        <div id="epreuves_<?= $id_ins ?>" class="list-group mb-2">
                                            <?php foreach ($bloc['epreuves'] as $ep):
                                                $type = strtoupper(trim($ep['type_epreuve']));
                                                if ($filtre_type !== 'ALL' && $type !== $filtre_type) continue;
                                                $deja  = !empty($ep['statut_epreuve']);
                                                $color = ['POR' => 'primary', 'MA' => 'warning', 'PTV' => 'info'][$type] ?? 'secondary';
                                            ?>
                                                <label class="list-group-item d-flex align-items-center gap-2 <?= $deja ? 'disabled' : '' ?>">
                                                    <input type="checkbox" name="epreuves[]"
                                                           value="<?= $ep['id_epreuve'] ?>"
                                                           class="form-check-input m-0"
                                                           <?= $deja ? 'disabled' : '' ?>>
                                                    <span class="badge bg-<?= $color ?>">
                                                        <?= htmlspecialchars($ep['type_epreuve']) ?>
                                                    </span>
                                                    <span class="flex-grow-1">
                                                        <?= htmlspecialchars($ep['nom_epreuve']) ?>
                                                    </span>
                                                    <?= $deja ? ($badge_statut[$ep['statut_epreuve']] ?? '') : '' ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="submit" class="btn btn-inscription btn-sm">
                                            <i class="bi bi-send me-1"></i> Valider les épreuves
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Historique des inscriptions -->
                <div class="card card-trec">
                    <div class="card-header-custom">
                        <i class="bi bi-calendar-check-fill text-success"></i> Mes inscriptions
                    </div>
                    <div class="card-body">
                        <?php if (empty($inscriptions)): ?>
                            <p class="text-muted mb-0">Aucune inscription enregistrée.</p>
                        <?php else: ?>
                            <?php foreach ($inscriptions as $ins):
                                $label_map = [
                                    'confirmee'  => 'Confirmée',
                                    'confirmée'  => 'Confirmée',
                                    'en_attente' => 'En attente',
                                    'refusee'    => 'Refusée',
                                    'refusée'    => 'Refusée',
                                ];
                                $label_statut = $label_map[$ins['statut_inscription']] ?? $ins['statut_inscription'];
                            ?>
                            <div class="timeline-item">
                                <div class="flex-grow-1">
                                    <div class="fw-semibold">
                                        <?= htmlspecialchars($ins['nom_competition']) ?>
                                    </div>
                                    <div class="text-muted small">
                                        <?= date('d/m/Y', strtotime($ins['date_competition'])) ?>
                                    </div>
                                    <div class="small mt-1">
                                        Statut : <strong><?= htmlspecialchars($label_statut) ?></strong>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div><!-- /col-lg-7 -->

            <!-- ── Colonne Secondaire (Droite) ── -->
            <div class="col-lg-5">

                <!-- Infos utilisateur -->
                <?php if (isset($_SESSION['succes_profil'])): ?>
                <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($_SESSION['succes_profil']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['succes_profil']); endif; ?>

                <div class="card card-trec mb-4">
                    <div class="card-header-custom">
                        <i class="bi bi-person-circle text-success"></i> Mon profil
                        <button class="btn btn-outline-success btn-sm ms-auto"
                                data-bs-toggle="modal" data-bs-target="#modalEditProfil">
                            <i class="bi bi-pencil me-1"></i>Modifier
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush rounded-3">
                            <li class="list-group-item d-flex align-items-center gap-3 py-3 px-4">
                                <i class="bi bi-person-fill text-secondary fs-5" style="width:20px"></i>
                                <div>
                                    <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Nom complet</div>
                                    <div class="fw-semibold">
                                        <?= htmlspecialchars(trim(($cavalier['prenom_cavalier'] ?? '') . ' ' . ($cavalier['nom_cavalier'] ?? ''))) ?: '—' ?>
                                    </div>
                                </div>
                            </li>
                            <li class="list-group-item d-flex align-items-center gap-3 py-3 px-4">
                                <i class="bi bi-envelope-fill text-secondary fs-5" style="width:20px"></i>
                                <div>
                                    <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Email</div>
                                    <div class="fw-semibold"><?= htmlspecialchars($cavalier['mail'] ?? '—') ?></div>
                                </div>
                            </li>
                            <li class="list-group-item d-flex align-items-center gap-3 py-3 px-4">
                                <i class="bi bi-geo-alt-fill text-secondary fs-5" style="width:20px"></i>
                                <div>
                                    <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Adresse</div>
                                    <div class="fw-semibold"><?= htmlspecialchars($cavalier['adresse'] ?? '—') ?></div>
                                </div>
                            </li>
                            <li class="list-group-item d-flex align-items-center gap-3 py-3 px-4">
                                <i class="bi bi-award-fill text-secondary fs-5" style="width:20px"></i>
                                <div>
                                    <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Catégorie</div>
                                    <div class="fw-semibold"><?= htmlspecialchars($cavalier['categorie'] ?? '—') ?></div>
                                </div>
                            </li>
                            <?php if ($dossard): ?>
                            <li class="list-group-item d-flex align-items-center gap-3 py-3 px-4">
                                <i class="bi bi-broadcast text-secondary fs-5" style="width:20px"></i>
                                <div>
                                    <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Dossard RFID</div>
                                    <div class="fw-semibold">
                                        #<?= htmlspecialchars($dossard['tag_rfid']) ?>
                                        &nbsp;·&nbsp; N° <?= htmlspecialchars($dossard['numero_dossard']) ?>
                                    </div>
                                </div>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <!-- Résultats -->
                <div class="card card-trec mb-4">
                    <div class="card-header-custom">
                        <i class="bi bi-trophy-fill text-warning"></i> Mes Résultats
                        <?php if (!empty($tous_resultats)): ?>
                            <span class="badge bg-success ms-auto" style="font-size:.75rem">
                                <?= count($tous_resultats) ?> résultat(s)
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($tous_resultats)): ?>
                            <p class="text-muted p-3 mb-0">
                                <i class="bi bi-hourglass me-1"></i> Aucun résultat disponible pour l'instant.
                            </p>
                        <?php else:
                            $groupes = [];
                            foreach ($tous_resultats as $r) {
                                $key = $r['nom_competition'] . '||' . $r['date_competition'];
                                $groupes[$key][] = $r;
                            }
                        ?>
                        <?php foreach ($groupes as $key => $lignes):
                            [$nom_comp, $date_comp] = explode('||', $key);
                        ?>
                            <div class="px-3 pt-3 pb-1 d-flex align-items-center gap-2 border-bottom">
                                <i class="bi bi-calendar-event text-success"></i>
                                <span class="fw-semibold small"><?= htmlspecialchars($nom_comp) ?></span>
                                <span class="text-muted small ms-auto">
                                    <?= date('d/m/Y', strtotime($date_comp)) ?>
                                </span>
                            </div>
                            <?php foreach ($lignes as $r):
                                $type  = $r['type_epreuve'] ?? '—';
                                $color = match($type) { 'POR' => 'primary', 'MA' => 'success', 'PTV' => 'warning', default => 'secondary' };

                                $tps_fmt = '—';
                                if ($r['temps_reel_min'] !== null) {
                                    $total_s = (int)round((float)$r['temps_reel_min'] * 60);
                                    $h = intdiv($total_s, 3600);
                                    $m = intdiv($total_s % 3600, 60);
                                    $s = $total_s % 60;
                                    $tps_fmt = $h > 0
                                        ? sprintf("%dh%02d'%02d\"", $h, $m, $s)
                                        : sprintf("%d'%02d\"", $m, $s);
                                }

                                $ecart_fmt   = '';
                                $ecart_class = '';
                                if (isset($r['ecart_s']) && $r['ecart_s'] !== null && $type === 'POR') {
                                    $abs       = abs($r['ecart_s']);
                                    $em        = intdiv($abs, 60); $es = $abs % 60;
                                    $str       = $em > 0 ? sprintf("%d'%02d\"", $em, $es) : $abs . '"';
                                    $ecart_fmt = ($r['ecart_s'] > 0 ? '+' : ($r['ecart_s'] < 0 ? '−' : '')) . $str;
                                    $ecart_class = $r['ecart_s'] === 0 ? 'text-success fw-bold' : ($r['ecart_s'] > 0 ? 'text-danger' : 'text-primary');
                                }

                                $score_class = 'bg-secondary';
                                if ($r['score'] !== null) {
                                    $score_class = $r['score'] >= 180 ? 'bg-success' : ($r['score'] >= 150 ? 'bg-warning text-dark' : 'bg-danger');
                                }
                            ?>
                            <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom">
                                <span class="badge bg-<?= $color ?>" style="min-width:38px;text-align:center">
                                    <?= htmlspecialchars($type) ?>
                                </span>
                                <span class="small flex-grow-1 text-truncate"
                                      title="<?= htmlspecialchars($r['nom_epreuve'] ?? '') ?>">
                                    <?= htmlspecialchars($r['nom_epreuve'] ?? '—') ?>
                                </span>
                                <span class="small text-muted" style="min-width:60px;text-align:right">
                                    <?= $tps_fmt ?>
                                </span>
                                <?php if ($ecart_fmt !== ''): ?>
                                <span class="small <?= $ecart_class ?>" style="min-width:55px;text-align:right">
                                    <?= $ecart_fmt ?>
                                </span>
                                <?php else: ?>
                                <span style="min-width:55px"></span>
                                <?php endif; ?>
                                <?php if ($r['penalites'] !== null): ?>
                                <span class="small <?= $r['penalites'] == 0 ? 'text-success' : 'text-danger' ?>"
                                      style="min-width:50px;text-align:right">
                                    <?= intval($r['penalites']) ?> pén.
                                </span>
                                <?php else: ?>
                                <span style="min-width:50px"></span>
                                <?php endif; ?>
                                <?php if ($r['score'] !== null): ?>
                                <span class="badge <?= $score_class ?>" style="min-width:60px;text-align:center">
                                    <?= intval($r['score']) ?> pts
                                </span>
                                <?php else: ?>
                                <span class="badge bg-secondary" style="min-width:60px;text-align:center">—</span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <div class="px-3 py-2 text-muted" style="font-size:.72rem">
                            <i class="bi bi-info-circle me-1"></i>
                            POR : 200 pts − 1 pén./4 s d'écart · MA/PTV : score depuis les résultats saisis
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Trace GPS -->
                <div class="card card-trec">
                    <div class="card-header-custom">
                        <i class="bi bi-geo-alt-fill text-success"></i> Ma Trace GPS
                    </div>
                    <div class="card-body">
                        <div class="gps-placeholder">
                            <i class="bi bi-map fs-1 text-success opacity-50 d-block mb-2"></i>
                            <p class="text-muted small mb-3">
                                Visualisez votre parcours réalisé sur la carte interactive
                            </p>
                            <a href="../parcours/carte_cavalier.php" class="btn btn-success">Voir la carte</a>
                        </div>
                    </div>
                </div>

            </div><!-- /col-lg-5 -->
        </div><!-- /row -->
    </div><!-- /container -->
</main>

<!-- ══ BOUTON FLOTTANT MESSAGE ÉPHÉMÈRE ══════════════════════════════════════ -->
<button class="btn-msg-ephemere" id="btnMsgEphemere"
        title="Envoyer un message à l'organisateur"
        onclick="toggleMsgPanel()">
    <i class="bi bi-megaphone-fill"></i>
</button>

<!-- ══ PANNEAU MESSAGE ÉPHÉMÈRE ══════════════════════════════════════════════ -->
<div id="msgEphemerePanel">
    <div class="panel-title">
        <i class="bi bi-megaphone-fill"></i> Message à l'organisateur
        <button type="button" class="btn-close ms-auto" onclick="toggleMsgPanel()" style="font-size:.75rem"></button>
    </div>
    <p class="text-muted small mb-2">
        Message <strong>éphémère</strong> — non enregistré, reçu en direct par l'organisateur (limite d'envoi - 5 messages max).
    </p>
    <form id="formMsgEphemere">
        <!-- Sujet prédéfini -->
        <div class="mb-2">
            <label class="form-label small fw-semibold mb-1">Sujet</label>
            <div class="d-flex flex-wrap gap-1" id="sujetBtns">
                <?php
                $sujets = [
                    'urgence'     => ['label' => '🚨 Urgence',     'color' => 'danger'],
                    'probleme'    => ['label' => '⚠️ Problème',    'color' => 'warning'],
                    'question'    => ['label' => '❓ Question',    'color' => 'primary'],
                    'information' => ['label' => 'ℹ️ Information', 'color' => 'secondary'],
                ];
                foreach ($sujets as $val => $s): ?>
                <button type="button"
                        class="btn btn-outline-<?= $s['color'] ?> btn-sm sujet-btn"
                        data-sujet="<?= $val ?>"
                        onclick="selectSujet(this)">
                    <?= $s['label'] ?>
                </button>
                <?php endforeach; ?>
            </div>
            <input type="hidden" id="sujetSelectionne" name="sujet" value="">
        </div>
        <!-- Texte libre -->
        <div class="mb-2">
            <label for="msgTexte" class="form-label small fw-semibold mb-1">Message</label>
            <textarea id="msgTexte" name="message" class="form-control form-control-sm"
                      rows="3" maxlength="500"
                      placeholder="Décrivez votre demande... (max 500 car.)"></textarea>
            <div class="text-end text-muted" style="font-size:.7rem">
                <span id="msgCompteur">0</span>/500
            </div>
        </div>
        <!-- Bouton envoi -->
        <button type="submit" class="btn btn-danger btn-sm w-100" id="btnEnvoyerMsg">
            <i class="bi bi-send-fill me-1"></i> Envoyer
        </button>
        <!-- Retour -->
        <div id="msgRetourEphemere" class="mt-2 text-center"></div>
    </form>
</div>

<!-- ══ MODAL MODIFIER PROFIL ════════════════════════════════════════════════ -->
<div class="modal fade" id="modalEditProfil" tabindex="-1" aria-labelledby="modalEditProfilLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="espace_cavalier.php">
                <input type="hidden" name="action" value="modifier_profil">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditProfilLabel">
                        <i class="bi bi-pencil-fill me-2"></i>Modifier mon profil
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Nom <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="nom"
                                   value="<?= htmlspecialchars($cavalier['nom_cavalier'] ?? '') ?>"
                                   required maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Prénom <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="prenom"
                                   value="<?= htmlspecialchars($cavalier['prenom_cavalier'] ?? '') ?>"
                                   required maxlength="100">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Adresse</label>
                            <input type="text" class="form-control" name="adresse"
                                   value="<?= htmlspecialchars($cavalier['adresse'] ?? '') ?>"
                                   placeholder="Ex : 12 rue des Écuries, 88000 Épinal" maxlength="255">
                        </div>
                    </div>
                    <div class="alert alert-info mt-3 mb-0 py-2 small">
                        <i class="bi bi-info-circle me-1"></i>
                        La catégorie et l'email ne sont modifiables que par un administrateur.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Annuler
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle-fill me-1"></i>Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Confirmation d'inscription ────────────────────────────────────────────────
function confirmerDemande() {
    const sel = document.getElementById('id_competition');
    if (!sel.value) return false;
    return confirm("Envoyer une demande pour :\n\n" + sel.options[sel.selectedIndex].text + "\n\nL'organisateur sera notifié !");
}

// ── Panneau message éphémère ──────────────────────────────────────────────────
function toggleMsgPanel() {
    const panel = document.getElementById('msgEphemerePanel');
    const visible = panel.style.display === 'block';
    panel.style.display = visible ? 'none' : 'block';
}

// Fermer si clic en dehors
document.addEventListener('click', function(e) {
    const panel = document.getElementById('msgEphemerePanel');
    const btn   = document.getElementById('btnMsgEphemere');
    if (panel.style.display === 'block' && !panel.contains(e.target) && !btn.contains(e.target)) {
        panel.style.display = 'none';
    }
});

// Sélection du sujet
function selectSujet(el) {
    document.querySelectorAll('.sujet-btn').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('sujetSelectionne').value = el.dataset.sujet;
}

// Compteur caractères
document.getElementById('msgTexte').addEventListener('input', function() {
    document.getElementById('msgCompteur').textContent = this.value.length;
});

// ── Envoi du message via fetch ────────────────────────────────────────────────
document.getElementById('formMsgEphemere').addEventListener('submit', function(e) {
    e.preventDefault();

    const sujet   = document.getElementById('sujetSelectionne').value;
    const message = document.getElementById('msgTexte').value.trim();
    const retour  = document.getElementById('msgRetourEphemere');
    const btn     = document.getElementById('btnEnvoyerMsg');

    // Validations côté client
    if (!sujet) {
        retour.innerHTML = '<span class="text-danger small">Veuillez choisir un sujet.</span>';
        return;
    }
    if (message.length < 3) {
        retour.innerHTML = '<span class="text-danger small">Message trop court (min 3 caractères).</span>';
        return;
    }

    btn.disabled   = true;
    btn.innerHTML  = '<span class="spinner-border spinner-border-sm me-1"></span>Envoi…';
    retour.innerHTML = '';

    const formData = new FormData();
    formData.append('action',  'send_message_ephemere');
    formData.append('sujet',   sujet);
    formData.append('message', message);

    fetch('espace_cavalier.php', {
        method: 'POST',
        body:   formData,
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            retour.innerHTML = '<span class="text-success small"><i class="bi bi-check-circle me-1"></i>' + data.message + '</span>';
            // Réinitialiser le formulaire
            document.getElementById('msgTexte').value = '';
            document.getElementById('msgCompteur').textContent = '0';
            document.getElementById('sujetSelectionne').value  = '';
            document.querySelectorAll('.sujet-btn').forEach(b => b.classList.remove('active'));
            // Fermer après 2s
            setTimeout(() => {
                document.getElementById('msgEphemerePanel').style.display = 'none';
                retour.innerHTML = '';
            }, 2000);
        } else {
            retour.innerHTML = '<span class="text-danger small"><i class="bi bi-exclamation-circle me-1"></i>' + (data.error ?? 'Erreur inconnue') + '</span>';
        }
    })
    .catch(() => {
        retour.innerHTML = '<span class="text-danger small">Erreur réseau, réessayez.</span>';
    })
    .finally(() => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="bi bi-send-fill me-1"></i> Envoyer';
    });
});
</script>
</body>
</html>