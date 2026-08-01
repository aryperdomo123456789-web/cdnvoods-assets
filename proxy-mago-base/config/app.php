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
    // FASE 2 — estado vivo (sessão, heartbeat, contador, trava por IP).
    // 'sqlite' = comportamento atual; 'redis' = destino oficial da Fase 2.
    // Pode ser trocado em settings (state_driver) sem deploy.
    'state_driver' => 'sqlite',
    'redis_host' => '127.0.0.1',
    'redis_port' => 6379,
    'redis_pass' => '',
    'redis_db' => 0,
    'redis_timeout' => 1.0,
    // FASE 2 — cérebro puro. Com 1, o main NÃO entrega conteúdo do XUI:
    // se o usuário não tiver músculo (LB) apto, o request é recusado em vez de
    // derrubar o cérebro. Só liga isso depois de ter LB instalado e saudável.
    'lb_require_delivery' => 0,
    // Modo aplicado a usuário que ainda não tem linha em lb_user_routes.
    // 'main_only' = comportamento histórico; 'auto' = todo mundo vai pro LB.
    'lb_default_mode' => 'main_only',
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
