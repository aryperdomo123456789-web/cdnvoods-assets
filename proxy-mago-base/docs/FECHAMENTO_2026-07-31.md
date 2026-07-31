# Fechamento — validação E2E, carga e checklist de produção (31/07/2026)

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Alvo real: VPS `45.140.192.237` (Ubuntu 22.04), PHP 8.1-FPM
(`/run/php/php8.1-fpm.sock`), SQLite, Nginx, base ativa em
`/opt/proxy-mago/proxy-mago-base`.

Origem XUI protegida: `dafonte.uk` / `38.190.176.170`.
Domínio público de teste: `voods.suafontee.com`.

## 1. Validação E2E do fluxo público

Executada contra a origem real, com o proxy servindo `Host: voods.suafontee.com`.

| Rota | Status | Tamanho | Vazamento de `dafonte.uk` / IP da origem |
| --- | --- | --- | --- |
| `player_api.php` (login) | 200 | 541 B | 0 |
| `player_api.php?action=get_live_categories` | 200 | — | 0 |
| `player_api.php?action=get_vod_categories` | 200 | 3,7 KB | 0 |
| `player_api.php?action=get_series` | 200 | 8,8 MB | 0 |
| `panel_api.php` | 200 | 19,3 MB | 0 |
| `get.php` (`output=mpegts`) | 200 | 94,2 MB | 0 |
| `get.php` (`output=m3u8` / `hls`) | 200 | 95,6 MB | 0 |
| `xmltv.php` | 200 | — | 0 |
| Host não cadastrado | 404 | — | — |

Amostra de linha da playlist entregue ao player:

```text
http://voods.suafontee.com/movie/<user>/<pass>/783645.mp4
```

Nenhum `301` para HTTPS, nenhum `403` por User-Agent, nenhum `Location` da
origem repassado ao cliente.

## 2. Direct Source (filme/série)

O XUI responde `302` para um CDN de terceiros (`readyondemand.click`).
O proxy agora:

- segue o redirect **por dentro** (o player nunca vê o `Location`);
- bloqueia loopback e faixas privadas no destino (anti-SSRF);
- desativa o `Host` header do XUI ao sair da origem (`markHop`);
- repassa o User-Agent real do player para o CDN;
- **mascara o host do direct source no corpo** de respostas textuais
  (`PlaylistRewriter::addHost`), então nem o CDN aparece em `.m3u8`.

Toggle: `settings.follow_external_redirects` (1 = ligado, padrão).
Com ele desligado, filme/série que dependa de direct source não toca.

Observação de laboratório: o CDN nega o IP do ambiente de teste (403), então a
validação de bytes de vídeo tem de ser feita na própria VPS — a mecânica de
redirect, headers e mascaramento já está validada.

## 3. Compatibilidade de players

`player_api.php` respondeu 200 para todos os User-Agents abaixo, com corpo
reescrito e sem vazamento:

- XCIPTV
- IBO Player Pro
- IPTV Smarters
- TiviMate
- VLC / LibVLC

O filtro de User-Agent continua **desligado por padrão**
(`settings.ua_filter_enabled = 0`), então qualquer app XUI passa.

## 4. Teste de carga (playlist de ~92 MB, a rota mais cara)

| Usuários simultâneos | Wall | Total trafegado | Vazão | Tempo médio | RAM PHP acima da base |
| --- | --- | --- | --- | --- | --- |
| 5 | 19,9 s | 449 MB | 22,6 MB/s | 18,9 s | ~0 MB |
| 10 | 38,1 s | 899 MB | 23,6 MB/s | 22,3 s | ~0 MB |
| 20 | 65,5 s | 1,80 GB | 27,5 MB/s | 48,5 s | ~2 MB |
| 50 | 125,7 s | 4,49 GB | 35,8 MB/s | 92,8 s | ~4 MB |

Todas as 85 requisições retornaram `200`. Zero erros, zero timeouts.

API leve (`get_vod_categories`): 50 simultâneos em 1,2 s, todos 200.

Custo de CPU do rewrite: **17 s de CPU para ~900 MB reescritos**
(~19 ms por MB, ~1,9 s de CPU por playlist de 92 MB). O gargalo real é banda,
não CPU nem RAM — a reescrita em streaming mantém memória constante.

## 5. Checklist de produção (rodar na VPS)

```bash
cd /opt/proxy-mago/proxy-mago-base
sudo bash bin/deploy.sh
bash bin/smoke-test.sh voods.suafontee.com <usuario> <senha>
```

Antes de liberar para clientes, confirmar:

- [ ] `bin/smoke-test.sh` termina com `SMOKE TEST OK`
- [ ] `/` , `/login.php`, `/dashboard.php`, `/setup.php`, `/health.php` devolvem
      **404** no domínio público (só existem em `panel_domain`)
- [ ] Nenhum `301` de `:80` para `:443` no domínio do cliente
- [ ] DNS do cliente: `A -> 45.140.192.237` ou `CNAME -> cdnvoods.vr766.com`,
      **DNS only / nuvem cinza**
- [ ] Nenhum registro público apontando para `38.190.176.170` ou `dafonte.uk`
- [ ] `settings.ua_filter_enabled = 0`
- [ ] `settings.follow_external_redirects = 1`
- [ ] `settings.log_segments = 0` (evita um INSERT por `.ts`)
- [ ] `php_admin_value[memory_limit] = 256M` no pool FPM (rede de segurança)
- [ ] `storage/` pertence a `www-data` com `775`
- [ ] Filme e série abrindo em pelo menos dois apps reais

## 6. Limites conhecidos

- `tvg-logo` de terceiros continua apontando para hosts externos. Não vaza a
  origem; proxiar isso custaria banda e CPU.
- HTTPS no domínio do cliente exige certificado por domínio (Let's Encrypt).
  O fluxo oficial hoje é HTTP puro, sem redirect.
- Banda da VPS é o teto: cada playlist de 92 MB é 92 MB de saída.
