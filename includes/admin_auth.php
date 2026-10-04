<?php
// /includes/admin_auth.php
declare(strict_types=1);

/**
 * Shared admin helpers:
 * - login / logout / session
 * - CSRF utilities
 * - role/guard helpers
 * - tiny HTML escaper
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const ADMIN_TABLE = 'admins'; // DB table name

/** Escape HTML */
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Strong session guard for admin pages */
function admin_require(PDO $pdo): void {
    if (!isset($_SESSION['admin_id']) || !is_int($_SESSION['admin_id']) || $_SESSION['admin_id'] <= 0) {
        header('Location: /admin/login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/admin/'));
        exit;
    }
    // Optional: refresh your admin record into cache
    $me = admin_current($pdo);
    if (!$me || !(int)$me['is_active']) {
        admin_logout();
        header('Location: /admin/login.php');
        exit;
    }
}

/** Return current admin row or null */
function admin_current(PDO $pdo): ?array {
    if (!isset($_SESSION['admin_id']) || !is_int($_SESSION['admin_id'])) return null;
    $st = $pdo->prepare("SELECT id, email, password_hash, role, is_active, created_at, last_login
                         FROM ".ADMIN_TABLE." WHERE id = ?");
    $st->execute([$_SESSION['admin_id']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Attempt login; returns admin row on success, null on failure */
function admin_login(PDO $pdo, string $email, string $password): ?array {
    $st = $pdo->prepare("SELECT id, email, password_hash, role, is_active
                         FROM ".ADMIN_TABLE." WHERE email = ?");
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !(int)$row['is_active']) return null;

    if (!password_verify($password, (string)$row['password_hash'])) return null;

    // OK: set session
    $_SESSION['admin_id'] = (int)$row['id'];

    // record last_login
    $upd = $pdo->prepare("UPDATE ".ADMIN_TABLE." SET last_login = NOW() WHERE id = ?");
    $upd->execute([(int)$row['id']]);

    return $row;
}

/** Log out */
function admin_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/** Optional: force HTTPS inside /admin */
function admin_force_https(): void {
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        $target = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        header('Location: ' . $target, true, 301);
        exit;
    }
}

/** CSRF token helpers */
function admin_csrf_token(): string {
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf'];
}
function admin_csrf_check(?string $token): bool {
    return is_string($token) && isset($_SESSION['admin_csrf']) && hash_equals($_SESSION['admin_csrf'], $token);
}

/** Role helpers */
function admin_is_owner(PDO $pdo): bool {
    $me = admin_current($pdo);
    return $me && strtolower((string)$me['role']) === 'owner';
}
function admin_is_admin(PDO $pdo): bool {
    $me = admin_current($pdo);
    if (!$me) return false;
    $r = strtolower((string)$me['role']);
    return $r === 'admin' || $r === 'owner';
}
