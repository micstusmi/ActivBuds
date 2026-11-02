<?php
session_start();
require __DIR__ . "/includes/db.php";

$message = "";

// If already logged in as advertiser, send to dashboard
if (isset($_SESSION['adv_user_id'])) {
    header("Location: advertiser_dashboard.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adv_type      = $_POST['type'] ?? '';
    $adv_name      = trim($_POST['advertiser_name'] ?? '');
    $user_name     = trim($_POST['user_name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $password      = $_POST['password'] ?? '';
    $password2     = $_POST['password2'] ?? '';

    $loc_name      = trim($_POST['location_name'] ?? '');
    $address1      = trim($_POST['address_line1'] ?? '');
    $address2      = trim($_POST['address_line2'] ?? '');
    $city          = trim($_POST['city'] ?? '');
    $state         = trim($_POST['state'] ?? '');
    $postcode      = trim($_POST['postcode'] ?? '');
    $country       = trim($_POST['country'] ?? '');

    // Basic validation
    if (!in_array($adv_type, ['gym', 'personal_trainer', 'sporting_venue', 'other_activity'], true)) {
        $message = "Please select a valid advertiser type.";
    } elseif ($adv_name === '' || $user_name === '' || $email === '' || $password === '' || $password2 === '') {
        $message = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
    } elseif ($password !== $password2) {
        $message = "Passwords don't match.";
    } else {
        // Check if email already used as advertiser_user
        $stmt = $conn->prepare("SELECT id FROM advertiser_users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $message = "This email is already registered. Please log in instead.";
            $stmt->close();
        } else {
            $stmt->close();

            // Keep advertiser, location and owner creation all-or-nothing.
            $transaction_started = false;
            try {
                // Transactions cannot protect partial inserts on legacy non-transactional tables.
                $schema = $conn->prepare("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME IN ('advertisers', 'advertiser_locations', 'advertiser_users')");
                if (!$schema->execute()) {
                    throw new RuntimeException('Registration storage check failed.');
                }
                $tables = [];
                $result = $schema->get_result();
                while ($table = $result->fetch_assoc()) {
                    $tables[$table['TABLE_NAME']] = strtoupper((string)$table['ENGINE']);
                }
                $schema->close();
                foreach (['advertisers', 'advertiser_locations', 'advertiser_users'] as $table) {
                    if (($tables[$table] ?? '') !== 'INNODB') {
                        throw new RuntimeException('Transactional registration storage required.');
                    }
                }
                if (!$conn->begin_transaction()) {
                    throw new RuntimeException('Registration transaction failed.');
                }
                $transaction_started = true;

                $stmt = $conn->prepare("
                    INSERT INTO advertisers (name, type, is_franchise)
                    VALUES (?, ?, 0)
                ");
                $stmt->bind_param("ss", $adv_name, $adv_type);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Advertiser creation failed.');
                }
                $advertiser_id = $stmt->insert_id;
                $stmt->close();

                if ($loc_name === '') {
                    $loc_name = "Main Location";
                }
                $stmt = $conn->prepare("
                    INSERT INTO advertiser_locations (
                        advertiser_id, name, address_line1, address_line2,
                        city, state, postcode, country
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("isssssss", $advertiser_id, $loc_name,
                    $address1, $address2, $city, $state, $postcode, $country);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Location creation failed.');
                }
                $stmt->close();

                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("
                    INSERT INTO advertiser_users
                        (advertiser_id, email, password_hash, name, role, is_active)
                    VALUES (?, ?, ?, ?, 'owner', 1)
                ");
                $stmt->bind_param("isss", $advertiser_id, $email, $password_hash, $user_name);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Owner creation failed.');
                }
                $user_id = $stmt->insert_id;
                $stmt->close();
                if (!$conn->commit()) {
                    throw new RuntimeException('Registration commit failed.');
                }
                $transaction_started = false;
            } catch (Throwable $error) {
                if ($transaction_started) {
                    try { $conn->rollback(); } catch (Throwable $rollback_error) {}
                }
                $message = "Your account could not be created. Please try again.";
            }

            // Never grant a session to a partially created account.
            if ($message === "") {
                $_SESSION['adv_user_id'] = $user_id;
                $_SESSION['adv_advertiser_id'] = $advertiser_id;
                $_SESSION['adv_role'] = 'owner';
                $_SESSION['adv_user_name'] = $user_name;
                $_SESSION['adv_advertiser_name'] = $adv_name;
                header("Location: advertiser_dashboard.php");
                exit;
            }
        }
    }
}
require __DIR__ . "/includes/header.php";
?>

<h2>Advertiser Registration</h2>

<?php if ($message !== ""): ?>
    <p class="mt-3 text-danger"><?php echo htmlspecialchars($message); ?></p>
<?php endif; ?>

<form method="POST" class="w-75">

<h4>Account type</h4>
<div class="mb-3">
  <label class="form-label d-block">I am a:</label>

  <div class="form-check form-check-inline">
    <input class="form-check-input" type="radio" name="type" id="typeGym" value="gym" required>
    <label class="form-check-label" for="typeGym">Gym</label>
  </div>

  <div class="form-check form-check-inline">
    <input class="form-check-input" type="radio" name="type" id="typeSportingVenue" value="sporting_venue" required>
    <label class="form-check-label" for="typeSportingVenue">Sporting venue</label>
  </div>

  <div class="form-check form-check-inline">
    <input class="form-check-input" type="radio" name="type" id="typePT" value="personal_trainer" required>
    <label class="form-check-label" for="typePT">Personal trainer</label>
  </div>

  <div class="form-check form-check-inline">
    <input class="form-check-input" type="radio" name="type" id="typeOther" value="other_activity" required>
    <label class="form-check-label" for="typeOther">Other competitive / social activities</label>
  </div>
</div>

  <div class="mb-3">
    <label class="form-label">Business / brand name</label>
    <input type="text" name="advertiser_name" class="form-control" required>
  </div>

  <h4>Your details</h4>
  <div class="mb-3">
    <label class="form-label">Your name</label>
    <input type="text" name="user_name" class="form-control" required>
  </div>

  <div class="mb-3">
    <label class="form-label">Email (will be your login)</label>
    <input type="email" name="email" class="form-control" required>
  </div>

  <div class="mb-3">
    <label class="form-label">Password</label>
    <input type="password" name="password" class="form-control" required>
  </div>

  <div class="mb-3">
    <label class="form-label">Confirm password</label>
    <input type="password" name="password2" class="form-control" required>
  </div>

  <h4>Main location</h4>
  <div class="mb-3">
    <label class="form-label">Location name (optional)</label>
    <input type="text" name="location_name" class="form-control" placeholder="e.g. CBD Branch, Main Studio">
  </div>

  <div class="mb-3">
    <label class="form-label">Address line 1</label>
    <input type="text" name="address_line1" class="form-control">
  </div>

  <div class="mb-3">
    <label class="form-label">Address line 2</label>
    <input type="text" name="address_line2" class="form-control">
  </div>

  <div class="mb-3">
    <label class="form-label">City</label>
    <input type="text" name="city" class="form-control">
  </div>

  <div class="mb-3">
    <label class="form-label">State</label>
    <input type="text" name="state" class="form-control">
  </div>

  <div class="mb-3">
    <label class="form-label">Postcode</label>
    <input type="text" name="postcode" class="form-control">
  </div>

  <div class="mb-3">
    <label class="form-label">Country</label>
    <input type="text" name="country" class="form-control">
  </div>

  <button type="submit" class="btn btn-success">Create advertiser account</button>
</form>

<?php require __DIR__ . "/includes/footer.php"; ?>
