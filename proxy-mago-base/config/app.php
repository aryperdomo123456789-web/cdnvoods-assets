<?php

return [
    'app_name' => 'Proxy Mago',
    'session_name' => 'PROXY_MAGO_SESSION',
    'db_driver' => 'sqlite',
    'db_path' => __DIR__ . '/../storage/app.sqlite',
    'db_host' => '127.0.0.1',
    'db_port' => 5432,
    'db_name' => 'proxy_mago',
    'db_user' => 'proxy_mago',
    'db_pass' => '',
    'db_sslmode' => 'prefer',
    'nginx_conf_path' => '/etc/nginx/sites-available/proxy-mago.conf',
    'nginx_conf_link' => '/etc/nginx/sites-enabled/proxy-mago.conf',
    'php_fpm_socket' => '/run/php/php8.1-fpm.sock',
    'panel_path' => '/opt/proxy-mago/proxy-mago-base',
    'ssl_cert_path' => '/etc/ssl/cloudflare/cdnvoods.pem',
    'ssl_key_path' => '/etc/ssl/cloudflare/cdnvoods.key',
    'force_https' => false, // nunca forçar HTTPS: domínios públicos de clientes rodam em http puro
    'allowed_user_agent' => '',
    'token_ttl' => 21600, // 6h por padrao
    'rate_limit_per_minute' => 240,
    'default_panel_domain' => '',
];
