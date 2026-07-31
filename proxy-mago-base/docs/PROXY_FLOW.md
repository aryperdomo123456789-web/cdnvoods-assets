# Fluxo do proxy — Fase 1→4

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Este documento descreve como o painel esconde a origem XUI e roteia streams
pelo main público oficial (`cdnvoods.vr766.com`).

## Componentes

| Arquivo | Papel |
| --- | --- |
| `app/OriginRepository.php` | CRUD das origens (host/porta/credenciais) — só em SQLite |
| `app/AliasRepository.php`  | CRUD dos hostnames públicos, com marcação de main oficial |
| `app/Tokens.php`           | Emite/valida tokens efêmeros por alias (opcionalmente presos ao IP) |
| `app/AccessGuard.php`      | Valida alias + origem + token + rate-limit + UA e loga |
| `app/PlaylistRewriter.php` | Reescreve playlists M3U/M3U8 para esconder origem e injetar token |
| `app/StreamProxy.php`      | cURL buffered para playlists / streaming chunkado para segmentos |
| `public/proxy.php`         | Entrada única do proxy (recebe `/get.php`, `.m3u8`, `.ts`, etc.) |

## Fluxo de um request de playlist

```
Player ──HTTP/HTTPS──► Dominio publico ──► Nginx VPS ──► public/proxy.php
                                                    │
                                                    ├─ AccessGuard: alias público? origem ativa? token válido?
                                                    │
                                                    ├─ StreamProxy::fetchBuffered  → XUI real (SQLite)
                                                    │
                                                    ├─ PlaylistRewriter::rewrite  → remove IP da origem, injeta token
                                                    │
                                                    └─ resposta com Content-Type m3u8, sem headers de origem
```

## Regras não negociáveis

- IP/host da origem **nunca** aparece em DNS, log de acesso ou header público.
- Credenciais da origem só existem em `origins.auth_user/auth_pass` (SQLite).
- O token público é opaco (32 hex) e vencível; um mesmo player recebe um novo a
  cada playlist emitida se não trouxer um explicitamente.
- Rate limit por IP configurável (padrão 240 req/min).
- CNAMEs extras podem apontar para o main oficial ou ir por A direto para a VPS.
- Operacao atual recomendada: DNS only / nuvem cinza.
