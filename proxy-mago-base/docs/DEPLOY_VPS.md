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

### Aplicar pelo painel (uma vez na VPS)

O botão **Aplicar no Nginx** usa um comando fixo e limitado. Instale a regra:

```bash
echo 'www-data ALL=(root) NOPASSWD: /usr/bin/php /opt/proxy-mago/proxy-mago-base/bin/apply-nginx.php' | sudo tee /etc/sudoers.d/proxy-mago-nginx
sudo chmod 440 /etc/sudoers.d/proxy-mago-nginx
sudo visudo -cf /etc/sudoers.d/proxy-mago-nginx
```

O aplicador salva backup em `proxy-mago.conf.backup`, testa a configuração e
só então recarrega o Nginx.

## Health checks

O dashboard verifica SQLite, permissão do storage, socket PHP-FPM e cada origem
ativa. O JSON autenticado fica em `/health.php`.

O access log bruto do Nginx fica desativado porque URLs XUI podem conter
credenciais. O painel registra somente host e caminho sanitizados no SQLite.

## Legado

Somente `proxy-mago-base/` é servido pelo Nginx. Os arquivos antigos na raiz do
repositório permanecem apenas como referência e não devem receber permissão de
escrita do usuário `www-data`.

## Cloudflare / DNS

- `cdnvoods.vr766.com` → A record para o IP da VPS, **com proxy laranja ligado**.
- Aliases extras → CNAME para `cdnvoods.vr766.com`, também proxied.
- Nunca crie DNS público apontando para `38.190.176.170` (origem XUI).
