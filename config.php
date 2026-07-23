<?php
/**
 * ═══════════════════════════════════════════════════════════════════
 * MAGO GATEWAY V3 (STEALTH MODE) - Configuração Central
 * ═══════════════════════════════════════════════════════════════════
 * Sistema de Proxy Gateway com Proteção Anti-Sniffing
 * - Integração Cloudflare API V4
 * - Tokenização por IP
 * - Whitelist de User-Agent
 * - Modo Stealth para VOD
 * ═══════════════════════════════════════════════════════════════════
 */

// CONFIGURAÇÕES DE AUTENTICAÇÃO DO PAINEL
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin'); // ALTERE APÓS A INSTALAÇÃO!

// CONFIGURAÇÕES DA API CLOUDFLARE V4
define('CLOUDFLARE_EMAIL', 'Pdmagopd@gmail.com');
define('CLOUDFLARE_ZONE_ID', '842ceb5d21a1d8783a8e500c8eaceb17');
define('CLOUDFLARE_API_TOKEN', 'fciG3mhkx8fcksBOlmm7oak8FUohymxZJjMYYOJ-');

// DOMÍNIO PRINCIPAL DO SISTEMA (Utilizado para exibição e links)
define('BASE_DOMAIN', 'dnsmain.site'); 

// URL BASE DA API CLOUDFLARE
define('CLOUDFLARE_API_URL', 'https://api.cloudflare.com/client/v4');

// CONFIGURAÇÕES DO SISTEMA
define('DB_FILE', __DIR__ . '/mago-manager/proxies.json');
define('SESSION_NAME', 'MAGO_GATEWAY_SESSION');
define('SESSION_TIMEOUT', 3600); // 1 hora

/**
 * ═══════════════════════════════════════════════════════════════════
 * CONFIGURAÇÕES DE SEGURANÇA V3 (STEALTH MODE)
 * ═══════════════════════════════════════════════════════════════════
 */

// SECRET KEY para geração de tokens (ALTERE PARA UM VALOR ÚNICO!)
// Use: openssl rand -base64 32
define('TOKEN_SECRET_KEY', 'MaG0_GaT3w4y_S3cR3t_K3y_2026_Ch4nG3_Th1s_N0w!');

// WHITELIST DE USER-AGENT (proteção contra sniffers)
// Somente requisições com este User-Agent serão aceitas
define('ALLOWED_USER_AGENT', 'MagoPlayer/3.0');

// ATIVAR VALIDAÇÃO DE USER-AGENT GLOBAL
// true = bloqueia tudo que não for o User-Agent permitido
// false = aceita qualquer User-Agent (modo compatibilidade)
define('ENFORCE_USER_AGENT', true);

// TEMPO DE VALIDADE DO TOKEN (em segundos)
// 7200 = 2 horas (tempo que o token permanece válido)
define('TOKEN_EXPIRATION', 7200);

// MODO STEALTH GLOBAL (padrão para novos proxies)
// 'stealth' = usa proxy reverso (Apache/Nginx) - RECOMENDADO PARA VOD
// 'direct' = usa redirecionamento direto (compatibilidade)
define('DEFAULT_PROXY_MODE', 'stealth');

// HABILITAR LOGS DE SEGURANÇA
define('SECURITY_LOGS', true);

// ARQUIVO DE LOG DE SEGURANÇA
define('SECURITY_LOG_FILE', __DIR__ . '/security.log');

/**
 * ═══════════════════════════════════════════════════════════════════
 * CONFIGURAÇÕES DE PERFORMANCE
 * ═══════════════════════════════════════════════════════════════════
 */

// HABILITAR CACHE DE TOKENS (reduz processamento)
define('ENABLE_TOKEN_CACHE', true);

// TIMEOUT PARA REQUISIÇÕES PROXY (em segundos)
define('PROXY_TIMEOUT', 30);
define('PROXY_CONNECT_TIMEOUT', 10);

// TIMEZONE
date_default_timezone_set('America/Sao_Paulo');

// CONFIGURAÇÕES DE ERRO (PRODUÇÃO)
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

/**
 * ═══════════════════════════════════════════════════════════════════
 * PADRÕES DE URL PARA DETECÇÃO DE CONTEÚDO VOD
 * ═══════════════════════════════════════════════════════════════════
 * Extensões de vídeo que devem ser protegidas com token
 */
define('VOD_EXTENSIONS', [
    '.mp4',
    '.mkv',
    '.avi',
    '.mov',
    '.m4v',
    '.ts',
    '.m3u8',
    '.mpd'
]);

/**
 * ═══════════════════════════════════════════════════════════════════
 * MENSAGENS DE ERRO PERSONALIZADAS
 * ═══════════════════════════════════════════════════════════════════
 */
define('ERROR_MSG_INVALID_TOKEN', 'Access Denied: Invalid or expired token');
define('ERROR_MSG_INVALID_USER_AGENT', 'Access Denied: Unauthorized client');
define('ERROR_MSG_IP_MISMATCH', 'Access Denied: IP address mismatch');