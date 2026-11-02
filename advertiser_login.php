<?php
session_start();

$message = "";
if (!isset($_SESSION['adv_login_csrf']) || !is_string($_SESSION['adv_login_csrf'])) {
    $_SESSION['adv_login_csrf'] = bin2hex(random_bytes(32));
}

// If already logged in as advertiser, go to dashboard
if (isset($_SESSION['adv_user_id'])) {
    header("Location: advertiser_dashboard.php");
    exit;
}

// Simple throttle settings
$MAX_ATTEMPTS   = 5;   // how many failed logins before blocking
$WINDOW_SECONDS = 900; // 15 minutes

if (!isset($_SESSION['adv_login_attempts'])) {
    $_SESSION['adv_login_attempts'] = [];
}

// Clean old attempts
$_SESSION['adv_login_attempts'] = array_filter(
    $_SESSION['adv_login_attempts'],
    function ($t) use ($WINDOW_SECONDS) {
        return $t > time() - $WINDOW_SECONDS;
    }
);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email    = $_POST["email"] ?? "";
    $password = $_POST["password"] ?? "";

    $csrf = $_POST['csrf_token'] ?? null;
    if (!is_string($csrf) || !hash_equals($_SESSION['adv_login_csrf'], $csrf)) {
        http_response_code(403);
        $message = "This sign-in form has expired. Please reload it and try again.";
    } elseif (!is_string($email) || !is_string($password)) {
        http_response_code(400);
        $message = "Invalid email or password.";
    } elseif (count($_SESSION['adv_login_attempts']) >= $MAX_ATTEMPTS) {
        $message = "Too many login attempts. Please try again later.";
    } else {
        require __DIR__ . "/includes/db.php";
        $stmt = $conn->prepare("
            SELECT au.id, au.password_hash, au.name, au.role, au.advertiser_id, a.name AS advertiser_name
            FROM advertiser_users au
            INNER JOIN advertisers a ON a.id = au.advertiser_id
            WHERE au.email = ? AND au.is_active = 1
            LIMIT 1
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        $genericError = "Invalid email or password.";

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user["password_hash"])) {
                // Replace the anonymous identifier before granting authenticated access.
                if (!session_regenerate_id(true)) {
                    $message = "Sign-in could not finish. Please try again.";
                } else {
                    // Reset attempts
                    $_SESSION['adv_login_attempts'] = [];
    
                    // Store session vars
                    $_SESSION["adv_user_id"]       = (int)$user["id"];
                    $_SESSION["adv_advertiser_id"] = (int)$user["advertiser_id"];
                    $_SESSION["adv_role"]          = $user["role"];
                    $_SESSION["adv_user_name"]     = $user["name"];
                    $_SESSION["adv_advertiser_name"] = $user["advertiser_name"];
    
                    header("Location: advertiser_dashboard.php");
                    exit;
                }
            } else {
                // wrong password
                $_SESSION['adv_login_attempts'][] = time();
                $message = $genericError;
            }
        } else {
            // user not found or inactive
            $_SESSION['adv_login_attempts'][] = time();
            $message = $genericError;
        }

        $stmt->close();
    }
}
require __DIR__ . "/includes/header.php";
?>

<h2>Advertiser Login</h2>

<?php if ($message !== ""): ?>
    <p class="mt-3 text-danger"><?php echo htmlspecialchars($message); ?></p>
<?php endif; ?>

<form method="POST" class="w-50">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['adv_login_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
  <div class="mb-3">
    <label class="form-label">Email</label>
    <input type="email" name="email" class="form-control" required>
  </div>

  <div class="mb-3">
    <label class="form-label">Password</label>
    <input type="password" name="password" class="form-control" required>
  </div>

  <button type="submit" class="btn btn-success">Login</button>
</form>

<p class="mt-3">
    Don't have an advertiser account? <a href="advertiser_register.php">Register here</a>.
</p>

<?php require __DIR__ . "/includes/footer.php"; ?>