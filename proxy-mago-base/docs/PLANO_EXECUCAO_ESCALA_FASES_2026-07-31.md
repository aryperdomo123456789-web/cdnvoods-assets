# Plano de Execucao — Escala Suprema em Fases (main cerebro + LBs musculos)

Data: `2026-07-31`
Base conceitual: `docs/PLANO_ESCALA_SUPREMA_MAIN_LB_2026-07-31.md`
Entrada oficial de estado: `docs/00_ORDEM_OFICIAL_E_ESTADO_REAL.md`

Este documento e EXECUCAO, nao visao. Cada item tem arquivo, criterio de aceite
e ordem. Regra de ouro: nada aqui pode derrubar o que ja funciona hoje em
`/opt/proxy-mago/proxy-mago-base`.

## Decisao de arquitetura (fechada)

- `main` = cerebro: painel, admin, cadastro de XUI e LBs, regras por usuario,
  sync do XUI, auditoria consolidada, API interna.
- `LB-0N` = musculo: recebe o DNS publico, valida regra local, entrega
  playlist/stream, segue `direct source` mascarado, manda telemetria compacta.
- Estado vivo (sessao, contador, trava de IP) sai do `SQLite` por etapas.
- Caminho quente sai do PHP so na Fase 4, e por LB, nunca de uma vez.

## Fase 1 — Endurecer e abrir espaco para LB-02 (agora)

Objetivo: contagem correta, painel leve, pacote de LB repetivel.

| # | Tarefa | Arquivos | Aceite |
|---|--------|----------|--------|
| 1.1 | Supersede de VOD (feito) | `app/CdnSession.php` | trocar de filme nao soma conexao; sessao antiga fica `close_reason='superseded'` |
| 1.2 | `bin/smoke-lb.sh` | `bin/` | prova troca de LB, queda de LB e fallback pro cerebro |
| 1.3 | Frescor de dados | `public/lb-data.php`, cards do painel | `_meta.data_age_ms` + aviso visivel de modo degradado |
| 1.4 | Aliviar polling | `public/restream-data.php`, `public/lb-data.php` | intervalo adaptativo + micro-cache; painel sem query pesada por tick |
| 1.5 | Jobs fora do caminho quente | `app/JobRunner.php`, `bin/jobs-run.php` | `xui_sync_streams` em perfil `heavy` com janela propria e disjuntor |
| 1.6 | Pacote padrao de LB | `bin/lb-install.sh`, `app/LbPackageBuilder.php` | instalar `LB-02` com IP + porta + root + senha, sem passo manual |
| 1.7 | Baseline de carga | `bin/smoke-*.sh` | numero real de simultaneas por LB registrado em doc |

Saida da Fase 1: `LB-02` no ar, contagem confiavel, painel sem travar.

## Fase 2 — Estado vivo no Redis

Objetivo: tirar escrita de alta frequencia do `SQLite`.

- 2.1 Camada `app/StateStore.php` com driver `sqlite` (atual) e `redis`.
- 2.2 Migrar para o driver: sessao ativa, heartbeat, contador por usuario,
  limite de conexao, trava por IP, presence por LB.
- 2.3 `SQLite` continua guardando historico e auditoria.
- 2.4 Chave de corte: `state_driver` em settings, troca sem deploy novo.
- Aceite: painel ao vivo sem `database is locked`, contador identico ao da
  Fase 1 rodando os dois drivers em paralelo por 24h.

## Fase 3 — PostgreSQL para persistencia estruturada

- 3.1 Abstracao `app/Database.php` com dialeto (`sqlite` | `pgsql`).
- 3.2 Migrar em blocos: config -> usuarios/regras -> LBs -> auditoria.
- 3.3 Espelho do XUI (483k+ streams) por ultimo, com copia em lote.
- 3.4 `SQLite` vira legado somente-leitura.
- Aceite: painel e jobs funcionando 100% em `pgsql` com rollback documentado.

## Fase 4 — Motor quente em Go no LB

- 4.1 Engine Go no LB assume: playlist, binario, HLS, follow de direct source,
  trava por IP, limite de conexao, sessao/uptime, eventos pro cerebro.
- 4.2 Rollout canario: 1 LB em Go, resto em PHP, comparando telemetria.
- 4.3 PHP fica no painel/admin.
- Aceite: mesmo LB dobra simultaneas com CPU menor e telemetria igual.

## Fase 5 — Promover main mais forte

- Mover banco central, mover painel/API, reapontar LBs, logica intacta.
- Preparacao ja na Fase 1: configuracao de maquina separada da logica.

## Ordem de prioridade (nao inverter)

1. 1.1 e 1.2 (contagem + prova de LB)
2. 1.3 a 1.5 (painel e jobs leves)
3. 1.6 e 1.7 (LB-02 + baseline)
4. Fase 2, depois 3, depois 4, depois 5

Migrar banco antes de fechar a Fase 1 e desperdicio: contagem errada em banco
novo continua contagem errada.
