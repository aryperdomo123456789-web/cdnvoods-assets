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
        $phpFpmControlSocket = (string) ($settings['php_fpm_control_socket'] ?? Config::get('php_fpm_control_socket', $phpFpmSocket));
        $publicGoUpstream = trim((string) ($settings['public_go_upstream'] ?? Config::get('public_go_upstream', '')));
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
        $blocks[] = self::publicServer(80, false, $panelPath, $phpFpmSocket, $publicGoUpstream, $secret, '', '');
        if ($sslEnabled) {
            $blocks[] = self::publicServer(443, true, $panelPath, $phpFpmSocket, $publicGoUpstream, $secret, $sslCertPath, $sslKeyPath);
        }

        // ---------- PAINEL ----------
        if ($panelDomain !== '') {
            if ($sslEnabled) {
                $blocks[] = $forceHttps
                    ? self::panelRedirect($panelDomain)
                    : self::panelServer(80, false, $panelDomain, $panelPath, $phpFpmControlSocket, $secret, '', '');
                $blocks[] = self::panelServer(443, true, $panelDomain, $panelPath, $phpFpmControlSocket, $secret, $sslCertPath, $sslKeyPath);
            } else {
                $blocks[] = self::panelServer(80, false, $panelDomain, $panelPath, $phpFpmControlSocket, $secret, '', '');
            }
        }

        $body = implode("\n", $blocks);

        return <<<NGINX
# Gerado pelo painel Proxy Mago.
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
        string $publicGoUpstream,
        string $secret,
        string $cert,
        string $key
    ): string {
        $listen = $https
            ? "    listen {$port} ssl http2 default_server;"
            : "    listen {$port} default_server;";
        $ssl = self::sslBlock($https, $cert, $key);
        $httpsParam = $https ? "        fastcgi_param HTTPS on;\n" : '';
        $out = "server {\n";
        $out .= $listen . "\n";
        $out .= "    server_name _;\n\n";
        $out .= $ssl . "    root {$panelPath}/public;\n\n";
        $out .= "    # Query strings XUI carregam username/password: nunca persistir em disco.\n";
        $out .= "    access_log off;\n";
        $out .= "    error_log  {$panelPath}/storage/logs/error.log warn;\n\n";
        $out .= "    server_tokens off;\n";
        $out .= "    add_header X-Content-Type-Options nosniff always;\n";
        $out .= "    add_header Referrer-Policy no-referrer always;\n\n";
        $out .= "    client_max_body_size 1m;\n";
        $out .= "    # Proxy de vídeo: sem buffer, sem cache, latência baixa.\n";
        $out .= "    fastcgi_buffering off;\n";
        $out .= "    proxy_buffering off;\n";
        $out .= "    tcp_nodelay on;\n";
        $out .= "    sendfile off;\n\n";
        $out .= "    # Painel NÃO existe nos domínios públicos.\n";
        $out .= "    location = /                { include fastcgi_params; fastcgi_pass unix:{$phpFpmSocket}; fastcgi_param SCRIPT_FILENAME \$document_root/proxy.php; fastcgi_param SCRIPT_NAME /proxy.php; fastcgi_read_timeout 3600; fastcgi_send_timeout 3600; fastcgi_buffering off; }\n";
        $out .= "    location = /index.php       { return 404; }\n";
        $out .= "    location = /login.php       { return 404; }\n";
        $out .= "    location = /dashboard.php   { return 404; }\n";
        $out .= "    location = /avancado.php    { return 404; }\n";
        $out .= "    location = /setup.php       { return 404; }\n";
        $out .= "    location = /health.php      { return 404; }\n";
        $out .= "    location ^~ /assets/        { return 404; }\n";
        $out .= "    location = /lb-contract.php { include fastcgi_params; fastcgi_pass unix:{$phpFpmSocket}; fastcgi_param SCRIPT_FILENAME \$document_root/lb-contract.php; fastcgi_param SCRIPT_NAME /lb-contract.php; }\n";
        $out .= "    location = /lb-events.php   { include fastcgi_params; fastcgi_pass unix:{$phpFpmSocket}; fastcgi_param SCRIPT_FILENAME \$document_root/lb-events.php; fastcgi_param SCRIPT_NAME /lb-events.php; }\n";
        $out .= "    location = /lb-ingest.php   { include fastcgi_params; fastcgi_pass unix:{$phpFpmSocket}; fastcgi_param SCRIPT_FILENAME \$document_root/lb-ingest.php; fastcgi_param SCRIPT_NAME /lb-ingest.php; }\n\n";
        $out .= "    # Todo o resto é stream/playlist e vai para o proxy.\n";
        $out .= "    location / {\n";
        $out .= "        proxy_http_version 1.1;\n";
        $out .= "        proxy_set_header Host \$host;\n";
        $out .= "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n";
        $out .= "        proxy_set_header X-Real-IP \$remote_addr;\n";
        $out .= "        proxy_set_header X-Forwarded-Proto \$scheme;\n";
        $out .= "        proxy_set_header X-Proxy-Secret \"{$secret}\";\n";
        $out .= "        proxy_set_header Connection \"\";\n";
        $out .= "        proxy_buffering off;\n";
        $out .= "        proxy_request_buffering off;\n";
        $out .= "        proxy_connect_timeout 10s;\n";
        $out .= "        proxy_send_timeout 3600s;\n";
        $out .= "        proxy_read_timeout 3600s;\n";
        $out .= "        proxy_next_upstream error timeout invalid_header http_500 http_502 http_503 http_504;\n";
        if ($publicGoUpstream !== '') {
            $out .= "        proxy_pass {$publicGoUpstream};\n";
        } else {
            $out .= "        include fastcgi_params;\n";
            $out .= "        fastcgi_pass unix:{$phpFpmSocket};\n";
            $out .= "        fastcgi_param SCRIPT_FILENAME \$document_root/proxy.php;\n";
            $out .= "        fastcgi_param SCRIPT_NAME /proxy.php;\n";
            $out .= "        fastcgi_read_timeout 3600;\n";
            $out .= "        fastcgi_send_timeout 3600;\n";
            $out .= "        fastcgi_buffering off;\n";
            $out .= "        fastcgi_param HTTP_X_PROXY_SECRET \"{$secret}\";\n";
        }
        $out .= "{$httpsParam}    }\n";
        $out .= "}\n";
        return $out;
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
