<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

Auth::logout();
header('Location: /login.php');
exit;
