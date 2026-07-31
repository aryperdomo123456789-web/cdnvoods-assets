<?php

/**
 * Entrada única de tudo que é PROXY (m3u/m3u8/ts/get.php/player_api/xmltv).
 *
 * Fluxo:
 *   1. AccessGuard valida alias, origem, rate-limit e (se o alias exigir) token.
 *   2. Textual (get.php, player_api.php, xmltv.php, .m3u8, .xml, .json):
 *      streaming com reescrita LINHA A LINHA — memória constante.
 *   3. Binário (.ts e afins): streaming chunkado direto.
 *
 * Nunca força HTTPS, nunca depende de User-Agent e nunca serve o painel.
 */

require_once dirname(__DIR__) . '/app/proxy-bootstrap.php';

/** @var RequestContext|null $REQ contexto rastreável do request atual */
$REQ = null;
/** @var string $SESSION_KEY sessão lógica local desta conexão */
$SESSION_KEY = '';

function proxy_is_heavy_player_api(string $path, array $query): bool
{
    if (stripos($path, 'player_api.php') === false) {
        return false;
    }
    $action = strtolower(trim((string) ($query['action'] ?? '')));
    return in_array($action, [
        'get_live_streams',
        'get_vod_streams',
        'get_series',
        'get_short_epg',
        'get_simple_data_table',
    ], true);
}

function proxy_fail(int $code, string $host = '', string $path = '/', string $reason = 'error'): void
{
    global $REQ, $SESSION_KEY;
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        if ($REQ instanceof RequestContext) {
            header('X-Request-Id: ' . $REQ->requestId);
        }
    }
    echo $code === 404 ? 'Not found' : 'Denied';
    try {
        AccessGuard::logAccess($host, $path, $code, 0, null, null, $reason);
        if ($REQ instanceof RequestContext) {
            RequestLog::open($REQ, null, null, $reason, $SESSION_KEY);
            RequestLog::close($REQ, $code, 0, $reason);
            CdnSession::record($SESSION_KEY, $code, 0);
            // Toda NEGATIVA também entra na trilha única: negar sem registrar
            // era o furo de auditoria mais grave do painel.
            AuditTimeline::record(
                $REQ,
                $SESSION_KEY !== '' ? $SESSION_KEY : 'deny-' . substr($REQ->requestId, 0, 24),
                $code,
                0,
                ['reason' => $reason, 'inconsistency' => $code >= 400 && $code !== 404 ? '' : '']
            );
        }
    } catch (Throwable $e) {
        // log é best-effort: nunca pode derrubar o request do player
    }
    exit;
}

// Só confia em X-Forwarded-Host quando o TCP peer é Cloudflare.
$remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$rawHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
if (AccessGuard::isCloudflare($remoteAddr)) {
    $rawHost = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $rawHost);
}
$host = strtolower($rawHost);
$host = preg_replace('/:\d+$/', '', $host);

// Esquema público real (o rewriter não pode forçar https se a VPS serve :80).
$httpsOn = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (AccessGuard::isCloudflare($remoteAddr)) {
    $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($proto !== '') { $httpsOn = ($proto === 'https'); }
}
$publicScheme = $httpsOn ? 'https' : 'http';

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$parts = parse_url($requestUri);
$path = is_array($parts) && isset($parts['path']) ? $parts['path'] : '/';
$query = [];
parse_str(is_array($parts) ? ($parts['query'] ?? '') : '', $query);
$token = (string) ($query['t'] ?? '');

// Tudo que é texto (playlist, JSON do player_api, EPG XML) precisa passar pela
// sanitização antes de sair — caso contrário a origem vaza no corpo.
$isTextual = (bool) preg_match('#(get\.php$|player_api\.php$|xmltv\.php$|panel_api\.php$|\.m3u8?$|\.m3u$|\.xml$|\.json$)#i', $path);

try {
    $clientIp = AccessGuard::clientIp();
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '-');
    $REQ = RequestContext::build($host, $clientIp, $path, $query);
    StreamProxy::resetTrace();
    $decision = AccessGuard::check($host, $clientIp, $userAgent, $token, $isTextual);
} catch (Throwable $e) {
    error_log('[proxy] guard falhou: ' . $e->getMessage());
    proxy_fail(503, $host, $path, 'guard_error');
    return;
}

if (!$decision['ok']) {
    proxy_fail((int) $decision['code'], $host, $path, (string) $decision['reason']);
    return;
}

$origin = $decision['origin'];
$tokenId = $decision['token'] ? (int) $decision['token']['id'] : null;

// ---------------------------------------------------------------------------
// ROTEAMENTO POR USUÁRIO (cérebro x músculos)
//
// O cérebro (esta VPS) continua sendo quem decide. A decisão é registrada na
// trilha para auditoria — "por qual LB este usuário passou agora" — sem
// nenhuma consulta pesada no caminho do stream: só rota textual resolve,
// segmento reaproveita a rota já gravada na sessão.
// ---------------------------------------------------------------------------
$lbDecision = ['target' => 'main', 'lb_id' => 0, 'reason' => 'main_only'];
if ($isTextual && $REQ->username !== '') {
    try {
        $lbDecision = LbRouter::decide($REQ->username, 'proxy');
    } catch (Throwable $e) {
        error_log('[lb] decide falhou: ' . $e->getMessage());
    }
}

// Sessão lógica da própria CDN: é ela que conta conexões, não o request.
$SESSION_KEY = CdnSession::touch($REQ);
StreamProxy::setSessionKey($SESSION_KEY);
if ($SESSION_KEY !== '' && (int) $lbDecision['lb_id'] > 0) {
    CdnSession::tagLb($SESSION_KEY, (int) $lbDecision['lb_id']);
}

// Enforcement de limite (só age em modo "block", após a tolerância de reconexão).
if ($REQ->username !== '' && Divergence::shouldBlock($REQ->username)) {
    Audit::log('restream_blocked_over_limit',
        sprintf('request_id=%s user=%s host=%s', $REQ->requestId, $REQ->username, $host), $clientIp, $userAgent);
    proxy_fail(429, $host, $path, 'above_limit_blocked');
    return;
}

// Rastreabilidade: abre o evento estruturado deste request.
if (RequestLog::shouldPersist($REQ)) {
    RequestLog::open($REQ, (int) $origin['id'], $tokenId, 'in_flight', $SESSION_KEY);
}
if (!headers_sent()) {
    header('X-Request-Id: ' . $REQ->requestId);
}

try {
    if ($isTextual) {
        if (proxy_is_heavy_player_api($path, $query)) {
            $status = 502;
            $bytes = 0;
            $reason = 'textual_passthrough_api';
            $inconsistency = '';
            $result = StreamProxy::stream($origin, $path, $query);
            $status = (int) ($result['status'] ?? 502);
            $bytes = (int) ($result['bytes'] ?? 0);
            AccessGuard::logAccess($host, $path, $status, $bytes, $tokenId, (int) $origin['id'], $reason);
            $directHost = DirectSource::persist($REQ, $SESSION_KEY, $status);
            RequestLog::close($REQ, $status, $bytes, $reason, $inconsistency, $directHost, count(StreamProxy::hops()));
            CdnSession::record($SESSION_KEY, $status, $bytes, $directHost);
            AuditTimeline::record($REQ, $SESSION_KEY, $status, $bytes, [
                'origin_id' => (int) $origin['id'],
                'lb_id' => (int) $lbDecision['lb_id'],
                'lb_target' => (string) $lbDecision['target'],
                'lb_reason' => (string) $lbDecision['reason'],
                'direct_host' => $directHost,
                'inconsistency' => $inconsistency,
                'hops' => count(StreamProxy::hops()),
                'reason' => $reason,
            ]);
            return;
        }

        $ctx = PlaylistRewriter::compile($origin, $host, $token, $publicScheme);
        // Anti-embaralhamento: a resposta reescrita só pode conter o username
        // que entrou. Qualquer divergência aborta a entrega.
        if ($REQ->username !== '' && (int) SettingsRepository::get('credential_guard_enabled', 1) === 1) {
            CredentialGuard::arm($REQ->username);
        } else {
            CredentialGuard::disarm();
        }
        if (stripos($path, 'player_api.php') !== false) {
            // O XUI devolve a porta dele; o player precisa da porta pública.
            $ctx['patterns'][]     = '#"port"\s*:\s*"?\d+"?#i';
            $ctx['replacements'][] = '"port":"80"';
            $ctx['patterns'][]     = '#"https_port"\s*:\s*"?\d+"?#i';
            $ctx['replacements'][] = '"https_port":"443"';
        }
        $forced = '';
        if (preg_match('#get\.php$#i', $path)) {
            $output = strtolower((string) ($query['output'] ?? ''));
            $forced = ($output === 'hls' || $output === 'm3u8')
                ? 'application/vnd.apple.mpegurl'
                : 'audio/x-mpegurl';
        } elseif (preg_match('#\.m3u8?$#i', $path)) {
            $forced = 'application/vnd.apple.mpegurl';
        }
        $result = StreamProxy::streamTextual($origin, $path, $query, $ctx, $forced);
        $inconsistency = '';
        $reason = 'textual';
        if (CredentialGuard::tripped() || StreamProxy::swapAborted()) {
            $inconsistency = 'invalid_credentials_swap';
            $reason = 'credentials_swap_blocked';
            $result['status'] = 502;
            Audit::log(
                'credential_swap_blocked',
                sprintf(
                    'request_id=%s host=%s rota=%s esperado=%s observado=%s',
                    $REQ->requestId, $host, $REQ->routeKind,
                    CredentialGuard::expected(), CredentialGuard::observed()
                ),
                $clientIp,
                $userAgent
            );
        }
        AccessGuard::logAccess($host, $path, $result['status'], $result['bytes'], $tokenId, (int) $origin['id'], $reason);
        $directHost = DirectSource::persist($REQ, $SESSION_KEY, (int) $result['status']);
        RequestLog::close($REQ, (int) $result['status'], (int) $result['bytes'], $reason, $inconsistency, $directHost, count(StreamProxy::hops()));
        CdnSession::record($SESSION_KEY, (int) $result['status'], (int) $result['bytes'], $directHost);
        AuditTimeline::record($REQ, $SESSION_KEY, (int) $result['status'], (int) $result['bytes'], [
            'origin_id' => (int) $origin['id'],
            'lb_id' => (int) $lbDecision['lb_id'],
            'lb_target' => (string) $lbDecision['target'],
            'lb_reason' => (string) $lbDecision['reason'],
            'direct_host' => $directHost,
            'inconsistency' => $inconsistency,
            'hops' => count(StreamProxy::hops()),
            'reason' => $reason,
        ]);
        return;
    }

    // Streaming binário (segmentos .ts, HLS parts, etc.)
    $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    $result = StreamProxy::stream($origin, $path, $query, $range);
    // Log de segmento é opt-in: um INSERT por .ts derruba a performance da VPS.
    if ($result['status'] >= 400 || (int) SettingsRepository::get('log_segments', 0) === 1) {
        AccessGuard::logAccess($host, $path, $result['status'], $result['bytes'], $tokenId, (int) $origin['id'], 'stream');
        if (!RequestLog::shouldPersist($REQ)) {
            RequestLog::open($REQ, (int) $origin['id'], $tokenId, 'stream', $SESSION_KEY);
        }
    }
    $directHost = DirectSource::persist($REQ, $SESSION_KEY, (int) $result['status']);
    RequestLog::close($REQ, (int) $result['status'], (int) $result['bytes'], 'stream', '', $directHost, count(StreamProxy::hops()));
    CdnSession::record($SESSION_KEY, (int) $result['status'], (int) $result['bytes'], $directHost);
    AuditTimeline::record($REQ, $SESSION_KEY, (int) $result['status'], (int) $result['bytes'], [
        'origin_id' => (int) $origin['id'],
        'lb_id' => (int) $lbDecision['lb_id'],
        'lb_target' => (string) $lbDecision['target'],
        'lb_reason' => (string) $lbDecision['reason'],
        'direct_host' => $directHost,
        'hops' => count(StreamProxy::hops()),
        'reason' => 'stream',
    ]);
} catch (Throwable $e) {
    error_log('[proxy] upstream falhou: ' . $e->getMessage());
    try { RequestLog::close($REQ, 502, 0, 'upstream_error'); } catch (Throwable $ignored) {}
    try { CdnSession::record($SESSION_KEY, 502, 0); } catch (Throwable $ignored) {}
    if (!headers_sent()) {
        proxy_fail(502, $host, $path, 'upstream_error');
    }
}
