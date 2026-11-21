<?php
/** Optional Central SSO. Native sessions remain application owned. */
declare(strict_types=1);

function ratSecure(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function ratCookiePath(): string
{
    return '/' . basename(dirname(__DIR__));
}

function ratAuthContext(): string
{
    return session_name() === 'RAT_STAFF_SSO' ? 'central' : 'native';
}

function ratAuthMode(string $mode): void
{
    setcookie('RAT_AUTH_MODE', $mode, ['path' => ratCookiePath(), 'secure' => ratSecure(),
        'httponly' => true, 'samesite' => 'Lax']);
}

function ratUrl(string $path): string
{
    if (str_starts_with($path, '?')) $path = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? 'index.php')) . $path;
    $page = parse_url($path, PHP_URL_PATH);
    if (!in_array($page, ratEntryPoints(), true) && $page !== 'login.php') return $path;
    parse_str((string)parse_url($path, PHP_URL_QUERY), $query);
    $query['rat_auth'] = $page === 'login.php' ? 'native' : ratAuthContext();
    $fragment = parse_url($path, PHP_URL_FRAGMENT);
    return $page . '?' . http_build_query($query) . (is_string($fragment) ? '#' . $fragment : '');
}

function ratEntryPoints(): array
{
    return ['ModelData.php', 'production.php', 'dashboard.php', 'monthly.php', 'history.php',
        'users.php', 'browse.php', 'workspace.php', 'logout.php'];
}

function ratAuthField(): string
{
    return '<input type="hidden" name="rat_auth" value="' . ratAuthContext() . '">';
}

function ratRemoteRoot(): ?string
{
    $configured = getenv('RAT_REMOTE_ROOT');
    if (is_string($configured) && $configured !== '') return rtrim($configured, '/\\');
    $root = dirname(__DIR__, 3) . '/nbc-bridges';
    return is_file($root . '/config.local.php') ? $root : null;
}

function ratCentralRuntime(): string
{
    if (is_file(dirname(__DIR__) . '/.nbc-central-disabled')) throw new RuntimeException('Central sign-in is disabled.');
    $remote = ratRemoteRoot();
    $root = $remote ?? (getenv('RAT_CENTRAL_ROOT') ?: dirname(__DIR__, 2) . '/nbc-central');
    $files = $remote !== null ? ['runtime.php'] : ['app/runtime.php', 'integrations/session.php'];
    $GLOBALS['nbc_application_key'] = 'rationalization';
    foreach ($files as $file) {
        if (!is_file($root . '/' . $file)) throw new RuntimeException('Central connector is unavailable.');
        require_once $root . '/' . $file;
    }
    if ((\NbcCentral\applications()['rationalization']['cookie_path'] ?? '') !== ratCookiePath()) {
        throw new RuntimeException('Central application path does not match this installation.');
    }
    return $remote !== null ? 'remote' : 'local';
}

function ratStartCentral(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) throw new RuntimeException('A session already started before staff SSO.');
    $storage = session_save_path();
    if (str_contains($storage, ';')) $storage = substr($storage, strrpos($storage, ';') + 1);
    $storage = rtrim($storage !== '' ? $storage : sys_get_temp_dir(), '/\\') . '/rat-staff-sso-v1';
    if (!is_dir($storage) && !@mkdir($storage, 0700, true) && !is_dir($storage)) throw new RuntimeException('Staff SSO storage is unavailable.');
    ini_set('session.save_handler', 'files');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_save_path($storage);
    session_name('RAT_STAFF_SSO');
    $cookie = $_COOKIE['RAT_STAFF_SSO'] ?? '';
    session_id(is_string($cookie) && preg_match('/^[A-Za-z0-9,-]{1,256}$/D', $cookie) ? $cookie : '');
    session_set_cookie_params(['lifetime' => 0, 'path' => ratCookiePath(), 'secure' => ratSecure(),
        'httponly' => true, 'samesite' => 'Lax']);
    if (!session_start()) throw new RuntimeException('Staff SSO session is unavailable.');
}

function ratEndCentral(): void
{
    if (session_status() === PHP_SESSION_ACTIVE && session_name() === 'RAT_STAFF_SSO') {
        $_SESSION = [];
        session_destroy();
    }
    setcookie('RAT_STAFF_SSO', '', ['expires' => time() - 3600, 'path' => ratCookiePath(),
        'secure' => ratSecure(), 'httponly' => true, 'samesite' => 'Lax']);
    ratAuthMode('native');
}

function ratCentralLogoutTarget(): string
{
    // Written only after a validated handoff; no user-supplied return URL.
    $target = $_SESSION['rat_central_return_url'] ?? '';
    if (is_string($target) && preg_match('~^/(?!/)[^\\\\\x00-\x20\x7f]*$~D', $target)) return $target;
    if (($_SESSION['rat_central_transport'] ?? '') === 'remote' && is_string($target)
        && filter_var($target, FILTER_VALIDATE_URL) && !preg_match('~[\\\\\x00-\x20\x7f]~', $target)) {
        $parts = parse_url($target);
        if (($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
            && str_ends_with($parts['path'] ?? '', '/index.php')) return $target;
    }
    return ratCookiePath() . '/login.php';
}

function ratCentralFailure(Throwable $error, string $phase, int $status = 503): never
{
    $transport = $error instanceof \NbcCentral\BridgeApiException
        ? ' curl=' . $error->curlNumber . ' http=' . $error->httpStatus . ' tls=' . $error->tlsResult : '';
    error_log('Rationalization SSO ' . $phase . ' failed (' . get_class($error) . ', '
        . basename($error->getFile()) . ':' . $error->getLine() . ').' . $transport);
    ratEndCentral();
    http_response_code($status);
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=utf-8');
    $base = htmlspecialchars(ratCookiePath() . '/', ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Rationalization sign-in</title>'
        . '<main><h1>Rationalization SSO could not be validated</h1><p>Open Rationalization again from NBC Central, or use direct access.</p>'
        . '<a href="' . $base . 'login.php">Staff login</a> '
        . '<a href="' . $base . 'index.php?rat_auth=native">Direct access</a></main></html>';
    exit;
}

function ratCallbackRequest(): bool
{
    return strcasecmp(str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? '')),
        str_replace('\\', '/', dirname(__DIR__)) . '/central-sso.php') === 0;
}

function ratStaffRequest(): bool
{
    $file = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    return strcasecmp(dirname($file), str_replace('\\', '/', dirname(__DIR__))) === 0
        && in_array(strtolower(basename($file)), array_map('strtolower', ratEntryPoints()), true);
}

function ratSelectStaffSession(): void
{
    // Native login/setup and CLI scripts never load the Central connector.
    if (!ratStaffRequest() || basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'login.php') return;
    $context = $_GET['rat_auth'] ?? $_POST['rat_auth'] ?? $_COOKIE['RAT_AUTH_MODE'] ?? 'native';
    if ($context !== 'central') return;
    try {
        ratStartCentral();
        header('Cache-Control: no-store');
        if (strtolower(basename((string)$_SERVER['SCRIPT_FILENAME'])) === 'logout.php') return;
        if (!is_array($_SESSION['nbc_central_identity'] ?? null)) {
            ratCentralFailure(new RuntimeException('Staff sign-in expired.'), 'identity', 403);
        }
    } catch (Throwable $error) {
        ratCentralFailure($error, 'session');
    }
}

function ratRefreshCentralAccount(string $username): void
{
    $stmt = db()->prepare('SELECT TOP (1) id, username, full_name, role, user_process FROM dbo.users WHERE username = ? AND is_active = 1');
    $stmt->execute([$username]);
    $account = $stmt->fetch();
    if (!$account || !in_array($account['role'] ?? '', ['admin', 'user'], true)) {
        throw new RuntimeException('Mapped active Rationalization account is unavailable.');
    }
    $_SESSION['user'] = ['id' => (int)$account['id'], 'username' => $account['username'],
        'full_name' => $account['full_name'], 'role' => $account['role'], 'user_process' => (string)($account['user_process'] ?? '')];
    if (!accessible_processes()) throw new RuntimeException('No production processes are assigned.');
}

function ratCentralLanding(): string
{
    $allowed = accessible_processes();
    if (!$allowed) throw new RuntimeException('No production processes are assigned.');
    return ratUrl('ModelData.php?process=' . rawurlencode((string)array_key_first($allowed)));
}

function ratRefreshStaffSession(): void
{
    if (ratAuthContext() !== 'central' || ratCallbackRequest()) return;
    if (strtolower(basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) === 'logout.php') {
        $target = ratCentralLogoutTarget();
        if (current_user()) {
            try { audit_log('LOGOUT', 'user', (int)current_user()['id'], 'NBC Central application sign-out'); }
            catch (Throwable $error) { error_log('Rationalization SSO logout audit unavailable.'); }
        }
        ratEndCentral();
        header('Location: ' . $target, true, 303);
        exit;
    }
    $phase = 'runtime';
    try {
        $identity = $_SESSION['nbc_central_identity'];
        $transport = ratCentralRuntime();
        if ($transport !== ($_SESSION['rat_central_transport'] ?? '')) throw new RuntimeException('Connector changed.');
        if ($transport === 'remote') {
            $phase = 'https';
            \NbcCentral\bridgeRequireHttps();
        }
        $phase = 'grant';
        if (!\NbcCentral\centralIdentityIsValid($identity, 'rationalization')) {
            ratCentralFailure(new RuntimeException('Central access revoked.'), $phase, 403);
        }
        $phase = 'account';
        ratRefreshCentralAccount((string)$identity['local_username']);
    } catch (Throwable $error) {
        ratCentralFailure($error, $phase, $phase === 'https' || $phase === 'account' ? 403 : 503);
    }
}
