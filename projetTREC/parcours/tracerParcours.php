<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Récupération de l'utilisateur connecté et de son rôle
$utilisateurConnecte = !empty($_SESSION['id_utilisateur']);
$roleUtilisateur = $_SESSION['role'] ?? '';

// Redirection si l'utilisateur n'est pas connecté
if (!$utilisateurConnecte) {
    header('Location: ../auth/login.php');
    exit;
}

// Accès autorisé uniquement à certains rôles
if (!in_array($roleUtilisateur, ['chef_piste', 'organisateur', 'admin'], true)) {
    header('Location: /index.php');
    exit;
}

include '../config.php';

// Traitement AJAX pour enregistrer une pénalité
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_penalite') {
    header('Content-Type: application/json');

    $idEpreuve = intval($_POST['id_epreuve'] ?? 0);
    $idCavalier = intval($_POST['id_cavalier'] ?? 0);
    $mauvaisItineraire = intval($_POST['mauvais_itineraire'] ?? 0);

    if ($idEpreuve <= 0 || $idCavalier <= 0) {
        echo json_encode(['success' => false, 'message' => 'Choisissez une épreuve et un cavalier.']);
        exit;
    }

    // Empêche d'avoir une valeur négative
    if ($mauvaisItineraire < 0) {
        $mauvaisItineraire = 0;
    }

    // 1 mauvais itinéraire correspond à 30 points de pénalité
    $penalites = $mauvaisItineraire * 30;

    // Vérifie si un résultat existe déjà pour ce cavalier et cette épreuve
    $check = $conn->prepare('SELECT id_resultat FROM resultat WHERE id_epreuve = ? AND id_cavalier = ? LIMIT 1');
    $check->bind_param('ii', $idEpreuve, $idCavalier);
    $check->execute();
    $resCheck = $check->get_result();

    if ($row = $resCheck->fetch_assoc()) {
        // Mise à jour des pénalités si le résultat existe déjà
        $stmt = $conn->prepare('UPDATE resultat SET penalites = ? WHERE id_resultat = ?');
        $stmt->bind_param('ii', $penalites, $row['id_resultat']);
    } else {
        // Création d'un résultat si aucun n'existe encore
        $tempsTotal = '00:00:00';
        $score = 0;
        $stmt = $conn->prepare('INSERT INTO resultat (id_epreuve, id_cavalier, temps_total, penalites, score) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('iisii', $idEpreuve, $idCavalier, $tempsTotal, $penalites, $score);
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'penalites' => $penalites]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de l’enregistrement.']);
    }

    exit;
}

include '../header.php';

// Gestion des droits d'affichage des deux parties
$peutParcoursOfficiel = $estAdmin || $estChef;
$peutParcoursRealise = $estAdmin || $estOrganisateur;
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<style>
    #map {
        height: 520px;
        width: 100%;
        border-radius: 8px;
    }

    #map.mode-ajout {
        cursor: crosshair;
    }

    #btn-ajouter.active {
        background-color: #198754 !important;
        color: white !important;
        border-color: #198754 !important;
    }

    #toast-container {
        position: fixed;
        bottom: 1.5rem;
        right: 1.5rem;
        z-index: 9999;
    }

    /* Mise en page spéciale pour l'impression de la carte */
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
            background: #fff !important;
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

        .no-print {
            display: none !important;
        }
    }
</style>

<main class="flex-grow-1 bg-light">

<section class="py-5 bg-dark text-white text-center shadow-sm">
    <div class="container">
        <h1 class="display-5 fw-bold">Gestion des parcours</h1>
    </div>
</section>

<div class="container py-5">

    <div class="row g-4">

        <div class="col-lg-8">
            <div class="card shadow border-0 h-100 p-4" id="zone-impression">
                <h2 class="h4 text-success font-title mb-3 screen-title">Carte du parcours</h2>

                <div id="titre-impression" class="d-none">
                    <h2>Carte du parcours</h2>
                    <p id="infos-impression"></p>
                </div>

                <div id="map"></div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow border-0 h-100 p-4 border-start border-4 border-warning">
                <h4 class="fw-bold font-title mb-3">Type de parcours</h4>

                <div class="btn-group w-100 mb-4" role="group">
                    <button class="btn btn-outline-dark <?= $peutParcoursOfficiel ? 'active' : 'd-none' ?>" id="tab-epreuve">Parcours officiel</button>
                    <button class="btn btn-outline-dark <?= $peutParcoursRealise ? (!$peutParcoursOfficiel ? 'active' : '') : 'd-none' ?>" id="tab-realise">Parcours réalisé</button>
                </div>

                <hr>

                <!-- Partie chef de piste : création du parcours officiel -->
                <div id="panel-epreuve" class="<?= $peutParcoursOfficiel ? '' : 'd-none' ?>">
                    <h5 class="fw-bold font-title mb-3">Tracer le parcours officiel</h5>

                    <div class="mb-3">
                        <label for="nom-parcours" class="form-label fw-semibold">Nom du parcours</label>
                        <input type="text" id="nom-parcours" class="form-control" placeholder="Ex : Parcours POR 2026">
                    </div>

                    <div class="mb-3 d-flex align-items-center gap-2">
                        <span class="badge bg-secondary" id="compteur-points">0 point(s)</span>
                        <span class="text-muted small" id="distance-affichee"></span>
                    </div>

                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-success" id="btn-ajouter">✚ Ajouter des points</button>
                        <button class="btn btn-outline-warning" id="btn-supprimer" disabled>⌫ Supprimer dernier point</button>
                        <button class="btn btn-outline-danger" id="btn-reinitialiser" disabled>✕ Réinitialiser les points</button>

                        <hr class="my-1">

                        <button class="btn btn-success" id="btn-enregistrer" disabled>💾 Enregistrer le parcours</button>
                        <button class="btn btn-outline-dark" id="btn-gpx" disabled>📂 Générer GPX</button>
                        <button class="btn btn-outline-secondary" id="btn-imprimer">🖨️ Imprimer la carte</button>
                    </div>
                </div>

                <!-- Partie organisateur : consultation des parcours réalisés -->
                <div id="panel-realise" class="<?= (!$peutParcoursOfficiel && $peutParcoursRealise) ? '' : 'd-none' ?>">
                    <h5 class="fw-bold font-title mb-3">Consulter les parcours</h5>

                    <div class="mb-3">
                        <label for="select-competition" class="form-label fw-semibold">Compétition</label>
                        <select id="select-competition" class="form-select">
                            <option value="">-- Choisir une compétition --</option>

                            <?php
                            // Récupération des compétitions pour remplir la liste déroulante
                            $res = mysqli_query($conn, "
                                SELECT id_competition, nom_competition 
                                FROM competition 
                                ORDER BY date_competition DESC
                            ");

                            while ($row = mysqli_fetch_assoc($res)) {
                                echo '<option value="' . $row['id_competition'] . '">' . htmlspecialchars($row['nom_competition']) . '</option>';
                            }
                            ?>

                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="select-epreuve" class="form-label fw-semibold">Épreuve</label>
                        <select id="select-epreuve" class="form-select" disabled>
                            <option value="">-- Choisir une épreuve --</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="select-cavalier" class="form-label fw-semibold">Cavalier</label>
                        <select id="select-cavalier" class="form-select" disabled>
                            <option value="">-- Choisir un cavalier --</option>
                        </select>
                    </div>

                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-success" id="btn-toggle-officiel">
                            🟢 Afficher parcours officiel
                        </button>

                        <button class="btn btn-outline-primary" id="btn-afficher-realise">
                            🗺️ Afficher parcours réalisé
                        </button>

                        <button class="btn btn-outline-secondary" id="btn-imprimer-realise">
                            🖨️ Imprimer la carte
                        </button>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <!-- Bloc permettant d'ajouter les pénalités du cavalier -->
    <div class="row justify-content-center mt-4 <?= (!$peutParcoursOfficiel && $peutParcoursRealise) ? '' : 'd-none' ?>" id="bloc-penalite">
        <div class="col-lg-6 col-md-8">
            <div class="card border-danger shadow-sm">
                <div class="card-header bg-danger text-white fw-bold text-center">
                    Pénalité POR
                </div>

                <div class="card-body text-center">
                    <label for="mauvais-itineraire" class="form-label fw-semibold">
                        Mauvais itinéraire
                    </label>

                    <input type="number" id="mauvais-itineraire" class="form-control text-center mb-2" min="0" value="0">

                    <p class="mb-2 small text-muted">
                        1 mauvais itinéraire = 30 points
                    </p>

                    <div class="alert alert-danger py-2 mb-3">
                        Total : <strong><span id="total-penalites">0</span> points</strong>
                    </div>

                    <button class="btn btn-danger" id="btn-enregistrer-penalite">
                        Enregistrer la pénalité
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
</main>

<div id="toast-container"></div>

<?php include '../footer.php'; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Initialisation de la carte Leaflet
const map = L.map('map').setView([48.1340, 6.6031], 14);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap'
}).addTo(map);

// Corrige l'affichage de la carte après le chargement
setTimeout(() => map.invalidateSize(), 200);

// Raccourci pour récupérer un élément par son id
const $ = id => document.getElementById(id);

// Éléments HTML utilisés pour créer le parcours
const btnAjouter = $('btn-ajouter');
const btnSupprimer = $('btn-supprimer');
const btnReinit = $('btn-reinitialiser');
const btnEnregistrer = $('btn-enregistrer');
const btnGpx = $('btn-gpx');
const nomInput = $('nom-parcours');
const compteur = $('compteur-points');
const distAff = $('distance-affichee');

// Parcours officiel en cours de création
let points = [];
let markers = [];
let polyline = null;
let modeAjout = false;

// Parcours affichés sur la carte
let polylineRealise = null;
let polylineOfficiel = null;
let realiseVisible = false;
let officielVisible = false;

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

// Supprime un élément de la carte
function removeLayer(layer) {
    if (layer) {
        map.removeLayer(layer);
    }
}

// Change l'apparence d'un bouton selon son état
function setButton(btn, text, activeClass, outlineClass, active) {
    btn.textContent = text;

    if (active) {
        btn.classList.remove(outlineClass);
        btn.classList.add(activeClass);
    } else {
        btn.classList.remove(activeClass);
        btn.classList.add(outlineClass);
    }
}

// Efface le parcours officiel en cours de création
function clearEpreuve() {
    markers.forEach(marker => map.removeLayer(marker));

    markers = [];
    points = [];

    removeLayer(polyline);
    polyline = null;

    modeAjout = false;
    btnAjouter.classList.remove('active');
    $('map').classList.remove('mode-ajout');

    updateUI();
}

// Efface les parcours affichés en consultation
function clearRealise() {
    removeLayer(polylineRealise);
    removeLayer(polylineOfficiel);

    polylineRealise = null;
    polylineOfficiel = null;

    realiseVisible = false;
    officielVisible = false;

    setButton(
        $('btn-afficher-realise'),
        '🗺️ Afficher parcours réalisé',
        'btn-primary',
        'btn-outline-primary',
        false
    );

    setButton(
        $('btn-toggle-officiel'),
        '🟢 Afficher parcours officiel',
        'btn-success',
        'btn-outline-success',
        false
    );
}

// Change l'affichage entre parcours officiel et parcours réalisé
function afficherPanel(panelVisible, panelCache, tabActif, tabInactif) {
    clearRealise();
    clearEpreuve();

    $(panelVisible).classList.remove('d-none');
    $(panelCache).classList.add('d-none');

    if ($('bloc-penalite')) {
        $('bloc-penalite').classList.toggle('d-none', panelVisible !== 'panel-realise');
    }

    $(tabActif).classList.add('active');
    $(tabInactif).classList.remove('active');

    setTimeout(() => map.invalidateSize(), 200);
}

// Onglet parcours officiel
$('tab-epreuve').addEventListener('click', () => {
    afficherPanel('panel-epreuve', 'panel-realise', 'tab-epreuve', 'tab-realise');
});

// Onglet parcours réalisé
$('tab-realise').addEventListener('click', () => {
    afficherPanel('panel-realise', 'panel-epreuve', 'tab-realise', 'tab-epreuve');
});

// Active ou désactive le mode ajout de points
btnAjouter.addEventListener('click', () => {
    modeAjout = !modeAjout;
    btnAjouter.classList.toggle('active', modeAjout);
    $('map').classList.toggle('mode-ajout', modeAjout);
});

// Ajoute un point sur la carte au clic
map.on('click', ({ latlng }) => {
    if (!modeAjout) return;

    points.push({
        lat: latlng.lat,
        lng: latlng.lng
    });

    // Affiche un marqueur numéroté
    const marker = L.marker([latlng.lat, latlng.lng])
        .addTo(map)
        .bindTooltip(`${points.length}`, {
            permanent: true,
            direction: 'top'
        });

    markers.push(marker);

    afficherLigneParcours();
    updateUI();
});

// Supprime le dernier point ajouté
btnSupprimer.addEventListener('click', () => {
    if (points.length === 0) return;

    points.pop();

    const marker = markers.pop();
    removeLayer(marker);

    afficherLigneParcours();
    updateUI();
});

// Réinitialise tous les points du parcours
btnReinit.addEventListener('click', () => {
    if (!confirm('Réinitialiser tous les points ?')) return;
    clearEpreuve();
});

// Affiche ou met à jour la ligne du parcours officiel
function afficherLigneParcours() {
    removeLayer(polyline);
    polyline = null;

    if (points.length >= 2) {
        polyline = L.polyline(
            points.map(p => [p.lat, p.lng]),
            {
                color: '#198754',
                weight: 4
            }
        ).addTo(map);
    }
}

// Met à jour le nombre de points, la distance et l'état des boutons
function updateUI() {
    const nbPoints = points.length;
    const distance = calculerDistance(points);

    compteur.textContent = `${nbPoints} point(s)`;
    distAff.textContent = nbPoints >= 2 ? `≈ ${distance.toFixed(2)} km` : '';

    btnSupprimer.disabled = nbPoints === 0;
    btnReinit.disabled = nbPoints === 0;
    btnEnregistrer.disabled = nbPoints < 2;
    btnGpx.disabled = nbPoints < 2;
}

// Calcule la distance totale du parcours
function calculerDistance(liste) {
    let distance = 0;

    for (let i = 1; i < liste.length; i++) {
        distance += haversine(liste[i - 1], liste[i]);
    }

    return distance;
}

// Calcule la distance entre deux points GPS
function haversine(a, b) {
    const R = 6371;
    const dLat = rad(b.lat - a.lat);
    const dLng = rad(b.lng - a.lng);

    const x =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(rad(a.lat)) *
        Math.cos(rad(b.lat)) *
        Math.sin(dLng / 2) ** 2;

    return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
}

// Convertit des degrés en radians
function rad(degres) {
    return degres * Math.PI / 180;
}

// Enregistre le parcours officiel en base de données
btnEnregistrer.addEventListener('click', async () => {
    const nom = nomInput.value.trim();

    if (!nom) {
        afficherToast('Veuillez saisir un nom de parcours.', 'warning');
        return;
    }

    try {
        const response = await fetch('save_parcours.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                nom_parcours: nom,
                points: points,
                distance_km: calculerDistance(points).toFixed(2)
            })
        });

        const data = await response.json();

        if (data.success) {
            afficherToast(`✅ Parcours « ${nom} » enregistré.`, 'success');
        } else {
            afficherToast('❌ ' + (data.message || 'Erreur.'), 'danger');
        }

    } catch {
        afficherToast('❌ Erreur réseau.', 'danger');
    }
});

// Génère le fichier GPX à partir des points du parcours
btnGpx.addEventListener('click', () => {
    const nom = nomInput.value.trim() || 'parcours';

    let gpx = '<' + `?xml version="1.0" encoding="UTF-8"?>\n`;
    gpx += `<gpx version="1.1" creator="TREC App" xmlns="http://www.topografix.com/GPX/1/1">\n`;
    gpx += `  <rte>\n`;
    gpx += `    <n>${nom}</n>\n`;

    points.forEach((point, index) => {
        gpx += `    <rtept lat="${point.lat.toFixed(7)}" lon="${point.lng.toFixed(7)}"><n>Point ${index + 1}</n></rtept>\n`;
    });

    gpx += `  </rte>\n`;
    gpx += `</gpx>`;

    const lien = document.createElement('a');
    lien.href = URL.createObjectURL(new Blob([gpx], {
        type: 'application/gpx+xml'
    }));

    lien.download = nom.replace(/\s+/g, '_') + '.gpx';
    lien.click();

    afficherToast('📥 Fichier GPX généré.', 'success');
});

// Prépare les informations avant impression de la carte
function preparerImpression() {
    let titre = 'Carte du parcours';
    let infos = '';

    if (!$('panel-epreuve').classList.contains('d-none')) {
        if (points.length < 2) {
            afficherToast('Ajoutez un parcours avant impression.', 'warning');
            return;
        }

        titre = nomInput.value.trim() || 'Carte du parcours';
        infos = `Parcours officiel - ${points.length} point(s) - ${calculerDistance(points).toFixed(2)} km`;
    } else {
        if (!officielVisible && !realiseVisible) {
            afficherToast('Affichez un parcours avant impression.', 'warning');
            return;
        }

        const competition = $('select-competition').selectedOptions[0]?.textContent.trim() || '';
        const epreuve = $('select-epreuve').selectedOptions[0]?.textContent.trim() || '';
        const cavalier = $('select-cavalier').selectedOptions[0]?.textContent.trim() || '';

        titre = epreuve || 'Carte du parcours';
        infos = [competition, epreuve, cavalier].filter(Boolean).join(' - ');
    }

    document.querySelector('#titre-impression h2').textContent = titre;
    $('infos-impression').textContent = infos;

    setTimeout(() => {
        map.invalidateSize();
        window.print();
    }, 300);
}

$('btn-imprimer').addEventListener('click', preparerImpression);
$('btn-imprimer-realise').addEventListener('click', preparerImpression);

// Réinitialise une liste déroulante
function resetSelect(id, texte) {
    $(id).innerHTML = `<option value="">${texte}</option>`;
    $(id).disabled = true;
}

// Ajoute une option dans une liste déroulante
function addOption(id, value, text, parcours = '') {
    $(id).insertAdjacentHTML(
        'beforeend',
        `<option value="${value}" data-parcours="${parcours}">${text}</option>`
    );
}

// Charge les épreuves quand une compétition est sélectionnée
$('select-competition').addEventListener('change', async () => {
    const idCompetition = $('select-competition').value;

    resetSelect('select-epreuve', '-- Choisir une épreuve --');
    resetSelect('select-cavalier', '-- Choisir un cavalier --');

    clearRealise();

    if (!idCompetition) return;

    try {
        const data = await fetch(`../parcours_competition/get_epreuves.php?id_competition=${idCompetition}`)
            .then(response => response.json());

        if (!data.success) {
            afficherToast('❌ ' + data.message, 'danger');
            return;
        }

        data.epreuves.forEach(epreuve => {
            addOption(
                'select-epreuve',
                epreuve.id_epreuve,
                epreuve.nom_epreuve,
                epreuve.id_parcours
            );
        });

        $('select-epreuve').disabled = false;

    } catch {
        afficherToast('❌ Erreur chargement des épreuves.', 'danger');
    }
});

// Charge les cavaliers inscrits à l'épreuve sélectionnée
$('select-epreuve').addEventListener('change', async () => {
    const idEpreuve = $('select-epreuve').value;

    resetSelect('select-cavalier', '-- Choisir un cavalier --');
    clearRealise();

    if (!idEpreuve) return;

    try {
        const data = await fetch(`../parcours_competition/get_cavaliers_epreuve.php?id_epreuve=${idEpreuve}`)
            .then(response => response.json());

        if (!data.success) {
            afficherToast('❌ ' + data.message, 'danger');
            return;
        }

        data.cavaliers.forEach(cavalier => {
            addOption(
                'select-cavalier',
                cavalier.id_cavalier,
                `${cavalier.prenom_cavalier} ${cavalier.nom_cavalier}`
            );
        });

        $('select-cavalier').disabled = false;

    } catch {
        afficherToast('❌ Erreur chargement des cavaliers.', 'danger');
    }
});

$('select-cavalier').addEventListener('change', clearRealise);

// Calcule le total des pénalités en direct
$('mauvais-itineraire').addEventListener('input', () => {
    const nb = Math.max(0, parseInt($('mauvais-itineraire').value || '0'));
    $('total-penalites').textContent = nb * 30;
});

// Enregistre la pénalité du cavalier sélectionné
$('btn-enregistrer-penalite').addEventListener('click', async () => {
    const idEpreuve = $('select-epreuve').value;
    const idCavalier = $('select-cavalier').value;
    const mauvaisItineraire = Math.max(0, parseInt($('mauvais-itineraire').value || '0'));

    if (!idEpreuve) {
        afficherToast('Choisissez une épreuve.', 'warning');
        return;
    }

    if (!idCavalier) {
        afficherToast('Choisissez un cavalier.', 'warning');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'save_penalite');
    formData.append('id_epreuve', idEpreuve);
    formData.append('id_cavalier', idCavalier);
    formData.append('mauvais_itineraire', mauvaisItineraire);

    try {
        const data = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        }).then(response => response.json());

        if (data.success) {
            $('total-penalites').textContent = data.penalites;
            afficherToast(`✅ Pénalité enregistrée : ${data.penalites} points.`, 'success');
        } else {
            afficherToast('❌ ' + (data.message || 'Erreur.'), 'danger');
        }
    } catch {
        afficherToast('❌ Erreur réseau.', 'danger');
    }
});

// Récupère l'id du parcours officiel lié à l'épreuve sélectionnée
function getIdParcoursSelectionne() {
    const option = $('select-epreuve').selectedOptions[0];
    return option ? option.dataset.parcours : '';
}

// Affiche ou masque le parcours officiel
$('btn-toggle-officiel').addEventListener('click', async () => {
    const btn = $('btn-toggle-officiel');
    const idEpreuve = $('select-epreuve').value;
    const idParcours = getIdParcoursSelectionne();

    if (!idEpreuve) {
        afficherToast('Choisissez une épreuve.', 'warning');
        return;
    }

    if (!idParcours || idParcours === 'null') {
        afficherToast('Aucun parcours officiel lié à cette épreuve.', 'warning');
        return;
    }

    // Si le parcours est déjà affiché, on le masque
    if (officielVisible) {
        removeLayer(polylineOfficiel);
        polylineOfficiel = null;
        officielVisible = false;

        setButton(btn, '🟢 Afficher parcours officiel', 'btn-success', 'btn-outline-success', false);
        return;
    }

    try {
        const data = await fetch(`parcours_officiel.php?id_parcours=${idParcours}`)
            .then(response => response.json());

        if (!data.success) {
            afficherToast('❌ ' + data.message, 'danger');
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
        setButton(btn, '🔴 Masquer parcours officiel', 'btn-success', 'btn-outline-success', true);

        afficherToast('✅ Parcours officiel affiché.', 'success');

    } catch {
        afficherToast('❌ Erreur réseau.', 'danger');
    }
});

// Affiche ou masque le parcours réalisé du cavalier
$('btn-afficher-realise').addEventListener('click', async () => {
    const btn = $('btn-afficher-realise');
    const idEpreuve = $('select-epreuve').value;
    const idCavalier = $('select-cavalier').value;

    if (!idEpreuve) {
        afficherToast('Choisissez une épreuve.', 'warning');
        return;
    }

    if (!idCavalier) {
        afficherToast('Choisissez un cavalier.', 'warning');
        return;
    }

    // Si le parcours est déjà affiché, on le masque
    if (realiseVisible) {
        removeLayer(polylineRealise);
        polylineRealise = null;
        realiseVisible = false;

        setButton(btn, '🗺️ Afficher parcours réalisé', 'btn-primary', 'btn-outline-primary', false);
        return;
    }

    try {
        const data = await fetch(`parcours_realise.php?id_epreuve=${idEpreuve}&id_cavalier=${idCavalier}`)
            .then(response => response.json());

        if (!data.success) {
            afficherToast('❌ ' + data.message, 'danger');
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
        setButton(btn, '🔴 Masquer parcours réalisé', 'btn-primary', 'btn-outline-primary', true);

        afficherToast('✅ Parcours réalisé affiché.', 'success');

    } catch {
        afficherToast('❌ Erreur réseau.', 'danger');
    }
});
</script>