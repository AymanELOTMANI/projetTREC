<?php
// 1. Initialisation et Sécurité
require '../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'chef_piste', 'organisateur'])) {
    header('Location: ../auth/login.php');
    exit;
}

function prepare_exec($conn, $sql, $types, ...$params) {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;
    if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    return $stmt;
}

function redirect($url) {
    header("Location: $url");
    exit;
}

// 2. Traitement des formulaires (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $id_epreuve = intval($_POST['id_epreuve'] ?? 0);

    if ($action === 'set_horaires') {
        $id_dossard = intval($_POST['id_dossard']);

        if ($id_dossard <= 0) {
            redirect("troncons.php?id_epreuve=$id_epreuve&erreur=1");
        }

        $debut  = trim($_POST['debut'] ?? '') ?: null;
        $p1     = trim($_POST['p1']    ?? '') ?: null;
        $p2     = trim($_POST['p2']    ?? '') ?: null;
        $fin    = trim($_POST['fin']   ?? '') ?: null;
        $duree1 = ($_POST['duree_troncon1_min'] ?? '') !== '' ? intval($_POST['duree_troncon1_min']) : null;
        $duree2 = ($_POST['duree_troncon2_min'] ?? '') !== '' ? intval($_POST['duree_troncon2_min']) : null;
        $duree3 = ($_POST['duree_troncon3_min'] ?? '') !== '' ? intval($_POST['duree_troncon3_min']) : null;

        // Statut automatique : terminé si arrivée renseignée, sinon en cours
        $statut = ($fin !== null) ? 'termine' : 'en_cours';

        $check    = prepare_exec($conn, "SELECT id_passage FROM passage WHERE id_dossard = ?", "i", $id_dossard);
        $existing = $check ? mysqli_fetch_assoc(mysqli_stmt_get_result($check)) : null;

        if ($existing) {
            prepare_exec($conn,
                "UPDATE passage SET debut=?, p1=?, p2=?, fin=?,
                 duree_troncon1_min=?, duree_troncon2_min=?, duree_troncon3_min=?,
                 statut=?, id_epreuve=?
                 WHERE id_dossard=?",
                "ssssiiiisi",
                $debut, $p1, $p2, $fin,
                $duree1, $duree2, $duree3,
                $statut, $id_epreuve, $id_dossard
            );
        } else {
            prepare_exec($conn,
                "INSERT INTO passage (id_dossard, id_epreuve, debut, p1, p2, fin,
                 duree_troncon1_min, duree_troncon2_min, duree_troncon3_min, statut, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                "iissssiiis",
                $id_dossard, $id_epreuve, $debut, $p1, $p2, $fin,
                $duree1, $duree2, $duree3, $statut
            );
        }

        if (mysqli_errno($conn)) die('Erreur SQL : ' . mysqli_error($conn));
        redirect("troncons.php?id_epreuve=$id_epreuve&succes=1");
    }

    if ($action === 'set_vitesses') {
        $v1 = (float)($_POST['vitesse_t1'] ?? 0) ?: null;
        $v2 = (float)($_POST['vitesse_t2'] ?? 0) ?: null;
        $v3 = (float)($_POST['vitesse_t3'] ?? 0) ?: null;

        prepare_exec($conn,
            "UPDATE epreuve SET vitesse_cible_t1=?, vitesse_cible_t2=?, vitesse_cible_t3=? WHERE id_epreuve=?",
            "dddi", $v1, $v2, $v3, $id_epreuve
        );
        redirect("troncons.php?id_epreuve=$id_epreuve&succes=2");
    }

    if ($action === 'set_temps_ideal') {
        $temps_ideal = ($_POST['temps_ideal'] ?? '') !== '' ? (float)$_POST['temps_ideal'] : null;
        prepare_exec($conn,
            "UPDATE epreuve SET temps_ideal = ? WHERE id_epreuve = ?",
            "di", $temps_ideal, $id_epreuve
        );
        redirect("troncons.php?id_epreuve=$id_epreuve&succes=3");
    }
}

// 3. Récupération des données (GET)
$id_competition_sel = intval($_GET['id_competition'] ?? 0);
$id_epreuve_sel     = intval($_GET['id_epreuve']     ?? 0);

$competitions_list = mysqli_fetch_all(mysqli_query($conn,
    "SELECT id_competition, nom_competition, date_competition FROM competition ORDER BY date_competition DESC"
), MYSQLI_ASSOC);

$sql_ep = "SELECT e.*, p.id_parcours, p.nom_parcours, p.distance_km,
                  c.id_competition, c.nom_competition, c.date_competition
           FROM epreuve e
           JOIN parcours_theorique p ON e.id_parcours = p.id_parcours
           JOIN competition c        ON e.id_competition = c.id_competition";
if ($id_competition_sel) $sql_ep .= " WHERE c.id_competition = $id_competition_sel";
$sql_ep .= " ORDER BY c.date_competition DESC, e.nom_epreuve ASC";

$epreuves_list = mysqli_fetch_all(mysqli_query($conn, $sql_ep), MYSQLI_ASSOC);

$epreuve_courante = null;
foreach ($epreuves_list as $ep) {
    if ((int)$ep['id_epreuve'] === $id_epreuve_sel) {
        $epreuve_courante = $ep;
        break;
    }
}

$points   = [];
$passages = [];

if ($epreuve_courante) {
    $stmt_pts = prepare_exec($conn,
        "SELECT id_point, ordre_point, latitude, longitude
         FROM point_parcours WHERE id_parcours = ? ORDER BY ordre_point ASC",
        "i", (int)$epreuve_courante['id_parcours']
    );
    if ($stmt_pts) $points = mysqli_fetch_all(mysqli_stmt_get_result($stmt_pts), MYSQLI_ASSOC);

    // ── Ajout du LEFT JOIN sur resultat pour récupérer les pénalités ──
    $stmt_pass = prepare_exec($conn,
        "SELECT ca.id_cavalier, ca.nom_cavalier, ca.prenom_cavalier, ca.categorie,
                i.id_dossard, d.numero_dossard,
                pa.id_passage, pa.debut, pa.p1, pa.p2, pa.fin,
                pa.duree_troncon1_min, pa.duree_troncon2_min, pa.duree_troncon3_min, pa.statut,
                r.penalites
         FROM cavalier ca
         JOIN inscription i ON i.id_cavalier = ca.id_cavalier
                            AND i.id_competition = ?
                            AND i.statut_inscription IN ('confirmee','confirmée')
         LEFT JOIN dossard d   ON d.id_dossard  = i.id_dossard
         LEFT JOIN passage pa  ON pa.id_dossard = i.id_dossard
         LEFT JOIN resultat r  ON r.id_cavalier = ca.id_cavalier
                               AND r.id_epreuve  = ?
         ORDER BY d.numero_dossard ASC, ca.nom_cavalier ASC",
        "ii", (int)$epreuve_courante['id_competition'], (int)$epreuve_courante['id_epreuve']
    );
    if ($stmt_pass) $passages = mysqli_fetch_all(mysqli_stmt_get_result($stmt_pass), MYSQLI_ASSOC);
}

$succes_msg = match($_GET['succes'] ?? '') {
    '1' => 'Horaires de passage enregistrés avec succès.',
    '2' => 'Vitesses cibles mises à jour.',
    '3' => 'Temps idéal mis à jour.',
    default => ''
};

$erreur_msg = match($_GET['erreur'] ?? '') {
    '1' => 'Impossible d\'enregistrer : ce cavalier n\'a pas de dossard assigné.',
    default => ''
};
?>

<?php require '../header.php'; ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<main class="flex-grow-1 bg-light">

    <section class="py-5 bg-dark text-white text-center shadow-sm">
        <div class="container">
            <h1 class="display-5 fw-bold">Gestion des tronçons</h1>
            <p class="lead mb-0">Définir les horaires de passage et les vitesses cibles pour chaque concurrent</p>
        </div>
    </section>

    <div class="container py-5">

        <?php if ($succes_msg): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm">
                <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($succes_msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($erreur_msg): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($erreur_msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">

            <!-- ── Sidebar ─────────────────────────────────────────────────── -->
            <div class="col-lg-3">

                <!-- Compétition -->
                <div class="card shadow-sm border-0 p-4 mb-3">
                    <h6 class="fw-bold mb-3"><i class="bi bi-trophy me-2 text-warning"></i>Compétition</h6>
                    <form method="GET" action="troncons.php">
                        <select name="id_competition" class="form-select" onchange="this.form.submit()">
                            <option value="">Toutes les compétitions</option>
                            <?php foreach ($competitions_list as $c): ?>
                                <option value="<?= $c['id_competition'] ?>"
                                    <?= $id_competition_sel === (int)$c['id_competition'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['nom_competition']) ?>
                                    - <?= date('d/m/Y', strtotime($c['date_competition'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <!-- Épreuve -->
                <div class="card shadow-sm border-0 p-4 mb-3">
                    <h6 class="fw-bold mb-3"><i class="bi bi-flag me-2 text-success"></i>Épreuve</h6>
                    <form method="GET" action="troncons.php">
                        <?php if ($id_competition_sel): ?>
                            <input type="hidden" name="id_competition" value="<?= $id_competition_sel ?>">
                        <?php endif; ?>
                        <select name="id_epreuve" class="form-select" onchange="this.form.submit()">
                            <option value="">Choisir une épreuve</option>
                            <?php foreach ($epreuves_list as $ep): ?>
                                <option value="<?= $ep['id_epreuve'] ?>"
                                    <?= $id_epreuve_sel === (int)$ep['id_epreuve'] ? 'selected' : '' ?>>
                                    [<?= htmlspecialchars($ep['type_epreuve'] ?: '—') ?>]
                                    <?= htmlspecialchars($ep['nom_epreuve'] ?: '—') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <?php if ($epreuve_courante): ?>

                <!-- Infos parcours -->
                <div class="card shadow-sm border-0 p-4 mb-3">
                    <h6 class="fw-bold mb-3"><i class="bi bi-info-circle me-2 text-primary"></i>Parcours</h6>
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted small">Parcours</td>
                            <td class="small fw-bold"><?= htmlspecialchars($epreuve_courante['nom_parcours']) ?></td></tr>
                        <tr><td class="text-muted small">Distance</td>
                            <td class="small fw-bold"><?= number_format($epreuve_courante['distance_km'], 2) ?> km</td></tr>
                        <tr><td class="text-muted small">Concurrents</td>
                            <td class="small fw-bold"><?= count($passages) ?></td></tr>
                        <tr><td class="text-muted small">Points GPS</td>
                            <td class="small fw-bold"><?= count($points) ?></td></tr>
                    </table>
                </div>

                <!-- Temps idéal -->
                <div class="card shadow-sm border-0 p-4 mb-3">
                    <h6 class="fw-bold mb-3"><i class="bi bi-clock-history me-2 text-warning"></i>Temps idéal</h6>

                    <?php
                    $ti = isset($epreuve_courante['temps_ideal']) && $epreuve_courante['temps_ideal'] !== null
                        ? (float)$epreuve_courante['temps_ideal'] : null;
                    if ($ti !== null) {
                        $ti_s   = (int)round($ti * 60);
                        $ti_fmt = ($ti_s >= 3600 ? intdiv($ti_s, 3600) . 'h ' : '')
                                . sprintf("%02d'%02d\"", intdiv($ti_s % 3600, 60), $ti_s % 60);
                    }
                    ?>

                    <?php if ($ti !== null): ?>
                        <div class="alert alert-success py-2 px-3 small mb-3">
                            <i class="bi bi-check-circle me-1"></i>
                            Défini : <strong><?= $ti_fmt ?></strong>
                            <span class="text-muted">(<?= number_format($ti, 4) ?> min)</span>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning py-2 px-3 small mb-3">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Non défini — classement par score indisponible.
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="troncons.php">
                        <input type="hidden" name="action"     value="set_temps_ideal">
                        <input type="hidden" name="id_epreuve" value="<?= $id_epreuve_sel ?>">
                        <label class="form-label small fw-bold mb-1">
                            Temps idéal <span class="text-muted fw-normal">(minutes décimales)</span>
                        </label>
                        <div class="input-group input-group-sm mb-1">
                            <input type="number" step="0.0001" min="0.1" name="temps_ideal"
                                   id="input-temps-ideal"
                                   class="form-control"
                                   value="<?= htmlspecialchars((string)($ti ?? '')) ?>"
                                   placeholder="ex : 47.5000">
                            <span class="input-group-text">min</span>
                        </div>
                        <div class="text-muted small mb-2" id="ti-preview"></div>
                        <button type="submit" class="btn btn-warning btn-sm fw-bold w-100">
                            <i class="bi bi-save me-1"></i>Sauvegarder
                        </button>
                    </form>
                </div>

                <!-- Vitesses cibles -->
                <div class="card shadow-sm border-0 p-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-speedometer2 me-2 text-danger"></i>Vitesses cibles</h6>
                    <form method="POST" action="troncons.php">
                        <input type="hidden" name="action"     value="set_vitesses">
                        <input type="hidden" name="id_epreuve" value="<?= $id_epreuve_sel ?>">
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Tronçon 1</label>
                            <input type="number" step="0.1" min="1" max="30" name="vitesse_t1"
                                   class="form-control form-control-sm"
                                   value="<?= htmlspecialchars($epreuve_courante['vitesse_cible_t1'] ?? '') ?>"
                                   placeholder="ex : 12.0">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Tronçon 2</label>
                            <input type="number" step="0.1" min="1" max="30" name="vitesse_t2"
                                   class="form-control form-control-sm"
                                   value="<?= htmlspecialchars($epreuve_courante['vitesse_cible_t2'] ?? '') ?>"
                                   placeholder="ex : 8.5">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Tronçon 3</label>
                            <input type="number" step="0.1" min="1" max="30" name="vitesse_t3"
                                   class="form-control form-control-sm"
                                   value="<?= htmlspecialchars($epreuve_courante['vitesse_cible_t3'] ?? '') ?>"
                                   placeholder="ex : 10.0">
                        </div>
                        <button type="submit" class="btn btn-warning btn-sm fw-bold w-100">
                            <i class="bi bi-save me-1"></i>Enregistrer
                        </button>
                    </form>
                </div>

                <?php endif; ?>
            </div>

            <!-- ── Contenu principal ───────────────────────────────────────── -->
            <div class="col-lg-9">
                <?php if (!$epreuve_courante): ?>
                    <div class="card shadow-sm border-0 p-5 text-center">
                        <i class="bi bi-signpost-split text-muted" style="font-size:3rem;"></i>
                        <h5 class="text-muted mt-3">Sélectionnez une épreuve</h5>
                        <p class="text-muted small mb-0">Choisissez une épreuve dans le panneau de gauche pour gérer ses tronçons.</p>
                    </div>
                <?php else: ?>

                    <!-- KPI -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card shadow-sm border-0 text-center p-3 h-100">
                                <div class="fs-5 fw-bold text-primary">
                                    [<?= htmlspecialchars($epreuve_courante['type_epreuve'] ?: '—') ?>]
                                    <?= htmlspecialchars($epreuve_courante['nom_epreuve'] ?: '—') ?>
                                </div>
                                <div class="small text-muted">Épreuve</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card shadow-sm border-0 text-center p-3 h-100">
                                <div class="fs-2 fw-bold text-success"><?= count($passages) ?></div>
                                <div class="small text-muted">Concurrent<?= count($passages) > 1 ? 's' : '' ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card shadow-sm border-0 text-center p-3 h-100">
                                <div class="fs-2 fw-bold text-warning">
                                    <?= number_format($epreuve_courante['distance_km'], 2) ?> km
                                </div>
                                <div class="small text-muted">Distance totale</div>
                            </div>
                        </div>
                    </div>

                    <!-- Carte Leaflet -->
                    <?php if (!empty($points)): ?>
                        <div class="card shadow-sm border-0 mb-4 overflow-hidden">
                            <div class="card-header bg-success text-white d-flex align-items-center justify-content-between py-3 px-4">
                                <h6 class="fw-bold mb-0">
                                    <i class="bi bi-map me-2"></i>Aperçu du parcours
                                    <span class="badge bg-light text-dark ms-2"><?= count($points) ?> points GPS</span>
                                </h6>
                                <button class="btn btn-light btn-sm" id="btn-toggle-map">
                                    <i class="bi bi-eye-slash me-1"></i>Masquer
                                </button>
                            </div>
                            <div id="wrapper-map">
                                <div id="map-parcours" style="height:340px;"></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Tableau des passages -->
                    <div class="card shadow-sm border-0 overflow-hidden">
                        <div class="card-header bg-dark text-white d-flex align-items-center justify-content-between py-3 px-4">
                            <h6 class="fw-bold mb-0">
                                <i class="bi bi-table me-2"></i>Horaires de passage
                                — <?= htmlspecialchars($epreuve_courante['nom_competition']) ?>
                            </h6>
                            <span class="badge bg-warning text-dark">
                                <?= count($passages) ?> concurrent<?= count($passages) > 1 ? 's' : '' ?>
                            </span>
                        </div>

                        <?php if (empty($passages)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-people text-muted" style="font-size:2.5rem;"></i>
                                <p class="text-muted mt-2 small mb-0">Aucun concurrent confirmé pour cette épreuve.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-4">Dossard</th>
                                            <th>Cavalier</th>
                                            <th>Catégorie</th>
                                            <th>Départ</th>
                                            <th>P1</th>
                                            <th>P2</th>
                                            <th>Arrivée</th>
                                            <th>T1</th>
                                            <th>T2</th>
                                            <th>T3</th>
                                            <th>Pénalités</th>
                                            <th>Statut</th>
                                            <th class="text-end pe-4">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($passages as $p):
                                            $has_passage = !empty($p['id_passage']);
                                            $statut      = $p['statut'] ?? null;
                                        ?>
                                        <tr>
                                            <td class="ps-4">
                                                <?= !empty($p['numero_dossard'])
                                                    ? '<span class="badge bg-dark">#' . intval($p['numero_dossard']) . '</span>'
                                                    : '<span class="text-muted small">—</span>' ?>
                                            </td>
                                            <td class="fw-bold">
                                                <?= htmlspecialchars($p['prenom_cavalier'] . ' ' . $p['nom_cavalier']) ?>
                                            </td>
                                            <td><?= htmlspecialchars($p['categorie'] ?? '—') ?></td>

                                            <td><?= $p['debut'] ? '<span class="badge bg-secondary">'.htmlspecialchars(substr($p['debut'],0,5)).'</span>' : '<span class="text-muted">—</span>' ?></td>
                                            <td><?= $p['p1']    ? '<span class="badge bg-secondary">'.htmlspecialchars(substr($p['p1'],0,5)).'</span>'    : '<span class="text-muted">—</span>' ?></td>
                                            <td><?= $p['p2']    ? '<span class="badge bg-secondary">'.htmlspecialchars(substr($p['p2'],0,5)).'</span>'    : '<span class="text-muted">—</span>' ?></td>
                                            <td><?= $p['fin']   ? '<span class="badge bg-success">'.htmlspecialchars(substr($p['fin'],0,5)).'</span>'     : '<span class="text-muted">—</span>' ?></td>

                                            <td><?= $p['duree_troncon1_min'] !== null ? intval($p['duree_troncon1_min']).' min' : '<span class="text-muted">—</span>' ?></td>
                                            <td><?= $p['duree_troncon2_min'] !== null ? intval($p['duree_troncon2_min']).' min' : '<span class="text-muted">—</span>' ?></td>
                                            <td><?= $p['duree_troncon3_min'] !== null ? intval($p['duree_troncon3_min']).' min' : '<span class="text-muted">—</span>' ?></td>

                                            <!-- ── Colonne Pénalités ── -->
                                            <td>
                                                <?php if ($p['penalites'] !== null): ?>
                                                    <span class="badge <?= intval($p['penalites']) > 0 ? 'bg-danger' : 'bg-success' ?>">
                                                        <?= intval($p['penalites']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <?php if (!$has_passage): ?>
                                                    <span class="badge bg-light text-muted border">Non défini</span>
                                                <?php elseif ($statut === 'termine'): ?>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-check-circle me-1"></i>Terminé
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">
                                                        <i class="bi bi-clock-history me-1"></i>En cours
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-end pe-4">
                                                <button class="btn btn-sm btn-outline-primary"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalPassage"
                                                    data-id-dossard="<?= intval($p['id_dossard'] ?? 0) ?>"
                                                    data-nom="<?= htmlspecialchars($p['prenom_cavalier'] . ' ' . $p['nom_cavalier']) ?>"
                                                    data-dossard="<?= intval($p['numero_dossard'] ?? 0) ?>"
                                                    data-debut="<?= htmlspecialchars($p['debut'] ?? '') ?>"
                                                    data-p1="<?= htmlspecialchars($p['p1'] ?? '') ?>"
                                                    data-p2="<?= htmlspecialchars($p['p2'] ?? '') ?>"
                                                    data-fin="<?= htmlspecialchars($p['fin'] ?? '') ?>"
                                                    data-t1="<?= $p['duree_troncon1_min'] ?? '' ?>"
                                                    data-t2="<?= $p['duree_troncon2_min'] ?? '' ?>"
                                                    data-t3="<?= $p['duree_troncon3_min'] ?? '' ?>">
                                                    <i class="bi bi-pencil me-1"></i>Éditer
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- Modal édition passage -->
<div class="modal fade" id="modalPassage" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-clock me-2 text-warning"></i>
                    <span id="modal-titre">Horaires de passage</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" action="troncons.php" id="form-modal-passage">
                <input type="hidden" name="action"     value="set_horaires">
                <input type="hidden" name="id_epreuve" value="<?= $id_epreuve_sel ?>">
                <input type="hidden" name="id_dossard" id="modal-id-dossard">

                <div class="modal-body">
                    <p id="modal-sous-titre" class="text-muted small mb-3"></p>

                    <h6 class="fw-bold text-secondary mb-2">Horaires de passage</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold">Départ <span class="text-danger">*</span></label>
                            <input type="time" name="debut" id="modal-debut" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">Passage 1</label>
                            <input type="time" name="p1" id="modal-p1" class="form-control form-control-sm">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">Passage 2</label>
                            <input type="time" name="p2" id="modal-p2" class="form-control form-control-sm">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">
                                Arrivée
                                <span class="text-muted fw-normal">(→ marque "Terminé")</span>
                            </label>
                            <input type="time" name="fin" id="modal-fin" class="form-control form-control-sm">
                        </div>
                    </div>

                    <hr>
                    <h6 class="fw-bold text-secondary mb-2">Durées cibles par tronçon</h6>
                    <div class="row g-3">
                        <div class="col-4">
                            <label class="form-label small fw-bold">Tronçon 1</label>
                            <input type="number" name="duree_troncon1_min" id="modal-t1"
                                   class="form-control form-control-sm" min="1" placeholder="min">
                        </div>
                        <div class="col-4">
                            <label class="form-label small fw-bold">Tronçon 2</label>
                            <input type="number" name="duree_troncon2_min" id="modal-t2"
                                   class="form-control form-control-sm" min="1" placeholder="min">
                        </div>
                        <div class="col-4">
                            <label class="form-label small fw-bold">Tronçon 3</label>
                            <input type="number" name="duree_troncon3_min" id="modal-t3"
                                   class="form-control form-control-sm" min="1" placeholder="min">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-success btn-sm fw-bold" id="btn-submit-passage">
                        <i class="bi bi-save me-1"></i>Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require '../footer.php'; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
<?php if (!empty($points)): ?>
(function() {
    const pts = <?= json_encode(array_map(fn($p) => ['lat' => (float)$p['latitude'], 'lng' => (float)$p['longitude']], $points)) ?>;
    if (!pts.length) return;

    const map = L.map('map-parcours').setView([pts[0].lat, pts[0].lng], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);

    const ll   = pts.map(p => [p.lat, p.lng]);
    const poly = L.polyline(ll, { color: '#198754', weight: 4 }).addTo(map);
    map.fitBounds(poly.getBounds(), { padding: [20, 20] });

    L.circleMarker(ll[0],            { radius: 8, color: '#198754', fillColor: '#198754', fillOpacity: 1 }).addTo(map).bindTooltip('Départ');
    L.circleMarker(ll[ll.length - 1],{ radius: 8, color: '#dc3545', fillColor: '#dc3545', fillOpacity: 1 }).addTo(map).bindTooltip('Arrivée');

    document.getElementById('btn-toggle-map').addEventListener('click', function() {
        const w = document.getElementById('wrapper-map');
        const hidden = w.style.display === 'none';
        w.style.display = hidden ? 'block' : 'none';
        this.innerHTML = hidden ? '<i class="bi bi-eye-slash me-1"></i>Masquer' : '<i class="bi bi-eye me-1"></i>Afficher';
        if (hidden) setTimeout(() => map.invalidateSize(), 100);
    });

    setTimeout(() => map.invalidateSize(), 300);
})();
<?php endif; ?>

// Remplissage de la modale
document.getElementById('modalPassage').addEventListener('show.bs.modal', function(e) {
    const btn = e.relatedTarget;
    document.getElementById('modal-id-dossard').value     = btn.dataset.idDossard;
    document.getElementById('modal-titre').textContent    = 'Dossard #' + btn.dataset.dossard;
    document.getElementById('modal-sous-titre').textContent = btn.dataset.nom;
    document.getElementById('modal-debut').value = btn.dataset.debut ? btn.dataset.debut.substring(0, 5) : '';
    document.getElementById('modal-p1').value    = btn.dataset.p1    ? btn.dataset.p1.substring(0, 5)    : '';
    document.getElementById('modal-p2').value    = btn.dataset.p2    ? btn.dataset.p2.substring(0, 5)    : '';
    document.getElementById('modal-fin').value   = btn.dataset.fin   ? btn.dataset.fin.substring(0, 5)   : '';
    document.getElementById('modal-t1').value    = btn.dataset.t1 || '';
    document.getElementById('modal-t2').value    = btn.dataset.t2 || '';
    document.getElementById('modal-t3').value    = btn.dataset.t3 || '';
});

document.getElementById('btn-submit-passage').addEventListener('click', function() {
    if (!document.getElementById('modal-debut').value) {
        alert('Veuillez saisir une heure de départ.');
        return;
    }
    document.getElementById('form-modal-passage').submit();
});

// Prévisualisation temps idéal
(function() {
    const inp = document.getElementById('input-temps-ideal');
    const pre = document.getElementById('ti-preview');
    if (!inp || !pre) return;
    function update() {
        const v = parseFloat(inp.value);
        if (isNaN(v) || v <= 0) { pre.textContent = ''; return; }
        const s   = Math.round(v * 60);
        const h   = Math.floor(s / 3600);
        const m   = Math.floor((s % 3600) / 60);
        const sec = s % 60;
        pre.textContent = '→ ' + (h > 0 ? h + 'h ' : '')
            + String(m).padStart(2, '0') + "'"
            + String(sec).padStart(2, '0') + '"';
    }
    inp.addEventListener('input', update);
    update();
})();
</script>