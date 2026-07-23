# Proxy Mago Base

Base leve de painel + proxy reverso para proteger uma única origem XUI.

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

- conectar essa base ao Nginx real
- adicionar a camada de assinatura por IP
- adicionar rewrite das URLs do player
