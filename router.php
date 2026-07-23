<?php
/**
 * ═══════════════════════════════════════════════════════════════════
 * MAGO GATEWAY V3 (STEALTH MODE) - Router Principal
 * ═══════════════════════════════════════════════════════════════════
 * Sistema de proxy avançado com proteção anti-sniffing:
 * - Spoofing do cabeçalho 'Host' via cURL
 * - Tokenização por IP para VOD
 * - Whitelist de User-Agent
 * - Modo Stealth (ocultação de fonte original)
 * - Resolução automática via CNAME
 * ═══════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mago-manager/security.php';

// Captura informações da requisição
$host_acessado = $_SERVER['HTTP_HOST'];
$uri = $_SERVER['REQUEST_URI'];
$clientIp = SecurityManager::getClientIp();

/**
 * ═══════════════════════════════════════════════════════════════════
 * ETAPA 1: VALIDAÇÃO DE USER-AGENT (ANTI-SNIFFING)
 * ═══════════════════════════════════════════════════════════════════
 */
if (!SecurityManager::validateUserAgent()) {
    SecurityManager::blockAccess(ERROR_MSG_INVALID_USER_AGENT);
}

/**
 * ═══════════════════════════════════════════════════════════════════
 * ETAPA 2: CARREGAMENTO DO BANCO DE PROXIES
 * ═══════════════════════════════════════════════════════════════════
 */
$db_file = DB_FILE;
$proxies = file_exists($db_file) ? json_decode(file_get_contents($db_file), true) : [];
if (!is_array($proxies)) {
    $proxies = [];
}

$proxyConfig = null;
$destino_ip = '';
$dominio_mestre = '';
$modo_proxy = 'direct'; // Padrão

/**
 * ═══════════════════════════════════════════════════════════════════
 * ETAPA 3: MAPEAMENTO DIRETO
 * ═══════════════════════════════════════════════════════════════════
 */
foreach ($proxies as $p) {
    if (isset($p['dominio']) && $p['dominio'] === $host_acessado) {
        $destino_ip = $p['ip_xui'];
        $dominio_mestre = $p['dominio'];
        $modo_proxy = $p['modo'] ?? DEFAULT_PROXY_MODE;
        $proxyConfig = $p;
        break;
    }
}

/**
 * ═══════════════════════════════════════════════════════════════════
 * ETAPA 4: RESOLUÇÃO VIA CNAME (FALLBACK)
 * ═══════════════════════════════════════════════════════════════════
 */
if (!$destino_ip) {
    $dns = @dns_get_record($host_acessado, DNS_CNAME);
    if (!empty($dns) && isset($dns[0]['target'])) {
        $target = rtrim($dns[0]['target'], '.');
        foreach ($proxies as $p) {
            if (isset($p['dominio']) && $target === $p['dominio']) {
                $destino_ip = $p['ip_xui'];
                $dominio_mestre = $p['dominio'];
                $modo_proxy = $p['modo'] ?? DEFAULT_PROXY_MODE;
                $proxyConfig = $p;
                break;
            }
        }
    }
}

/**
 * ═══════════════════════════════════════════════════════════════════
 * ETAPA 5: PROCESSAMENTO DO PROXY
 * ═══════════════════════════════════════════════════════════════════
 */
if ($destino_ip && $proxyConfig) {

    /**
     * ─────────────────────────────────────────────────────────────────
     * SUB-ETAPA 5.1: DETECÇÃO DE TIPO DE CONTEÚDO
     * ─────────────────────────────────────────────────────────────────
     */
    $isVod = SecurityManager::isVodContent($uri);
    $isM3U = (strpos($uri, '.m3u') !== false || strpos($uri, '.m3u8') !== false);

    /**
     * ─────────────────────────────────────────────────────────────────
     * SUB-ETAPA 5.2: VALIDAÇÃO DE TOKEN PARA VOD
     * ─────────────────────────────────────────────────────────────────
     */
    if ($isVod && !$isM3U && $modo_proxy === 'stealth') {
        // VOD em modo stealth requer token
        $token = $_GET['token'] ?? '';

        if (empty($token)) {
            SecurityManager::blockAccess(ERROR_MSG_INVALID_TOKEN);
        }

        if (!SecurityManager::validateToken($token, $clientIp)) {
            SecurityManager::blockAccess(ERROR_MSG_INVALID_TOKEN);
        }
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * SUB-ETAPA 5.3: EXECUÇÃO DO PROXY
     * ─────────────────────────────────────────────────────────────────
     */

    // Monta a URL de destino
    $url = "http://" . $destino_ip . $uri;

    /**
     * MODO STEALTH: Usa proxy reverso do servidor web
     * Para Apache: mod_proxy
     * Para Nginx: proxy_pass
     *
     * IMPORTANTE: O modo stealth verdadeiro deve ser configurado
     * no Apache/Nginx. Este código PHP ainda usa cURL, mas com
     * processamento especial para M3U.
     */

    if ($modo_proxy === 'stealth' && $isM3U) {
        /**
         * ═════════════════════════════════════════════════════════════
         * PROCESSAMENTO ESPECIAL PARA M3U EM MODO STEALTH
         * ═════════════════════════════════════════════════════════════
         * 1. Baixa o M3U original
         * 2. Substitui URLs originais pelas URLs do proxy
         * 3. Adiciona tokens em URLs de vídeo
         * 4. Retorna M3U modificado
         */

        // Gera token para este cliente
        $token = SecurityManager::generateToken($clientIp);

        // Baixa o M3U original
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, PROXY_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, PROXY_CONNECT_TIMEOUT);

        $headers = [
            "Host: $dominio_mestre",
            "User-Agent: " . ALLOWED_USER_AGENT,
            "Accept: */*"
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $m3uContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $m3uContent) {
            // Processa o M3U: substitui domínio original pelo proxy e adiciona tokens
            $originalDomain = parse_url("http://" . $destino_ip, PHP_URL_HOST);
            $m3uProcessed = SecurityManager::processM3U(
                $m3uContent,
                $token,
                $host_acessado,
                $originalDomain
            );

            // Envia o M3U processado
            header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
            header('Content-Length: ' . strlen($m3uProcessed));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            echo $m3uProcessed;
            exit;
        } else {
            http_response_code(502);
            die('Bad Gateway: Unable to fetch playlist');
        }

    } else {
        /**
         * ═════════════════════════════════════════════════════════════
         * PROXY PADRÃO (DIRETO OU STREAMING)
         * ═════════════════════════════════════════════════════════════
         * Estabelece túnel cURL para streaming de vídeo ou modo direct
         */

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // Stream direto
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, PROXY_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, PROXY_CONNECT_TIMEOUT);

        // Buffer otimizado para streaming
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 128 * 1024); // 128KB buffer

        /**
         * Spoofing do cabeçalho Host
         */
        $headers = [
            "Host: $dominio_mestre",
            "User-Agent: " . ALLOWED_USER_AGENT,
            "Accept: */*",
            "Connection: keep-alive"
        ];

        // Repassa Range header (importante para seeking em vídeos)
        if (isset($_SERVER['HTTP_RANGE'])) {
            $headers[] = "Range: " . $_SERVER['HTTP_RANGE'];
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        /**
         * Callback para repassar cabeçalhos de resposta
         */
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) {
            $headerLine = trim($header);

            // Repassa cabeçalhos relevantes
            if (!empty($headerLine) && strpos($headerLine, ':') !== false) {
                // Não repassa cabeçalhos que revelam a fonte
                $lowerHeader = strtolower($headerLine);
                if (strpos($lowerHeader, 'server:') === 0 ||
                    strpos($lowerHeader, 'x-powered-by:') === 0) {
                    return strlen($header);
                }

                header($header);
            }

            return strlen($header);
        });

        // Executa o proxy
        curl_exec($ch);

        // Tratamento de erros
        if (curl_errno($ch)) {
            http_response_code(502);
            echo "<!-- Proxy Error: " . curl_error($ch) . " -->";
        }

        curl_close($ch);
        exit;
    }
}

/**
 * ═══════════════════════════════════════════════════════════════════
 * ETAPA 6: FALLBACK - PÁGINA DE STATUS
 * ═══════════════════════════════════════════════════════════════════
 */
include(__DIR__ . '/index.php');
