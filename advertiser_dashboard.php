<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['adv_user_id'], $_SESSION['adv_advertiser_id'])) {
    header('Location: advertiser_login.php'); exit;
}
require __DIR__.'/includes/campaigns.php';
$advertiser = (int)$_SESSION['adv_advertiser_id'];
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$filter = is_string($_GET['filter'] ?? null) && in_array($_GET['filter'], ['draft', 'archived'], true) ? $_GET['filter'] : '';
$problem = '';
$campaigns = [];
$stats = null;
$notice = '';
if (!isset($_SESSION['campaign_csrf']) || !is_string($_SESSION['campaign_csrf'])) {
    $_SESSION['campaign_csrf'] = bin2hex(random_bytes(32));
}
if (strlen($search) > 160) {
    http_response_code(400);
    $problem = 'Use a shorter campaign search.';
}
try {
    require __DIR__.'/includes/db.php';
    // Recheck account membership; session values alone are not current authority.
    $stmt = $conn->prepare('SELECT role FROM advertiser_users
        WHERE id = ? AND advertiser_id = ? AND is_active = 1 LIMIT 1');
    $user = (int)$_SESSION['adv_user_id'];
    $stmt->bind_param('ii', $user, $advertiser);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$member) {
        http_response_code(403);
        $problem = 'Your access to this advertiser is no longer available. Sign in again or contact the owner.';
    } elseif ($problem === '') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $csrf = $_POST['csrf_token'] ?? null;
            $action = $_POST['action'] ?? null;
            $raw_id = $_POST['campaign_id'] ?? null;
            if (($member['role'] ?? '') !== 'owner') {
                http_response_code(403); $problem = 'Only the active advertiser owner can manage campaign drafts.';
            } elseif (!is_string($csrf) || !hash_equals($_SESSION['campaign_csrf'], $csrf)) {
                http_response_code(403); $problem = 'This form has expired. Reload the dashboard before trying again.';
            } elseif (!is_string($raw_id) || !preg_match('/^[1-9][0-9]{0,9}$/D', $raw_id)
                || (int)$raw_id > 2147483647 || !is_string($action) || !in_array($action, ['duplicate', 'archive', 'restore'], true)) {
                http_response_code(400); $problem = 'Choose a valid campaign action.';
            } else {
                $source = campaign_find($conn, $advertiser, (int)$raw_id);
                if (!$source) {
                    http_response_code(404); $problem = 'This campaign is not available.';
                } elseif ($action === 'duplicate') {
                    $new_id = campaign_duplicate($conn, $advertiser, $source);
                    $_SESSION['campaign_saved'] = $new_id;
                    header('Location: campaign_edit.php?id='.$new_id, true, 303); exit;
                } else {
                    $from = $action === 'archive' ? 'draft' : 'archived';
                    $to = $action === 'archive' ? 'archived' : 'draft';
                    if (!campaign_transition($conn, $advertiser, (int)$raw_id, $from, $to)) {
                        http_response_code(409); $problem = 'This campaign changed or does not support that action. Reload before trying again.';
                    } else {
                        $_SESSION['campaign_managed'] = $action;
                        $query = http_build_query(['q'=>$search, 'filter'=>$filter]);
                        header('Location: advertiser_dashboard.php?'.$query, true, 303); exit;
                    }
                }
            }
        }
        $flash = $_SESSION['campaign_managed'] ?? null;
        if ($flash === 'archive') $notice = 'Draft archived. Its content has been kept and can be restored.';
        if ($flash === 'restore') $notice = 'Campaign restored as a draft. No advertising has been started.';
        unset($_SESSION['campaign_managed']);
        $campaigns = campaign_list($conn, $advertiser, $search, $filter);
        try {
            $stmt = $conn->prepare('SELECT SUM(impressions) AS impressions,
                SUM(clicks) AS clicks, SUM(spend) AS spend FROM ad_metrics_daily
                WHERE campaign_id IN (SELECT id FROM ad_campaigns WHERE advertiser_id = ?)');
            $stmt->bind_param('i', $advertiser);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stats = ['impressions'=>(int)($row['impressions'] ?? 0),
                'clicks'=>(int)($row['clicks'] ?? 0), 'spend'=>(float)($row['spend'] ?? 0)];
            $stmt->close();
        } catch (Throwable $metrics_error) {
            // Campaign planning remains useful when the legacy metrics table is not installed.
            $stats = null;
        }
    }
} catch (Throwable $error) {
    http_response_code(503);
    $problem = 'Campaigns are temporarily unavailable. Please try again later.';
}
$owner = isset($member) && ($member['role'] ?? '') === 'owner';
require __DIR__.'/includes/header.php';
?>
<link rel="stylesheet" href="assets/campaigns.css?v=drafts-5">
<div class="campaign-workspace">
  <aside class="campaign-sidebar" aria-label="Advertiser navigation">
    <p class="campaign-eyebrow">Advertiser workspace</p>
    <p><?= campaign_escape($_SESSION['adv_advertiser_name'] ?? 'Your business') ?></p>
    <nav><a href="advertiser_dashboard.php" aria-current="page">Dashboard &amp; campaigns</a>
      <?php if ($owner): ?><a href="campaign_edit.php">Create a campaign</a><?php endif; ?>
      <a href="#dashboard-help">Dashboard help</a></nav>
  </aside>
  <main class="campaign-main">
    <header class="campaign-title"><p class="campaign-eyebrow">Advertiser dashboard</p>
      <h1>Your campaigns</h1><p>Welcome, <?= campaign_escape($_SESSION['adv_user_name'] ?? '') ?>. Plan your promotions and return to saved drafts.</p>
      <?php if ($owner && $problem === ''): ?><a class="campaign-action" href="campaign_edit.php">+ Create a campaign</a><?php endif; ?>
    </header>
    <?php if ($problem !== ''): ?><div class="campaign-error" role="alert"><?= campaign_escape($problem) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="campaign-notice" role="status"><?= campaign_escape($notice) ?></div><?php endif; ?>
    <?php if (isset($member) && $member && $problem === ''): ?>
    <section class="campaign-stats" aria-label="All-time measured advertising totals">
      <div><span>Total impressions</span><strong><?= $stats === null ? '—' : number_format($stats['impressions']) ?></strong></div>
      <div><span>Total clicks</span><strong><?= $stats === null ? '—' : number_format($stats['clicks']) ?></strong></div>
      <div><span>Total spend</span><strong><?= $stats === null ? '—' : '$'.number_format($stats['spend'], 2) ?></strong></div>
    </section>
    <p class="campaign-muted"><?= $stats === null ? 'Advertising metrics are currently unavailable.' : 'All-time recorded totals across your campaigns; search filters do not change these totals.' ?> Saving a draft does not start advertising spend.</p>
    <section class="campaign-panel" aria-labelledby="campaign-list-heading">
      <h2 id="campaign-list-heading">Campaign library</h2>
      <form method="get" action="advertiser_dashboard.php" class="campaign-filters">
        <div><label for="campaign-search">Search campaigns</label><input type="search" id="campaign-search" name="q" value="<?= campaign_escape($search) ?>" placeholder="Campaign name" maxlength="160"></div>
        <div><label for="campaign-filter">Show</label><select name="filter" id="campaign-filter"><option value="all">All campaigns</option><option value="draft" <?= $filter === 'draft' ? 'selected' : '' ?>>Drafts only</option><option value="archived" <?= $filter === 'archived' ? 'selected' : '' ?>>Archived only</option></select></div>
        <button type="submit" class="campaign-action">Apply filters</button><a href="advertiser_dashboard.php">Clear</a>
      </form>
      <?php if (!$campaigns): ?>
        <div class="campaign-empty"><h3><?= $search !== '' || $filter !== '' ? 'No matching campaigns' : 'Your first campaign starts here' ?></h3>
        <p><?= $search !== '' || $filter !== '' ? 'Try a different name or clear the filters to see all campaigns.' : 'Create a draft with a name, planned radius and format. You can edit it before any advertising is enabled.' ?></p>
        <?php if ($owner): ?><a href="campaign_edit.php">Create a draft →</a><?php endif; ?></div>
      <?php else: ?>
        <p class="campaign-muted"><?= count($campaigns) ?> result<?= count($campaigns) === 1 ? '' : 's' ?> · newest first<?= count($campaigns) === 100 ? ' · showing the first 100; narrow your search for older campaigns' : '' ?></p>
        <div class="campaign-library">
        <?php foreach ($campaigns as $campaign): ?>
          <article class="campaign-row">
            <div><h3><?= campaign_escape($campaign['name']) ?></h3><span class="campaign-status <?= $campaign['status'] === 'draft' ? 'campaign-status-draft' : '' ?>"><?= campaign_escape($campaign['status']) ?></span></div>
            <div><span class="campaign-row-label">Planned radius</span><span><?= (int)$campaign['target_radius_km'] ?> km</span></div>
            <div><span class="campaign-row-label">Format</span><span><?= campaign_escape($campaign['media_type']) ?></span></div>
            <?php if ($owner): ?><a class="campaign-row-action" href="campaign_edit.php?id=<?= (int)$campaign['id'] ?>"><?= $campaign['status'] === 'draft' ? 'Edit draft' : 'View details' ?> →<span class="campaign-sr-only"> <?= campaign_escape($campaign['name']) ?></span></a><?php endif; ?>
            <?php if ($owner): ?><div class="campaign-row-controls">
              <form method="post" action="advertiser_dashboard.php?<?= campaign_escape(http_build_query(['q'=>$search, 'filter'=>$filter])) ?>">
                <input type="hidden" name="csrf_token" value="<?= campaign_escape($_SESSION['campaign_csrf']) ?>">
                <input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>">
                <button type="submit" name="action" value="duplicate">Duplicate to draft<span class="campaign-sr-only"> <?= campaign_escape($campaign['name']) ?></span></button>
                <?php if ($campaign['status'] === 'draft'): ?><button type="submit" name="action" value="archive">Archive draft<span class="campaign-sr-only"> <?= campaign_escape($campaign['name']) ?></span></button><?php endif; ?>
                <?php if ($campaign['status'] === 'archived'): ?><button type="submit" name="action" value="restore">Restore draft<span class="campaign-sr-only"> <?= campaign_escape($campaign['name']) ?></span></button><?php endif; ?>
              </form>
            </div><?php endif; ?>
          </article>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (!$owner): ?><p class="campaign-muted">You can view this advertiser's campaigns. Ask the owner to create or edit drafts.</p><?php endif; ?>
    </section>
    <?php endif; ?>
    <details class="campaign-panel" id="dashboard-help"><summary>Help with your campaign dashboard</summary>
      <p>Search by campaign name or show drafts only. Open an existing draft to continue editing it. Existing non-draft campaigns are shown for reference; this workspace cannot activate, pause or charge for advertising.</p>
      <p>Duplicate creates a separate draft with copied settings and text, never a running ad. Archive keeps a draft's content; choose Archived only to find and restore it. Active campaigns cannot be archived here.</p>
      <p>Statistics reflect recorded campaign metrics only. A dash means metrics are unavailable, not that your spend is zero.</p>
    </details>
    <footer class="campaign-page-footer"><a href="advertiser_dashboard.php">Advertiser dashboard</a><span>Campaign planning · Drafts only</span></footer>
  </main>
</div>
<?php require __DIR__.'/includes/footer.php'; ?>
