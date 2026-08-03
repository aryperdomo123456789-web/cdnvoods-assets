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

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $result = Auth::attemptLogin($username, $password);
    if (($result['ok'] ?? false) === true) {
        Audit::log('login', 'Admin login successful', $_SERVER['REMOTE_ADDR'] ?? '-', $_SERVER['HTTP_USER_AGENT'] ?? '-');
        header('Location: /dashboard.php');
        exit;
    }

    $reason = (string) ($result['reason'] ?? 'invalid');
    $error = match ($reason) {
        'locked' => 'Conta temporariamente bloqueada. Tente novamente mais tarde.',
        default => 'Usuário, senha ou código inválidos.',
    };
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | Proxy Mago</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="page-bg">
<main class="card compact">
    <h1>Proxy Mago</h1>
    <p>Entrar no painel.</p>
    <?php if ($error): ?><div class="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <label>Usuário</label>
        <input name="username" required>
        <label>Senha</label>
        <input name="password" type="password" required>
        <button type="submit">Entrar</button>
    </form>
</main>
</body>
</html>
