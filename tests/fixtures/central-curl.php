<?php
/** Test transport for running the installed connector functions without a network. */
declare(strict_types=1);
namespace NbcCentral;
if (!getenv('RAT_SSO_FIXTURE')) { http_response_code(404); exit; }
function curl_init(?string $url = null): object { return (object)['url' => $url, 'options' => []]; }
function curl_setopt_array(object $handle, array $options): bool { $handle->options = $options; return true; }
function curl_exec(object $handle): bool {
    $options = $handle->options;
    if ($handle->url !== config()['issuer'] . '/remote-api.php' || $options[CURLOPT_SSL_VERIFYPEER] !== true
        || $options[CURLOPT_SSL_VERIFYHOST] !== 2 || $options[CURLOPT_PROTOCOLS] !== CURLPROTO_HTTPS
        || $options[CURLOPT_FOLLOWLOCATION] !== false || $options[CURLOPT_CONNECTTIMEOUT] > 3 || $options[CURLOPT_TIMEOUT] > 6
        || !in_array('X-NBC-Client: rationalization', $options[CURLOPT_HTTPHEADER], true)) throw new \RuntimeException('Unsafe connector transport.');
    $state = json_decode(file_get_contents(getenv('RAT_SSO_FIXTURE') . '/state.json'), true);
    if ($state['outage']) return false;
    parse_str($options[CURLOPT_POSTFIELDS], $data);
    if ($data['action'] === 'exchange') {
        if (($data['callback'] ?? '') !== bridgeApp()['callback'] || bridgeChallenge($data['verifier'] ?? '') !== ($state['expected_challenge'] ?? '')) throw new \RuntimeException('Invalid code/PKCE exchange.');
        $result = ['active' => $state['grant'], 'user' => ['id' => 7], 'local_username' => $state['username'], 'token' => str_repeat('a', 64)];
    } elseif ($data['action'] === 'check') {
        $result = ['active' => $state['grant'] && ($data['token'] ?? '') === str_repeat('a', 64), 'user_id' => 7, 'local_username' => $state['username']];
    } else throw new \RuntimeException('Unexpected API action.');
    $options[CURLOPT_WRITEFUNCTION]($handle, json_encode($result));
    return true;
}
function curl_getinfo(object $handle, int $option): int {
    $state = json_decode(file_get_contents(getenv('RAT_SSO_FIXTURE') . '/state.json'), true);
    return $option === CURLINFO_RESPONSE_CODE ? ($state['outage'] ? 0 : 200) : ($state['outage'] ? 1 : 0);
}
function curl_errno(object $handle): int { return json_decode(file_get_contents(getenv('RAT_SSO_FIXTURE') . '/state.json'), true)['outage'] ? 28 : 0; }
function curl_close(object $handle): void {}
