<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

$settings = SettingsRepository::all();
$config = NginxGenerator::render($settings);

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: inline; filename="proxy-mago.conf"');
echo $config;
