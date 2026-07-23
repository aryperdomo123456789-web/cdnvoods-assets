<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (!SettingsRepository::seeded()) {
    header('Location: /setup.php');
    exit;
}

if (Auth::check()) {
    header('Location: /dashboard.php');
    exit;
}

header('Location: /login.php');
exit;
