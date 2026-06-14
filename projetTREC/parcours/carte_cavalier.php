<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifie que l'utilisateur est connecté
if (!isset($_SESSION['role'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Seul le rôle cavalier a accès à cette page
if ($_SESSION['role'] !== 'cavalier') {
    header('Location: ../index.php');
    exit;
}

include '../config.php';
include '../header.php';

// Récupération de l'utilisateur connecté
$idUtilisateur = (int)($_SESSION['id_utilisateur'] ?? 0);

// Récupération du cavalier lié à l'utilisateur
$stmtCavalier = mysqli_prepare($conn, "
    SELECT id_cavalier
    FROM cavalier
    WHERE id_utilisateur = ?
    LIMIT 1
");

mysqli_stmt_bind_param($stmtCavalier, 'i', $idUtilisateur);
mysqli_stmt_execute($stmtCavalier);

$resultCavalier = mysqli_stmt_get_result($stmtCavalier);
$cavalier = mysqli_fetch_assoc($resultCavalier);

$idCavalier = $cavalier ? (int)$cavalier['id_cavalier'] : 0;

// Récupération des épreuves auxquelles le cavalier est inscrit
$stmt = mysqli_prepare($conn, "
    SELECT DISTINCT
        e.id_epreuve,
        e.nom_epreuve,
        e.id_parcours,
        comp.nom_competition
    FROM inscription i
    JOIN competition comp ON comp.id_competition = i.id_competition
    JOIN epreuve e ON e.id_competition = comp.id_competition
    WHERE i.id_cavalier = ?
    ORDER BY comp.date_competition DESC, e.nom_epreuve ASC
");

mysqli_stmt_bind_param($stmt, 'i', $idCavalier);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<style>
    #map {
        height: 520px;
        width: 100%;
        border-radius: 8px;
    }

    #toast-container {
        position: fixed;
        bottom: 1.5rem;
        right: 1.5rem;
        z-index: 9999;
    }

    /* Mise en page utilisée uniquement lors de l'impression */
    @media print {
        @page {
            size: A4 landscape;
            margin: 5mm;
        }

        html,
        body {
            width: 100%;
            height: 100%;
            margin: 0 !important;
            padding: 0 !important;
            overflow: hidden !important;
        }

        body * {
            visibility: hidden;
        }

        #zone-impression,
        #zone-impression * {
            visibility: visible;
        }

        #zone-impression {
            position: fixed;
            left: 0;
            top: 0;
            width: 100% !important;
            height: 100% !important;
            box-shadow: none !important;
            border: none !important;
            padding: 0 !important;
            margin: 0 !important;
            background: white !important;
        }

        .screen-title {
            display: none !important;
        }

        #titre-impression {
            display: block !important;
            text-align: center;
            margin-bottom: 5px !important;
        }

        #titre-impression h2 {
            font-size: 18px !important;
            margin: 0 !important;
        }

        #infos-impression {
            font-size: 12px !important;
            margin-bottom: 5px !important;
        }

        #map {
            width: 100% !important;
            height: 180mm !important;
            border-radius: 0 !important;
        }
    }
</style>

<main class="flex-grow-1 bg-light">

<section class="py-5 bg-dark text-white text-center shadow-sm">
    <div class="container">
        <h1 class="display-5 fw-bold">Ma carte</h1>
        <p class="lead mb-0">
            Consulter le parcours officiel et le parcours réalisé pendant une épreuve
        </p>
    </div>
</section>

<div class="container py-5">

    <?php if ($idCavalier === 0): ?>

        <div class="alert alert-danger">
            Impossible de récupérer l'identifiant du cavalier connecté.
        </div>

    <?php else: ?>

        <div class="row g-4">

            <div class="col-lg-8">
                <div class="card shadow border-0 h-100 p-4" id="zone-impression">
                    <h2 class="h4 text-success mb-3 screen-title">Carte du parcours</h2>

                    <div id="titre-impression" class="d-none">
                        <h2>Carte du parcours</h2>
                        <p id="infos-impression"></p>
                    </div>

                    <div id="map"></div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow border-0 h-100 p-4 border-start border-4 border-success">

                    <h4 class="fw-bold mb-3">Sélection de l'épreuve</h4>

                    <div class="mb-3">
                        <label for="select-epreuve" class="form-label fw-semibold">Épreuve</label>

                        <select id="select-epreuve" class="form-select">
                            <option value="">-- Choisir une épreuve --</option>

                            <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                <option 
                                    value="<?= $row['id_epreuve'] ?>"
                                    data-parcours="<?= $row['id_parcours'] ?? '' ?>"
                                    data-competition="<?= htmlspecialchars($row['nom_competition']) ?>"
                                    data-epreuve="<?= htmlspecialchars($row['nom_epreuve']) ?>"
                                >
                                    <?= htmlspecialchars($row['nom_competition'] . ' - ' . $row['nom_epreuve']) ?>
                                </option>
                            <?php endwhile; ?>

                        </select>
                    </div>

                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-success" id="btn-toggle-officiel">
                            🟢 Afficher parcours officiel
                        </button>

                        <button class="btn btn-outline-primary" id="btn-toggle-realise">
                            🗺️ Afficher mon parcours réalisé
                        </button>

                        <button class="btn btn-outline-secondary" id="btn-imprimer">
                            🖨️ Imprimer la carte
                        </button>
                    </div>

                </div>
            </div>

        </div>

    <?php endif; ?>

</div>
</main>

<div id="toast-container"></div>

<?php include '../footer.php'; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// Id du cavalier connecté, récupéré depuis PHP
const idCavalier = <?= $idCavalier ?>;

// Initialisation de la carte Leaflet
const map = L.map('map').setView([48.1340, 6.6031], 14);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap'
}).addTo(map);

// Corrige l'affichage de la carte après le chargement de la page
setTimeout(() => map.invalidateSize(), 200);

// Variables pour gérer les parcours affichés
let polylineOfficiel = null;
let polylineRealise = null;
let officielVisible = false;
let realiseVisible = false;

// Raccourci pour récupérer un élément avec son id
const $ = id => document.getElementById(id);

// Efface les parcours affichés sur la carte
function clearParcours() {
    if (polylineOfficiel) {
        map.removeLayer(polylineOfficiel);
        polylineOfficiel = null;
    }

    if (polylineRealise) {
        map.removeLayer(polylineRealise);
        polylineRealise = null;
    }

    officielVisible = false;
    realiseVisible = false;

    $('btn-toggle-officiel').textContent = '🟢 Afficher parcours officiel';
    $('btn-toggle-officiel').classList.remove('btn-success');
    $('btn-toggle-officiel').classList.add('btn-outline-success');

    $('btn-toggle-realise').textContent = '🗺️ Afficher mon parcours réalisé';
    $('btn-toggle-realise').classList.remove('btn-primary');
    $('btn-toggle-realise').classList.add('btn-outline-primary');
}

// Efface la carte quand le cavalier change d'épreuve
$('select-epreuve')?.addEventListener('change', () => {
    clearParcours();
});

// Récupère l'épreuve sélectionnée dans la liste
function getEpreuveSelectionnee() {
    return $('select-epreuve').selectedOptions[0];
}

// Affiche ou masque le parcours officiel
$('btn-toggle-officiel')?.addEventListener('click', async () => {
    const btn = $('btn-toggle-officiel');
    const option = getEpreuveSelectionnee();

    if (!option || !option.value) {
        afficherToast('Choisissez une épreuve.', 'warning');
        return;
    }

    const idParcours = option.dataset.parcours;

    if (!idParcours || idParcours === 'null' || idParcours === '0') {
        afficherToast('Aucun parcours officiel lié à cette épreuve.', 'warning');
        return;
    }

    // Si le parcours officiel est déjà affiché, on le retire
    if (officielVisible) {
        map.removeLayer(polylineOfficiel);
        polylineOfficiel = null;
        officielVisible = false;

        btn.textContent = '🟢 Afficher parcours officiel';
        btn.classList.remove('btn-success');
        btn.classList.add('btn-outline-success');
        return;
    }

    try {
        const data = await fetch(`parcours_officiel.php?id_parcours=${idParcours}`)
            .then(response => response.json());

        if (!data.success) {
            afficherToast('❌ ' + data.message, 'danger');
            return;
        }

        if (!data.points || data.points.length < 2) {
            afficherToast('❌ Pas assez de points pour afficher le parcours officiel.', 'danger');
            return;
        }

        // Affichage du parcours officiel en vert
        polylineOfficiel = L.polyline(
            data.points.map(point => [point.lat, point.lng]),
            {
                color: '#198754',
                weight: 4,
                dashArray: '8 4'
            }
        ).addTo(map);

        map.fitBounds(polylineOfficiel.getBounds());

        officielVisible = true;

        btn.textContent = '🔴 Masquer parcours officiel';
        btn.classList.remove('btn-outline-success');
        btn.classList.add('btn-success');

        afficherToast('✅ Parcours officiel affiché.', 'success');

    } catch {
        afficherToast('❌ Erreur lors du chargement du parcours officiel.', 'danger');
    }
});

// Affiche ou masque le parcours réalisé du cavalier
$('btn-toggle-realise')?.addEventListener('click', async () => {
    const btn = $('btn-toggle-realise');
    const option = getEpreuveSelectionnee();

    if (!option || !option.value) {
        afficherToast('Choisissez une épreuve.', 'warning');
        return;
    }

    const idEpreuve = option.value;

    // Si le parcours réalisé est déjà affiché, on le retire
    if (realiseVisible) {
        map.removeLayer(polylineRealise);
        polylineRealise = null;
        realiseVisible = false;

        btn.textContent = '🗺️ Afficher mon parcours réalisé';
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-outline-primary');
        return;
    }

    try {
        const data = await fetch(`parcours_realise.php?id_epreuve=${idEpreuve}&id_cavalier=${idCavalier}`)
            .then(response => response.json());

        if (!data.success) {
            afficherToast('❌ ' + data.message, 'danger');
            return;
        }

        if (!data.points || data.points.length < 2) {
            afficherToast('❌ Pas assez de points GPS pour afficher le parcours réalisé.', 'danger');
            return;
        }

        // Affichage du parcours réalisé en bleu
        polylineRealise = L.polyline(
            data.points.map(point => [point.lat, point.lng]),
            {
                color: '#0d6efd',
                weight: 4
            }
        ).addTo(map);

        map.fitBounds(polylineRealise.getBounds());

        realiseVisible = true;

        btn.textContent = '🔴 Masquer mon parcours réalisé';
        btn.classList.remove('btn-outline-primary');
        btn.classList.add('btn-primary');

        afficherToast('✅ Parcours réalisé affiché.', 'success');

    } catch {
        afficherToast('❌ Erreur lors du chargement du parcours réalisé.', 'danger');
    }
});

// Prépare le titre et les informations avant l'impression
$('btn-imprimer')?.addEventListener('click', () => {
    const option = getEpreuveSelectionnee();

    if (!officielVisible && !realiseVisible) {
        afficherToast('Affichez un parcours avant impression.', 'warning');
        return;
    }

    const competition = option?.dataset.competition || '';
    const epreuve = option?.dataset.epreuve || '';

    document.querySelector('#titre-impression h2').textContent = epreuve || 'Carte du parcours';
    $('infos-impression').textContent = [competition, epreuve].filter(Boolean).join(' - ');

    setTimeout(() => {
        map.invalidateSize();
        window.print();
    }, 300);
});

// Affiche un petit message en bas à droite
function afficherToast(message, type = 'success') {
    const colors = {
        success: 'bg-success text-white',
        warning: 'bg-warning text-dark',
        danger: 'bg-danger text-white'
    };

    const id = 'toast-' + Date.now();

    $('toast-container').insertAdjacentHTML('beforeend', `
        <div id="${id}" class="toast align-items-center ${colors[type]} border-0 show mb-2" role="alert">
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="document.getElementById('${id}').remove()"></button>
            </div>
        </div>
    `);

    setTimeout(() => {
        const toast = $(id);
        if (toast) toast.remove();
    }, 4000);
}
</script>