<?php
/**
 * includes/settings.php
 *
 * Lightweight settings helper for sitedeliveries.site
 * -----------------------------------------------------------
 * - Stores key/value pairs in `app_settings`.
 * - Safe getters/setters with in-request caching.
 * - Normalises & validates Time Window settings for the UI.
 * - Adds helpers for weather + modal QR feature flags.
 *
 * Usage (examples):
 *   require_once __DIR__ . '/settings.php';
 *   // Time window
 *   $conf = get_time_settings($pdo); // ['start'=>'06:00','end'=>'18:00','interval'=>20]
 *   // Weather & modal QR (strings '0'/'1' etc.)
 *   $cfg  = get_settings($pdo, [
 *     'weather_enabled' => '0',
 *     'weather_lat'     => '',
 *     'weather_lon'     => '',
 *     'weather_api_key' => '',
 *     'modal_qr_enabled'=> '1',
 *   ]);
 *
 * Notes:
 * - Assumes a PDO instance ($pdo) exists (from your root db.php).
 * - If `app_settings` table is missing, it will be created automatically.
 */

declare(strict_types=1);

/** Ensure the app_settings table exists (runs once per request at first call). */
function ensure_app_settings_table(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $sql = "
        CREATE TABLE IF NOT EXISTS app_settings (
          `key` VARCHAR(64) PRIMARY KEY,
          `value` TEXT NOT NULL,
          updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $pdo->exec($sql);
    $done = true;
}

/** Get a single setting by key with a default (cached per-request). */
function get_setting(PDO $pdo, string $key, string $default = ''): string
{
    ensure_app_settings_table($pdo);

    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();

    $cache[$key] = ($val !== false) ? (string)$val : $default;
    return $cache[$key];
}

/** Set (insert or update) a setting value by key. Returns true on success. */
function set_setting(PDO $pdo, string $key, string $value): bool
{
    ensure_app_settings_table($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO app_settings (`key`, `value`)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ");
    $ok = $stmt->execute([$key, $value]);

    // Update in-request cache
    static $cache = [];
    $cache[$key] = $value;

    return $ok;
}

/**
 * Get multiple settings at once. Missing keys use provided defaults.
 * @param array $map ['time_window_start' => '06:00', 'time_window_end' => '18:00']
 * @return array same shape as $map with resolved values
 */
function get_settings(PDO $pdo, array $map): array
{
    ensure_app_settings_table($pdo);
    if (!$map) return [];

    $keys = array_keys($map);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT `key`, `value` FROM app_settings WHERE `key` IN ($placeholders)");
    $stmt->execute($keys);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // ['key' => 'value']

    static $cache = [];
    foreach ($map as $k => $def) {
        $cache[$k] = array_key_exists($k, $rows) ? (string)$rows[$k] : (string)$def;
    }
    return array_intersect_key($cache, $map);
}

/** Validate "HH:MM" 24h time. */
function is_valid_hhmm(string $t): bool
{
    if (!preg_match('/^\d{2}:\d{2}$/', $t)) return false;
    [$h, $m] = array_map('intval', explode(':', $t));
    return $h >= 0 && $h <= 23 && $m >= 0 && $m <= 59;
}

/** Compare two "HH:MM" times (-1,0,1). */
function compare_hhmm(string $a, string $b): int
{
    [$ah, $am] = array_map('intval', explode(':', $a));
    [$bh, $bm] = array_map('intval', explode(':', $b));
    $ai = $ah * 60 + $am;
    $bi = $bh * 60 + $bm;
    return $ai <=> $bi;
}

/**
 * Normalised Time Window settings.
 * Returns: ['start' => 'HH:MM', 'end' => 'HH:MM', 'interval' => int]
 */
function get_time_settings(PDO $pdo): array
{
    $defaults = [
        'time_window_start'      => '06:00',
        'time_window_end'        => '18:00',
        'time_interval_minutes'  => '20',
    ];

    $settings = get_settings($pdo, $defaults);

    $start = $settings['time_window_start'] ?? $defaults['time_window_start'];
    $end   = $settings['time_window_end']   ?? $defaults['time_window_end'];
    $step  = (int)($settings['time_interval_minutes'] ?? $defaults['time_interval_minutes']);

    if (!is_valid_hhmm($start)) $start = $defaults['time_window_start'];
    if (!is_valid_hhmm($end))   $end   = $defaults['time_window_end'];
    if (compare_hhmm($start, $end) >= 0) { $start = $defaults['time_window_start']; $end = $defaults['time_window_end']; }
    if ($step < 5) $step = 5; if ($step > 720) $step = 720;

    return ['start'=>$start, 'end'=>$end, 'interval'=>$step];
}

/**
 * Echo time settings to JS constants safely:
 *   const WINDOW_START = "06:00";
 *   const WINDOW_END = "18:00";
 *   const SLOT_INTERVAL_MINUTES = 20;
 */
function echo_time_settings_js(PDO $pdo): void
{
    $ts = get_time_settings($pdo);
    $start = htmlspecialchars($ts['start'], ENT_QUOTES, 'UTF-8');
    $end   = htmlspecialchars($ts['end'],   ENT_QUOTES, 'UTF-8');
    $step  = (int)$ts['interval'];
    echo "const WINDOW_START = \"{$start}\";\n";
    echo "const WINDOW_END = \"{$end}\";\n";
    echo "const SLOT_INTERVAL_MINUTES = {$step};\n";
}

/** Seed a batch of defaults if keys are missing (idempotent). */
function ensure_defaults(PDO $pdo, array $defaults): void
{
    ensure_app_settings_table($pdo);
    if (!$defaults) return;

    // Insert any missing keys in one pass
    $stmt = $pdo->prepare("INSERT IGNORE INTO app_settings (`key`, `value`) VALUES (?, ?)");
    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, (string)$v]);
    }
}

/** Back-compat: seed just the time defaults. */
function ensure_time_defaults(PDO $pdo): void
{
    ensure_defaults($pdo, [
        'time_window_start'      => '06:00',
        'time_window_end'        => '18:00',
        'time_interval_minutes'  => '20',
    ]);
}

/**
 * Convenience seeder for the whole Deliveries app:
 * - time window keys
 * - weather keys
 * - modal QR toggle
 */
function ensure_delivery_defaults(PDO $pdo): void
{
    ensure_defaults($pdo, [
        'time_window_start'      => '06:00',
        'time_window_end'        => '18:00',
        'time_interval_minutes'  => '20',
        'weather_enabled'        => '0',
        'weather_lat'            => '',
        'weather_lon'            => '',
        'weather_api_key'        => '',
        'modal_qr_enabled'       => '1',
    ]);
}
