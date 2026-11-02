<?php
declare(strict_types=1);

function campaign_escape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function campaign_revision(array $campaign): string {
    return hash('sha256', json_encode([(int)$campaign['id'], (string)$campaign['name'],
        (string)$campaign['status'], (int)$campaign['target_radius_km'],
        (string)$campaign['media_type'], (string)($campaign['headline'] ?? ''),
        (string)($campaign['message'] ?? ''), (string)($campaign['destination_url'] ?? '')], JSON_THROW_ON_ERROR));
}

function campaign_input(array $input): array {
    $errors = [];
    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    $radius = is_string($input['target_radius_km'] ?? null) ? $input['target_radius_km'] : '';
    $media = is_string($input['media_type'] ?? null) ? $input['media_type'] : '';
    $creative = [];
    foreach (['headline'=>90, 'message'=>500, 'destination_url'=>2048] as $field=>$limit) {
        $creative[$field] = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
        if (isset($input[$field]) && !is_string($input[$field])) {
            $errors[$field] = 'Enter text for this field.';
        } elseif ($creative[$field] !== '' && (preg_match('/^.{1,'.$limit.'}$/usD', $creative[$field]) !== 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $creative[$field]))) {
            $errors[$field] = 'Use no more than '.$limit.' characters without control characters.';
        }
    }
    if ($creative['destination_url'] !== '') {
        $url = parse_url($creative['destination_url']);
        if (!filter_var($creative['destination_url'], FILTER_VALIDATE_URL) || !$url
            || strtolower($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass'])) {
            $errors['destination_url'] = 'Enter a full HTTPS website address without a username or password.';
        }
    }
    if (preg_match('/^.{1,160}$/usD', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
        $errors['name'] = 'Enter a campaign name of up to 160 characters without control characters.';
    }
    if (!preg_match('/^[1-9][0-9]{0,2}$/D', $radius) || (int)$radius > 250) {
        $errors['target_radius_km'] = 'Choose a whole-number radius from 1 to 250 km.';
    }
    if (!in_array($media, ['image', 'carousel', 'video'], true)) {
        $errors['media_type'] = 'Choose an image, carousel or video draft.';
    }
    return [array_merge(['name'=>$name, 'target_radius_km'=>$radius, 'media_type'=>$media], $creative), $errors];
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

function campaign_list($db, int $advertiser, string $search, string $status): array {
    // Literal substring search: user '%'/'_' are not SQL wildcard operators.
    $pattern = '%'.str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search).'%';
    if (!in_array($status, ['', 'draft', 'archived'], true)) throw new InvalidArgumentException('Unsupported filter.');
    $stmt = $db->prepare("SELECT id, name, status, target_radius_km, media_type
        FROM ad_campaigns WHERE advertiser_id = ? AND name LIKE ? ESCAPE '='
        AND (? = '' OR status = ?) ORDER BY created_at DESC, id DESC LIMIT 100");
    $stmt->bind_param('isss', $advertiser, $pattern, $status, $status);
    $stmt->execute();
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function campaign_duplicate($db, int $advertiser, array $source): int {
    if (preg_match('/^.{0,150}/us', (string)$source['name'], $parts) !== 1) {
        throw new RuntimeException('Campaign name unavailable.');
    }
    $values = ['name'=>$parts[0].' (copy)', 'target_radius_km'=>(string)$source['target_radius_km'],
        'media_type'=>$source['media_type'], 'headline'=>$source['headline'] ?? '',
        'message'=>$source['message'] ?? '', 'destination_url'=>$source['destination_url'] ?? ''];
    [$values, $errors] = campaign_input($values);
    if ($errors) throw new RuntimeException('Source campaign requires review before copying.');
    return campaign_create($db, $advertiser, $values);
}

function campaign_transition($db, int $advertiser, int $id, string $from, string $to): bool {
    if (!(($from === 'draft' && $to === 'archived') || ($from === 'archived' && $to === 'draft'))) {
        throw new InvalidArgumentException('Unsupported transition.');
    }
    $stmt = $db->prepare('UPDATE ad_campaigns SET status = ? WHERE id = ? AND advertiser_id = ? AND status = ?');
    $stmt->bind_param('siis', $to, $id, $advertiser, $from);
    if (!$stmt->execute()) throw new RuntimeException('Campaign status was not saved.');
    $changed = $stmt->affected_rows === 1;
    $stmt->close();
    return $changed;
}

function campaign_find($db, int $advertiser, int $id): ?array {
    $stmt = $db->prepare('SELECT id, name, status, target_radius_km, media_type, headline, message, destination_url
        FROM ad_campaigns WHERE id = ? AND advertiser_id = ? LIMIT 1');
    $stmt->bind_param('ii', $id, $advertiser);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function campaign_create($db, int $advertiser, array $values): int {
    $stmt = $db->prepare("INSERT INTO ad_campaigns
        (advertiser_id, name, status, target_radius_km, media_type, headline, message, destination_url, created_at)
        VALUES (?, ?, 'draft', ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
    $radius = (int)$values['target_radius_km'];
    $stmt->bind_param('isissss', $advertiser, $values['name'], $radius, $values['media_type'],
        $values['headline'], $values['message'], $values['destination_url']);
    if (!$stmt->execute()) throw new RuntimeException('Campaign was not saved.');
    $id = (int)$stmt->insert_id;
    $stmt->close();
    if ($id < 1) throw new RuntimeException('Campaign identifier unavailable.');
    return $id;
}

function campaign_update($db, int $advertiser, array $before, array $values): bool {
    $stmt = $db->prepare("UPDATE ad_campaigns SET name = ?, target_radius_km = ?, media_type = ?,
        headline = ?, message = ?, destination_url = ?
        WHERE id = ? AND advertiser_id = ? AND status = 'draft'
        AND BINARY name = BINARY ? AND target_radius_km = ? AND BINARY media_type = BINARY ?
        AND BINARY headline = BINARY ? AND BINARY message = BINARY ? AND BINARY destination_url = BINARY ?");
    $radius = (int)$values['target_radius_km'];
    $id = (int)$before['id'];
    $old_radius = (int)$before['target_radius_km'];
    $stmt->bind_param('sissssiisissss', $values['name'], $radius, $values['media_type'],
        $values['headline'], $values['message'], $values['destination_url'],
        $id, $advertiser, $before['name'], $old_radius, $before['media_type'],
        $before['headline'], $before['message'], $before['destination_url']);
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
        && $current['media_type'] === $values['media_type']
        && $current['headline'] === $values['headline']
        && $current['message'] === $values['message']
        && $current['destination_url'] === $values['destination_url'];
}
