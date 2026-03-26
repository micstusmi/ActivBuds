<?php
session_start();
require __DIR__ . "/includes/db.php";
require __DIR__ . "/includes/header.php";

// Require advertiser login
if (!isset($_SESSION['adv_user_id'], $_SESSION['adv_advertiser_id'])) {
    header("Location: advertiser_login.php");
    exit;
}

$advertiser_id = (int)$_SESSION['adv_advertiser_id'];
$user_name     = $_SESSION['adv_user_name'] ?? '';
$adv_name      = $_SESSION['adv_advertiser_name'] ?? '';

// Fetch basic stats
$stats = [
    'impressions' => 0,
    'clicks'      => 0,
    'spend'       => 0.00
];

$stmt = $conn->prepare("
    SELECT 
        SUM(impressions) AS impressions,
        SUM(clicks) AS clicks,
        SUM(spend) AS spend
    FROM ad_metrics_daily
    WHERE campaign_id IN (
        SELECT id FROM ad_campaigns WHERE advertiser_id = ?
    )
");
$stmt->bind_param("i", $advertiser_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $stats['impressions'] = (int)($row['impressions'] ?? 0);
    $stats['clicks']      = (int)($row['clicks'] ?? 0);
    $stats['spend']       = (float)($row['spend'] ?? 0);
}
$stmt->close();

// Fetch campaigns
$campaigns = [];
$stmt = $conn->prepare("
    SELECT id, name, status, target_radius_km, media_type
    FROM ad_campaigns
    WHERE advertiser_id = ?
    ORDER BY created_at DESC
");
$stmt->bind_param("i", $advertiser_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $campaigns[] = $row;
}
$stmt->close();
?>

<h2>Fitbuds Advertiser Dashboard</h2>

<p>Welcome, <?php echo htmlspecialchars($user_name); ?> from <?php echo htmlspecialchars($adv_name); ?>.</p>

<div class="row mb-4">
  <div class="col-md-4">
    <div class="card p-3">
      <h5>Total impressions</h5>
      <p class="fs-4"><?php echo number_format($stats['impressions']); ?></p>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card p-3">
      <h5>Total clicks</h5>
      <p class="fs-4"><?php echo number_format($stats['clicks']); ?></p>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card p-3">
      <h5>Total spend</h5>
      <p class="fs-4">$<?php echo number_format($stats['spend'], 2); ?></p>
    </div>
  </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Your campaigns</h3>
  <a href="campaign_edit.php" class="btn btn-primary">Create new campaign</a>
</div>

<?php if (empty($campaigns)): ?>
    <p>You don't have any campaigns yet. Click "Create new campaign" to get started.</p>
<?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Status</th>
          <th>Radius (km)</th>
          <th>Media</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($campaigns as $c): ?>
        <tr>
          <td><?php echo htmlspecialchars($c['name']); ?></td>
          <td><?php echo htmlspecialchars($c['status']); ?></td>
          <td><?php echo (int)$c['target_radius_km']; ?></td>
          <td><?php echo htmlspecialchars($c['media_type']); ?></td>
          <td>
            <a href="campaign_edit.php?id=<?php echo (int)$c['id']; ?>" class="btn btn-sm btn-outline-secondary">View / Edit</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>

<p class="mt-4">
  <a href="advertiser_logout.php" class="btn btn-link">Log out</a>
</p>

<?php require __DIR__ . "/includes/footer.php"; ?>