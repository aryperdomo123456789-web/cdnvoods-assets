# Plano de Escala Suprema — Main + LBs

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Data de referencia: `2026-07-31`

Este documento cruza o estado real atual do projeto com a meta de evoluir para
uma CDN/proxy profissional, preparada para:

- proteger `1` XUI com alto nivel de sigilo
- mascarar `direct source`
- suportar varios LBs
- manter rastreabilidade forte
- crescer sem recomeçar do zero quando o `main` for trocado por um mais forte

Ele foi escrito para o ambiente real atual:

- `main`: `2 vCPU`, `6 GB RAM`, rede `1/1`
- `LB-01`: `3 vCPU`, `8 GB RAM`, rede `10/10`
- projeto ativo em `/opt/proxy-mago/proxy-mago-base`
- papel atual do ambiente: `role=main`

## 1. Leitura executiva

Sim, da para seguir nesse rumo.

Mas a forma profissional nao e tentar espremer o desenho atual ate o limite.
O caminho certo e:

- manter o `main` atual como cerebro leve
- usar os LBs como musculo de entrega
- reduzir o que roda no caminho quente do stream
- preparar o projeto para uma futura troca de `main`
- separar desde ja o que e:
  - controle
  - runtime ao vivo
  - auditoria
  - entrega de stream

Conclusao honesta:

- com o estado atual, o sistema ainda esta em fase de consolidacao
- com ajustes fortes de arquitetura, ele pode virar uma base boa para varios LBs
- para chegar em patamar alto de escala, o gargalo principal nao sera so CPU ou
  banda: sera o desenho do runtime e da persistencia

## 2. Estado real atual cruzado

Hoje o projeto ja tem estes blocos importantes:

- proxy publico funcional em `public/proxy.php`
- reescrita de playlist e mascaramento de origem
- rastreamento de sessoes da CDN
- trilha de `direct source`
- roteamento para LB por usuario
- painel operacional ao vivo
- sync com `1` XUI
- trava CDN por IP por usuario

Tambem ha sinais reais de limite estrutural:

- `SQLite` local com cerca de `5.24 GB`
- historico de `database is locked`
- polling forte do painel em `restream-data.php`
- muitos jobs concorrendo com o runtime
- `PHP-FPM` ainda no caminho quente de entrega

Configuracao real observada:

- `journal_mode = WAL`
- `busy_timeout = 30000`
- `follow_external_redirects = 1`
- `direct_source_trace = 1`
- `log_segments = 0`
- `xui_sync_seconds = 5`
- `cdn_sessions_enabled = 1`

Leitura tecnica direta:

- a base funcional existe
- o modelo conceitual de cerebro + musculos ja existe
- o maior risco atual e concorrencia entre stream, telemetria e painel

## 3. O que este hardware atual permite de verdade

### 3.1. Main atual

O `main` atual pode funcionar bem como:

- painel admin
- cerebro de roteamento
- cadastro de LBs
- distribuicao de regras
- consolidacao leve de auditoria
- sync do XUI

O `main` atual nao deve ser tratado como maquina para:

- carregar stream pesado de varios usuarios ao mesmo tempo
- sustentar telemetria extremamente agressiva
- servir como verdade viva de alta escrita via `SQLite` por muito tempo

### 3.2. LB atual

O `LB-01` atual pode ser uma boa maquina de entrega para:

- HLS
- `movie`
- `series`
- `live`
- redirects de `direct source`

Faixas realistas por LB deste porte, com arquitetura melhorada:

- `200 a 500` simultaneas: zona confortavel
- `500 a 1.500`: possivel com otimizacao forte
- acima disso: zona de risco e benchmark obrigatorio

### 3.3. Quantos LBs este main pode coordenar

Se o `main` virar so cerebro de verdade:

- `3 a 5` LBs: faixa segura
- `6 a 8` LBs: possivel com backend bem mais limpo
- acima de `8`: melhor planejar troca do cerebro

## 4. Capacidade alvo realista com 6 a 8 LBs

Sem vender ilusao:

- `6 LBs`: algo entre `1.200` e `4.000` simultaneas com mais seguranca
- `8 LBs`: algo entre `1.600` e `5.500` simultaneas

Com arquitetura mais madura:

- `6 LBs`: `3.000` a `6.000`
- `8 LBs`: `4.000` a `8.000`

Para chegar em `15.000+`, este desenho ainda precisara de:

- `main` mais forte
- mais LBs
- motor de runtime mais eficiente
- backend de estado melhor que `SQLite`

## 5. Arquitetura-alvo recomendada

## 5.1. Papel do main

O `main` deve ficar responsavel por:

- painel
- autenticacao admin
- cadastro do XUI
- gestao dos usuarios espelhados
- regra de roteamento por usuario
- cadastro e saude dos LBs
- distribuicao de configuracao
- auditoria consolidada
- API central interna

O `main` nao deve permanecer no caminho pesado de:

- entrega de `movie`
- entrega de `series`
- entrega de `live`
- streaming direto de grande volume

## 5.2. Papel do LB

Cada LB deve ficar responsavel por:

- receber o dominio publico do usuario
- entregar playlist e stream
- seguir redirect externo de forma segura
- aplicar trava de IP, limite e regra de usuario antes de tocar no XUI
- mandar telemetria compacta de volta para o cerebro

## 5.3. Fluxo operacional correto

```text
Cliente/App
  -> DNS publico do usuario
  -> LB escolhido
  -> validacao local de regra
  -> proxy/stream
  -> telemetria compacta para o cerebro
  -> auditoria consolidada no main
```

## 6. Gargalos que precisam sair do caminho

Para chegar no ponto supremo que voce quer, estes gargalos precisam ser
resolvidos:

- `SQLite` como runtime vivo de alta concorrencia
- polling frequente e pesado do painel
- jobs competindo com o trafego
- `PHP-FPM` segurando stream longo em excesso
- telemetria sincrona demais no caminho do player
- dependencia do `main` para coisas que deveriam acontecer no LB

## 7. Caminho tecnico recomendado

## 7.1. Curto prazo: endurecer sem reescrever tudo

Objetivo:

- estabilizar o que ja existe
- preparar a entrada de mais LBs
- nao quebrar o sistema atual

Acoes:

- reduzir polling do painel
- reduzir frequencia e peso do `xui_sync_streams`
- transformar mais logs pesados em best-effort
- consolidar o `main` como cerebro e o LB como saida real
- medir CPU, RAM, latencia e tempos de resposta por LB
- padronizar pacote de instalacao dos proximos LBs

## 7.2. Medio prazo: separar runtime vivo da auditoria

Objetivo:

- tirar pressao do `SQLite`
- melhorar telemetria em tempo real

Recomendacao:

- `Redis` para:
  - sessoes ativas
  - heartbeat
  - contadores por usuario
  - limite de conexao
  - rate limit
  - presence por LB
- `PostgreSQL` para:
  - configuracao
  - usuarios
  - regras
  - auditoria consolidada
  - historico e relatórios

Resultado:

- o estado vivo para de brigar com auditoria
- o painel fica mais rapido
- o runtime fica mais previsivel

## 7.3. Longo prazo: motor quente em Go

Objetivo:

- aumentar capacidade por LB
- reduzir custo por conexao
- melhorar robustez sob stream longo

O motor em `Go` deve assumir:

- proxy de playlist
- proxy de binario
- HLS
- follow de `direct source`
- validacao de trava CDN por IP
- enforcement de limite de conexoes
- sessao viva e uptime
- emissao de eventos para o cerebro

PHP pode continuar temporariamente no painel/admin, mas o caminho quente do
player nao deve depender dele no estado final.

## 8. Desenho de migracao sem jogar fora o projeto

O projeto atual nao deve ser descartado. Ele deve ser usado como base de
transicao.

### Fase 1

- manter painel atual
- estabilizar LBs
- endurecer runtime atual
- preparar API central de configuracao

### Fase 2

- introduzir `Redis`
- tirar contadores vivos do `SQLite`
- reduzir lock e latencia do painel

### Fase 3

- introduzir `PostgreSQL`
- migrar configuracoes e auditoria principal
- deixar `SQLite` apenas como legado temporario

### Fase 4

- criar engine em `Go` para LB
- depois criar engine em `Go` para o caminho quente do `main`

### Fase 5

- promover `main` novo e mais forte
- deixar o projeto pronto para operar em maior escala

## 9. Como preparar o sistema de hoje para um future main melhor

Desde agora, o codigo deve ser organizado para que a troca do `main` seja
simples.

Principios:

- separar configuracao de maquina e configuracao logica
- separar painel de engine
- separar estado vivo de historico
- tratar LBs como agentes conectados ao cerebro
- expor API interna estavel para:
  - regras
  - usuarios
  - health
  - telemetria
  - bloqueios

Assim, quando entrar um `main` melhor, a migracao sera:

- mover banco central
- mover painel/API
- reapontar LBs
- manter logica de operacao quase intacta

## 10. Regras de ouro para protecao do XUI

Para cumprir o objetivo de proteger de verdade o XUI e o `direct source`, o
sistema final precisa garantir:

- o cliente nunca ve o IP do XUI
- o cliente nunca ve host real do `direct source`
- o cliente nunca contorna a CDN usando `Net Capture` simples
- a trava por IP/CIDR/faixa e aplicada antes do XUI
- o limite de conexoes e aplicado pela CDN, nao so pelo XUI
- o LB entrega so o que o cerebro autorizou
- o `main` conhece:
  - usuario
  - IP final
  - app
  - conteudo
  - LB de saida
  - tempo de sessao

## 11. Plano profissional objetivo

Se a meta e chegar muito alto sem perder controle, a ordem mais correta e:

1. estabilizar o estado atual com `main` como cerebro e `LB-01` como saida real
2. preparar cadastro padrao para `LB-02`, `LB-03` e proximos
3. reduzir o peso do painel e dos jobs sobre o runtime
4. tirar sessoes e contadores vivos do `SQLite`
5. introduzir `Redis`
6. mover persistencia estruturada para `PostgreSQL`
7. reescrever o caminho quente em `Go`
8. trocar o `main` por um mais forte quando os LBs pedirem isso

## 12. Conclusao honesta

O rumo e viavel e faz sentido.

Com o hardware atual, da para construir uma base boa, profissional e preparada
para crescer.

Mas o ponto supremo que voce quer nao vira so com ajuste fino em `PHP + SQLite`.
Ele exige evolucao de arquitetura.

O jeito certo de fazer isso e:

- usar o que ja existe como base
- endurecer o modelo `cerebro + musculos`
- preparar mais LBs agora
- e deixar o projeto pronto para um futuro `main` maior e um motor de runtime
  mais forte

Esse e o caminho mais profissional, mais seguro e com menor risco de refazer
tudo depois.
