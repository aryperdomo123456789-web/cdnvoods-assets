# Proxy Mago Base

Base leve de painel + proxy reverso para proteger uma única origem XUI.

Arquitetura atual:

- a máquina atual fica como `main`/cérebro
- o `main` mantém painel, SQLite, jobs e auditoria
- os LBs recebem a carga pesada do tráfego público
- o projeto está consolidado em `single XUI`
- o experimento `multi-XUI` é legado e não faz parte do fluxo principal

## O que tem aqui

- painel em PHP puro
- setup inicial
- autenticação simples
- persistência em SQLite
- gerador de config Nginx
- layout leve e responsivo

## Rodando

1. aponte o domínio para a VPS
2. instale `nginx`, `php8.2-fpm` e `sqlite3`
3. coloque a pasta em `/opt/proxy-mago`
4. abra `/setup.php` na primeira vez
5. faça login em `/login.php`

## Próximo passo

- apontar os domínios públicos para o `main` ou para um LB instalado
- usar a aba `LB` para jogar a carga de stream nos músculos
- manter a origem XUI apenas no cadastro interno do painel
