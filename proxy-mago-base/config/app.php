<?php

return [
    'app_name' => 'Proxy Mago',
    'session_name' => 'PROXY_MAGO_SESSION',
    'db_path' => __DIR__ . '/../storage/app.sqlite',
    'nginx_conf_path' => '/etc/nginx/sites-available/proxy-mago.conf',
    'nginx_conf_link' => '/etc/nginx/sites-enabled/proxy-mago.conf',
    'php_fpm_socket' => '/run/php/php8.1-fpm.sock',
    'panel_path' => '/opt/proxy-mago/proxy-mago-base',
    'allowed_user_agent' => '',
    'token_ttl' => 21600, // 6h por padrao
    'rate_limit_per_minute' => 240,
    'default_panel_domain' => '',
];
