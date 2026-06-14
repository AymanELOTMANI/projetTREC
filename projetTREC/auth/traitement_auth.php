<?php
// Inclusion du fichier de configuration (connexion à la base de données)
require '../config.php';

// Démarrage de la session si elle n'est pas déjà active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- INSCRIPTION ----
if (isset($_POST['inscription'])) {
    // Récupération et nettoyage des données du formulaire
    $login     = trim($_POST['login']);
    $nom       = trim($_POST['nom']);
    $prenom    = trim($_POST['prenom']);
    $categorie = trim($_POST['categorie'] ?? '');
    $mail      = trim($_POST['mail']);
    $password  = $_POST['password_hash'];
    $confirm   = $_POST['password_confirm'];

    // Vérification que les deux mots de passe saisis sont identiques
    if ($password !== $confirm) {
        header('Location: signup.php?error=mdp_different');
        exit;
    }

    // Vérification que le login n'est pas déjà utilisé dans la base
    $stmt = mysqli_prepare($conn, "SELECT id_utilisateur FROM utilisateur WHERE login = ?");
    mysqli_stmt_bind_param($stmt, "s", $login);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_fetch_assoc($result)) {
        header('Location: signup.php?error=login_pris');
        exit;
    }

    // Hachage du mot de passe avant stockage en base 
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Insertion avec statut 'en_attente' — le compte doit être validé par un admin avant connexion
    $stmt = mysqli_prepare($conn,
        "INSERT INTO utilisateur (login, password_hash, role, nom, prenom, mail, statut)
         VALUES (?, ?, 'cavalier', ?, ?, ?, 'en_attente')"
    );
    mysqli_stmt_bind_param($stmt, "sssss", $login, $password_hash, $nom, $prenom, $mail);
    mysqli_stmt_execute($stmt);

    // Si une catégorie a été fournie, on l'enregistre dans la table cavalier
    if ($categorie !== '') {
        $id_user = mysqli_insert_id($conn);
        $stmt_cav = mysqli_prepare($conn,
            "INSERT INTO cavalier (nom_cavalier, prenom_cavalier, categorie, id_utilisateur)
             VALUES (?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($stmt_cav, "sssi", $nom, $prenom, $categorie, $id_user);
        mysqli_stmt_execute($stmt_cav);
    }

    header('Location: login.php?inscription=succes');
    exit;
}

// ---- CONNEXION ----
if (isset($_POST['connexion'])) {
    $login    = trim($_POST['login']);
    $password = $_POST['password_hash'];

    // Récupération de l'utilisateur correspondant au login saisi
    $stmt = mysqli_prepare($conn, "SELECT * FROM utilisateur WHERE login = ?");
    mysqli_stmt_bind_param($stmt, "s", $login);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user   = mysqli_fetch_assoc($result);

    // password_verify() compare le mot de passe saisi avec le hash stocké en base
    if ($user && password_verify($password, $user['password_hash'])) {

        $statut = $user['statut'] ?? 'actif';

        if ($statut === 'en_attente') {
            header('Location: login.php?error=compte_attente');
            exit;
        }

        if ($statut === 'refuse') {
            header('Location: login.php?error=compte_refuse');
            exit;
        }

        $_SESSION['id_utilisateur'] = $user['id_utilisateur'];
        $_SESSION['role']           = $user['role'];
        $_SESSION['temp_user_id']   = $user['id_utilisateur'];
        $_SESSION['nom']            = $user['nom'];
        $_SESSION['prenom']         = $user['prenom'];

        // Détection mot de passe temporaire → on stocke en session, on agira après le 2FA
        if (str_starts_with($password, 'TREC-')) {
            $_SESSION['mdp_temporaire'] = true;
        }

        // Définition de la page de destination selon le rôle
        if ($user['role'] === 'admin') {
            $_SESSION['2fa_redirect'] = 'espace_admin.php';
        } elseif ($user['role'] === 'chef_piste') {
            $_SESSION['2fa_redirect'] = 'espace_chef.php';
        } elseif ($user['role'] === 'organisateur') {
            $_SESSION['2fa_redirect'] = 'espace_organisateur.php';
        } else {
            $_SESSION['2fa_redirect'] = 'espace_cavalier.php';
        }

        if (!empty($user['totp_active']) && $user['totp_active'] == 1) {
            $_SESSION['2fa_valide'] = false;
            header('Location: login2fa.php');
        } else {
            // Pas de 2FA configuré → aller vers activation 2FA (obligatoire)
            $_SESSION['2fa_valide'] = false;
            header('Location: activation2fa.php');
        }
        exit;

    } else {
        header('Location: login.php?error=1');
    }
// =============================================
        // =============================================
        // Définition de la page de destination selon le rôle
        if ($user['role'] === 'admin') {
            $_SESSION['2fa_redirect'] = 'espace_admin.php';
        } elseif ($user['role'] === 'chef_piste') {
            $_SESSION['2fa_redirect'] = 'espace_chef.php';
        } elseif ($user['role'] === 'organisateur') {
            $_SESSION['2fa_redirect'] = 'espace_organisateur.php';
        } else {
            $_SESSION['2fa_redirect'] = 'espace_cavalier.php';
        }

        if (!empty($user['totp_active']) && $user['totp_active'] == 1) {
            $_SESSION['2fa_valide'] = false;
            header('Location: login2fa.php');
        } else {
            // Pas de 2FA configuré → redirection directe vers l'espace
            $_SESSION['2fa_valide'] = true;
            header('Location: ' . $_SESSION['2fa_redirect']);
        }

    } else {
        header('Location: login.php?error=1');
    }
