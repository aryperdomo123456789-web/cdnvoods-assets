#!/usr/bin/env bash
# CDN Voods e um projeto PHP (roda na VPS via Nginx + PHP-FPM).
# Aqui so validamos a sintaxe PHP e geramos um dist/ estatico
# para satisfazer o pipeline de build do ambiente.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="$ROOT/proxy-mago-base"
PHP="nix run nixpkgs#php82 --"

echo "==> Lint PHP (app/, public/, bin/)"
fail=0
while IFS= read -r f; do
  if ! $PHP -l "$f" >/dev/null 2>&1; then
    echo "SYNTAX ERROR: $f"
    $PHP -l "$f" || true
    fail=1
  fi
done < <(find "$BASE/app" "$BASE/public" "$BASE/bin" -name '*.php' -not -path '*/_isolated/*' 2>/dev/null)
[ "$fail" -eq 0 ] || { echo "Lint falhou"; exit 1; }
echo "Lint OK"

echo "==> Gerando dist/"
rm -rf "$ROOT/dist"
mkdir -p "$ROOT/dist"
cat > "$ROOT/dist/index.html" <<'HTML'
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CDN Voods - Painel PHP (deploy na VPS)</title>
<meta name="description" content="CDN Voods: proxy PHP que protege a origem XUI. O painel roda na VPS com Nginx + PHP-FPM.">
<style>
body{margin:0;font-family:ui-sans-serif,system-ui,sans-serif;background:#0b0f14;color:#e6edf3;display:grid;place-items:center;min-height:100vh}
main{max-width:640px;padding:2rem;line-height:1.6}
code{background:#161b22;padding:.15rem .4rem;border-radius:4px}
h1{font-size:1.5rem}
</style>
</head>
<body>
<main>
<h1>CDN Voods - build PHP validado</h1>
<p>Este repositorio e uma aplicacao <strong>PHP</strong>. Nao existe bundle de front-end.</p>
<p>Deploy real: <code>/opt/proxy-mago/proxy-mago-base</code> na VPS Ubuntu 22.04 com Nginx + PHP-FPM,
usando <code>bin/deploy.sh</code>.</p>
<p>O build deste ambiente executa apenas o lint de sintaxe PHP de <code>app/</code>, <code>public/</code> e <code>bin/</code>.</p>
</main>
</body>
</html>
HTML
echo "dist/ gerado"
