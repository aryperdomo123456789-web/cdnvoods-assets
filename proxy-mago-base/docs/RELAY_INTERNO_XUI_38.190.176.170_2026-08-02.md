# Relay interno via XUI `38.190.176.170` — 2 de agosto de 2026

## Objetivo

Fazer `movie` e `series` tocarem sem expor:

- o XUI `38.190.176.170`
- o host final de direct source
- a cadeia `readyondemand.click -> highcdnvideo.link`

Arquitetura desejada:

- cliente -> `voods.suafontee.com`
- CDN/LB -> cérebro
- cérebro -> relay privado no XUI `38.190.176.170`
- relay -> host final permitido por esse IP
- bytes voltam pela CDN ao cliente

## Causa raiz confirmada

Em `2 de agosto de 2026` foi confirmado:

- `readyondemand.click` barra os IPs do cérebro `45.140.192.237` e do LB `143.14.168.78`
- o mesmo conteúdo funciona quando o request sai do próprio XUI `38.190.176.170`
- logo, a classe de host bloqueada precisa de egress pelo `38.190.176.170`

## O que foi implementado

### 1. Relay privado versionado no projeto

Arquivo criado:

- [bin/lib/xui-internal-relay.php](/opt/proxy-mago/proxy-mago-base/bin/lib/xui-internal-relay.php)

Função:

- aceita só IP permitido
- exige token privado
- restringe hosts aceitos
- faz streaming com `Range`, `User-Agent`, `Accept` e `Accept-Encoding`

Deploy aplicado no XUI:

- `/home/xui/www/internal-relay.php`
- segredo local em `/home/xui/config/internal-relay.token`

### 2. Cérebro preparado para usar o relay

Arquivos alterados:

- [public/lb-direct-fetch.php](/opt/proxy-mago/proxy-mago-base/public/lb-direct-fetch.php)
- [storage/local.config.php](/opt/proxy-mago/proxy-mago-base/storage/local.config.php)

Config aplicada:

- `xui_internal_relay_url = http://38.190.176.170/internal-relay.php`
- `xui_internal_relay_token = <segredo>`

Comportamento:

- o cérebro aceita targets dessa classe
- para hosts bloqueados ou origem XUI de `movie/series`, tenta usar o relay interno

### 3. LB-Go preparado para mandar `movie/series` direto ao relay do XUI

Arquivo alterado:

- [lb-go/internal/proxy/handler.go](/opt/proxy-mago/proxy-mago-base/lb-go/internal/proxy/handler.go)

Comportamento:

- `movie` e `series` entram em `relay xui`
- o LB evita insistir no fetch direto pelos IPs bloqueados

Deploy aplicado:

- binário novo publicado no `LB-01` `143.14.168.78`

## O que funcionou de verdade

### OK

- o relay privado no `38.190.176.170` respondeu `206 Partial Content` quando chamado diretamente com target já em `readyondemand.click`
- o cérebro respondeu `206` quando apontado para esse relay com target direto em `readyondemand.click`
- o `LB-01` passou a registrar `relay xui para path=/movie/...` e `relay xui para path=/series/...`

### Ainda bloqueado

Quando o relay recebe como target a própria URL XUI:

- `http://38.190.176.170/movie/...`
- `http://38.190.176.170/series/...`

o fluxo ainda não fecha limpo.

Sinais observados:

- em um estágio o relay devolveu `429 Too Many Requests`
- em outro estágio devolveu `500 Internal Server Error`
- em outro teste o corpo retornado foi:
  `{"error":"MySQL: Cannot connect to database!..."}`

Conclusão técnica:

- o relay no XUI já existe e está no ar
- o cérebro e o LB já estão adaptados para usá-lo
- o bloqueio final está no comportamento do próprio XUI quando o relay tenta resolver o primeiro hop `movie/series` localmente

## Estado real no fim desta execução

### Implantado

- relay privado no XUI
- cérebro integrado ao relay
- LB integrado ao relay
- documentação criada

### Não concluído

- `movie` e `series` públicos ainda não ficaram estáveis nesse caminho novo
- o primeiro hop interno `XUI -> auth/redirect` ainda reage mal quando disparado de dentro do relay

## Próximo passo recomendado

Fechar o primeiro hop dentro do XUI sem passar pelo mesmo caminho web público de `auth.php`.

As duas saídas mais prováveis são:

1. criar endpoint interno no `38.190.176.170` que resolva o direct source por dentro da lógica do XUI, sem reaproveitar o caminho público `/movie` e `/series`
2. criar um bypass local no Nginx/XUI para esse hop interno, sem `limit_req` e sem dependência do caminho web normal do assinante

## Resumo curto

O relay profissional via `38.190.176.170` foi iniciado de verdade e já tem:

- código no projeto
- arquivo publicado no XUI
- integração no cérebro
- integração no LB

O que falta não é “começar”. O que falta é resolver o primeiro hop interno do XUI para esse relay conseguir transformar `movie/series` em bytes sem cair no caminho público problemático do próprio XUI.
