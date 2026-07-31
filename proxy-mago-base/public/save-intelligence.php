<?php

/**
 * Configuração da inteligência da CDN (sessões locais, direct source e o que
 * fazer quando o usuário estoura o limite do plano).
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();
csrf_verify();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /restream.php');
    exit;
}

$mode = (string) ($_POST['limit_mode'] ?? 'alert');
if (!in_array($mode, ['alert', 'mark', 'block'], true)) {
    $mode = 'alert';
}
$tolerance = max(0, min(600, (int) ($_POST['limit_tolerance_seconds'] ?? 45)));

SettingsRepository::set('limit_mode', $mode);
SettingsRepository::set('limit_tolerance_seconds', $tolerance);
SettingsRepository::set('cdn_sessions_enabled', isset($_POST['cdn_sessions_enabled']) ? 1 : 0);
SettingsRepository::set('direct_source_trace', isset($_POST['direct_source_trace']) ? 1 : 0);

Audit::log('intelligence_settings', sprintf('limit_mode=%s tolerance=%ds', $mode, $tolerance));
$_SESSION['flash'] = sprintf('Regras atualizadas: modo %s, tolerância de %ds.', $mode, $tolerance);

header('Location: /restream.php');
exit;
