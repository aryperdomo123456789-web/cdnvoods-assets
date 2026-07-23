<?php
/**
 * ═══════════════════════════════════════════════════════════════════
 * MAGO GATEWAY V3 - Script de Correção de Domínios
 * ═══════════════════════════════════════════════════════════════════
 * Este script corrige proxies antigos salvos apenas com prefixo,
 * adicionando automaticamente o domínio base configurado.
 *
 * USO: php fix_domains.php
 * ═══════════════════════════════════════════════════════════════════
 */

require_once '../config.php';

// CONFIGURAÇÃO: Defina seu domínio base aqui
$DOMAIN_BASE = '.dnsmain.site'; // ← ALTERE SE NECESSÁRIO

echo "═══════════════════════════════════════════════════════════════\n";
echo "🔧 MAGO GATEWAY V3 - Correção de Domínios\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Carrega banco de dados
$db_file = DB_FILE;

if (!file_exists($db_file)) {
    echo "❌ ERRO: Arquivo proxies.json não encontrado!\n";
    echo "   Caminho: $db_file\n\n";
    exit(1);
}

$proxies = json_decode(file_get_contents($db_file), true);

if (!is_array($proxies)) {
    echo "❌ ERRO: proxies.json inválido ou corrompido!\n\n";
    exit(1);
}

$total = count($proxies);
echo "📊 Total de proxies encontrados: $total\n\n";

if ($total === 0) {
    echo "ℹ️  Nenhum proxy cadastrado ainda.\n";
    echo "✅ Nada para corrigir!\n\n";
    exit(0);
}

// Verifica e corrige cada proxy
$fixed = 0;
$already_ok = 0;
$errors = 0;

echo "🔍 Analisando proxies...\n\n";

foreach ($proxies as $id => &$proxy) {
    $dominio = $proxy['dominio'] ?? '';

    if (empty($dominio)) {
        echo "⚠️  Proxy #" . ($id + 1) . ": Domínio vazio (ignorado)\n";
        $errors++;
        continue;
    }

    // Verifica se domínio contém ponto (é completo)
    if (strpos($dominio, '.') !== false) {
        echo "✅ Proxy #" . ($id + 1) . ": $dominio (OK)\n";
        $already_ok++;
        continue;
    }

    // Domínio é apenas prefixo, precisa correção
    $old_domain = $dominio;
    $new_domain = $dominio . $DOMAIN_BASE;

    $proxy['dominio'] = $new_domain;
    $fixed++;

    echo "🔧 Proxy #" . ($id + 1) . ":\n";
    echo "   Antes: $old_domain\n";
    echo "   Agora: $new_domain\n";
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "📊 RESUMO DA CORREÇÃO\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

echo "Total de proxies:      $total\n";
echo "Já estavam corretos:   $already_ok\n";
echo "Corrigidos agora:      $fixed\n";
echo "Erros/Ignorados:       $errors\n\n";

// Salva as alterações se houver correções
if ($fixed > 0) {
    echo "💾 Salvando alterações...\n";

    // Faz backup primeiro
    $backup_file = $db_file . '.backup_' . date('Y-m-d_H-i-s');
    copy($db_file, $backup_file);
    echo "📦 Backup criado: " . basename($backup_file) . "\n";

    // Salva o arquivo corrigido
    $json_content = json_encode($proxies, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($db_file, $json_content);

    echo "✅ Arquivo proxies.json atualizado com sucesso!\n\n";

    echo "🎯 PRÓXIMOS PASSOS:\n";
    echo "   1. Recarregue o painel administrativo\n";
    echo "   2. Verifique se os domínios estão corretos\n";
    echo "   3. Teste o botão copiar\n";
    echo "   4. Se algo der errado, restaure o backup:\n";
    echo "      cp " . basename($backup_file) . " proxies.json\n\n";

} elseif ($already_ok === $total) {
    echo "✅ Todos os domínios já estão corretos!\n";
    echo "ℹ️  Nenhuma alteração necessária.\n\n";
} else {
    echo "ℹ️  Nenhuma correção aplicada.\n\n";
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "🏁 SCRIPT CONCLUÍDO\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit(0);
