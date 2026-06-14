<?php
require_once '../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_GET['ajax_check'])) {
    $result = mysqli_query($conn, "SELECT uid_hex FROM dernier_scan WHERE id = 1");
    $scan = mysqli_fetch_assoc($result);
    header('Content-Type: application/json');
    echo json_encode(['uid' => ($scan['uid_hex'] ?? "")]);
    exit;
}

$message = "";
$status_class = "alert-success";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['valider'])) {
    $numero = intval($_POST["numero"]);
    $uid = mysqli_real_escape_string($conn, $_POST["uid_hex"]);

    if (!empty($numero) && !empty($uid)) {
        mysqli_begin_transaction($conn);
        try {
            $temp_uid = "TEMP_" . time();
            mysqli_query($conn, "UPDATE dossard SET tag_rfid = '$temp_uid' WHERE tag_rfid = '$uid'");

            $sql = "INSERT INTO dossard (numero_dossard, tag_rfid) 
                    VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE tag_rfid = VALUES(tag_rfid)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "is", $numero, $uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            mysqli_query($conn, "UPDATE dernier_scan SET uid_hex = NULL WHERE id = 1");
            mysqli_commit($conn);

            header("Location: associer.php?success=1&num=" . $numero);
            exit();

        } catch (mysqli_sql_exception $e) {
            mysqli_rollback($conn);
            $message = "Erreur : " . $e->getMessage();
            $status_class = "alert-danger";
        }
    }
}

if (isset($_GET['success'])) {
    $message = "Association réussie pour le dossard n°" . htmlspecialchars($_GET['num']);
}

require '../header.php';
?>

<main class="flex-grow-1 bg-light">

    <section class="py-5 bg-dark text-white text-center shadow-sm">
        <div class="container">
            <h1 class="display-5 fw-bold">Association RFID</h1>
            <p class="lead mb-0">Associer un badge RFID à un numéro de dossard</p>
        </div>
    </section>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">

                <?php if ($message): ?>
                    <div class="alert <?= $status_class ?> alert-dismissible fade show shadow-sm">
                        <i class="bi bi-info-circle me-2"></i><?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="card shadow-lg border-0 overflow-hidden">
                    <div class="card-header bg-success text-white py-3">
                        <h4 class="mb-0">
                            <i class="bi bi-broadcast me-2"></i>Lecture du badge
                        </h4>
                    </div>

                    <div class="card-body p-4">
                        <form method="POST">
                            <div class="mb-4">
                                <label class="form-label fw-semibold">ID du badge</label>
                                <input type="text" class="form-control form-control-lg bg-dark text-warning border-0 fw-bold"
                                       name="uid_hex" id="uid_hex" readonly placeholder="Posez un badge...">
                                <div class="form-text">Le champ se remplit automatiquement après lecture RFID.</div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold">Numéro de dossard</label>
                                <input type="number" class="form-control form-control-lg"
                                       name="numero" id="numero" required autofocus placeholder="Ex : 1411">
                            </div>

                            <button type="submit" name="valider" id="btn-valider"
                                    class="btn btn-warning btn-lg w-100 fw-bold disabled">
                                <i class="bi bi-check-circle me-2"></i>Valider l'association
                            </button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>
</main>

<script>
function checkScan() {
    fetch(window.location.pathname + '?ajax_check=1')
        .then(response => response.json())
        .then(data => {
            const inputUid = document.getElementById('uid_hex');
            const btn = document.getElementById('btn-valider');

            if (data.uid && data.uid.trim() !== "") {
                inputUid.value = data.uid;
                btn.classList.remove('disabled');
            }
        });
}
setInterval(checkScan, 1500);
</script>

<?php include '../footer.php'; ?>