# Deploy na VPS (Ubuntu 22.04)

## Pull do GitHub

```bash
cd /opt/proxy-mago
git pull origin main
```

## Permissões (uma vez)

```bash
sudo chown -R www-data:www-data /opt/proxy-mago/proxy-mago-base/storage
sudo find /opt/proxy-mago/proxy-mago-base/storage -type d -exec chmod 775 {} \;
```

## Nginx

O snippet gerado pelo painel deve ser copiado (ou aplicado via
`export-config.php` → salvar em `/etc/nginx/sites-available/proxy-mago.conf`).

```bash
sudo ln -sf /etc/nginx/sites-available/proxy-mago.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## Cloudflare / DNS

- `cdnvoods.vr766.com` → A record para o IP da VPS, **com proxy laranja ligado**.
- Aliases extras → CNAME para `cdnvoods.vr766.com`, também proxied.
- Nunca crie DNS público apontando para `38.190.176.170` (origem XUI).
