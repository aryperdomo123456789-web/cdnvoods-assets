<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

$settings = SettingsRepository::all();
$adminUser = (string) ($settings['admin_user'] ?? '');
$enabled = (int) ($settings['admin_2fa_enabled'] ?? 0) === 1;
$secret = (string) ($settings['admin_2fa_secret'] ?? '');
$generated = '';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($secret === '') {
    $generated = TotpAuth::randomSecret();
    $secret = $generated;
}

$issuer = 'Proxy Mago';
$uri = TotpAuth::provisioningUri($issuer, $adminUser !== '' ? $adminUser : 'admin', $secret);
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&margin=8&data=' . rawurlencode($uri);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Usuário | Proxy Mago</title>
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        .twofa{display:grid;grid-template-columns:240px 1fr;gap:18px;align-items:start}
        .qr{background:#fff;padding:12px;border-radius:18px;display:grid;place-items:center;box-shadow:0 12px 40px rgba(0,0,0,.25)}
        .secret{font:600 14px/1.4 ui-monospace,monospace;word-break:break-all;background:#0b1220;color:#d1fae5;padding:10px 12px;border-radius:10px}
        @media (max-width:720px){.twofa{grid-template-columns:1fr}.qr{justify-self:start}}
    </style>
</head>
<body class="page-bg">
<header class="topbar">
    <div>
        <strong>Proxy Mago</strong>
        <span>Usuário e 2FA</span>
    </div>
    <nav>
        <a href="/dashboard.php">Domínios</a>
        <a href="/usuario.php">Usuário</a>
        <a href="/avancado.php">Avançado</a>
        <a href="/logout.php">Sair</a>
    </nav>
</header>

<main class="grid">
    <section class="card full">
        <h2>Conta do administrador</h2>
        <p>O login continua normal com usuário e senha. Nesta aba você vincula o app autenticador por QR code.</p>
        <?php if ($flash): ?><div class="alert success"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>
        <form method="post" action="/save-user.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <label>Usuário admin</label>
            <input name="admin_user" value="<?php echo htmlspecialchars($adminUser); ?>" required>
            <label>Nova senha admin</label>
            <input name="admin_pass" type="password" placeholder="Deixe em branco para manter">
            <button type="submit">Salvar conta</button>
        </form>
    </section>

    <section class="card full">
        <h2>2FA com QR code</h2>
        <div class="twofa">
            <div class="qr">
                <img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="QR code 2FA" width="240" height="240">
            </div>
            <div>
                <p>Escaneie o QR code no Google Authenticator, Microsoft Authenticator, Aegis ou 1Password.</p>
                <p class="secret"><?php echo htmlspecialchars($uri); ?></p>
                <form method="post" action="/save-user.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="admin_user" value="<?php echo htmlspecialchars($adminUser); ?>">
                    <input type="hidden" name="admin_pass" value="">
                    <label>Código atual do autenticador</label>
                    <input name="verify_2fa_code" inputmode="numeric" autocomplete="one-time-code" placeholder="Digite os 6 dígitos do app">
                    <label><input type="checkbox" name="admin_2fa_enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?>> Ativar 2FA no login administrativo</label>
                    <label><input type="checkbox" name="regenerate_2fa_secret" value="1"> Gerar novo segredo 2FA</label>
                    <button type="submit">Salvar 2FA</button>
                </form>
                <?php if ($generated !== ''): ?>
                    <p><small>Segredo gerado agora. Escaneie e confirme com o código atual antes de ativar.</small></p>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>
</body>
</html>
