<?php
// api_auth.php — email gate + admin login using PHP sessions
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

if (defined('SESSION_NAME')) {
  session_name(SESSION_NAME);
}
session_set_cookie_params([
  'httponly' => true,
  'samesite' => 'Lax',
  'secure' => $secure,
]);

if (defined('SESSION_NAME')) session_name(SESSION_NAME);

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

function json_out(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data);
  exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '[]', true);
if (!is_array($body)) $body = [];

$action = $body['action'] ?? '';

try {
  $pdo = captionerner_db();

  if ($action === 'whoami') {
    $email = $_SESSION['user_email'] ?? null;
    $role = $_SESSION['user_role'] ?? null;
    if ($email && $role) json_out(['ok' => true, 'email' => $email, 'role' => $role]);
    json_out(['ok' => false, 'message' => 'Not logged in.'], 200);
  }

  if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"], $params["secure"], $params["httponly"]
      );
    }
    session_destroy();
    json_out(['ok' => true]);
  }

  if ($action === 'gate') {
    $email = strtolower(trim((string)($body['email'] ?? '')));
    if ($email === '' || !str_ends_with($email, '@3playmedia.com')) {
      json_out(['ok' => false, 'message' => 'Invalid email domain.'], 400);
    }

    $stmt = $pdo->prepare("SELECT id, email, role, is_active FROM captionerner_users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if (!$u || (int)$u['is_active'] !== 1) {
      json_out(['ok' => false, 'message' => 'Email not recognized.'], 403);
    }

    // Gate sets a session for metrics logging (admins can still use assessment)
    $_SESSION['user_email'] = $u['email'];
    $_SESSION['user_role']  = $u['role'];
    $_SESSION['user_id']    = (int)$u['id'];
    $_SESSION['assessment_slug'] = defined('DEFAULT_ASSESSMENT_SLUG') ? DEFAULT_ASSESSMENT_SLUG : 'default';


    json_out(['ok' => true, 'role' => $u['role']]);
  }

  if ($action === 'admin_login') {
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $password = (string)($body['password'] ?? '');

    if ($email === '' || $password === '') {
      json_out(['ok' => false, 'message' => 'Email and password required.'], 400);
    }

    $stmt = $pdo->prepare("SELECT id, email, role, is_active, password_hash FROM captionerner_users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if (!$u || (int)$u['is_active'] !== 1 || $u['role'] !== 'admin') {
      json_out(['ok' => false, 'message' => 'Not authorized.'], 403);
    }

    $hash = (string)($u['password_hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
      json_out(['ok' => false, 'message' => 'Invalid credentials.'], 403);
    }

    $_SESSION['user_email'] = $u['email'];
    $_SESSION['user_role']  = $u['role'];
    $_SESSION['user_id']    = (int)$u['id'];
    $_SESSION['assessment_slug'] = defined('DEFAULT_ASSESSMENT_SLUG') ? DEFAULT_ASSESSMENT_SLUG : 'default';


    json_out(['ok' => true, 'role' => 'admin']);
  }

  json_out(['ok' => false, 'message' => 'Unknown action.'], 400);

} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => 'Server error.'], 500);
}