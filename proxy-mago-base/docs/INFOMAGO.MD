1. Tabela mestre — streams (canal, filme e episódio moram todos aqui)



Discriminador é o campo type:





1 = Live (canal)


2 = Movie (filme VOD)


5 = Series (episódio) — ⚠️ nesta base é 5, não 3 (doc legado antigo erra isso)





Campos que importam pro rastreio:



Campo	Papel
id	PK usada por todas as outras tabelas
type	1/2/5
category_id	longtext, guarda lista JSON-like: [8141] ou ['8141'] — preserve o formato já existente
stream_display_name	nome exibido
stream_source	JSON array de URLs — é aqui que mora o link real da fonte
stream_icon	capa/logo
movie_properties	JSON de metadados VOD (tmdb_id, plot, cover_big, rating, releasedate, duration…)
direct_source	1 = entrega o link cru, sem transcode
target_container	normalmente mp4 em VOD/episódio
series_no	em episódio, aponta pro streams_series.id do pai
tmdb_id	chave forte de dedup
epg_id / channel_id	ligação com o guia (só live)


2. Séries — vínculo tríplice (obrigatório)


streams_series (pai)        -> id, title, category_id, cover, plot, cast,
                               director, tmdb_id, seasons(JSON), backdrop_path,
                               youtube_trailer, year
streams (episódio, type=5)  -> stream_source, movie_properties, series_no = pai
streams_episodes (elo)      -> id, series_id, stream_id, season_num, episode_num




Sem streams_episodes a série existe no banco mas fica sem navegação no app. E seasons não pode ser NULL — precisa de array com season_number + episode_count refletindo os episódios locais reais.



3. Distribuição — streams_servers (sem isso nada toca)



Chaves: stream_id + server_id (unique index).





stream_status: 1 ativo / 0 parado


on_demand: 1 para VOD (filme/episódio, lê só no play), 0 para live (fluxo contínuo)


parent_id: origem do sinal em Load Balancer


current_source, bitrate, resolution, video_codec, audio_codec: telemetria real do fluxo




4. Organização e liberação comercial




streams_categories — category_type = live | movie | series, parent_id=0 = raiz, cat_order, is_adult


bouquets — bouquet_channels, bouquet_movies, bouquet_series são arrays JSON de IDs


lines — conta do cliente final, campo bouquet (JSON) define o que ele enxerga


users_packages — planos de revenda, campo bouquets (JSON)


streams_options + streams_arguments — headers técnicos (User-Agent, Referer) por stream




5. Rastreio completo até o link de stream



Do cliente até o arquivo (leitura):



lines.username/password
  └─ lines.bouquet (JSON de bouquet ids)
       └─ bouquets.bouquet_channels/movies/series (JSON de stream ids)
            └─ streams.id
                 ├─ streams.category_id  → streams_categories.id
                 ├─ streams.stream_source → ["http://origem/....ts"]   ← LINK REAL
                 ├─ streams_options → headers (User-Agent/Referer)
                 └─ streams_servers (stream_id+server_id) → onde é entregue




Série: streams_series.id → streams_episodes.series_id → .stream_id → streams.stream_source.




URL que o cliente consome (o Nginx embutido do XUI reescreve /live/*, /movie/*, /series/* para stream/live.php, stream/vod.php):



http://host:port/live/{username}/{password}/{streams.id}.ts
http://host:port/movie/{username}/{password}/{streams.id}.mp4
http://host:port/series/{username}/{password}/{streams.id}.mp4




Ou seja: o ID na URL do player é literalmente streams.id — daí você volta pro stream_source e acha a origem.



6. Regras que o Mago-Core já respeita (e você não pode quebrar)



Ordem de escrita: categoria → streams → streams_servers → bouquet → (série: streams_series antes, streams_episodes depois).
Ordem de exclusão (cascata segura): streams_servers → streams_options → streams_episodes → streams → streams_series → epg_data → limpar IDs dos JSONs de bouquets e users_packages.
Dedup: tmdb_id > identificador estável da origem > nome sanitizado. Nunca nome bruto.




Se quiser, gero isso como documento (.docx/.md) com os diagramas e os SQLs de rastreio prontos pra colar no banco.

Gerar diagrama de rastreio
Criar SQL de auditoria
Criar endpoint de trilha
Rodar verificador de consistência


Segue o mapa exato dos modos de entrega no XUI/XC_VM — todos vivem em streams + streams_servers.



1. Os 4 modos e onde ficam


Modo	Campo	Valor	Efeito
Live restream (padrão)	streams_servers.on_demand = 0 + streams.direct_source = 0	—	O core abre o input 24/7, grava buffer em /home/xc_vm/wwwdir/... e serve pro cliente pelo streaming/clients_live.php
On Demand (live sob demanda)	streams_servers.on_demand = 1	—	O canal só liga quando o 1º cliente pede, desliga após delay_minutes/timeout. Economiza banda em canal pouco visto
Direct Source	streams.direct_source = 1	—	O painel não processa nada — devolve 302 pro link cru de stream_source. Zero CPU/banda no seu servidor, mas expõe a origem e perde estatística/timeshift
Direct Stream / Proxy	streams.direct_proxy = 1	—	O painel faz proxy do link em tempo real (passa pelo seu IP, sem gravar em disco). Esconde a fonte, consome banda, mas não usa ffmpeg



Complementares que mudam o caminho:





streams.read_native = 1 → lê o input sem transcode/remux (pass-through puro do ffmpeg)


streams.custom_ffmpeg → força linha de comando própria (ignora o perfil do transcode)


streams.transcode_profile_id → 0 = sem transcode; >0 puxa de profiles




2. Caminho por tipo de conteúdo



Canal (type=1)



streams.stream_source  -> JSON array: ["http://origem/live.ts","http://backup/live.ts"]
streams.direct_source  -> 0 = restream | 1 = redirect
streams.direct_proxy   -> 1 = proxy sem disco
streams_servers.on_demand -> 1 = liga só com cliente
streams_servers.server_id -> em qual LB/edge esse canal roda
streams_servers.pid / parent_id -> processo vivo (monitor)




Filme (type=2) — sempre VOD, o "modo" aqui é como o arquivo é lido:



streams.stream_source -> ["/home/xc_vm/movies/arquivo.mkv"]  (local)
                       ou ["http://origem/filme.mkv"]        (remoto)
streams.direct_source = 1  -> remoto sem tocar no seu servidor
streams.target_container -> "mp4"/"mkv" (o que o player recebe)
streams.movie_symlink = 1 -> cria symlink em vez de copiar o arquivo




Episódio (type=5) — idêntico a filme, com o vínculo:



streams_episodes.stream_id  -> streams.id
streams_episodes.series_id  -> streams_series.id
streams_episodes.season_num / episode_num



3. URL final que o cliente recebe


Live       : http://host:port/live/USER/PASS/{stream_id}.m3u8|.ts
Movie      : http://host:port/movie/USER/PASS/{stream_id}.{target_container}
Series     : http://host:port/series/USER/PASS/{stream_id}.{target_container}




O que decide o que acontece depois dessa URL é exatamente o trio direct_source / direct_proxy / on_demand.



4. Regra prática pra tua produção (1000 canais / 5000 simultâneos)




Canais principais: on_demand=0, direct_source=0, direct_proxy=0 → restream normal no LB


Canais de cauda longa (pouco vistos): on_demand=1 → economiza uplink na origem


Fontes de terceiro que você não quer bancar banda: direct_source=1


Fontes que precisam esconder o link mas sem gravar: direct_proxy=1


Nunca misture direct_source=1 com on_demand=1 — o segundo é ignorado e vira redirect puro.





Quer que eu escreva isso como módulo no Mago-Core (um seletor de modo por linha no sincronizador, gravando direct_source/direct_proxy/on_demand no servidor ativo)?