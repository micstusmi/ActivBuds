<?php
declare(strict_types=1);

function campaign_escape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function campaign_revision(array $campaign): string {
    return hash('sha256', json_encode([(int)$campaign['id'], (string)$campaign['name'],
        (string)$campaign['status'], (int)$campaign['target_radius_km'],
        (string)$campaign['media_type']], JSON_THROW_ON_ERROR));
}

function campaign_input(array $input): array {
    $errors = [];
    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    $radius = is_string($input['target_radius_km'] ?? null) ? $input['target_radius_km'] : '';
    $media = is_string($input['media_type'] ?? null) ? $input['media_type'] : '';
    if (preg_match('/^.{1,160}$/usD', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
        $errors['name'] = 'Enter a campaign name of up to 160 characters without control characters.';
    }
    if (!preg_match('/^[1-9][0-9]{0,2}$/D', $radius) || (int)$radius > 250) {
        $errors['target_radius_km'] = 'Choose a whole-number radius from 1 to 250 km.';
    }
    if (!in_array($media, ['image', 'carousel', 'video'], true)) {
        $errors['media_type'] = 'Choose an image, carousel or video draft.';
    }
    return [['name'=>$name, 'target_radius_km'=>$radius, 'media_type'=>$media], $errors];
}

function campaign_owner($db, int $user, int $advertiser): bool {
    $stmt = $db->prepare("SELECT id FROM advertiser_users
        WHERE id = ? AND advertiser_id = ? AND is_active = 1 AND role = 'owner' LIMIT 1");
    $stmt->bind_param('ii', $user, $advertiser);
    $stmt->execute();
    $allowed = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $allowed;
}

function campaign_find($db, int $advertiser, int $id): ?array {
    $stmt = $db->prepare('SELECT id, name, status, target_radius_km, media_type
        FROM ad_campaigns WHERE id = ? AND advertiser_id = ? LIMIT 1');
    $stmt->bind_param('ii', $id, $advertiser);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function campaign_create($db, int $advertiser, array $values): int {
    $stmt = $db->prepare("INSERT INTO ad_campaigns
        (advertiser_id, name, status, target_radius_km, media_type, created_at)
        VALUES (?, ?, 'draft', ?, ?, CURRENT_TIMESTAMP)");
    $radius = (int)$values['target_radius_km'];
    $stmt->bind_param('isis', $advertiser, $values['name'], $radius, $values['media_type']);
    if (!$stmt->execute()) throw new RuntimeException('Campaign was not saved.');
    $id = (int)$stmt->insert_id;
    $stmt->close();
    if ($id < 1) throw new RuntimeException('Campaign identifier unavailable.');
    return $id;
}

function campaign_update($db, int $advertiser, array $before, array $values): bool {
    $stmt = $db->prepare("UPDATE ad_campaigns SET name = ?, target_radius_km = ?, media_type = ?
        WHERE id = ? AND advertiser_id = ? AND status = 'draft'
        AND BINARY name = BINARY ? AND target_radius_km = ? AND BINARY media_type = BINARY ?");
    $radius = (int)$values['target_radius_km'];
    $id = (int)$before['id'];
    $old_radius = (int)$before['target_radius_km'];
    $stmt->bind_param('sisiisis', $values['name'], $radius, $values['media_type'],
        $id, $advertiser, $before['name'], $old_radius, $before['media_type']);
    if (!$stmt->execute()) throw new RuntimeException('Campaign was not saved.');
    $changed = $stmt->affected_rows === 1;
    $stmt->close();
    if ($changed) return true;
    // MySQL reports zero affected rows for a valid no-change save, too.
    $current = campaign_find($db, $advertiser, $id);
    return $current && $current['status'] === 'draft'
        && campaign_revision($current) === campaign_revision($before)
        && $current['name'] === $values['name']
        && (int)$current['target_radius_km'] === $radius
        && $current['media_type'] === $values['media_type'];
}
