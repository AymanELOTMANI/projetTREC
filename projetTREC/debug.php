<?php
require 'config.php';
require_once __DIR__ . '/vendor/autoload.php';

use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;

// Change juste l'ID utilisateur ici
$id_utilisateur = 1;

$stmt = mysqli_prepare($conn, "SELECT login, secret_totp FROM utilisateur WHERE id_utilisateur = ?");
mysqli_stmt_bind_param($stmt, "i", $id_utilisateur);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$tfa = new TwoFactorAuth(new EndroidQrCodeProvider(), 'TREC88000');
$qrCodeUrl = $tfa->getQRCodeImageAsDataUri($user['login'], $user['secret_totp']);
?>
<!DOCTYPE html>
<html>
<body style="text-align:center;padding:40px;">
    <h2>QR Code - <?php echo htmlspecialchars($user['login']); ?></h2>
    <img src="<?php echo $qrCodeUrl; ?>">
    <p>Clé manuelle : <code><?php echo $user['secret_totp']; ?></code></p>
</body>
</html>