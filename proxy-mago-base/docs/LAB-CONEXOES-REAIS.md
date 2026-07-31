# Laboratório real — conexões por usuário do XUI

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Alvo de produção: VPS `45.140.192.237`, Ubuntu 22.04, `/opt/proxy-mago/proxy-mago-base`.
Origem protegida: XUI `38.190.176.170` (DNS do main `dafonte.uk`).
Domínio público de teste: `voods.suafontee.com`.

## O que foi entregue

- `app/UserIntelligence.php` — parque completo de assinantes espelhado do XUI com
  conexões contratadas (`max_connections`) x em uso agora.
  - **Em uso** = `max(contador local da CDN, user_activity_now do XUI)`.
    Direct source só a CDN enxerga, então a CDN é a fonte de verdade.
  - **Playlist/API** (get.php, player_api) tem coluna própria: baixar m3u não
    ocupa slot do plano, mas precisa aparecer no painel.
  - Status por usuário: `streaming`, `full`, `over_limit`, `fetching`, `idle`,
    `expired`, `disabled`.
- `restream-data.php?view=users` e `?view=user_connections&username=...`.
- Painel `/restream.php`: bloco "Usuários do XUI — conexões contratadas x em uso"
  com busca, filtro de online/habilitado/acima do limite e KPIs do parque.
- KPI corrigido: o painel mostrava "0 conexões" enquanto baixava 180MB de m3u,
  porque playlist/API não era contada em lugar nenhum. Agora existem
  `connections_now` (vídeo), `fetch_now` (playlist/API) e `sessions_now` (total).

## Scripts de laboratório real (sem mock)

```bash
# 1) espelha o XUI real e imprime o parque de usuários
php bin/lab/real-users-lab.php 38.190.176.170 xui <user> <senha> 3306 <username-teste>

# 2) dispara tráfego REAL pela CDN e observa o contador reagir
php bin/lab/real-traffic-lab.php 38.190.176.170 voods.suafontee.com <username> <senha>
```

`bin/router.php` reproduz o roteamento do Nginx no servidor embutido do PHP
(arquivo existente em `public/` é painel; qualquer outra rota vai para o proxy).

## Resultado medido (execução real)

| Cenário | Resultado |
|---|---|
| Ping MySQL do XUI real | ok, ~1.1s |
| Usuários espelhados (tabela `lines`) | 15 |
| Catálogo de streams espelhado | 483.869 |
| `get.php` m3u_plus real | HTTP 200, 128 MB, 1 sessão `playlist` (não ocupa slot) |
| `player_api.php` | HTTP 200, 1 sessão `api` |
| 3 streams distintos | 3 conexões de vídeo |
| Burst de 6 requests no mesmo stream | continua 1 conexão (`reqs=6`) |
| Direct source | detectado e atribuído ao host final `slackewn.click` |
| Usuário `P2on2325154215633` | em uso 3 de 1000 (997 livres), fonte `cdn_local` |

## Produção

Contagem e limite continuam fora do caminho crítico do stream: o proxy só faz
1 UPSERT + 1 UPDATE por request em `cdn_sessions`; a leitura do XUI é read-only
e roda em job. Se o MySQL do XUI cair, o contador da CDN segue de pé sozinho.
