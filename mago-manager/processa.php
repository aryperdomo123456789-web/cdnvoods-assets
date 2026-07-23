<?php
/**
 * ═══════════════════════════════════════════════════════════════════
 * MAGO GATEWAY V3 - Processamento de Novo Proxy
 * ═══════════════════════════════════════════════════════════════════
 * Adiciona proxy ao banco e cria registro DNS na Cloudflare
 * Suporta modos: Direct e Stealth
 * ═══════════════════════════════════════════════════════════════════
 */

require_once '../config.php';
require_once 'auth.php';
require_once 'cloudflare.php';

// Verifica autenticação
verificarAutenticacao();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Recebe e sanitiza os dados
$dominio = trim($_POST['dominio'] ?? '');
$ip_xui = trim($_POST['ip_xui'] ?? '');
$modo = trim($_POST['modo'] ?? DEFAULT_PROXY_MODE);

// Valida modo (aceita apenas 'direct' ou 'stealth')
if (!in_array($modo, ['direct', 'stealth'])) {
    $modo = DEFAULT_PROXY_MODE;
}

// Valida dados
if (empty($dominio) || empty($ip_xui)) {
    $_SESSION['erro'] = 'Domínio e IP são obrigatórios!';
    header('Location: index.php');
    exit;
}

// Instancia a API da Cloudflare
$cf = new CloudflareAPI();

// Valida formato dos dados
$validacao = $cf->validarDados($dominio, $ip_xui);
if (!$validacao['valido']) {
    $_SESSION['erro'] = $validacao['erro'];
    header('Location: index.php');
    exit;
}

// Carrega banco de dados
$db_file = DB_FILE;
if (!file_exists($db_file)) {
    file_put_contents($db_file, json_encode([]));
}
$proxies = json_decode(file_get_contents($db_file), true) ?: [];

// Verifica se o domínio já existe
foreach ($proxies as $p) {
    if ($p['dominio'] === $dominio) {
        $_SESSION['erro'] = 'Este domínio já está cadastrado!';
        header('Location: index.php');
        exit;
    }
}

/**
 * ═══════════════════════════════════════════════════════════════════
 * ETAPA 1: Criar registro DNS na Cloudflare
 * ═══════════════════════════════════════════════════════════════════
 */
$resultadoCF = $cf->criarRegistroA($dominio, $ip_xui);

if (!$resultadoCF['success']) {
    $_SESSION['erro'] = 'Erro ao criar DNS na Cloudflare: ' . $resultadoCF['error'];
    header('Location: index.php');
    exit;
}

$recordId = $resultadoCF['data']['id'] ?? null;

/**
 * ═══════════════════════════════════════════════════════════════════
 * ETAPA 2: Adicionar ao banco de dados local
 * ═══════════════════════════════════════════════════════════════════
 */
$proxies[] = [
    'dominio' => $dominio,
    'ip_xui' => $ip_xui,
    'modo' => $modo,
    'cloudflare_record_id' => $recordId,
    'criado_em' => date('Y-m-d H:i:s')
];

file_put_contents($db_file, json_encode($proxies, JSON_PRETTY_PRINT));

/**
 * ═══════════════════════════════════════════════════════════════════
 * ETAPA 3: Log da operação
 * ═══════════════════════════════════════════════════════════════════
 */
$logMsg = sprintf(
    "[%s] Proxy adicionado - Domínio: %s | IP: %s | Modo: %s | CF Record ID: %s | Usuário: %s\n",
    date('Y-m-d H:i:s'),
    $dominio,
    $ip_xui,
    strtoupper($modo),
    $recordId,
    $_SESSION['mago_usuario']
);
error_log($logMsg, 3, __DIR__ . '/../proxy.log');

$modoDesc = $modo === 'stealth' ? 'Stealth (Proteção Anti-Sniffing Ativada)' : 'Direct (Modo Compatibilidade)';

$_SESSION['sucesso'] = "Proxy criado com sucesso!\n\nDomínio: {$dominio}\nIP: {$ip_xui}\nModo: {$modoDesc}\n\nO registro DNS foi configurado automaticamente na Cloudflare (DNS Only).";
header('Location: index.php');
exit;
