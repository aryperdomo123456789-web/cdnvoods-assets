# Producao — LB Automatico com Senha Uma Vez e Chave Depois

Data: 2026-07-31

Ambiente real deste projeto:

- VPS principal: `45.140.192.237`
- OS principal: `Ubuntu 22.04`
- path principal: `/opt/proxy-mago/proxy-mago-base`

LB real alvo atual:

- LB: `143.14.168.78`
- papel: musculo do sistema
- objetivo: absorver trafego pesado e deixar o cerebro leve

Repositorio de referencia:

- GitHub: `aryperdomo123456789-web/cdnvoods-assets`

## 1. Objetivo exato

Fechar o upgrade do modulo de LB para o modo profissional:

- ao salvar o LB no painel, a instalacao ja pode ser disparada automaticamente
- a senha root do LB e usada apenas no primeiro acesso
- o sistema gera um par de chaves SSH exclusivo do projeto
- a chave publica e instalada automaticamente no LB
- depois disso todas as operacoes passam a usar chave, nao senha
- o sistema detecta CPU, RAM, disco e rede do LB
- o sistema escolhe o melhor tuning automaticamente
- o cerebro continua leve
- o LB fica parrudo para pancada de stream

## 2. Resumo executivo

O estado atual do modulo de LB ja resolve:

- cadastro do LB
- armazenamento criptografado da senha root
- teste de conexao
- instalacao remota por etapas
- pacote minimo do proxy
- telemetria do LB
- score e roteamento `main_only`, `forced` e `auto`

Mas ainda existe um gap importante:

- o primeiro bootstrap ainda depende de `sshpass`
- o sistema ainda nao vira automaticamente para `auth_mode=key`
- o fluxo de `salvar -> instalar sozinho -> migrar para chave` ainda nao esta fechado

Este documento define exatamente como fazer isso em producao real, sem quebrar o
que ja funciona hoje.

## 3. Principio de arquitetura

O caminho profissional deve ser:

1. admin cadastra o LB no painel
2. sistema salva o inventario
3. sistema faz o primeiro acesso usando senha root uma unica vez
4. sistema detecta hardware e SO do LB
5. sistema gera par de chaves SSH exclusivo do projeto
6. sistema instala a chave publica no LB
7. sistema valida login por chave
8. sistema apaga a dependencia operacional da senha para as proximas rotinas
9. sistema instala o pacote minimo do proxy e tuning do LB
10. cerebro passa a operar o musculo so por chave

Regra de ouro:

- senha root e onboarding
- chave SSH e operacao continua

## 4. Fluxo alvo no painel

### 4.1. Cadastro

Na aba de LB, ao salvar:

- `label`
- `public_ip`
- `ssh_host`
- `ssh_port`
- `ssh_user`
- `ssh_password`
- `declared_bandwidth_mbps`
- limites soft/hard

o sistema deve:

- salvar no banco
- validar se o IP nao esta duplicado
- cifrar a senha
- gerar um `agent_token`
- opcionalmente gerar um `install_run_id`

### 4.2. Pos-salvamento automatico

Ao finalizar o save com sucesso:

- se o admin marcou `instalar automaticamente`
  - o sistema ja dispara o job de instalacao em background
- se o admin nao marcou
  - o sistema so salva e deixa o botao `Instalar` disponivel

Para este projeto, o recomendado de producao e:

- `Salvar e instalar automaticamente` por padrao

## 5. Etapas do bootstrap ideal

### 5.1. Etapa 1 — validate

Validar localmente:

- formato do IP
- porta SSH
- usuario SSH
- campos obrigatorios

Se falhar aqui:

- nada remoto deve rodar

### 5.2. Etapa 2 — handshake por senha

Primeiro acesso remoto:

- usar senha root so nesta fase
- nunca logar senha em claro
- nunca colocar senha na linha de comando
- sempre mascarar qualquer erro retornado

Dependencias no cerebro:

- `ssh`
- `sshpass`

### 5.3. Etapa 3 — detect

Detectar no LB:

- distribuicao e versao
- CPU cores
- RAM total
- disco total e livre
- interface principal
- php disponivel
- nginx disponivel
- largura de banda declarada ou medivel

Persistir no SQLite central:

- `os_name`
- `os_version`
- `cpu_cores`
- `ram_mb`
- `disk_total_gb`
- `disk_free_gb`
- `profile`

### 5.4. Etapa 4 — suporte de SO

Aceitar:

- Ubuntu 22
- Ubuntu 23
- Ubuntu 24
- Ubuntu 25

Bloquear:

- qualquer outro SO nao homologado

### 5.5. Etapa 5 — gerar chave SSH exclusiva do projeto

O cerebro deve gerar um par de chaves dedicado ao modulo de LB.

Diretorio recomendado:

- `/opt/proxy-mago/proxy-mago-base/storage/ssh/`

Arquivos recomendados:

- `lb_ed25519`
- `lb_ed25519.pub`

Requisitos:

- usar `ed25519`
- permissao restrita
- uma chave do projeto, nao do root global da VPS
- permitir reutilizacao para varios LBs

### 5.6. Etapa 6 — instalar chave publica no LB

Ainda na primeira conexao por senha:

- criar `~/.ssh` do usuario remoto se necessario
- garantir permissoes corretas
- adicionar a chave publica no `authorized_keys`
- evitar duplicidade

Depois:

- testar login por chave imediatamente

### 5.7. Etapa 7 — virar para modo key

Assim que o login por chave passar:

- marcar o LB como `ssh_auth_mode=key`
- manter a senha cifrada apenas como contingencia opcional
- preferir sempre chave em `LbSsh`

Modo recomendado:

- chave primeiro
- senha apenas fallback manual, nunca padrao

### 5.8. Etapa 8 — bootstrap do musculo

Instalar remotamente:

- `nginx`
- `php-fpm`
- `php-cli`
- `php-curl`
- `php-sqlite3`
- `php-mbstring`
- `php-xml`
- `curl`
- `tar`
- `gzip`
- `ca-certificates`

### 5.9. Etapa 9 — pacote minimo

Enviar ao LB apenas:

- `public/proxy.php`
- bootstrap minimo
- classes do proxy
- config minimo
- `health.php`

Nao enviar:

- painel admin
- jobs do cerebro
- dashboards pesados
- sync do XUI do cerebro

### 5.10. Etapa 10 — tuning automatico

O tuning deve partir do hardware detectado.

#### Perfil `small`

Quando:

- ate `2` vCPU
- ate `4 GB` RAM

#### Perfil `medium`

Quando:

- ate `4` vCPU
- ate `8 GB` RAM

#### Perfil `large`

Quando:

- acima disso

### 5.11. Tuning desejado

O sistema deve ajustar automaticamente:

- `worker_processes`
- `worker_connections`
- `pm.max_children`
- `pm.start_servers`
- `pm.min_spare_servers`
- `pm.max_spare_servers`
- `pm.max_requests`
- `request_terminate_timeout`
- buffers do nginx
- `fastcgi_buffering off`
- `access_log off`
- timeouts de stream longos

Objetivo:

- minimo consumo no cerebro
- minimo consumo extra no LB
- maxima capacidade de entrega

## 6. Melhor caminho para rede

O sistema deve considerar:

- banda declarada do LB
- banda medida por telemetria
- CPU
- RAM livre
- erros recentes
- heartbeat recente
- drain mode

O score ja existe no projeto, mas precisa continuar sendo a base da decisao.

## 7. Mudancas de codigo recomendadas

### 7.1. `app/LbNode.php`

Adicionar ou consolidar campos:

- `ssh_auth_mode`
- `ssh_key_name`
- `ssh_password_last_used_at`
- `ssh_key_installed_at`
- `auto_install`

### 7.2. `app/LbSsh.php`

Precisa evoluir para:

- preferir chave se existir
- cair para senha so se necessario
- ter metodo `ensureProjectKeypair()`
- ter metodo `installPublicKey()`
- ter metodo `testKeyLogin()`

Fluxo interno:

- se `ssh_auth_mode=key`, usar chave
- se `ssh_auth_mode=password`, usar senha
- se instalou chave com sucesso, promover para `key`

### 7.3. `app/LbInstaller.php`

Adicionar etapas novas:

- `keygen`
- `install_key`
- `key_smoke`

Fluxo ideal:

- `validate`
- `handshake_password`
- `detect`
- `support`
- `keygen`
- `install_key`
- `key_smoke`
- `bootstrap`
- `package`
- `configure`
- `smoke`

### 7.4. `public/save-lb.php`

Hoje ele salva e volta com mensagem.

Precisa evoluir para:

- salvar
- se `auto_install=1`, disparar instalacao em background
- retornar mensagem clara:
  - `LB salvo e instalacao automatica iniciada`

### 7.5. `public/lb.php`

Adicionar no formulario:

- checkbox `instalar automaticamente ao salvar`
- mostrar modo atual de auth:
  - `password`
  - `key`
- mostrar se chave ja foi instalada

### 7.6. `bin/lb-install-run.php`

Continuar rodando em background, mas com suporte ao novo fluxo:

- primeira fase por senha
- promocao para chave
- fases seguintes por chave

## 8. Sequencia operacional recomendada

### 8.1. Primeira vez

1. cadastrar LB
2. salvar
3. instalacao automatica dispara
4. detecta hardware
5. gera chave
6. instala chave
7. valida chave
8. instala pacote minimo
9. aplica tuning
10. smoke test
11. LB vira `installed`

### 8.2. Proximas operacoes

Depois do primeiro bootstrap:

- `sync`
- `probe`
- `rebalance`
- `install update`

devem usar apenas chave.

## 9. Smoke tests obrigatorios

### 9.1. Bootstrap

Validar:

- SSH por senha funcionou
- chave foi instalada
- SSH por chave funcionou

### 9.2. Infra

Validar:

- `nginx` ativo
- `php-fpm` ativo
- `__lb_health` responde `200`

### 9.3. Proxy

Validar:

- `player_api.php`
- `get.php`
- `movie`
- `live`

### 9.4. Seguranca

Validar:

- senha nao vazou em log
- chave privada nao foi para o Git
- `authorized_keys` existe no LB

## 10. Rollback

Se a migracao para chave falhar:

- o sistema deve manter o modo `password`
- marcar o step como erro
- nao fingir que o LB esta pronto

Se o bootstrap por chave passar mas o resto falhar:

- deixar `ssh_auth_mode=key`
- `install_status=error`
- permitir novo `sync` ou novo `install`

## 11. Riscos e mitigacoes

### 11.1. Risco

`sshpass` ausente no cerebro

Mitigacao:

- instalar `sshpass`
- ou exigir chave preinstalada manualmente

### 11.2. Risco

chave publica nao entra corretamente no LB

Mitigacao:

- validar login por chave imediatamente
- so promover para `key` se o smoke passar

### 11.3. Risco

chave privada com permissao errada

Mitigacao:

- forcar `0600`
- forcar diretorio `0700`

## 12. Recomendacao final

O caminho certo para producao real desta CDN e:

- manter a senha apenas no bootstrap inicial
- gerar chave SSH exclusiva do projeto
- instalar a chave automaticamente no LB
- trocar o modo de operacao para chave
- manter auto-detect de CPU, RAM, disco e rede
- manter tuning automatico por perfil

Esse e o modo profissional, inteligente e mais seguro.

Ele reduz:

- dependencia de senha
- risco operacional
- atrito no dia a dia
- chance de erro manual

E preserva:

- leveza do cerebro
- forca do musculo
- rastreabilidade central
- compatibilidade com o fluxo atual do projeto

## 13. Estado esperado apos implementacao

Quando esse upgrade estiver pronto, o comportamento ideal sera:

- admin preenche dados do LB
- clica em `Salvar`
- instalacao comeca sozinha
- sistema detecta hardware
- sistema gera e instala chave
- sistema muda para `SSH por chave`
- sistema instala pacote e tuning
- sistema entrega status no log ao vivo
- LB fica pronto para receber usuarios

Isso e exatamente o alvo correto para a producao real deste projeto na VPS
`45.140.192.237`.
