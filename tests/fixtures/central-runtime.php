<?php
/** Test-only connector contract. No credentials, network calls or live SQL. */
declare(strict_types=1);
namespace NbcCentral;
if (!getenv('RAT_SSO_FIXTURE')) { http_response_code(404); exit; }
function fixtureState(): array { return json_decode(file_get_contents(getenv('RAT_SSO_FIXTURE') . '/state.json'), true, 16, JSON_THROW_ON_ERROR); }
function config(): array { return ['issuer' => 'https://central.invalid:4883/nbc-central']; }
function applications(): array { return ['rationalization' => ['cookie_path' => '/rationalization-system', 'callback' => 'https://' . $_SERVER['HTTP_HOST'] . '/rationalization-system/central-sso.php']]; }
function bridgeApp(): array { return applications()['rationalization']; }
function url(): string { return !empty(fixtureState()['local']) ? '/nbc-central/index.php' : config()['issuer'] . '/index.php'; }
function bridgeRequireHttps(bool $callback = false): void {
    if (empty($_SERVER['HTTPS']) || ($callback && parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/rationalization-system/central-sso.php')) throw new \RuntimeException('HTTPS required.');
}
function bridgeBegin(): string {
    $state = bin2hex(random_bytes(32)); $verifier = bin2hex(random_bytes(32));
    $_SESSION['nbc_remote_pending'][$state] = ['verifier' => $verifier, 'expires' => time() + 300];
    return url() . '?' . http_build_query(['page' => 'remote-authorize', 'client_id' => 'rationalization', 'response_type' => 'code',
        'state' => $state, 'redirect_uri' => bridgeApp()['callback'], 'code_challenge_method' => 'S256',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')]);
}
function bridgeConsumeState(array $request): string {
    $state = $request['state'] ?? ''; $pending = is_string($state) ? ($_SESSION['nbc_remote_pending'][$state] ?? null) : null;
    if (is_string($state)) unset($_SESSION['nbc_remote_pending'][$state]);
    if (!$pending || $pending['expires'] <= time() || ($request['iss'] ?? '') !== config()['issuer']
        || !is_string($request['code'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $request['code'])) throw new \RuntimeException('Invalid browser state.');
    $GLOBALS['fixture_expected_verifier'] = $pending['verifier']; return $pending['verifier'];
}
final class BridgeApiException extends \RuntimeException {
    public function __construct(public readonly int $curlNumber, public readonly int $httpStatus, public readonly int $tlsResult) { parent::__construct('Fixture transport failure.'); }
}
function bridgeApi(array $data): array {
    $state = fixtureState();
    if ($state['outage']) throw new BridgeApiException(28, 0, 1);
    if ($data['action'] !== 'exchange' || $data['callback'] !== bridgeApp()['callback']
        || $data['verifier'] !== ($GLOBALS['fixture_expected_verifier'] ?? null)) throw new \RuntimeException('Invalid exchange parameters.');
    return ['active' => $state['grant'], 'user' => ['id' => 7], 'local_username' => $state['username'], 'token' => str_repeat('a', 64)];
}
function centralIdentityIsValid(array $identity, string $app): bool {
    $state = fixtureState();
    if ($state['outage']) throw new BridgeApiException(28, 0, 1);
    return $app === 'rationalization' && ($identity['app_key'] ?? '') === $app && $state['grant']
        && ($identity['local_username'] ?? '') === $state['username']
        && ((!empty($state['local']) && ($identity['session_hash'] ?? '') === 'fixture-session-hash') || ($identity['token'] ?? '') === str_repeat('a', 64));
}
