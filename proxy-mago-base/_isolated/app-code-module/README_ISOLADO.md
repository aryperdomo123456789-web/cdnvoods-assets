# Módulo Isolado — `app-code`

Data da extração: `2026-07-31`  
Origem da extração: `/opt/proxy-mago/proxy-mago-base`  
Ambiente original: VPS `45.140.192.237` / `Ubuntu 22.04`

## O que este módulo era

Este módulo implementava a aba `https://cdnvoods.vr766.com/app-code.php`.

Objetivo dele:

- receber um DNS fixo compilado dentro de um app
- descobrir em qual XUI um `username` existe
- "grudar" o usuário naquele XUI
- evitar embaralhamento entre vários XUIs

## Por que ele foi removido do projeto principal

Ele adicionava complexidade e acoplamento em pontos quentes do core:

- `bootstrap.php`
- `bootstrap-cli.php`
- `proxy-bootstrap.php`
- `public/proxy.php`
- `Database.php`
- navegação do painel

Como o foco atual do projeto principal é:

- proteção leve de XUI
- rastreamento ao vivo
- restream inteligente
- multi-LB

o módulo foi isolado para não continuar pesando no runtime principal nem confundir a evolução da CDN.

## O que foi preservado aqui

Snapshot funcional dos arquivos originais:

- `app/AppCode.php`
- `app/AppCodeRouter.php`
- `public/app-code.php`
- `public/save-app-code.php`
- `docs/CODIGO_DE_APP_MULTI_XUI.md`
- `docs/RELATORIO_CODIGO_DE_APP.md`

## O que ele precisa para funcionar como projeto separado

### Dependências de código

O módulo depende do ecossistema atual da CDN. Para renascer separado, ele ainda precisa de equivalentes para:

- `Config`
- `Cache`
- `Database`
- `SettingsRepository`
- `Audit`
- `Auth`
- `OriginRepository`
- `AliasRepository`
- `XuiOrigin`
- `RequestContext`

### Dependências de banco

Ele precisa destas tabelas:

- `app_servers`
- `app_user_routes`
- `app_negative_cache`
- `app_discovery_lock`

Também depende de:

- `settings`
- `aliases`
- `origins`

### Dependências de painel

Para a UI funcionar, ele ainda precisa de:

- sessão/admin login
- `csrf_token()`
- `csrf_verify()`
- `require_seeded_or_setup()`
- stylesheet do painel

### Dependências de proxy

Para roteamento real em produção, ele precisa ser religado em algum proxy que:

- extraia `username` e `password`
- saiba distinguir rota textual de binária
- permita trocar a origem dinamicamente
- registre falha e fallback

## O que falta para transformar em projeto próprio

1. remover dependência do painel atual
2. criar bootstrap próprio
3. criar migration própria
4. criar auth próprio
5. criar config própria
6. definir se ele vira:
   - microserviço
   - painel separado
   - biblioteca plugável
7. definir contrato de integração com o proxy principal

## Observação importante

Este diretório é um snapshot de isolamento, não um projeto pronto para subir sozinho.

Ele foi guardado para uso futuro sem continuar contaminando o runtime principal da CDN.

