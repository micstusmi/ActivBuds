<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Fitbuds</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- If you’re using Bootstrap via CDN: -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light bg-light mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="/fitbuds/index.php">Fitbuds</a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNavbar">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <!-- Put general site links here if you have them -->
        <!-- <li class="nav-item"><a class="nav-link" href="/fitbuds/some_page.php">Home</a></li> -->
      </ul>

      <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
        <?php if (isset($_SESSION['adv_user_id'])): ?>
          <li class="nav-item">
            <a class="nav-link" href="/fitbuds/advertiser_dashboard.php">Advertiser dashboard</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="/fitbuds/campaign_edit.php">Create campaign</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="/fitbuds/advertiser_logout.php">Logout</a>
          </li>
        <?php else: ?>
          <li class="nav-item">
            <a class="nav-link" href="/fitbuds/advertiser_login.php">Advertiser login</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="/fitbuds/advertiser_register.php">Advertiser register</a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<div class="container">