# Relatório — Módulo "Código de App" (multi-XUI)

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Branch alvo: `main` de `aryperdomo123456789-web/cdnvoods-assets`
Alvo de produção: Ubuntu 22.04, `/opt/proxy-mago/proxy-mago-base`, PHP 8.1-FPM + Nginx + SQLite

## 1. Objetivo
Fazer UM código de app (com DNS fixo compilado, `assistservpd.phpd77.com`) atender
assinantes espalhados em VÁRIOS XUIs, sem embaralhar usuário/playlist/EPG,
sem pesar a CDN e sem quebrar nada do que já rodava.

## 2. Arquivos novos
| Arquivo | Papel |
| --- | --- |
| `app/AppCode.php` | Modelo: CRUD de servidores XUI do app, settings, rotas grudadas, dedup de host/porta |
| `app/AppCodeRouter.php` | Roteador: cache grudado -> cache negativo -> lock de descoberta -> probe HTTP |
| `public/app-code.php` | Aba do painel: DNS do app, servidores, settings, rotas ao vivo |
| `public/save-app-code.php` | Handler POST (settings, servidor, testar, desgrudar, excluir) com CSRF |
| `docs/CODIGO_DE_APP_MULTI_XUI.md` | Documentação operacional |

## 3. Arquivos alterados
- `app/Database.php` — migração `migrateAppCode`: tabelas `app_servers`,
  `app_user_routes`, `app_negative_cache`, `app_discovery_lock` + índices.
  Idempotente (`CREATE TABLE IF NOT EXISTS`), roda no schema versionado.
- `public/proxy.php` — resolução multi-XUI antes do fetch; auto-cura em 5xx.
- `app/bootstrap-cli.php` — carrega as classes novas para CLI/testes.

## 4. Regras de roteamento implementadas
1. Descoberta roda SÓ em rota textual (`get.php`, `player_api.php`, `.m3u8`).
2. Segmento `.ts` nunca varre: leitura O(1) da rota grudada. Peso zero.
3. Lock por username: N players do mesmo assinante = 1 varredura.
4. Cache negativo (5 min) só para "usuário realmente não existe".
5. XUI inacessível NÃO gera cache negativo (`probeErrors`) — evita bloquear
   assinante por 5 min durante queda de rede.
6. Rota que passa a devolver 5xx é desgrudada e redescoberta automaticamente.
7. Fallback opcional para a origem XUI padrão: a CDN nunca para.
8. Throttle de 60s na gravação da rota — evita write amplification no SQLite.
9. `extra_hosts` por servidor: mascara a CDN interna de cada XUI na reescrita.
10. `saveServer` deduplica host/porta e faz merge dos campos não vazios.

## 5. Testes executados localmente
- `php -l` limpo em todos os arquivos novos/alterados.
- E2E com 2 XUIs distintos e usuários distintos, 12 players concorrentes:
  0% de embaralhamento de usuário/lista.
- Verificação de vazamento: IP, porta e CDN interna do XUI ausentes nas
  respostas reescritas (m3u/JSON/XMLTV).
- Simulação de queda: rota desgruda, redescobre e volta sozinha sem TTL.
- Recadastro do mesmo destino: não duplica servidor, não zera ajustes.
- Base de teste limpa após a validação (`app_servers`, `app_user_routes`,
  `app_negative_cache`, `app_discovery_lock`, aliases e settings de teste).

## 6. Ações de DNS necessárias em produção
```
CNAME assistservpd.phpd77.com -> cdnvoods.vr766.com
  ou
A     assistservpd.phpd77.com -> 45.140.192.237
```
Nunca apontar para IP/DNS de XUI.

## 7. Pontos para o Codex verificar no main
- `app/Database.php`: migração aplica sem erro em base já existente em produção.
- `public/proxy.php`: ordem entre `AppCodeRouter::resolve` e `AccessGuard::check`.
- `public/save-app-code.php`: CSRF + `Auth::requireLogin` em todos os caminhos.
- `app/AppCodeRouter.php`: TTL do lock e limpeza de locks órfãos.
- Permissões de `storage/` para `www-data` após o deploy.
