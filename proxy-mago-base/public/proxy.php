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

/** @return string[] */
function proxy_host_list(string $csv): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', strtolower(trim($csv))) ?: [] as $host) {
        $host = trim($host);
        if ($host !== '') {
            $out[$host] = $host;
        }
    }
    return array_values($out);
}

/**
 * Alguns hosts de direct source barram o egress de um LB específico.
 * Quando isso acontece, o cérebro pode assumir só esses requests para não
 * derrubar a experiência do assinante enquanto o parque de músculos é ajustado.
 *
 * @return array{enabled:bool,host:string,mode:string}
 */
function proxy_direct_host_brain_fallback(?RequestContext $req): array
{
    if (!$req instanceof RequestContext) {
        return ['enabled' => false, 'host' => '', 'mode' => ''];
    }
    if (!in_array($req->routeKind, ['movie', 'series'], true)) {
        return ['enabled' => false, 'host' => '', 'mode' => ''];
    }
    $streamId = (int) ($req->streamId ?? 0);
    if ($streamId <= 0) {
        return ['enabled' => false, 'host' => '', 'mode' => ''];
    }
    $configured = proxy_host_list((string) Config::get('brain_direct_fallback_hosts', ''));
    if ($configured === []) {
        // Sem allowlist explícita, qualquer host marcado como direct no catálogo
        // pode usar o relay interno. Isso evita quebrar apps novos só porque o
        // host não foi adicionado manualmente à configuração.
        $db = DirectCatalog::dbHostFor($streamId);
        $host = strtolower(trim((string) ($db['host'] ?? '')));
        if ((int) ($db['direct'] ?? 0) !== 1 || $host === '') {
            return ['enabled' => false, 'host' => '', 'mode' => (string) ($db['mode'] ?? '')];
        }
        return ['enabled' => true, 'host' => $host, 'mode' => (string) ($db['mode'] ?? '')];
    }
    $db = DirectCatalog::dbHostFor($streamId);
    $host = strtolower(trim((string) ($db['host'] ?? '')));
    if ((int) ($db['direct'] ?? 0) !== 1 || $host === '') {
        return ['enabled' => false, 'host' => '', 'mode' => (string) ($db['mode'] ?? '')];
    }
    foreach ($configured as $candidate) {
        if (DirectCatalog::sameHost($host, $candidate)) {
            return ['enabled' => true, 'host' => $host, 'mode' => (string) ($db['mode'] ?? '')];
        }
    }
    return ['enabled' => false, 'host' => $host, 'mode' => (string) ($db['mode'] ?? '')];
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

function proxy_guard_landing(string $host): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    http_response_code(200);
    $safeHost = htmlspecialchars($host, ENT_QUOTES, 'UTF-8');
    $wizardGif = 'https://c.tenor.com/MDxs9sUkJ_AAAAAC/tenor.gif';
    echo <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CDN Voods</title>
  <style>
    :root{
      --bg:#03040a; --bg2:#081126; --neon:#8cf6ff; --hot:#ff4fd8; --gold:#ffd166; --text:#eef6ff;
      --line:rgba(140,246,255,.22);
    }
    html,body{height:100%;margin:0;background:
      radial-gradient(circle at 50% 38%, rgba(255,79,216,.20), transparent 18%),
      radial-gradient(circle at 52% 46%, rgba(140,246,255,.18), transparent 24%),
      radial-gradient(circle at 20% 18%, rgba(255,79,216,.16), transparent 23%),
      radial-gradient(circle at 84% 24%, rgba(140,246,255,.16), transparent 25%),
      radial-gradient(circle at 50% 82%, rgba(255,209,102,.08), transparent 28%),
      linear-gradient(160deg, #02030a, #060a18 38%, #0b1230 58%, #050812 78%, #020309 100%);
      color:var(--text); font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif; overflow:hidden;
    }
    .wrap{position:relative;display:grid;place-items:center;height:100%;perspective:1200px}
    .halo{position:absolute;inset:-20%;background:
      radial-gradient(circle at 50% 48%, rgba(140,246,255,.16), transparent 22%),
      radial-gradient(circle at 50% 44%, rgba(255,79,216,.12), transparent 28%),
      radial-gradient(circle at 50% 72%, rgba(255,209,102,.08), transparent 20%);
      filter:blur(24px); animation: drift 12s ease-in-out infinite alternate;
    }
    @keyframes drift{from{transform:translate3d(-1%, -1%,0) scale(1)}to{transform:translate3d(1%,1%,0) scale(1.05)}}
    .card{position:relative;width:min(82vw,1080px);min-height:min(72vh,760px);padding:clamp(16px,2.4vw,28px);border:1px solid rgba(140,246,255,.16);border-radius:30px;background:
      radial-gradient(circle at 50% 42%, rgba(255,79,216,.18), transparent 18%),
      radial-gradient(circle at 56% 48%, rgba(140,246,255,.16), transparent 20%),
      radial-gradient(circle at 50% 70%, rgba(255,79,216,.10), transparent 22%),
      linear-gradient(180deg, rgba(4,7,18,.74), rgba(2,4,12,.90) 56%, rgba(2,4,10,.96) 100%);
      backdrop-filter:blur(14px);box-shadow:0 0 0 1px rgba(255,255,255,.03), 0 28px 100px rgba(0,0,0,.60);transform-style:preserve-3d;overflow:hidden}
    .title{font-size:clamp(28px,5vw,54px);font-weight:900;letter-spacing:.04em;line-height:1.02;margin:0 0 10px;text-transform:uppercase;text-shadow:0 0 18px rgba(140,246,255,.42), 0 0 32px rgba(255,79,216,.22)}
    .sub{margin:0 0 22px;color:rgba(238,246,255,.82);font-size:clamp(14px,2vw,18px)}
    .sig{display:inline-flex;gap:10px;align-items:center;padding:8px 12px;border-radius:999px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);font-size:12px;color:rgba(238,246,255,.72);margin-bottom:18px}
    .sig b{color:var(--gold)}
    .scene{position:relative;display:grid;grid-template-columns:minmax(240px,.84fr) minmax(220px,.66fr);gap:clamp(12px,1.8vw,22px);align-items:center;justify-items:center;min-height:calc(72vh - 90px);z-index:2;max-width:980px;margin:0 auto;padding-top:clamp(4px,1.2vh,10px)}
    .wizard{position:relative;width:min(100%,420px);height:min(40vh,380px);min-height:190px;display:grid;place-items:center;transform-style:preserve-3d;transform:rotateX(var(--rx,0deg)) rotateY(var(--ry,0deg)) translateY(calc(var(--ty,0px) - 18px))}
    .wizard:before{content:"";position:absolute;inset:10% 6% 0;background:
      radial-gradient(circle at 50% 44%, rgba(140,246,255,.26), transparent 28%),
      radial-gradient(circle at 50% 52%, rgba(255,79,216,.18), transparent 34%);
      filter:blur(34px);opacity:.9;animation:pulse 3.8s ease-in-out infinite alternate}
    .wizard img{width:100%;height:100%;max-height:100%;object-fit:contain;object-position:center center;filter:drop-shadow(0 0 20px rgba(140,246,255,.16)) drop-shadow(0 0 40px rgba(255,79,216,.16));transform:translateZ(80px) scale(var(--scale,1));animation: floaty 6.6s ease-in-out infinite alternate}
    .sigil{position:absolute;top:3%;right:6%;width:74px;height:74px;border-radius:50%;border:1px solid rgba(140,246,255,.28);box-shadow:0 0 0 8px rgba(140,246,255,.05), 0 0 28px rgba(140,246,255,.18);animation:spin 9s linear infinite,pulse 2.8s ease-in-out infinite alternate}
    .sigil:before,.sigil:after{content:"";position:absolute;inset:18%;border-radius:50%;border:1px solid rgba(255,79,216,.38)}
    .sigil:after{inset:34%}
    @keyframes spin{to{transform:rotate(360deg)}}
    .text{padding:8px 4px 0;align-self:center;justify-self:center;max-width:min(38vw,420px);z-index:3;transform:translateZ(40px);text-align:center}
    .big{
      font-size:clamp(24px,2.5vw,38px);
      line-height:1.15;
      font-weight:900;
      letter-spacing:.01em;
      color:#f6fbff;
      max-width:18ch;
      margin:8px auto 0;
      text-shadow:
        0 1px 0 rgba(255,255,255,.35),
        0 2px 0 rgba(140,246,255,.20),
        0 3px 0 rgba(140,246,255,.14),
        0 8px 18px rgba(0,0,0,.40),
        0 0 24px rgba(255,79,216,.12);
      transform:perspective(700px) rotateX(12deg);
    }
    .big strong{color:var(--neon)}
    .small{margin-top:14px;color:rgba(238,246,255,.62);font-size:13px}
    .quote{margin:18px auto 0;padding:12px 16px;border-left:3px solid rgba(140,246,255,.55);background:rgba(255,255,255,.03);border-radius:12px;color:rgba(238,246,255,.84);font-size:13px;display:inline-block;box-shadow:0 0 0 1px rgba(140,246,255,.04), 0 0 24px rgba(255,79,216,.06)}
    .scan{position:absolute;inset:auto 0 0; height:2px;background:linear-gradient(90deg, transparent, rgba(140,246,255,.75), rgba(255,79,216,.6), transparent);filter:blur(.2px);animation:scan 2.4s linear infinite}
    @keyframes scan{0%{transform:translateY(0)}100%{transform:translateY(-100vh)}}
    @keyframes pulse{from{opacity:.55;transform:scale(.98)}to{opacity:1;transform:scale(1.02)}}
    @keyframes floaty{from{transform:translateZ(80px) translateY(-2px) scale(var(--scale,1))}to{transform:translateZ(80px) translateY(6px) scale(var(--scale,1))}}
    .bgglow{position:absolute;inset:-10% -10% auto -10%;height:85%;background:
      radial-gradient(circle at 50% 40%, rgba(255,79,216,.18), transparent 25%),
      radial-gradient(circle at 50% 48%, rgba(140,246,255,.12), transparent 30%);
      filter:blur(34px);opacity:.94;pointer-events:none;animation:pulse 4.4s ease-in-out infinite alternate}
    .backdrop-art{position:absolute;inset:0;background:url('{$wizardGif}') center center / cover no-repeat;opacity:.08;filter:saturate(1.08) contrast(1.03) blur(6px);transform:scale(1.14);pointer-events:none}
    @media (max-width:860px){
      .card{width:92vw;min-height:84vh;padding:14px;border-radius:24px}
      .scene{grid-template-columns:1fr;min-height:calc(84vh - 80px);gap:10px;padding-top:4px}
      .text{order:2;max-width:100%;justify-self:center;text-align:center}
      .wizard{order:1;width:min(100%,320px);height:min(28vh,250px);min-height:140px;transform:rotateX(var(--rx,0deg)) rotateY(var(--ry,0deg)) translateY(calc(var(--ty,0px) - 12px))}
      .title{font-size:clamp(22px,7vw,36px)}
      .sub{font-size:14px}
      .big{max-width:100%}
      .quote{max-width:100%}
    }
  </style>
</head>
<body>
<div class="wrap">
  <div class="halo"></div>
  <div class="card" id="card" data-host="{$safeHost}">
    <div class="backdrop-art"></div>
    <div class="bgglow"></div>
    <div class="sig"><b>CDN Voods</b><span>proteção ativa</span></div>
    <h1 class="title">Você entrou no portal errado</h1>
    <div class="scene">
      <div class="wizard" id="wizard">
        <div class="sigil"></div>
        <img src="{$wizardGif}" alt="Mago guardião">
      </div>
      <div class="text">
        <div class="big">viva hoje como se fosse seu ultimo dia BY: MagoPD</div>
      </div>
    </div>
    <div class="scan"></div>
  </div>
</div>
<script>
const card = document.getElementById('card');
const wiz = document.getElementById('wizard');
let pointerX = 0;
let pointerY = 0;
function move(e){
  const r = card.getBoundingClientRect();
  pointerX = ((e.clientX - r.left) / r.width - 0.5) * 2;
  pointerY = ((e.clientY - r.top) / r.height - 0.5) * 2;
  card.style.setProperty('--scale', '1');
  card.style.transform = 'rotateX(' + (-pointerY * 2).toFixed(2) + 'deg) rotateY(' + (pointerX * 3).toFixed(2) + 'deg)';
  wiz.style.setProperty('--rx', (-pointerY * 8).toFixed(2) + 'deg');
  wiz.style.setProperty('--ry', (pointerX * 10).toFixed(2) + 'deg');
  wiz.style.setProperty('--ty', (-pointerY * 5).toFixed(2) + 'px');
  wiz.style.setProperty('--scale', (1 + Math.abs(pointerX) * 0.02 + Math.abs(pointerY) * 0.02).toFixed(3));
}
window.addEventListener('mousemove', move, {passive:true});
window.addEventListener('deviceorientation', (e) => {
  if (e.beta == null || e.gamma == null) return;
  const x = Math.max(-1, Math.min(1, e.gamma / 35));
  const y = Math.max(-1, Math.min(1, e.beta / 35));
  card.style.transform = 'rotateX(' + (-y * 1.5).toFixed(2) + 'deg) rotateY(' + (x * 2).toFixed(2) + 'deg)';
  wiz.style.setProperty('--rx', (-y * 8).toFixed(2) + 'deg');
  wiz.style.setProperty('--ry', (x * 10).toFixed(2) + 'deg');
  wiz.style.setProperty('--ty', (-y * 5).toFixed(2) + 'px');
}, {passive:true});
</script>
</body>
</html>
HTML;
    exit;
}

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

// Root navegador-first: o / precisa mostrar a tela neon antes de qualquer
// regra de entrega por LB. Isso não toca get.php, player_api.php nem playback.
if (
    $path === '/'
    && in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)
    && $token === ''
    && stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html') !== false
) {
    proxy_guard_landing($host);
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
$lbDecision = ['target' => 'main', 'lb_id' => 0, 'reason' => 'main_only', 'host' => '', 'mode' => 'main_only'];
if ((string) Config::get('role', 'main') !== 'lb' && $REQ->username !== '') {
    try {
        $lbDecision = LbRouter::decide($REQ->username, 'proxy');
    } catch (Throwable $e) {
        error_log('[lb] decide falhou: ' . $e->getMessage());
    }
}

$directFallback = ['enabled' => false, 'host' => '', 'mode' => ''];
if ((string) Config::get('role', 'main') !== 'lb'
    && (string) ($lbDecision['target'] ?? 'main') === 'lb') {
    $directFallback = proxy_direct_host_brain_fallback($REQ);
    if ($directFallback['enabled']) {
        $lbDecision = [
            'target' => 'main',
            'lb_id' => 0,
            'reason' => 'direct_host_brain_fallback',
            'host' => '',
            'mode' => 'brain_direct_fallback',
        ];
        Audit::log(
            'direct_host_brain_fallback',
            sprintf(
                'request_id=%s user=%s stream_id=%d direct_host=%s mode=%s host=%s',
                $REQ->requestId,
                $REQ->username,
                (int) ($REQ->streamId ?? 0),
                $directFallback['host'],
                $directFallback['mode'],
                $host
            ),
            $clientIp,
            $userAgent
        );
    }
}

// Sessão lógica da própria CDN: é ela que conta conexões, não o request.
$SESSION_KEY = CdnSession::touch($REQ);
StreamProxy::setSessionKey($SESSION_KEY);
if ($SESSION_KEY !== '' && (int) $lbDecision['lb_id'] > 0) {
    CdnSession::tagLb($SESSION_KEY, (int) $lbDecision['lb_id']);
}

if ((string) Config::get('role', 'main') !== 'lb'
    && (string) ($lbDecision['target'] ?? 'main') === 'lb'
    && (string) ($lbDecision['host'] ?? '') !== '') {
    $origin = [
        'id' => -((int) $lbDecision['lb_id']),
        'host' => (string) $lbDecision['host'],
        'port' => 80,
        'scheme' => 'http',
        'base_path' => '',
        'auth_user' => '',
        'auth_pass' => '',
        'name' => 'LB-' . (int) $lbDecision['lb_id'],
        'type' => 'a',
        'host_header' => '',
        'extra_hosts' => '',
        // O músculo precisa saber QUAL domínio/IP o cliente realmente usou.
        // Sem isso ele reenfileira eventos no cérebro como se o host/IP
        // públicos fossem os do próprio LB, quebrando a trilha canônica.
        'forwarded_public_host' => $host,
        'forwarded_client_ip' => $clientIp,
        'forwarded_via_brain' => 1,
    ];
}

// ---------------------------------------------------------------------------
// CÉREBRO PURO (lb_require_delivery=1)
//
// Com a flag ligada, o main é REGISTRO + CONTROLE: quem entrega byte é músculo.
// Se o usuário não tem LB apto, é melhor recusar este request do que deixar o
// cérebro virar servidor de stream e derrubar o painel inteiro junto.
// ---------------------------------------------------------------------------
if ((string) Config::get('role', 'main') !== 'lb'
    && (string) ($lbDecision['target'] ?? 'main') !== 'lb'
    && LbRouter::requireDelivery()
    && !$directFallback['enabled']) {
    CdnSession::reject($SESSION_KEY, 'lb_required_no_muscle');
    Audit::log(
        'lb_required_no_muscle',
        sprintf('request_id=%s user=%s host=%s reason=%s',
            $REQ->requestId, $REQ->username, $host, (string) ($lbDecision['reason'] ?? '')),
        $clientIp,
        $userAgent
    );
    proxy_fail(503, $host, $path, 'lb_required_no_muscle');
    return;
}

// Enforcement de limite (só age em modo "block", após a tolerância de reconexão).
if ($REQ->username !== '' && Divergence::shouldBlock($REQ->username, CdnSession::kindOf($REQ))) {
    CdnSession::reject($SESSION_KEY, 'above_limit_blocked');
    Audit::log('restream_blocked_over_limit',
        sprintf('request_id=%s user=%s host=%s', $REQ->requestId, $REQ->username, $host), $clientIp, $userAgent);
    proxy_fail(429, $host, $path, 'above_limit_blocked');
    return;
}

if ($REQ->username !== '') {
    $ipVerdict = UserIpLock::explain($REQ->username, $clientIp);
    if (!$ipVerdict['allowed']) {
        CdnSession::reject($SESSION_KEY, 'cdn_ip_lock_blocked');
        Audit::log(
            'cdn_ip_lock_blocked',
            sprintf(
                'request_id=%s user=%s client_ip=%s host=%s motivo=%s regras=%d',
                $REQ->requestId,
                $REQ->username,
                $clientIp,
                $host,
                $ipVerdict['reason'],
                $ipVerdict['rules']
            ),
            $clientIp,
            $userAgent
        );
        proxy_fail(403, $host, $path, 'cdn_ip_lock_blocked');
        return;
    }
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
        if (PlayerApiLocal::shouldHandle($path, $query)) {
            $reason = 'player_api_local_cache';
            $result = PlayerApiLocal::serve($query);
            $status = (int) ($result['status'] ?? 200);
            $bytes = (int) ($result['bytes'] ?? 0);
            AccessGuard::logAccess($host, $path, $status, $bytes, $tokenId, (int) $origin['id'], $reason);
            RequestLog::close($REQ, $status, $bytes, $reason, '', '', 0);
            CdnSession::record($SESSION_KEY, $status, $bytes, '');
            AuditTimeline::record($REQ, $SESSION_KEY, $status, $bytes, [
                'origin_id' => (int) $origin['id'],
                'lb_id' => (int) $lbDecision['lb_id'],
                'lb_target' => (string) $lbDecision['target'],
                'lb_reason' => (string) $lbDecision['reason'],
                'direct_host' => '',
                'inconsistency' => '',
                'hops' => 0,
                'reason' => $reason,
            ]);
            return;
        }

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

        if (XuiSeriesCompat::shouldHandle($path, $query)) {
            // O host público visto pelo player deve continuar sendo o domínio
            // protegido da CDN. O LB é só o destino interno da entrega, nunca
            // a identidade pública devolvida em player_api/playlist.
            $rewriteHost = $host;
            $buffered = StreamProxy::fetchBuffered($origin, $path, $query);
            $status = (int) ($buffered['status'] ?? 502);
            $body = XuiSeriesCompat::normalizeBody((string) ($buffered['body'] ?? ''));
            $body = PlaylistRewriter::rewrite($body, $origin, $rewriteHost, $token, $publicScheme);
            $bytes = strlen($body);
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            echo $body;

            $inconsistency = '';
            $reason = 'series_info_compat';
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

        // Mantém o domínio protegido estável mesmo quando a entrega interna
        // foi roteada para um músculo.
        $rewriteHost = $host;
        $ctx = PlaylistRewriter::compile($origin, $rewriteHost, $token, $publicScheme);
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
        $extraHeaders = [];
        if (preg_match('#get\.php$#i', $path)) {
            $output = strtolower((string) ($query['output'] ?? ''));
            $forced = ($output === 'hls' || $output === 'm3u8')
                ? 'application/vnd.apple.mpegurl'
                : 'audio/x-mpegurl';
            // Apps de IPTV precisam ler a playlist imediatamente. Mantemos o
            // tipo correto e evitamos forçar download no caminho quente.
            if (!headers_sent()) {
                header('Cache-Control: no-store');
            }
        } elseif (preg_match('#\.m3u8?$#i', $path)) {
            $forced = 'application/vnd.apple.mpegurl';
        }
        $result = StreamProxy::streamTextual($origin, $path, $query, $ctx, $forced, $extraHeaders);
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
