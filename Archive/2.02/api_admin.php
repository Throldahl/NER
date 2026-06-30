<?php
// api_admin.php — admin-only endpoints for metrics + user management (integrated dashboard)
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

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

function json_out(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data);
  exit;
}

$role = $_SESSION['user_role'] ?? null;
if ($role !== 'admin') {
  json_out(['ok' => false, 'message' => 'Admin only.'], 403);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '[]', true);
if (!is_array($body)) $body = [];

$action = $body['action'] ?? '';

try {
  $pdo = captionerner_db();

  if ($action === 'users_list') {
    $limit = (int)($body['limit'] ?? 500);
    if ($limit < 1) $limit = 50;
    if ($limit > 2000) $limit = 2000;

    $stmt = $pdo->prepare("SELECT id, email, role, is_active, created_at FROM captionerner_users ORDER BY email ASC LIMIT {$limit}");
    $stmt->execute();
    $users = $stmt->fetchAll();
    json_out(['ok' => true, 'users' => $users]);
  }

  if ($action === 'users_add') {
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $role = trim((string)($body['role'] ?? 'captioner'));
    $password = (string)($body['password'] ?? '');

    if ($email === '' || !str_ends_with($email, '@3playmedia.com')) {
      json_out(['ok' => false, 'message' => 'Invalid email domain.'], 400);
    }
    if (!in_array($role, ['captioner', 'admin'], true)) {
      json_out(['ok' => false, 'message' => 'Invalid role.'], 400);
    }
    if ($role === 'admin' && strlen($password) < 8) {
      json_out(['ok' => false, 'message' => 'Admin password required (min 8 chars).'], 400);
    }

    $hash = null;
    if ($role === 'admin') {
      $hash = password_hash($password, PASSWORD_DEFAULT);
    }

    // Upsert: if exists, reactivate + update role/hash as needed
    $stmt = $pdo->prepare("
      INSERT INTO captionerner_users (email, role, is_active, password_hash)
      VALUES (:email, :role, 1, :hash)
      ON DUPLICATE KEY UPDATE
        role = VALUES(role),
        is_active = 1,
        password_hash = COALESCE(VALUES(password_hash), password_hash)
    ");
    $stmt->bindValue(':email', $email);
    $stmt->bindValue(':role', $role);
    $stmt->bindValue(':hash', $hash, $hash === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->execute();

    json_out(['ok' => true]);
  }

  if ($action === 'users_deactivate' || $action === 'users_reactivate') {
    $id = (int)($body['user_id'] ?? 0);
    if ($id <= 0) json_out(['ok' => false, 'message' => 'Missing user_id.'], 400);

    $active = ($action === 'users_reactivate') ? 1 : 0;

    $stmt = $pdo->prepare("UPDATE captionerner_users SET is_active = :active WHERE id = :id LIMIT 1");
    $stmt->execute([':active' => $active, ':id' => $id]);

    json_out(['ok' => true]);
  }


  if ($action === 'metrics_users_rollup') {
    $limit = (int)($body['limit'] ?? 1000);
    if ($limit < 1) $limit = 1000;
    if ($limit > 5000) $limit = 5000;

    // Confirm sessions table exists so we can return a useful message.
    $t = $pdo->query("SHOW TABLES LIKE 'captionerner_assessments'")->fetch();
    if (!$t) {
      json_out(['ok' => false, 'message' => 'Missing captionerner_assessments table. Run the schema patch to create it.'], 200);
    }

    // One row per user + their latest session (if any)
    $sql = "
      SELECT
        u.id AS user_id,
        u.email,
        u.role,
        u.is_active,
        u.created_at AS user_created_at,

        s.id AS session_id,
        s.created_at,
        s.start_clicked_at,
        s.video_started_at,
        s.video_ended_at,
        s.audio_tested AS audio_tested_before_start,
        s.copy_count AS copy_count_before_start,
        s.focus_ms_before_start,
        s.wall_ms_before_start,
        s.tab_switches AS tab_hidden_count_before_start,
        CASE WHEN s.countdown_cancels > 0 THEN 1 ELSE 0 END AS countdown_cancelled
      FROM captionerner_users u
      LEFT JOIN captionerner_assessments s
        ON s.id = (
          SELECT id
          FROM captionerner_assessments
          WHERE user_id = u.id
          ORDER BY created_at DESC, id DESC
          LIMIT 1
        )
      ORDER BY u.email ASC
      LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    json_out(['ok' => true, 'rows' => $rows]);
  }

  if ($action === 'metrics_list') {
    $limit = isset($body['limit']) ? max(1, min(1000, (int)$body['limit'])) : 500;

    $sql = "
SELECT
  u.email,
  a.created_at,
  a.start_clicked_at,
  a.audio_tested AS audio_tested_before_start,
  a.copy_count AS copy_count_before_start,
  a.tab_switches AS tab_hidden_count_before_start,
  a.focus_ms_before_start,
  a.wall_ms_before_start,
  CASE WHEN a.countdown_cancels > 0 THEN 1 ELSE 0 END AS countdown_cancelled
FROM captionerner_users u
LEFT JOIN (
  SELECT x.*
  FROM captionerner_assessments x
  INNER JOIN (
    SELECT user_id, MAX(id) AS max_id
    FROM captionerner_assessments
    GROUP BY user_id
  ) latest ON latest.max_id = x.id
) a ON a.user_id = u.id
ORDER BY u.email ASC
LIMIT {$limit}
";


    $stmt = $pdo->query($sql);
    echo json_encode(['ok' => true, 'rows' => $stmt->fetchAll()]);
    exit;
}


  json_out(['ok' => false, 'message' => 'Unknown action.'], 400);

} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => 'Server error.', 'detail' => $e->getMessage()], 500);
} 