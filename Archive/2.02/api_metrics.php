<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

if (defined('SESSION_NAME')) session_name(SESSION_NAME);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

$userId = $_SESSION['user_id'] ?? null;
$slug   = $_SESSION['assessment_slug'] ?? (defined('DEFAULT_ASSESSMENT_SLUG') ? DEFAULT_ASSESSMENT_SLUG : 'default');

if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

try {
    $pdo = captionerner_db();

    if ($action === 'session_start') {
        $ua = substr((string)($body['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 512);

        $vw = isset($body['viewport_w']) && is_numeric($body['viewport_w']) ? (int)$body['viewport_w'] : null;
        $vh = isset($body['viewport_h']) && is_numeric($body['viewport_h']) ? (int)$body['viewport_h'] : null;

        $stmt = $pdo->prepare("
            INSERT INTO captionerner_assessments
                (user_id, assessment_slug, login_at, gate_unlocked_at, user_agent, viewport_w, viewport_h)
            VALUES
                (:uid, :slug, NOW(), NOW(), :ua, :vw, :vh)
        ");
        $stmt->execute([
            ':uid' => (int)$userId,
            ':slug' => $slug,
            ':ua' => $ua,
            ':vw' => $vw,
            ':vh' => $vh
        ]);

        echo json_encode(['ok' => true, 'session_id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    // Everything below requires a valid session row id
    $sessionId = isset($body['session_id']) ? (int)$body['session_id'] : 0;
    if ($sessionId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing session_id']);
        exit;
    }

    // Confirm ownership (prevents cross-user updates)
    $check = $pdo->prepare("SELECT id FROM captionerner_assessments WHERE id = :id AND user_id = :uid LIMIT 1");
    $check->execute([':id' => $sessionId, ':uid' => (int)$userId]);
    if (!$check->fetch()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    if ($action === 'prestart_update') {
        // JS fields (existing)
        $focusMs = isset($body['focus_ms_before_start']) ? (int)$body['focus_ms_before_start'] : 0;
        $wallMs  = isset($body['wall_ms_before_start']) ? (int)$body['wall_ms_before_start'] : 0;

        $audioTested = !empty($body['audio_tested_before_start']) ? 1 : 0;

        $copyCount = isset($body['copy_count_before_start']) ? (int)$body['copy_count_before_start'] : 0;

        $tabHiddenCount = isset($body['tab_hidden_count_before_start']) ? (int)$body['tab_hidden_count_before_start'] : 0;

        $countdownCancelled = !empty($body['countdown_cancelled']) ? 1 : 0;

        $stmt = $pdo->prepare("
            UPDATE captionerner_assessments
            SET
                focus_ms_before_start = :focusMs,
                wall_ms_before_start = :wallMs,
                audio_tested = GREATEST(audio_tested, :audioTested),
                copy_count = :copyCount,
                tab_switches = :tabSwitches,
                countdown_cancels = GREATEST(countdown_cancels, :cancelled),
                start_clicked_at = IF(start_clicked_at IS NULL, NOW(), start_clicked_at)
            WHERE id = :id AND user_id = :uid
            LIMIT 1
        ");
        $stmt->execute([
            ':focusMs' => $focusMs,
            ':wallMs' => $wallMs,
            ':audioTested' => $audioTested,
            ':copyCount' => $copyCount,
            ':tabSwitches' => $tabHiddenCount,
            ':cancelled' => $countdownCancelled,
            ':id' => $sessionId,
            ':uid' => (int)$userId
        ]);

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'video_started') {
        // Requires you to add the column (see SQL below)
        $stmt = $pdo->prepare("
            UPDATE captionerner_assessments
            SET video_started_at = IF(video_started_at IS NULL, NOW(), video_started_at)
            WHERE id = :id AND user_id = :uid
            LIMIT 1
        ");
        $stmt->execute([':id' => $sessionId, ':uid' => (int)$userId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'video_ended') {
        // Optional: track completion
        $stmt = $pdo->prepare("
            UPDATE captionerner_assessments
            SET video_ended_at = IF(video_ended_at IS NULL, NOW(), video_ended_at)
            WHERE id = :id AND user_id = :uid
            LIMIT 1
        ");
        $stmt->execute([':id' => $sessionId, ':uid' => (int)$userId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error', 'detail' => $e->getMessage()]);
}
