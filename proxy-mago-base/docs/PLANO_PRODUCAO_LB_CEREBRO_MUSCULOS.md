# Plano de Producao — LB Cerebro + Musculos

Data: 2026-07-31

Ambiente principal real deste projeto:

- VPS principal: `45.140.192.237`
- OS principal: `Ubuntu 22.04`
- path principal: `/opt/proxy-mago/proxy-mago-base`

LB alvo real informado para este plano:

- VPS LB: `143.14.168.78`
- acesso: `root`
- OS verificado: `Ubuntu 22.04.5 LTS`
- CPU verificada: `3` vCPU
- RAM verificada: `7.7 GiB`
- disco verificado: `30G`, com `23G` livres
- RTT principal -> LB: ~`2.16 ms`
- RTT LB -> principal: ~`2.11 ms`

Este documento nao descreve teoria generica. Ele descreve como transformar a
VPS `143.14.168.78` em **LB de trafego** da CDN, mantendo a VPS
`45.140.192.237` como **cerebro** do sistema.

## 1. Objetivo exato

Chegar num desenho onde:

- a VPS principal continua sendo:
  - painel
  - banco SQLite
  - rastreabilidade
  - jobs
  - inteligencia
  - decisao de qual usuario vai para qual LB
- as VPS LB viram os **musculos**:
  - recebem o trafego pesado do stream
  - mascaram a origem XUI
  - entregam o conteudo
  - fazem o minimo possivel de CPU e RAM
- o painel tenha uma aba `LB`
- nessa aba eu cadastro:
  - IP
  - usuario SSH
  - senha root
- eu clico em `Instalar LB`
- o sistema:
  - detecta Ubuntu `22`, `23`, `24` ou `25`
  - detecta CPU, RAM, disco e rede basica
  - instala tudo sozinho
  - mostra log ao vivo
  - registra status de cada etapa
- depois de instalado:
  - eu escolho quais usuarios do XUI trafegam por qual LB
  - eu vejo saude, carga, banda e disponibilidade do LB
  - eu continuo com rastreabilidade central no cerebro

## 2. Conclusao executiva

Sim, isso e viavel.

Mas o jeito profissional **nao** e transformar o LB em uma copia completa do
painel. O certo e dividir responsabilidades:

- **Cerebro**
  - painel
  - autenticacao do admin
  - SQLite principal
  - jobs
  - decisao e roteamento por usuario
  - inventario dos LBs
  - regra de alocacao
  - observabilidade consolidada
- **Musculos**
  - nginx
  - php-fpm
  - codigo minimo do proxy publico
  - sessao local opcional
  - envio de eventos de volta ao cerebro

Ou seja:

- o LB nao precisa carregar toda a parte pesada de painel
- o LB precisa carregar so o que entrega stream com seguranca

Essa divisao reduz:

- CPU
- RAM
- disco
- chance de regressao
- complexidade de operacao

## 3. Testes reais usados como base deste plano

### 3.1. Conectividade principal -> LB

Executado em `2026-07-31`:

- `ping 143.14.168.78`
- resultado:
  - `0%` packet loss
  - media ~`2.16 ms`

### 3.2. Acesso remoto ao LB

Executado em `2026-07-31` com SSH real:

- acesso `root` funcional
- host remoto respondeu:
  - `Ubuntu 22.04.5 LTS`
  - `3` nucleos
  - `7.7 GiB` de RAM
  - `30G` de disco

### 3.3. Conectividade LB -> principal

Executado de dentro do LB:

- `ping 45.140.192.237`
- resultado:
  - `0%` packet loss
  - media ~`2.11 ms`

### 3.4. Limites do que NAO foi medido ainda

Ainda nao foi comprovado com benchmark real:

- throughput real de `10 Gbps`
- PPS real
- teto real de conexoes simultaneas com stream
- estabilidade do kernel sob carga de CDN

Entao o sistema deve:

- tratar `10/10 de rede` como capacidade declarada
- medir throughput real depois da instalacao
- recalibrar peso do LB com base na medicao real

## 4. Arquitetura alvo

## 4.1. Fluxo

```text
Cliente/App
  -> dominio publico do usuario
  -> DNS aponta para um LB especifico
  -> LB entrega o stream mascarado
  -> LB consulta/usa configuracao recebida do cerebro
  -> eventos de sessao e trafego voltam ao cerebro
  -> cerebro consolida tudo no painel
```

## 4.2. Principio central

O **dominio publico do usuario** deve apontar para o LB escolhido.

Nao e o cerebro que faz proxy duplo desnecessario para o LB.

O correto e:

- o cerebro decide
- o LB entrega

Assim:

- o cerebro nao vira gargalo de banda
- a banda pesada fica no LB
- a latencia cai
- a abertura fica mais instantanea

## 4.3. Como balancear por usuario

Cada usuario do XUI precisa ganhar uma politica de saida:

- `main_only`
- `lb_auto`
- `lb_forced:<lb_id>`
- `lb_pool:<pool_id>`

O painel decide isso por:

- username
- fingerprint
- dominio publico
- grupo
- regra manual

## 5. O que precisa existir no banco local

## 5.1. Nova tabela `lb_nodes`

Campos propostos:

- `id`
- `label`
- `public_ip`
- `ssh_host`
- `ssh_port`
- `ssh_user`
- `ssh_auth_mode` (`password`, `key`)
- `ssh_password_enc`
- `ssh_private_key_enc`
- `os_name`
- `os_version`
- `cpu_cores`
- `ram_mb`
- `disk_total_gb`
- `disk_free_gb`
- `declared_bandwidth_mbps`
- `measured_bandwidth_mbps`
- `health_status`
- `install_status`
- `install_step`
- `install_log_path`
- `agent_version`
- `proxy_version`
- `last_seen_at`
- `last_probe_at`
- `enabled`
- `drain_mode`
- `weight`
- `max_users_soft`
- `max_users_hard`
- `max_mbps_soft`
- `max_mbps_hard`
- `created_at`
- `updated_at`

## 5.2. Nova tabela `lb_installs`

Para log detalhado da instalacao:

- `id`
- `lb_id`
- `run_id`
- `step`
- `status`
- `message`
- `started_at`
- `finished_at`
- `details_json`

## 5.3. Nova tabela `lb_user_routes`

Para dizer qual usuario usa qual LB:

- `id`
- `username`
- `lb_id`
- `mode` (`forced`, `auto`, `pool`)
- `reason`
- `created_at`
- `updated_at`

## 5.4. Nova tabela `lb_metrics`

Coleta periodica de recursos dos LBs:

- `id`
- `lb_id`
- `ts_epoch`
- `cpu_pct`
- `ram_used_mb`
- `ram_free_mb`
- `disk_used_gb`
- `rx_mbps`
- `tx_mbps`
- `sessions_active`
- `users_active`
- `errors_5m`

## 5.5. Nova tabela `lb_sync_events`

Trilha de comandos enviados do cerebro para o LB:

- `id`
- `lb_id`
- `event_type`
- `status`
- `payload_json`
- `created_at`

## 6. Como a aba `LB` deve funcionar

## 6.1. Tela principal

Nova entrada de menu:

- `LB`

Blocos da tela:

### Bloco 1 — Inventario de LBs

Tabela com:

- nome
- IP
- OS
- CPU
- RAM
- rede declarada
- banda medida
- status
- usuarios ativos
- sessoes ativas
- carga atual
- acao

### Bloco 2 — Cadastro rapido

Campos:

- `Nome do LB`
- `IP publico`
- `Porta SSH`
- `Usuario SSH`
- `Senha root`
- `Rede declarada`
- `Peso inicial`
- `Modo`
  - `habilitado`
  - `drenando`
  - `desabilitado`

Botao:

- `Instalar LB`

### Bloco 3 — Log ao vivo da instalacao

Console com:

- etapa atual
- stdout/stderr mascarado
- tempo
- status final

### Bloco 4 — Balanceamento por usuario

Filtros:

- usuario
- LB atual
- online/offline
- acima do limite

Acoes:

- mover usuario para LB X
- voltar usuario para `main_only`
- colocar em `auto`
- aplicar em lote

### Bloco 5 — Saude do LB

Cards:

- CPU
- RAM
- banda RX/TX
- usuarios ativos
- sessoes ativas
- picos de 5m / 1h
- ultimo heartbeat

## 6.2. Politica de seguranca da aba

Nao guardar senha root em claro.

O certo e:

- criptografar `ssh_password` com `app_secret`
- descriptografar so no momento do install
- registrar no log apenas:
  - host
  - usuario
  - passo
  - resultado
- nunca registrar senha

## 7. Fluxo completo do botao `Instalar LB`

## 7.1. Etapa 1 — Validacao local

O cerebro valida:

- IP valido
- SSH port valida
- usuario nao vazio
- senha nao vazia
- duplicidade de IP

## 7.2. Etapa 2 — Handshake SSH

O cerebro abre SSH no LB e coleta:

- `hostnamectl`
- `nproc`
- `free -m`
- `df -h /`
- `ip -brief addr`
- `systemctl --version`
- `php -v` se existir
- `nginx -v` se existir

## 7.3. Etapa 3 — Validacao de SO suportado

Aceitar:

- Ubuntu `22.x`
- Ubuntu `23.x`
- Ubuntu `24.x`
- Ubuntu `25.x`

Se nao for Ubuntu suportado:

- aborta
- grava log claro de incompatibilidade

## 7.4. Etapa 4 — Bootstrap remoto

O cerebro envia um instalador unico, por exemplo:

- `/root/proxy-mago-lb-install.sh`

Esse script precisa:

- atualizar apt
- instalar `nginx`
- instalar `php-fpm`
- instalar extensoes necessarias
- instalar `curl`, `git`, `jq`, `unzip`
- criar diretorio:
  - `/opt/proxy-mago-lb`
- baixar ou rsync do cerebro o pacote minimo do LB

## 7.5. Etapa 5 — Instalar somente o pacote minimo do LB

O pacote do LB **nao** deve ser o projeto inteiro.

Deve conter apenas:

- `public/proxy.php`
- `public/health.php` especifico do LB
- `app/proxy-bootstrap.php`
- classes usadas pelo caminho publico:
  - `AccessGuard`
  - `PlaylistRewriter`
  - `StreamProxy`
  - `RequestContext`
  - `RequestLog` em modo remoto/minimo
  - `CdnSession` em modo remoto/minimo
  - `DirectSource`
  - `CredentialGuard`
  - `Config`
- `config/app.php`
- um `storage/` proprio e leve

Nao levar para o LB:

- painel admin completo
- jobs do cerebro
- sync do XUI inteiro
- dashboards pesados

## 7.6. Etapa 6 — Registrar o LB no cerebro

Depois da instalacao:

- cerebro salva `lb_nodes`
- gera token/credencial de agente
- LB passa a responder heartbeat

## 7.7. Etapa 7 — Aplicar configuracao remota

O cerebro publica no LB:

- dominios publicos que aquele LB atende
- origem XUI mascarada
- regras de rate limit
- politicas de trace
- endpoint do cerebro para envio de eventos

## 7.8. Etapa 8 — Smoke test automatico

O cerebro testa:

- `ssh ok`
- `nginx ok`
- `php-fpm ok`
- `health ok`
- `proxy ok`
- `get.php` ok
- retorno do log

## 8. Como distribuir usuarios por LB

## 8.1. Modos de distribuicao

### Manual por usuario

Exemplo:

- `P2on2325154215633` -> `LB-01`
- `Magoopdokjm32000` -> `main_only`

### Auto por peso

O cerebro escolhe com base em score:

- `cpu headroom`
- `ram headroom`
- `banda medida livre`
- `usuarios ativos`
- `sessoes ativas`
- `erros recentes`
- `RTT`

### Pool por grupo

Exemplo:

- pool `BR-10G`
- varios usuarios apontados para o pool
- o cerebro distribui entre os LBs do pool

## 8.2. Formula de score recomendada

```text
score = (peso_manual * 100)
      + (headroom_banda * 5)
      + (headroom_ram * 2)
      + (headroom_cpu * 2)
      - (usuarios_ativos * 0.5)
      - (erros_5m * 10)
      - (drain_penalty)
```

Regras:

- LB em `drain_mode` nunca recebe usuario novo
- LB com heartbeat atrasado sai do roteamento
- LB acima do soft limit perde score
- LB acima do hard limit sai do roteamento

## 9. Como fazer o usuario realmente trafegar por um LB especifico

O jeito correto e DNS por dominio publico.

Opcao A:

- cada dominio publico aponta para um LB especifico

Opcao B:

- o painel cria subdominios tecnicos por LB
  - `lb1.seudominio.com`
  - `lb2.seudominio.com`
- o sistema entrega ao cliente o dominio conforme a politica do usuario

Opcao C:

- Nginx principal responde com `302` controlado para o LB de destino

Para o teu objetivo de leveza maxima, a melhor e:

- **Opcao A ou B**

Evitar:

- principal recebendo todo trafego para depois repassar ao LB

Porque isso:

- dobra banda
- dobra latencia
- aumenta custo no cerebro

## 10. Recurso inteligente de instalacao

## 10.1. Deteccao automatica do LB

Na instalacao o sistema deve ler:

- versao do Ubuntu
- numero de vCPU
- RAM total
- disco livre
- interfaces
- MTU
- portas livres

## 10.2. Perfil automatico sugerido

### Perfil Small

- ate `2` vCPU
- ate `4 GB` RAM

### Perfil Medium

- ate `4` vCPU
- ate `8 GB` RAM

### Perfil Large

- acima disso

Para o LB real `143.14.168.78`, hoje o perfil recomendado e:

- `Medium`

## 10.3. Parametros automaticos por perfil

O instalador deve ajustar:

- `worker_processes`
- `worker_connections`
- `pm.max_children` do PHP-FPM
- `pm.start_servers`
- `pm.min_spare_servers`
- `pm.max_spare_servers`
- timeouts
- bufferizacao
- `sendfile`
- `tcp_nodelay`
- `fastcgi_buffering`

Meta:

- abrir rapido
- usar pouca RAM
- nao estourar CPU

## 11. Configuracao recomendada do LB

## 11.1. Nginx

Objetivo:

- streaming eficiente
- sem log cru de URL com credencial
- sem redirecionamentos desnecessarios

Regras:

- `access_log off` no caminho publico
- log sanitizado via app
- `sendfile on` para binario quando seguro
- `tcp_nodelay on`
- `keepalive_timeout` curto
- `client_body_buffer_size` baixo
- `proxy_buffering off` no caminho textual grande

## 11.2. PHP-FPM

Objetivo:

- o PHP entra so onde precisa

Regras:

- `pm = dynamic`
- children suficientes para bursts pequenos
- sem pool gigante
- `memory_limit` controlado
- `request_terminate_timeout` alto no caminho publico

## 11.3. SQLite no LB

Recomendacao:

- o LB nao deve manter o banco principal
- no maximo um SQLite local de cache e fila curta

O certo e:

- estado principal fica no cerebro
- LB manda eventos de volta

## 12. Como manter 100% rastreavel

## 12.1. Cada LB precisa mandar para o cerebro

Para cada request relevante:

- `request_id`
- `lb_id`
- username
- dominio publico
- IP do cliente
- player
- tipo
- stream_id
- bytes
- host final direct
- status

## 12.2. Cada LB precisa mandar heartbeat

A cada `5` ou `10` segundos:

- cpu
- ram
- disco
- rx/tx
- usuarios ativos
- sessoes ativas
- erros recentes

## 12.3. Cada LB precisa mandar mapa de sessoes

Para o cerebro consolidar:

- `session_key`
- username
- session_kind
- direct_source
- host final
- last_seen

## 13. O que precisa ser construido no codigo

## 13.1. Backend

Novos componentes:

- `app/LbNode.php`
- `app/LbInstaller.php`
- `app/LbSsh.php`
- `app/LbTelemetry.php`
- `app/LbRouter.php`
- `app/LbPackageBuilder.php`
- `app/LbAssignment.php`

## 13.2. Rotas/public

Novas telas/endpoints:

- `public/lb.php`
- `public/lb-data.php`
- `public/save-lb.php`
- `public/install-lb.php`
- `public/lb-log.php`
- `public/save-lb-route.php`
- `public/delete-lb.php`

## 13.3. Jobs

Novos jobs:

- `lb_probe`
- `lb_heartbeat_ingest`
- `lb_rebalance`
- `lb_sync_routes`
- `lb_cleanup`

## 13.4. Pacote de agente no LB

Scripts:

- `bin/lb-install.sh`
- `bin/lb-heartbeat.sh`
- `bin/lb-apply-config.sh`
- `bin/lb-smoke.sh`

## 14. Regras de UX da aba `LB`

O admin deve conseguir:

1. cadastrar LB
2. clicar instalar
3. acompanhar log ao vivo
4. ver status final
5. ver CPU/RAM/banda
6. escolher usuarios por LB
7. drenar LB para manutencao
8. reinstalar LB
9. remover LB

Tudo sem shell manual.

## 15. Como garantir abertura instantanea

Para abrir muito rapido:

- DNS do usuario deve apontar direto para o LB escolhido
- nada de proxy duplo principal -> LB na operacao normal
- playlist grande precisa continuar em streaming/rewrite leve
- direct source precisa continuar seguido por dentro
- conexoes de VOD/direct precisam ter janela viva maior

## 16. O que NAO fazer

- nao usar a VPS principal como proxy de banda para todos os LBs
- nao rodar painel completo dentro de cada LB
- nao replicar o SQLite principal inteiro por escrita concorrente
- nao guardar senha root em claro
- nao logar credenciais
- nao depender do XUI para ver direct source

## 17. Fases de implantacao

## Fase A — Fundacao de dados e painel

Entregas:

- schema `lb_nodes`, `lb_installs`, `lb_user_routes`, `lb_metrics`
- tela `LB`
- cadastro e listagem

Aceite:

- admin consegue cadastrar LB e ver inventario

## Fase B — SSH e instalador remoto

Entregas:

- handshake SSH
- deteccao de Ubuntu 22-25
- bootstrap remoto
- log ao vivo

Aceite:

- clicar `Instalar LB` instala nginx/php e registra status

## Fase C — Pacote minimo do LB

Entregas:

- build do pacote minimo
- deploy remoto
- health remoto

Aceite:

- LB responde health e proxy

## Fase D — Telemetria e heartbeat

Entregas:

- coleta de CPU/RAM/banda
- heartbeat
- cards da aba LB

Aceite:

- painel mostra saude em tempo real

## Fase E — Roteamento por usuario

Entregas:

- atribuicao manual por username
- modo auto por score

Aceite:

- admin escolhe quem trafega por qual LB

## Fase F — Rebalanceamento inteligente

Entregas:

- motor de score
- drain mode
- limites soft/hard

Aceite:

- sistema distribui sem sobrecarregar

## 18. Checklist de testes de producao

## 18.1. Testes de instalacao

- [ ] SSH conecta
- [ ] detecta Ubuntu suportado
- [ ] instala nginx
- [ ] instala php-fpm
- [ ] sobe servico
- [ ] grava log

## 18.2. Testes de proxy

- [ ] `get.php` via LB responde `200`
- [ ] `player_api.php` via LB responde `200`
- [ ] `xmltv.php` via LB responde `200`
- [ ] `movie` via LB abre
- [ ] `direct source` via LB nao vaza origem

## 18.3. Testes de observabilidade

- [ ] request aparece no cerebro com `lb_id`
- [ ] sessao aparece no painel
- [ ] usuario aparece no LB certo
- [ ] banda do LB sobe no painel

## 18.4. Testes de balanceamento

- [ ] usuario forced vai para LB certo
- [ ] usuario em auto respeita score
- [ ] LB em drain nao recebe novos
- [ ] LB offline sai do roteamento

## 18.5. Testes de seguranca

- [ ] senha root nao aparece em log
- [ ] credenciais do XUI nao aparecem em log
- [ ] origem nao vaza

## 19. Veredito tecnico

Para o teu objetivo, o desenho correto e:

- `45.140.192.237` = **cerebro**
- `143.14.168.78` = **LB/musculo**

E o projeto deve evoluir para:

- painel centralizado
- LBs leves
- rota por usuario
- telemetria consolidada
- install remoto por clique

Esse e o jeito mais profissional, mais leve e mais escalavel.

## 20. Proximo passo recomendado

Implementar primeiro:

1. schema de `lb_nodes`
2. aba `LB`
3. `LbSsh`
4. `LbInstaller`
5. pacote minimo do LB

Sem isso, nao existe base segura para instalar por clique nem para balancear
usuarios do XUI por LB.
