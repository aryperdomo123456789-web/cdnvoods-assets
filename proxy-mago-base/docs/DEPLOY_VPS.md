# Deploy na VPS (Ubuntu 22.04)

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


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

## DNS publico

- `cdnvoods.vr766.com` -> A record para o IP da VPS `45.140.192.237`
- Aliases extras -> A para a VPS ou CNAME para `cdnvoods.vr766.com`
- Operacao atual: **DNS only / nuvem cinza**
- Nunca crie DNS público apontando para `38.190.176.170` ou qualquer DNS do XUI

## Modelo operacional

- A VPS protege apenas a origem XUI
- O IP da VPS nao e escondido
- O stream deve passar da forma mais enxuta possivel
- A VPS nao deve processar video, apenas proteger e encaminhar

Consulte tambem:

- `docs/ARQUITETURA_LEVE_SEM_NUVEM_LARANJA.md`

## Fechamento (31/07/2026)

Deploy + validação completa na VPS:

```bash
cd /opt/proxy-mago/proxy-mago-base
sudo bash bin/deploy.sh
bash bin/smoke-test.sh voods.suafontee.com <usuario> <senha>
```

Resultados E2E, números de carga (5/10/20/50 usuários) e checklist final em
`docs/FECHAMENTO_2026-07-31.md`.
