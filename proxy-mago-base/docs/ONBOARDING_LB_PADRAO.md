# Onboarding padrão de LB (LB-02, LB-03, LB-0N)

Regra: o `main` é cérebro (painel, regras, sync, auditoria). O LB só entrega
tráfego e manda telemetria. Nenhum LB recebe credencial do XUI em claro.

## 1. Cadastro (painel `/lb.php`)
Campos obrigatórios: `IP público`, `porta SSH`, `usuário root`, `senha root`,
`label`. Ao salvar, a instalação automática dispara em background
(`bin/lb-install-run.php` → `app/LbInstaller.php`) com log ao vivo por etapa.
A senha nunca vai por linha de comando (`SSHPASS`) e é guardada com
`LbCrypto` (AES-256-GCM); todo log passa por `LbSsh::redact()`.

## 2. Aceite da instalação
- pacote entregue por `app/LbPackageBuilder.php` + `bin/lb-install.sh`
- `health` do nó sai de `unknown` para `ok` em `/lb.php`
- telemetria chegando (CPU/RAM/disco/RX/TX/sessões) via `public/lb-ingest.php`
- idade do dado visível no banner de frescor (`app/Freshness.php`); dado velho
  marca `MODO DEGRADADO` em vez de mentir número ao vivo

## 3. Rotas por usuário
Modos: `main_only`, `lb_auto`, `lb_forced`, `drain`. Aplicáveis a um usuário ou
a todos (`public/save-lb-route.php`). A decisão de cada request fica em
`lb_route_history` e na trilha única (`cdn_audit_timeline`).

## 4. Fallback obrigatório
LB `offline`/`drain` sai do score e o usuário volta pro cérebro sem intervenção.
Prova: `bin/smoke-lb.sh` etapas 1–4.

## 5. Aceite funcional antes de mandar cliente real
Rodar contra o host público apontado para o LB:
`get.php`, `player_api.php` (inclui `get_series_info`), `xmltv.php`,
`movie/...`, `series/...`, `live/...`, um direct source e uma sessão com troca
de conteúdo. Checar no painel: usuário, em uso, livres, IP final, app, conteúdo,
saída (main/LB) e uptime estável.

## 6. Baseline de carga
Preencher `docs/BASELINE_CARGA_LB.md` com número medido no nó real (simultâneas
até degradação). Número de laboratório não vale.
