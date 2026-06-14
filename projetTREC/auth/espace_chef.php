<?php
// 1. Configuration et Sécurité
require '../config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'chef_piste') {
    header('Location: ../auth/login.php');
    exit;
}

// 2. Fonctions Utiles
function prepare_exec($conn, $sql, $types, ...$params) {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;
    if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    return $stmt;
}

// 3. Filtres
$filtre_competition = isset($_GET['filtre_competition']) ? intval($_GET['filtre_competition']) : 0;

// Compétitions
$competitions = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM competition ORDER BY date_competition ASC"), MYSQLI_ASSOC);

// Inscriptions confirmées
$sql_ins = "SELECT i.id_inscription, i.date_inscription, i.statut_inscription,
                   cav.nom_cavalier AS nom, cav.prenom_cavalier AS prenom, cav.categorie,
                   d.numero_dossard, c.id_competition, c.nom_competition, c.date_competition
            FROM inscription i
            JOIN cavalier cav ON i.id_cavalier = cav.id_cavalier
            JOIN competition c ON i.id_competition = c.id_competition
            LEFT JOIN dossard d ON i.id_dossard = d.id_dossard
            WHERE i.statut_inscription = 'confirmee'";

if ($filtre_competition) {
    $stmt_ins = prepare_exec($conn, $sql_ins . " AND i.id_competition = ? ORDER BY cav.nom_cavalier ASC", "i", $filtre_competition);
    $inscriptions = mysqli_fetch_all(mysqli_stmt_get_result($stmt_ins), MYSQLI_ASSOC);
} else {
    $inscriptions = mysqli_fetch_all(mysqli_query($conn, $sql_ins . " ORDER BY c.date_competition ASC, cav.nom_cavalier ASC"), MYSQLI_ASSOC);
}

// 4. KPIs
$nb_inscriptions   = count($inscriptions);
$nb_competitions   = count(array_unique(array_column($inscriptions, 'id_competition')));
$nb_avec_dossard   = count(array_filter($inscriptions, fn($r) => !empty($r['numero_dossard'])));
$nb_sans_dossard   = $nb_inscriptions - $nb_avec_dossard;
$inscrits_par_comp = array_count_values(array_column($inscriptions, 'id_competition'));

// 5. Parcours théoriques
$where_parcours = $filtre_competition ? "WHERE e.id_competition = " . intval($filtre_competition) : "";
$sql_parcours = "
    SELECT p.id_parcours, p.nom_parcours, p.distance_km,
           COUNT(DISTINCT pp.id_point) AS nb_points,
           GROUP_CONCAT(DISTINCT e.nom_epreuve ORDER BY e.nom_epreuve SEPARATOR ', ') AS epreuves,
           GROUP_CONCAT(DISTINCT e.type_epreuve ORDER BY e.type_epreuve SEPARATOR ', ') AS types_epreuve
    FROM parcours_theorique p
    LEFT JOIN point_parcours pp ON pp.id_parcours = p.id_parcours
    LEFT JOIN epreuve e ON e.id_parcours = p.id_parcours
    $where_parcours
    GROUP BY p.id_parcours
    ORDER BY p.id_parcours DESC
";
$parcours_list = mysqli_fetch_all(mysqli_query($conn, $sql_parcours), MYSQLI_ASSOC);

// 6. Points GPS par parcours
$points_par_parcours = [];
if (!empty($parcours_list)) {
    $ids = implode(',', array_column($parcours_list, 'id_parcours'));
    $res_pts = mysqli_query($conn, "SELECT id_parcours, ordre_point, latitude, longitude FROM point_parcours WHERE id_parcours IN ($ids) ORDER BY id_parcours, ordre_point ASC");
    foreach (mysqli_fetch_all($res_pts, MYSQLI_ASSOC) as $pt) {
        $points_par_parcours[$pt['id_parcours']][] = [
            'lat'   => $pt['latitude'],
            'lng'   => $pt['longitude'],
            'ordre' => $pt['ordre_point']
        ];
    }
}

// 7. Passages réalisés par cavalier
$where_passage = $filtre_competition ? "AND e.id_competition = " . intval($filtre_competition) : "";
$sql_passages = "
    SELECT cav.id_cavalier, cav.nom_cavalier, cav.prenom_cavalier, cav.categorie,
           d.numero_dossard,
           e.nom_epreuve, e.type_epreuve, e.id_parcours,
           pa.id_passage, pa.debut, pa.p1, pa.p2, pa.fin,
           pa.duree_troncon1_min, pa.duree_troncon2_min, pa.duree_troncon3_min,
           TIMEDIFF(pa.fin, pa.debut) AS temps_total,
           c.nom_competition
    FROM passage pa
    JOIN dossard d     ON pa.id_dossard    = d.id_dossard
    JOIN inscription i ON i.id_dossard     = d.id_dossard
    JOIN cavalier cav  ON i.id_cavalier    = cav.id_cavalier
    JOIN epreuve e     ON pa.id_epreuve    = e.id_epreuve
    JOIN competition c ON e.id_competition = c.id_competition
    WHERE i.statut_inscription = 'confirmee'
    $where_passage
    ORDER BY cav.nom_cavalier ASC, cav.prenom_cavalier ASC, e.nom_epreuve ASC
";
$rows_passages = mysqli_fetch_all(mysqli_query($conn, $sql_passages), MYSQLI_ASSOC);

// Regrouper par cavalier
$passages_par_cavalier = [];
foreach ($rows_passages as $row) {
    $key = $row['id_cavalier'];
    if (!isset($passages_par_cavalier[$key])) {
        $passages_par_cavalier[$key] = [
            'nom'            => $row['prenom_cavalier'] . ' ' . $row['nom_cavalier'],
            'categorie'      => $row['categorie'],
            'numero_dossard' => $row['numero_dossard'],
            'competition'    => $row['nom_competition'],
            'passages'       => [],
        ];
    }
    $passages_par_cavalier[$key]['passages'][] = $row;
}

// Helper : formater une durée en minutes décimales -> MM'SS"
function fmt_duree($min) {
    if ($min === null || $min === '') return '—';
    $total_sec = intval(round(floatval($min) * 60));
    return intdiv($total_sec, 60) . "'" . str_pad($total_sec % 60, 2, '0', STR_PAD_LEFT) . '"';
}
?>

<?php include '../header.php'; ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

<main class="flex-grow-1 bg-light">

    <section class="py-5 bg-dark text-white text-center shadow-sm">
        <div class="container">
            <h1 class="display-5 fw-bold">Espace chef de piste</h1>
            <p class="lead mb-0">Consulter les cavaliers inscrits, suivre les dossards et tracer le parcours officiel</p>
        </div>
    </section>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 main-content">

                <div class="mb-4">
                    <h4 class="fw-bold">Bonjour, <?= htmlspecialchars(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')) ?> 🚩</h4>
                    <p class="text-muted mb-0">Bienvenue dans l'espace Chef de piste — consultation des cavaliers inscrits.</p>
                </div>

                <?php if (isset($_GET['succes'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle-fill me-2"></i>Opération effectuée avec succès.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- KPI -->
                <div class="mb-5">
                    <h4 class="mb-3 text-secondary">Tableau de bord</h4>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="card stat-card shadow-sm p-3 text-center">
                                <i class="bi bi-trophy-fill text-primary fs-2"></i>
                                <h3 class="fw-bold mt-2"><?= $nb_competitions ?></h3>
                                <p class="text-muted mb-0">Compétitions</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card stat-card shadow-sm p-3 text-center">
                                <i class="bi bi-clipboard-check-fill text-success fs-2"></i>
                                <h3 class="fw-bold mt-2"><?= $nb_inscriptions ?></h3>
                                <p class="text-muted mb-0">Cavaliers inscrits</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card stat-card shadow-sm p-3 text-center">
                                <i class="bi bi-upc-scan text-warning fs-2"></i>
                                <h3 class="fw-bold mt-2"><?= $nb_avec_dossard ?></h3>
                                <p class="text-muted mb-0">Dossards attribués</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card stat-card shadow-sm p-3 text-center">
                                <i class="bi bi-hourglass-split text-danger fs-2"></i>
                                <h3 class="fw-bold mt-2"><?= $nb_sans_dossard ?></h3>
                                <p class="text-muted mb-0">Sans dossard</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tableau cavaliers inscrits -->
                <div class="mb-5">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="text-secondary mb-0">Cavaliers inscrits</h4>
                        <button class="btn btn-outline-secondary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalFilter">
                            <i class="bi bi-funnel me-1"></i>Filtrer
                            <?php if ($filtre_competition): ?>
                                <span class="badge bg-warning text-dark ms-1">actif</span>
                            <?php endif; ?>
                        </button>
                    </div>

                    <?php if ($filtre_competition):
                        $nom_comp_actif = array_column($competitions, 'nom_competition', 'id_competition')[$filtre_competition] ?? '';
                    ?>
                        <div class="mb-2 d-flex gap-2 flex-wrap align-items-center">
                            <small class="text-muted">Filtre actif :</small>
                            <span class="badge bg-warning text-dark"><i class="bi bi-trophy me-1"></i><?= htmlspecialchars($nom_comp_actif) ?></span>
                            <a href="espace_chef.php" class="btn btn-outline-danger btn-sm py-0 px-2"><i class="bi bi-x"></i> Réinitialiser</a>
                        </div>
                    <?php endif; ?>

                    <div class="card card-custom shadow-sm p-0">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Cavalier</th>
                                    <th>Dossard</th>
                                    <th>Catégorie</th>
                                    <th>Compétition</th>
                                    <th>Date compétition</th>
                                    <th>Date inscription</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($inscriptions)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        Aucun cavalier inscrit<?= $filtre_competition ? ' pour cette compétition' : '' ?>.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($inscriptions as $ins): ?>
                                <tr>
                                    <td class="ps-4 fw-bold"><?= htmlspecialchars(($ins['prenom'] ?? '') . ' ' . ($ins['nom'] ?? '')) ?></td>
                                    <td><?= !empty($ins['numero_dossard']) ? '<span class="badge bg-secondary">#' . intval($ins['numero_dossard']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                                    <td><?= htmlspecialchars($ins['categorie'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($ins['nom_competition']) ?></td>
                                    <td><?= $ins['date_competition'] ? date('d/m/Y', strtotime($ins['date_competition'])) : '—' ?></td>
                                    <td><?= $ins['date_inscription'] ? date('d/m/Y', strtotime($ins['date_inscription'])) : '—' ?></td>
                                    <td><span class="badge bg-success"><?= htmlspecialchars($ins['statut_inscription']) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Récapitulatif par compétition -->
                <?php if (!$filtre_competition && !empty($competitions)): ?>
                <div class="mb-5">
                    <h4 class="mb-3 text-secondary">Récapitulatif par compétition</h4>
                    <div class="row g-3">
                        <?php foreach ($competitions as $c):
                            $nb = $inscrits_par_comp[$c['id_competition']] ?? 0;
                        ?>
                        <div class="col-md-4">
                            <div class="card shadow-sm p-3">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="fw-bold mb-1"><?= htmlspecialchars($c['nom_competition']) ?></h6>
                                        <small class="text-muted"><i class="bi bi-calendar me-1"></i><?= date('d/m/Y', strtotime($c['date_competition'])) ?></small>
                                    </div>
                                    <span class="badge bg-primary fs-6"><?= $nb ?></span>
                                </div>
                                <div class="mt-2">
                                    <small class="text-muted"><?= $nb ?> cavalier<?= $nb > 1 ? 's' : '' ?> inscrit<?= $nb > 1 ? 's' : '' ?></small>
                                </div>
                                <div class="mt-2">
                                    <a href="espace_chef.php?filtre_competition=<?= $c['id_competition'] ?>" class="btn btn-outline-secondary btn-sm w-100">
                                        <i class="bi bi-eye me-1"></i>Voir les cavaliers
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Parcours Théoriques -->
                <div class="mb-5">
                    <h4 class="mb-3 text-secondary">
                        <i class="bi bi-map me-2"></i>Parcours théoriques
                        <?php if ($filtre_competition && !empty($parcours_list)): ?>
                            <small class="text-muted fs-6 ms-2">(filtrés par compétition)</small>
                        <?php endif; ?>
                    </h4>

                    <?php if (empty($parcours_list)): ?>
                        <div class="alert alert-secondary">
                            <i class="bi bi-info-circle me-2"></i>Aucun parcours théorique disponible<?= $filtre_competition ? ' pour cette compétition' : '' ?>.
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($parcours_list as $p):
                                $types = array_unique(array_filter(explode(', ', $p['types_epreuve'] ?? '')));
                                $type_colors = ['POR' => 'primary', 'MA' => 'info', 'PTV' => 'warning'];
                                $pts_json = json_encode($points_par_parcours[$p['id_parcours']] ?? []);
                            ?>
                            <div class="col-md-4">
                                <div class="card shadow-sm h-100">
                                    <div class="card-body d-flex flex-column">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="fw-bold mb-0"><?= htmlspecialchars($p['nom_parcours']) ?></h6>
                                            <span class="badge bg-secondary">
                                                <i class="bi bi-geo-alt me-1"></i><?= intval($p['nb_points']) ?> pts GPS
                                            </span>
                                        </div>
                                        <div class="d-flex flex-wrap gap-1 mb-3">
                                            <?php foreach ($types as $t): ?>
                                                <span class="badge bg-<?= $type_colors[$t] ?? 'secondary' ?> bg-opacity-75"><?= htmlspecialchars($t) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                        <ul class="list-unstyled small text-muted mb-3">
                                            <li class="mb-1">
                                                <i class="bi bi-rulers me-2"></i>
                                                <?= $p['distance_km'] ? number_format($p['distance_km'], 2) . ' km' : '—' ?>
                                            </li>
                                            <?php if (!empty($p['epreuves'])): ?>
                                            <li class="mb-1">
                                                <i class="bi bi-flag me-2"></i>
                                                <?= htmlspecialchars($p['epreuves']) ?>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                        <div class="mt-auto">
                                            <?php if (intval($p['nb_points']) > 0): ?>
                                                <button class="btn btn-outline-primary btn-sm w-100"
                                                    onclick='ouvrirCarteParcours(<?= $p['id_parcours'] ?>, <?= json_encode($p['nom_parcours']) ?>, <?= $pts_json ?>)'>
                                                    <i class="bi bi-map me-1"></i>Voir la carte
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-outline-secondary btn-sm w-100" disabled>
                                                    <i class="bi bi-map me-1"></i>Aucun point GPS
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Parcours réalisés par cavalier -->
                <div class="mb-5">
                    <h4 class="mb-3 text-secondary">
                        <i class="bi bi-person-lines-fill me-2"></i>Parcours réalisés par cavalier
                        <?php if ($filtre_competition && !empty($passages_par_cavalier)): ?>
                            <small class="text-muted fs-6 ms-2">(filtrés par compétition)</small>
                        <?php endif; ?>
                    </h4>

                    <?php if (empty($passages_par_cavalier)): ?>
                        <div class="alert alert-secondary">
                            <i class="bi bi-info-circle me-2"></i>Aucun passage enregistré<?= $filtre_competition ? ' pour cette compétition' : '' ?>.
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($passages_par_cavalier as $id_cav => $cav): ?>
                            <div class="col-md-6">
                                <div class="card shadow-sm h-100">
                                    <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
                                        <div>
                                            <span class="fw-bold"><i class="bi bi-person-fill me-2 text-primary"></i><?= htmlspecialchars($cav['nom']) ?></span>
                                            <small class="text-muted ms-2"><?= htmlspecialchars($cav['categorie'] ?? '') ?></small>
                                        </div>
                                        <div class="d-flex gap-2 align-items-center">
                                            <?php if (!empty($cav['numero_dossard'])): ?>
                                                <span class="badge bg-secondary">#<?= intval($cav['numero_dossard']) ?></span>
                                            <?php endif; ?>
                                            <small class="text-muted"><?= htmlspecialchars($cav['competition']) ?></small>
                                        </div>
                                    </div>
                                    <div class="card-body p-0">
                                        <table class="table table-sm table-hover align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th class="ps-3">Épreuve</th>
                                                    <th>Type</th>
                                                    <th>Départ</th>
                                                    <th>Arrivée</th>
                                                    <th>Durée totale</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($cav['passages'] as $pa):
                                                $pts_parcours = $points_par_parcours[$pa['id_parcours']] ?? [];
                                                $pts_json_cav = json_encode($pts_parcours);
                                                $type_badge = match($pa['type_epreuve']) {
                                                    'POR' => 'primary', 'MA' => 'info', 'PTV' => 'warning', default => 'secondary'
                                                };
                                            ?>
                                            <tr>
                                                <td class="ps-3 fw-semibold"><?= htmlspecialchars($pa['nom_epreuve']) ?></td>
                                                <td><span class="badge bg-<?= $type_badge ?> bg-opacity-75"><?= htmlspecialchars($pa['type_epreuve']) ?></span></td>
                                                <td><?= $pa['debut'] ? substr($pa['debut'], 0, 5) : '—' ?></td>
                                                <td><?= $pa['fin']   ? substr($pa['fin'],   0, 5) : '—' ?></td>
                                                <td><?= $pa['temps_total'] ? substr($pa['temps_total'], 0, 5) : '—' ?></td>
                                                <td>
                                                    <?php if (!empty($pts_parcours)): ?>
                                                        <button class="btn btn-outline-primary btn-sm py-0 px-2"
                                                            onclick='ouvrirCartePassage(
                                                                <?= json_encode($cav['nom']) ?>,
                                                                <?= json_encode($pa['nom_epreuve']) ?>,
                                                                <?= $pts_json_cav ?>,
                                                                <?= json_encode([
                                                                    'debut' => $pa['debut'],
                                                                    'p1'    => $pa['p1'],
                                                                    'p2'    => $pa['p2'],
                                                                    'fin'   => $pa['fin'],
                                                                    't1'    => $pa['duree_troncon1_min'],
                                                                    't2'    => $pa['duree_troncon2_min'],
                                                                    't3'    => $pa['duree_troncon3_min'],
                                                                    'total' => $pa['temps_total'],
                                                                ]) ?>
                                                            )'>
                                                            <i class="bi bi-map"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-outline-secondary btn-sm py-0 px-2" disabled title="Pas de points GPS">
                                                            <i class="bi bi-map"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</main>

<!-- Modale Filtre -->
<div class="modal fade" id="modalFilter" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="espace_chef.php" method="GET">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-funnel me-2"></i>Filtrer par compétition</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Compétition</label>
                        <select class="form-select" name="filtre_competition">
                            <option value="">— Toutes —</option>
                            <?php foreach ($competitions as $c): ?>
                                <option value="<?= $c['id_competition'] ?>" <?= $filtre_competition == $c['id_competition'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['nom_competition']) ?> — <?= date('d/m/Y', strtotime($c['date_competition'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="espace_chef.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Réinitialiser</a>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-funnel-fill me-1"></i>Appliquer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modale Carte Parcours théorique -->
<div class="modal fade" id="modalCarteParcours" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-map me-2"></i><span id="modalCarteTitle"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="mapParcours" style="height:450px;width:100%;"></div>
            </div>
            <div class="modal-footer">
                <small class="text-muted me-auto" id="modalCarteInfo"></small>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- Modale Carte Passage cavalier -->
<div class="modal fade" id="modalCartePassage" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-fill me-2"></i><span id="modalPassageTitle"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="mapPassage" style="height:400px;width:100%;"></div>
                <div class="p-3" id="modalPassageTimes"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let mapInstance  = null;
let mapPassage   = null;

/* ---- Carte parcours théorique ---- */
function ouvrirCarteParcours(idParcours, nomParcours, points) {
    document.getElementById('modalCarteTitle').textContent = nomParcours;
    document.getElementById('modalCarteInfo').textContent  = points.length + ' point(s) GPS';
    const modal = new bootstrap.Modal(document.getElementById('modalCarteParcours'));
    modal.show();
    document.getElementById('modalCarteParcours').addEventListener('shown.bs.modal', function init() {
        if (mapInstance) { mapInstance.remove(); mapInstance = null; }
        mapInstance = L.map('mapParcours');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(mapInstance);
        if (!points.length) { mapInstance.setView([46.8, 2.3], 6); return; }
        const latlngs = points.map(p => [parseFloat(p.lat), parseFloat(p.lng)]);
        L.polyline(latlngs, { color: '#0d6efd', weight: 3 }).addTo(mapInstance);
        points.forEach(function(p, i) {
            const isFirst = i === 0, isLast = i === points.length - 1;
            const color = isFirst ? '#198754' : (isLast ? '#dc3545' : '#0d6efd');
            const label = isFirst ? 'D' : (isLast ? 'A' : (i + 1));
            const icon = L.divIcon({ className: '', html: `<div style="background:${color};color:#fff;border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:bold;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)">${label}</div>`, iconSize: [26,26], iconAnchor: [13,13] });
            L.marker([parseFloat(p.lat), parseFloat(p.lng)], { icon }).bindPopup(`<b>Point ${i+1}</b><br>${p.lat}, ${p.lng}`).addTo(mapInstance);
        });
        mapInstance.fitBounds(latlngs, { padding: [20,20] });
        this.removeEventListener('shown.bs.modal', init);
    });
}
document.getElementById('modalCarteParcours').addEventListener('hidden.bs.modal', function() {
    if (mapInstance) { mapInstance.remove(); mapInstance = null; }
});

/* ---- Carte passage cavalier ---- */
function ouvrirCartePassage(nomCavalier, nomEpreuve, points, temps) {
    document.getElementById('modalPassageTitle').textContent = nomCavalier + ' — ' + nomEpreuve;

    // Bloc temps
    function fmtMin(m) {
        if (m === null || m === undefined || m === '') return '—';
        const s = Math.round(parseFloat(m) * 60);
        return Math.floor(s/60) + "'" + String(s%60).padStart(2,'0') + '"';
    }
    const t = temps;
    document.getElementById('modalPassageTimes').innerHTML = `
        <div class="row g-2 text-center small">
            <div class="col-3"><div class="border rounded p-2"><div class="text-muted">Départ</div><b>${t.debut ? t.debut.slice(0,5) : '—'}</b></div></div>
            <div class="col-3"><div class="border rounded p-2"><div class="text-muted">P1</div><b>${t.p1 ? t.p1.slice(0,5) : '—'}</b></div></div>
            <div class="col-3"><div class="border rounded p-2"><div class="text-muted">P2</div><b>${t.p2 ? t.p2.slice(0,5) : '—'}</b></div></div>
            <div class="col-3"><div class="border rounded p-2"><div class="text-muted">Arrivée</div><b>${t.fin ? t.fin.slice(0,5) : '—'}</b></div></div>
            <div class="col-4"><div class="border rounded p-2 bg-light"><div class="text-muted">Tronçon 1</div><b>${fmtMin(t.t1)}</b></div></div>
            <div class="col-4"><div class="border rounded p-2 bg-light"><div class="text-muted">Tronçon 2</div><b>${fmtMin(t.t2)}</b></div></div>
            <div class="col-4"><div class="border rounded p-2 bg-light"><div class="text-muted">Tronçon 3</div><b>${fmtMin(t.t3)}</b></div></div>
        </div>
        <div class="text-center mt-2"><span class="badge bg-dark fs-6">Total : ${fmtMin(t.total)}</span></div>`;

    const modal = new bootstrap.Modal(document.getElementById('modalCartePassage'));
    modal.show();

    document.getElementById('modalCartePassage').addEventListener('shown.bs.modal', function init() {
        if (mapPassage) { mapPassage.remove(); mapPassage = null; }
        mapPassage = L.map('mapPassage');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(mapPassage);
        if (!points.length) { mapPassage.setView([46.8, 2.3], 6); return; }
        const latlngs = points.map(p => [parseFloat(p.lat), parseFloat(p.lng)]);
        L.polyline(latlngs, { color: '#198754', weight: 3, dashArray: '6,4' }).addTo(mapPassage);
        points.forEach(function(p, i) {
            const isFirst = i === 0, isLast = i === points.length - 1;
            const color = isFirst ? '#198754' : (isLast ? '#dc3545' : '#6c757d');
            const label = isFirst ? 'D' : (isLast ? 'A' : (i+1));
            const icon = L.divIcon({ className: '', html: `<div style="background:${color};color:#fff;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:bold;border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.4)">${label}</div>`, iconSize: [24,24], iconAnchor: [12,12] });
            L.marker([parseFloat(p.lat), parseFloat(p.lng)], { icon }).bindPopup(`<b>Point ${i+1}</b>`).addTo(mapPassage);
        });
        mapPassage.fitBounds(latlngs, { padding: [20,20] });
        this.removeEventListener('shown.bs.modal', init);
    });
}
document.getElementById('modalCartePassage').addEventListener('hidden.bs.modal', function() {
    if (mapPassage) { mapPassage.remove(); mapPassage = null; }
});
</script>
</body>
</html>