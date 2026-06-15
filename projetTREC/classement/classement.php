<?php
require '../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ─── Filtres GET ──────────────────────────────────────────────────────────────
$id_competition_sel = isset($_GET['id_competition']) && $_GET['id_competition'] !== '' ? intval($_GET['id_competition']) : 0;
$id_epreuve_sel     = isset($_GET['id_epreuve'])     && $_GET['id_epreuve']     !== '' ? intval($_GET['id_epreuve'])     : 0;
$categorie_sel      = isset($_GET['categorie'])      ? trim($_GET['categorie'])         : '';

define('POR_CAPITAL', 200);

// ─── Listes filtres ───────────────────────────────────────────────────────────
$competitions_list = mysqli_fetch_all(
    mysqli_query($conn, "SELECT id_competition, nom_competition, date_competition,
                                date_fin_competition, heure_debut, heure_fin, statut
                         FROM competition ORDER BY date_competition DESC"),
    MYSQLI_ASSOC
);

// Uniquement les épreuves POR
$sql_ep_where = ["type_epreuve = 'POR'"];
if ($id_competition_sel) $sql_ep_where[] = "id_competition = $id_competition_sel";
$sql_ep = "SELECT id_epreuve, type_epreuve, nom_epreuve, temps_ideal, id_competition FROM epreuve"
        . " WHERE " . implode(" AND ", $sql_ep_where)
        . " ORDER BY nom_epreuve ASC";
$epreuves_list = mysqli_fetch_all(mysqli_query($conn, $sql_ep), MYSQLI_ASSOC);

$categories_list = mysqli_fetch_all(
    mysqli_query($conn, "SELECT DISTINCT categorie FROM cavalier WHERE categorie IS NOT NULL AND categorie != '' ORDER BY categorie ASC"),
    MYSQLI_ASSOC
);

// ─── Épreuve sélectionnée ─────────────────────────────────────────────────────
$temps_ideal_min  = null;
$type_epreuve_sel = null;
if ($id_epreuve_sel) {
    foreach ($epreuves_list as $ep) {
        if ((int)$ep['id_epreuve'] === $id_epreuve_sel) {
            $temps_ideal_min  = $ep['temps_ideal'] !== null ? (float)$ep['temps_ideal'] : null;
            $type_epreuve_sel = $ep['type_epreuve'] ?? null;
            break;
        }
    }
    if ($type_epreuve_sel === null) {
        $row_ep = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT temps_ideal, type_epreuve FROM epreuve WHERE id_epreuve = $id_epreuve_sel LIMIT 1"
        ));
        if ($row_ep) {
            $temps_ideal_min  = $row_ep['temps_ideal'] !== null ? (float)$row_ep['temps_ideal'] : null;
            $type_epreuve_sel = $row_ep['type_epreuve'];
        }
    }
}

// ─── Compétition sélectionnée ─────────────────────────────────────────────────
$comp_sel = null;
if ($id_competition_sel) {
    foreach ($competitions_list as $comp) {
        if ((int)$comp['id_competition'] === $id_competition_sel) { $comp_sel = $comp; break; }
    }
}

$cat_esc = $categorie_sel !== '' ? mysqli_real_escape_string($conn, $categorie_sel) : '';

// ─── Helpers ──────────────────────────────────────────────────────────────────
function fmtMinutes(?float $min): string {
    if ($min === null) return '—';
    $ts = (int)round($min * 60);
    $h = intdiv($ts, 3600); $m = intdiv($ts % 3600, 60); $s = $ts % 60;
    return $h > 0 ? sprintf('%dh%02d\'%02d"', $h, $m, $s) : sprintf('%d\'%02d"', $m, $s);
}
function fmtTime(?string $t): string { return $t ? substr($t, 0, 8) : '—'; }
function fmtDate(?string $d1, ?string $d2 = null): string {
    if (!$d1) return '—';
    $debut = date('d/m/Y', strtotime($d1));
    if ($d2 && $d2 !== $d1) return $debut . ' → ' . date('d/m/Y', strtotime($d2));
    return $debut;
}
function fmtEcart(int $e): string {
    $abs = abs($e); $m = intdiv($abs, 60); $s = $abs % 60;
    $str = $m > 0 ? sprintf('%d\'%02d"', $m, $s) : $abs . '"';
    return ($e > 0 ? '+' : ($e < 0 ? '−' : '')) . $str;
}
function scoreBadgeClass(int $sc): string {
    if ($sc >= 180) return 'bg-success';
    if ($sc >= 150) return 'bg-warning text-dark';
    return 'bg-danger';
}
function calcPOR(float $r, float $i): array {
    $rs = (int)round($r * 60); $is = (int)round($i * 60); $e = $rs - $is;
    $p  = (int)floor(abs($e) / 4);
    return ['ecart_s' => $e, 'penalites' => $p, 'score' => max(0, 200 - $p)];
}

// ─── Jointure de base ─────────────────────────────────────────────────────────
$base_join = "
    FROM passage p
    JOIN dossard dos        ON dos.id_dossard      = p.id_dossard
    JOIN epreuve e          ON e.id_epreuve         = p.id_epreuve
    JOIN competition cmp    ON cmp.id_competition   = e.id_competition
    LEFT JOIN inscription i ON i.id_dossard         = dos.id_dossard
                           AND i.id_competition      = e.id_competition
    LEFT JOIN cavalier c    ON c.id_cavalier         = i.id_cavalier
";

// ─── WHERE live ───────────────────────────────────────────────────────────────
$wl = ["e.type_epreuve = 'POR'"];
if ($id_competition_sel) $wl[] = "e.id_competition = $id_competition_sel";
if ($id_epreuve_sel)     $wl[] = "p.id_epreuve = $id_epreuve_sel";
if ($cat_esc)            $wl[] = "c.categorie = '$cat_esc'";
$where_live = "WHERE " . implode(" AND ", $wl);

// ─── WHERE classement ─────────────────────────────────────────────────────────
$wc = ["e.type_epreuve = 'POR'", "(p.statut = 'termine' OR p.fin IS NOT NULL)", "p.fin IS NOT NULL", "p.debut IS NOT NULL"];
if ($id_competition_sel) $wc[] = "e.id_competition = $id_competition_sel";
if ($id_epreuve_sel)     $wc[] = "p.id_epreuve = $id_epreuve_sel";
if ($cat_esc)            $wc[] = "c.categorie = '$cat_esc'";
$where_cl = "WHERE " . implode(" AND ", $wc);

// ─── Marquer automatiquement terminé si fin renseignée ────────────────────────
mysqli_query($conn, "UPDATE passage SET statut = 'termine' WHERE fin IS NOT NULL AND statut != 'termine'");

// ─── Passages récents (live) ──────────────────────────────────────────────────
$sql_live = "
    SELECT p.id_passage, p.debut, p.fin, p.statut,
           p.duree_troncon1_min, p.duree_troncon2_min, p.duree_troncon3_min, p.duree_minutes_totales,
           dos.numero_dossard,
           c.nom_cavalier, c.prenom_cavalier, c.categorie,
           cmp.nom_competition, cmp.date_competition, cmp.date_fin_competition,
           e.type_epreuve, e.nom_epreuve, e.temps_ideal
    $base_join
    $where_live
    GROUP BY p.id_passage
    ORDER BY p.id_passage DESC LIMIT 20
";
$liveRows = mysqli_fetch_all(mysqli_query($conn, $sql_live), MYSQLI_ASSOC);

// ─── Classement POR ───────────────────────────────────────────────────────────
$sql_cl = "
    SELECT dos.numero_dossard, c.nom_cavalier, c.prenom_cavalier, c.categorie,
           c.id_cavalier, p.id_epreuve,
           cmp.nom_competition, cmp.date_competition, cmp.date_fin_competition,
           e.type_epreuve, e.nom_epreuve, e.temps_ideal,
           ROUND(TIME_TO_SEC(TIMEDIFF(p.fin, p.debut)) / 60, 4) AS meilleur_temps_total
    $base_join
    $where_cl
    GROUP BY dos.id_dossard, p.id_epreuve
    ORDER BY meilleur_temps_total ASC
";
$raw = mysqli_fetch_all(mysqli_query($conn, $sql_cl), MYSQLI_ASSOC);

foreach ($raw as &$row) {
    $ti = $temps_ideal_min ?? ($row['temps_ideal'] !== null ? (float)$row['temps_ideal'] : null);
    if ($ti !== null) {
        $cx = calcPOR((float)$row['meilleur_temps_total'], $ti);
        $row['score'] = $cx['score']; $row['penalites'] = $cx['penalites']; $row['ecart_s'] = $cx['ecart_s'];
    } else {
        $row['score'] = null; $row['penalites'] = null; $row['ecart_s'] = null;
    }
    $row['temps_ideal_affiche'] = $ti;
}
unset($row);

usort($raw, function ($a, $b) {
    if ($a['score'] !== null && $b['score'] !== null) {
        if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
        return $a['penalites'] <=> $b['penalites'];
    }
    return $a['meilleur_temps_total'] <=> $b['meilleur_temps_total'];
});

$classementRows = $raw;

// ─── Persistance dans resultat ────────────────────────────────────────────────
foreach ($classementRows as $row) {
    if ($row['score'] === null) continue;

    $id_cav = (int)$row['id_cavalier'];
    $id_ep  = (int)$row['id_epreuve'];
    if (!$id_cav || !$id_ep) continue;
    
    $score  = (int)$row['score'];
    $pen    = (int)$row['penalites'];

    $ts  = (int)round((float)$row['meilleur_temps_total'] * 60);
    $h   = intdiv($ts, 3600); $m = intdiv($ts % 3600, 60); $s = $ts % 60;
    $tps = sprintf('%02d:%02d:%02d', $h, $m, $s);

    $stmt = $conn->prepare("
        UPDATE resultat
        SET temps_total = ?, score = ?, penalites = ?
        WHERE id_cavalier = ? AND id_epreuve = ?
    ");
    $stmt->bind_param('siiii', $tps, $score, $pen, $id_cav, $id_ep);
    $stmt->execute();
}
?>
<meta http-equiv="refresh" content="30">
<?php require '../header.php'; ?>
<style>
.pulse{animation:pulse-anim 2s infinite}
@keyframes pulse-anim{0%,100%{opacity:1}50%{opacity:.5}}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}
.rang-1{background:linear-gradient(90deg,#fffbe6,#fff)!important;font-weight:700}
.rang-2{background:linear-gradient(90deg,#f5f5f5,#fff)!important}
.rang-3{background:linear-gradient(90deg,#fff3e6,#fff)!important}
.score-bar{height:6px;border-radius:3px;background:#e9ecef;overflow:hidden}
.score-bar-fill{height:100%;border-radius:3px;transition:width .4s}
.troncon-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:.75rem;background:#e8f4fd;color:#0d6efd;font-weight:600}
.category-badge{font-size:.7rem;padding:2px 6px;border-radius:10px;background:#f0f0f0;color:#555;border:1px solid #ddd}
.podium-card{transition:transform .2s;cursor:default}.podium-card:hover{transform:translateY(-4px)}
</style>
<main class="flex-grow-1 bg-light">

<section class="py-4 bg-dark text-white text-center shadow-sm">
    <div class="container">
        <h1 class="display-6 fw-bold mb-1 d-flex justify-content-center align-items-center flex-wrap gap-2">
            <i class="bi bi-trophy-fill text-warning"></i>
            <?php
            if ($comp_sel) {
                echo htmlspecialchars($comp_sel['nom_competition']);
                $today = date('Y-m-d');
                $dd = $comp_sel['date_competition'];
                $df = !empty($comp_sel['date_fin_competition']) ? $comp_sel['date_fin_competition'] : $dd;
                if ($today >= $dd && $today <= $df)
                    echo '<span class="badge bg-danger pulse fs-6"><i class="bi bi-broadcast me-1"></i>En direct</span>';
                elseif ($today > $df)
                    echo '<span class="badge bg-secondary fs-6">🏁 Terminée</span>';
                else
                    echo '<span class="badge bg-success fs-6">✅ À venir</span>';
            } else {
                echo 'Classement TREC';
            }
            ?>
        </h1>
        <p class="lead mb-0 text-white-50">Parcours d'Orientation et de Régularité – TREC</p>
    </div>
</section>

<div class="container py-4">

    <!-- FILTRES -->
    <form method="GET" action="classement.php">
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <label class="form-label small fw-semibold text-muted mb-2">
                        <i class="bi bi-calendar-event me-1"></i>Compétition
                    </label>
                    <select name="id_competition" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Toutes les compétitions</option>
                        <?php foreach ($competitions_list as $c): ?>
                        <option value="<?= (int)$c['id_competition'] ?>"
                            <?= $id_competition_sel === (int)$c['id_competition'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['nom_competition']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <label class="form-label small fw-semibold text-muted mb-2">
                        <i class="bi bi-flag me-1"></i>Épreuve <span class="badge bg-primary ms-1">POR</span>
                    </label>
                    <select name="id_epreuve" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Toutes les épreuves</option>
                        <?php foreach ($epreuves_list as $ep):
                            $n = trim($ep['nom_epreuve'] ?? '');
                            $lbl = $n ?: 'Épreuve POR #' . $ep['id_epreuve'];
                        ?>
                        <option value="<?= (int)$ep['id_epreuve'] ?>"
                            <?= $id_epreuve_sel === (int)$ep['id_epreuve'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($lbl) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <label class="form-label small fw-semibold text-muted mb-2">
                        <i class="bi bi-person-badge me-1"></i>Catégorie
                    </label>
                    <select name="categorie" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Toutes les catégories</option>
                        <?php foreach ($categories_list as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['categorie']) ?>"
                            <?= $categorie_sel === $cat['categorie'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['categorie']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div></div>
            </div>
        </div>

        <?php if ($id_competition_sel || $id_epreuve_sel || $categorie_sel !== ''): ?>
        <div class="mb-3 d-flex gap-2 flex-wrap align-items-center">
            <small class="text-muted">Filtres actifs :</small>
            <?php if ($id_competition_sel):
                $cn = ''; foreach ($competitions_list as $c) { if ((int)$c['id_competition'] === $id_competition_sel) { $cn = $c['nom_competition']; break; } }
            ?><span class="badge bg-info text-dark"><?= htmlspecialchars($cn) ?></span><?php endif; ?>
            <?php if ($id_epreuve_sel):
                $el = ''; foreach ($epreuves_list as $ep) { if ((int)$ep['id_epreuve'] === $id_epreuve_sel) { $el = trim($ep['nom_epreuve'] ?? 'POR #'.$ep['id_epreuve']); break; } }
            ?><span class="badge bg-primary"><?= htmlspecialchars($el) ?></span><?php endif; ?>
            <?php if ($categorie_sel !== ''): ?>
                <span class="badge text-white" style="background:#6f42c1">
                    <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($categorie_sel) ?>
                </span>
            <?php endif; ?>
            <a href="classement.php" class="btn btn-outline-danger btn-sm py-0 px-2">
                <i class="bi bi-x"></i> Réinitialiser
            </a>
        </div>
        <?php endif; ?>
    </form>

    <?php if ($temps_ideal_min !== null): ?>
    <div class="alert alert-success d-flex align-items-center gap-3 py-2 mb-3">
        <i class="bi bi-clock-fill fs-5"></i>
        <span>Temps idéal : <strong><?= fmtMinutes($temps_ideal_min) ?></strong>
        <span class="text-muted ms-2 small">(<?= number_format($temps_ideal_min, 2) ?> min)</span>
        — 200 pts − 1 pén. / 4 s d'écart</span>
    </div>
    <?php elseif ($id_epreuve_sel): ?>
    <div class="alert alert-warning d-flex align-items-center gap-3 py-2 mb-3">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <span>Aucun temps idéal défini pour cette épreuve — classement par temps total.</span>
    </div>
    <?php endif; ?>

    <!-- ═══ PASSAGES RÉCENTS ═══ -->
    <div class="card border-0 shadow mb-4">
        <div class="card-header bg-dark text-white py-3 d-flex align-items-center gap-2">
            <span class="badge bg-danger" style="width:10px;height:10px;border-radius:50%;padding:0;animation:blink 1.2s infinite">&nbsp;</span>
            <strong>Passages récents</strong>
            <span class="badge bg-secondary ms-auto"><?= count($liveRows) ?> passage(s)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light"><tr>
                    <th>Dossard / Cavalier</th><th>Catégorie</th><th>Épreuve</th>
                    <th>Départ</th><th>Arrivée</th><th class="text-center">Tronçons</th>
                    <th class="text-end">Temps total</th>
                    <?php if ($temps_ideal_min !== null): ?>
                    <th class="text-end">Écart</th><th class="text-end">Score</th>
                    <?php endif; ?>
                    <th class="text-center">Statut</th>
                </tr></thead>
                <tbody>
                <?php if (empty($liveRows)): ?>
                    <tr><td colspan="<?= $temps_ideal_min !== null ? 10 : 8 ?>" class="text-center text-muted py-4">
                        <i class="bi bi-hourglass me-2"></i>Aucun passage enregistré
                    </td></tr>
                <?php else: foreach ($liveRows as $row):
                    $tps  = (!empty($row['debut']) && !empty($row['fin'])) ? round((strtotime($row['fin']) - strtotime($row['debut'])) / 60, 4) : null;
                    $fini = !empty($row['fin']);
                    $ti_l = $temps_ideal_min ?? ($row['temps_ideal'] !== null ? (float)$row['temps_ideal'] : null);
                    $calc = ($fini && $tps !== null && $ti_l !== null) ? calcPOR($tps, $ti_l) : null;
                    $pid  = $row['id_passage'];
                    $ht   = !empty($row['duree_troncon1_min']) || !empty($row['duree_troncon2_min']) || !empty($row['duree_troncon3_min']);
                ?>
                <tr>
                    <td>
                        <span class="badge bg-secondary">#<?= htmlspecialchars($row['numero_dossard']) ?></span>
                        <div class="fw-semibold text-uppercase small mt-1">
                            <?= htmlspecialchars(trim(($row['nom_cavalier'] ?? '') . ' ' . ($row['prenom_cavalier'] ?? ''))) ?: '<span class="text-muted fst-italic">—</span>' ?>
                        </div>
                    </td>
                    <td><?= !empty($row['categorie']) ? '<span class="category-badge">' . htmlspecialchars($row['categorie']) . '</span>' : '—' ?></td>
                    <td><small class="text-muted"><?= htmlspecialchars($row['nom_epreuve'] ?? 'POR') ?></small></td>
                    <td><?= fmtTime($row['debut'] ?? null) ?></td>
                    <td><?= fmtTime($row['fin']   ?? null) ?></td>
                    <td class="text-center">
                        <?php if ($ht): ?>
                            <button class="btn btn-link btn-sm p-0 text-primary" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#trc-<?= $pid ?>">
                                <i class="bi bi-chevron-down"></i> Détail
                            </button>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-end fw-semibold <?= $fini ? 'text-success' : 'text-muted' ?>">
                        <?= $tps !== null ? fmtMinutes($tps) : '—' ?>
                    </td>
                    <?php if ($temps_ideal_min !== null): ?>
                    <td class="text-end">
                        <?= $calc ? '<span class="' . ($calc['ecart_s'] === 0 ? 'text-success fw-bold' : 'text-danger') . '">' . fmtEcart($calc['ecart_s']) . '</span>' : '—' ?>
                    </td>
                    <td class="text-end">
                        <?= $calc ? '<span class="badge ' . scoreBadgeClass($calc['score']) . '">' . $calc['score'] . ' pts</span>' : '—' ?>
                    </td>
                    <?php endif; ?>
                    <td class="text-center">
                        <?= $fini
                            ? '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Terminé</span>'
                            : '<span class="badge bg-warning text-dark pulse"><i class="bi bi-clock-history me-1"></i>En cours</span>' ?>
                    </td>
                </tr>
                <?php if ($ht): ?>
                <tr class="collapse" id="trc-<?= $pid ?>">
                    <td colspan="<?= $temps_ideal_min !== null ? 10 : 8 ?>" class="py-2 ps-5 bg-light">
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach ([
                                ['Tronçon 1','duree_troncon1_min','bi-1-circle'],
                                ['Tronçon 2','duree_troncon2_min','bi-2-circle'],
                                ['Tronçon 3','duree_troncon3_min','bi-3-circle'],
                            ] as [$l,$k,$ic]):
                                if (empty($row[$k])) continue; ?>
                            <div class="troncon-badge"><i class="bi <?= $ic ?>"></i> <?= $l ?> : <strong><?= htmlspecialchars($row[$k]) ?>'</strong></div>
                            <?php endforeach; ?>
                            <?php if (!empty($row['duree_minutes_totales'])): ?>
                            <div class="troncon-badge" style="background:#fff3cd;color:#856404;">
                                <i class="bi bi-clock-fill"></i> Total : <strong><?= htmlspecialchars($row['duree_minutes_totales']) ?>'</strong>
                            </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══ CLASSEMENT POR ═══ -->
    <div class="card border-0 shadow">
        <div class="card-header bg-warning text-dark py-3 d-flex align-items-center gap-2">
            <i class="bi bi-trophy-fill fs-5"></i>
            <strong>Classement POR – Score de régularité</strong>
            <?php if (!empty($classementRows)): ?>
                <span class="badge bg-dark text-warning ms-auto"><?= count($classementRows) ?> concurrent(s)</span>
            <?php endif; ?>
        </div>

        <?php if ($temps_ideal_min !== null && count($classementRows) >= 1):
            $pod = array_slice($classementRows, 0, min(3, count($classementRows)));
            $med = ['🥇','🥈','🥉']; $col = ['#FFD700','#C0C0C0','#CD7F32']; $hgt = ['180px','220px','150px'];
            $ord = count($pod) === 3 ? [1,0,2] : range(0, count($pod)-1);
        ?>
        <div class="d-flex justify-content-center gap-3 py-4 bg-white border-bottom flex-wrap px-3">
            <?php foreach ($ord as $pi): if (!isset($pod[$pi])) continue; $p = $pod[$pi]; ?>
            <div class="text-center podium-card" style="min-width:120px">
                <div class="mb-1 fs-2"><?= $med[$pi] ?></div>
                <div class="fw-bold text-uppercase small"><?= htmlspecialchars(trim(($p['nom_cavalier'] ?? '') . ' ' . ($p['prenom_cavalier'] ?? ''))) ?></div>
                <div class="text-muted small mb-1">#<?= htmlspecialchars($p['numero_dossard']) ?></div>
                <?php if (!empty($p['categorie'])): ?><div class="mb-2"><span class="category-badge"><?= htmlspecialchars($p['categorie']) ?></span></div><?php endif; ?>
                <div class="d-flex flex-column align-items-center justify-content-end"
                     style="height:<?= $hgt[$pi] ?>;background:<?= $col[$pi] ?>22;border-radius:8px 8px 0 0;padding:8px;">
                    <span class="badge <?= scoreBadgeClass((int)$p['score']) ?> fs-6 mb-1"><?= $p['score'] ?> pts</span>
                    <small class="text-muted"><?= $p['penalites'] ?> pén.</small>
                    <small class="text-muted"><?= fmtMinutes((float)($p['meilleur_temps_total'] ?? 0)) ?></small>
                    <div class="score-bar mt-2 w-100">
                        <div class="score-bar-fill bg-<?= $pi===0?'warning':($pi===1?'secondary':'danger') ?>"
                             style="width:<?= round((int)$p['score'] / 2) ?>%"></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th style="width:50px">Rang</th><th>Cavalier</th><th>Catégorie</th>
                    <th>Dossard</th><th>Compétition</th><th>Épreuve</th>
                    <th>Temps réel</th><th>Temps idéal</th><th>Écart</th>
                    <th class="text-end">Pénalités</th><th class="text-end">Score</th>
                </tr></thead>
                <tbody>
                <?php if (empty($classementRows)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-5">
                        <i class="bi bi-trophy fs-3 d-block mb-2 opacity-25"></i>
                        Aucun résultat — les passages apparaîtront ici dès qu'une arrivée sera enregistrée.
                    </td></tr>
                <?php else: foreach ($classementRows as $i => $row):
                    $rc = match($i) { 0=>'rang-1', 1=>'rang-2', 2=>'rang-3', default=>'' };
                    $ti = $temps_ideal_min ?? ($row['temps_ideal'] ?? null ? (float)$row['temps_ideal'] : null);
                    $tr = isset($row['meilleur_temps_total']) && $row['meilleur_temps_total'] !== null ? (float)$row['meilleur_temps_total'] : null;
                    $sc = $row['score']     ?? null;
                    $pe = $row['penalites'] ?? null;
                    $ec = isset($row['ecart_s']) ? (int)$row['ecart_s'] : null;
                    if ($sc === null && $ti !== null && $tr !== null) {
                        $cx = calcPOR($tr, $ti); $sc = $cx['score']; $pe = $cx['penalites']; $ec = $cx['ecart_s'];
                    }
                ?>
                <tr class="<?= $rc ?>">
                    <td class="text-center"><?= match($i) {
                        0=>'<span class="fs-5">🥇</span>', 1=>'<span class="fs-5">🥈</span>',
                        2=>'<span class="fs-5">🥉</span>', default=>'<span class="text-muted">'.($i+1).'</span>'
                    } ?></td>
                    <td class="text-uppercase fw-semibold"><?= htmlspecialchars(trim(($row['nom_cavalier'] ?? '—') . ' ' . ($row['prenom_cavalier'] ?? ''))) ?></td>
                    <td><?= !empty($row['categorie']) ? '<span class="category-badge">'.htmlspecialchars($row['categorie']).'</span>' : '—' ?></td>
                    <td><span class="badge bg-secondary">#<?= htmlspecialchars($row['numero_dossard']) ?></span></td>
                    <td><small class="text-muted"><?= htmlspecialchars($row['nom_competition'] ?? '—') ?></small></td>
                    <td><small class="text-muted"><?= htmlspecialchars($row['nom_epreuve'] ?? 'POR') ?></small></td>
                    <td class="fw-bold"><?= $tr !== null ? fmtMinutes($tr) : '—' ?></td>
                    <td><?= $ti !== null ? '<span class="text-muted">'.fmtMinutes($ti).'</span>' : '<span class="text-muted fst-italic small">—</span>' ?></td>
                    <td><?= $ec !== null ? '<span class="'.($ec===0?'text-success fw-bold':($ec>0?'text-danger':'text-primary')).'">'.fmtEcart($ec).'</span>' : '—' ?></td>
                    <td class="text-end"><?= $pe !== null ? '<span class="'.($pe===0?'text-success fw-bold':'text-danger').'">'.$pe.' pén.</span>' : '—' ?></td>
                    <td class="text-end"><?= $sc !== null ? '<span class="badge '.scoreBadgeClass((int)$sc).'">'.$sc.' pts</span>' : '—' ?></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-transparent text-muted small py-2 px-3">
            <i class="bi bi-info-circle me-1"></i>
            Capital POR : <strong>200 pts</strong> — 1 pén. / tranche de <strong>4 s</strong> d'écart — Score min : <strong>0</strong>
        </div>
    </div>

</div>
</main>
<?php include '../footer.php'; ?>