# Main Flow Example

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


## Goal

Use one official public main hostname and keep the XUI origin IP internal.

## Example Values

- Official main: `cdnvoods.vr766.com`
- VPS public IP: hidden behind Cloudflare
- XUI origin IP: `38.190.176.170`
- Origin port: `80`
- Test playlist URL: `http://38.190.176.170:80/get.php?username=33366&password=33366&type=m3u_plus&output=hls`

## Setup Screen

Fill the setup screen like this:

- `Usuário admin`: your chosen admin login
- `Senha admin`: a strong password
- `Domínio oficial do main`: `cdnvoods.vr766.com`
- `IP ou host da origem XUI`: `38.190.176.170`
- `Porta da origem XUI`: `80`
- `Segredo interno do proxy`: leave blank to auto-generate or provide your own secret

## Cloudflare Rule

If your objective is to hide the VPS IP, the public hostname should remain proxied by Cloudflare.

Recommended rule:

- `cdnvoods.vr766.com` -> Cloudflare proxied
- extra aliases -> CNAME to `cdnvoods.vr766.com`
- do not publish the XUI origin IP in public DNS

## What the Panel Stores

- the public main hostname
- the internal origin IP/host
- the origin port
- the proxy secret

## What It Must Not Expose

- the VPS IP as a public DNS target
- the XUI origin IP in public-facing records
- raw origin URLs in the dashboard or exported config

