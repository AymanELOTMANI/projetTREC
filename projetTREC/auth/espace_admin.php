<?php
// 1. Configuration et Sécurité
require '../config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
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

// 3. Récupération des filtres globaux et données de base
$onglet = isset($_GET['tab']) ? $_GET['tab'] : 'accueil';

// Liste globale des compétitions pour les filtres
$competitions = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM competition ORDER BY date_competition ASC"), MYSQLI_ASSOC);

// 4. Récupération des données selon l'onglet actif

// ===== ACCUEIL - KPIs =====
if ($onglet === 'accueil') {
    $nb_users        = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM utilisateur"))['total'] ?? 0;
    $nb_competitions = count($competitions);
    $nb_inscriptions = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM inscription WHERE statut_inscription = 'confirmee'"))['total'] ?? 0;
    $nb_dossards     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT id_dossard) as total FROM inscription WHERE id_dossard IS NOT NULL"))['total'] ?? 0;
}

// ===== UTILISATEURS =====
if ($onglet === 'utilisateurs') {
    $filtre_role = $_GET['filtre_role'] ?? '';
    $query_users = "SELECT u.id_utilisateur, u.login, u.nom, u.prenom, u.mail, u.role, u.statut, c.categorie, c.adresse FROM utilisateur u LEFT JOIN cavalier c ON c.id_utilisateur = u.id_utilisateur ";    if ($filtre_role) {
        $safe_role    = mysqli_real_escape_string($conn, $filtre_role);
        $query_users .= " WHERE role = '$safe_role' ";
    }
    $query_users .= " ORDER BY login ASC";
    $utilisateurs = mysqli_fetch_all(mysqli_query($conn, $query_users), MYSQLI_ASSOC);
}

// ===== COMPÉTITIONS =====
if ($onglet === 'competitions') {
    $filtre_annee = $_GET['filtre_annee'] ?? '';
    $query_comps  = "SELECT c.*, COUNT(i.id_inscription) as nb_inscrits
                     FROM competition c
                     LEFT JOIN inscription i ON c.id_competition = i.id_competition ";
    if ($filtre_annee) {
        $safe_annee   = mysqli_real_escape_string($conn, $filtre_annee);
        $query_comps .= " WHERE YEAR(c.date_competition) = '$safe_annee' ";
    }
    $query_comps       .= " GROUP BY c.id_competition ORDER BY c.date_competition DESC";
    $competitions_detail = mysqli_fetch_all(mysqli_query($conn, $query_comps), MYSQLI_ASSOC);
}

// ===== INSCRIPTIONS =====
$filtre_competition = isset($_GET['filtre_competition']) ? intval($_GET['filtre_competition']) : 0;
if ($onglet === 'inscriptions') {
    $sql_ins = "SELECT i.id_inscription, i.date_inscription, i.statut_inscription,
                       CONCAT(cav.prenom_cavalier, ' ', cav.nom_cavalier) as cavalier,
                       c.nom_competition, c.id_competition
                FROM inscription i
                JOIN cavalier cav ON i.id_cavalier = cav.id_cavalier
                JOIN competition c ON i.id_competition = c.id_competition";
    if ($filtre_competition) {
        $stmt_ins     = prepare_exec($conn, $sql_ins . " WHERE i.id_competition = ? ORDER BY i.date_inscription DESC", "i", $filtre_competition);
        $inscriptions = mysqli_fetch_all(mysqli_stmt_get_result($stmt_ins), MYSQLI_ASSOC);
    } else {
        $inscriptions = mysqli_fetch_all(mysqli_query($conn, $sql_ins . " ORDER BY i.date_inscription DESC"), MYSQLI_ASSOC);
    }
    $nb_confirmees = count(array_filter($inscriptions, fn($r) => $r['statut_inscription'] === 'confirmee'));
    $nb_attente    = count(array_filter($inscriptions, fn($r) => $r['statut_inscription'] === 'en attente'));
    $nb_refusees   = count(array_filter($inscriptions, fn($r) => $r['statut_inscription'] === 'refusee'));
}

// ===== DOSSARDS =====
if ($onglet === 'dossards') {
    $filtre_etat    = $_GET['filtre_etat'] ?? '';
    $query_dossards = "SELECT d.id_dossard, d.numero_dossard, d.tag_rfid,
                              CONCAT(ca.prenom_cavalier, ' ', ca.nom_cavalier) as cavalier,
                              ca.categorie, i.id_cavalier
                       FROM dossard d
                       LEFT JOIN inscription i ON i.id_dossard = d.id_dossard
                                              AND i.statut_inscription IN ('confirmee','confirmée')
                       LEFT JOIN cavalier ca ON ca.id_cavalier = i.id_cavalier";
    if ($filtre_etat === 'attribue') {
        $query_dossards .= " WHERE i.id_cavalier IS NOT NULL ";
    } elseif ($filtre_etat === 'libre') {
        $query_dossards .= " WHERE i.id_cavalier IS NULL ";
    }
    $query_dossards       .= " ORDER BY d.numero_dossard ASC";
    $dossards              = mysqli_fetch_all(mysqli_query($conn, $query_dossards), MYSQLI_ASSOC);
    $nb_dossards_total     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM dossard"))['total'] ?? 0;
    $nb_dossards_attribues = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT id_dossard) as total FROM inscription WHERE id_dossard IS NOT NULL"))['total'] ?? 0;
    $nb_dossards_libres    = $nb_dossards_total - $nb_dossards_attribues;
}

// ===== CAVALIERS =====
if ($onglet === 'cavaliers') {
    $filtre_categorie = $_GET['filtre_categorie'] ?? '';
    $query_cavaliers  = "SELECT c.*,
                                COUNT(DISTINCT i.id_inscription) as nb_inscriptions,
                                d.numero_dossard
                         FROM cavalier c
                         LEFT JOIN inscription i ON c.id_cavalier = i.id_cavalier
                         LEFT JOIN dossard d ON i.id_dossard = d.id_dossard";
    if ($filtre_categorie) {
        $safe_cat         = mysqli_real_escape_string($conn, $filtre_categorie);
        $query_cavaliers .= " WHERE c.categorie = '$safe_cat' ";
    }
    $query_cavaliers .= " GROUP BY c.id_cavalier ORDER BY c.nom_cavalier ASC";
    $cavaliers        = mysqli_fetch_all(mysqli_query($conn, $query_cavaliers), MYSQLI_ASSOC);
}

// 5. Traitement des actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;

    try {

        // ===== SUPPRIMER UTILISATEUR =====
        if ($action === 'delete_user') {
            $id       = intval($_POST['id']);
            $stmt_cav = mysqli_prepare($conn, "SELECT id_cavalier FROM cavalier WHERE id_utilisateur = ?");
            mysqli_stmt_bind_param($stmt_cav, 'i', $id);
            mysqli_stmt_execute($stmt_cav);
            $cav = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_cav));

            if ($cav) {
                $id_cavalier = $cav['id_cavalier'];
                prepare_exec($conn, "DELETE pg FROM pointGPS pg JOIN session_gps sg ON pg.id_sessionGPS = sg.id_sessionGPS WHERE sg.id_cavalier = ?", "i", $id_cavalier);
                prepare_exec($conn, "DELETE FROM session_gps WHERE id_cavalier = ?", "i", $id_cavalier);
                prepare_exec($conn, "DELETE FROM affectation_boitier WHERE id_cavalier = ?", "i", $id_cavalier);
                prepare_exec($conn, "DELETE FROM resultat WHERE id_cavalier = ?", "i", $id_cavalier);
                prepare_exec($conn, "UPDATE inscription SET id_dossard = NULL WHERE id_cavalier = ?", "i", $id_cavalier);
                prepare_exec($conn, "DELETE FROM inscription WHERE id_cavalier = ?", "i", $id_cavalier);
                prepare_exec($conn, "DELETE FROM cavalier WHERE id_cavalier = ?", "i", $id_cavalier);
            }

            $stmt = prepare_exec($conn, "DELETE FROM utilisateur WHERE id_utilisateur = ?", "i", $id);
            if (mysqli_stmt_affected_rows($stmt) > 0) $_SESSION['succes'] = 'Utilisateur supprimé avec succès.';
        }

        // ===== MODIFIER UTILISATEUR =====
        if ($action === 'edit_user') {
            $id     = intval($_POST['id']);
            $login     = trim($_POST['login'] ?? '');
            $nom       = trim($_POST['nom'] ?? '');
            $prenom    = trim($_POST['prenom'] ?? '');
            $mail      = trim($_POST['mail'] ?? '');
            $adresse   = trim($_POST['adresse'] ?? '') ?: null;
            $role      = $_POST['role'] ?? 'cavalier';
            $statut    = $_POST['statut'] ?? 'actif';
            $categorie = trim($_POST['categorie'] ?? '');

            if (empty($login) || empty($mail)) {
                $_SESSION['erreur'] = 'Login et email sont obligatoires.';
            } else {
                // Vérifier que le login n'est pas déjà utilisé par un autre utilisateur
                $check = prepare_exec($conn, "SELECT id_utilisateur FROM utilisateur WHERE login = ? AND id_utilisateur != ?", "si", $login, $id);
                if (mysqli_num_rows(mysqli_stmt_get_result($check)) > 0) {
                    $_SESSION['erreur'] = "Le login « $login » est déjà utilisé par un autre utilisateur.";
                } else {
                    // Récupérer l'ancien mail avant modification pour détecter le changement
                    $stmt_ancien = prepare_exec($conn, "SELECT mail, prenom FROM utilisateur WHERE id_utilisateur = ?", "i", $id);
                    $ancien      = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_ancien));
                    $ancien_mail = $ancien['mail'] ?? '';
                    $prenom_user = $prenom ?: ($ancien['prenom'] ?? $login);
                    $mail_change = (strtolower(trim($ancien_mail)) !== strtolower(trim($mail)));

                    $stmt = prepare_exec(
                        $conn,
                        "UPDATE utilisateur SET nom = ?, prenom = ?, login = ?, mail = ?, role = ?, statut = ? WHERE id_utilisateur = ?",
                        "ssssssi",
                        $nom, $prenom, $login, $mail, $role, $statut, $id
                    );
                    if ($stmt && mysqli_stmt_affected_rows($stmt) >= 0) {
                        // Mise à jour mot de passe si renseigné
                        $new_password = $_POST['new_password'] ?? '';
                        if (!empty($new_password)) {
                            if (strlen($new_password) < 8) {
                                $_SESSION['erreur'] = 'Le mot de passe doit contenir au moins 8 caractères.';
                            } else {
                                $hash_new = password_hash($new_password, PASSWORD_BCRYPT);
                                prepare_exec($conn, "UPDATE utilisateur SET password_hash = ? WHERE id_utilisateur = ?", "si", $hash_new, $id);
                                // Si c'est un mdp temporaire TREC-, on le précise dans le message
                                if (str_starts_with($new_password, 'TREC-')) {
                                    $_SESSION['succes'] = "Mot de passe temporaire défini pour « $login ». L'utilisateur devra le changer à la connexion.";
                                }
                            }
                        }

                        // Mise à jour catégorie si cavalier
                        if ($role === 'cavalier') {
                            $cat_val = $categorie !== '' ? $categorie : null;
                            $cav_exist = prepare_exec($conn, "SELECT id_cavalier FROM cavalier WHERE id_utilisateur = ?", "i", $id);
                            if (mysqli_num_rows(mysqli_stmt_get_result($cav_exist)) > 0) {
                                prepare_exec($conn, "UPDATE cavalier SET categorie = ?, nom_cavalier = ?, prenom_cavalier = ?, adresse = ? WHERE id_utilisateur = ?", "ssssi", $cat_val, $nom, $prenom, $adresse, $id);
                            } else {
                                prepare_exec($conn, "INSERT INTO cavalier (nom_cavalier, prenom_cavalier, categorie, adresse, id_utilisateur) VALUES (?, ?, ?, ?, ?)", "ssssi", $nom, $prenom, $cat_val, $adresse, $id);
                            }
                        }

                        if ($mail_change) {
                            // Générer un nouveau mot de passe temporaire et le renvoyer
                            $mdp_temporaire = 'TREC-' . bin2hex(random_bytes(6));
                            $password_hash  = password_hash($mdp_temporaire, PASSWORD_BCRYPT);
                            prepare_exec($conn, "UPDATE utilisateur SET password_hash = ? WHERE id_utilisateur = ?", "si", $password_hash, $id);

                            $sujet   = "Vos identifiants de connexion — ProjetTREC";
                            $corps   = "Bonjour $prenom_user,\n\nVotre adresse e-mail a été mise à jour sur ProjetTREC.\n\nVoici vos identifiants de connexion :\nLogin : $login\nMot de passe temporaire : $mdp_temporaire\n\nMerci de changer votre mot de passe dès que possible.\n\nCordialement,\nL'équipe ProjetTREC";
                            $headers = "From: noreply@projettrec.fr\r\nReply-To: noreply@projettrec.fr\r\nContent-Type: text/plain; charset=UTF-8\r\n";
                            $mail_envoye = mail($mail, $sujet, $corps, $headers);

                            $_SESSION['succes'] = $mail_envoye
                                ? "Utilisateur « $login » modifié. Les identifiants ont été envoyés à $mail."
                                : "Utilisateur « $login » modifié, mais l'envoi du mail a échoué. Mot de passe temporaire : $mdp_temporaire";
                        } else {
                            $_SESSION['succes'] = "Utilisateur « $login » modifié avec succès.";
                        }
                    } else {
                        $_SESSION['erreur'] = "Erreur lors de la modification de l'utilisateur.";
                    }
                }
            }
        }

        // ===== CRÉER UTILISATEUR =====
        if ($action === 'create_user') {
            $login  = trim($_POST['login'] ?? '');
            $nom    = trim($_POST['nom'] ?? '');
            $prenom = trim($_POST['prenom'] ?? '');
            $mail   = trim($_POST['mail'] ?? '');
            $role   = $_POST['role'] ?? 'cavalier';
            $statut = $_POST['statut'] ?? 'actif';

            if (empty($login) || empty($mail)) {
                $_SESSION['erreur'] = 'Login et email sont obligatoires.';
            } else {
                $check = prepare_exec($conn, "SELECT id_utilisateur FROM utilisateur WHERE login = ?", "s", $login);
                if (mysqli_num_rows(mysqli_stmt_get_result($check)) > 0) {
                    $_SESSION['erreur'] = "Le login « $login » est déjà utilisé.";
                } else {
                    $mdp_temporaire = 'TREC-' . bin2hex(random_bytes(6));
                    $password_hash  = password_hash($mdp_temporaire, PASSWORD_BCRYPT);
                    $stmt = prepare_exec(
                        $conn,
                        "INSERT INTO utilisateur (nom, prenom, login, mail, password_hash, role, statut, totp_active, secret_totp) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL)",
                        "sssssss",
                        $nom, $prenom, $login, $mail, $password_hash, $role, $statut
                    );
                    if ($stmt && mysqli_stmt_affected_rows($stmt) > 0) {
                        $id_new = mysqli_insert_id($conn);
                        if ($role === 'cavalier') {
                            $cat_val = $categorie !== '' ? $categorie : null;
                            prepare_exec($conn,
                                "INSERT INTO cavalier (nom_cavalier, prenom_cavalier, categorie, adresse, id_utilisateur) VALUES (?, ?, ?, ?, ?)",
                                "ssssi", $nom, $prenom, $cat_val, $adresse, $id_new
                            );
                        }
                        $sujet   = "Vos identifiants de connexion — ProjetTREC";
                        $corps   = "Bonjour " . ($prenom ?: $login) . ",\n\nUn compte a été créé pour vous sur ProjetTREC.\n\nLogin : $login\nMot de passe temporaire : $mdp_temporaire\n\nMerci de changer votre mot de passe dès que possible.\n\nCordialement,\nL'équipe ProjetTREC";
                        $headers = "From: noreply@projettrec.fr\r\nReply-To: noreply@projettrec.fr\r\nContent-Type: text/plain; charset=UTF-8\r\n";
                        $mail_envoye = mail($mail, $sujet, $corps, $headers);
                        $_SESSION['succes'] = $mail_envoye
                            ? "Utilisateur « $login » créé. Les identifiants ont été envoyés à $mail."
                            : "Utilisateur « $login » créé, mais l'envoi du mail a échoué. Mot de passe temporaire : $mdp_temporaire";
                    } else {
                        $_SESSION['erreur'] = "Erreur lors de la création de l'utilisateur.";
                    }
                }
            }
        }

        // ===== SUPPRIMER COMPÉTITION =====
        if ($action === 'delete_competition') {
            $id = intval($_POST['id']);
            prepare_exec($conn, "DELETE pa FROM passage pa JOIN epreuve e ON pa.id_epreuve = e.id_epreuve WHERE e.id_competition = ?", "i", $id);
            prepare_exec($conn, "DELETE r FROM resultat r JOIN epreuve e ON r.id_epreuve = e.id_epreuve WHERE e.id_competition = ?", "i", $id);
            prepare_exec($conn, "DELETE pg FROM pointGPS pg JOIN session_gps sg ON pg.id_sessionGPS = sg.id_sessionGPS JOIN epreuve e ON sg.id_epreuve = e.id_epreuve WHERE e.id_competition = ?", "i", $id);
            prepare_exec($conn, "DELETE sg FROM session_gps sg JOIN epreuve e ON sg.id_epreuve = e.id_epreuve WHERE e.id_competition = ?", "i", $id);
            prepare_exec($conn, "DELETE FROM epreuve WHERE id_competition = ?", "i", $id);
            prepare_exec($conn, "UPDATE inscription SET id_dossard = NULL WHERE id_competition = ?", "i", $id);
            prepare_exec($conn, "DELETE FROM inscription WHERE id_competition = ?", "i", $id);
            $stmt = prepare_exec($conn, "DELETE FROM competition WHERE id_competition = ?", "i", $id);
            if (mysqli_stmt_affected_rows($stmt) > 0) $_SESSION['succes'] = 'Compétition supprimée avec succès.';
        }

        // ===== CONFIRMER INSCRIPTION =====
        if ($action === 'confirm_inscription') {
            $id   = intval($_POST['id']);
            $stmt = prepare_exec($conn, "UPDATE inscription SET statut_inscription = 'confirmee' WHERE id_inscription = ?", "i", $id);
            if (mysqli_stmt_affected_rows($stmt) > 0) $_SESSION['succes'] = 'Inscription confirmée.';
        }

        // ===== SUPPRIMER INSCRIPTION =====
        if ($action === 'delete_inscription') {
            $id   = intval($_POST['id']);
            $stmt = prepare_exec($conn, "DELETE FROM inscription WHERE id_inscription = ?", "i", $id);
            if (mysqli_stmt_affected_rows($stmt) > 0) $_SESSION['succes'] = 'Inscription supprimée.';
        }

        // ===== SUPPRIMER CAVALIER =====
        if ($action === 'delete_cavalier') {
            $id = intval($_POST['id']);
            prepare_exec($conn, "DELETE pg FROM pointGPS pg JOIN session_gps sg ON pg.id_sessionGPS = sg.id_sessionGPS WHERE sg.id_cavalier = ?", "i", $id);
            prepare_exec($conn, "DELETE FROM session_gps WHERE id_cavalier = ?", "i", $id);
            prepare_exec($conn, "DELETE FROM affectation_boitier WHERE id_cavalier = ?", "i", $id);
            prepare_exec($conn, "DELETE FROM resultat WHERE id_cavalier = ?", "i", $id);
            prepare_exec($conn, "UPDATE inscription SET id_dossard = NULL WHERE id_cavalier = ?", "i", $id);
            prepare_exec($conn, "DELETE FROM inscription WHERE id_cavalier = ?", "i", $id);
            $stmt = prepare_exec($conn, "DELETE FROM cavalier WHERE id_cavalier = ?", "i", $id);
            if (mysqli_stmt_affected_rows($stmt) > 0) $_SESSION['succes'] = 'Cavalier supprimé.';
        }

        // ===== SUPPRIMER DOSSARD =====
        if ($action === 'delete_dossard') {
            $id = intval($_POST['id']);
            prepare_exec($conn, "DELETE FROM passage WHERE id_dossard = ?", "i", $id);
            prepare_exec($conn, "UPDATE inscription SET id_dossard = NULL WHERE id_dossard = ?", "i", $id);
            $stmt = prepare_exec($conn, "DELETE FROM dossard WHERE id_dossard = ?", "i", $id);
            if (mysqli_stmt_affected_rows($stmt) > 0) $_SESSION['succes'] = 'Dossard supprimé.';
        }

        $redirect_tab = match($action) {
            'delete_user', 'create_user', 'edit_user' => 'utilisateurs',
            'delete_competition'                       => 'competitions',
            'confirm_inscription',
            'delete_inscription'                       => 'inscriptions',
            'delete_cavalier'                          => 'cavaliers',
            'delete_dossard'                           => 'dossards',
            default                                    => 'accueil',
        };
        header("Location: espace_admin.php?tab=$redirect_tab");
        exit;

    } catch (Exception $e) {
        $_SESSION['erreur'] = 'Erreur : ' . $e->getMessage();
        header("Location: espace_admin.php?tab=accueil");
        exit;
    }
}
?>
<?php include '../header.php'; ?>

<main class="flex-grow-1 bg-light">

    <section class="py-5 bg-dark text-white text-center shadow-sm">
        <div class="container">
            <h1 class="display-5 fw-bold"><i class="bi bi-shield-lock"></i> Espace Administrateur</h1>
            <p class="lead mb-0">Gestion complète du système TREC</p>
        </div>
    </section>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 main-content">

                <div class="mb-4">
                    <h4 class="fw-bold"><i class="bi bi-person-check"></i> Bonjour, <?= htmlspecialchars(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')) ?></h4>
                    <p class="text-muted mb-0">Bienvenue dans l'espace Administrateur — gestion administrative du système TREC.</p>
                </div>

                <?php if (isset($_SESSION['succes'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle-fill me-2"></i><?= $_SESSION['succes'] ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['succes']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['erreur'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $_SESSION['erreur'] ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['erreur']); ?>
                <?php endif; ?>

                <ul class="nav nav-tabs mb-4 border-0 flex-wrap" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link <?= $onglet === 'accueil' ? 'active fw-bold bg-dark text-white' : '' ?>" href="espace_admin.php?tab=accueil"><i class="bi bi-house me-1"></i>Accueil</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $onglet === 'utilisateurs' ? 'active fw-bold bg-dark text-white' : '' ?>" href="espace_admin.php?tab=utilisateurs"><i class="bi bi-people me-1"></i>Utilisateurs</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $onglet === 'competitions' ? 'active fw-bold bg-dark text-white' : '' ?>" href="espace_admin.php?tab=competitions"><i class="bi bi-trophy me-1"></i>Compétitions</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $onglet === 'inscriptions' ? 'active fw-bold bg-dark text-white' : '' ?>" href="espace_admin.php?tab=inscriptions"><i class="bi bi-clipboard-check me-1"></i>Inscriptions</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $onglet === 'cavaliers' ? 'active fw-bold bg-dark text-white' : '' ?>" href="espace_admin.php?tab=cavaliers"><i class="bi bi-person-check me-1"></i>Cavaliers</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $onglet === 'dossards' ? 'active fw-bold bg-dark text-white' : '' ?>" href="espace_admin.php?tab=dossards"><i class="bi bi-upc-scan me-1"></i>Dossards</a>
                    </li>
                </ul>

                <!-- ===== ACCUEIL ===== -->
                <?php if ($onglet === 'accueil'): ?>
                    <div class="mb-5">
                        <h4 class="mb-3 text-secondary">Tableau de bord</h4>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="card stat-card shadow-sm p-3 text-center">
                                    <i class="bi bi-people-fill text-primary fs-2"></i>
                                    <h3 class="fw-bold mt-2"><?= $nb_users ?></h3>
                                    <p class="text-muted mb-0">Utilisateurs</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card shadow-sm p-3 text-center">
                                    <i class="bi bi-trophy-fill text-warning fs-2"></i>
                                    <h3 class="fw-bold mt-2"><?= $nb_competitions ?></h3>
                                    <p class="text-muted mb-0">Compétitions</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card shadow-sm p-3 text-center">
                                    <i class="bi bi-clipboard-check-fill text-success fs-2"></i>
                                    <h3 class="fw-bold mt-2"><?= $nb_inscriptions ?></h3>
                                    <p class="text-muted mb-0">Inscriptions</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card shadow-sm p-3 text-center">
                                    <i class="bi bi-upc-scan text-danger fs-2"></i>
                                    <h3 class="fw-bold mt-2"><?= $nb_dossards ?></h3>
                                    <p class="text-muted mb-0">Dossards attribués</p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ===== UTILISATEURS ===== -->
                <?php if ($onglet === 'utilisateurs'): ?>
                    <div class="mb-5">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="text-secondary mb-0"><i class="bi bi-people-fill"></i> Gestion des Utilisateurs</h4>
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-secondary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalFilterUsers">
                                    <i class="bi bi-funnel me-1"></i>Filtrer<?php if ($filtre_role): ?><span class="badge bg-warning text-dark ms-1">actif</span><?php endif; ?>
                                </button>
                                <button class="btn btn-dark btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalCreateUser">
                                    <i class="bi bi-person-plus-fill me-1"></i>Nouvel utilisateur
                                </button>
                            </div>
                        </div>
                        <?php if ($filtre_role): ?>
                            <div class="mb-2 d-flex gap-2 align-items-center">
                                <small class="text-muted">Filtre actif :</small>
                                <span class="badge bg-info"><?= htmlspecialchars(ucfirst($filtre_role)) ?></span>
                                <a href="espace_admin.php?tab=utilisateurs" class="btn btn-outline-danger btn-sm py-0 px-2"><i class="bi bi-x"></i> Réinitialiser</a>
                            </div>
                        <?php endif; ?>
                        <div class="card card-custom shadow-sm p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">Login</th>
                                        <th>Email</th>
                                        <th>Rôle</th>
                                        <th>Statut</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($utilisateurs)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">Aucun utilisateur trouvé.</td></tr>
                                <?php else: foreach ($utilisateurs as $user): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold"><?= htmlspecialchars($user['login']) ?></td>
                                        <td><?= htmlspecialchars($user['mail'] ?? '—') ?></td>
                                        <td><span class="badge bg-info"><?= ucfirst($user['role']) ?></span></td>
                                        <?php $badge = $user['statut'] === 'actif' ? 'success' : 'secondary'; ?>
                                        <td><span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($user['statut'] ?? '—') ?></span></td>
                                        <td>
                                            <!-- Bouton Modifier -->
                                            <button type="button"
                                                class="btn btn-outline-primary btn-sm me-1"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalEditUser"
                                                data-id="<?= $user['id_utilisateur'] ?>"
                                                data-login="<?= htmlspecialchars($user['login'], ENT_QUOTES) ?>"
                                                data-nom="<?= htmlspecialchars($user['nom'] ?? '', ENT_QUOTES) ?>"
                                                data-prenom="<?= htmlspecialchars($user['prenom'] ?? '', ENT_QUOTES) ?>"
                                                data-mail="<?= htmlspecialchars($user['mail'] ?? '', ENT_QUOTES) ?>"
                                                data-role="<?= htmlspecialchars($user['role'], ENT_QUOTES) ?>"
                                                data-statut="<?= htmlspecialchars($user['statut'], ENT_QUOTES) ?>"
                                                data-categorie="<?= htmlspecialchars($user['categorie'] ?? '', ENT_QUOTES) ?>"
                                                data-adresse="<?= htmlspecialchars($user['adresse'] ?? '', ENT_QUOTES) ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <!-- Bouton Supprimer -->
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="id" value="<?= $user['id_utilisateur'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Êtes-vous sûr ?')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ===== COMPÉTITIONS ===== -->
                <?php if ($onglet === 'competitions'): ?>
                    <div class="mb-5">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="text-secondary mb-0"><i class="bi bi-trophy"></i> Gestion des Compétitions</h4>
                            <button class="btn btn-outline-secondary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalFilterCompetitions">
                                <i class="bi bi-funnel me-1"></i>Filtrer<?php if ($filtre_annee): ?><span class="badge bg-warning text-dark ms-1">actif</span><?php endif; ?>
                            </button>
                        </div>
                        <?php if ($filtre_annee): ?>
                            <div class="mb-2 d-flex gap-2 align-items-center">
                                <small class="text-muted">Filtre actif :</small>
                                <span class="badge bg-warning text-dark"><i class="bi bi-calendar me-1"></i><?= htmlspecialchars($filtre_annee) ?></span>
                                <a href="espace_admin.php?tab=competitions" class="btn btn-outline-danger btn-sm py-0 px-2"><i class="bi bi-x"></i> Réinitialiser</a>
                            </div>
                        <?php endif; ?>
                        <div class="card card-custom shadow-sm p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">Nom</th>
                                        <th>Date</th>
                                        <th>Inscrits</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($competitions_detail)): ?>
                                    <tr><td colspan="4" class="text-center text-muted py-4">Aucune compétition trouvée.</td></tr>
                                <?php else: foreach ($competitions_detail as $comp): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold"><?= htmlspecialchars($comp['nom_competition']) ?></td>
                                        <td><?= date('d/m/Y', strtotime($comp['date_competition'])) ?></td>
                                        <td><span class="badge bg-info"><?= $comp['nb_inscrits'] ?> inscrits</span></td>
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="delete_competition">
                                                <input type="hidden" name="id" value="<?= $comp['id_competition'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Êtes-vous sûr ?')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ===== INSCRIPTIONS ===== -->
                <?php if ($onglet === 'inscriptions'): ?>
                    <div class="mb-5">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="text-secondary mb-0"><i class="bi bi-clipboard-check"></i> Gestion des Inscriptions</h4>
                            <button class="btn btn-outline-secondary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalFilter">
                                <i class="bi bi-funnel me-1"></i>Filtrer<?php if ($filtre_competition): ?><span class="badge bg-warning text-dark ms-1">actif</span><?php endif; ?>
                            </button>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-auto"><span class="badge bg-success"><?= $nb_confirmees ?> Confirmées</span></div>
                            <div class="col-auto"><span class="badge bg-warning text-dark"><?= $nb_attente ?> En attente</span></div>
                            <div class="col-auto"><span class="badge bg-danger"><?= $nb_refusees ?> Refusées</span></div>
                        </div>
                        <?php if ($filtre_competition):
                            $nom_comp_actif = array_column($competitions, 'nom_competition', 'id_competition')[$filtre_competition] ?? '';
                        ?>
                            <div class="mb-2 d-flex gap-2 flex-wrap align-items-center">
                                <small class="text-muted">Filtre actif :</small>
                                <span class="badge bg-warning text-dark"><i class="bi bi-trophy me-1"></i><?= htmlspecialchars($nom_comp_actif) ?></span>
                                <a href="espace_admin.php?tab=inscriptions" class="btn btn-outline-danger btn-sm py-0 px-2"><i class="bi bi-x"></i> Réinitialiser</a>
                            </div>
                        <?php endif; ?>
                        <div class="card card-custom shadow-sm p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">Cavalier</th>
                                        <th>Compétition</th>
                                        <th>Date inscription</th>
                                        <th>Statut</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($inscriptions)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">Aucune inscription trouvée.</td></tr>
                                <?php else: foreach ($inscriptions as $ins): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold"><?= htmlspecialchars($ins['cavalier']) ?></td>
                                        <td><?= htmlspecialchars($ins['nom_competition']) ?></td>
                                        <td><?= date('d/m/Y', strtotime($ins['date_inscription'])) ?></td>
                                        <td>
                                            <?php if ($ins['statut_inscription'] === 'confirmee'): ?>
                                                <span class="badge bg-success">Confirmée</span>
                                            <?php elseif ($ins['statut_inscription'] === 'en attente'): ?>
                                                <span class="badge bg-warning text-dark">En attente</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Refusée</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($ins['statut_inscription'] !== 'confirmee'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="confirm_inscription">
                                                    <input type="hidden" name="id" value="<?= $ins['id_inscription'] ?>">
                                                    <button type="submit" class="btn btn-outline-success btn-sm"><i class="bi bi-check"></i></button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="delete_inscription">
                                                <input type="hidden" name="id" value="<?= $ins['id_inscription'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Êtes-vous sûr ?')"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ===== CAVALIERS ===== -->
                <?php if ($onglet === 'cavaliers'): ?>
                    <div class="mb-5">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="text-secondary mb-0"><i class="bi bi-person-check"></i> Gestion des Cavaliers</h4>
                            <button class="btn btn-outline-secondary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalFilterCavaliers">
                                <i class="bi bi-funnel me-1"></i>Filtrer<?php if ($filtre_categorie): ?><span class="badge bg-warning text-dark ms-1">actif</span><?php endif; ?>
                            </button>
                        </div>
                        <?php if ($filtre_categorie): ?>
                            <div class="mb-2 d-flex gap-2 align-items-center">
                                <small class="text-muted">Filtre actif :</small>
                                <span class="badge bg-info"><?= htmlspecialchars($filtre_categorie) ?></span>
                                <a href="espace_admin.php?tab=cavaliers" class="btn btn-outline-danger btn-sm py-0 px-2"><i class="bi bi-x"></i> Réinitialiser</a>
                            </div>
                        <?php endif; ?>
                        <div class="card card-custom shadow-sm p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">Nom</th>
                                        <th>Prénom</th>
                                        <th>Catégorie</th>
                                        <th>Dossard</th>
                                        <th>Inscriptions</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($cavaliers)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">Aucun cavalier trouvé.</td></tr>
                                <?php else: foreach ($cavaliers as $cav): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold"><?= htmlspecialchars($cav['nom_cavalier']) ?></td>
                                        <td><?= htmlspecialchars($cav['prenom_cavalier']) ?></td>
                                        <td><?= htmlspecialchars($cav['categorie'] ?? '—') ?></td>
                                        <td><?= !empty($cav['numero_dossard']) ? '<span class="badge bg-secondary">#' . intval($cav['numero_dossard']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                                        <td><span class="badge bg-info"><?= $cav['nb_inscriptions'] ?></span></td>
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="delete_cavalier">
                                                <input type="hidden" name="id" value="<?= $cav['id_cavalier'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Êtes-vous sûr ?')"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ===== DOSSARDS ===== -->
                <?php if ($onglet === 'dossards'): ?>
                    <div class="mb-5">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="text-secondary mb-0"><i class="bi bi-upc-scan"></i> Gestion des Dossards RFID</h4>
                            <button class="btn btn-outline-secondary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalFilterDossards">
                                <i class="bi bi-funnel me-1"></i>Filtrer<?php if ($filtre_etat): ?><span class="badge bg-warning text-dark ms-1">actif</span><?php endif; ?>
                            </button>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <div class="card stat-card shadow-sm p-3 text-center">
                                    <h3 class="fw-bold"><?= $nb_dossards_total ?></h3><p class="text-muted mb-0">Total</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card stat-card shadow-sm p-3 text-center">
                                    <h3 class="fw-bold text-success"><?= $nb_dossards_attribues ?></h3><p class="text-muted mb-0">Attribués</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card stat-card shadow-sm p-3 text-center">
                                    <h3 class="fw-bold text-warning"><?= $nb_dossards_libres ?></h3><p class="text-muted mb-0">Libres</p>
                                </div>
                            </div>
                        </div>
                        <?php if ($filtre_etat): ?>
                            <div class="mb-2 d-flex gap-2 align-items-center">
                                <small class="text-muted">Filtre actif :</small>
                                <span class="badge bg-warning text-dark"><i class="bi bi-funnel me-1"></i>État : <?= ucfirst(htmlspecialchars($filtre_etat)) ?></span>
                                <a href="espace_admin.php?tab=dossards" class="btn btn-outline-danger btn-sm py-0 px-2"><i class="bi bi-x"></i> Réinitialiser</a>
                            </div>
                        <?php endif; ?>
                        <div class="card card-custom shadow-sm p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">Numéro</th>
                                        <th>Tag RFID</th>
                                        <th>Cavalier</th>
                                        <th>Catégorie</th>
                                        <th>État</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($dossards)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">Aucun dossard trouvé.</td></tr>
                                <?php else: foreach ($dossards as $dos): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold"><?= $dos['numero_dossard'] ?></td>
                                        <td><code><?= htmlspecialchars($dos['tag_rfid']) ?></code></td>
                                        <td><?= !empty($dos['cavalier']) ? htmlspecialchars($dos['cavalier']) : '<span class="text-muted">—</span>' ?></td>
                                        <td><?= !empty($dos['categorie']) ? htmlspecialchars($dos['categorie']) : '<span class="text-muted">—</span>' ?></td>
                                        <td>
                                            <?php if (!empty($dos['id_cavalier'])): ?>
                                                <span class="badge bg-success"><i class="bi bi-check"></i> Attribué</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> Libre</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="delete_dossard">
                                                <input type="hidden" name="id" value="<?= $dos['id_dossard'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Êtes-vous sûr ?')"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</main>

<!-- ===================== MODAL : MODIFIER UN UTILISATEUR ===================== -->
<div class="modal fade" id="modalEditUser" tabindex="-1" aria-labelledby="modalEditUserLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="espace_admin.php?tab=utilisateurs">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="id" id="editUserId">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalEditUserLabel"><i class="bi bi-pencil-fill me-2"></i>Modifier l'utilisateur</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Nom</label>
                            <input type="text" class="form-control" name="nom" id="editUserNom" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Prénom</label>
                            <input type="text" class="form-control" name="prenom" id="editUserPrenom" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Login <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="login" id="editUserLogin" required maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="mail" id="editUserMail" required maxlength="150">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Rôle <span class="text-danger">*</span></label>
                            <select class="form-select" name="role" id="editUserRole" required>
                                <option value="cavalier">Cavalier</option>
                                <option value="organisateur">Organisateur</option>
                                <option value="chef_piste">Chef de Piste</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="editStatutWrap" style="display:none;">
                            <label class="form-label small fw-bold">Statut</label>
                            <select class="form-select" name="statut" id="editUserStatut">
                                <option value="actif">Actif</option>
                                <option value="en_attente">En attente de validation</option>
                            </select>
                        </div>
                        <div class="col-12" id="editAdresseWrap" style="display:none;">
                            <label class="form-label small fw-bold">Adresse</label>
                            <input type="text" class="form-control" name="adresse" id="editUserAdresse"
                                   placeholder="Ex : 12 rue des Écuries, 88000 Épinal" maxlength="255">
                        </div>
                        <div id="editCategoriWrap" class="col-12" style="display:none;">
                            <label class="form-label small fw-bold">Catégorie</label>
                            <select class="form-select" name="categorie" id="editUserCategorie">
                                <option value="">— Non renseignée —</option>
                                <option value="Club 1">Club 1</option>
                                <option value="Club 2">Club 2</option>
                                <option value="Club 3">Club 3</option>
                                <option value="Amateur 1">Amateur 1</option>
                                <option value="Amateur 2">Amateur 2</option>
                                <option value="Elite">Elite</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <hr class="my-1">
                            <label class="form-label small fw-bold">
                                Nouveau mot de passe
                                <span class="text-muted fw-normal">(laisser vide pour ne pas modifier)</span>
                            </label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="new_password" id="editUserPassword"
                                       placeholder="Nouveau mot de passe" minlength="8" autocomplete="new-password">
                                <button class="btn btn-outline-secondary" type="button"
                                        onclick="toggleMdp('editUserPassword', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-outline-warning" type="button"
                                        onclick="genererMdpTemp()"
                                        title="Générer un mot de passe temporaire TREC-">
                                    <i class="bi bi-arrow-repeat"></i>
                                </button>
                            </div>
                            <div id="mdpTempInfo" class="form-text text-warning fw-semibold" style="display:none;">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Mot de passe temporaire — l'utilisateur devra le changer à la connexion.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Annuler
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle-fill me-1"></i>Enregistrer les modifications
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===================== MODAL : CRÉER UN UTILISATEUR ===================== -->
<div class="modal fade" id="modalCreateUser" tabindex="-1" aria-labelledby="modalCreateUserLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="espace_admin.php?tab=utilisateurs">
                <input type="hidden" name="action" value="create_user">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="modalCreateUserLabel"><i class="bi bi-person-plus-fill me-2"></i>Créer un nouvel utilisateur</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Nom</label>
                            <input type="text" class="form-control" name="nom" placeholder="Ex : Dupont" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Prénom</label>
                            <input type="text" class="form-control" name="prenom" placeholder="Ex : Jean" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Login <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="login" placeholder="Ex : jean.dupont" required maxlength="100">
                            <div class="form-text">Identifiant unique de connexion.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="mail" placeholder="Ex : jean@example.com" required maxlength="150">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Rôle <span class="text-danger">*</span></label>
                            <select class="form-select" name="role" id="createRole" required onchange="toggleCreateCavalierFields(this.value)">
                                <option value="cavalier">Cavalier</option>
                                <option value="organisateur">Organisateur</option>
                                <option value="chef_piste">Chef de Piste</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="createStatutWrap" style="display:none;">
                            <label class="form-label small fw-bold">Statut</label>
                            <select class="form-select" name="statut" id="createStatut">
                                <option value="actif" selected>Actif</option>
                                <option value="en_attente">En attente de validation</option>
                            </select>
                        </div>
                        <div class="col-12" id="createAdresseWrap" style="display:none;">
                            <label class="form-label small fw-bold">Adresse</label>
                            <input type="text" class="form-control" name="adresse" id="createAdresse"
                                   placeholder="Ex : 12 rue des Écuries, 88000 Épinal" maxlength="255">
                        </div>

                        <div class="col-md-6" id="champCategorie" style="display:none;">
                            <label class="form-label small fw-bold">Catégorie (cavalier)</label>
                            <select class="form-select" name="categorie" required>
                                <option value="">— Non définie —</option>
                                <option value="Club 1">Club 1</option>
                                <option value="Club 2">Club 2</option>
                                <option value="Club 3">Club 3</option>
                                <option value="Amateur 1">Amateur 1</option>
                                <option value="Amateur 2">Amateur 2</option>
                            </select>
                        </div>
                    </div>
                    <div class="alert alert-info mt-3 mb-0 py-2 small">
                        <i class="bi bi-info-circle me-1"></i>
                        Le TOTP (double authentification) sera désactivé par défaut. L'utilisateur pourra l'activer depuis son espace.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>Annuler</button>
                    <button type="submit" class="btn btn-dark"><i class="bi bi-person-check-fill me-1"></i>Créer l'utilisateur</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===================== MODALS FILTRES ===================== -->
<div class="modal fade" id="modalFilter" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="espace_admin.php" method="GET">
                <input type="hidden" name="tab" value="inscriptions">
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
                    <a href="espace_admin.php?tab=inscriptions" class="btn btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Réinitialiser</a>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-funnel-fill me-1"></i>Appliquer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalFilterUsers" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="espace_admin.php" method="GET">
                <input type="hidden" name="tab" value="utilisateurs">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-funnel me-2"></i>Filtrer les utilisateurs</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Rôle</label>
                        <select class="form-select" name="filtre_role">
                            <option value="">— Tous —</option>
                            <option value="admin"        <?= (($_GET['filtre_role'] ?? '') === 'admin')        ? 'selected' : '' ?>>Admin</option>
                            <option value="organisateur" <?= (($_GET['filtre_role'] ?? '') === 'organisateur') ? 'selected' : '' ?>>Organisateur</option>
                            <option value="chef_piste"   <?= (($_GET['filtre_role'] ?? '') === 'chef_piste')   ? 'selected' : '' ?>>Chef de Piste</option>
                            <option value="cavalier"     <?= (($_GET['filtre_role'] ?? '') === 'cavalier')     ? 'selected' : '' ?>>Cavalier</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="espace_admin.php?tab=utilisateurs" class="btn btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Réinitialiser</a>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-funnel-fill me-1"></i>Appliquer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalFilterCompetitions" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="espace_admin.php" method="GET">
                <input type="hidden" name="tab" value="competitions">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-funnel me-2"></i>Filtrer les compétitions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Année</label>
                        <select class="form-select" name="filtre_annee">
                            <option value="">— Toutes —</option>
                            <option value="2024" <?= (($_GET['filtre_annee'] ?? '') === '2024') ? 'selected' : '' ?>>2024</option>
                            <option value="2025" <?= (($_GET['filtre_annee'] ?? '') === '2025') ? 'selected' : '' ?>>2025</option>
                            <option value="2026" <?= (($_GET['filtre_annee'] ?? '') === '2026') ? 'selected' : '' ?>>2026</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="espace_admin.php?tab=competitions" class="btn btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Réinitialiser</a>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-funnel-fill me-1"></i>Appliquer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalFilterCavaliers" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="espace_admin.php" method="GET">
                <input type="hidden" name="tab" value="cavaliers">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-funnel me-2"></i>Filtrer les cavaliers</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Catégorie</label>
                        <select class="form-select" name="filtre_categorie">
                            <option value="">— Toutes —</option>
                            <option value="Club 1"    <?= (($_GET['filtre_categorie'] ?? '') === 'Club 1')    ? 'selected' : '' ?>>Club 1</option>
                            <option value="Club 2"    <?= (($_GET['filtre_categorie'] ?? '') === 'Club 2')    ? 'selected' : '' ?>>Club 2</option>
                            <option value="Club 3"    <?= (($_GET['filtre_categorie'] ?? '') === 'Club 3')    ? 'selected' : '' ?>>Club 3</option>
                            <option value="Amateur 1" <?= (($_GET['filtre_categorie'] ?? '') === 'Amateur 1') ? 'selected' : '' ?>>Amateur 1</option>
                            <option value="Amateur 2" <?= (($_GET['filtre_categorie'] ?? '') === 'Amateur 2') ? 'selected' : '' ?>>Amateur 2</option>
                            <option value="Elite"     <?= (($_GET['filtre_categorie'] ?? '') === 'Elite')     ? 'selected' : '' ?>>Elite</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="espace_admin.php?tab=cavaliers" class="btn btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Réinitialiser</a>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-funnel-fill me-1"></i>Appliquer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalFilterDossards" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="espace_admin.php" method="GET">
                <input type="hidden" name="tab" value="dossards">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-funnel me-2"></i>Filtrer les dossards</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">État d'affectation</label>
                        <select class="form-select" name="filtre_etat">
                            <option value="">— Tous —</option>
                            <option value="attribue" <?= (($_GET['filtre_etat'] ?? '') === 'attribue') ? 'selected' : '' ?>>Attribué</option>
                            <option value="libre"    <?= (($_GET['filtre_etat'] ?? '') === 'libre')    ? 'selected' : '' ?>>Libre</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="espace_admin.php?tab=dossards" class="btn btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Réinitialiser</a>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-funnel-fill me-1"></i>Appliquer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Modal Modifier ──────────────────────────────────────────────────────
    const modalEditUser = document.getElementById('modalEditUser');
    if (modalEditUser) {
        modalEditUser.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;

            document.getElementById('editUserId').value     = btn.dataset.id;
            document.getElementById('editUserLogin').value  = btn.dataset.login;
            document.getElementById('editUserNom').value    = btn.dataset.nom;
            document.getElementById('editUserPrenom').value = btn.dataset.prenom;
            document.getElementById('editUserMail').value   = btn.dataset.mail;

            const roleSelect      = document.getElementById('editUserRole');
            const statutSelect    = document.getElementById('editUserStatut');
            const categorieSelect = document.getElementById('editUserCategorie');
            const categorieWrap   = document.getElementById('editCategoriWrap');
            const adresseWrap     = document.getElementById('editAdresseWrap');
            const statutWrap      = document.getElementById('editStatutWrap');

            for (let opt of roleSelect.options) {
                opt.selected = (opt.value === btn.dataset.role);
            }
            for (let opt of statutSelect.options) {
                opt.selected = (opt.value === btn.dataset.statut);
            }

            const isCavalier = btn.dataset.role === 'cavalier';

            categorieWrap.style.display = isCavalier ? 'block' : 'none';
            adresseWrap.style.display   = isCavalier ? 'block' : 'none';
            statutWrap.style.display    = isCavalier ? 'block' : 'none';

            for (let opt of categorieSelect.options) {
                opt.selected = (opt.value === (btn.dataset.categorie ?? ''));
            }
            document.getElementById('editUserAdresse').value = isCavalier ? (btn.dataset.adresse ?? '') : '';

            document.getElementById('mdpTempInfo').style.display = 'none';
            document.getElementById('editUserPassword').value    = '';
            document.getElementById('editUserPassword').type     = 'password';

            roleSelect.addEventListener('change', function () {
                const cav = this.value === 'cavalier';
                categorieWrap.style.display = cav ? 'block' : 'none';
                adresseWrap.style.display   = cav ? 'block' : 'none';
                statutWrap.style.display    = cav ? 'block' : 'none';
                if (!cav) {
                    document.getElementById('editUserAdresse').value = '';
                    document.getElementById('editUserStatut').value  = 'actif';
                }
            });
        });
    }

    // ── Modal Créer ─────────────────────────────────────────────────────────
    const modalCreateUser = document.getElementById('modalCreateUser');
    if (modalCreateUser) {
        modalCreateUser.addEventListener('show.bs.modal', function () {
            toggleCreateCavalierFields(document.getElementById('createRole').value);
        });
    }

});

// ── Fonctions globales ───────────────────────────────────────────────────────
function toggleCreateCavalierFields(role) {
    const adresseWrap = document.getElementById('createAdresseWrap');
    const adresse     = document.getElementById('createAdresse');
    const categorie   = document.getElementById('champCategorie');
    const statutWrap  = document.getElementById('createStatutWrap');
    const isCavalier  = role === 'cavalier';

    adresseWrap.style.display = isCavalier ? 'block' : 'none';
    categorie.style.display   = isCavalier ? 'block' : 'none';
    statutWrap.style.display  = isCavalier ? 'block' : 'none';

    if (!isCavalier) {
        adresse.value    = '';
        adresse.disabled = true;
        document.getElementById('createStatut').value = 'actif';
    } else {
        adresse.disabled = false;
    }
}

function genererMdpTemp() {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    let rand    = '';
    for (let i = 0; i < 12; i++) {
        rand += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const mdp   = 'TREC-' + rand;
    const input = document.getElementById('editUserPassword');
    input.value = mdp;
    input.type  = 'text';
    document.getElementById('mdpTempInfo').style.display = 'block';
}

function toggleMdp(id, btn) {
    const input = document.getElementById(id);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
}
</script>

</body>
</html>