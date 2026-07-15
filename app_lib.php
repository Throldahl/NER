<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const CAPTIONERNER_GOOGLE_DOMAIN = '3playmedia.com';
const CAPTIONERNER_UPLOAD_DIR = 'uploads/ner-media';
const CAPTIONERNER_TEST_AUDIO_MAX_BYTES = 52428800; // 50 MB
const CAPTIONERNER_SOURCE_MEDIA_MAX_BYTES = 524288000; // 500 MB

if (!function_exists('str_ends_with')) {
  function str_ends_with(string $haystack, string $needle): bool {
    return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
  }
}

if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool {
    return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}

function captionerner_start_session(): void {
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  if (defined('SESSION_NAME')) {
    session_name(SESSION_NAME);
  }
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
}

function captionerner_json_out(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data);
  exit;
}

function captionerner_local_config(): array {
  static $config = null;
  if (is_array($config)) return $config;

  $config = [];
  $path = __DIR__ . '/configs/config.php';
  if (is_file($path)) {
    $loaded = require $path;
    if (is_array($loaded)) {
      $config = $loaded;
    }
  }

  return $config;
}

function captionerner_google_client_id(): string {
  if (defined('GOOGLE_CLIENT_ID')) {
    $clientId = trim((string)GOOGLE_CLIENT_ID);
    if ($clientId !== '') return $clientId;
  }

  $config = captionerner_local_config();
  return trim((string)($config['google_client_id'] ?? ($config['auth']['google_client_id'] ?? '')));
}

function captionerner_google_domain(): string {
  if (defined('GOOGLE_AUTH_REQUIRED_DOMAIN')) {
    $domain = strtolower(trim((string)GOOGLE_AUTH_REQUIRED_DOMAIN));
    if ($domain !== '') return $domain;
  }

  $config = captionerner_local_config();
  return strtolower(trim((string)($config['allowed_domain'] ?? ($config['auth']['allowed_domain'] ?? CAPTIONERNER_GOOGLE_DOMAIN))));
}

function captionerner_default_admin_email(): string {
  if (defined('DEFAULT_ADMIN_EMAIL')) {
    $email = strtolower(trim((string)DEFAULT_ADMIN_EMAIL));
    if ($email !== '') return $email;
  }

  $config = captionerner_local_config();
  return strtolower(trim((string)($config['default_admin_email'] ?? 'dthroldahl@3playmedia.com')));
}

function captionerner_is_google_domain_email(string $email): bool {
  $email = strtolower(trim($email));
  return str_ends_with($email, '@' . captionerner_google_domain());
}

function captionerner_is_default_admin_email(string $email): bool {
  return strtolower(trim($email)) === captionerner_default_admin_email();
}

function captionerner_table_exists(PDO $pdo, string $table): bool {
  try {
    $stmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

function captionerner_column_exists(PDO $pdo, string $table, string $column): bool {
  if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
  if (!captionerner_table_exists($pdo, $table)) return false;
  try {
    $stmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

function captionerner_index_exists(PDO $pdo, string $table, string $index): bool {
  if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
  if (!captionerner_table_exists($pdo, $table)) return false;
  try {
    $stmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND INDEX_NAME = ?
    ");
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

function captionerner_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): bool {
  if (captionerner_column_exists($pdo, $table, $column)) return false;
  $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
  return true;
}

function captionerner_add_index_if_missing(PDO $pdo, string $table, string $index, string $definition): void {
  if (captionerner_index_exists($pdo, $table, $index)) return;
  $pdo->exec("ALTER TABLE `{$table}` ADD {$definition}");
}

function captionerner_try_add_column(PDO $pdo, string $table, string $column, string $definition): void {
  try {
    captionerner_add_column_if_missing($pdo, $table, $column, $definition);
  } catch (Throwable $e) {
    error_log("captionerner could not add {$table}.{$column}: " . $e->getMessage());
  }
}

function captionerner_ensure_schema(PDO $pdo): void {
  static $done = false;
  if ($done) return;

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS captionerner_tests (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      slug VARCHAR(128) NOT NULL,
      title VARCHAR(255) NOT NULL,
      subtitle VARCHAR(255) NULL,
      instructions_html MEDIUMTEXT NOT NULL,
      prep_html MEDIUMTEXT NOT NULL,
      test_audio_media_id INT UNSIGNED NULL,
      source_media_id INT UNSIGNED NULL,
      created_by INT UNSIGNED NULL,
      updated_by INT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_captionerner_tests_slug (slug),
      KEY idx_captionerner_tests_deleted_at (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS captionerner_media (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      label VARCHAR(255) NOT NULL,
      file_path VARCHAR(512) NOT NULL,
      original_name VARCHAR(255) NOT NULL,
      mime_type VARCHAR(128) NULL,
      media_kind ENUM('audio','video') NOT NULL,
      usage_kind ENUM('test_audio','source') NOT NULL,
      size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
      is_builtin TINYINT(1) NOT NULL DEFAULT 0,
      created_by INT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_captionerner_media_path (file_path),
      KEY idx_captionerner_media_usage (usage_kind, media_kind, deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $usersTestColumnAdded = false;
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS captionerner_users (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      email VARCHAR(255) NOT NULL,
      role ENUM('captioner','admin') NOT NULL DEFAULT 'captioner',
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      password_hash VARCHAR(255) NULL,
      test_id INT UNSIGNED NULL,
      last_login_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_captionerner_users_email (email),
      KEY idx_captionerner_users_test_id (test_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
  captionerner_add_column_if_missing($pdo, 'captionerner_users', 'role', "role ENUM('captioner','admin') NOT NULL DEFAULT 'captioner'");
  captionerner_add_column_if_missing($pdo, 'captionerner_users', 'is_active', 'is_active TINYINT(1) NOT NULL DEFAULT 1');
  captionerner_add_column_if_missing($pdo, 'captionerner_users', 'password_hash', 'password_hash VARCHAR(255) NULL');
  $usersTestColumnAdded = captionerner_add_column_if_missing($pdo, 'captionerner_users', 'test_id', 'test_id INT UNSIGNED NULL');
  captionerner_add_column_if_missing($pdo, 'captionerner_users', 'last_login_at', 'last_login_at DATETIME NULL');
  captionerner_add_column_if_missing($pdo, 'captionerner_users', 'created_at', 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
  captionerner_add_column_if_missing($pdo, 'captionerner_users', 'updated_at', 'updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
  captionerner_add_index_if_missing($pdo, 'captionerner_users', 'idx_captionerner_users_test_id', 'KEY idx_captionerner_users_test_id (test_id)');

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS captionerner_assessments (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id INT UNSIGNED NOT NULL,
      test_id INT UNSIGNED NULL,
      assessment_slug VARCHAR(128) NOT NULL,
      client_session_id VARCHAR(128) NULL,
      login_at DATETIME NULL,
      gate_unlocked_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      start_clicked_at DATETIME NULL,
      video_started_at DATETIME NULL,
      video_ended_at DATETIME NULL,
      completed_at DATETIME NULL,
      started_email_sent_at DATETIME NULL,
      audio_tested TINYINT(1) NOT NULL DEFAULT 0,
      audio_test_count INT UNSIGNED NOT NULL DEFAULT 0,
      copy_count INT UNSIGNED NOT NULL DEFAULT 0,
      focus_ms_before_start BIGINT UNSIGNED NOT NULL DEFAULT 0,
      wall_ms_before_start BIGINT UNSIGNED NOT NULL DEFAULT 0,
      tab_switches INT UNSIGNED NOT NULL DEFAULT 0,
      countdown_cancels INT UNSIGNED NOT NULL DEFAULT 0,
      first_focus_ms INT NULL,
      user_agent VARCHAR(512) NULL,
      viewport_w INT NULL,
      viewport_h INT NULL,
      PRIMARY KEY (id),
      KEY idx_captionerner_assessments_user (user_id, created_at),
      KEY idx_captionerner_assessments_test (test_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
  foreach ([
    'user_id' => 'user_id INT UNSIGNED NULL',
    'test_id' => 'test_id INT UNSIGNED NULL',
    'assessment_slug' => "assessment_slug VARCHAR(128) NOT NULL DEFAULT 'default'",
    'client_session_id' => 'client_session_id VARCHAR(128) NULL',
    'login_at' => 'login_at DATETIME NULL',
    'gate_unlocked_at' => 'gate_unlocked_at DATETIME NULL',
    'created_at' => 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'start_clicked_at' => 'start_clicked_at DATETIME NULL',
    'video_started_at' => 'video_started_at DATETIME NULL',
    'video_ended_at' => 'video_ended_at DATETIME NULL',
    'completed_at' => 'completed_at DATETIME NULL',
    'started_email_sent_at' => 'started_email_sent_at DATETIME NULL',
    'audio_tested' => 'audio_tested TINYINT(1) NOT NULL DEFAULT 0',
    'audio_test_count' => 'audio_test_count INT UNSIGNED NOT NULL DEFAULT 0',
    'copy_count' => 'copy_count INT UNSIGNED NOT NULL DEFAULT 0',
    'focus_ms_before_start' => 'focus_ms_before_start BIGINT UNSIGNED NOT NULL DEFAULT 0',
    'wall_ms_before_start' => 'wall_ms_before_start BIGINT UNSIGNED NOT NULL DEFAULT 0',
    'tab_switches' => 'tab_switches INT UNSIGNED NOT NULL DEFAULT 0',
    'countdown_cancels' => 'countdown_cancels INT UNSIGNED NOT NULL DEFAULT 0',
    'first_focus_ms' => 'first_focus_ms INT NULL',
    'user_agent' => 'user_agent VARCHAR(512) NULL',
    'viewport_w' => 'viewport_w INT NULL',
    'viewport_h' => 'viewport_h INT NULL',
  ] as $column => $definition) {
    captionerner_add_column_if_missing($pdo, 'captionerner_assessments', $column, $definition);
  }
  captionerner_add_index_if_missing($pdo, 'captionerner_assessments', 'idx_captionerner_assessments_test', 'KEY idx_captionerner_assessments_test (test_id, created_at)');

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS captionerner_activity_log (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id INT UNSIGNED NULL,
      user_email VARCHAR(255) NULL,
      test_id INT UNSIGNED NULL,
      assessment_id INT UNSIGNED NULL,
      event_type VARCHAR(64) NOT NULL,
      event_label VARCHAR(255) NOT NULL,
      details_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_captionerner_activity_created (created_at),
      KEY idx_captionerner_activity_user (user_id, created_at),
      KEY idx_captionerner_activity_test (test_id, created_at),
      KEY idx_captionerner_activity_event (event_type, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $defaultTestId = captionerner_seed_default_test($pdo);
  captionerner_seed_default_admin($pdo, $defaultTestId);
  if ($usersTestColumnAdded && $defaultTestId > 0) {
    $stmt = $pdo->prepare('UPDATE captionerner_users SET test_id = :test_id WHERE test_id IS NULL');
    $stmt->execute([':test_id' => $defaultTestId]);
  }

  $pdo->prepare("UPDATE captionerner_users SET is_active = 0 WHERE email = 'throldahl@gmail.com'")->execute();

  $done = true;
}

function captionerner_seed_media(PDO $pdo, string $label, string $filePath, string $originalName, string $mimeType, string $mediaKind, string $usageKind, int $sizeBytes = 0): int {
  $stmt = $pdo->prepare("
    INSERT INTO captionerner_media
      (label, file_path, original_name, mime_type, media_kind, usage_kind, size_bytes, is_builtin)
    VALUES
      (:label, :file_path, :original_name, :mime_type, :media_kind, :usage_kind, :size_bytes, 1)
    ON DUPLICATE KEY UPDATE
      label = VALUES(label),
      original_name = VALUES(original_name),
      mime_type = VALUES(mime_type),
      media_kind = VALUES(media_kind),
      usage_kind = VALUES(usage_kind),
      is_builtin = 1,
      deleted_at = NULL
  ");
  $stmt->execute([
    ':label' => $label,
    ':file_path' => $filePath,
    ':original_name' => $originalName,
    ':mime_type' => $mimeType,
    ':media_kind' => $mediaKind,
    ':usage_kind' => $usageKind,
    ':size_bytes' => $sizeBytes,
  ]);

  $lookup = $pdo->prepare('SELECT id FROM captionerner_media WHERE file_path = ? LIMIT 1');
  $lookup->execute([$filePath]);
  return (int)($lookup->fetchColumn() ?: 0);
}

function captionerner_seed_default_test(PDO $pdo): int {
  $testAudioId = captionerner_seed_media($pdo, 'Default test audio', 'test-audio.mp3', 'test-audio.mp3', 'audio/mpeg', 'audio', 'test_audio', captionerner_file_size('test-audio.mp3'));
  $sourceAudioId = captionerner_seed_media($pdo, 'Winter 2026 NER audio', 'NERAudio.mp3', 'NERAudio.mp3', 'audio/mpeg', 'audio', 'source', captionerner_file_size('NERAudio.mp3'));
  captionerner_seed_media($pdo, 'Winter 2026 NER video', 'NERVideo.mp4', 'NERVideo.mp4', 'video/mp4', 'video', 'source', captionerner_file_size('NERVideo.mp4'));

  $instructions = '<p>Please set up for a StreamText event. Prep is to the right with a button below to test audio. When you are ready to begin, message the coordinators on Slack and ask them to start your StreamText session. Once you receive confirmation, you can continue.</p><p>Click Start Assessment to begin. You will see a 10-second countdown, then the audio will automatically play without the ability to pause or rewind.</p><p>Let the coordinators know when you finish. We will retrieve your transcript from StreamText.</p>';
  $prep = '<p><b>IDs:</b><br>&gt;&gt; Anastasia: (interviewer - will be the first speaker)<br>&gt;&gt; Nick: (male interviewee)<br>&gt;&gt; Sarah: (female interviewee)</p><p><b>Description:</b><br>Podcast interview with a cycling power-couple, talking about living and training together.</p><p><b>Terms:</b><br>Coach Franck<br>DTE<br>Hugo Barrette<br>keirin<br>Kirsti Lay<br>Megan Rapinoe<br>omnium<br><i>NOTE:</i> There is a term from our nulled list used in this segment (not in an offensive way, just as a description). Be prepared to write it.</p>';

  $stmt = $pdo->prepare("
    INSERT INTO captionerner_tests
      (slug, title, subtitle, instructions_html, prep_html, test_audio_media_id, source_media_id)
    VALUES
      ('winter_ner_2026', 'Winter 2026 Employee NER', 'Captioner NER testing - internal', :instructions, :prep, :test_audio_id, :source_audio_id)
    ON DUPLICATE KEY UPDATE
      test_audio_media_id = COALESCE(test_audio_media_id, VALUES(test_audio_media_id)),
      source_media_id = COALESCE(source_media_id, VALUES(source_media_id))
  ");
  $stmt->execute([
    ':instructions' => $instructions,
    ':prep' => $prep,
    ':test_audio_id' => $testAudioId ?: null,
    ':source_audio_id' => $sourceAudioId ?: null,
  ]);

  $lookup = $pdo->prepare("SELECT id FROM captionerner_tests WHERE slug = 'winter_ner_2026' LIMIT 1");
  $lookup->execute();
  return (int)($lookup->fetchColumn() ?: 0);
}

function captionerner_seed_default_admin(PDO $pdo, int $defaultTestId): void {
  $email = captionerner_default_admin_email();
  if ($email === '') return;

  $stmt = $pdo->prepare("
    INSERT INTO captionerner_users (email, role, is_active, test_id)
    VALUES (:email, 'admin', 1, :test_id)
    ON DUPLICATE KEY UPDATE
      role = 'admin',
      is_active = 1,
      test_id = COALESCE(test_id, VALUES(test_id))
  ");
  $stmt->execute([
    ':email' => $email,
    ':test_id' => $defaultTestId > 0 ? $defaultTestId : null,
  ]);
}

function captionerner_file_size(string $relativePath): int {
  $path = __DIR__ . '/' . ltrim($relativePath, '/');
  return is_file($path) ? (int)filesize($path) : 0;
}

function captionerner_sanitize_html(string $html): string {
  $html = trim($html);
  if ($html === '') return '';
  if ($html === strip_tags($html)) {
    return nl2br(htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
  }
  $html = preg_replace('/<\s*(script|style)[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? '';
  $html = strip_tags($html, '<p><div><br><b><strong><i><em><u><ul><ol><li>');
  $html = preg_replace('/<([a-z][a-z0-9]*)(?:\s+[^>]*)?>/i', '<$1>', $html) ?? '';
  $html = preg_replace('/<br><\/br>/i', '<br>', $html) ?? $html;
  return trim($html);
}

function captionerner_fetch_user(PDO $pdo, string $email): ?array {
  if (!captionerner_table_exists($pdo, 'captionerner_users')) return null;
  $roleColumn = captionerner_column_exists($pdo, 'captionerner_users', 'role') ? 'role' : "'captioner' AS role";
  $activeColumn = captionerner_column_exists($pdo, 'captionerner_users', 'is_active') ? 'is_active' : '1 AS is_active';
  $testColumn = captionerner_column_exists($pdo, 'captionerner_users', 'test_id') ? 'test_id' : 'NULL AS test_id';
  $stmt = $pdo->prepare("SELECT id, email, {$roleColumn}, {$activeColumn}, {$testColumn} FROM captionerner_users WHERE LOWER(TRIM(email)) = ? LIMIT 1");
  $stmt->execute([strtolower(trim($email))]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function captionerner_user_lookup_debug(PDO $pdo, string $email): array {
  $email = strtolower(trim($email));
  $debug = [
    'database' => null,
    'table_exists' => false,
    'columns' => [],
    'direct_count' => null,
    'normalized_count' => null,
    'row' => null,
  ];

  try {
    $debug['database'] = (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
  } catch (Throwable $e) {
    $debug['database'] = 'unavailable: ' . $e->getMessage();
  }

  $debug['table_exists'] = captionerner_table_exists($pdo, 'captionerner_users');
  if (!$debug['table_exists']) return $debug;

  try {
    $cols = $pdo->query('SHOW COLUMNS FROM captionerner_users')->fetchAll();
    $debug['columns'] = array_map(static fn($c) => (string)$c['Field'], $cols ?: []);
  } catch (Throwable $e) {
    $debug['columns'] = ['unavailable: ' . $e->getMessage()];
  }

  try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM captionerner_users WHERE email = ?');
    $stmt->execute([$email]);
    $debug['direct_count'] = (int)$stmt->fetchColumn();
  } catch (Throwable $e) {
    $debug['direct_count'] = 'error: ' . $e->getMessage();
  }

  try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM captionerner_users WHERE LOWER(TRIM(email)) = ?');
    $stmt->execute([$email]);
    $debug['normalized_count'] = (int)$stmt->fetchColumn();
  } catch (Throwable $e) {
    $debug['normalized_count'] = 'error: ' . $e->getMessage();
  }

  try {
    $roleColumn = captionerner_column_exists($pdo, 'captionerner_users', 'role') ? 'role' : "'captioner' AS role";
    $activeColumn = captionerner_column_exists($pdo, 'captionerner_users', 'is_active') ? 'is_active' : '1 AS is_active';
    $stmt = $pdo->prepare("
      SELECT id, email, CHAR_LENGTH(email) AS email_len, HEX(email) AS email_hex, {$roleColumn}, {$activeColumn}
      FROM captionerner_users
      WHERE LOWER(TRIM(email)) = ?
      LIMIT 1
    ");
    $stmt->execute([$email]);
    $debug['row'] = $stmt->fetch() ?: null;
  } catch (Throwable $e) {
    $debug['row'] = ['error' => $e->getMessage()];
  }

  return $debug;
}

function captionerner_bootstrap_default_admin(PDO $pdo, string $email): ?array {
  $email = strtolower(trim($email));
  if (!captionerner_is_default_admin_email($email)) return null;

  try {
    if (!captionerner_table_exists($pdo, 'captionerner_users')) {
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS captionerner_users (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          email VARCHAR(255) NOT NULL,
          role VARCHAR(32) NOT NULL DEFAULT 'captioner',
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          password_hash VARCHAR(255) NULL,
          test_id INT UNSIGNED NULL,
          last_login_at DATETIME NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uniq_captionerner_users_email (email),
          KEY idx_captionerner_users_test_id (test_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      ");
    }

    captionerner_try_add_column($pdo, 'captionerner_users', 'role', "role VARCHAR(32) NOT NULL DEFAULT 'captioner'");
    captionerner_try_add_column($pdo, 'captionerner_users', 'is_active', 'is_active TINYINT(1) NOT NULL DEFAULT 1');
    captionerner_try_add_column($pdo, 'captionerner_users', 'test_id', 'test_id INT UNSIGNED NULL');
    captionerner_try_add_column($pdo, 'captionerner_users', 'last_login_at', 'last_login_at DATETIME NULL');
    captionerner_try_add_column($pdo, 'captionerner_users', 'created_at', 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
    captionerner_try_add_column($pdo, 'captionerner_users', 'updated_at', 'updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');

    $hasRole = captionerner_column_exists($pdo, 'captionerner_users', 'role');
    $hasActive = captionerner_column_exists($pdo, 'captionerner_users', 'is_active');
    $hasTestId = captionerner_column_exists($pdo, 'captionerner_users', 'test_id');

    $lookup = $pdo->prepare('SELECT id FROM captionerner_users WHERE email = ? LIMIT 1');
    $lookup->execute([$email]);
    $existingId = (int)($lookup->fetchColumn() ?: 0);

    if ($existingId > 0) {
      $sets = [];
      $params = [':id' => $existingId];
      if ($hasRole) {
        $sets[] = "role = 'admin'";
      }
      if ($hasActive) {
        $sets[] = 'is_active = 1';
      }
      if ($hasTestId) {
        $sets[] = 'test_id = COALESCE(test_id, NULL)';
      }
      if ($sets) {
        $stmt = $pdo->prepare('UPDATE captionerner_users SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1');
        $stmt->execute($params);
      }
    } else {
      $columns = ['email'];
      $values = [':email'];
      $params = [':email' => $email];
      if ($hasRole) {
        $columns[] = 'role';
        $values[] = "'admin'";
      }
      if ($hasActive) {
        $columns[] = 'is_active';
        $values[] = '1';
      }
      if ($hasTestId) {
        $columns[] = 'test_id';
        $values[] = 'NULL';
      }
      $stmt = $pdo->prepare('INSERT INTO captionerner_users (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')');
      $stmt->execute($params);
    }

    return captionerner_fetch_user($pdo, $email);
  } catch (Throwable $e) {
    $GLOBALS['captionerner_bootstrap_error'] = $e->getMessage();
    error_log('captionerner default admin bootstrap failed: ' . $e->getMessage());
    return null;
  }
}

function captionerner_fetch_test(PDO $pdo, ?int $testId): ?array {
  if (!$testId) return null;
  if (!captionerner_table_exists($pdo, 'captionerner_tests') || !captionerner_table_exists($pdo, 'captionerner_media')) return null;
  $stmt = $pdo->prepare("
    SELECT
      t.id,
      t.slug,
      t.title,
      t.subtitle,
      t.instructions_html,
      t.prep_html,
      t.test_audio_media_id,
      t.source_media_id,
      ta.label AS test_audio_label,
      ta.file_path AS test_audio_url,
      ta.mime_type AS test_audio_mime,
      sm.label AS source_media_label,
      sm.file_path AS source_media_url,
      sm.mime_type AS source_media_mime,
      sm.media_kind AS source_media_kind
    FROM captionerner_tests t
    LEFT JOIN captionerner_media ta ON ta.id = t.test_audio_media_id AND ta.deleted_at IS NULL
    LEFT JOIN captionerner_media sm ON sm.id = t.source_media_id AND sm.deleted_at IS NULL
    WHERE t.id = ? AND t.deleted_at IS NULL
    LIMIT 1
  ");
  $stmt->execute([$testId]);
  $test = $stmt->fetch();
  return $test ?: null;
}

function captionerner_auth_payload(PDO $pdo, array $user): array {
  $test = captionerner_fetch_test($pdo, isset($user['test_id']) ? (int)$user['test_id'] : null);
  return [
    'ok' => true,
    'user' => [
      'id' => (int)$user['id'],
      'email' => $user['email'],
      'role' => $user['role'],
      'test_id' => isset($user['test_id']) ? (int)$user['test_id'] : null,
    ],
    'role' => $user['role'],
    'test' => $test,
    'google_client_id' => captionerner_google_client_id(),
    'google_required_domain' => captionerner_google_domain(),
  ];
}

function captionerner_set_login_session(PDO $pdo, array $user, string $authMethod): array {
  $_SESSION['user_email'] = $user['email'];
  $_SESSION['user_role'] = $user['role'];
  $_SESSION['user_id'] = (int)$user['id'];
  $_SESSION['test_id'] = isset($user['test_id']) ? (int)$user['test_id'] : null;
  $_SESSION['assessment_slug'] = defined('DEFAULT_ASSESSMENT_SLUG') ? DEFAULT_ASSESSMENT_SLUG : 'default';
  $_SESSION['auth_method'] = $authMethod;

  if (captionerner_column_exists($pdo, 'captionerner_users', 'last_login_at')) {
    $stmt = $pdo->prepare('UPDATE captionerner_users SET last_login_at = NOW() WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$user['id']]);
  }

  captionerner_log_activity($pdo, 'login', 'Logged in', [
    'user_id' => (int)$user['id'],
    'user_email' => $user['email'],
    'test_id' => isset($user['test_id']) ? (int)$user['test_id'] : null,
    'details' => ['auth_method' => $authMethod],
  ]);

  return captionerner_auth_payload($pdo, $user);
}

function captionerner_log_activity(PDO $pdo, string $eventType, string $eventLabel, array $opts = []): void {
  try {
    if (!captionerner_table_exists($pdo, 'captionerner_activity_log')) return;
    $details = $opts['details'] ?? null;
    $detailsJson = $details === null ? null : json_encode($details, JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare("
      INSERT INTO captionerner_activity_log
        (user_id, user_email, test_id, assessment_id, event_type, event_label, details_json)
      VALUES
        (:user_id, :user_email, :test_id, :assessment_id, :event_type, :event_label, :details_json)
    ");
    $stmt->execute([
      ':user_id' => isset($opts['user_id']) && $opts['user_id'] ? (int)$opts['user_id'] : null,
      ':user_email' => isset($opts['user_email']) ? (string)$opts['user_email'] : null,
      ':test_id' => isset($opts['test_id']) && $opts['test_id'] ? (int)$opts['test_id'] : null,
      ':assessment_id' => isset($opts['assessment_id']) && $opts['assessment_id'] ? (int)$opts['assessment_id'] : null,
      ':event_type' => $eventType,
      ':event_label' => $eventLabel,
      ':details_json' => $detailsJson,
    ]);
  } catch (Throwable $e) {
    error_log('captionerner activity log failed: ' . $e->getMessage());
  }
}

function captionerner_eastern_timestamp(): string {
  try {
    $dt = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
    return $dt->format('m/d/Y h:i:s A') . ' ET';
  } catch (Throwable $e) {
    return date('m/d/Y h:i:s A') . ' ET';
  }
}

function captionerner_send_assessment_started_email(PDO $pdo, int $assessmentId, int $userId, string $userEmail, int $testId): void {
  if ($assessmentId <= 0 || $userId <= 0 || $userEmail === '') return;
  if (!captionerner_column_exists($pdo, 'captionerner_assessments', 'started_email_sent_at')) return;

  try {
    $claim = $pdo->prepare("
      UPDATE captionerner_assessments
      SET started_email_sent_at = NOW()
      WHERE id = ?
        AND user_id = ?
        AND started_email_sent_at IS NULL
      LIMIT 1
    ");
    $claim->execute([$assessmentId, $userId]);
    if ($claim->rowCount() < 1) return;

    $test = captionerner_fetch_test($pdo, $testId);
    $testTitle = $test && !empty($test['title']) ? (string)$test['title'] : 'NER Assessment';
    $timestamp = captionerner_eastern_timestamp();
    $line = "[{$timestamp}] {$userEmail} started {$testTitle}." . PHP_EOL;

    $to = defined('NER_STARTED_EMAIL_TO')
      ? (string)NER_STARTED_EMAIL_TO
      : 'dthroldahl@3playmedia.com, mmclaren@3playmedia.com';
    $from = defined('NER_STARTED_EMAIL_FROM')
      ? (string)NER_STARTED_EMAIL_FROM
      : 'Derek@dereksprojects.com';
    $subject = 'NER Assessment Started: ' . $testTitle;
    $headers = "From: {$from}\r\nReply-To: {$from}";

    @file_put_contents(__DIR__ . '/feedback.txt', $line, FILE_APPEND | LOCK_EX);
    $sent = @mail($to, $subject, $line, $headers);
    if (!$sent) {
      $reset = $pdo->prepare('UPDATE captionerner_assessments SET started_email_sent_at = NULL WHERE id = ? LIMIT 1');
      $reset->execute([$assessmentId]);
      error_log('captionerner started email failed for assessment ' . $assessmentId);
    }
  } catch (Throwable $e) {
    error_log('captionerner started email error: ' . $e->getMessage());
  }
}

function captionerner_verify_google_token(string $credential): array {
  $clientId = captionerner_google_client_id();
  if ($clientId === '') {
    throw new RuntimeException('Google Sign-In is not configured yet.');
  }
  if ($credential === '') {
    throw new RuntimeException('Missing Google credential.');
  }

  $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($credential);
  $json = false;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 8,
      CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $json = curl_exec($ch);
    curl_close($ch);
  } else {
    $ctx = stream_context_create(['http' => ['timeout' => 8]]);
    $json = @file_get_contents($url, false, $ctx);
  }

  if (!is_string($json) || $json === '') {
    throw new RuntimeException('Unable to verify Google Sign-In.');
  }

  $payload = json_decode($json, true);
  if (!is_array($payload)) {
    throw new RuntimeException('Invalid Google verification response.');
  }

  if (($payload['aud'] ?? '') !== $clientId) {
    throw new RuntimeException('Google credential audience does not match this site.');
  }

  $email = strtolower(trim((string)($payload['email'] ?? '')));
  $verified = $payload['email_verified'] ?? false;
  $verified = $verified === true || $verified === 'true' || $verified === '1' || $verified === 1;
  if ($email === '' || !$verified) {
    throw new RuntimeException('Google email is not verified.');
  }
  if (!captionerner_is_google_domain_email($email)) {
    throw new RuntimeException('Use your @' . captionerner_google_domain() . ' Google account.');
  }

  return [
    'email' => $email,
    'name' => $payload['name'] ?? '',
    'picture' => $payload['picture'] ?? '',
    'hd' => $payload['hd'] ?? '',
  ];
}

function captionerner_allowed_uploads(string $usageKind): array {
  if ($usageKind === 'test_audio') {
    return [
      'extensions' => ['mp3', 'wav', 'm4a', 'aac'],
      'max_bytes' => CAPTIONERNER_TEST_AUDIO_MAX_BYTES,
    ];
  }
  return [
    'extensions' => ['mp3', 'wav', 'm4a', 'aac', 'mp4', 'mov', 'webm'],
    'max_bytes' => CAPTIONERNER_SOURCE_MEDIA_MAX_BYTES,
  ];
}

function captionerner_ini_bytes(string $value): int {
  $value = trim($value);
  if ($value === '') return 0;

  $unit = strtolower(substr($value, -1));
  $number = (float)$value;
  switch ($unit) {
    case 'g':
      $number *= 1024;
      // no break
    case 'm':
      $number *= 1024;
      // no break
    case 'k':
      $number *= 1024;
      break;
  }

  return (int)$number;
}

function captionerner_format_bytes(int $bytes): string {
  if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
  if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
  if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
  return $bytes . ' B';
}

function captionerner_upload_error_message(int $errorCode): string {
  $uploadMax = captionerner_ini_bytes((string)ini_get('upload_max_filesize'));
  $postMax = captionerner_ini_bytes((string)ini_get('post_max_size'));
  $limits = array_values(array_filter([$uploadMax, $postMax], static fn($v) => $v > 0));
  $serverLimit = $limits ? min($limits) : max($uploadMax, $postMax);
  $serverLimitText = $serverLimit > 0 ? captionerner_format_bytes((int)$serverLimit) : 'the server limit';

  switch ($errorCode) {
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
      return 'The uploaded file exceeds the server upload limit of ' . $serverLimitText . '. Increase Hostinger/PHP upload limits or choose a smaller file.';
    case UPLOAD_ERR_PARTIAL:
      return 'The upload only partially completed. Please try again.';
    case UPLOAD_ERR_NO_FILE:
      return 'Choose a media file to upload.';
    case UPLOAD_ERR_NO_TMP_DIR:
      return 'The server is missing a temporary upload folder.';
    case UPLOAD_ERR_CANT_WRITE:
      return 'The server could not write the uploaded file.';
    case UPLOAD_ERR_EXTENSION:
      return 'A server extension stopped the upload.';
    default:
      return 'Upload failed. Check the file size and try again.';
  }
}

function captionerner_media_kind_from_extension(string $ext): string {
  return in_array($ext, ['mp4', 'mov', 'webm'], true) ? 'video' : 'audio';
}

function captionerner_generate_slug(PDO $pdo, string $title, ?int $existingId = null): string {
  $base = strtolower(trim($title));
  $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? 'test';
  $base = trim($base, '-');
  if ($base === '') $base = 'test';

  $slug = $base;
  $i = 2;
  while (true) {
    $sql = 'SELECT id FROM captionerner_tests WHERE slug = ?';
    $params = [$slug];
    if ($existingId) {
      $sql .= ' AND id <> ?';
      $params[] = $existingId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if (!$stmt->fetch()) return $slug;
    $slug = $base . '-' . $i;
    $i += 1;
  }
}

function captionerner_media_valid_for_usage(PDO $pdo, int $mediaId, string $usageKind): bool {
  if ($mediaId <= 0) return true;
  $stmt = $pdo->prepare('SELECT id FROM captionerner_media WHERE id = ? AND usage_kind = ? AND deleted_at IS NULL LIMIT 1');
  $stmt->execute([$mediaId, $usageKind]);
  return (bool)$stmt->fetch();
}

function captionerner_upload_base_dir(): string {
  return __DIR__ . '/' . CAPTIONERNER_UPLOAD_DIR;
}

function captionerner_public_upload_path(string $filename): string {
  return CAPTIONERNER_UPLOAD_DIR . '/' . ltrim($filename, '/');
}

function captionerner_ensure_upload_dir(): void {
  $dir = captionerner_upload_base_dir();
  if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
  }
  $htaccess = dirname($dir) . '/.htaccess';
  if (!is_file($htaccess)) {
    file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|phar|phtml)$\">\n  Require all denied\n</FilesMatch>\n");
  }
}

function captionerner_store_upload(PDO $pdo, array $file, string $usageKind, string $label, int $createdBy): int {
  if (!in_array($usageKind, ['test_audio', 'source'], true)) {
    throw new RuntimeException('Invalid media usage.');
  }
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new RuntimeException(captionerner_upload_error_message((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)));
  }

  $original = (string)($file['name'] ?? '');
  $size = (int)($file['size'] ?? 0);
  $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
  if ($usageKind === 'test_audio' && in_array($ext, ['mp4', 'mov', 'webm'], true)) {
    $usageKind = 'source';
  }
  $rules = captionerner_allowed_uploads($usageKind);
  if (!in_array($ext, $rules['extensions'], true)) {
    throw new RuntimeException('Unsupported file type for this media use.');
  }
  if ($size <= 0 || $size > (int)$rules['max_bytes']) {
    throw new RuntimeException('File is too large for this upload type. Limit: ' . captionerner_format_bytes((int)$rules['max_bytes']) . '.');
  }

  captionerner_ensure_upload_dir();

  $safeBase = bin2hex(random_bytes(12)) . '.' . $ext;
  $target = captionerner_upload_base_dir() . '/' . $safeBase;
  if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
    throw new RuntimeException('Could not store uploaded file.');
  }

  $mime = null;
  if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
      $mime = finfo_file($finfo, $target) ?: null;
      finfo_close($finfo);
    }
  }

  $mediaKind = captionerner_media_kind_from_extension($ext);
  $displayLabel = trim($label) !== '' ? trim($label) : pathinfo($original, PATHINFO_FILENAME);
  $stmt = $pdo->prepare("
    INSERT INTO captionerner_media
      (label, file_path, original_name, mime_type, media_kind, usage_kind, size_bytes, is_builtin, created_by)
    VALUES
      (:label, :file_path, :original_name, :mime_type, :media_kind, :usage_kind, :size_bytes, 0, :created_by)
  ");
  $stmt->execute([
    ':label' => $displayLabel,
    ':file_path' => captionerner_public_upload_path($safeBase),
    ':original_name' => $original,
    ':mime_type' => $mime,
    ':media_kind' => $mediaKind,
    ':usage_kind' => $usageKind,
    ':size_bytes' => $size,
    ':created_by' => $createdBy,
  ]);

  return (int)$pdo->lastInsertId();
}
