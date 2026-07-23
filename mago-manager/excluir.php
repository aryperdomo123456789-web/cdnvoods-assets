<?php
/**
 * ═══════════════════════════════════════════════════════════════════
 * MAGO GATEWAY V3 - Exclusão de Proxy
 * ═══════════════════════════════════════════════════════════════════
 * Remove proxy do banco (NÃO remove da Cloudflare automaticamente)
 * ═══════════════════════════════════════════════════════════════════
 */

require_once '../config.php';
require_once 'auth.php';

// Verifica autenticação
verificarAutenticacao();

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$id = (int)$_GET['id'];

// Carrega banco de dados
$db_file = DB_FILE;
if (!file_exists($db_file)) {
    header('Location: index.php');
    exit;
}

$proxies = json_decode(file_get_contents($db_file), true) ?: [];

// Verifica se o ID existe
if (!isset($proxies[$id])) {
    $_SESSION['erro'] = 'Proxy não encontrado!';
    header('Location: index.php');
    exit;
}

// Armazena informações para o log
$proxyRemovido = $proxies[$id];

/**
 * ═══════════════════════════════════════════════════════════════════
 * ETAPA 1: Remove do banco de dados
 * ═══════════════════════════════════════════════════════════════════
 */
array_splice($proxies, $id, 1);
file_put_contents($db_file, json_encode($proxies, JSON_PRETTY_PRINT));

/**
 * ═══════════════════════════════════════════════════════════════════
 * ETAPA 2: Log da operação
 * ═══════════════════════════════════════════════════════════════════
 */
$logMsg = sprintf(
    "[%s] Proxy removido - Domínio: %s | IP: %s | Modo: %s | Usuário: %s\n",
    date('Y-m-d H:i:s'),
    $proxyRemovido['dominio'],
    $proxyRemovido['ip_xui'],
    strtoupper($proxyRemovido['modo'] ?? 'direct'),
    $_SESSION['mago_usuario']
);
error_log($logMsg, 3, __DIR__ . '/../proxy.log');

/**
 * ═══════════════════════════════════════════════════════════════════
 * NOTA IMPORTANTE
 * ═══════════════════════════════════════════════════════════════════
 * A remoção do registro DNS da Cloudflare NÃO é feita automaticamente
 * para evitar exclusões acidentais. Se desejar remover também da
 * Cloudflare, você pode descomentar o código abaixo:
 *
 * require_once 'cloudflare.php';
 * $cf = new CloudflareAPI();
 * if (isset($proxyRemovido['cloudflare_record_id'])) {
 *     $cf->deletarRegistro($proxyRemovido['cloudflare_record_id']);
 * }
 * ═══════════════════════════════════════════════════════════════════
 */

$_SESSION['sucesso'] = "Proxy removido com sucesso!\n\nDomínio: {$proxyRemovido['dominio']}\n\nOBSERVAÇÃO: O registro DNS ainda existe na Cloudflare. Remova manualmente se necessário.";
header('Location: index.php');
exit;
