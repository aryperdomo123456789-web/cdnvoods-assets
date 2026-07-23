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

        return <<<NGINX
# Gerado pelo painel Proxy Mago. NÃO expõe o IP da origem.
# Todo o proxy real acontece dentro do PHP (public/proxy.php), que puxa
# do XUI protegido registrado no SQLite. O Nginx apenas encaminha para o PHP.

server {
    listen 80;
    server_name {$serverName};

    root {$panelPath}/public;
    index index.php;

    access_log {$panelPath}/storage/logs/access.log;
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
    location = /get.php   { try_files /proxy.php /proxy.php; rewrite ^ /proxy.php last; }
    location ~' \.m3u8?$  { rewrite ^ /proxy.php last; }
    location ~' \.ts$     { rewrite ^ /proxy.php last; }
    location /hls/        { rewrite ^ /proxy.php last; }
    location /live/       { rewrite ^ /proxy.php last; }
    location /movie/      { rewrite ^ /proxy.php last; }
    location /series/     { rewrite ^ /proxy.php last; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:{$phpFpmSocket};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 3600;
        fastcgi_buffering off;
        # Segredo interno consumido por AccessGuard::verifySharedSecret()
        fastcgi_param HTTP_X_PROXY_SECRET "{\$secret}";
    }
}
NGINX;
    }
}
