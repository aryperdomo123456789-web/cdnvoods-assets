# Arquitetura leve sem nuvem laranja

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Data: 31/07/2026

## Objetivo

Operar esta CDN/proxy de protecao de XUI da forma mais leve possivel na VPS
`45.140.192.237`, sem usar Cloudflare com nuvem laranja.

Neste modelo:

- a VPS nao precisa esconder o proprio IP
- a VPS precisa esconder apenas a origem XUI
- o dominio publico aponta para a VPS
- a origem XUI fica apenas no SQLite interno
- a VPS atua como gateway/proxy de protecao, nao como aplicacao pesada

## Decisao operacional

Nao usar Cloudflare proxied / nuvem laranja para o trafego publico.

Usar apenas:

- `A -> 45.140.192.237`
- ou `CNAME -> cdnvoods.vr766.com`
- sempre em modo DNS only / nuvem cinza

Motivo:

- simplifica a operacao
- reduz variaveis externas
- evita comportamento de borda inadequado para trafego VOD
- mantem a VPS como unico ponto de protecao/controlador

## Arquitetura recomendada

Fluxo:

```text
Cliente/Player
  -> dominio publico (ex: meudominio.com)
  -> VPS 45.140.192.237
  -> Nginx
  -> public/proxy.php
  -> validacao minima / lookup SQLite
  -> origem XUI real (somente internamente)
```

Principios:

- PHP fica com a parte de controle
- Nginx e cURL fazem o transporte
- SQLite guarda somente configuracao e metadados
- a VPS nao deve fazer trabalho desnecessario por segmento

## Regras de leveza

### 1. VPS so protege

A VPS deve:

- validar o dominio publico
- localizar a origem XUI correta
- reescrever playlist, JSON e XML
- encaminhar stream sem expor origem

A VPS nao deve:

- processar video
- recodificar
- armazenar stream
- manter logs brutos com query string
- fazer cache pesado em disco

### 2. PHP fora do peso bruto sempre que possivel

O PHP deve ser usado para:

- `/get.php`
- `player_api.php`
- `xmltv.php`
- reescrita e sanitizacao de respostas textuais

O fluxo binario deve ser o mais enxuto possivel:

- streaming chunkado
- sem buffering desnecessario
- sem montar respostas inteiras em memoria quando nao for texto

### 3. Nginx enxuto

Configuracao recomendada:

- `access_log off`
- `fastcgi_buffering off`
- `fastcgi_read_timeout 3600`
- `client_max_body_size` pequeno
- sem modulos extras desnecessarios

### 4. Logs minimos

Registrar apenas:

- host publico
- path sanitizado
- status
- bytes
- horario

Nao registrar:

- IP/DNS da origem
- `username`
- `password`
- `token`
- query string bruta

### 5. Origem apenas interna

O XUI real deve existir apenas em:

- `origins`
- `settings` quando estritamente necessario
- memoria do processo no momento do request

Nunca em:

- DNS publico
- headers publicos
- redirects publicos
- body final entregue ao cliente

## Apontamento DNS recomendado

### Dominio principal

Exemplo:

- `cdnvoods.vr766.com -> A -> 45.140.192.237`

### Dominios de clientes

Exemplos:

- `meudominio.com -> A -> 45.140.192.237`
- `meudominio.com -> CNAME -> cdnvoods.vr766.com`

Ambos em:

- DNS only
- nuvem cinza

## O que protege de fato

Este modelo protege:

- IP do XUI
- DNS/main do XUI
- credenciais internas da origem
- direct source exposto em playlist, JSON, XML e rotas de stream

Este modelo nao protege:

- IP da VPS

Isso e intencional.

## Como manter leve e rapido

1. manter apenas uma origem XUI ativa por vez quando possivel
2. cadastrar multiplos dominios publicos apontando para a mesma origem
3. evitar token obrigatorio se o fluxo for XUI classico por `username/password`
4. usar reescrita apenas para respostas textuais
5. manter streaming binario em caminho simples
6. nao usar cache de video local
7. nao usar Cloudflare proxied para o stream
8. purgar logs antigos com credenciais

## Validacao operacional

Para considerar o modelo correto:

1. o cliente acessa apenas o dominio publico
2. a playlist devolvida nao mostra IP/DNS do XUI
3. `player_api.php` e `xmltv.php` nao mostram a origem
4. segmentos `.ts` e `.m3u8` passam sem redirect externo visivel
5. a VPS responde rapido sem uso excessivo de CPU/RAM

## Conclusao

Sim, e possivel operar de forma segura, leve e rapida sem nuvem laranja.

O desenho recomendado para este projeto e:

- DNS publico apontando direto para a VPS
- VPS fazendo apenas protecao e proxy
- origem XUI escondida internamente
- Nginx + cURL + PHP enxuto

Esse e o modelo mais coerente com o objetivo atual deste projeto.
