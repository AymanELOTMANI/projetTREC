<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifie que l'utilisateur est connecté
if (!isset($_SESSION['role'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Seuls ces rôles ont accès à la page
$rolesAutorises = ['chef_piste', 'organisateur', 'admin'];

if (!in_array($_SESSION['role'], $rolesAutorises)) {
    header('Location: ../index.php');
    exit;
}

include __DIR__ . '/../config.php';

$showHeroVideo = false;

// Ajout d'une affectation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_affectation'])) {
    header('Content-Type: application/json');

    $id_cavalier = (int)($_POST['cavalier'] ?? 0);
    $id_epreuve = (int)($_POST['epreuve'] ?? 0);
    $id_boitier = (int)($_POST['boitier'] ?? 0);

    // Vérifie si le cavalier est déjà affecté à cette épreuve
    $stmt = $conn->prepare("
        SELECT id_affectation
        FROM affectation_boitier
        WHERE id_cavalier = ?
        AND id_epreuve = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $id_cavalier, $id_epreuve);
    $stmt->execute();

    if ($stmt->get_result()->num_rows > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Cavalier déjà affecté à cette épreuve.'
        ]);
        exit;
    }

    // Vérifie si le boîtier est déjà affecté à cette épreuve
    $stmt = $conn->prepare("
        SELECT id_affectation
        FROM affectation_boitier
        WHERE id_boitier = ?
        AND id_epreuve = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $id_boitier, $id_epreuve);
    $stmt->execute();

    if ($stmt->get_result()->num_rows > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Boîtier déjà affecté à cette épreuve.'
        ]);
        exit;
    }

    // Enregistrement de l'affectation en base de données
    $stmt = $conn->prepare("
        INSERT INTO affectation_boitier
        (id_cavalier, id_boitier, id_epreuve, date_affectation)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->bind_param("iii", $id_cavalier, $id_boitier, $id_epreuve);
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'message' => 'Affectation ajoutée avec succès.',
        'id_affectation' => $conn->insert_id
    ]);
    exit;
}

// Suppression d'une affectation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_affectation'])) {
    $id_affectation = (int)($_POST['id_affectation'] ?? 0);

    // Supprime l'affectation sélectionnée
    $stmt = $conn->prepare("
        DELETE FROM affectation_boitier
        WHERE id_affectation = ?
    ");
    $stmt->bind_param("i", $id_affectation);
    $stmt->execute();

    header("Location: affectation_depart.php");
    exit;
}

// Liste des compétitions pour remplir la liste déroulante
$competitions = $conn->query("
    SELECT id_competition, nom_competition
    FROM competition
    ORDER BY date_competition DESC
");

// Liste des affectations déjà enregistrées
$affectations = $conn->query("
    SELECT 
        a.id_affectation,
        c.nom_cavalier,
        c.prenom_cavalier,
        b.nom_boitier,
        e.nom_epreuve,
        comp.nom_competition
    FROM affectation_boitier a
    JOIN cavalier c ON a.id_cavalier = c.id_cavalier
    JOIN boitier b ON a.id_boitier = b.id_boitier
    JOIN epreuve e ON a.id_epreuve = e.id_epreuve
    JOIN competition comp ON e.id_competition = comp.id_competition
    ORDER BY a.date_affectation DESC
");

include '../header.php';
?>

<style>
#toast-container {
    position: fixed;
    bottom: 1.5rem;
    right: 1.5rem;
    z-index: 9999;
}
</style>

<main class="flex-grow-1 bg-light">

<section class="py-5 bg-dark text-white border-bottom shadow-sm">
    <div class="container text-center">
        <h1 class="display-5 fw-bold">Affectation avant départ</h1>
        <p class="lead">Associer un cavalier à un boîtier GPS</p>
    </div>
</section>

<div class="container py-5">

    <div class="row g-4 mb-5">

        <div class="col-md-4">
            <div class="card h-100 shadow border-0 text-center p-4">
                <i class="bi bi-person-badge display-4 text-success"></i>
                <h5 class="fw-bold mt-3">Cavalier inscrit</h5>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100 shadow border-0 text-center p-4">
                <i class="bi bi-router display-4 text-warning"></i>
                <h5 class="fw-bold mt-3">Boîtier GPS</h5>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100 shadow border-0 text-center p-4">
                <i class="bi bi-flag display-4 text-danger"></i>
                <h5 class="fw-bold mt-3">Épreuve</h5>
            </div>
        </div>

    </div>

    <div class="row g-4">

        <div class="col-lg-8">
            <div class="card shadow-lg border-0">

                <div class="card-header bg-success text-white">
                    <h4><i class="bi bi-clipboard-check me-2"></i>Formulaire</h4>
                </div>

                <div class="card-body">

                    <form method="post" id="affectation-form">

                        <div class="row g-3">

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Compétition</label>
                                <select class="form-select form-select-lg" id="competition" name="competition" required>
                                    <option value="" disabled selected>Choisir une compétition</option>

                                    <?php while ($c = $competitions->fetch_assoc()): ?>
                                        <option value="<?= $c['id_competition'] ?>">
                                            <?= htmlspecialchars($c['nom_competition']) ?>
                                        </option>
                                    <?php endwhile; ?>

                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Épreuve</label>
                                <select class="form-select form-select-lg" id="epreuve" name="epreuve" required disabled>
                                    <option value="" disabled selected>Choisir une compétition</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Cavalier</label>
                                <select class="form-select form-select-lg" id="cavalier" name="cavalier" required disabled>
                                    <option value="" disabled selected>Choisir une compétition</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Boîtier</label>
                                <select class="form-select form-select-lg" id="boitier" name="boitier" required disabled>
                                    <option value="" disabled selected>Choisir une épreuve</option>
                                </select>
                            </div>

                            <div class="col-12 text-end">
                                <button class="btn btn-success btn-lg px-4" type="submit">
                                    <i class="bi bi-check-circle me-2"></i>Valider
                                </button>
                            </div>

                        </div>

                    </form>

                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow border-0">

                <div class="card-header bg-info text-white text-center">
                    <h5>Ajouter un boîtier GPS</h5>
                </div>

                <div class="card-body">
                    <form id="boitier-form" method="post">
                        <input class="form-control mb-3" name="nom_boitier" placeholder="Nom du boîtier GPS" required>
                        <button class="btn btn-info w-100" type="submit">Ajouter</button>
                    </form>
                </div>

            </div>
        </div>

    </div>

    <div class="card mt-5 shadow border-0">

        <div class="card-header">
            <h4><i class="bi bi-table me-2"></i>Affectations</h4>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0">

                <thead class="table-dark">
                    <tr>
                        <th>Cavalier</th>
                        <th>Boîtier</th>
                        <th>Compétition</th>
                        <th>Épreuve</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody id="tbody-affectations">
                    <?php while ($a = $affectations->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($a['prenom_cavalier'] . " " . $a['nom_cavalier']) ?></td>
                            <td><?= htmlspecialchars($a['nom_boitier']) ?></td>
                            <td><?= htmlspecialchars($a['nom_competition']) ?></td>
                            <td><?= htmlspecialchars($a['nom_epreuve']) ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Confirmer la suppression ?');">
                                    <input type="hidden" name="id_affectation" value="<?= $a['id_affectation'] ?>">
                                    <input type="hidden" name="delete_affectation" value="1">
                                    <button class="btn btn-sm btn-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>

            </table>
        </div>

    </div>

</div>
</main>

<div id="toast-container"></div>

<script>
// Récupération des éléments du formulaire
const competitionSelect = document.getElementById('competition');
const epreuveSelect = document.getElementById('epreuve');
const cavalierSelect = document.getElementById('cavalier');
const boitierSelect = document.getElementById('boitier');
const affectationForm = document.getElementById('affectation-form');
const boitierForm = document.getElementById('boitier-form');

// Affiche un petit message en bas à droite
function afficherToast(message, type = 'success') {
    const colors = {
        success: 'bg-success text-white',
        warning: 'bg-warning text-dark',
        danger: 'bg-danger text-white',
        info: 'bg-info text-white'
    };

    const id = 'toast-' + Date.now();

    document.getElementById('toast-container').insertAdjacentHTML('beforeend', `
        <div id="${id}" class="toast align-items-center ${colors[type]} border-0 show mb-2" role="alert">
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="document.getElementById('${id}').remove()"></button>
            </div>
        </div>
    `);

    setTimeout(() => {
        const toast = document.getElementById(id);
        if (toast) toast.remove();
    }, 4000);
}

// Protège l'affichage HTML quand on ajoute une ligne dans le tableau
function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char]));
}

// Réinitialise une liste déroulante
function resetSelect(select, texte) {
    select.innerHTML = `<option value="" disabled selected>${texte}</option>`;
    select.disabled = true;
}

// Remplit une liste déroulante avec les données reçues
function remplirSelect(select, texteDefault, data, valueKey, textCallback) {
    select.innerHTML = '';

    const optDefault = document.createElement('option');
    optDefault.text = texteDefault;
    optDefault.value = '';
    optDefault.disabled = true;
    optDefault.selected = true;
    select.appendChild(optDefault);

    data.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item[valueKey];
        opt.text = textCallback(item);
        select.appendChild(opt);
    });

    select.disabled = false;
}

// Choix compétition -> charge les épreuves
competitionSelect.addEventListener('change', function() {
    const idCompetition = this.value;

    resetSelect(epreuveSelect, 'Chargement...');
    resetSelect(cavalierSelect, 'Choisir une épreuve');
    resetSelect(boitierSelect, 'Choisir un cavalier');

    fetch('../parcours_competition/get_epreuves.php?id_competition=' + idCompetition)
        .then(res => res.json())
        .then(data => {
            if (!data.success || data.epreuves.length === 0) {
                resetSelect(epreuveSelect, 'Aucune épreuve disponible');
                return;
            }

            remplirSelect(
                epreuveSelect,
                'Choisir une épreuve',
                data.epreuves,
                'id_epreuve',
                e => e.nom_epreuve
            );
        })
        .catch(() => afficherToast('❌ Erreur chargement des épreuves.', 'danger'));
});

// Choix épreuve -> charge les cavaliers disponibles
epreuveSelect.addEventListener('change', function() {
    const idEpreuve = this.value;

    resetSelect(cavalierSelect, 'Chargement...');
    resetSelect(boitierSelect, 'Choisir un cavalier');

    fetch('../parcours_competition/get_cavaliers_epreuve.php?id_epreuve=' + idEpreuve)
        .then(res => res.json())
        .then(data => {
            if (!data.success || data.cavaliers.length === 0) {
                resetSelect(cavalierSelect, 'Aucun cavalier disponible');
                return;
            }

            remplirSelect(
                cavalierSelect,
                'Choisir un cavalier',
                data.cavaliers,
                'id_cavalier',
                c => c.prenom_cavalier + ' ' + c.nom_cavalier
            );
        })
        .catch(() => afficherToast('❌ Erreur chargement des cavaliers.', 'danger'));
});

// Choix cavalier -> charge les boîtiers disponibles
cavalierSelect.addEventListener('change', function() {
    const idEpreuve = epreuveSelect.value;
    const idCavalier = cavalierSelect.value;

    resetSelect(boitierSelect, 'Chargement...');

    if (!idEpreuve || !idCavalier) {
        resetSelect(boitierSelect, 'Choisir un cavalier');
        return;
    }

    fetch('get_boitiers.php?id_epreuve=' + idEpreuve)
        .then(res => res.json())
        .then(data => {
            if (!data || data.length === 0) {
                resetSelect(boitierSelect, 'Aucun boîtier disponible');
                return;
            }

            remplirSelect(
                boitierSelect,
                'Choisir un boîtier',
                data,
                'id_boitier',
                b => b.nom_boitier
            );
        })
        .catch(() => afficherToast('❌ Erreur chargement des boîtiers.', 'danger'));
});

// Ajoute l'affectation sans recharger la page
affectationForm.addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(affectationForm);

    const nomCompetition = competitionSelect.selectedOptions[0]?.textContent.trim() || '';
    const nomEpreuve = epreuveSelect.selectedOptions[0]?.textContent.trim() || '';
    const nomCavalier = cavalierSelect.selectedOptions[0]?.textContent.trim() || '';
    const nomBoitier = boitierSelect.selectedOptions[0]?.textContent.trim() || '';

    fetch('affectation_depart.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            afficherToast('❌ ' + data.message, 'danger');
            return;
        }

        afficherToast('✅ ' + data.message, 'success');

        // Ajoute directement la nouvelle affectation dans le tableau
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${escapeHtml(nomCavalier)}</td>
            <td>${escapeHtml(nomBoitier)}</td>
            <td>${escapeHtml(nomCompetition)}</td>
            <td>${escapeHtml(nomEpreuve)}</td>
            <td>
                <form method="post" onsubmit="return confirm('Confirmer la suppression ?');">
                    <input type="hidden" name="id_affectation" value="${data.id_affectation}">
                    <input type="hidden" name="delete_affectation" value="1">
                    <button class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                </form>
            </td>
        `;

        document.getElementById('tbody-affectations').prepend(tr);

        // Réinitialise les champs après l'ajout
        resetSelect(boitierSelect, 'Choisir un cavalier');
        cavalierSelect.value = '';
    })
    .catch(() => afficherToast('❌ Erreur réseau.', 'danger'));
});

// Ajoute un boîtier GPS sans recharger la page
boitierForm.addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(boitierForm);

    fetch('ajouter_boitier.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            afficherToast('❌ ' + data.message, 'danger');
            return;
        }

        afficherToast('✅ Boîtier GPS ajouté. ID enregistré : ' + data.id_boitier, 'success');
        boitierForm.reset();
    })
    .catch(() => afficherToast('❌ Erreur lors de l’ajout du boîtier GPS.', 'danger'));
});
</script>

<?php include '../footer.php'; ?>