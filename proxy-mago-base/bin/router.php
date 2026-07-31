<?php
// Router do servidor embutido do PHP (dev/lab). Em produção quem roteia é o Nginx.
// Regra: arquivo existente em public/ é servido direto (painel); qualquer outra
// rota — inclusive get.php, player_api.php e xmltv.php — cai no proxy.
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/../public' . $uri;
if ($uri !== '/' && is_file($file)) { return false; }
if ($uri === '/') { require __DIR__ . '/../public/index.php'; return true; }
$_SERVER['SCRIPT_NAME'] = '/proxy.php';
require __DIR__ . '/../public/proxy.php';
return true;
