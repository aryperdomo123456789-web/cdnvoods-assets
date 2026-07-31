# Faltas de Código — Restreamento Inteligente da CDN

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Data: 2026-07-31

## Escopo obrigatório de ambiente

Este documento se refere **exclusivamente** ao projeto rodando nesta VPS:

- VPS: `45.140.192.237`
- OS: `Ubuntu 22.04`
- Path ativo: `/opt/proxy-mago/proxy-mago-base`
- Repositório GitHub: `aryperdomo123456789-web/cdnvoods-assets`

Não interpretar este texto como orientação genérica para outro ambiente.
Tudo aqui foi pensado para ser implementado no GitHub e depois puxado para
esta VPS com o mínimo de ajuste manual.

## Objetivo deste documento

Registrar de forma direta o que **ainda falta no código** para a CDN/proxy
ficar realmente inteligente e saber com alta confiança:

- quem está conectado agora
- quantas conexões cada usuário está usando
- qual domínio público ele usou
- qual IP e player ele usou
- qual tipo de consumo ele abriu
- qual sessão local corresponde a qual sessão do XUI
- o que aconteceu em conteúdos `direct source`

## O que já existe hoje

O projeto já entregou:

- rastreio local por request com `request_id`
- log estruturado do proxy
- proteção contra troca de credenciais (`CredentialGuard`)
- painel de restreamento
- painel de jobs internos
- sync read-only do XUI
- documentação operacional para esta VPS

Isso já coloca o projeto em um nível bom de observabilidade.

## O que ainda falta para chegar no nível “CDN inteligente”

### 1. Ativação real do espelho XUI em produção

Status:

- o código existe
- ainda depende de configuração e validação real aqui

Falta no código/processo:

- validar `pdo_mysql` ativo nesta VPS
- validar formulário de configuração do XUI com testes reais de conexão
- validar sync real de:
  - `users`
  - `streams`
  - `user_activity_now`
- tratar melhor erros operacionais de grant, timeout e DNS do MySQL

Por que isso importa:

- sem isso, o painel local não consegue comparar conexões atuais com o limite
  oficial do usuário no XUI

### 2. Matching de alta confiança entre proxy e sessão ativa

Status:

- a base existe
- ainda precisa ser refinada e validada com tráfego real

Falta no código:

- aumentar confiança do vínculo entre request local e sessão do XUI usando:
  - `username`
  - `client_ip`
  - `user_agent`
  - janela de tempo
  - `stream_id`
  - `container`
- definir score explícito:
  - `high`
  - `medium`
  - `low`
  - `invalid`
- salvar o motivo do match
- destacar no painel quando o match for fraco

Por que isso importa:

- sem um match forte, a CDN vê requests e o XUI vê sessões, mas a ligação entre
  eles ainda pode ficar “boa” em vez de “exata”

### 3. Sessão local própria da CDN

Status:

- hoje o sistema rastreia muito bem requests
- ainda falta consolidar isso em sessões lógicas da própria CDN

Falta no código:

- criar entidade de sessão local da CDN
- agrupar requests correlatos do mesmo usuário em uma sessão:
  - playlist
  - api
  - stream
  - segmentos
- definir início, atividade, idle timeout e encerramento
- criar tabela de sessões ativas locais

Por que isso importa:

- request não é a mesma coisa que conexão
- para a CDN “saber quantos conectados tem”, ela precisa contar sessões locais,
  não apenas linhas de log

### 4. Contador real de conexões ativas da CDN

Status:

- hoje o XUI ajuda a informar sessões ativas
- a CDN ainda precisa de um contador próprio mais inteligente

Falta no código:

- regras locais por tipo:
  - `m3u`
  - `api`
  - `live`
  - `movie`
  - `series`
  - `hls`
  - `segment`
- timeout por tipo de atividade
- fusão de múltiplos requests da mesma sessão
- deduplicação de bursts de HLS
- contador local independente do XUI

Por que isso importa:

- `user_activity_now` do XUI sozinho não resolve `direct source`
- o número de requests HLS pode inflar falsa percepção de conexões

### 5. Rastreamento profundo de `direct source`

Status:

- há rastreio do request público
- falta fechar melhor o trecho interno após redirect

Falta no código:

- registrar cada hop seguido pelo proxy
- registrar host final acessado por dentro
- registrar início e fim do consumo `direct source`
- registrar falha, abandono e retry
- diferenciar no painel:
  - request público
  - origem XUI
  - host final do direct

Por que isso importa:

- o XUI muitas vezes não enxerga bem esse caminho
- a fonte de verdade precisa ser a CDN

### 6. Regras explícitas para estouro de limite

Status:

- o painel já pode mostrar comparação
- falta fechar a regra de produto e o enforcement

Falta no código:

- definir modo de operação:
  - só alertar
  - alertar + marcar risco
  - bloquear acima do limite
- permitir tolerância curta para reconexão
- registrar quando a CDN discorda do XUI
- mostrar no painel a origem do contador:
  - `cdn_local`
  - `xui_activity_now`
  - `merged`

Por que isso importa:

- “quantas conexões tem” não basta
- o sistema precisa saber o que fazer com isso

### 7. Divergências operacionais visíveis

Status:

- logs existem
- falta superfície melhor no painel

Falta no código:

- quadro de divergências:
  - CDN vê 3
  - XUI vê 2
  - causa provável
- alertas para:
  - `unknown_user`
  - `orphan_request`
  - `orphan_activity`
  - `invalid_credentials_swap`
  - `above_limit`
  - `sync_stale`
- filtros rápidos por severidade

Por que isso importa:

- rastreabilidade forte não é só guardar log
- é conseguir ver rápido onde está o problema

### 8. Cobertura real de testes multiusuário

Status:

- existem smoke tests
- ainda faltam cenários mais próximos de produção

Falta no código/teste:

- mesmo usuário em múltiplos apps
- mesmo usuário em múltiplos IPs
- HLS com vários segmentos simultâneos
- VOD com `direct source`
- reconexão rápida
- usuário ocioso que volta
- degradação do MySQL do XUI

Por que isso importa:

- é nesses cenários que a contagem costuma quebrar

### 9. Métricas operacionais da própria CDN

Status:

- há boa base de logs
- falta consolidar isso em indicadores prontos

Falta no código:

- KPIs de conexão:
  - ativos agora
  - pico 5 min
  - pico 1 h
  - média por usuário
- KPIs de qualidade:
  - falhas por tipo
  - redirects direct por tipo
  - swaps bloqueados
  - jobs atrasados
- KPIs de consistência:
  - matches `high/medium/low`
  - divergências CDN vs XUI

Por que isso importa:

- uma CDN inteligente precisa mostrar saúde, não só eventos brutos

## Ordem correta de implementação

### Fase 1 — tornar o XUI espelho confiável

- ativar `pdo_mysql`
- validar config read-only
- validar sync real

### Fase 2 — fechar sessões locais da CDN

- modelar sessão local
- consolidar requests em conexão
- criar contador local ativo

### Fase 3 — reforçar matching e divergências

- melhorar score
- melhorar vínculo request/sessão
- expor divergências no painel

### Fase 4 — fechar `direct source`

- registrar hops
- contar sessões direct
- consolidar lifecycle

### Fase 5 — endurecer testes e métricas

- cenários multiusuário reais
- relatórios e KPIs
- troubleshooting final

## Critério de aceite final

Só considerar esta parte encerrada quando:

- a CDN contar conexões ativas localmente com boa precisão
- o painel cruzar isso com o XUI
- `direct source` estiver rastreado de ponta a ponta
- divergências estiverem visíveis e explicadas
- existir histórico suficiente para auditoria
- smoke tests e testes multiusuário provarem o comportamento

## O que subir para o GitHub

Este documento deve ser enviado para:

1. branch `backup`
2. depois promovido para `main`

Fluxo obrigatório:

- primeiro enviar para `backup`
- validar no GitHub
- depois levar o mesmo conteúdo para `main`

## Resumo executivo

Hoje o projeto já tem observabilidade forte.

O que falta para a CDN ficar realmente “inteligente” é transformar:

- rastreamento por request

em:

- rastreamento por sessão/conexão real

com:

- contador local independente
- correlação forte com XUI
- visão clara de `direct source`
- divergência operacional visível

