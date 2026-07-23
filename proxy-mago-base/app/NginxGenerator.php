<?php

final class NginxGenerator
{
    public static function render(array $settings): string
    {
        $panelDomain = trim((string) ($settings['panel_domain'] ?? ''));
        $secret = trim((string) ($settings['app_secret'] ?? ''));
        $phpFpmSocket = (string) ($settings['php_fpm_socket'] ?? Config::get('php_fpm_socket'));
        $panelPath = rtrim((string) ($settings['panel_path'] ?? Config::get('panel_path')), '/');
        $serverName = $panelDomain !== '' ? $panelDomain : '_';

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

        return <<<NGINX
# Gerado pelo painel Proxy Mago. NÃO expõe o IP da origem.
# Todo o proxy real acontece dentro do PHP (public/proxy.php), que puxa
# do XUI protegido registrado no SQLite. O Nginx apenas encaminha para o PHP.

server {
    listen 80;
    server_name {$serverName};

    root {$panelPath}/public;
    index index.php;

    # Desligado para nunca persistir username/password presentes em query strings XUI.
    # O proxy mantém seu próprio access_log sanitizado no SQLite.
    access_log off;
    error_log  {$panelPath}/storage/logs/error.log;

    # Nunca revele origem em headers
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy no-referrer always;

    client_max_body_size 4m;

    # Painel administrativo
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # Entrada unica do proxy de streaming (playlists e segmentos)
    location = /get.php        { try_files __proxy_mago__ @stream_proxy; }
    location = /player_api.php { try_files __proxy_mago__ @stream_proxy; }
    location = /xmltv.php      { try_files __proxy_mago__ @stream_proxy; }
    location ~ \.m3u8?$        { try_files __proxy_mago__ @stream_proxy; }
    location ~ \.ts$           { try_files __proxy_mago__ @stream_proxy; }
    location /hls/             { try_files __proxy_mago__ @stream_proxy; }
    location /live/            { try_files __proxy_mago__ @stream_proxy; }
    location /movie/           { try_files __proxy_mago__ @stream_proxy; }
    location /series/          { try_files __proxy_mago__ @stream_proxy; }

    location @stream_proxy {
        include fastcgi_params;
        fastcgi_pass unix:{$phpFpmSocket};
        fastcgi_param SCRIPT_FILENAME \$document_root/proxy.php;
        fastcgi_read_timeout 3600;
        fastcgi_buffering off;
        fastcgi_param HTTP_X_PROXY_SECRET "{$secret}";
    }

    location ~ \.php$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_pass unix:{$phpFpmSocket};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 3600;
        fastcgi_buffering off;
        # Segredo interno consumido por AccessGuard::verifySharedSecret()
        fastcgi_param HTTP_X_PROXY_SECRET "{$secret}";
    }
}
NGINX;
    }
}
