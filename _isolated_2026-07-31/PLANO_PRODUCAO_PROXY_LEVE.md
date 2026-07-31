# Plano de Produção - Painel Web + Proxy Leve Anti-Vazamento

## Objetivo

Construir um painel web simples para proteger um único servidor XUI de origem, sem usar Laravel, com foco em:

- baixo consumo de CPU e RAM
- implantação fácil em Ubuntu 22
- um único IP de origem
- bloqueio básico de vazamento da fonte direta
- manutenção simples via painel

## Escopo

### O que este sistema faz

- cadastra o IP da origem XUI
- expõe um domínio/painel para configuração
- gera regras de proxy para esconder a origem
- controla acesso por IP/assinatura simples
- registra logs de acesso e erro
- permite atualizar a origem sem editar arquivos manualmente

### O que este sistema não faz

- não pretende ser DRM
- não impede cópia por captura externa de tela
- não substitui proteção de conteúdo do provedor
- não precisa de fila, jobs, ORM ou arquitetura de microserviços

## Decisão Técnica

### Stack recomendada

- Ubuntu 22.04
- Nginx como proxy reverso
- PHP 8.2 FPM apenas para painel e geração de regras
- SQLite para persistência leve
- systemd para iniciar serviços

### Por que não Laravel

- mais pesado para uma solução de um único servidor
- mais dependências do que o necessário
- mais tempo de deploy e manutenção
- pouco ganho real para este caso

## Arquitetura Proposta

### Componentes

1. `public/`
   - painel web
   - login
   - cadastro da origem
   - status do sistema

2. `app/`
   - regras do negócio
   - validações
   - assinatura de tokens
   - geração de configuração

3. `storage/`
   - banco SQLite
   - logs
   - cache simples

4. `nginx/`
   - configuração do proxy reverso
   - bloqueios de rota
   - headers de segurança

## Fluxo de Uso

### Fluxo principal

1. admin acessa o painel
2. cadastra o IP/porta do XUI principal
3. o painel grava a configuração
4. o sistema gera ou atualiza as regras do proxy
5. o Nginx recarrega a configuração
6. os players passam a usar o domínio do proxy

### Fluxo de proteção

1. o player solicita a playlist ou stream
2. o painel ou middleware valida a assinatura/IP
3. o Nginx encaminha para a origem
4. a resposta volta sem expor o IP real ao cliente

## Estratégia Anti-Vazamento

### Camada 1 - Ocultação da origem

- o cliente nunca recebe o IP real da origem
- toda URL pública aponta para o proxy
- headers sensíveis não devem ser repassados

### Camada 2 - Assinatura simples

- token curto ligado ao IP do cliente
- expiração curta e renovação automática
- bloqueio se o token for reutilizado fora do IP esperado

### Camada 3 - Limitação de abuso

- limite de conexões por IP
- limite de requisições por minuto
- log de eventos suspeitos

### Camada 4 - Proteção operacional

- painel atrás de autenticação
- secret key fora do código
- logs rotacionados
- backup do SQLite

## Estrutura de Pastas

```text
/opt/proxy-mago
  ├── app
  │   ├── Config.php
  │   ├── Security.php
  │   ├── Storage.php
  │   └── ProxyRules.php
  ├── public
  │   ├── index.php
  │   ├── login.php
  │   ├── dashboard.php
  │   └── assets
  ├── storage
  │   ├── db.sqlite
  │   ├── logs
  │   └── cache
  ├── nginx
  │   └── site.conf
  └── docs
      └── PLANO_PRODUCAO_PROXY_LEVE.md
```

## Modelo de Banco

### Tabela `settings`

- `key`
- `value`
- `updated_at`

### Tabela `sources`

- `id`
- `name`
- `origin_ip`
- `origin_port`
- `mode`
- `created_at`
- `updated_at`

### Tabela `audit_logs`

- `id`
- `event_type`
- `client_ip`
- `user_agent`
- `message`
- `created_at`

## Regras do Painel

- login único ou lista curta de admins
- salvar origem principal em formulário simples
- botão para validar conectividade
- botão para gerar config do Nginx
- botão para recarregar serviço
- histórico básico de mudanças

## Regras do Proxy

- manter a origem oculta
- repassar apenas headers necessários
- aceitar apenas endpoints esperados
- bloquear acesso direto ao backend
- preferir proxy reverso do Nginx em vez de stream por PHP

## Deploy Inicial

### Passo 1 - preparar a máquina

- instalar `nginx`, `php8.2-fpm`, `sqlite3`
- criar usuário de serviço
- criar diretório `/opt/proxy-mago`
- definir permissões mínimas

### Passo 2 - publicar o painel

- copiar o código para `/opt/proxy-mago`
- criar vhost do Nginx
- apontar o domínio do painel
- testar login e escrita no SQLite

### Passo 3 - configurar a origem

- registrar IP e porta do XUI
- validar acesso entre proxy e origem
- testar playlist e stream

### Passo 4 - endurecer a proteção

- ativar headers de segurança
- restringir acesso ao painel por IP se possível
- separar logs de acesso e erro
- rotacionar segredo e credenciais

## Plano de Produção

### Fase 1 - Base funcional

- criar estrutura de projeto
- criar login
- criar cadastro da origem
- salvar em SQLite

### Fase 2 - Proxy leve

- gerar configuração Nginx
- aplicar proxy reverso
- testar M3U e stream

### Fase 3 - Proteção

- token por IP
- expiração curta
- bloqueio de rotas não autorizadas

### Fase 4 - Operação

- logs
- backup
- monitoramento simples
- rotina de atualização

## Critérios de Aceite

- painel abre no domínio definido
- origem pode ser cadastrada sem editar arquivos
- stream funciona sem revelar o IP real ao cliente
- servidor permanece leve em VPS básica
- configuração pode ser alterada sem reinstalar tudo

## Próximos Passos Recomendados

1. criar a estrutura base do projeto
2. instalar Nginx e PHP-FPM no servidor
3. montar o painel mínimo em PHP puro
4. testar o proxy com uma origem de homologação
5. ajustar a proteção de token e logs

