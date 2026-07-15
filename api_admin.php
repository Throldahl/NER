<?php
// api_admin.php - admin endpoints for tests, media, users, metrics, and activity
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/app_lib.php';

captionerner_start_session();

try {
  $pdo = captionerner_db();
  captionerner_ensure_schema($pdo);
} catch (Throwable $e) {
  captionerner_json_out(['ok' => false, 'error' => 'Server error.'], 500);
}

$role = $_SESSION['user_role'] ?? null;
$adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$adminEmail = (string)($_SESSION['user_email'] ?? '');
if ($role !== 'admin' || $adminId <= 0) {
  captionerner_json_out(['ok' => false, 'message' => 'Admin only.'], 403);
}

$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
$isMultipart = stripos($contentType, 'multipart/form-data') !== false;
$body = $isMultipart ? $_POST : json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($body)) $body = [];

if ($isMultipart && empty($_POST) && empty($_FILES)) {
  $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
  $postMax = captionerner_ini_bytes((string)ini_get('post_max_size'));
  if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax) {
    captionerner_json_out([
      'ok' => false,
      'message' => 'This upload exceeds the server request limit of ' . captionerner_format_bytes($postMax) . '. Increase Hostinger/PHP post_max_size and upload_max_filesize or choose a smaller file.',
    ], 413);
  }
}

$action = (string)($body['action'] ?? '');

function admin_media_row(PDO $pdo, int $id): ?array {
  $stmt = $pdo->prepare('SELECT * FROM captionerner_media WHERE id = ? LIMIT 1');
  $stmt->execute([$id]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function admin_test_exists(PDO $pdo, int $id): bool {
  if ($id <= 0) return false;
  $stmt = $pdo->prepare('SELECT id FROM captionerner_tests WHERE id = ? AND deleted_at IS NULL LIMIT 1');
  $stmt->execute([$id]);
  return (bool)$stmt->fetch();
}

try {
  if ($action === 'tests_list') {
    $stmt = $pdo->query("
      SELECT
        t.id,
        t.slug,
        t.title,
        t.subtitle,
        t.instructions_html,
        t.prep_html,
        t.test_audio_media_id,
        t.source_media_id,
        t.created_at,
        t.updated_at,
        ta.label AS test_audio_label,
        sm.label AS source_media_label,
        sm.media_kind AS source_media_kind,
        (SELECT COUNT(*) FROM captionerner_users u WHERE u.test_id = t.id) AS assigned_count
      FROM captionerner_tests t
      LEFT JOIN captionerner_media ta ON ta.id = t.test_audio_media_id
      LEFT JOIN captionerner_media sm ON sm.id = t.source_media_id
      WHERE t.deleted_at IS NULL
      ORDER BY t.title ASC
    ");
    captionerner_json_out(['ok' => true, 'tests' => $stmt->fetchAll()]);
  }

  if ($action === 'tests_save') {
    $id = isset($body['test_id']) ? (int)$body['test_id'] : 0;
    $title = trim((string)($body['title'] ?? ''));
    $subtitle = trim((string)($body['subtitle'] ?? ''));
    $instructions = captionerner_sanitize_html((string)($body['instructions_html'] ?? ''));
    $prep = captionerner_sanitize_html((string)($body['prep_html'] ?? ''));
    $testAudioId = isset($body['test_audio_media_id']) && (int)$body['test_audio_media_id'] > 0 ? (int)$body['test_audio_media_id'] : null;
    $sourceMediaId = isset($body['source_media_id']) && (int)$body['source_media_id'] > 0 ? (int)$body['source_media_id'] : null;

    if ($title === '') captionerner_json_out(['ok' => false, 'message' => 'Test title is required.'], 400);
    if ($instructions === '') captionerner_json_out(['ok' => false, 'message' => 'Instructions are required.'], 400);
    if ($prep === '') captionerner_json_out(['ok' => false, 'message' => 'Prep content is required.'], 400);
    if ($testAudioId === null) captionerner_json_out(['ok' => false, 'message' => 'Choose a Test Audio button file.'], 400);
    if ($sourceMediaId === null) captionerner_json_out(['ok' => false, 'message' => 'Choose an assessment source media file.'], 400);
    if ($testAudioId !== null && !captionerner_media_valid_for_usage($pdo, $testAudioId, 'test_audio')) {
      captionerner_json_out(['ok' => false, 'message' => 'Choose a valid test audio file.'], 400);
    }
    if ($sourceMediaId !== null && !captionerner_media_valid_for_usage($pdo, $sourceMediaId, 'source')) {
      captionerner_json_out(['ok' => false, 'message' => 'Choose a valid source media file.'], 400);
    }

    if ($id > 0) {
      if (!admin_test_exists($pdo, $id)) captionerner_json_out(['ok' => false, 'message' => 'Test not found.'], 404);
      $stmt = $pdo->prepare("
        UPDATE captionerner_tests
        SET
          title = :title,
          subtitle = :subtitle,
          instructions_html = :instructions,
          prep_html = :prep,
          test_audio_media_id = :test_audio_id,
          source_media_id = :source_media_id,
          updated_by = :updated_by,
          updated_at = NOW()
        WHERE id = :id
        LIMIT 1
      ");
      $stmt->execute([
        ':title' => $title,
        ':subtitle' => $subtitle !== '' ? $subtitle : null,
        ':instructions' => $instructions,
        ':prep' => $prep,
        ':test_audio_id' => $testAudioId,
        ':source_media_id' => $sourceMediaId,
        ':updated_by' => $adminId,
        ':id' => $id,
      ]);
      captionerner_log_activity($pdo, 'admin_test_saved', 'Admin updated test', [
        'user_id' => $adminId,
        'user_email' => $adminEmail,
        'test_id' => $id,
        'details' => ['title' => $title],
      ]);
      captionerner_json_out(['ok' => true, 'test_id' => $id]);
    }

    $slug = captionerner_generate_slug($pdo, $title);
    $stmt = $pdo->prepare("
      INSERT INTO captionerner_tests
        (slug, title, subtitle, instructions_html, prep_html, test_audio_media_id, source_media_id, created_by, updated_by)
      VALUES
        (:slug, :title, :subtitle, :instructions, :prep, :test_audio_id, :source_media_id, :created_by, :updated_by)
    ");
    $stmt->execute([
      ':slug' => $slug,
      ':title' => $title,
      ':subtitle' => $subtitle !== '' ? $subtitle : null,
      ':instructions' => $instructions,
      ':prep' => $prep,
      ':test_audio_id' => $testAudioId,
      ':source_media_id' => $sourceMediaId,
      ':created_by' => $adminId,
      ':updated_by' => $adminId,
    ]);
    $newId = (int)$pdo->lastInsertId();
    captionerner_log_activity($pdo, 'admin_test_created', 'Admin created test', [
      'user_id' => $adminId,
      'user_email' => $adminEmail,
      'test_id' => $newId,
      'details' => ['title' => $title],
    ]);
    captionerner_json_out(['ok' => true, 'test_id' => $newId]);
  }

  if ($action === 'tests_delete') {
    $id = (int)($body['test_id'] ?? 0);
    $force = !empty($body['force']);
    if (!admin_test_exists($pdo, $id)) captionerner_json_out(['ok' => false, 'message' => 'Test not found.'], 404);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM captionerner_users WHERE test_id = ?');
    $countStmt->execute([$id]);
    $assignedCount = (int)$countStmt->fetchColumn();
    if ($assignedCount > 0 && !$force) {
      captionerner_json_out([
        'ok' => false,
        'requires_confirm' => true,
        'assigned_count' => $assignedCount,
        'message' => "This test is assigned to {$assignedCount} user(s).",
      ], 409);
    }

    $pdo->beginTransaction();
    if ($assignedCount > 0) {
      $clear = $pdo->prepare('UPDATE captionerner_users SET test_id = NULL WHERE test_id = ?');
      $clear->execute([$id]);
    }
    $del = $pdo->prepare('UPDATE captionerner_tests SET deleted_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
    $del->execute([$adminId, $id]);
    $pdo->commit();

    captionerner_log_activity($pdo, 'admin_test_deleted', 'Admin deleted test', [
      'user_id' => $adminId,
      'user_email' => $adminEmail,
      'test_id' => $id,
      'details' => ['unassigned_users' => $assignedCount],
    ]);
    captionerner_json_out(['ok' => true, 'unassigned_users' => $assignedCount]);
  }

  if ($action === 'media_list') {
    $usage = (string)($body['usage_kind'] ?? '');
    $where = 'WHERE deleted_at IS NULL';
    $params = [];
    if (in_array($usage, ['test_audio', 'source'], true)) {
      $where .= ' AND usage_kind = ?';
      $params[] = $usage;
    }
    $stmt = $pdo->prepare("
      SELECT id, label, file_path, original_name, mime_type, media_kind, usage_kind, size_bytes, is_builtin, created_at
      FROM captionerner_media
      {$where}
      ORDER BY usage_kind ASC, label ASC
    ");
    $stmt->execute($params);
    captionerner_json_out(['ok' => true, 'media' => $stmt->fetchAll()]);
  }

  if ($action === 'media_upload') {
    $usageKind = (string)($body['usage_kind'] ?? 'source');
    $label = (string)($body['label'] ?? '');
    $uploadFile = $_FILES['media_file'] ?? [];
    $uploadExt = strtolower(pathinfo((string)($uploadFile['name'] ?? ''), PATHINFO_EXTENSION));
    $effectiveUsageKind = ($usageKind === 'test_audio' && in_array($uploadExt, ['mp4', 'mov', 'webm'], true)) ? 'source' : $usageKind;
    $mediaId = captionerner_store_upload($pdo, $uploadFile, $usageKind, $label, $adminId);
    captionerner_log_activity($pdo, 'admin_media_uploaded', 'Admin uploaded media', [
      'user_id' => $adminId,
      'user_email' => $adminEmail,
      'details' => ['media_id' => $mediaId, 'usage_kind' => $effectiveUsageKind],
    ]);
    captionerner_json_out(['ok' => true, 'media_id' => $mediaId, 'usage_kind' => $effectiveUsageKind]);
  }

  if ($action === 'media_delete') {
    $id = (int)($body['media_id'] ?? 0);
    $media = admin_media_row($pdo, $id);
    if (!$media || !empty($media['deleted_at'])) captionerner_json_out(['ok' => false, 'message' => 'Media not found.'], 404);
    if ((int)$media['is_builtin'] === 1) {
      captionerner_json_out(['ok' => false, 'message' => 'Built-in media cannot be deleted.'], 400);
    }

    $refs = $pdo->prepare('SELECT COUNT(*) FROM captionerner_tests WHERE deleted_at IS NULL AND (test_audio_media_id = ? OR source_media_id = ?)');
    $refs->execute([$id, $id]);
    if ((int)$refs->fetchColumn() > 0) {
      captionerner_json_out(['ok' => false, 'message' => 'This media is used by a test. Remove it from tests before deleting.'], 409);
    }

    $stmt = $pdo->prepare('UPDATE captionerner_media SET deleted_at = NOW() WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);

    $relative = (string)$media['file_path'];
    if (str_starts_with($relative, CAPTIONERNER_UPLOAD_DIR . '/')) {
      $path = __DIR__ . '/' . $relative;
      if (is_file($path)) @unlink($path);
    }

    captionerner_log_activity($pdo, 'admin_media_deleted', 'Admin deleted media', [
      'user_id' => $adminId,
      'user_email' => $adminEmail,
      'details' => ['media_id' => $id, 'label' => $media['label']],
    ]);
    captionerner_json_out(['ok' => true]);
  }

  if ($action === 'users_list') {
    $limit = (int)($body['limit'] ?? 1000);
    if ($limit < 1) $limit = 1000;
    if ($limit > 5000) $limit = 5000;

    $stmt = $pdo->prepare("
      SELECT
        u.id,
        u.email,
        u.role,
        u.is_active,
        u.test_id,
        u.last_login_at,
        u.created_at,
        t.title AS test_title,
        EXISTS (
          SELECT 1
          FROM captionerner_assessments a
          WHERE a.user_id = u.id
            AND (a.video_ended_at IS NOT NULL OR a.completed_at IS NOT NULL)
          LIMIT 1
        ) AS has_completed
      FROM captionerner_users u
      LEFT JOIN captionerner_tests t ON t.id = u.test_id
      ORDER BY u.email ASC
      LIMIT {$limit}
    ");
    $stmt->execute();
    captionerner_json_out(['ok' => true, 'users' => $stmt->fetchAll()]);
  }

  if ($action === 'users_add') {
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $newRole = trim((string)($body['role'] ?? 'captioner'));
    $testId = isset($body['test_id']) && (int)$body['test_id'] > 0 ? (int)$body['test_id'] : null;

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      captionerner_json_out(['ok' => false, 'message' => 'Enter a valid email address.'], 400);
    }
    if (!in_array($newRole, ['captioner', 'admin'], true)) {
      captionerner_json_out(['ok' => false, 'message' => 'Invalid role.'], 400);
    }
    if ($newRole === 'admin' && !captionerner_is_google_domain_email($email)) {
      captionerner_json_out(['ok' => false, 'message' => 'Admin users must use a @' . captionerner_google_domain() . ' Google account.'], 400);
    }
    if ($testId !== null && !admin_test_exists($pdo, $testId)) {
      captionerner_json_out(['ok' => false, 'message' => 'Assigned test not found.'], 400);
    }

    $stmt = $pdo->prepare("
      INSERT INTO captionerner_users (email, role, is_active, test_id)
      VALUES (:email, :role, 1, :test_id)
      ON DUPLICATE KEY UPDATE
        role = VALUES(role),
        is_active = 1,
        test_id = VALUES(test_id),
        updated_at = NOW()
    ");
    $stmt->execute([
      ':email' => $email,
      ':role' => $newRole,
      ':test_id' => $testId,
    ]);

    captionerner_log_activity($pdo, 'admin_user_saved', 'Admin added/reactivated user', [
      'user_id' => $adminId,
      'user_email' => $adminEmail,
      'test_id' => $testId,
      'details' => ['target_email' => $email, 'role' => $newRole],
    ]);
    captionerner_json_out(['ok' => true]);
  }

  if ($action === 'users_update') {
    $id = (int)($body['user_id'] ?? 0);
    $newRole = trim((string)($body['role'] ?? 'captioner'));
    $isActive = !empty($body['is_active']) ? 1 : 0;
    $testId = isset($body['test_id']) && (int)$body['test_id'] > 0 ? (int)$body['test_id'] : null;
    if ($id <= 0) captionerner_json_out(['ok' => false, 'message' => 'Missing user_id.'], 400);
    if (!in_array($newRole, ['captioner', 'admin'], true)) captionerner_json_out(['ok' => false, 'message' => 'Invalid role.'], 400);
    if ($testId !== null && !admin_test_exists($pdo, $testId)) captionerner_json_out(['ok' => false, 'message' => 'Assigned test not found.'], 400);

    $stmt = $pdo->prepare('SELECT email FROM captionerner_users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $targetEmail = (string)($stmt->fetchColumn() ?: '');
    if ($targetEmail === '') captionerner_json_out(['ok' => false, 'message' => 'User not found.'], 404);
    if ($newRole === 'admin' && !captionerner_is_google_domain_email($targetEmail)) {
      captionerner_json_out(['ok' => false, 'message' => 'Admin users must use a @' . captionerner_google_domain() . ' Google account.'], 400);
    }
    if ($id === $adminId && $isActive === 0) {
      captionerner_json_out(['ok' => false, 'message' => 'You cannot deactivate your own active session.'], 400);
    }

    $stmt = $pdo->prepare('UPDATE captionerner_users SET role = :role, is_active = :active, test_id = :test_id, updated_at = NOW() WHERE id = :id LIMIT 1');
    $stmt->execute([
      ':role' => $newRole,
      ':active' => $isActive,
      ':test_id' => $testId,
      ':id' => $id,
    ]);

    captionerner_log_activity($pdo, 'admin_user_saved', 'Admin updated user', [
      'user_id' => $adminId,
      'user_email' => $adminEmail,
      'test_id' => $testId,
      'details' => ['target_email' => $targetEmail, 'role' => $newRole, 'is_active' => $isActive],
    ]);
    captionerner_json_out(['ok' => true]);
  }

  if ($action === 'users_bulk_assign_3play') {
    $testId = isset($body['test_id']) && (int)$body['test_id'] > 0 ? (int)$body['test_id'] : 0;
    if ($testId <= 0) captionerner_json_out(['ok' => false, 'message' => 'Choose a test first.'], 400);
    if (!admin_test_exists($pdo, $testId)) captionerner_json_out(['ok' => false, 'message' => 'Assigned test not found.'], 400);

    $domain = captionerner_google_domain();
    $domainLike = '%@' . addcslashes($domain, '\_%');

    $countStmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM captionerner_users
      WHERE LOWER(TRIM(email)) LIKE ? ESCAPE '\\\\'
    ");
    $countStmt->execute([$domainLike]);
    $matchedCount = (int)$countStmt->fetchColumn();

    $changeStmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM captionerner_users
      WHERE LOWER(TRIM(email)) LIKE ? ESCAPE '\\\\'
        AND (test_id IS NULL OR test_id <> ?)
    ");
    $changeStmt->execute([$domainLike, $testId]);
    $changedCount = (int)$changeStmt->fetchColumn();

    $updateStmt = $pdo->prepare("
      UPDATE captionerner_users
      SET test_id = ?, updated_at = NOW()
      WHERE LOWER(TRIM(email)) LIKE ? ESCAPE '\\\\'
        AND (test_id IS NULL OR test_id <> ?)
    ");
    $updateStmt->execute([$testId, $domainLike, $testId]);

    captionerner_log_activity($pdo, 'admin_users_bulk_assigned', 'Admin bulk assigned 3Play users', [
      'user_id' => $adminId,
      'user_email' => $adminEmail,
      'test_id' => $testId,
      'details' => [
        'domain' => $domain,
        'matched_count' => $matchedCount,
        'changed_count' => $changedCount,
      ],
    ]);

    captionerner_json_out([
      'ok' => true,
      'matched_count' => $matchedCount,
      'changed_count' => $changedCount,
    ]);
  }

  if ($action === 'users_deactivate' || $action === 'users_reactivate') {
    $id = (int)($body['user_id'] ?? 0);
    if ($id <= 0) captionerner_json_out(['ok' => false, 'message' => 'Missing user_id.'], 400);
    if ($id === $adminId && $action === 'users_deactivate') {
      captionerner_json_out(['ok' => false, 'message' => 'You cannot deactivate yourself.'], 400);
    }
    $active = ($action === 'users_reactivate') ? 1 : 0;
    $stmt = $pdo->prepare('UPDATE captionerner_users SET is_active = :active, updated_at = NOW() WHERE id = :id LIMIT 1');
    $stmt->execute([':active' => $active, ':id' => $id]);
    captionerner_json_out(['ok' => true]);
  }

  if ($action === 'metrics_users_rollup') {
    $limit = (int)($body['limit'] ?? 1000);
    if ($limit < 1) $limit = 1000;
    if ($limit > 5000) $limit = 5000;
    $filterTestId = isset($body['test_id']) ? (int)$body['test_id'] : 0;
    $sessionFilter = $filterTestId > 0 ? "AND test_id = {$filterTestId}" : '';
    $whereFilter = $filterTestId > 0
      ? "WHERE u.test_id = {$filterTestId} OR EXISTS (SELECT 1 FROM captionerner_assessments ax WHERE ax.user_id = u.id AND ax.test_id = {$filterTestId})"
      : '';

    $sql = "
      SELECT
        u.id AS user_id,
        u.email,
        u.role,
        u.is_active,
        u.test_id AS assigned_test_id,
        at.title AS assigned_test_title,
        u.created_at AS user_created_at,
        s.id AS session_id,
        s.test_id AS session_test_id,
        st.title AS session_test_title,
        s.created_at,
        COALESCE(s.start_clicked_at, started_event.created_at, s.video_started_at, s.video_ended_at) AS start_clicked_at,
        s.video_started_at,
        s.video_ended_at,
        s.completed_at,
        s.audio_tested AS audio_tested_before_start,
        s.audio_test_count,
        s.copy_count AS copy_count_before_start,
        s.focus_ms_before_start,
        s.wall_ms_before_start,
        s.tab_switches AS tab_hidden_count_before_start,
        CASE WHEN s.countdown_cancels > 0 THEN 1 ELSE 0 END AS countdown_cancelled
      FROM captionerner_users u
      LEFT JOIN captionerner_tests at ON at.id = u.test_id
      LEFT JOIN captionerner_assessments s
        ON s.id = (
          SELECT id
          FROM captionerner_assessments
          WHERE user_id = u.id {$sessionFilter}
          ORDER BY
            CASE
              WHEN video_ended_at IS NOT NULL OR completed_at IS NOT NULL THEN 5
              WHEN video_started_at IS NOT NULL THEN 4
              WHEN start_clicked_at IS NOT NULL THEN 3
              WHEN audio_tested = 1 OR audio_test_count > 0 THEN 2
              WHEN focus_ms_before_start > 0 OR wall_ms_before_start > 0 OR copy_count > 0 OR tab_switches > 0 THEN 1
              ELSE 0
            END DESC,
            created_at DESC,
            id DESC
          LIMIT 1
        )
      LEFT JOIN captionerner_tests st ON st.id = s.test_id
      LEFT JOIN captionerner_activity_log started_event
        ON started_event.id = (
          SELECT id
          FROM captionerner_activity_log
          WHERE assessment_id = s.id
            AND event_type = 'test_started'
          ORDER BY created_at ASC, id ASC
          LIMIT 1
        )
      {$whereFilter}
      ORDER BY u.email ASC
      LIMIT {$limit}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    captionerner_json_out(['ok' => true, 'rows' => $stmt->fetchAll()]);
  }

  if ($action === 'activity_list') {
    $limit = (int)($body['limit'] ?? 500);
    if ($limit < 1) $limit = 500;
    if ($limit > 2000) $limit = 2000;
    $filterTestId = isset($body['test_id']) ? (int)$body['test_id'] : 0;
    $where = $filterTestId > 0 ? "WHERE l.test_id = {$filterTestId}" : '';
    $stmt = $pdo->prepare("
      SELECT *
      FROM (
        SELECT
          l.id,
          l.user_email,
          l.test_id,
          t.title AS test_title,
          l.event_type,
          l.event_label,
          l.details_json,
          l.created_at
        FROM captionerner_activity_log l
        LEFT JOIN captionerner_tests t ON t.id = l.test_id
        {$where}
        ORDER BY l.created_at DESC, l.id DESC
        LIMIT {$limit}
      ) latest_events
      ORDER BY created_at ASC, id ASC
    ");
    $stmt->execute();
    captionerner_json_out(['ok' => true, 'events' => $stmt->fetchAll()]);
  }

  captionerner_json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  if ($e instanceof RuntimeException) {
    captionerner_json_out(['ok' => false, 'message' => $e->getMessage()], 400);
  }
  captionerner_json_out(['ok' => false, 'error' => 'Server error.', 'detail' => $e->getMessage()], 500);
}
