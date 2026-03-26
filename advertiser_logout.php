<?php
session_start();

// Remove advertiser-specific session variables
unset(
    $_SESSION['adv_user_id'],
    $_SESSION['adv_advertiser_id'],
    $_SESSION['adv_role'],
    $_SESSION['adv_user_name'],
    $_SESSION['adv_advertiser_name']
);

// If you want to log them out of *everything* (frontend users etc.), you could also:
// session_destroy();

header("Location: advertiser_login.php");
exit;