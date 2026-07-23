<?php

final class NginxGenerator
{
    public static function render(array $settings): string
    {
        $panelDomain = trim((string) ($settings['panel_domain'] ?? ''));
        $originHost = trim((string) ($settings['origin_host'] ?? ''));
        $originPort = (int) ($settings['origin_port'] ?? 80);
        $secret = trim((string) ($settings['app_secret'] ?? ''));
        $userAgent = trim((string) ($settings['allowed_user_agent'] ?? Config::get('allowed_user_agent')));
        $phpFpmSocket = (string) ($settings['php_fpm_socket'] ?? Config::get('php_fpm_socket'));
        $panelPath = rtrim((string) ($settings['panel_path'] ?? Config::get('panel_path')), '/');

        $serverName = $panelDomain !== '' ? $panelDomain : '_';
        $originUpstream = $originHost . ':' . $originPort;

        return <<<NGINX
server {
    listen 80;
    server_name {$serverName};

    root {$panelPath}/public;
    index index.php;

    access_log {$panelPath}/storage/logs/access.log;
    error_log {$panelPath}/storage/logs/error.log;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy no-referrer always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location /media/ {
        proxy_pass http://{$originUpstream};
        proxy_http_version 1.1;
        proxy_set_header Host {$originHost};
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header User-Agent "{$userAgent}";
        proxy_set_header X-Proxy-Secret "{$secret}";
        proxy_buffering off;
        proxy_request_buffering off;
        proxy_read_timeout 3600;
        proxy_send_timeout 3600;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:{$phpFpmSocket};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }
}
NGINX;
    }
}
