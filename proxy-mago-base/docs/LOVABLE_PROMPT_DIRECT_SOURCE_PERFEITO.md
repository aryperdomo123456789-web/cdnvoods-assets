> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.

Quero que você trabalhe no repositório `aryperdomo123456789-web/cdnvoods-assets`,
branch `main`, com foco total em fechar o rastreamento de `direct source` no
nível mais alto possível de produção real.

Contexto obrigatório e inegociável:

- o projeto final vai rodar na VPS real `45.140.192.237`
- sistema operacional alvo: `Ubuntu 22.04`
- path real alvo: `/opt/proxy-mago/proxy-mago-base`
- data de referência: sexta-feira, 31 de julho de 2026
- você NÃO pode assumir ambiente local abstrato seu
- toda documentação e toda decisão técnica devem ser escritas pensando nessa
  VPS e nesse path

Leia obrigatoriamente:

- `docs/CDN_INTELIGENTE.md`
- `docs/FALTAS_RESTREAMENTO_INTELIGENTE.md`
- `docs/PLANO_ESPECIALISTA_DIRECT_SOURCE_PERFEITO.md`
- `docs/RESTREAMENTO_OBSERVABILIDADE.md`
- `docs/DEPLOY_VPS.md`

Contexto técnico real já descoberto no XUI:

- banco real: `xui`
- host: `38.190.176.170`
- schema de assinantes/sessões:
  - `lines`
  - `lines_live`
  - `streams`
- não é o schema clássico puro de `user_activity_now`
- muitos conteúdos `direct source` já estão marcados em `streams.direct_source = 1`
- muitos desses conteúdos já trazem URL externa pronta em `streams.stream_source`
- portanto o `direct source` não acontece só por redirect em runtime

Objetivo principal:

quero que a CDN fique perfeita para rastrear `direct source` tanto:

1. quando ele vier por redirect em runtime
2. quanto quando ele já vier cadastrado no `stream_source` do banco do XUI

Eu quero que o painel da CDN seja a fonte principal de verdade operacional.

O banco do XUI deve ser complementar, nunca a única fonte.

Quero que a CDN saiba:

- se um stream é `direct source`
- se ele é `direct source` por cadastro no DB
- se ele gera redirect em runtime
- qual host final foi realmente consumido
- qual host veio do DB
- qual host veio do runtime
- qual host efetivo deve ser considerado
- qual usuário usou esse conteúdo
- por qual domínio público
- por qual IP
- por qual player
- em qual sessão local da CDN
- com qual divergência, se houver

Restrições obrigatórias:

- manter PHP puro
- manter SQLite local como base principal do painel
- manter o stream leve
- não colocar consulta ao MySQL do XUI no caminho crítico do stream
- não quebrar `get.php`, `player_api.php`, `xmltv.php`, `.m3u8`, `.ts`, `/movie/`, `/series/`, `/live/`
- preservar compatibilidade real com players

Quero que você implemente o que ainda falta:

1. enriquecer `xui_streams_cache`
- guardar `direct_source`
- guardar `direct_proxy`
- guardar `stream_source_raw`
- guardar `direct_host_detected`
- guardar `source_mode`
- guardar `parse_status`

2. fazer parse real de `stream_source`
- suportar JSON textual
- suportar string simples
- suportar múltiplas URLs
- extrair host final
- marcar erro de parsing quando vier formato inesperado

3. consolidar as duas verdades do direct source
- DB do XUI
- runtime da CDN

Quero colunas/campos/resultados como:

- `direct_host_from_db`
- `direct_host_runtime`
- `direct_host_effective`
- `direct_origin_mode`
- `direct_consistency`

4. enriquecer as sessões locais da CDN
- marcar se a sessão é direct
- guardar host final
- guardar modo do direct
- guardar primeira e última vez que o direct apareceu
- guardar falhas e bloqueios do direct

5. melhorar o painel
- filtros por direct
- coluna de modo do direct
- host final do direct
- top hosts direct
- falhas por host direct
- usuários com conteúdo direct ativo agora
- divergências específicas de direct

6. criar divergências específicas
- `direct_db_runtime_mismatch`
- `direct_host_missing`
- `direct_parse_error`
- `direct_orphan_session`
- `direct_runtime_without_db_flag`
- `direct_db_flag_without_runtime`

7. melhorar jobs e métricas
- job que enriquece cache de streams com dados de direct
- job que consolida DB + runtime
- KPIs específicos de direct
- rollup por host e por tipo

8. melhorar smoke tests
- conteúdo direct vindo do DB
- conteúdo direct vindo de redirect
- caso de mismatch DB vs runtime
- caso de parsing múltiplo
- caso de falha no host final

Quero documentação final de produção explicando:

- exatamente como esse XUI específico trata `direct source`
- exatamente como a CDN passa a tratar
- o que é fonte do DB
- o que é fonte do runtime
- como interpretar divergência
- como validar no painel
- como validar via CLI
- como validar nesta VPS `45.140.192.237`

Entregáveis obrigatórios:

- código completo
- documentação completa
- smoke tests atualizados
- troubleshooting
- checklist de produção
- instruções explícitas para esta VPS `45.140.192.237`
- instruções explícitas para `Ubuntu 22.04`
- instruções explícitas para `/opt/proxy-mago/proxy-mago-base`

Critério de conclusão:

considere concluído somente quando o repositório estiver pronto para eu puxar
para esta VPS e a CDN:

- identificar `direct source` por DB
- identificar `direct source` por runtime
- consolidar ambos
- exibir isso no painel
- auditar divergências
- manter o stream leve

Não simplifique o escopo.
Não trate isso como protótipo.
Quero fechamento de produção real.
