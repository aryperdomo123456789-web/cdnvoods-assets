# Código de App — 1 código, vários XUIs

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


## O problema
O app tem um DNS fixo compilado dentro dele (`assistservpd.phpd77.com`). Esse DNS
só sabe falar com UM servidor. Se os assinantes estão espalhados em vários XUIs,
o mesmo código de app não atende todo mundo.

## A solução
A CDN vira dona desse DNS. Quando o assinante entra, a CDN descobre em qual XUI
aquele `username` existe, **gruda** o usuário naquele XUI e nunca mais troca.
É a grudação que impede embaralhar usuário, playlist e EPG.

## Fluxo

```text
App (DNS fixo) -> CDN -> cache grudado? sim -> XUI dono (O(1), zero probe)
                          |
                          nao -> descoberta com lock -> gruda -> XUI dono
```

- Descoberta roda **só** em `get.php`, `player_api.php` e `.m3u8`.
- Segmento `.ts` nunca varre nada: usa o cache grudado. Peso zero na CDN.
- Lock por username: 10 players do mesmo assinante = 1 varredura só.
- Cache negativo (5 min) para usuário que não existe em lugar nenhum.
- XUI fora do ar **não** vira cache negativo — o assinante volta assim que o
  servidor volta, sem esperar TTL.
- Rota que começa a dar 5xx é desgrudada sozinha e redescoberta.
- Se ninguém reconhecer o usuário e o fallback estiver ligado, a origem padrão
  atende: a CDN nunca para.

## Como usar no painel
1. Aba **Código de App**.
2. Cole os DNS que estão dentro do app (um por linha) e ative. Cada DNS é
   registrado automaticamente como domínio protegido.
3. Cadastre cada XUI (IP/DNS + porta). Porta `0` autodescobre as portas padrão;
   porta declarada = zero varredura, sempre prefira declarar.
4. Preencha `extra_hosts` com a CDN interna do XUI para ela não vazar na playlist.
5. Botão **Testar** confirma que o XUI responde.
6. **Desgrudar** força redescoberta — use ao migrar um assinante de servidor.

## DNS

```text
CNAME  assistservpd.phpd77.com  ->  cdnvoods.vr766.com
   ou
A      assistservpd.phpd77.com  ->  45.140.192.237
```

Sempre para a CDN. Nunca para o IP ou DNS de um XUI.

## Validado localmente
- 2 XUIs distintos com usuários diferentes: 12 players concorrentes, 0 embaralhamento.
- Zero vazamento de IP, porta ou CDN interna nas respostas reescritas.
- Queda de XUI: desgruda, redescobre e volta sozinho quando o servidor retorna.
- Recadastro do mesmo destino não duplica servidor nem apaga o que já estava afinado.