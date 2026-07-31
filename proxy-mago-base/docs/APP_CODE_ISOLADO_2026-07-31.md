# App Code Isolado do Core

Data: `2026-07-31`  
Projeto principal: `/opt/proxy-mago/proxy-mago-base`  
VPS: `45.140.192.237`  
SO: `Ubuntu 22.04`

## Resumo

A aba `app-code.php` foi removida do projeto principal e isolada em:

`/opt/proxy-mago/proxy-mago-base/_isolated/app-code-module`

## O que saiu do core

- tela `public/app-code.php`
- handler `public/save-app-code.php`
- classes `app/AppCode.php` e `app/AppCodeRouter.php`
- integração do `public/proxy.php`
- carregamento em `bootstrap.php`
- carregamento em `bootstrap-cli.php`
- carregamento em `proxy-bootstrap.php`
- links da navegação do painel
- migração `migrateAppCode()` do `Database.php`

## O que não foi feito de propósito

Não foi feito `DROP TABLE` no banco local.

As tabelas antigas do módulo podem continuar existindo em `storage/app.sqlite`,
mas o runtime principal não depende mais delas nem carrega mais o módulo.

Isso foi escolhido para evitar risco operacional no projeto atual.

## Onde está a documentação do módulo

Dentro do snapshot isolado:

- `_isolated/app-code-module/README_ISOLADO.md`
- `_isolated/app-code-module/docs/CODIGO_DE_APP_MULTI_XUI.md`
- `_isolated/app-code-module/docs/RELATORIO_CODIGO_DE_APP.md`

## Objetivo

Deixar o projeto principal focado em:

- CDN leve
- proteção de XUI
- rastreamento ao vivo
- restream inteligente
- multi-LB

e preservar o `app-code` para virar projeto separado no futuro.

