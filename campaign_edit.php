<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['adv_user_id'], $_SESSION['adv_advertiser_id'])) {
    header('Location: advertiser_login.php'); exit;
}
require __DIR__.'/includes/campaigns.php';
$advertiser = (int)$_SESSION['adv_advertiser_id'];
$user = (int)$_SESSION['adv_user_id'];
$id = 0;
$problem = '';
$errors = [];
$campaign = null;
$available = false;
$saved = false;
$values = ['name'=>'', 'target_radius_km'=>'10', 'media_type'=>'image',
    'headline'=>'', 'message'=>'', 'destination_url'=>''];
if (!isset($_SESSION['campaign_csrf']) || !is_string($_SESSION['campaign_csrf'])) {
    $_SESSION['campaign_csrf'] = bin2hex(random_bytes(32));
}
$raw_id = $_GET['id'] ?? null;
if ($raw_id !== null && (!is_string($raw_id)
    || !preg_match('/^[1-9][0-9]{0,9}$/D', $raw_id) || (int)$raw_id > 2147483647)) {
    http_response_code(404); $problem = 'This campaign is not available.';
} else {
    $id = $raw_id === null ? 0 : (int)$raw_id;
    try {
        require __DIR__.'/includes/db.php';
        if (!campaign_owner($conn, $user, $advertiser)) {
            http_response_code(403); $problem = 'Only an active owner can manage campaigns for this advertiser.';
        } else {
            $campaign = $id ? campaign_find($conn, $advertiser, $id) : null;
            if ($id && !$campaign) {
                http_response_code(404); $problem = 'This campaign is not available.';
            } else {
                $available = true;
                if ($campaign) $values = $campaign;
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    [$values, $errors] = campaign_input($_POST);
                    $csrf = $_POST['csrf_token'] ?? null;
                    if (!is_string($csrf) || !hash_equals($_SESSION['campaign_csrf'], $csrf)) {
                        http_response_code(403); $problem = 'This form has expired. Reload the page before saving.';
                    } elseif ($campaign && $campaign['status'] !== 'draft') {
                        http_response_code(409); $problem = 'Only drafts can be edited here. This campaign has not been changed.';
                    } elseif ($campaign && (!is_string($_POST['revision'] ?? null)
                        || !hash_equals(campaign_revision($campaign), $_POST['revision']))) {
                        http_response_code(409); $problem = 'This draft changed in another tab. Reload it before saving.';
                    } elseif ($errors) {
                        http_response_code(422);
                    } else {
                        if ($campaign) {
                            if (!campaign_update($conn, $advertiser, $campaign, $values)) {
                                http_response_code(409); $problem = 'This draft changed while saving. Reload it before trying again.';
                            }
                        } else {
                            $id = campaign_create($conn, $advertiser, $values);
                        }
                        if ($problem === '') {
                            $_SESSION['campaign_saved'] = $id;
                            header('Location: campaign_edit.php?id='.$id, true, 303); exit;
                        }
                    }
                }
                $saved = ($_SESSION['campaign_saved'] ?? null) === $id && $id > 0;
                unset($_SESSION['campaign_saved']);
            }
        }
    } catch (Throwable $error) {
        http_response_code(503); $available = false;
        $problem = 'Campaigns are temporarily unavailable. Your changes have not been confirmed. Return to your dashboard or try again later.';
    }
}
$editable = $available && (!$campaign || $campaign['status'] === 'draft');
require __DIR__.'/includes/header.php';
?>
<link rel="stylesheet" href="assets/campaigns.css?v=drafts-3">
<div class="campaign-workspace">
  <aside class="campaign-sidebar" aria-label="Advertiser navigation">
    <p class="campaign-eyebrow">Advertiser workspace</p>
    <p><?= campaign_escape($_SESSION['adv_advertiser_name'] ?? 'Your business') ?></p>
    <nav><a href="advertiser_dashboard.php">Dashboard &amp; campaigns</a>
      <a href="campaign_edit.php" <?= !$id ? 'aria-current="page"' : '' ?>>Create a campaign</a>
      <a href="#campaign-help">Campaign help</a></nav>
  </aside>
  <main class="campaign-main" id="campaign-main">
    <header class="campaign-title"><p class="campaign-eyebrow">Your campaigns</p>
      <h1><?= $id ? 'Campaign details' : 'Create a campaign' ?></h1>
      <p>Plan your next promotion. Save a draft now and come back to it later.</p>
      <a href="advertiser_dashboard.php">← Back to dashboard</a>
    </header>
    <?php if ($saved): ?><div class="campaign-notice" role="status">Draft saved. It is not running and no advertising spend has been started.</div><?php endif; ?>
    <?php if ($problem !== ''): ?><div class="campaign-error" role="alert"><?= campaign_escape($problem) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="campaign-error" role="alert">Check the highlighted fields below.</div><?php endif; ?>
    <?php if ($available): ?>
    <div class="campaign-columns">
      <section class="campaign-panel" aria-labelledby="details-heading">
        <h2 id="details-heading">Campaign details</h2>
        <p class="campaign-muted">Status: <strong><?= campaign_escape($campaign['status'] ?? 'draft') ?></strong></p>
        <form method="post" action="campaign_edit.php<?= $id ? '?id='.$id : '' ?>" data-campaign-form>
          <input type="hidden" name="csrf_token" value="<?= campaign_escape($_SESSION['campaign_csrf']) ?>">
          <?php if ($campaign): ?><input type="hidden" name="revision" value="<?= campaign_revision($campaign) ?>"><?php endif; ?>
          <fieldset <?= !$editable ? 'disabled' : '' ?>>
            <legend class="campaign-sr-only">Draft settings</legend>
            <label for="campaign-name">Campaign name</label>
            <input id="campaign-name" name="name" type="text" maxlength="160" required value="<?= campaign_escape($values['name']) ?>" placeholder="e.g. Summer training sessions" <?= isset($errors['name']) ? 'aria-invalid="true" aria-describedby="name-error"' : '' ?>>
            <?php if (isset($errors['name'])): ?><p id="name-error" class="campaign-field-error"><?= campaign_escape($errors['name']) ?></p><?php endif; ?>
            <label for="campaign-radius">Planned targeting radius (km)</label>
            <input id="campaign-radius" name="target_radius_km" type="number" min="1" max="250" step="1" required value="<?= campaign_escape($values['target_radius_km']) ?>" aria-describedby="radius-help<?= isset($errors['target_radius_km']) ? ' radius-error' : '' ?>" <?= isset($errors['target_radius_km']) ? 'aria-invalid="true"' : '' ?>>
            <p id="radius-help" class="campaign-muted">Draft preference only. A radius does not choose a venue or start location-based delivery.</p>
            <?php if (isset($errors['target_radius_km'])): ?><p id="radius-error" class="campaign-field-error"><?= campaign_escape($errors['target_radius_km']) ?></p><?php endif; ?>
            <label for="campaign-media">Planned format</label>
            <select id="campaign-media" name="media_type" <?= isset($errors['media_type']) ? 'aria-invalid="true" aria-describedby="media-error"' : '' ?>>
              <?php foreach (['image'=>'Image', 'carousel'=>'Carousel', 'video'=>'Video'] as $key=>$label): ?>
              <option value="<?= $key ?>" <?= $values['media_type'] === $key ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['media_type'])): ?><p id="media-error" class="campaign-field-error"><?= campaign_escape($errors['media_type']) ?></p><?php endif; ?>
            <p class="campaign-muted">Choose a format for your plan. Media upload and ad placement are not enabled in this draft editor.</p>
            <h2 class="campaign-creative-heading">Write your promotion</h2>
            <p class="campaign-muted">Optional draft content. Nothing is published when you save.</p>
            <label for="campaign-headline">Headline</label>
            <input id="campaign-headline" name="headline" maxlength="90" value="<?= campaign_escape($values['headline']) ?>" placeholder="e.g. Find your next training partner" <?= isset($errors['headline']) ? 'aria-invalid="true" aria-describedby="headline-error"' : '' ?>>
            <?php if (isset($errors['headline'])): ?><p id="headline-error" class="campaign-field-error"><?= campaign_escape($errors['headline']) ?></p><?php endif; ?>
            <label for="campaign-message">Message</label>
            <textarea id="campaign-message" name="message" rows="4" maxlength="500" placeholder="Tell people what makes your activity worth trying." <?= isset($errors['message']) ? 'aria-invalid="true" aria-describedby="message-error"' : '' ?>><?= campaign_escape($values['message']) ?></textarea>
            <?php if (isset($errors['message'])): ?><p id="message-error" class="campaign-field-error"><?= campaign_escape($errors['message']) ?></p><?php endif; ?>
            <label for="campaign-destination">Destination website</label>
            <input type="url" id="campaign-destination" name="destination_url" maxlength="2048" value="<?= campaign_escape($values['destination_url']) ?>" placeholder="https://your-website.example" aria-describedby="destination-help<?= isset($errors['destination_url']) ? ' destination-error' : '' ?>" <?= isset($errors['destination_url']) ? 'aria-invalid="true"' : '' ?>>
            <p id="destination-help" class="campaign-muted">An HTTPS link for your plan. We do not visit it or publish it from this page.</p>
            <?php if (isset($errors['destination_url'])): ?><p id="destination-error" class="campaign-field-error"><?= campaign_escape($errors['destination_url']) ?></p><?php endif; ?>
            <?php if ($editable): ?><button type="submit" class="campaign-primary">Save draft</button><?php endif; ?>
          </fieldset>
        </form>
        <?php if (!$editable): ?><p>This campaign is read-only here because it is no longer a draft.</p><?php endif; ?>
      </section>
      <aside class="campaign-panel campaign-preview" aria-labelledby="preview-heading">
        <p class="campaign-eyebrow">Draft summary</p><h2 id="preview-heading" data-preview-name><?= campaign_escape($values['name'] ?: 'Your next promotion') ?></h2>
        <div class="campaign-ad-preview" aria-label="Draft promotion text preview">
          <div class="campaign-media-placeholder">Creative space<span>Media upload comes later</span></div>
          <div class="campaign-ad-copy"><span class="campaign-eyebrow">Promotion draft</span>
            <h3 data-preview-headline><?= campaign_escape($values['headline'] ?: 'Your headline goes here') ?></h3>
            <p data-preview-message><?= campaign_escape($values['message'] ?: 'Your promotion message will appear here.') ?></p>
            <span class="campaign-destination" data-preview-destination><?= campaign_escape($values['destination_url'] ?: 'Your destination website') ?></span>
          </div>
        </div>
        <dl><dt>Format</dt><dd data-preview-media><?= campaign_escape($values['media_type']) ?></dd>
          <dt>Planned radius</dt><dd><span data-preview-radius><?= campaign_escape($values['target_radius_km']) ?></span> km</dd>
          <dt>Delivery</dt><dd>Not running</dd></dl>
        <p>This is a draft text layout, not a live advertisement, final placement or price quote.</p>
      </aside>
    </div>
    <?php endif; ?>
    <details id="campaign-help" class="campaign-panel"><summary>Help with campaign drafts</summary>
      <p>Save a name, planned radius and format, then reopen the draft from “Your campaigns” on the dashboard. Only the advertiser’s current active owner can save changes.</p>
      <p>Saving does not publish an ad, reserve placement or charge you. Pricing, venue selection, uploads and delivery will be separate steps when available.</p>
      <p>If another tab changes the draft, reload before saving. If a save is interrupted, check your dashboard before creating another draft.</p>
    </details>
    <footer class="campaign-page-footer"><a href="advertiser_dashboard.php">Advertiser dashboard</a><span>Campaign planning · Drafts only</span></footer>
  </main>
</div>
<script src="assets/campaigns.js?v=drafts-2" defer></script>
<?php require __DIR__.'/includes/footer.php'; ?>
