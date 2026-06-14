<?php
// competition.php — Gestion des compétitions TREC (admin)
// Onglets : Participants · Notifications · Config · Épreuves

require '../config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'organisateur'])) {
    header('Location: ../auth/login.php'); exit;
}

// ── Helpers génériques ────────────────────────────────────────────────────────

/**
 * Prépare, bind et exécute une requête paramétrée mysqli.
 * Retourne le stmt exécuté (pour fetch) ou false en cas d'échec.
 */
function prepare_exec(mysqli $conn, string $sql, string $types, mixed ...$params): mysqli_stmt|false
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;
    if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    return $stmt;
}

/** Redirige et stoppe l'exécution. */
function redirect(string $url): never { header("Location: $url"); exit; }

/** Retourne un <span class="badge"> coloré selon le statut de la compétition. */
function statut_badge(string $statut): string
{
    static $map = [
        'draft'    => ['🔒 Brouillon',            'bg-secondary'],
        'open'     => ['✅ Inscriptions ouvertes', 'bg-success'],
        'en_cours' => ['🏇 En cours',              'bg-primary'],
        'closed'   => ['🏁 Terminée',              'bg-dark'],
    ];
    [$label, $class] = $map[$statut] ?? ['⚙️ ' . ($statut ?: '—'), 'bg-secondary'];
    return '<span class="badge ' . $class . '">' . htmlspecialchars($label) . '</span>';
}

/** Retourne la couleur Bootstrap associée au type d'épreuve. */
function epreuve_color(string $type): string
{
    return ['POR' => 'primary', 'MA' => 'warning', 'PTV' => 'info'][$type] ?? 'secondary';
}

// ── Mail ──────────────────────────────────────────────────────────────────────

/**
 * Envoie un mail (text + html) encodé en base64.
 */
function send_mail(string $to, string $sujet, string $html_body): void
{
    $boundary = md5(uniqid());
    $headers  = implode("\r\n", [
        'From: TREC Cheniménil <noreply@trec-competition.fr>',
        'Reply-To: noreply@trec-competition.fr',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: PHP/' . PHP_VERSION,
    ]);
    $text  = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html_body));
    $body  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))     . "\r\n";
    $body .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"  . chunk_split(base64_encode($html_body)) . "\r\n";
    $body .= "--$boundary--";
    mail($to, '=?UTF-8?B?' . base64_encode($sujet) . '?=', $body, $headers);
}

/**
 * Génère le corps HTML du mail de confirmation ou refus d'inscription.
 */
function mail_inscription(string $prenom, string $nom, string $competition, bool $ok): string
{
    $titre = $ok ? 'Inscription confirmée ✅' : 'Inscription refusée ❌';
    $corps = $ok
        ? 'Votre inscription à <strong style="color:#10b981;">' . htmlspecialchars($competition) . '</strong> a été <strong>acceptée</strong>.
           <div style="text-align:center;margin:28px 0"><a href="https://trec88000.alwaysdata.net/projetTREC/classement/auth/login.php"
           style="background:#f59e0b;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">Mon espace cavalier</a></div>'
        : 'Votre demande pour <strong>' . htmlspecialchars($competition) . '</strong> n\'a pas pu être acceptée.
           <div style="background:#fef2f2;border-left:4px solid #ef4444;padding:12px 16px;border-radius:4px;margin:20px 0">
           <p style="color:#b91c1c;margin:0;font-size:13px">📧 <a href="mailto:contact@trec-competition.fr" style="color:#b91c1c">contact@trec-competition.fr</a></p></div>';
    $pn = htmlspecialchars("$prenom $nom");
    return "<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'></head><body style='font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:20px'>
<div style='max-width:520px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden'>
 <div style='background:#1a1a2e;padding:28px 32px;text-align:center'><h1 style='color:#f9c74f;margin:0;font-size:20px'>🏇 TREC Cheniménil</h1></div>
 <div style='padding:32px'><h2 style='color:#1a1a2e;margin-top:0'>$titre</h2><p>Bonjour <strong>$pn</strong>,</p><p>$corps</p></div>
 <div style='background:#f9fafb;padding:16px 32px;text-align:center'><p style='color:#9ca3af;font-size:12px;margin:0'>TREC Cheniménil &copy; 2026</p></div>
</div></body></html>";
}

/**
 * Récupère prenom, nom, mail et nom_competition pour un id_inscription donné.
 */
function fetch_mail_data(mysqli $conn, int $id): ?array
{
    $stmt = prepare_exec($conn,
        "SELECT u.mail, u.prenom, u.nom, c.nom_competition
         FROM inscription i
         JOIN cavalier    cav ON i.id_cavalier     = cav.id_cavalier
         JOIN utilisateur u   ON cav.id_utilisateur = u.id_utilisateur
         JOIN competition c   ON i.id_competition  = c.id_competition
         WHERE i.id_inscription = ?", "i", $id);
    return $stmt ? mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) : null;
}

// ============================================================
// CACHE : colonnes optionnelles (vérifié une seule fois)
// ============================================================
$has_date_fin = (bool) mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM competition LIKE 'date_fin_competition'"));

// ============================================================
// TRANSITIONS DE STATUT AUTOMATIQUES
// draft → open    : J-4 avant le début
// open  → en_cours: à partir de l'heure de début
// en_cours→ closed: après l'heure de fin
// ============================================================
if ($has_date_fin) {
    mysqli_query($conn, "
        UPDATE competition SET statut = 'open'
        WHERE statut = 'draft'
          AND CONCAT(date_competition, ' ', COALESCE(heure_debut, '00:00:00')) > NOW()
          AND DATE_SUB(CONCAT(date_competition, ' ', COALESCE(heure_debut, '00:00:00')), INTERVAL 4 DAY) <= NOW()
    ");
    mysqli_query($conn, "
        UPDATE competition SET statut = 'en_cours'
        WHERE statut IN ('open', 'draft')
          AND CONCAT(date_competition, ' ', COALESCE(heure_debut, '00:00:00')) <= NOW()
          AND CONCAT(date_fin_competition, ' ', COALESCE(heure_fin, '23:59:59')) >= NOW()
    ");
    mysqli_query($conn, "
        UPDATE competition SET statut = 'closed'
        WHERE statut != 'closed'
          AND CONCAT(date_fin_competition, ' ', COALESCE(heure_fin, '23:59:59')) < NOW()
    ");
}

// ============================================================
// TRAITEMENT POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $fc     = intval($_POST['filtre_comp'] ?? 0);

    // ── Inscription épreuve : accepter / refuser ──────────────
    if ($action === 'accepter_inscription_epreuve') {
        $id_ie = intval($_POST['id_inscription_epreuve']);

        // Récupère l'id_epreuve et son type
        $stmt = prepare_exec($conn,
            "SELECT ie.id_epreuve, e.type_epreuve
             FROM inscription_epreuve ie
             JOIN epreuve e ON ie.id_epreuve = e.id_epreuve
             WHERE ie.id_inscription_epreuve = ?", "i", $id_ie);
        $row = $stmt ? mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) : null;

        if ($row && $row['type_epreuve'] === 'POR') {
            // Vérifie si un cavalier est déjà confirmé pour cette épreuve POR
            $check = prepare_exec($conn,
                "SELECT COUNT(*) AS nb FROM inscription_epreuve
                 WHERE id_epreuve = ? AND statut_epreuve = 'confirmée'", "i", $row['id_epreuve']);
            $nb = $check ? (int) mysqli_fetch_assoc(mysqli_stmt_get_result($check))['nb'] : 0;

            if ($nb >= 1) {
                redirect('competition.php?erreur=por_plein&tab=notifications');
            }
        }

        prepare_exec($conn, "UPDATE inscription_epreuve SET statut_epreuve = 'confirmée' WHERE id_inscription_epreuve = ?", "i", $id_ie);
        redirect('competition.php?succes=1&tab=notifications');
    }
    if ($action === 'refuser_inscription_epreuve') {
        prepare_exec($conn, "UPDATE inscription_epreuve SET statut_epreuve = 'refusée' WHERE id_inscription_epreuve = ?", "i", intval($_POST['id_inscription_epreuve']));
        redirect('competition.php?succes=1&tab=notifications');
    }

    // ── Inscription compétition : accepter / refuser / supprimer ──
    if ($action === 'accepter_inscription') {
        $id = intval($_POST['id_inscription']);
        prepare_exec($conn, "UPDATE inscription SET statut_inscription = 'confirmee' WHERE id_inscription = ?", "i", $id);
        if ($r = fetch_mail_data($conn, $id))
            send_mail($r['mail'], 'Inscription acceptée – ' . $r['nom_competition'], mail_inscription($r['prenom'], $r['nom'], $r['nom_competition'], true));
        redirect("competition.php?succes=1&tab=participants&filtre_comp=$fc");
    }
    if ($action === 'refuser_inscription') {
        $id  = intval($_POST['id_inscription']);
        $r   = fetch_mail_data($conn, $id);
        prepare_exec($conn, "UPDATE inscription SET statut_inscription = 'refusee' WHERE id_inscription = ?", "i", $id);
        if ($r) send_mail($r['mail'], 'Inscription refusée – ' . $r['nom_competition'], mail_inscription($r['prenom'], $r['nom'], $r['nom_competition'], false));
        redirect("competition.php?succes=1&tab=participants&filtre_comp=$fc");
    }
    if ($action === 'supprimer_inscription') {
        $id   = intval($_POST['id_inscription']);
        $stmt = prepare_exec($conn, "SELECT id_cavalier FROM inscription WHERE id_inscription = ?", "i", $id);
        $row  = $stmt ? mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) : null;
        if ($row) {
            $id_cav = $row['id_cavalier'];
            prepare_exec($conn, "DELETE pg FROM pointGPS pg JOIN session_gps sg ON pg.id_sessionGPS = sg.id_sessionGPS WHERE sg.id_cavalier = ?", "i", $id_cav);
            prepare_exec($conn, "DELETE FROM session_gps WHERE id_cavalier = ?", "i", $id_cav);
            prepare_exec($conn, "DELETE FROM affectation_boitier WHERE id_cavalier = ?", "i", $id_cav);
            prepare_exec($conn, "UPDATE inscription SET id_dossard = NULL WHERE id_inscription = ?", "i", $id);
            prepare_exec($conn, "DELETE FROM inscription WHERE id_inscription = ?", "i", $id);
        }
        redirect("competition.php?succes=1&tab=participants&filtre_comp=$fc");
    }

    // ── Compétitions : créer / modifier ──────────────────────
    if (in_array($action, ['create_competition', 'update_competition'], true)) {
        $nom         = trim($_POST['nom_competition']      ?? '');
        $date        = trim($_POST['date_competition']     ?? '');
        $heure_debut = trim($_POST['heure_debut']          ?? '09:00');
        $date_fin    = trim($_POST['date_fin_competition'] ?? '');
        $heure_fin   = trim($_POST['heure_fin']            ?? '17:00');
        $lieu        = trim($_POST['lieu']                 ?? '');
        $desc        = trim($_POST['description']          ?? '');
        $stat        = (isset($_POST['draft_mode']) && $_POST['draft_mode'] === '1') ? 'draft' : 'open';

        if ($nom === '' || $date === '') {
            $error_config = "Le nom et la date de début sont obligatoires.";
        } elseif ($has_date_fin && $date_fin === '') {
            $error_config = "La date de fin est obligatoire.";
        } elseif ($action === 'create_competition') {
            if ($has_date_fin) {
                prepare_exec($conn, "INSERT INTO competition (nom_competition,date_competition,heure_debut,date_fin_competition,heure_fin,lieu,description,statut) VALUES (?,?,?,?,?,?,?,?)",
                    "ssssssss", $nom, $date, $heure_debut, $date_fin, $heure_fin, $lieu, $desc, $stat);
            } else {
                prepare_exec($conn, "INSERT INTO competition (nom_competition,date_competition,lieu,description,statut) VALUES (?,?,?,?,?)",
                    "sssss", $nom, $date, $lieu, $desc, $stat);
            }
            redirect('competition.php?succes=1&tab=config');
        } else {
            $id = intval($_POST['id_competition'] ?? 0);
            if ($id > 0) {
                if ($has_date_fin) {
                    prepare_exec($conn, "UPDATE competition SET nom_competition=?,date_competition=?,heure_debut=?,date_fin_competition=?,heure_fin=?,lieu=?,description=?,statut=? WHERE id_competition=?",
                        "ssssssssi", $nom, $date, $heure_debut, $date_fin, $heure_fin, $lieu, $desc, $stat, $id);
                } else {
                    prepare_exec($conn, "UPDATE competition SET nom_competition=?,date_competition=?,lieu=?,description=?,statut=? WHERE id_competition=?",
                        "sssssi", $nom, $date, $lieu, $desc, $stat, $id);
                }
                redirect('competition.php?succes=1&tab=config');
            }
        }
    }

    // ── Compétitions : supprimer ──────────────────────────────
    if ($action === 'delete_competition') {
        $id = intval($_POST['id_competition'] ?? 0);
        if ($id > 0) {
            prepare_exec($conn, "DELETE FROM inscription_epreuve WHERE id_epreuve IN (SELECT id_epreuve FROM epreuve WHERE id_competition=?)", "i", $id);
            prepare_exec($conn, "DELETE FROM epreuve WHERE id_competition=?", "i", $id);
            prepare_exec($conn, "DELETE FROM inscription WHERE id_competition=?", "i", $id);
            prepare_exec($conn, "DELETE FROM competition WHERE id_competition=?", "i", $id);
            redirect('competition.php?succes=1&tab=config');
        }
    }

    // ── Épreuves : créer / modifier ───────────────────────────
    if (in_array($action, ['create_epreuve', 'update_epreuve'], true)) {
        $nom     = trim($_POST['nom_epreuve']             ?? '');
        $desc    = trim($_POST['description_epreuve']     ?? '');
        $type    = trim($_POST['type_epreuve']            ?? 'POR');
        $id_comp = intval($_POST['id_competition_epreuve'] ?? 0);
        $id_par  = !empty($_POST['id_parcours_epreuve']) ? intval($_POST['id_parcours_epreuve']) : null;

        if ($nom === '' || $id_comp <= 0) {
            $error_event = "Le nom et la compétition sont obligatoires.";
        } elseif ($action === 'create_epreuve') {
            $check  = prepare_exec($conn, "SELECT id_epreuve FROM epreuve WHERE id_competition = ? AND type_epreuve = ?", "is", $id_comp, $type);
            $exists = $check ? mysqli_fetch_assoc(mysqli_stmt_get_result($check)) : null;
            if ($exists) {
                $error_event = "Une épreuve de type $type existe déjà pour cette compétition. Une seule épreuve POR, MA et PTV est autorisée par compétition.";
            } else {
                $id_par
                    ? prepare_exec($conn, "INSERT INTO epreuve (nom_epreuve,description,type_epreuve,id_competition,id_parcours) VALUES (?,?,?,?,?)", "sssii", $nom, $desc, $type, $id_comp, $id_par)
                    : prepare_exec($conn, "INSERT INTO epreuve (nom_epreuve,description,type_epreuve,id_competition) VALUES (?,?,?,?)", "sssi", $nom, $desc, $type, $id_comp);
                redirect('competition.php?succes=1&tab=events');
            }
        } else {
            $id = intval($_POST['id_epreuve'] ?? 0);
            if ($id > 0) {
                $id_par
                    ? prepare_exec($conn, "UPDATE epreuve SET nom_epreuve=?,description=?,type_epreuve=?,id_competition=?,id_parcours=? WHERE id_epreuve=?", "sssiii", $nom, $desc, $type, $id_comp, $id_par, $id)
                    : prepare_exec($conn, "UPDATE epreuve SET nom_epreuve=?,description=?,type_epreuve=?,id_competition=?,id_parcours=NULL WHERE id_epreuve=?", "sssii", $nom, $desc, $type, $id_comp, $id);
            }
            redirect('competition.php?succes=1&tab=events');
        }
    }

    // ── Épreuves : supprimer ──────────────────────────────────
    if ($action === 'delete_epreuve') {
        $id = intval($_POST['id_epreuve'] ?? 0);
        if ($id > 0) {
            prepare_exec($conn, "DELETE pg FROM pointGPS pg JOIN session_gps sg ON pg.id_sessionGPS = sg.id_sessionGPS WHERE sg.id_epreuve = ?", "i", $id);
            prepare_exec($conn, "DELETE FROM session_gps WHERE id_epreuve = ?", "i", $id);
            prepare_exec($conn, "DELETE FROM inscription_epreuve WHERE id_epreuve = ?", "i", $id);
            prepare_exec($conn, "DELETE FROM epreuve WHERE id_epreuve = ?", "i", $id);
            redirect('competition.php?succes=1&tab=events');
        }
    }

    // ── Parcours : créer / modifier / supprimer / assigner ────
    if (in_array($action, ['create_parcours', 'update_parcours'], true)) {
        $nom      = trim($_POST['nom_parcours']  ?? '');
        $distance = floatval($_POST['distance_km'] ?? 0);
        $geojson  = trim($_POST['trace_geojson'] ?? '');
        if ($geojson !== '') {
            json_decode($geojson, true);
            if (json_last_error() !== JSON_ERROR_NONE) $error_parcours = "GeoJSON invalide.";
        }
        if (!isset($error_parcours)) {
            if ($nom === '' || $distance <= 0) {
                $error_parcours = "Le nom et la distance sont obligatoires.";
            } elseif ($action === 'create_parcours') {
                prepare_exec($conn, "INSERT INTO parcours_theorique (nom_parcours,distance_km,trace_geojson) VALUES (?,?,?)", "sds", $nom, $distance, $geojson);
                redirect('competition.php?succes=1&tab=events');
            } else {
                $id = intval($_POST['id_parcours'] ?? 0);
                if ($id > 0) {
                    prepare_exec($conn, "UPDATE parcours_theorique SET nom_parcours=?,distance_km=?,trace_geojson=? WHERE id_parcours=?", "sdsi", $nom, $distance, $geojson, $id);
                    redirect("competition.php?succes=1&tab=events&parcours_id=$id");
                }
            }
        }
    }
    if ($action === 'delete_parcours') {
        $id = intval($_POST['id_parcours'] ?? 0);
        if ($id > 0) {
            prepare_exec($conn, "UPDATE epreuve SET id_parcours=NULL WHERE id_parcours=?", "i", $id);
            prepare_exec($conn, "DELETE FROM parcours_theorique WHERE id_parcours=?", "i", $id);
            redirect('competition.php?succes=1&tab=events');
        }
    }
    if ($action === 'assigner_parcours') {
        $id_par = intval($_POST['id_parcours']);
        prepare_exec($conn, "UPDATE epreuve SET id_parcours=? WHERE id_epreuve=?", "ii", $id_par, intval($_POST['id_epreuve']));
        redirect("competition.php?succes=1&tab=events&parcours_id=$id_par");
    }
}

// ============================================================
// LECTURE DES DONNÉES
// ============================================================

$active_tab  = in_array($_GET['tab'] ?? '', ['participants','notifications','config','events'], true) ? $_GET['tab'] : 'participants';
$filtre_comp = isset($_GET['filtre_comp']) ? intval($_GET['filtre_comp']) : 0;
$filtre_type = isset($_GET['filtre_type']) ? trim($_GET['filtre_type']) : 'ALL';

// Fragments SQL pour le filtre compétition (entier validé, pas d'injection)
$sf_comp = $filtre_comp ? " AND c.id_competition = $filtre_comp" : '';
$sf_insc = $filtre_comp ? " AND i.id_competition = $filtre_comp" : '';

// Liste complète des compétitions
$competitions_list = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM competition ORDER BY date_competition DESC"), MYSQLI_ASSOC);

// ── Demandes d'inscription aux épreuves (en_attente) ─────────────────────────
$demandes_epreuves_attente = mysqli_fetch_all(mysqli_query($conn,
    "SELECT ie.id_inscription_epreuve, ie.date_inscription_epreuve,
            e.id_epreuve, e.nom_epreuve, e.type_epreuve,
            c.id_competition, c.nom_competition,
            cav.nom_cavalier, cav.prenom_cavalier, cav.categorie, u.mail
     FROM inscription_epreuve ie
     JOIN epreuve      e   ON ie.id_epreuve     = e.id_epreuve
     JOIN competition  c   ON e.id_competition  = c.id_competition
     JOIN inscription  i   ON ie.id_inscription = i.id_inscription
     JOIN cavalier     cav ON i.id_cavalier      = cav.id_cavalier
     LEFT JOIN utilisateur u ON cav.id_utilisateur = u.id_utilisateur
     WHERE ie.statut_epreuve = 'en_attente' $sf_comp
     ORDER BY ie.date_inscription_epreuve ASC"
), MYSQLI_ASSOC);

if ($filtre_type !== 'ALL')
    $demandes_epreuves_attente = array_values(array_filter($demandes_epreuves_attente, fn($d) => $d['type_epreuve'] === $filtre_type));
$nb_epreuves_attente = count($demandes_epreuves_attente);

// ── Demandes d'inscription aux compétitions (en_attente) ─────────────────────
$demandes_attente = mysqli_fetch_all(mysqli_query($conn,
    "SELECT i.id_inscription, i.date_inscription,
            cav.nom_cavalier AS nom, cav.prenom_cavalier AS prenom, cav.categorie, u.mail,
            c.id_competition, c.nom_competition, c.date_competition
     FROM inscription  i
     JOIN cavalier     cav ON i.id_cavalier    = cav.id_cavalier
     JOIN competition  c   ON i.id_competition = c.id_competition
     LEFT JOIN utilisateur u ON cav.id_utilisateur = u.id_utilisateur
     WHERE i.statut_inscription = 'en_attente' $sf_insc
     ORDER BY i.date_inscription ASC"
), MYSQLI_ASSOC);
$nb_attente = count($demandes_attente);

// ── Participants confirmés et refusés ─────────────────────────────────────────
$participants = mysqli_fetch_all(mysqli_query($conn,
    "SELECT i.id_inscription, i.date_inscription, i.statut_inscription,
            cav.nom_cavalier AS nom, cav.prenom_cavalier AS prenom, cav.categorie, u.mail,
            c.nom_competition, d.numero_dossard
     FROM inscription  i
     JOIN cavalier     cav ON i.id_cavalier    = cav.id_cavalier
     JOIN competition  c   ON i.id_competition = c.id_competition
     LEFT JOIN utilisateur u ON cav.id_utilisateur = u.id_utilisateur
     LEFT JOIN dossard     d ON i.id_dossard = d.id_dossard
     WHERE i.statut_inscription IN ('confirmee','confirmée','refusee') $sf_insc
     ORDER BY c.date_competition ASC, cav.nom_cavalier ASC"
), MYSQLI_ASSOC);

// ── Données détaillées par compétition (alimentent les modals) ───────────────
$detail_data = [];
foreach ($competitions_list as $c) {
    $id    = $c['id_competition'];
    $stats = ['confirmee' => 0, 'en_attente' => 0, 'refusee' => 0];
    $res   = mysqli_query($conn, "SELECT statut_inscription, COUNT(*) nb FROM inscription WHERE id_competition=$id GROUP BY statut_inscription");
    while ($r = mysqli_fetch_assoc($res)) {
        $k = str_starts_with($r['statut_inscription'], 'confirm') ? 'confirmee' : $r['statut_inscription'];
        $stats[$k] = ($stats[$k] ?? 0) + (int)$r['nb'];
    }

    $part_list = mysqli_fetch_all(mysqli_query($conn,
        "SELECT cav.nom_cavalier nom, cav.prenom_cavalier prenom, cav.categorie,
                u.mail, d.numero_dossard, i.id_inscription, i.statut_inscription
         FROM inscription i
         JOIN cavalier    cav ON i.id_cavalier    = cav.id_cavalier
         LEFT JOIN utilisateur u ON cav.id_utilisateur = u.id_utilisateur
         LEFT JOIN dossard     d ON i.id_dossard = d.id_dossard
         WHERE i.id_competition=$id AND i.statut_inscription IN ('confirmee','confirmée')
         ORDER BY cav.nom_cavalier ASC"), MYSQLI_ASSOC);

    $att_list = mysqli_fetch_all(mysqli_query($conn,
        "SELECT cav.nom_cavalier nom, cav.prenom_cavalier prenom, cav.categorie,
                u.mail, i.id_inscription, i.date_inscription
         FROM inscription i
         JOIN cavalier    cav ON i.id_cavalier    = cav.id_cavalier
         LEFT JOIN utilisateur u ON cav.id_utilisateur = u.id_utilisateur
         WHERE i.id_competition=$id AND i.statut_inscription='en_attente'
         ORDER BY i.date_inscription ASC"), MYSQLI_ASSOC);

    $ep_list = mysqli_fetch_all(mysqli_query($conn,
        "SELECT e.*, p.nom_parcours, p.distance_km
         FROM epreuve e
         LEFT JOIN parcours_theorique p ON e.id_parcours = p.id_parcours
         WHERE e.id_competition=$id ORDER BY e.nom_epreuve ASC"), MYSQLI_ASSOC);

    $fil_list = mysqli_fetch_all(mysqli_query($conn,
        "SELECT i.date_inscription, i.statut_inscription,
                cav.nom_cavalier nom, cav.prenom_cavalier prenom
         FROM inscription i
         JOIN cavalier cav ON i.id_cavalier = cav.id_cavalier
         WHERE i.id_competition=$id ORDER BY i.date_inscription DESC LIMIT 10"), MYSQLI_ASSOC);

    $detail_data[$id] = compact('stats', 'part_list', 'att_list', 'ep_list', 'fil_list');
}

// ── Enregistrement en cours d'édition ────────────────────────────────────────
$competition_edit = null;
if ($active_tab === 'config' && isset($_GET['edit'])) {
    $stmt = prepare_exec($conn, "SELECT * FROM competition WHERE id_competition=?", "i", intval($_GET['edit']));
    if ($stmt) $competition_edit = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}
$epreuve_edit = null;
if ($active_tab === 'events' && isset($_GET['edit_epreuve'])) {
    $stmt = prepare_exec($conn, "SELECT * FROM epreuve WHERE id_epreuve=?", "i", intval($_GET['edit_epreuve']));
    if ($stmt) $epreuve_edit = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}

// ── Parcours ──────────────────────────────────────────────────────────────────
$parcours_selectionne = isset($_GET['parcours_id']) ? intval($_GET['parcours_id']) : 0;
$parcours_list        = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM parcours_theorique ORDER BY nom_parcours ASC"), MYSQLI_ASSOC);

$parcours_actif = null;
if ($parcours_selectionne) {
    $stmt = prepare_exec($conn, "SELECT * FROM parcours_theorique WHERE id_parcours=?", "i", $parcours_selectionne);
    if ($stmt) $parcours_actif = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}
$parcours_actif_geojson_valid = false;
if ($parcours_actif && !empty($parcours_actif['trace_geojson'])) {
    json_decode($parcours_actif['trace_geojson'], true);
    $parcours_actif_geojson_valid = (json_last_error() === JSON_ERROR_NONE);
}

// ── Épreuves ──────────────────────────────────────────────────────────────────
$epreuves = mysqli_fetch_all(mysqli_query($conn,
    "SELECT e.*, c.nom_competition, p.nom_parcours, p.distance_km
     FROM epreuve e
     LEFT JOIN competition        c ON e.id_competition = c.id_competition
     LEFT JOIN parcours_theorique p ON e.id_parcours    = p.id_parcours
     ORDER BY c.date_competition DESC, e.nom_epreuve ASC"), MYSQLI_ASSOC);

$epreuves_simple = mysqli_fetch_all(mysqli_query($conn, "SELECT id_epreuve, nom_epreuve FROM epreuve ORDER BY nom_epreuve ASC"), MYSQLI_ASSOC);

// ── Pré-calcul des données pour les modals épreuve ───────────────────────────
$epreuves_modal_data = [];
foreach ($epreuves as $ep) {
    $id_ep   = $ep['id_epreuve'];
    $id_comp = $ep['id_competition'] ?? 0;
    $parts   = $id_comp ? mysqli_fetch_all(mysqli_query($conn,
        "SELECT cav.nom_cavalier nom, cav.prenom_cavalier prenom, cav.categorie, u.mail, d.numero_dossard
         FROM inscription i
         JOIN cavalier    cav ON i.id_cavalier    = cav.id_cavalier
         LEFT JOIN utilisateur u ON cav.id_utilisateur = u.id_utilisateur
         LEFT JOIN dossard     d ON i.id_dossard = d.id_dossard
         WHERE i.id_competition=$id_comp AND i.statut_inscription IN ('confirmee','confirmée')
         ORDER BY cav.nom_cavalier ASC"), MYSQLI_ASSOC) : [];
    $gj    = $ep['trace_geojson'] ?? null;
    $valid = false;
    if ($gj) { json_decode($gj, true); $valid = json_last_error() === JSON_ERROR_NONE; }
    $epreuves_modal_data[$id_ep] = ['participants' => $parts, 'geojson_valid' => $valid, 'geojson' => $valid ? $gj : null];
}
?>
<?php require '../header.php'; ?>

<main class="flex-grow-1 bg-light">

<!-- En-tête page -->
<section class="py-5 bg-dark text-white text-center shadow-sm">
 <div class="container">
  <h1 class="display-5 fw-bold">Gestion des compétitions</h1>
  <p class="lead mb-0">Participants · Notifications · Configuration · Épreuves & Parcours</p>
 </div>
</section>

<div class="container py-5">

 <!-- Alertes flash -->
 <?php if (isset($_GET['succes'])): ?>
  <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-2"></i>Opération effectuée avec succès.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
 <?php endif; ?>
 <?php if (isset($_GET['erreur']) && $_GET['erreur'] === 'por_plein'): ?>
  <div class="alert alert-danger alert-dismissible fade show">
   <i class="bi bi-exclamation-triangle-fill me-2"></i>
   Impossible d'accepter : cette épreuve POR a déjà un cavalier confirmé.
   <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
 <?php endif; ?>
 <?php foreach (['error_config','error_event','error_parcours'] as $ev): if (isset($$ev)): ?>
  <div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($$ev) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
 <?php endif; endforeach; ?>

 <!-- Navigation par onglets -->
 <?php $nb_total_notif = $nb_attente + $nb_epreuves_attente; ?>
 <ul class="nav nav-pills mb-4 nav-justified bg-white p-2 rounded shadow-sm" role="tablist">
  <?php foreach ([
   'participants'  => ['people',         'Participants',        $nb_attente,     'bg-danger'],
   'notifications' => ['bell',           'Notifications',       $nb_total_notif, 'bg-warning text-dark'],
   'config'        => ['calendar-event', 'Configuration',       0, ''],
   'events'        => ['stopwatch',      'Épreuves & Parcours', 0, ''],
  ] as $tid => [$icon, $lbl, $badge, $bc]): ?>
   <li class="nav-item" role="presentation">
    <button class="nav-link fw-bold position-relative <?= $active_tab === $tid ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#pills-<?= $tid ?>" type="button">
     <i class="bi bi-<?= $icon ?> me-2"></i><?= $lbl ?>
     <?php if ($badge > 0): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill <?= $bc ?>" style="font-size:10px"><?= $badge ?></span><?php endif; ?>
    </button>
   </li>
  <?php endforeach; ?>
 </ul>

 <div class="tab-content">

  <!-- ════════════════════════════════════════════════════════
       ONGLET : PARTICIPANTS
  ════════════════════════════════════════════════════════ -->
  <div class="tab-pane fade <?= $active_tab === 'participants' ? 'show active' : '' ?>" id="pills-participants">

   <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <label class="fw-bold text-secondary mb-0">Compétition :</label>
    <div class="d-flex gap-2 flex-wrap">
     <a href="competition.php?tab=participants" class="btn btn-sm <?= !$filtre_comp ? 'btn-dark' : 'btn-outline-secondary' ?>">Toutes</a>
     <?php foreach ($competitions_list as $c):
      $n = count(array_filter($demandes_attente, fn($d) => $d['id_competition'] == $c['id_competition']));
     ?>
      <a href="competition.php?tab=participants&filtre_comp=<?= $c['id_competition'] ?>" class="btn btn-sm <?= $filtre_comp == $c['id_competition'] ? 'btn-dark' : 'btn-outline-secondary' ?>">
       <?= htmlspecialchars($c['nom_competition']) ?><?php if ($n > 0): ?><span class="badge bg-danger ms-1"><?= $n ?></span><?php endif; ?>
      </a>
     <?php endforeach; ?>
    </div>
   </div>

   <?php $nb_conf = count(array_filter($participants, fn($p) => str_starts_with($p['statut_inscription'], 'confirm'))); ?>
   <h5 class="fw-bold text-secondary mb-3"><i class="bi bi-clipboard-check me-2 text-success"></i>Participants confirmés <span class="badge bg-success ms-2"><?= $nb_conf ?></span></h5>
   <div class="card shadow-sm p-0">
    <table class="table table-hover align-middle mb-0">
     <thead class="table-light">
      <tr><th class="ps-4">Cavalier</th><th>Mail</th><th>Compétition</th><th>Dossard</th><th>Catégorie</th><th>Date</th><th>Statut</th><th class="text-end pe-4">Actions</th></tr>
     </thead>
     <tbody>
     <?php if (empty($participants)): ?>
      <tr><td colspan="8" class="text-center text-muted py-4">Aucun participant.</td></tr>
     <?php else: foreach ($participants as $p): $ok = str_starts_with(mb_strtolower($p['statut_inscription']), 'confirm'); ?>
      <tr>
       <td class="ps-4 fw-bold"><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></td>
       <td><?= htmlspecialchars($p['mail'] ?? '—') ?></td>
       <td><?= htmlspecialchars($p['nom_competition']) ?></td>
       <td><?= !empty($p['numero_dossard']) ? '<span class="badge bg-secondary">#' . intval($p['numero_dossard']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
       <td><?= htmlspecialchars($p['categorie'] ?? '—') ?></td>
       <td><?= $p['date_inscription'] ? date('d/m/Y', strtotime($p['date_inscription'])) : '—' ?></td>
       <td><span class="badge <?= $ok ? 'bg-success' : 'bg-danger' ?>"><?= htmlspecialchars($p['statut_inscription']) ?></span></td>
       <td class="text-end pe-4">
        <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer ce participant ?')">
         <input type="hidden" name="action" value="supprimer_inscription">
         <input type="hidden" name="id_inscription" value="<?= $p['id_inscription'] ?>">
         <input type="hidden" name="filtre_comp" value="<?= $filtre_comp ?>">
         <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
        </form>
       </td>
      </tr>
     <?php endforeach; endif; ?>
     </tbody>
    </table>
   </div>
  </div><!-- /participants -->

  <!-- ════════════════════════════════════════════════════════
       ONGLET : NOTIFICATIONS
  ════════════════════════════════════════════════════════ -->
  <div class="tab-pane fade <?= $active_tab === 'notifications' ? 'show active' : '' ?>" id="pills-notifications">

   <!-- Section 1 : inscriptions compétitions -->
   <div class="mb-5">
    <div class="d-flex align-items-center gap-2 mb-3">
     <h5 class="fw-bold text-secondary mb-0"><i class="bi bi-person-check me-2"></i>Demandes d'inscription aux compétitions</h5>
     <?php if ($nb_attente > 0): ?><span class="badge bg-danger fs-6"><?= $nb_attente ?></span><?php endif; ?>
    </div>
    <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
     <label class="fw-bold text-secondary mb-0 small">Filtre :</label>
     <div class="d-flex gap-2 flex-wrap">
      <a href="competition.php?tab=notifications" class="btn btn-sm <?= !$filtre_comp ? 'btn-dark' : 'btn-outline-secondary' ?>">Toutes</a>
      <?php foreach ($competitions_list as $c):
       $n = count(array_filter($demandes_attente, fn($d) => $d['id_competition'] == $c['id_competition']));
      ?>
       <a href="competition.php?tab=notifications&filtre_comp=<?= $c['id_competition'] ?>" class="btn btn-sm <?= $filtre_comp == $c['id_competition'] ? 'btn-dark' : 'btn-outline-secondary' ?>">
        <?= htmlspecialchars($c['nom_competition']) ?><?php if ($n > 0): ?><span class="badge bg-danger ms-1"><?= $n ?></span><?php endif; ?>
       </a>
      <?php endforeach; ?>
     </div>
    </div>
    <div class="card shadow-sm <?= $nb_attente > 0 ? 'border-danger border-2' : '' ?> p-0">
     <table class="table table-hover align-middle mb-0">
      <thead <?= $nb_attente > 0 ? 'style="background:#fee2e2"' : 'class="table-light"' ?>>
       <tr><th class="ps-4">Cavalier</th><th>Mail</th><th>Compétition</th><th>Catégorie</th><th>Date</th><th class="text-end pe-4">Actions</th></tr>
      </thead>
      <tbody>
      <?php if (empty($demandes_attente)): ?>
       <tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-check-circle text-success me-2"></i>Aucune demande en attente.</td></tr>
      <?php else: foreach ($demandes_attente as $d): $ns = htmlspecialchars(addslashes($d['prenom'] . ' ' . $d['nom'])); ?>
       <tr>
        <td class="ps-4 fw-bold"><?= htmlspecialchars($d['prenom'] . ' ' . $d['nom']) ?></td>
        <td><?= htmlspecialchars($d['mail'] ?? '—') ?></td>
        <td><strong><?= htmlspecialchars($d['nom_competition']) ?></strong><br><small class="text-muted"><?= date('d/m/Y', strtotime($d['date_competition'])) ?></small></td>
        <td><?= htmlspecialchars($d['categorie'] ?? '—') ?></td>
        <td><?= date('d/m/Y', strtotime($d['date_inscription'])) ?></td>
        <td class="text-end pe-4">
         <div class="d-flex gap-2 justify-content-end">
          <form method="POST" class="d-inline" onsubmit="return confirm('Accepter <?= $ns ?> ?')">
           <input type="hidden" name="action" value="accepter_inscription">
           <input type="hidden" name="id_inscription" value="<?= $d['id_inscription'] ?>">
           <input type="hidden" name="filtre_comp" value="<?= $filtre_comp ?>">
           <button type="submit" class="btn btn-sm btn-success fw-bold"><i class="bi bi-check-lg me-1"></i>Accepter</button>
          </form>
          <form method="POST" class="d-inline" onsubmit="return confirm('Refuser <?= $ns ?> ?')">
           <input type="hidden" name="action" value="refuser_inscription">
           <input type="hidden" name="id_inscription" value="<?= $d['id_inscription'] ?>">
           <input type="hidden" name="filtre_comp" value="<?= $filtre_comp ?>">
           <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg me-1"></i>Refuser</button>
          </form>
         </div>
        </td>
       </tr>
      <?php endforeach; endif; ?>
      </tbody>
     </table>
    </div>
   </div><!-- /section 1 -->

   <hr class="my-5">

   <!-- Section 2 : inscriptions épreuves -->
   <div>
    <div class="d-flex align-items-center gap-2 mb-3">
     <h5 class="fw-bold text-secondary mb-0"><i class="bi bi-flag-fill me-2"></i>Demandes d'inscription aux épreuves</h5>
     <?php if ($nb_epreuves_attente > 0): ?><span class="badge bg-warning text-dark fs-6"><?= $nb_epreuves_attente ?></span><?php endif; ?>
    </div>
    <div class="d-flex flex-column gap-2 mb-3">
     <div class="d-flex align-items-center gap-2 flex-wrap">
      <label class="fw-bold text-secondary mb-0 small">Compétition :</label>
      <a href="competition.php?tab=notifications" class="btn btn-sm <?= !$filtre_comp && $filtre_type === 'ALL' ? 'btn-dark' : 'btn-outline-secondary' ?>">Toutes</a>
      <?php foreach ($competitions_list as $c):
       $n  = count(array_filter($demandes_epreuves_attente, fn($d) => $d['id_competition'] == $c['id_competition']));
       $tq = $filtre_type !== 'ALL' ? '&filtre_type=' . $filtre_type : '';
      ?>
       <a href="competition.php?tab=notifications&filtre_comp=<?= $c['id_competition'] ?><?= $tq ?>" class="btn btn-sm <?= $filtre_comp == $c['id_competition'] ? 'btn-dark' : 'btn-outline-secondary' ?>">
        <?= htmlspecialchars($c['nom_competition']) ?><?php if ($n > 0): ?><span class="badge bg-warning text-dark ms-1"><?= $n ?></span><?php endif; ?>
       </a>
      <?php endforeach; ?>
     </div>
     <div class="d-flex align-items-center gap-2 flex-wrap">
      <label class="fw-bold text-secondary mb-0 small">Type :</label>
      <?php $cq = $filtre_comp ? '&filtre_comp=' . $filtre_comp : '';
      foreach (['ALL'=>['Tous','info'],'POR'=>['POR','primary'],'MA'=>['MA','warning'],'PTV'=>['PTV','info']] as $tv=>[$tl,$tc]): ?>
       <a href="competition.php?tab=notifications<?= $cq ?><?= $tv !== 'ALL' ? '&filtre_type='.$tv : '' ?>" class="btn btn-sm <?= $filtre_type===$tv ? 'btn-'.$tc : 'btn-outline-secondary' ?>"><?= $tl ?></a>
      <?php endforeach; ?>
     </div>
    </div>
    <div class="card shadow-sm <?= $nb_epreuves_attente > 0 ? 'border-warning border-2' : '' ?> p-0">
     <table class="table table-hover align-middle mb-0">
      <thead <?= $nb_epreuves_attente > 0 ? 'style="background:#fef3c7"' : 'class="table-light"' ?>>
       <tr><th class="ps-4">Cavalier</th><th>Type</th><th>Épreuve</th><th>Compétition</th><th>Date demande</th><th class="text-end pe-4">Actions</th></tr>
      </thead>
      <tbody>
      <?php if (empty($demandes_epreuves_attente)): ?>
       <tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-check-circle text-success me-2"></i>Aucune demande en attente.</td></tr>
      <?php else: foreach ($demandes_epreuves_attente as $d): ?>
       <tr>
        <td class="ps-4 fw-bold"><?= htmlspecialchars($d['prenom_cavalier'] . ' ' . $d['nom_cavalier']) ?></td>
        <td><span class="badge bg-<?= epreuve_color($d['type_epreuve']) ?>"><?= htmlspecialchars($d['type_epreuve']) ?></span></td>
        <td><?= htmlspecialchars($d['nom_epreuve']) ?></td>
        <td><strong><?= htmlspecialchars($d['nom_competition']) ?></strong></td>
        <td><?= date('d/m/Y H:i', strtotime($d['date_inscription_epreuve'])) ?></td>
        <td class="text-end pe-4">
         <div class="d-flex gap-2 justify-content-end">
          <form method="POST" class="d-inline" onsubmit="return confirm('Accepter ?')">
           <input type="hidden" name="action" value="accepter_inscription_epreuve">
           <input type="hidden" name="id_inscription_epreuve" value="<?= $d['id_inscription_epreuve'] ?>">
           <button type="submit" class="btn btn-sm btn-success fw-bold"><i class="bi bi-check-lg me-1"></i>Accepter</button>
          </form>
          <form method="POST" class="d-inline" onsubmit="return confirm('Refuser ?')">
           <input type="hidden" name="action" value="refuser_inscription_epreuve">
           <input type="hidden" name="id_inscription_epreuve" value="<?= $d['id_inscription_epreuve'] ?>">
           <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg me-1"></i>Refuser</button>
          </form>
         </div>
        </td>
       </tr>
      <?php endforeach; endif; ?>
      </tbody>
     </table>
    </div>
   </div><!-- /section 2 -->
  </div><!-- /notifications -->

  <!-- ════════════════════════════════════════════════════════
       ONGLET : CONFIGURATION
  ════════════════════════════════════════════════════════ -->
  <div class="tab-pane fade <?= $active_tab === 'config' ? 'show active' : '' ?>" id="pills-config">
   <div class="row g-4">

    <!-- Formulaire compétition -->
    <div class="col-lg-5">
     <div class="card shadow-sm border-0 p-4">
      <h4 class="font-title mb-4 text-primary"><?= $competition_edit ? '<i class="bi bi-pencil-square me-2"></i>Modifier' : '<i class="bi bi-plus-circle me-2"></i>Créer une compétition' ?></h4>
      <form method="POST" action="competition.php?tab=config">
       <input type="hidden" name="action" value="<?= $competition_edit ? 'update_competition' : 'create_competition' ?>">
       <?php if ($competition_edit): ?><input type="hidden" name="id_competition" value="<?= intval($competition_edit['id_competition']) ?>"><?php endif; ?>
       <div class="mb-3">
        <label class="form-label fw-bold">Nom</label>
        <input type="text" class="form-control" name="nom_competition" value="<?= htmlspecialchars($competition_edit['nom_competition'] ?? '') ?>" required>
       </div>
       <div class="mb-3">
        <label class="form-label fw-bold">Date et heure de début</label>
        <div class="row g-2">
         <div class="col-md-7"><input type="date" class="form-control" name="date_competition" value="<?= htmlspecialchars($competition_edit['date_competition'] ?? '') ?>" required></div>
         <div class="col-md-5"><input type="time" class="form-control" name="heure_debut" value="<?= htmlspecialchars($competition_edit['heure_debut'] ?? '09:00') ?>" required></div>
        </div>
       </div>
       <?php if ($has_date_fin): ?>
       <div class="mb-3">
        <label class="form-label fw-bold">Date et heure de fin</label>
        <div class="row g-2">
         <div class="col-md-7"><input type="date" class="form-control" name="date_fin_competition" value="<?= htmlspecialchars($competition_edit['date_fin_competition'] ?? '') ?>" required></div>
         <div class="col-md-5"><input type="time" class="form-control" name="heure_fin" value="<?= htmlspecialchars($competition_edit['heure_fin'] ?? '17:00') ?>" required></div>
        </div>
       </div>
       <?php endif; ?>
       <div class="mb-3">
        <label class="form-label fw-bold">Lieu</label>
        <input type="text" class="form-control" name="lieu" value="<?= htmlspecialchars($competition_edit['lieu'] ?? '') ?>">
       </div>
       <div class="mb-3">
        <label class="form-label fw-bold">Description</label>
        <textarea class="form-control" name="description" rows="3"><?= htmlspecialchars($competition_edit['description'] ?? '') ?></textarea>
       </div>
       <div class="mb-3">
        <label class="form-label fw-bold">Statut</label>
        <div class="form-check">
         <input class="form-check-input" type="checkbox" id="draft_mode" name="draft_mode" value="1" <?= ($competition_edit['statut'] ?? 'draft') === 'draft' ? 'checked' : '' ?>>
         <label class="form-check-label fw-bold" for="draft_mode">🔒 Mode brouillon (non visible aux cavaliers)</label>
        </div>
        <small class="text-muted d-block mt-2">La compétition se lancera automatiquement à la date/heure de début.<br>Elle se fermera automatiquement à la date/heure de fin.</small>
       </div>
       <div class="d-flex gap-2">
        <button type="submit" class="btn btn-success fw-bold"><i class="bi bi-save me-1"></i><?= $competition_edit ? 'Mettre à jour' : 'Créer' ?></button>
        <?php if ($competition_edit): ?><a href="competition.php?tab=config" class="btn btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Annuler</a><?php endif; ?>
       </div>
      </form>
     </div>
    </div>

    <!-- Liste des compétitions -->
    <div class="col-lg-7">
     <div class="card shadow-sm border-0 p-4">
      <h5 class="fw-bold mb-3 text-secondary"><i class="bi bi-list-ul me-2"></i>Compétitions (<?= count($competitions_list) ?>)</h5>
      <?php if (empty($competitions_list)): ?>
       <p class="text-muted text-center py-4">Aucune compétition.</p>
      <?php else: ?>
      <div class="table-responsive">
       <table class="table table-hover align-middle">
        <thead class="table-light">
         <tr>
          <th>Nom</th>
          <th>Date et heure début</th>
          <?php if ($has_date_fin): ?><th>Date et heure fin</th><?php endif; ?>
          <th>Statut</th>
          <th class="text-end">Actions</th>
         </tr>
        </thead>
        <tbody>
        <?php foreach ($competitions_list as $c):
         $d       = $detail_data[$c['id_competition']];
         $nb_conf = $d['stats']['confirmee'];
         $nb_att  = count($d['att_list']);
        ?>
         <tr <?= ($competition_edit && $competition_edit['id_competition'] == $c['id_competition']) ? 'class="table-warning"' : '' ?>>
          <td>
           <button class="btn btn-link p-0 fw-bold text-start text-decoration-none" data-bs-toggle="modal" data-bs-target="#modalDetail<?= $c['id_competition'] ?>">
            <?= htmlspecialchars($c['nom_competition']) ?>
            <?php if ($nb_att > 0): ?><span class="badge bg-warning text-dark ms-1"><?= $nb_att ?></span><?php endif; ?>
           </button>
           <small class="text-muted d-block"><?= $nb_conf ?> participant<?= $nb_conf > 1 ? 's' : '' ?> confirmé<?= $nb_conf > 1 ? 's' : '' ?></small>
          </td>
          <td>
           <small><?= date('d/m/Y', strtotime($c['date_competition'])) ?></small><br>
           <strong><?= date('H:i', strtotime($c['heure_debut'] ?? '09:00')) ?></strong>
          </td>
          <?php if ($has_date_fin && !empty($c['date_fin_competition'])): ?>
          <td>
           <small><?= date('d/m/Y', strtotime($c['date_fin_competition'])) ?></small><br>
           <strong><?= date('H:i', strtotime($c['heure_fin'] ?? '17:00')) ?></strong>
          </td>
          <?php endif; ?>
          <td><?= statut_badge($c['statut'] ?? 'draft') ?></td>
          <td class="text-end">
           <button class="btn btn-sm btn-outline-info me-1" data-bs-toggle="modal" data-bs-target="#modalDetail<?= $c['id_competition'] ?>"><i class="bi bi-eye"></i></button>
           <a href="competition.php?tab=config&edit=<?= $c['id_competition'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
           <form method="POST" action="competition.php?tab=config" class="d-inline" onsubmit="return confirm('Supprimer ?')">
            <input type="hidden" name="action" value="delete_competition">
            <input type="hidden" name="id_competition" value="<?= $c['id_competition'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
           </form>
          </td>
         </tr>
        <?php endforeach; ?>
        </tbody>
       </table>
      </div>
      <?php endif; ?>
     </div>
    </div>
   </div>
  </div><!-- /config -->

  <!-- ════════════════════════════════════════════════════════
       ONGLET : ÉPREUVES & PARCOURS
  ════════════════════════════════════════════════════════ -->
  <div class="tab-pane fade <?= $active_tab === 'events' ? 'show active' : '' ?>" id="pills-events">

   <!-- Légende des types d'épreuve -->
   <div class="alert alert-light border d-flex flex-wrap gap-3 align-items-start mb-4 py-3" role="alert">
    <span class="fw-bold text-secondary small me-1"><i class="bi bi-question-circle me-1"></i>Types d'épreuve :</span>
    <?php foreach ([
     'POR' => ['primary', '🧭', 'POR', 'Parcours d\'Orientation et de Régularité — suivre un itinéraire balisé en respectant un temps idéal.'],
     'MA'  => ['warning', '🐴', 'MA',  'Maîtrise des Allures — travailler le cheval à différentes allures dans des corridors délimités.'],
     'PTV' => ['info',    '🌿', 'PTV', 'Parcours en Terrain Varié — franchir des obstacles naturels et artificiels.'],
    ] as [$color, $emoji, $label, $desc]): ?>
     <span class="d-inline-flex align-items-center gap-1 small">
      <span class="badge bg-<?= $color ?>"><?= $emoji ?> <?= $label ?></span>
      <span class="text-muted"><?= htmlspecialchars($desc) ?></span>
     </span>
    <?php endforeach; ?>
   </div>

   <!-- Bloc épreuves -->
   <div class="card shadow-sm border-0 p-4 mb-4">
    <h4 class="font-title mb-4 text-primary"><i class="bi bi-flag me-2"></i>Gestion des épreuves</h4>
    <div class="row g-4">

     <!-- Formulaire épreuve -->
     <div class="col-lg-5">
      <h6 class="fw-bold text-secondary mb-3"><?= $epreuve_edit ? '<i class="bi bi-pencil-square me-1"></i>Modifier' : '<i class="bi bi-plus-circle me-1"></i>Nouvelle épreuve' ?></h6>
      <form method="POST" action="competition.php?tab=events">
       <input type="hidden" name="action" value="<?= $epreuve_edit ? 'update_epreuve' : 'create_epreuve' ?>">
       <?php if ($epreuve_edit): ?><input type="hidden" name="id_epreuve" value="<?= intval($epreuve_edit['id_epreuve']) ?>"><?php endif; ?>
       <div class="mb-3">
        <label class="form-label small fw-bold">Nom</label>
        <input type="text" class="form-control form-control-sm" name="nom_epreuve" value="<?= htmlspecialchars($epreuve_edit['nom_epreuve'] ?? '') ?>" required>
       </div>
       <div class="mb-3">
        <label class="form-label small fw-bold">Compétition</label>
        <select class="form-select form-select-sm" name="id_competition_epreuve" required>
         <option value="">— Choisir —</option>
         <?php foreach ($competitions_list as $c): ?>
          <option value="<?= $c['id_competition'] ?>" <?= ($epreuve_edit['id_competition'] ?? 0) == $c['id_competition'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nom_competition']) ?></option>
         <?php endforeach; ?>
        </select>
       </div>
       <div class="mb-3">
        <label class="form-label small fw-bold">Type</label>
        <select class="form-select form-select-sm" name="type_epreuve" id="select_type_epreuve" required>
         <?php foreach (['POR' => 'POR — Orientation & Régularité', 'MA' => 'MA — Maîtrise des Allures', 'PTV' => 'PTV — Parcours Tout-Terrain'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= ($epreuve_edit['type_epreuve'] ?? 'POR') === $v ? 'selected' : '' ?>><?= $l ?></option>
         <?php endforeach; ?>
        </select>
       </div>
       <div class="mb-3">
        <label class="form-label small fw-bold">Parcours (optionnel)</label>
        <select class="form-select form-select-sm" name="id_parcours_epreuve">
         <option value="">— Aucun —</option>
         <?php foreach ($parcours_list as $p): ?>
          <option value="<?= $p['id_parcours'] ?>" <?= ($epreuve_edit['id_parcours'] ?? 0) == $p['id_parcours'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nom_parcours']) ?></option>
         <?php endforeach; ?>
        </select>
       </div>
       <div class="mb-3">
        <label class="form-label small fw-bold">Description</label>
        <textarea class="form-control form-control-sm" name="description_epreuve" rows="2"><?= htmlspecialchars($epreuve_edit['description'] ?? '') ?></textarea>
       </div>
       <div class="d-flex gap-2">
        <button type="submit" class="btn btn-success btn-sm fw-bold"><i class="bi bi-save me-1"></i><?= $epreuve_edit ? 'Mettre à jour' : 'Créer' ?></button>
        <?php if ($epreuve_edit): ?><a href="competition.php?tab=events" class="btn btn-outline-secondary btn-sm">Annuler</a><?php endif; ?>
       </div>
      </form>
     </div>

     <!-- Liste des épreuves -->
     <div class="col-lg-7">
      <h6 class="fw-bold text-secondary mb-3">Épreuves (<?= count($epreuves) ?>)</h6>
      <?php if (empty($epreuves)): ?>
       <p class="text-muted small">Aucune épreuve.</p>
      <?php else: ?>
      <div class="table-responsive" style="max-height:350px;overflow-y:auto">
       <table class="table table-sm table-hover align-middle">
        <thead class="table-light">
         <tr><th>Nom</th><th>Type</th><th>Compétition</th><th>Parcours</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($epreuves as $ep):
         $id_ep      = $ep['id_epreuve'];
         $nb_part_ep = count($epreuves_modal_data[$id_ep]['participants']);
        ?>
         <tr <?= ($epreuve_edit && $epreuve_edit['id_epreuve'] == $id_ep) ? 'class="table-warning"' : '' ?>>
          <td>
           <button class="btn btn-link p-0 fw-bold text-start text-decoration-none text-dark" data-bs-toggle="modal" data-bs-target="#modalEpreuve<?= $id_ep ?>"><?= htmlspecialchars($ep['nom_epreuve']) ?></button>
           <small class="text-muted d-block"><?= $nb_part_ep ?> participant<?= $nb_part_ep > 1 ? 's' : '' ?></small>
          </td>
          <td><span class="badge bg-<?= epreuve_color($ep['type_epreuve'] ?? 'POR') ?>"><?= htmlspecialchars($ep['type_epreuve'] ?? 'POR') ?></span></td>
          <td class="small"><?= htmlspecialchars($ep['nom_competition'] ?? '—') ?></td>
          <td class="small"><?= !empty($ep['nom_parcours']) ? '<span class="badge bg-info text-dark">' . htmlspecialchars($ep['nom_parcours']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
          <td class="text-end">
           <button class="btn btn-xs btn-outline-info me-1" data-bs-toggle="modal" data-bs-target="#modalEpreuve<?= $id_ep ?>"><i class="bi bi-eye"></i></button>
           <a href="competition.php?tab=events&edit_epreuve=<?= $id_ep ?>" class="btn btn-xs btn-outline-primary"><i class="bi bi-pencil"></i></a>
           <form method="POST" action="competition.php?tab=events" class="d-inline" onsubmit="return confirm('Supprimer ?')">
            <input type="hidden" name="action" value="delete_epreuve">
            <input type="hidden" name="id_epreuve" value="<?= $id_ep ?>">
            <button type="submit" class="btn btn-xs btn-outline-danger"><i class="bi bi-trash"></i></button>
           </form>
          </td>
         </tr>
        <?php endforeach; ?>
        </tbody>
       </table>
      </div>
      <?php endif; ?>
     </div>
    </div>
   </div>

   <!-- Carte + panneau infos du parcours sélectionné -->
   <?php if ($parcours_actif): ?>
    <div class="row g-4">
     <div class="col-md-3">
      <div class="card shadow-sm border-0 h-100 p-4">
       <h6 class="fw-bold text-primary mb-3"><i class="bi bi-info-circle me-2"></i>Infos parcours</h6>
       <p class="mb-2"><span class="text-muted small">Nom</span><br><strong><?= htmlspecialchars($parcours_actif['nom_parcours']) ?></strong></p>
       <p class="mb-2"><span class="text-muted small">Distance</span><br><strong><?= number_format($parcours_actif['distance_km'], 2) ?> km</strong></p>
       <hr>
       <div class="d-grid gap-2">
        <a href="competition.php?tab=events&edit_parcours=<?= $parcours_actif['id_parcours'] ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Modifier</a>
        <form method="POST" action="competition.php?tab=events" onsubmit="return confirm('Supprimer ce parcours ?')">
         <input type="hidden" name="action" value="delete_parcours">
         <input type="hidden" name="id_parcours" value="<?= $parcours_actif['id_parcours'] ?>">
         <button type="submit" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-trash me-1"></i>Supprimer</button>
        </form>
       </div>
       <hr>
       <form method="POST" action="competition.php">
        <input type="hidden" name="action" value="assigner_parcours">
        <input type="hidden" name="id_parcours" value="<?= $parcours_actif['id_parcours'] ?>">
        <label class="form-label small fw-bold">Assigner à une épreuve</label>
        <?php if (empty($epreuves_simple)): ?>
         <p class="text-muted small">Aucune épreuve.</p>
        <?php else: ?>
         <select class="form-select form-select-sm mb-2" name="id_epreuve" required>
          <option value="">— Choisir —</option>
          <?php foreach ($epreuves_simple as $ep): ?><option value="<?= $ep['id_epreuve'] ?>"><?= htmlspecialchars($ep['nom_epreuve']) ?></option><?php endforeach; ?>
         </select>
         <button type="submit" class="btn btn-warning btn-sm w-100 fw-bold"><i class="bi bi-link-45deg me-1"></i>Assigner</button>
        <?php endif; ?>
       </form>
      </div>
     </div>
     <div class="col-md-9">
      <div class="card shadow-sm border-0"><div class="card-body p-0">
       <?php if ($parcours_actif_geojson_valid): ?>
        <div id="map-parcours" style="height:500px;border-radius:12px"></div>
       <?php else: ?>
        <div class="d-flex align-items-center justify-content-center" style="height:200px"><p class="text-muted"><i class="bi bi-exclamation-triangle me-2"></i>Aucun tracé GeoJSON valide.</p></div>
       <?php endif; ?>
      </div></div>
     </div>
    </div>
   <?php endif; ?>

  </div><!-- /events -->

 </div><!-- /tab-content -->
</div><!-- /container -->
</main>

<!-- ════════════════════════════════════════════════════════════
     MODALS : DÉTAIL COMPÉTITION
════════════════════════════════════════════════════════════ -->
<?php foreach ($competitions_list as $c):
    $id      = $c['id_competition'];
    $d       = $detail_data[$id];
    $nb_conf = $d['stats']['confirmee'];
    $nb_att  = $d['stats']['en_attente'];
    $nb_ref  = $d['stats']['refusee'];
    $nb_ep   = count($d['ep_list']);
    $total   = $nb_conf + $nb_att + $nb_ref;
?>
<div class="modal fade" id="modalDetail<?= $id ?>" tabindex="-1">
 <div class="modal-dialog modal-xl modal-dialog-scrollable">
  <div class="modal-content">
   <div class="modal-header" style="background:#1a1a2e">
    <div>
     <h5 class="modal-title text-white fw-bold mb-1"><i class="bi bi-trophy-fill text-warning me-2"></i><?= htmlspecialchars($c['nom_competition']) ?></h5>
     <div class="d-flex gap-2 align-items-center flex-wrap">
      <?= statut_badge($c['statut'] ?? 'draft') ?>
      <small class="text-white-50">
       <i class="bi bi-calendar-event me-1"></i><?= date('d/m/Y', strtotime($c['date_competition'])) ?> à <strong><?= date('H:i', strtotime($c['heure_debut'] ?? '09:00')) ?></strong>
       <?php if ($has_date_fin && !empty($c['date_fin_competition'])): ?>
        → <?= date('d/m/Y', strtotime($c['date_fin_competition'])) ?> à <strong><?= date('H:i', strtotime($c['heure_fin'] ?? '17:00')) ?></strong>
       <?php endif; ?>
      </small>
      <?php if (!empty($c['lieu'])): ?><small class="text-white-50"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($c['lieu']) ?></small><?php endif; ?>
     </div>
    </div>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
   </div>
   <div class="modal-body p-0">
    <ul class="nav nav-tabs px-3 pt-2 bg-light border-bottom">
     <li class="nav-item"><button class="nav-link active fw-bold" data-bs-toggle="tab" data-bs-target="#dtab-infos-<?= $id ?>"><i class="bi bi-info-circle me-1"></i>Infos & Stats</button></li>
     <li class="nav-item">
      <button class="nav-link fw-bold position-relative" data-bs-toggle="tab" data-bs-target="#dtab-attente-<?= $id ?>">
       <i class="bi bi-hourglass-split me-1"></i>En attente
       <?php if ($nb_att > 0): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:9px"><?= $nb_att ?></span><?php endif; ?>
      </button>
     </li>
     <li class="nav-item"><button class="nav-link fw-bold" data-bs-toggle="tab" data-bs-target="#dtab-part-<?= $id ?>"><i class="bi bi-people me-1"></i>Participants <span class="badge bg-success ms-1"><?= $nb_conf ?></span></button></li>
     <li class="nav-item"><button class="nav-link fw-bold" data-bs-toggle="tab" data-bs-target="#dtab-ep-<?= $id ?>"><i class="bi bi-flag me-1"></i>Épreuves <span class="badge bg-primary ms-1"><?= $nb_ep ?></span></button></li>
     <li class="nav-item"><button class="nav-link fw-bold" data-bs-toggle="tab" data-bs-target="#dtab-fil-<?= $id ?>"><i class="bi bi-clock-history me-1"></i>Activité</button></li>
    </ul>
    <div class="tab-content p-3">

     <!-- Infos & Stats -->
     <div class="tab-pane fade show active" id="dtab-infos-<?= $id ?>">
      <div class="row g-3 mb-4">
       <?php foreach ([['text-success','border-success',$nb_conf,'Confirmés'],['text-warning','border-warning',$nb_att,'En attente'],['text-danger','border-danger',$nb_ref,'Refusés'],['text-primary','border-primary',$nb_ep,'Épreuves']] as [$tc,$bc,$val,$lbl]): ?>
        <div class="col-6 col-md-3"><div class="card text-center p-3 <?= $bc ?> border-2"><div class="fs-1 fw-bold <?= $tc ?>"><?= $val ?></div><div class="small text-muted"><?= $lbl ?></div></div></div>
       <?php endforeach; ?>
      </div>
      <div class="row g-4">
       <div class="col-md-5">
        <div class="card p-3 h-100">
         <h6 class="fw-bold text-secondary mb-3"><i class="bi bi-pie-chart me-2"></i>Répartition</h6>
         <?php if ($total > 0): ?><canvas id="donut<?= $id ?>" height="220"></canvas>
         <?php else: ?><div class="d-flex align-items-center justify-content-center h-100"><p class="text-muted">Aucune inscription.</p></div><?php endif; ?>
        </div>
       </div>
       <div class="col-md-7">
        <div class="card p-3 h-100">
         <h6 class="fw-bold text-secondary mb-3"><i class="bi bi-file-text me-2"></i>Informations</h6>
         <table class="table table-sm table-borderless mb-0">
          <tr><td class="text-muted" style="width:35%">Nom</td><td><strong><?= htmlspecialchars($c['nom_competition']) ?></strong></td></tr>
          <tr><td class="text-muted">Date début</td><td><?= date('d/m/Y H:i', strtotime($c['date_competition'] . ' ' . ($c['heure_debut'] ?? '09:00'))) ?></td></tr>
          <?php if ($has_date_fin && !empty($c['date_fin_competition'])): ?>
          <tr><td class="text-muted">Date fin</td><td><?= date('d/m/Y H:i', strtotime($c['date_fin_competition'] . ' ' . ($c['heure_fin'] ?? '17:00'))) ?></td></tr>
          <?php endif; ?>
          <tr><td class="text-muted">Lieu</td><td><?= htmlspecialchars($c['lieu'] ?? '—') ?></td></tr>
          <tr><td class="text-muted">Statut</td><td><?= statut_badge($c['statut'] ?? 'draft') ?></td></tr>
          <tr><td class="text-muted">Total inscrits</td><td><strong><?= $total ?></strong></td></tr>
         </table>
         <?php if (!empty($c['description'])): ?><hr><h6 class="fw-bold text-secondary small">Description</h6><p class="small text-muted mb-0"><?= nl2br(htmlspecialchars($c['description'])) ?></p><?php endif; ?>
         <hr><a href="competition.php?tab=config&edit=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Modifier</a>
        </div>
       </div>
      </div>
     </div>

     <!-- En attente -->
     <div class="tab-pane fade" id="dtab-attente-<?= $id ?>">
      <?php if (empty($d['att_list'])): ?>
       <div class="text-center py-5"><i class="bi bi-check-circle text-success fs-1"></i><p class="text-muted mt-2">Aucune demande en attente.</p></div>
      <?php else: ?>
       <div class="table-responsive"><table class="table table-hover align-middle">
        <thead style="background:#fff8e1"><tr><th>Cavalier</th><th>Mail</th><th>Catégorie</th><th>Date demande</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($d['att_list'] as $att): ?>
         <tr>
          <td class="fw-bold"><?= htmlspecialchars($att['prenom'] . ' ' . $att['nom']) ?></td>
          <td><?= htmlspecialchars($att['mail'] ?? '—') ?></td>
          <td><?= htmlspecialchars($att['categorie'] ?? '—') ?></td>
          <td><?= date('d/m/Y', strtotime($att['date_inscription'])) ?></td>
          <td class="text-end">
           <div class="d-flex gap-2 justify-content-end">
            <form method="POST" class="d-inline" onsubmit="return confirm('Accepter ?')">
             <input type="hidden" name="action" value="accepter_inscription">
             <input type="hidden" name="id_inscription" value="<?= $att['id_inscription'] ?>">
             <input type="hidden" name="filtre_comp" value="<?= $id ?>">
             <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Accepter</button>
            </form>
            <form method="POST" class="d-inline" onsubmit="return confirm('Refuser ?')">
             <input type="hidden" name="action" value="refuser_inscription">
             <input type="hidden" name="id_inscription" value="<?= $att['id_inscription'] ?>">
             <input type="hidden" name="filtre_comp" value="<?= $id ?>">
             <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg me-1"></i>Refuser</button>
            </form>
           </div>
          </td>
         </tr>
        <?php endforeach; ?>
        </tbody>
       </table></div>
      <?php endif; ?>
     </div>

     <!-- Participants confirmés -->
     <div class="tab-pane fade" id="dtab-part-<?= $id ?>">
      <?php if (empty($d['part_list'])): ?>
       <div class="text-center py-5"><i class="bi bi-people text-muted fs-1"></i><p class="text-muted mt-2">Aucun participant confirmé.</p></div>
      <?php else: ?>
       <div class="table-responsive"><table class="table table-hover align-middle">
        <thead class="table-light"><tr><th>#</th><th>Cavalier</th><th>Mail</th><th>Catégorie</th><th>Dossard</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($d['part_list'] as $i => $p): ?>
         <tr>
          <td class="text-muted small"><?= $i + 1 ?></td>
          <td class="fw-bold"><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></td>
          <td><?= htmlspecialchars($p['mail'] ?? '—') ?></td>
          <td><?= htmlspecialchars($p['categorie'] ?? '—') ?></td>
          <td><?= !empty($p['numero_dossard']) ? '<span class="badge bg-secondary">#' . intval($p['numero_dossard']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
          <td class="text-end">
           <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer ?')">
            <input type="hidden" name="action" value="supprimer_inscription">
            <input type="hidden" name="id_inscription" value="<?= $p['id_inscription'] ?>">
            <input type="hidden" name="filtre_comp" value="<?= $id ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
           </form>
          </td>
         </tr>
        <?php endforeach; ?>
        </tbody>
       </table></div>
      <?php endif; ?>
     </div>

     <!-- Épreuves de la compétition -->
     <div class="tab-pane fade" id="dtab-ep-<?= $id ?>">
      <?php if (empty($d['ep_list'])): ?>
       <div class="text-center py-5"><i class="bi bi-flag text-muted fs-1"></i><p class="text-muted mt-2">Aucune épreuve définie.</p></div>
      <?php else: ?>
       <div class="row g-3">
        <?php foreach ($d['ep_list'] as $ep): ?>
         <div class="col-md-6"><div class="card p-3 h-100">
          <div class="d-flex justify-content-between align-items-start">
           <h6 class="fw-bold mb-1"><?= htmlspecialchars($ep['nom_epreuve']) ?></h6>
           <div class="d-flex gap-1">
            <button class="btn btn-xs btn-outline-info" data-bs-toggle="modal" data-bs-target="#modalEpreuve<?= $ep['id_epreuve'] ?>"><i class="bi bi-eye"></i></button>
            <a href="competition.php?tab=events&edit_epreuve=<?= $ep['id_epreuve'] ?>" class="btn btn-xs btn-outline-primary"><i class="bi bi-pencil"></i></a>
           </div>
          </div>
          <div class="mb-2">
           <span class="badge bg-<?= epreuve_color($ep['type_epreuve'] ?? 'POR') ?>"><?= htmlspecialchars($ep['type_epreuve'] ?? 'POR') ?></span>
          </div>
          <?php if (!empty($ep['nom_parcours'])): ?>
           <span class="badge bg-info text-dark mb-2" style="width:fit-content"><i class="bi bi-map me-1"></i><?= htmlspecialchars($ep['nom_parcours']) ?> — <?= number_format($ep['distance_km'], 2) ?> km</span>
          <?php else: ?><span class="badge bg-light text-muted mb-2" style="width:fit-content">Aucun parcours</span><?php endif; ?>
          <?php if (!empty($ep['description'])): ?><p class="small text-muted mb-0"><?= htmlspecialchars($ep['description']) ?></p><?php endif; ?>
         </div></div>
        <?php endforeach; ?>
       </div>
      <?php endif; ?>
     </div>

     <!-- Fil d'activité -->
     <div class="tab-pane fade" id="dtab-fil-<?= $id ?>">
      <?php if (empty($d['fil_list'])): ?>
       <div class="text-center py-5"><i class="bi bi-clock-history text-muted fs-1"></i><p class="text-muted mt-2">Aucune activité.</p></div>
      <?php else: ?>
       <ul class="list-group list-group-flush">
        <?php foreach ($d['fil_list'] as $f):
         $is_ok   = str_starts_with(mb_strtolower($f['statut_inscription']), 'confirm');
         $is_wait = $f['statut_inscription'] === 'en_attente';
         $icon    = $is_ok ? 'bi-check-circle-fill text-success' : ($is_wait ? 'bi-hourglass-split text-warning' : 'bi-x-circle-fill text-danger');
         $label   = $is_ok ? 'confirmée' : ($is_wait ? 'en attente' : 'refusée');
        ?>
         <li class="list-group-item d-flex align-items-center gap-3 py-2">
          <i class="bi <?= $icon ?> fs-5"></i>
          <div class="flex-grow-1"><strong><?= htmlspecialchars($f['prenom'] . ' ' . $f['nom']) ?></strong><span class="text-muted small"> — Inscription <?= $label ?></span></div>
          <small class="text-muted"><?= date('d/m/Y', strtotime($f['date_inscription'])) ?></small>
         </li>
        <?php endforeach; ?>
       </ul>
      <?php endif; ?>
     </div>

    </div><!-- /tab-content modal compétition -->
   </div><!-- /modal-body -->
   <div class="modal-footer">
    <a href="competition.php?tab=participants&filtre_comp=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-people me-1"></i>Gérer tous les participants</a>
    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
   </div>
  </div>
 </div>
</div>
<?php endforeach; ?>

<!-- ════════════════════════════════════════════════════════════
     MODALS : DÉTAIL ÉPREUVE
════════════════════════════════════════════════════════════ -->
<?php foreach ($epreuves as $ep):
    $id_ep         = $ep['id_epreuve'];
    $md            = $epreuves_modal_data[$id_ep];
    $nb_part       = count($md['participants']);
    $geojson_valid = $md['geojson_valid'];
?>
<div class="modal fade" id="modalEpreuve<?= $id_ep ?>" tabindex="-1">
 <div class="modal-dialog modal-xl modal-dialog-scrollable">
  <div class="modal-content">
   <div class="modal-header" style="background:#0f3460">
    <div>
     <h5 class="modal-title text-white fw-bold mb-1"><i class="bi bi-flag-fill text-warning me-2"></i><?= htmlspecialchars($ep['nom_epreuve']) ?></h5>
     <div class="d-flex gap-3 align-items-center flex-wrap">
      <span class="badge bg-<?= epreuve_color($ep['type_epreuve'] ?? 'POR') ?>"><?= htmlspecialchars($ep['type_epreuve'] ?? 'POR') ?></span>
      <small class="text-white-50"><i class="bi bi-trophy me-1"></i><?= htmlspecialchars($ep['nom_competition'] ?? '—') ?></small>
      <?php if (!empty($ep['nom_parcours'])): ?><small class="text-white-50"><i class="bi bi-map me-1"></i><?= htmlspecialchars($ep['nom_parcours']) ?> · <?= number_format($ep['distance_km'], 2) ?> km</small><?php endif; ?>
     </div>
    </div>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
   </div>
   <div class="modal-body">
    <div class="row g-4">
     <div class="col-md-<?= $geojson_valid ? '5' : '12' ?>">
      <div class="row g-2 mb-4">
       <div class="col-6"><div class="card text-center p-3 border-success border-2 h-100"><div class="fs-2 fw-bold text-success"><?= $nb_part ?></div><div class="small text-muted">Participant<?= $nb_part > 1 ? 's' : '' ?></div></div></div>
       <div class="col-6"><div class="card text-center p-3 border-primary border-2 h-100"><div class="fs-2 fw-bold text-primary"><?= !empty($ep['distance_km']) ? number_format($ep['distance_km'], 2) . ' km' : '—' ?></div><div class="small text-muted">Distance</div></div></div>
      </div>
      <div class="card p-3 mb-3 border-0 bg-light">
       <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-info-circle me-1"></i>Informations</h6>
       <table class="table table-sm table-borderless mb-0">
        <tr><td class="text-muted" style="width:40%">Compétition</td><td><strong><?= htmlspecialchars($ep['nom_competition'] ?? '—') ?></strong></td></tr>
        <tr><td class="text-muted">Type</td><td><span class="badge bg-<?= epreuve_color($ep['type_epreuve'] ?? 'POR') ?>"><?= htmlspecialchars($ep['type_epreuve'] ?? 'POR') ?></span></td></tr>
        <tr><td class="text-muted">Parcours</td><td><?= !empty($ep['nom_parcours']) ? '<span class="badge bg-info text-dark"><i class="bi bi-map me-1"></i>' . htmlspecialchars($ep['nom_parcours']) . '</span>' : '<span class="text-muted small">Aucun</span>' ?></td></tr>
        <tr><td class="text-muted">Distance</td><td><?= !empty($ep['distance_km']) ? number_format($ep['distance_km'], 2) . ' km' : '<span class="text-muted">—</span>' ?></td></tr>
       </table>
      </div>
      <?php if (!empty($ep['description'])): ?>
       <div class="card p-3 mb-3 border-start border-4 border-primary bg-light">
        <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-file-text me-1"></i>Description</h6>
        <p class="small text-muted mb-0"><?= nl2br(htmlspecialchars($ep['description'])) ?></p>
       </div>
      <?php endif; ?>
      <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-people me-1"></i>Participants confirmés <span class="badge bg-success ms-1"><?= $nb_part ?></span></h6>
      <?php if (empty($md['participants'])): ?>
       <div class="text-center py-4 bg-light rounded"><i class="bi bi-people text-muted fs-3 d-block mb-1"></i><span class="text-muted small">Aucun participant confirmé.</span></div>
      <?php else: ?>
       <div class="table-responsive" style="max-height:300px;overflow-y:auto">
        <table class="table table-sm table-hover align-middle mb-0">
         <thead class="table-light" style="position:sticky;top:0;z-index:1"><tr><th style="width:30px">#</th><th>Cavalier</th><th>Catégorie</th><th>Dossard</th></tr></thead>
         <tbody>
         <?php foreach ($md['participants'] as $i => $p): ?>
          <tr>
           <td class="text-muted small"><?= $i + 1 ?></td>
           <td>
            <div class="fw-bold small"><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></div>
            <?php if (!empty($p['mail'])): ?><div class="text-muted" style="font-size:11px"><?= htmlspecialchars($p['mail']) ?></div><?php endif; ?>
           </td>
           <td><?= !empty($p['categorie']) ? '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle small">' . htmlspecialchars($p['categorie']) . '</span>' : '<span class="text-muted small">—</span>' ?></td>
           <td><?= !empty($p['numero_dossard']) ? '<span class="badge bg-dark">#' . intval($p['numero_dossard']) . '</span>' : '<span class="text-muted small">—</span>' ?></td>
          </tr>
         <?php endforeach; ?>
         </tbody>
        </table>
       </div>
      <?php endif; ?>
     </div>
     <?php if ($geojson_valid): ?>
      <div class="col-md-7">
       <div class="card border-0 shadow-sm overflow-hidden" style="height:100%;min-height:420px">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex align-items-center gap-2">
         <i class="bi bi-map text-primary"></i><span class="fw-bold small text-secondary">Tracé du parcours</span>
         <?php if (!empty($ep['nom_parcours'])): ?><span class="badge bg-info text-dark ms-auto"><?= htmlspecialchars($ep['nom_parcours']) ?></span><?php endif; ?>
        </div>
        <div id="map-epreuve-<?= $id_ep ?>" style="height:100%;min-height:380px"></div>
       </div>
      </div>
     <?php endif; ?>
    </div>
   </div>
   <div class="modal-footer bg-light">
    <a href="competition.php?tab=events&edit_epreuve=<?= $id_ep ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Modifier l'épreuve</a>
    <?php if (!empty($ep['id_parcours'])): ?><a href="competition.php?tab=events&parcours_id=<?= $ep['id_parcours'] ?>" class="btn btn-outline-info btn-sm"><i class="bi bi-map me-1"></i>Voir le parcours complet</a><?php endif; ?>
    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
   </div>
  </div>
 </div>
</div>
<?php endforeach; ?>

<?php include '../footer.php'; ?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<?php if ($parcours_actif && $parcours_actif_geojson_valid): ?>
<script>
(function () {
    const map = L.map('map-parcours');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);
    const layer = L.geoJSON(<?= $parcours_actif['trace_geojson'] ?>, { style: { color: '#f59e0b', weight: 4, opacity: .9 } }).addTo(map);
    map.fitBounds(layer.getBounds(), { padding: [20, 20] });
})();
</script>
<?php endif; ?>

<script>
<?php foreach ($competitions_list as $c):
    $id    = $c['id_competition'];
    $d     = $detail_data[$id];
    $total = $d['stats']['confirmee'] + $d['stats']['en_attente'] + $d['stats']['refusee'];
    if ($total === 0) continue;
?>
document.getElementById('modalDetail<?= $id ?>').addEventListener('shown.bs.modal', function () {
    const ctx = document.getElementById('donut<?= $id ?>');
    if (!ctx || ctx.dataset.rendered) return;
    ctx.dataset.rendered = '1';
    new Chart(ctx, {
        type: 'doughnut',
        data: { labels: ['Confirmés','En attente','Refusés'], datasets: [{ data: [<?= $d['stats']['confirmee'] ?>,<?= $d['stats']['en_attente'] ?>,<?= $d['stats']['refusee'] ?>], backgroundColor: ['#10b981','#f59e0b','#ef4444'], borderWidth: 2, borderColor: '#fff' }] },
        options: { responsive: true, cutout: '65%', plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: c => ' ' + c.label + ' : ' + c.raw + ' (' + Math.round(c.raw / <?= $total ?> * 100) + '%)' } } } }
    });
});
<?php endforeach; ?>

<?php foreach ($epreuves as $ep):
    $id_ep = $ep['id_epreuve'];
    if (!$epreuves_modal_data[$id_ep]['geojson_valid']) continue;
?>
document.getElementById('modalEpreuve<?= $id_ep ?>').addEventListener('shown.bs.modal', function () {
    const el = document.getElementById('map-epreuve-<?= $id_ep ?>');
    if (!el || el._leaflet_id) return;
    const m = L.map(el);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(m);
    const layer = L.geoJSON(<?= $epreuves_modal_data[$id_ep]['geojson'] ?>, { style: { color: '#0f3460', weight: 4, opacity: .9 } }).addTo(m);
    m.fitBounds(layer.getBounds(), { padding: [20, 20] });
});
<?php endforeach; ?>
</script>

<style>
.btn-xs   { padding: 2px 6px; font-size: .75rem; }
.modal-xl { max-width: 1100px; }
</style>