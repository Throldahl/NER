<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/app_lib.php';

captionerner_start_session();

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($body['action'] ?? '');

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$userEmail = (string)($_SESSION['user_email'] ?? '');
$testId = isset($_SESSION['test_id']) ? (int)$_SESSION['test_id'] : 0;

if ($userId <= 0) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Not logged in']);
  exit;
}

try {
  $pdo = captionerner_db();
  captionerner_ensure_schema($pdo);

  if ($action === 'session_start') {
    if ($testId <= 0) {
      echo json_encode(['ok' => false, 'message' => 'No test is assigned.']);
      exit;
    }

    $test = captionerner_fetch_test($pdo, $testId);
    if (!$test) {
      echo json_encode(['ok' => false, 'message' => 'Assigned test is unavailable.']);
      exit;
    }

    $ua = substr((string)($body['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 512);
    $vw = isset($body['viewport_w']) && is_numeric($body['viewport_w']) ? (int)$body['viewport_w'] : null;
    $vh = isset($body['viewport_h']) && is_numeric($body['viewport_h']) ? (int)$body['viewport_h'] : null;
    $firstFocusMs = isset($body['first_focus_ms']) && is_numeric($body['first_focus_ms']) ? (int)$body['first_focus_ms'] : null;
    $clientSessionId = substr((string)($body['client_session_id'] ?? ''), 0, 128);

    $stmt = $pdo->prepare("
      INSERT INTO captionerner_assessments
        (user_id, test_id, assessment_slug, client_session_id, login_at, gate_unlocked_at, user_agent, viewport_w, viewport_h, first_focus_ms)
      VALUES
        (:uid, :test_id, :slug, :client_session_id, NOW(), NOW(), :ua, :vw, :vh, :first_focus_ms)
    ");
    $stmt->execute([
      ':uid' => $userId,
      ':test_id' => $testId,
      ':slug' => $test['slug'],
      ':client_session_id' => $clientSessionId !== '' ? $clientSessionId : null,
      ':ua' => $ua,
      ':vw' => $vw,
      ':vh' => $vh,
      ':first_focus_ms' => $firstFocusMs,
    ]);

    $assessmentId = (int)$pdo->lastInsertId();

    echo json_encode(['ok' => true, 'session_id' => $assessmentId]);
    exit;
  }

  $sessionId = isset($body['session_id']) ? (int)$body['session_id'] : 0;
  if ($sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing session_id']);
    exit;
  }

  $check = $pdo->prepare('SELECT id, test_id, start_clicked_at, video_ended_at FROM captionerner_assessments WHERE id = :id AND user_id = :uid LIMIT 1');
  $check->execute([':id' => $sessionId, ':uid' => $userId]);
  $assessment = $check->fetch();
  if (!$assessment) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
  }
  $assessmentTestId = isset($assessment['test_id']) ? (int)$assessment['test_id'] : $testId;

  if ($action === 'audio_tested') {
    $stmt = $pdo->prepare("
      UPDATE captionerner_assessments
      SET audio_tested = 1, audio_test_count = audio_test_count + 1
      WHERE id = :id AND user_id = :uid
      LIMIT 1
    ");
    $stmt->execute([':id' => $sessionId, ':uid' => $userId]);
    captionerner_log_activity($pdo, 'audio_tested', 'Tested audio', [
      'user_id' => $userId,
      'user_email' => $userEmail,
      'test_id' => $assessmentTestId,
      'assessment_id' => $sessionId,
    ]);
    echo json_encode(['ok' => true]);
    exit;
  }

  if ($action === 'prestart_update') {
    $focusMs = isset($body['focus_ms_before_start']) ? max(0, (int)$body['focus_ms_before_start']) : 0;
    $wallMs = isset($body['wall_ms_before_start']) ? max(0, (int)$body['wall_ms_before_start']) : 0;
    $audioTested = !empty($body['audio_tested_before_start']) ? 1 : 0;
    $audioTestCount = isset($body['audio_test_count']) ? max(0, (int)$body['audio_test_count']) : 0;
    $copyCount = isset($body['copy_count_before_start']) ? max(0, (int)$body['copy_count_before_start']) : 0;
    $tabHiddenCount = isset($body['tab_hidden_count_before_start']) ? max(0, (int)$body['tab_hidden_count_before_start']) : 0;
    $countdownCancelled = !empty($body['countdown_cancelled']) ? 1 : 0;
    $wasStarted = !empty($assessment['start_clicked_at']);

    $stmt = $pdo->prepare("
      UPDATE captionerner_assessments
      SET
        focus_ms_before_start = :focus_ms,
        wall_ms_before_start = :wall_ms,
        audio_tested = GREATEST(audio_tested, :audio_tested),
        audio_test_count = GREATEST(audio_test_count, :audio_test_count),
        copy_count = :copy_count,
        tab_switches = :tab_switches,
        countdown_cancels = GREATEST(countdown_cancels, :cancelled),
        start_clicked_at = IF(start_clicked_at IS NULL AND :cancelled = 0, NOW(), start_clicked_at)
      WHERE id = :id AND user_id = :uid
      LIMIT 1
    ");
    $stmt->execute([
      ':focus_ms' => $focusMs,
      ':wall_ms' => $wallMs,
      ':audio_tested' => $audioTested,
      ':audio_test_count' => $audioTestCount,
      ':copy_count' => $copyCount,
      ':tab_switches' => $tabHiddenCount,
      ':cancelled' => $countdownCancelled,
      ':id' => $sessionId,
      ':uid' => $userId,
    ]);

    if ($countdownCancelled === 1) {
      captionerner_log_activity($pdo, 'countdown_cancelled', 'Cancelled countdown', [
        'user_id' => $userId,
        'user_email' => $userEmail,
        'test_id' => $assessmentTestId,
        'assessment_id' => $sessionId,
      ]);
    } elseif (!$wasStarted) {
      captionerner_log_activity($pdo, 'test_started', 'Started test', [
        'user_id' => $userId,
        'user_email' => $userEmail,
        'test_id' => $assessmentTestId,
        'assessment_id' => $sessionId,
      ]);
    }

    echo json_encode(['ok' => true]);
    exit;
  }

  if ($action === 'video_started') {
    $stmt = $pdo->prepare("
      UPDATE captionerner_assessments
      SET video_started_at = IF(video_started_at IS NULL, NOW(), video_started_at)
      WHERE id = :id AND user_id = :uid
      LIMIT 1
    ");
    $stmt->execute([':id' => $sessionId, ':uid' => $userId]);
    captionerner_log_activity($pdo, 'media_started', 'Assessment media started', [
      'user_id' => $userId,
      'user_email' => $userEmail,
      'test_id' => $assessmentTestId,
      'assessment_id' => $sessionId,
    ]);
    echo json_encode(['ok' => true]);
    exit;
  }

  if ($action === 'video_ended') {
    $alreadyCompleted = !empty($assessment['video_ended_at']);
    $stmt = $pdo->prepare("
      UPDATE captionerner_assessments
      SET
        video_ended_at = IF(video_ended_at IS NULL, NOW(), video_ended_at),
        completed_at = IF(completed_at IS NULL, NOW(), completed_at)
      WHERE id = :id AND user_id = :uid
      LIMIT 1
    ");
    $stmt->execute([':id' => $sessionId, ':uid' => $userId]);

    if (!$alreadyCompleted) {
      captionerner_log_activity($pdo, 'test_completed', 'Completed test', [
        'user_id' => $userId,
        'user_email' => $userEmail,
        'test_id' => $assessmentTestId,
        'assessment_id' => $sessionId,
      ]);
    }

    echo json_encode(['ok' => true]);
    exit;
  }

  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Server error', 'detail' => $e->getMessage()]);
}
