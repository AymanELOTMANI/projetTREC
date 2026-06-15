<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config.php';

/* ===== PARTIE 1 : TRAITEMENT DE LA REQUÊTE EN ARRIÈRE-PLAN (AJAX) ===== */
if (isset($_GET['action']) && $_GET['action'] === 'get_cavaliers') {
    header('Content-Type: application/json');
    $id_competition = isset($_GET['id_competition']) ? (int)$_GET['id_competition'] : 0;
    $cavaliers = [];

    if ($id_competition > 0) {
        $sql = "
            SELECT 
                c.id_cavalier, 
                u.nom, 
                u.prenom 
            FROM inscription i
            INNER JOIN cavalier c ON i.id_cavalier = c.id_cavalier
            LEFT JOIN utilisateur u ON c.id_utilisateur = u.id_utilisateur
            WHERE i.id_competition = ? AND i.id_dossard IS NULL
            ORDER BY u.nom ASC
        ";
        
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("i", $id_competition);
            $stmt->execute();
            $result = $stmt->get_result();
            while($row = $result->fetch_assoc()) {
                $cavaliers[] = [
                    'id_cavalier' => $row['id_cavalier'],
                    'nom' => htmlspecialchars($row['nom'] ?? ''),
                    'prenom' => htmlspecialchars($row['prenom'] ?? '')
                ];
            }
        }
    }
    echo json_encode($cavaliers);
    exit();
}

/* ===== PARTIE 2 : TRAITEMENT DE LA SOUMISSION DU FORMULAIRE (POST) ===== */
$message = "";
$status_class = "alert-success";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["valider"])) {
    $id_competition = (int) $_POST["id_competition"];
    $id_cavalier = (int) $_POST["id_cavalier"];
    $id_dossard = (int) $_POST["id_dossard"];

    if ($id_competition > 0 && $id_cavalier > 0 && $id_dossard > 0) {
        // 1. Vérifie si le dossard est déjà pris DANS CETTE COMPÉTITION
        $verif = $conn->prepare("
            SELECT id_inscription 
            FROM inscription 
            WHERE id_competition = ? AND id_dossard = ?
            LIMIT 1
        ");
        $verif->bind_param("ii", $id_competition, $id_dossard);
        $verif->execute();
        $result_verif = $verif->get_result();

        if ($result_verif->num_rows > 0) {
            $message = "Ce dossard est déjà attribué à un autre participant dans cette compétition.";
            $status_class = "alert-danger";
        } else {
            // 2. Mise à jour de la ligne d'inscription correspondante
            $update = $conn->prepare("
                UPDATE inscription 
                SET id_dossard = ? 
                WHERE id_competition = ? AND id_cavalier = ?
            ");
            $update->bind_param("iii", $id_dossard, $id_competition, $id_cavalier);

            if ($update->execute()) {
                header("Location: associer_dossard.php?success=1");
                exit();
            } else {
                $message = "Erreur SQL lors de l'attribution du dossard.";
                $status_class = "alert-danger";
            }
        }
    } else {
        $message = "Veuillez remplir tous les champs du formulaire.";
        $status_class = "alert-warning";
    }
}

if (isset($_GET["success"])) {
    $message = "Dossard associé avec succès au cavalier pour cette compétition !";
    $status_class = "alert-success";
}

/* ===== PARTIE 3 : RÉCUPÉRATION DES DONNÉES DE LA PAGE ===== */

// 1. Liste de toutes les compétitions
$sql_competitions = "SELECT id_competition, nom_competition, lieu, date_competition FROM competition ORDER BY date_competition DESC";
$res_competitions = $conn->query($sql_competitions);

// 2. Liste de tous les dossards existants pour le formulaire
$sql_dossards = "SELECT id_dossard, numero_dossard FROM dossard ORDER BY numero_dossard ASC";
$res_dossards = $conn->query($sql_dossards);

// 3. Compteurs pour les blocs de statistiques (Carrés)
// Nombre total de cavaliers inscrits sans dossard toutes compétitions confondues
$sql_cavaliers_sans_dossard = "SELECT COUNT(*) as total FROM inscription WHERE id_dossard IS NULL";
$res_count_cavaliers = $conn->query($sql_cavaliers_sans_dossard);
$total_cavaliers_attente = $res_count_cavaliers ? $res_count_cavaliers->fetch_assoc()['total'] : 0;

// Nombre total de dossards totalement libres (non reliés à une inscription)
$sql_dossards_libres = "
    SELECT COUNT(DISTINCT d.id_dossard) as total 
    FROM dossard d 
    LEFT JOIN inscription i ON d.id_dossard = i.id_dossard 
    WHERE i.id_inscription IS NULL
";
$res_count_dossards = $conn->query($sql_dossards_libres);
$total_dossards_libres = $res_count_dossards ? $res_count_dossards->fetch_assoc()['total'] : 0;

include '../header.php';
?>

<main class="flex-grow-1 bg-light">
    <section class="py-5 bg-dark text-white border-bottom shadow-sm">
        <div class="container text-center">
            <h1 class="display-5 fw-bold">Association de Dossards</h1>
            <p class="lead mb-0">Attribuer un dossard à un cavalier pour une compétition spécifique</p>
        </div>
    </section>

    <div class="container py-5">
        <?php if ($message): ?>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="alert <?= $status_class ?> alert-dismissible fade show shadow-sm">
                        <i class="bi bi-info-circle me-2"></i>
                        <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-5 justify-content-center">

            <div class="col-md-4">
                <div class="card h-100 shadow border-0 text-center p-4">
                    <i class="bi bi-person-badge display-4 text-success"></i>
                    <h5 class="fw-bold mt-3">Cavaliers en attente</h5>
                    <p class="text-muted mb-0"><?= $total_cavaliers_attente ?> cavalier(s) globalement</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 shadow border-0 text-center p-4">
                    <i class="bi bi-card-list display-4 text-warning"></i>
                    <h5 class="fw-bold mt-3">Dossards 100% libres</h5>
                    <p class="text-muted mb-0"><?= $total_dossards_libres ?> dossard(s) disponible(s)</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 shadow border-0 text-center p-4">
                    <i class="bi bi-trophy display-4 text-primary"></i>
                    <h5 class="fw-bold mt-3">Mode d'association</h5>
                    <p class="text-muted mb-0">1 Dossard par Cavalier et par Compétition</p>
                </div>
            </div>

        </div>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-lg border-0 overflow-hidden">
                    <div class="card-header bg-success text-white py-3">
                        <h4 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Formulaire d'attribution</h4>
                    </div>
                    <div class="card-body p-4 bg-white">
                        
                        <form method="POST" id="formAssociation">
                            
                            <div class="mb-4">
                                <label for="selectCompetition" class="form-label fw-semibold">1. Choisir la Compétition</label>
                                <select name="id_competition" id="selectCompetition" class="form-select form-select-lg" required>
                                    <option value="" disabled selected>Choisir...</option>
                                    <?php while ($comp = $res_competitions->fetch_assoc()): ?>
                                        <option value="<?= $comp['id_competition'] ?>">
                                            <?= htmlspecialchars($comp['nom_competition'] . " (" . ($comp['lieu'] ?? '') . ")") ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="mb-4">
                                <label for="selectCavalier" class="form-label fw-semibold">2. Choisir le Cavalier (sans dossard)</label>
                                <select name="id_cavalier" id="selectCavalier" class="form-select form-select-lg" required disabled>
                                    <option value="" disabled selected>Sélectionnez d'abord une compétition</option>
                                </select>
                            </div>

                            <div class="mb-4">
                                <label for="selectDossard" class="form-label fw-semibold">3. Choisir le Dossard Disponible</label>
                                <select name="id_dossard" id="selectDossard" class="form-select form-select-lg" required disabled>
                                    <option value="" disabled selected>Sélectionnez d'abord un cavalier</option>
                                    <?php 
                                    $res_dossards->data_seek(0);
                                    while ($d = $res_dossards->fetch_assoc()): 
                                    ?>
                                        <option value="<?= $d['id_dossard'] ?>">
                                            Dossard n°<?= htmlspecialchars($d['numero_dossard']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="d-grid mt-5">
                                <button type="submit" name="valider" id="btnValider" class="btn btn-success btn-lg fw-bold" disabled>
                                    <i class="bi bi-check-circle me-2"></i>Valider l'association
                                </button>
                            </div>
                        </form>

                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
const selectCompetition = document.getElementById('selectCompetition');
const selectCavalier = document.getElementById('selectCavalier');
const selectDossard = document.getElementById('selectDossard');
const btnValider = document.getElementById('btnValider');

// Chargement dynamique des cavaliers
selectCompetition.addEventListener('change', function() {
    const idComp = this.value;
    
    if(idComp) {
        selectCavalier.disabled = true;
        selectCavalier.innerHTML = '<option value="">Chargement des cavaliers...</option>';
        
        fetch(`associer_dossard.php?action=get_cavaliers&id_competition=${idComp}`)
            .then(response => response.json())
            .then(data => {
                selectCavalier.innerHTML = '<option value="" disabled selected>Choisir un cavalier</option>';
                if(data.length === 0) {
                    selectCavalier.innerHTML = '<option value="">Aucun cavalier sans dossard pour cette compétition</option>';
                    selectCavalier.disabled = true;
                } else {
                    data.forEach(cavalier => {
                        selectCavalier.innerHTML += `<option value="${cavalier.id_cavalier}">${cavalier.nom} ${cavalier.prenom}</option>`;
                    });
                    selectCavalier.disabled = false;
                }
            })
            .catch(err => {
                console.error(err);
                selectCavalier.innerHTML = '<option value="">Erreur de chargement</option>';
            });
            
        selectDossard.disabled = true;
        btnValider.disabled = true;
    }
});

selectCavalier.addEventListener('change', function() {
    if(this.value !== "") {
        selectDossard.disabled = false;
        selectDossard.options[0].text = "Choisir un numéro de dossard";
    }
});

selectDossard.addEventListener('change', function() {
    if(this.value !== "") {
        btnValider.disabled = false;
    }
});
</script>

<?php include '../footer.php'; ?>