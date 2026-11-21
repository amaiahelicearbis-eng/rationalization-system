<?php
// NBC Central managed bridge
// Native password authentication and Central SSO have separate session stores.
$GLOBALS['nbc_application_key'] = 'rationalization';
$nbcDualPath = '/rationalization-system';
$nbcDualLogin = $nbcDualPath . '/login.php';
$GLOBALS['nbc_native_login_url'] = $nbcDualLogin;
$nbcDualFile = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
$nbcDualRoot = str_replace('\\', '/', __DIR__);
$nbcDualRelative = strtolower(substr($nbcDualFile, strlen($nbcDualRoot) + 1));
// Detaching SSO must also discard cached Central sessions. This marker is
// respected by installers and works without any Central source files.
$nbcDualNativeOnly = is_file(__DIR__ . '/.nbc-central-disabled');
if (session_status() === PHP_SESSION_ACTIVE) session_abort();
session_id('');
if (strcasecmp($nbcDualFile, $nbcDualRoot . '/central-sso.php') === 0) return;
// Keep NBCAssess participant/quiz routes and CertificationBadge QC independent.
if ('rationalization' === 'nbcaccess' && !str_starts_with($nbcDualRelative, 'public/admin/')) return;
if ('rationalization' === 'certification-badge' && str_starts_with($nbcDualRelative, 'qc/')) return;
$nbcDualNativeRoute = in_array($nbcDualRelative, array ('login.php', 'logout.php'), true);
$nbcDualQuery = $_GET[''] ?? '';
if ($nbcDualRelative === '' && is_string($nbcDualQuery)
    && in_array(strtolower($nbcDualQuery), array (), true)) $nbcDualNativeRoute = true;
$nbcDualSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$nbcDualSsoName = 'NBC_APP_RATIONALIZATION';
if ('rationalization' === 'paperless' && $nbcDualNativeOnly && basename(__DIR__) === 'paperless') {
    // Retire the previous narrower cookie so CNC/Office do not select different
    // sessions when browsers send both cookies with the same name.
    foreach ([$nbcDualSsoName, 'NBC_NATIVE_PAPERLESS'] as $nbcDualOldCookie) {
        setcookie($nbcDualOldCookie, '', ['expires' => time() - 3600, 'path' => $nbcDualPath . '/office', 'secure' => $nbcDualSecure, 'httponly' => true, 'samesite' => 'Lax']);
    }
}
// A prepend handler owns session selection before native bootstraps run.
$nbcDualStart = static function (string $name) use ($nbcDualPath, $nbcDualSecure): void {
    ini_set('session.use_strict_mode', '1');
    session_name($name);
    // PHP can retain the previous session ID after abort/destroy. Select the
    // cookie explicitly when switching from shared or Central session storage.
    $cookie = $_COOKIE[$name] ?? '';
    session_id(is_string($cookie) && preg_match('/^[A-Za-z0-9,-]{1,256}$/D', $cookie) ? $cookie : '');
    session_set_cookie_params(['lifetime' => 0, 'path' => $nbcDualPath, 'secure' => $nbcDualSecure, 'httponly' => true, 'samesite' => 'Lax']);
    if (!session_start()) { http_response_code(503); exit('Application session is unavailable.'); }
};
if (isset($_COOKIE[$nbcDualSsoName])) {
    $nbcDualStart($nbcDualSsoName);
    if (!$nbcDualNativeOnly && !$nbcDualNativeRoute && isset($_SESSION['nbc_central_identity'])) {
        foreach (array_unique(['C:/Xampp/htdocs/nbc-central/integrations/guard.php', 'C:/Xampp/htdocs/nbc-central/integrations/guard.php']) as $nbcDualGuard) {
            if (!is_file($nbcDualGuard)) continue;
            try {
                require $nbcDualGuard;
                // The existing guard validates the Central session/grant on
                // every protected request; native code retains role checks.
                return;
            } catch (\Throwable $nbcDualError) {
                error_log('NBC optional sign-in validation unavailable.');
                break;
            }
        }
    }
    // Native login/logout explicitly switches modes. Missing connector code
    // discards the mapped identity before native authentication can run.
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    setcookie($nbcDualSsoName, '', ['expires' => time() - 3600, 'path' => $nbcDualPath, 'secure' => $nbcDualSecure, 'httponly' => true, 'samesite' => 'Lax']);
    unset($_COOKIE[$nbcDualSsoName]);
}
$nbcDualStorage = session_save_path();
if (str_contains($nbcDualStorage, ';')) $nbcDualStorage = substr($nbcDualStorage, strrpos($nbcDualStorage, ';') + 1);
if ($nbcDualStorage === '') $nbcDualStorage = sys_get_temp_dir();
$nbcDualStorage = rtrim($nbcDualStorage, '/\\') . '/nbc-rationalization-native-sessions';
if (!is_dir($nbcDualStorage) && !@mkdir($nbcDualStorage, 0700, true) && !is_dir($nbcDualStorage)) {
    http_response_code(503); exit('Application native session storage is unavailable.');
}
ini_set('session.save_handler', 'files');
session_save_path($nbcDualStorage);
$nbcDualStart('NBC_NATIVE_RATIONALIZATION');
if ('rationalization' === 'paperless' && $nbcDualNativeOnly && basename(__DIR__) === 'paperless') {
    setcookie(session_name(), session_id(), ['path' => $nbcDualPath, 'secure' => $nbcDualSecure, 'httponly' => true, 'samesite' => 'Lax']);
}
if (isset($_SESSION['nbc_central_identity']) || isset($_SESSION['nbc_central_user'])) {
    $_SESSION = [];
    session_regenerate_id(true);
}
// Native sign-in never loads Central runtime, SQL settings, or its API.
