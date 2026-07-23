<?php
/**
 * ═══════════════════════════════════════════════════════════════════
 * MAGO GATEWAY V3 - Sistema de Segurança (Stealth Mode)
 * ═══════════════════════════════════════════════════════════════════
 * Funções de segurança para proteção contra sniffing:
 * - Geração de tokens baseados em IP
 * - Validação de tokens
 * - Whitelist de User-Agent
 * - Logs de tentativas de acesso não autorizado
 * ═══════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/../config.php';

class SecurityManager {

    /**
     * ═══════════════════════════════════════════════════════════════════
     * GERAÇÃO DE TOKENS
     * ═══════════════════════════════════════════════════════════════════
     * Gera um token único baseado no IP do cliente
     *
     * @param string $clientIp IP do cliente
     * @param int $timestamp Timestamp (opcional, usa time() se não fornecido)
     * @return string Token gerado
     */
    public static function generateToken($clientIp, $timestamp = null) {
        if ($timestamp === null) {
            $timestamp = time();
        }

        // Normaliza o IP (remove portas, etc)
        $cleanIp = self::normalizeIp($clientIp);

        // Calcula o timestamp de expiração
        $expirationWindow = floor($timestamp / TOKEN_EXPIRATION);

        // Gera o hash: SHA256(IP + Timestamp + Secret)
        $data = $cleanIp . '|' . $expirationWindow . '|' . TOKEN_SECRET_KEY;
        $hash = hash('sha256', $data);

        // Retorna: timestamp.hash
        return base64_encode($expirationWindow . '.' . $hash);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * VALIDAÇÃO DE TOKENS
     * ═══════════════════════════════════════════════════════════════════
     * Valida se o token é válido para o IP fornecido
     *
     * @param string $token Token a ser validado
     * @param string $clientIp IP do cliente
     * @return bool True se válido, False se inválido
     */
    public static function validateToken($token, $clientIp) {
        try {
            // Decodifica o token
            $decoded = base64_decode($token);
            if ($decoded === false) {
                self::logSecurityEvent('INVALID_TOKEN_FORMAT', $clientIp, $token);
                return false;
            }

            // Separa timestamp e hash
            $parts = explode('.', $decoded);
            if (count($parts) !== 2) {
                self::logSecurityEvent('INVALID_TOKEN_STRUCTURE', $clientIp, $token);
                return false;
            }

            list($expirationWindow, $providedHash) = $parts;

            // Verifica se o token expirou
            $currentWindow = floor(time() / TOKEN_EXPIRATION);
            if ($expirationWindow < $currentWindow) {
                self::logSecurityEvent('TOKEN_EXPIRED', $clientIp, $token);
                return false;
            }

            // Normaliza o IP
            $cleanIp = self::normalizeIp($clientIp);

            // Recalcula o hash para validação
            $data = $cleanIp . '|' . $expirationWindow . '|' . TOKEN_SECRET_KEY;
            $expectedHash = hash('sha256', $data);

            // Compara os hashes de forma segura (timing-attack safe)
            if (!hash_equals($expectedHash, $providedHash)) {
                self::logSecurityEvent('TOKEN_HASH_MISMATCH', $clientIp, $token);
                return false;
            }

            return true;

        } catch (Exception $e) {
            self::logSecurityEvent('TOKEN_VALIDATION_ERROR', $clientIp, $e->getMessage());
            return false;
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * VALIDAÇÃO DE USER-AGENT
     * ═══════════════════════════════════════════════════════════════════
     * Verifica se o User-Agent da requisição é permitido
     *
     * @param string $userAgent User-Agent da requisição
     * @return bool True se permitido, False se bloqueado
     */
    public static function validateUserAgent($userAgent = null) {
        // Se a validação estiver desabilitada, permite tudo
        if (!ENFORCE_USER_AGENT) {
            return true;
        }

        // Captura o User-Agent da requisição se não fornecido
        if ($userAgent === null) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        }

        // Verifica se corresponde ao User-Agent permitido
        if ($userAgent !== ALLOWED_USER_AGENT) {
            $clientIp = self::getClientIp();
            self::logSecurityEvent('INVALID_USER_AGENT', $clientIp, $userAgent);
            return false;
        }

        return true;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * OBTER IP DO CLIENTE
     * ═══════════════════════════════════════════════════════════════════
     * Obtém o IP real do cliente (considera proxies reversos)
     *
     * @return string IP do cliente
     */
    public static function getClientIp() {
        // Lista de cabeçalhos a verificar (em ordem de prioridade)
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_REAL_IP',             // Nginx
            'HTTP_X_FORWARDED_FOR',       // Proxy padrão
            'HTTP_CLIENT_IP',             // Proxy alternativo
            'REMOTE_ADDR'                 // Conexão direta
        ];

        foreach ($headers as $header) {
            if (isset($_SERVER[$header]) && !empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // Se for X-Forwarded-For, pega o primeiro IP da lista
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }

                // Valida se é um IP válido
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * NORMALIZAR IP
     * ═══════════════════════════════════════════════════════════════════
     * Remove portas e normaliza o formato do IP
     *
     * @param string $ip IP a ser normalizado
     * @return string IP normalizado
     */
    private static function normalizeIp($ip) {
        // Remove porta se existir
        if (strpos($ip, ':') !== false) {
            $parts = explode(':', $ip);
            $ip = $parts[0];
        }

        return trim($ip);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * VERIFICAR SE É CONTEÚDO VOD
     * ═══════════════════════════════════════════════════════════════════
     * Verifica se a URI requisitada é um arquivo de vídeo
     *
     * @param string $uri URI da requisição
     * @return bool True se for VOD, False caso contrário
     */
    public static function isVodContent($uri) {
        $uri = strtolower($uri);

        foreach (VOD_EXTENSIONS as $ext) {
            if (strpos($uri, $ext) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * ADICIONAR TOKEN À URL
     * ═══════════════════════════════════════════════════════════════════
     * Adiciona o token de autenticação a uma URL
     *
     * @param string $url URL original
     * @param string $token Token a ser adicionado
     * @return string URL com token
     */
    public static function addTokenToUrl($url, $token) {
        $separator = (strpos($url, '?') !== false) ? '&' : '?';
        return $url . $separator . 'token=' . urlencode($token);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * PROCESSAR M3U E ADICIONAR TOKENS
     * ═══════════════════════════════════════════════════════════════════
     * Processa um arquivo M3U e adiciona tokens a todas as URLs de vídeo
     *
     * @param string $m3uContent Conteúdo do M3U
     * @param string $token Token a ser adicionado
     * @param string $proxyDomain Domínio do proxy
     * @param string $originalDomain Domínio original a ser substituído
     * @return string M3U processado
     */
    public static function processM3U($m3uContent, $token, $proxyDomain, $originalDomain = null) {
        $lines = explode("\n", $m3uContent);
        $processedLines = [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Se a linha contém uma URL
            if (!empty($line) && !str_starts_with($line, '#')) {
                // Se foi fornecido um domínio original, substitui pelo proxy
                if ($originalDomain !== null) {
                    $line = str_replace($originalDomain, $proxyDomain, $line);
                }

                // Adiciona token se for conteúdo VOD
                if (self::isVodContent($line)) {
                    $line = self::addTokenToUrl($line, $token);
                }
            }

            $processedLines[] = $line;
        }

        return implode("\n", $processedLines);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * LOG DE EVENTOS DE SEGURANÇA
     * ═══════════════════════════════════════════════════════════════════
     * Registra eventos de segurança em log
     *
     * @param string $event Tipo de evento
     * @param string $ip IP envolvido
     * @param string $details Detalhes adicionais
     */
    private static function logSecurityEvent($event, $ip, $details = '') {
        if (!SECURITY_LOGS) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        $logMessage = sprintf(
            "[%s] EVENT:%s | IP:%s | URI:%s | UA:%s | DETAILS:%s\n",
            $timestamp,
            $event,
            $ip,
            $uri,
            $userAgent,
            $details
        );

        error_log($logMessage, 3, SECURITY_LOG_FILE);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * BLOQUEAR ACESSO COM ERRO 403
     * ═══════════════════════════════════════════════════════════════════
     * Bloqueia acesso e retorna erro 403
     *
     * @param string $reason Razão do bloqueio
     */
    public static function blockAccess($reason) {
        $clientIp = self::getClientIp();
        self::logSecurityEvent('ACCESS_BLOCKED', $clientIp, $reason);

        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');

        // Não revela detalhes do bloqueio por segurança
        die('Access Denied');
    }
}
