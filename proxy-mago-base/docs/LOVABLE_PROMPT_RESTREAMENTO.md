Implemente no repositório `aryperdomo123456789-web/cdnvoods-assets` o módulo de
**restreamento em tempo real** para o projeto da CDN, seguindo estritamente o
documento [PLANO_RESTREAMENTO_TEMPO_REAL.md](/opt/proxy-mago/proxy-mago-base/docs/PLANO_RESTREAMENTO_TEMPO_REAL.md).

Contexto obrigatório:

- o projeto final precisa rodar na VPS real `45.140.192.237`
- OS alvo: `Ubuntu 22.04`
- path alvo: `/opt/proxy-mago/proxy-mago-base`
- não trate isso como “ambiente local seu”
- todo deploy, documentação e troubleshooting devem falar explicitamente desta
  VPS e deste path

Objetivo do módulo:

Criar uma aba de painel que mostre em tempo quase real:

- usuário
- domínio público usado
- IP do cliente
- player / User-Agent
- quantas conexões ativas ele está usando agora
- limite de conexões do usuário no XUI
- última atividade
- tipo de consumo: `live`, `movie`, `series`, `m3u`, `api`

Restrições de arquitetura:

- manter PHP puro
- manter SQLite local como banco principal do painel
- a integração com XUI deve ser somente `read-only`
- não consultar o MySQL do XUI no caminho crítico do stream
- não quebrar `get.php`, `player_api.php`, `xmltv.php`, `.m3u8`, `.ts`, `/movie/`,
  `/series/`, `/live/`
- não introduzir nada pesado desnecessário

O que precisa ser construído:

1. tabelas locais novas no SQLite para espelho do XUI e runtime do proxy
2. conector read-only ao banco do XUI
3. rotina de sync curto para `user_activity_now`
4. cache local de `users`, `streams` e `user_activity_now`
5. log estruturado de requests públicos no proxy
6. matching entre request local e sessão ativa do XUI
7. dashboard de restreamento com polling
8. filtros e detalhe por usuário
9. documentação completa de produção
10. smoke tests e troubleshooting

Obrigatório validar:

- que o sistema não embaralha credenciais entre usuários
- que o request de um usuário não produz playlist de outro
- que as conexões ativas batem com o `user_activity_now` do XUI
- que o painel continua respondendo mesmo se o MySQL do XUI ficar fora
- que o stream público continua funcional se o módulo de restreamento falhar

Obrigatório testar com fluxo público real compatível com:

- XCIPTV
- IBO Player Pro
- IPTV Smarters
- TiviMate
- VLC

Entregáveis obrigatórios no GitHub:

- código completo
- documentação completa
- checklist de produção
- troubleshooting
- smoke test
- instruções explícitas para esta VPS Ubuntu 22.04

Critério de conclusão:

Só considere o trabalho concluído quando estiver pronto para ser puxado para
`/opt/proxy-mago/proxy-mago-base` e exigir no máximo:

- configurar credenciais read-only do XUI
- instalar `php8.1-mysql`
- rodar deploy e smoke test

Não simplifique o escopo. O objetivo é vir do GitHub praticamente pronto para
rodar aqui.
