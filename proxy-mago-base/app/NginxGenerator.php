<?php

/**
 * Gera o vhost do Nginx para a VPS real (Ubuntu 22.04, /opt/proxy-mago/proxy-mago-base).
 *
 * Dois papéis TOTALMENTE separados:
 *
 *  1. PAINEL  -> server_name = panel_domain. Serve o PHP do painel (login,
 *     dashboard, setup). Pode ter TLS e, se quiser, forçar HTTPS.
 *
 *  2. PÚBLICO -> `default_server` na porta 80 (e 443 quando houver certificado).
 *     Atende QUALQUER domínio de cliente apontado para a VPS. Só existe proxy:
 *     nenhum arquivo do painel é servido, e NUNCA há redirect 80 -> 443
 *     (players em http puro precisam funcionar).
 */
final class NginxGenerator
{
    public static function render(array $settings): string
    {
        $panelDomain = trim((string) ($settings['panel_domain'] ?? ''));
        $secret = trim((string) ($settings['app_secret'] ?? ''));
        $phpFpmSocket = (string) ($settings['php_fpm_socket'] ?? Config::get('php_fpm_socket'));
        $panelPath = rtrim((string) ($settings['panel_path'] ?? Config::get('panel_path')), '/');
        $sslCertPath = (string) ($settings['ssl_cert_path'] ?? Config::get('ssl_cert_path'));
        $sslKeyPath = (string) ($settings['ssl_key_path'] ?? Config::get('ssl_key_path'));
        $forceHttps = filter_var($settings['force_https'] ?? Config::get('force_https', true), FILTER_VALIDATE_BOOL);
        $sslEnabled = $sslCertPath !== '' && $sslKeyPath !== '';

        if ($panelDomain !== '' && !filter_var($panelDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new InvalidArgumentException('Domínio do painel inválido.');
        }
        if ($secret === '' || !preg_match('/^[A-Za-z0-9_-]{32,128}$/', $secret)) {
            throw new InvalidArgumentException('Segredo interno inválido.');
        }
        if (!preg_match('#^/run/php/[A-Za-z0-9._-]+\.sock$#', $phpFpmSocket)) {
            throw new InvalidArgumentException('Socket PHP-FPM inválido.');
        }
        if (!preg_match('#^/[A-Za-z0-9._/-]+$#', $panelPath)) {
            throw new InvalidArgumentException('Caminho do painel inválido.');
        }
        if ($sslCertPath !== '' && !preg_match('#^/[A-Za-z0-9._/-]+$#', $sslCertPath)) {
            throw new InvalidArgumentException('Caminho do certificado SSL inválido.');
        }
        if ($sslKeyPath !== '' && !preg_match('#^/[A-Za-z0-9._/-]+$#', $sslKeyPath)) {
            throw new InvalidArgumentException('Caminho da chave SSL inválido.');
        }

        $blocks = [];

        // ---------- PÚBLICO (aliases dos clientes) ----------
        $blocks[] = self::publicServer(80, false, $panelPath, $phpFpmSocket, $secret, '', '');
        if ($sslEnabled) {
            $blocks[] = self::publicServer(443, true, $panelPath, $phpFpmSocket, $secret, $sslCertPath, $sslKeyPath);
        }

        // ---------- PAINEL ----------
        if ($panelDomain !== '') {
            if ($sslEnabled) {
                $blocks[] = $forceHttps
                    ? self::panelRedirect($panelDomain)
                    : self::panelServer(80, false, $panelDomain, $panelPath, $phpFpmSocket, $secret, '', '');
                $blocks[] = self::panelServer(443, true, $panelDomain, $panelPath, $phpFpmSocket, $secret, $sslCertPath, $sslKeyPath);
            } else {
                $blocks[] = self::panelServer(80, false, $panelDomain, $panelPath, $phpFpmSocket, $secret, '', '');
            }
        }

        $body = implode("\n", $blocks);

        return <<<NGINX
# Gerado pelo painel Proxy Mago (VPS 45.140.192.237 / Ubuntu 22.04).
# NUNCA expõe host, IP ou credenciais da origem XUI: todo o proxy acontece
# dentro do PHP (public/proxy.php), que lê a origem protegida do SQLite.
#
# - Bloco default_server = domínios públicos dos clientes (só proxy, sem TLS
#   obrigatório, sem painel).
# - Bloco do painel = apenas o domínio administrativo.

{$body}
NGINX;
    }

    private static function sslBlock(bool $https, string $cert, string $key): string
    {
        if (!$https) return '';
        return "    ssl_certificate {$cert};\n"
            . "    ssl_certificate_key {$key};\n"
            . "    ssl_protocols TLSv1.2 TLSv1.3;\n"
            . "    ssl_session_cache shared:SSL:10m;\n"
            . "    ssl_session_timeout 1d;\n\n";
    }

    /** Domínios públicos: tudo cai no proxy.php, nada do painel é exposto. */
    private static function publicServer(
        int $port,
        bool $https,
        string $panelPath,
        string $phpFpmSocket,
        string $secret,
        string $cert,
        string $key
    ): string {
        $listen = $https
            ? "    listen {$port} ssl http2 default_server;"
            : "    listen {$port} default_server;";
        $ssl = self::sslBlock($https, $cert, $key);
        $httpsParam = $https ? "        fastcgi_param HTTPS on;\n" : '';

        return <<<NGINX
server {
{$listen}
    server_name _;

{$ssl}    root {$panelPath}/public;

    # Query strings XUI carregam username/password: nunca persistir em disco.
    access_log off;
    error_log  {$panelPath}/storage/logs/error.log warn;

    server_tokens off;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy no-referrer always;

    client_max_body_size 1m;
    # Proxy de vídeo: sem buffer, sem cache, latência baixa.
    fastcgi_buffering off;
    proxy_buffering off;
    tcp_nodelay on;
    sendfile off;

    # Painel NÃO existe nos domínios públicos.
    location = /                { return 404; }
    location = /index.php       { return 404; }
    location = /login.php       { return 404; }
    location = /dashboard.php   { return 404; }
    location = /avancado.php    { return 404; }
    location = /setup.php       { return 404; }
    location = /health.php      { return 404; }
    location ^~ /assets/        { return 404; }

    # Todo o resto é stream/playlist e vai para o proxy.
    location / {
        include fastcgi_params;
        fastcgi_pass unix:{$phpFpmSocket};
        fastcgi_param SCRIPT_FILENAME \$document_root/proxy.php;
        fastcgi_param SCRIPT_NAME /proxy.php;
        fastcgi_read_timeout 3600;
        fastcgi_send_timeout 3600;
        fastcgi_buffering off;
        fastcgi_param HTTP_X_PROXY_SECRET "{$secret}";
{$httpsParam}    }
}
NGINX;
    }

    private static function panelRedirect(string $panelDomain): string
    {
        return <<<NGINX
server {
    listen 80;
    server_name {$panelDomain};
    return 301 https://\$host\$request_uri;
}
NGINX;
    }

    /** Painel administrativo: só no domínio do painel. */
    private static function panelServer(
        int $port,
        bool $https,
        string $panelDomain,
        string $panelPath,
        string $phpFpmSocket,
        string $secret,
        string $cert,
        string $key
    ): string {
        $listen = $https ? "    listen {$port} ssl http2;" : "    listen {$port};";
        $ssl = self::sslBlock($https, $cert, $key);
        $httpsParam = $https ? "        fastcgi_param HTTPS on;\n" : '';

        return <<<NGINX
server {
{$listen}
    server_name {$panelDomain};

{$ssl}    root {$panelPath}/public;
    index index.php;

    access_log off;
    error_log  {$panelPath}/storage/logs/error.log warn;

    server_tokens off;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy no-referrer always;

    client_max_body_size 4m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_pass unix:{$phpFpmSocket};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 120;
        fastcgi_param HTTP_X_PROXY_SECRET "{$secret}";
{$httpsParam}    }
}
NGINX;
    }
}
