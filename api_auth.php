<?php
// api_auth.php - unified email/Google gate using PHP sessions
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/app_lib.php';

captionerner_start_session();

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '[]', true);
if (!is_array($body)) $body = [];

$action = (string)($body['action'] ?? '');

if ($action === 'config') {
  captionerner_json_out([
    'ok' => true,
    'google_client_id' => captionerner_google_client_id(),
    'google_required_domain' => captionerner_google_domain(),
    'test_audio_max_mb' => (int)(CAPTIONERNER_TEST_AUDIO_MAX_BYTES / 1048576),
    'source_media_max_mb' => (int)(CAPTIONERNER_SOURCE_MEDIA_MAX_BYTES / 1048576),
    'allowed_test_audio' => captionerner_allowed_uploads('test_audio')['extensions'],
    'allowed_source_media' => captionerner_allowed_uploads('source')['extensions'],
  ]);
}

try {
  $pdo = captionerner_db();
  try {
    captionerner_ensure_schema($pdo);
  } catch (Throwable $schemaError) {
    error_log('captionerner schema setup failed during auth: ' . $schemaError->getMessage());
  }

  if ($action === 'whoami') {
    $email = $_SESSION['user_email'] ?? null;
    $role = $_SESSION['user_role'] ?? null;
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if (!$email || !$role || $userId <= 0) {
      captionerner_json_out(['ok' => false, 'message' => 'Not logged in.'], 200);
    }

    $testColumn = captionerner_column_exists($pdo, 'captionerner_users', 'test_id') ? 'test_id' : 'NULL AS test_id';
    $stmt = $pdo->prepare("SELECT id, email, role, is_active, {$testColumn} FROM captionerner_users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || (int)$user['is_active'] !== 1) {
      captionerner_json_out(['ok' => false, 'message' => 'Not logged in.'], 200);
    }

    captionerner_json_out(captionerner_auth_payload($pdo, $user));
  }

  if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    captionerner_json_out(['ok' => true]);
  }

  if ($action === 'email_login' || $action === 'gate') {
    $email = strtolower(trim((string)($body['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      captionerner_json_out(['ok' => false, 'message' => 'Enter a valid email address.'], 400);
    }
    if (captionerner_is_google_domain_email($email)) {
      captionerner_json_out(['ok' => false, 'message' => 'Use Google Sign-In for @' . captionerner_google_domain() . ' accounts.'], 403);
    }

    $user = captionerner_fetch_user($pdo, $email);
    if (!$user || (int)$user['is_active'] !== 1) {
      captionerner_log_activity($pdo, 'login_denied', 'Login denied', [
        'user_email' => $email,
        'details' => ['reason' => 'unknown_or_inactive', 'auth_method' => 'email'],
      ]);
      captionerner_json_out(['ok' => false, 'message' => 'Email not recognized.'], 403);
    }

    captionerner_json_out(captionerner_set_login_session($pdo, $user, 'email'));
  }

  if ($action === 'google_login') {
    try {
      $google = captionerner_verify_google_token((string)($body['credential'] ?? ''));
    } catch (Throwable $e) {
      captionerner_json_out(['ok' => false, 'message' => $e->getMessage()], 403);
    }

    $user = captionerner_fetch_user($pdo, $google['email']);
    if (!$user || (int)$user['is_active'] !== 1) {
      captionerner_log_activity($pdo, 'login_denied', 'Login denied', [
        'user_email' => $google['email'],
        'details' => ['reason' => 'unknown_or_inactive', 'auth_method' => 'google'],
      ]);
      captionerner_json_out(['ok' => false, 'message' => 'Google account is valid, but this email has not been added for access.'], 403);
    }

    captionerner_json_out(captionerner_set_login_session($pdo, $user, 'google'));
  }

  captionerner_json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
} catch (Throwable $e) {
  error_log('captionerner auth failed: ' . $e->getMessage());
  captionerner_json_out(['ok' => false, 'error' => 'Server error.', 'detail' => $e->getMessage()], 500);
}
