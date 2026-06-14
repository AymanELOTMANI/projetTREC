<?php
session_start(); // démarre la session (si elle existe encore)

// on détruit la session actuelle, ex : quand l'utilisateur se déconnecte
session_destroy(); // session finie

// on vire le cookie PHPSESSID si il est encore là
if (isset($_COOKIE[session_name()])) { 
    setcookie(session_name(), '', time() - 3600, '/'); // expire le cookie
}

// redirection vers la page d'authentification (normal quoi)
header('Location: login.php');
exit;
?>

