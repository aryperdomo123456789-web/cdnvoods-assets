# Pesquisa profunda — Apps IPTV x requisitos de CDN (2026-08-01)

Documento de referência para engenharia do CDN Voods. Consolida (a) o comportamento
HTTP REAL dos apps IPTV do mercado contra um painel Xtream Codes / XUI.one e
(b) tudo que a CDN precisa ter para entregar MPEG-TS e HLS com latência mínima
em escala (10k+ conexões).

---

## PARTE 1 — Comportamento HTTP real dos apps

### 1.1 Base do protocolo Xtream Codes (todos os apps falam isso)

| Família | Endpoint |
| --- | --- |
| Auth/API JSON | `GET /player_api.php?username=U&password=P[&action=...]` |
| Playlist legada | `GET /get.php?username=U&password=P&type=m3u_plus&output=ts|hls` |
| EPG | `GET /xmltv.php?username=U&password=P` |
| Live | `/live/{user}/{pass}/{stream_id}.ts` ou `.m3u8` |
| Filme | `/movie/{user}/{pass}/{vod_id}.{container_extension}` |
| Episódio | `/series/{user}/{pass}/{episode_id}.{ext}` |
| Catchup | `/timeshift/{user}/{pass}/{dur}/{start}/{stream_id}.ts` |
| Capas | valor arbitrário de `stream_icon` / `movie_image` / `cover` (host qualquer) |

`action` relevantes: `get_live_categories`, `get_live_streams`, `get_vod_categories`,
`get_vod_streams`, `get_series_categories`, `get_series`, `get_series_info`,
`get_vod_info`, `get_short_epg`, `get_simple_data_table`.
Sem `action` → `user_info` + `server_info` (`auth`, `status`, `exp_date`,
`active_cons`, `max_connections`).

**Ponto crítico para nós:** o XUI original responde `302` para load balancer.
Muitos clientes perdem `Range` / headers ao seguir o redirect. Nossa CDN deve
**evitar 302 no hot path** e entregar em 200 com repasse chunkado.

### 1.2 Tabela comparativa dos apps

| App | UA customizável | Endpoint principal | Formato padrão | Segue 302 | Paginação lazy | Multi-conexão | Engine |
| --- | --- | --- | --- | --- | --- | --- | --- |
| IBO Player / Pro | Não | player_api.php | `.ts` | Sim | Sim (por categoria) | Baixa | ExoPlayer / AVPlayer |
| XCIPTV | Parcial (versões novas) | player_api.php + get.php | `.ts` | Sim | Parcial | Baixa | ExoPlayer ou VLC interno |
| IPTV Smarters Pro / Lite | Não | player_api.php | `.ts`/`.m3u8` | Sim | **Não — bulk load** | Média (thumbs) | ExoPlayer / VLCKit |
| Smart STB / MAG / STB Emu | N/A (MAC) | `portal.php` (Stalker) | `.ts` | Sim | Sim | Alta (heartbeat) | Player nativo STB |
| Duplex Play | Não | player_api.php | `.ts`/`.m3u8` | Depende da plataforma | Sim | Baixa | Tizen AVPlay / webOS / Roku |
| Flix IPTV | Não | player_api.php | `.ts`/`.m3u8` | Sim | **Não — bulk** | Média | ExoPlayer |
| Net IPTV | Não | Xtream/M3U/Stalker | Auto | Depende | Sim (cache EPG local) | Baixa | Nativo por plataforma |
| Sparkle TV | Não documentado | Xtream/M3U/XMLTV | `.ts`/`.m3u8` | Sim | Sim | **Alta** (multi-tela/DVR) | ExoPlayer |
| TiviMate | **Sim (manual por playlist)** | player_api.php | respeita a origem | Sim | Sim | **Alta** (multi-tela) | ExoPlayer |
| Web (hls.js/video.js/Clappr) | Sim (fetch headers) | `.m3u8` via proxy | **HLS/fMP4 only** | Sim, mas CORS quebra | N/A | Baixa | MSE + remux JS |

### 1.3 Detalhes por app que impactam a CDN

**IBO Player Pro** — só `player_api.php`, nunca M3U cru. Lazy load por categoria.
EPG por `get_short_epg` sob demanda. Live em `.ts`. `Range` só em VOD.
Timeout de API ~5-10s, de stream 15-20s. Poucas conexões paralelas + burst de capas.

**XCIPTV** — aceita login Xtream ou M3U. Player trocável (ExoPlayer/VLC), o que muda
a tolerância a `Content-Type` errado. Zapping rápido gera rajada de `player_api.php`
antes do `.ts`. Sensível a TLS autoassinado em builds de TV.

**IPTV Smarters Pro / Lite** — o mais pesado: no login baixa **tudo** (`get_live_streams`
sem `category_id`, `get_vod_streams`, `get_series`) em JSONs de vários MB, sem paginar.
Também pode baixar `xmltv.php` inteiro. Falha feio se o painel devolver HTML de erro
no lugar de JSON. Abre muitas conexões paralelas de pôster na grade de filmes.

**Smart STB / MAG / STB Emu** — **não é Xtream**, é Stalker/Ministra:
`GET /portal.php?type=stb&action=handshake&token=&JsHttpRequest=1-xml` com
`Cookie: mac=...; stb_lang=en; timezone=...`, `X-User-Agent: Model: MAG254; Link: WiFi`
e UA `Mozilla/5.0 (QtEmbedded; U; Linux; C) ... MAG200 stbapp ver: 2 rev: 250`.
Depois `Authorization: Bearer <token>`, `get_profile`, `itv&action=get_ordered_list`,
`itv&action=create_link`. Auth é por MAC, não user/pass. Handshake acima de 3-5s de
TTFB já dá "portal indisponível". Gera heartbeat contínuo (`events_get`, `get_localization`).

**Duplex Play / Net IPTV / Sparkle (Smart TV)** — engine nativo (Tizen AVPlay,
webOS luna, Roku roVideoPlayer) é **rígido com MIME**: exige `video/mp2t` para TS e
`application/vnd.apple.mpegurl` para HLS, e `Accept-Ranges` correto em VOD.
Redirect HTTP→HTTPS falha por mixed-content.

**TiviMate / Sparkle** — multi-tela abre **N conexões TCP de stream simultâneas**,
cada uma contando como conexão no plano. TiviMate permite trocar UA por playlist
(workaround real contra DPI/throttling de ISP) — logo, **não podemos exigir UA fixo**.

**Web players** — navegador não toca MPEG-TS nativo: só HLS (fMP4 via MSE/hls.js) ou
Safari nativo. E XUI não manda CORS. Para web precisamos de `Access-Control-Allow-Origin`
e `Content-Type` correto no manifest — inclusive nas respostas de redirect.

### 1.4 Causas reais de tela preta (checklist de defeito)

1. HTML de erro do painel com `Content-Type: text/html` no lugar do `.ts`.
2. `max_connections` estourado — inclusive por conexões fantasma e multi-tela.
3. `302` para servidor secundário fora do ar / porta bloqueada.
4. TTFB alto: ExoPlayer/AVPlayer cortam em 10-15s.
5. TLS autoassinado (Tizen/webOS/iOS ATS são rígidos).
6. `Content-Type` errado — quebra Smart TV mesmo funcionando no ExoPlayer.
7. `xmltv.php` gigante sem gzip travando Smarters/Flix.

---

## PARTE 2 — O que a CDN precisa ter

### 2.1 Nginx / OpenResty

```nginx
# live: repassa byte a byte, sem acumular
location /live/ {
    proxy_pass http://origin_backend;
    proxy_buffering off;
    proxy_request_buffering off;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    chunked_transfer_encoding on;
    proxy_connect_timeout 5s;      # falha rápido
    proxy_read_timeout 3600s;      # live 24/7
    proxy_send_timeout 3600s;
    send_timeout 3600s;
}

# VOD/catchup: cache + slice para Range/seek
location /vod/ {
    slice 1m;
    proxy_cache vod_cache;
    proxy_cache_key $uri$is_args$args$slice_range;
    proxy_set_header Range $slice_range;
    proxy_cache_valid 200 206 1d;
    proxy_pass http://origin_backend;
}

# segmentos e manifest: absorve thundering herd
proxy_cache_path /dev/shm/hls_cache levels=1:2 keys_zone=hls_cache:200m
                 max_size=4g inactive=30s use_temp_path=off;

location ~ \.(ts|m4s)$ {
    proxy_cache hls_cache;
    proxy_cache_valid 200 10s;
    proxy_cache_lock on;                 # 1 request à origem por chave
    proxy_cache_lock_timeout 3s;
    proxy_cache_use_stale updating error timeout http_500 http_502 http_503 http_504;
    proxy_cache_background_update on;
    add_header X-Cache-Status $upstream_cache_status;
}
location ~ \.m3u8$ { proxy_cache hls_cache; proxy_cache_valid 200 1s; proxy_cache_use_stale updating; }
```

```nginx
http {
    sendfile on; tcp_nopush on; tcp_nodelay on;
    aio threads; aio_write on;
    output_buffers 2 512k;
    directio 4m;
}
events { worker_connections 65536; use epoll; multi_accept on; accept_mutex off; }
worker_processes auto; worker_rlimit_nofile 1000000; worker_cpu_affinity auto;
# listen 443 ssl http2 reuseport;   -> reuseport elimina contenção de accept()
upstream origin_backend { server o1:8080; keepalive 256; keepalive_requests 10000; keepalive_timeout 60s; }
thread_pool default threads=64 max_queue=65536;
```

### 2.2 Kernel Ubuntu 22.04

```conf
# /etc/sysctl.d/99-streaming.conf
net.core.default_qdisc = fq
net.ipv4.tcp_congestion_control = bbr
net.core.somaxconn = 65535
net.ipv4.tcp_max_syn_backlog = 65535
net.core.netdev_max_backlog = 65535
net.ipv4.tcp_syncookies = 1
net.netfilter.nf_conntrack_max = 1048576
net.netfilter.nf_conntrack_tcp_timeout_established = 600
net.core.rmem_max = 67108864
net.core.wmem_max = 67108864
net.ipv4.tcp_rmem = 4096 1048576 67108864
net.ipv4.tcp_wmem = 4096 1048576 67108864
net.ipv4.tcp_notsent_lowat = 131072
```

- BBR **exige** qdisc `fq` (sem fq o pacing quebra). BBR ganha em última milha
  residencial com bufferbloat; CUBIC ainda pode vencer entre datacenters.
- `ip route change default ... initcwnd 20 initrwnd 20` reduz slow-start no TTFB
  de segmento HLS.
- `tcp_notsent_lowat` evita empilhar bytes obsoletos para cliente lento.
- `LimitNOFILE=1000000` no systemd + `limits.d`.

### 2.3 TLS e protocolo

```nginx
ssl_protocols TLSv1.3 TLSv1.2;
ssl_prefer_server_ciphers off;
ssl_session_cache shared:SSL:50m;
ssl_session_timeout 1d;
ssl_session_tickets on;
ssl_early_data on;               # 0-RTT: GET de segmento é idempotente
ssl_stapling on; ssl_stapling_verify on;
```

- **HTTP/1.1 keepalive é a escolha certa para `.ts` e segmentos.** HTTP/2 sofre
  TCP head-of-line blocking (perda de 0,1% travando a conexão inteira), tem
  overhead de framing e flow-control por stream, e para MPEG-TS contínuo não
  oferece benefício algum de multiplexação.
- HTTP/3 / QUIC resolve o HOL entre streams e acelera reconexão/zapping
  (1-RTT / 0-RTT). Oferecer como progressivo via `Alt-Svc`, ciente de que
  alguns ISPs deprioritizam UDP e o custo de CPU por conexão é maior.
- MoQ (Media over QUIC) é a aposta de médio prazo para sub-segundo; suporte de
  STB/TV ainda insuficiente.

### 2.4 Arquitetura de distribuição

```
[10k+ clientes] → [Edges/PoPs — GeoDNS ou Anycast]
                       ↓ poucas conexões (cache miss / restream fetch)
                  [Origin Shield por região]
                       ↓ 1 conexão por stream
                  [Origem XUI real / encoder]
```

**Restream 1:N (ring buffer)** — padrão central para MPEG-TS ao vivo:
1 goroutine lê o socket da origem para um ring buffer compartilhado; N goroutines
(1 por cliente) leem desse buffer com offset próprio. Regras:
- cliente lento **nunca** bloqueia a leitura da origem nem os outros clientes;
- cliente que ficou muito atrás sofre "seek" para o ponto mais recente (drop),
  em vez de crescer memória sem limite;
- dimensionar o buffer para alguns segundos de bitrate de pico (8 Mbps × 3 s ≈ 3 MB),
  tamanho múltiplo de 188 bytes.

Complementos: **prefetch** do próximo segmento anunciado no manifest antes do
primeiro cliente pedir; **health check ativo** por edge/origem; **sticky de PoP**
para preservar localidade de cache e o fan-out 1:N.

### 2.5 Engine em Go (nosso `lb-go`)

- `io.Copy` entre dois `*net.TCPConn` ativa `splice(2)` automaticamente.
  **Qualquer wrapper** (`bufio`, `io.TeeReader` para métricas) derruba
  `ReaderFrom`/`WriterTo` e dobra o CPU — contadores devem delegar
  `ReadFrom`/`WriteTo` ou medir fora do hot path.
- Buffer múltiplo de 188: `188 * 348 ≈ 64 KB` para live; 256 KB–1 MB só para VOD.
- `sync.Pool` para buffers, `automaxprocs` em container, `GOMEMLIMIT`/
  `debug.SetMemoryLimit` para conter GC em pico.
- Rust/`io_uring` (`tokio-uring`, `glommio`) é a fronteira de performance sem GC.
- Gargalo real do fan-out costuma migrar para NIC/IRQ: usar `ethtool -L/-X`
  (múltiplas filas + RSS) e `SO_REUSEPORT` também no processo Go.

### 2.6 Métricas que importam de verdade

| Métrica | Por que | Onde medir |
| --- | --- | --- |
| TTFB | zapping e início de VOD | `$upstream_response_time` / instrumentação Go |
| Jitter de segmento | risco de rebuffer | delta de entrega entre segmento N e N+1 |
| Rebuffer ratio | QoE real | reportado pelo player |
| Bitrate sustentado | entrega vs. nominal | bytes/tempo por conexão |
| Conexões por core | escala horizontal | carga com wrk2/h2load |
| RAM por conexão | densidade por servidor | RSS/conexões ativas |

Com `proxy_buffering off` e buffers pequenos, o cenário I/O-bound bem tunado
chega à faixa de dezenas de milhares de conexões por core; o gargalo passa a ser
handshake TLS (CPU) — mitigado por session tickets/resumption.

---

## PARTE 3 — Aplicação direta ao CDN Voods

1. **Nunca devolver HTML no caminho de mídia.** Erro de linha/limite deve virar
   status HTTP puro, não página — HTML mata o player em silêncio.
2. **`Content-Type` correto sempre**: `video/mp2t` para `.ts`,
   `application/vnd.apple.mpegurl` para `.m3u8`, `Accept-Ranges: bytes` em VOD.
   É o que faz Tizen/webOS/Roku funcionarem.
3. **Sem UA obrigatório**: TiviMate e afins trocam UA de propósito. Usar UA só
   para telemetria/identificação de app, nunca como gate.
4. **Zero 302 no hot path** — repasse em 200 chunkado preserva `Range` e headers.
5. **`Range` obrigatório em VOD/série** (seek) e `slice`+cache na borda.
6. **`get.php` e `player_api.php` são caminhos "bulk"** (Smarters/Flix baixam tudo):
   cachear resposta por curto TTL + gzip. `xmltv.php` **sempre** com gzip.
7. **Contagem de conexão precisa tolerar multi-tela e zapping**: N sockets do
   mesmo usuário no mesmo stream = 1 conexão lógica; sessão fantasma deve expirar
   rápido (já é o comportamento do `CdnSession`/`UserIntelligence`).
8. **Stalker/MAG** exige gateway próprio (`portal.php`, MAC binding, heartbeat) —
   hoje fora do escopo do proxy Xtream; se entrar, precisa de rota dedicada com
   TTFB de handshake abaixo de 3s.
9. **Web player** só funciona com CORS liberado + HLS; TS cru não toca no browser.
10. **`lb-go` deve preservar splice** e usar buffer alinhado a 188 bytes.

