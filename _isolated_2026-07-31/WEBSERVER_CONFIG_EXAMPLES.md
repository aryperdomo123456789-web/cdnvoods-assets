# 🌐 MAGO GATEWAY V3 - Configurações de Servidor Web

Este arquivo contém exemplos de configuração para Apache e Nginx para maximizar a performance do **Modo Stealth**.

---

## 📋 Índice

- [Apache com mod_proxy](#apache-com-mod_proxy)
- [Nginx com proxy_pass](#nginx-com-proxy_pass)
- [Otimizações de Performance](#otimizações-de-performance)
- [Troubleshooting](#troubleshooting)

---

## 🔴 Apache com mod_proxy

### Instalação dos Módulos Necessários

```bash
sudo a2enmod proxy
sudo a2enmod proxy_http
sudo a2enmod headers
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### Configuração do VirtualHost

Edite o arquivo de configuração do seu site:

```bash
sudo nano /etc/apache2/sites-available/seu-dominio.conf
```

**Configuração Completa:**

```apache
<VirtualHost *:80>
    ServerName seu-dominio.com
    ServerAlias *.seu-dominio.com
    DocumentRoot /www/wwwroot/seu-dominio.com

    # Logs
    ErrorLog ${APACHE_LOG_DIR}/mago_gateway_error.log
    CustomLog ${APACHE_LOG_DIR}/mago_gateway_access.log combined

    <Directory /www/wwwroot/seu-dominio.com>
        AllowOverride All
        Require all granted
    </Directory>

    # ═══════════════════════════════════════════════════════════════
    # MODO STEALTH: Proxy Reverso para VOD
    # ═══════════════════════════════════════════════════════════════
    # Esta configuração intercepta requisições de vídeo e faz proxy
    # direto pelo Apache, ocultando a fonte original
    # ═══════════════════════════════════════════════════════════════

    # Proxy para arquivos de vídeo (.mp4, .mkv, .ts, etc)
    # Ajuste o IP e porta conforme seu servidor de origem
    <LocationMatch "\.(mp4|mkv|avi|mov|m4v|ts|m3u8|mpd)$">
        # Desabilita PHP (vídeo será servido via proxy)
        SetHandler None

        # Headers de segurança
        RequestHeader unset X-Forwarded-For
        RequestHeader unset X-Real-IP

        # Remove headers que revelam a fonte
        Header unset Server
        Header unset X-Powered-By
    </LocationMatch>

    # Habilita mod_rewrite (necessário para o router.php)
    RewriteEngine On

    # Previne acesso direto a arquivos sensíveis
    RewriteRule ^config\.php$ - [F,L]
    RewriteRule ^mago-manager/(auth|cloudflare|security)\.php$ - [F,L]

    # Redireciona tudo que não for arquivo real para o router.php
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ router.php [L,QSA]

    # ═══════════════════════════════════════════════════════════════
    # OTIMIZAÇÕES DE PERFORMANCE
    # ═══════════════════════════════════════════════════════════════

    # Buffer maior para streaming
    ProxyIOBufferSize 128000

    # Timeouts otimizados
    ProxyTimeout 300
    Timeout 300

    # Keep-Alive para melhor performance
    KeepAlive On
    MaxKeepAliveRequests 100
    KeepAliveTimeout 5

    # Compressão (apenas para M3U, não para vídeos)
    <IfModule mod_deflate.c>
        AddOutputFilterByType DEFLATE application/vnd.apple.mpegurl
        AddOutputFilterByType DEFLATE application/x-mpegURL
    </IfModule>

    # Cache headers para segmentos TS
    <FilesMatch "\.ts$">
        Header set Cache-Control "public, max-age=3600"
    </FilesMatch>

    # No-cache para playlists
    <FilesMatch "\.(m3u|m3u8)$">
        Header set Cache-Control "no-cache, no-store, must-revalidate"
        Header set Pragma "no-cache"
        Header set Expires 0
    </FilesMatch>

</VirtualHost>

# Configuração SSL (recomendado para produção)
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName seu-dominio.com
    ServerAlias *.seu-dominio.com
    DocumentRoot /www/wwwroot/seu-dominio.com

    # SSL Certificates (Let's Encrypt)
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/seu-dominio.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/seu-dominio.com/privkey.pem

    # (Repita as configurações acima aqui)
</VirtualHost>
</IfModule>
```

### Testar Configuração

```bash
# Testa sintaxe
sudo apache2ctl configtest

# Se OK, reinicia
sudo systemctl restart apache2
```

---

## 🟢 Nginx com proxy_pass

### Configuração do Server Block

Edite o arquivo de configuração:

```bash
sudo nano /etc/nginx/sites-available/seu-dominio.conf
```

**Configuração Completa:**

```nginx
# Cache Zone para performance
proxy_cache_path /var/cache/nginx/mago levels=1:2 keys_zone=mago_cache:10m max_size=1g inactive=60m use_temp_path=off;

# Upstream (lista de servidores de origem)
# Adicione seus servidores XUI aqui
upstream xui_servers {
    # Exemplo: ip_hash para sessões persistentes
    ip_hash;

    # Adicione seus servidores
    # server 198.13.16.162:80 max_fails=3 fail_timeout=30s;
    # server 38.147.106.155:80 max_fails=3 fail_timeout=30s;
}

server {
    listen 80;
    listen [::]:80;
    server_name seu-dominio.com *.seu-dominio.com;

    # Redireciona HTTP para HTTPS (recomendado)
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name seu-dominio.com *.seu-dominio.com;

    root /www/wwwroot/seu-dominio.com;
    index index.php index.html;

    # SSL Certificates (Let's Encrypt)
    ssl_certificate /etc/letsencrypt/live/seu-dominio.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/seu-dominio.com/privkey.pem;

    # SSL Optimization
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers on;
    ssl_ciphers HIGH:!aNULL:!MD5;

    # Logs
    access_log /var/log/nginx/mago_gateway_access.log;
    error_log /var/log/nginx/mago_gateway_error.log;

    # ═══════════════════════════════════════════════════════════════
    # MODO STEALTH: Proxy Reverso para VOD
    # ═══════════════════════════════════════════════════════════════

    # Proxy reverso para arquivos de vídeo
    # IMPORTANTE: Ajustar conforme necessário para cada servidor
    location ~* \.(mp4|mkv|avi|mov|m4v|ts|m3u8|mpd)$ {
        # Headers de proxy
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

        # Remove headers que revelam a fonte
        proxy_hide_header X-Powered-By;
        proxy_hide_header Server;

        # Timeouts otimizados para streaming
        proxy_connect_timeout 10s;
        proxy_send_timeout 300s;
        proxy_read_timeout 300s;

        # Buffering otimizado
        proxy_buffering on;
        proxy_buffer_size 128k;
        proxy_buffers 8 128k;
        proxy_busy_buffers_size 256k;

        # HTTP/1.1 com Keep-Alive
        proxy_http_version 1.1;
        proxy_set_header Connection "";

        # Passa para o router.php processar
        # O router.php fará o proxy real
        try_files $uri @php;
    }

    # ═══════════════════════════════════════════════════════════════
    # PROCESSAMENTO PHP
    # ═══════════════════════════════════════════════════════════════

    location @php {
        try_files $uri /router.php?$query_string;
    }

    location / {
        try_files $uri $uri/ /router.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;

        # Timeouts para streaming
        fastcgi_read_timeout 300;
        fastcgi_send_timeout 300;

        # Buffer otimizado
        fastcgi_buffer_size 128k;
        fastcgi_buffers 8 128k;
    }

    # ═══════════════════════════════════════════════════════════════
    # SEGURANÇA
    # ═══════════════════════════════════════════════════════════════

    # Bloqueia acesso a arquivos sensíveis
    location ~ /(config\.php|mago-manager/(auth|cloudflare|security)\.php) {
        deny all;
        return 403;
    }

    location ~ /\.ht {
        deny all;
    }

    location ~ /\.git {
        deny all;
    }

    # ═══════════════════════════════════════════════════════════════
    # CACHE E OTIMIZAÇÕES
    # ═══════════════════════════════════════════════════════════════

    # Cache para segmentos TS
    location ~ \.ts$ {
        add_header Cache-Control "public, max-age=3600";
        expires 1h;
    }

    # No-cache para playlists
    location ~ \.(m3u|m3u8)$ {
        add_header Cache-Control "no-cache, no-store, must-revalidate";
        add_header Pragma "no-cache";
        add_header Expires 0;
    }

    # Gzip compression (apenas texto)
    gzip on;
    gzip_vary on;
    gzip_types application/vnd.apple.mpegurl application/x-mpegURL;
    gzip_disable "msie6";

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
}
```

### Testar Configuração

```bash
# Testa sintaxe
sudo nginx -t

# Se OK, reinicia
sudo systemctl restart nginx
```

---

## ⚡ Otimizações de Performance

### PHP-FPM (para ambos Apache e Nginx)

Edite `/etc/php/8.2/fpm/pool.d/www.conf`:

```ini
; Processos
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35

; Otimizações para streaming
request_terminate_timeout = 300
max_execution_time = 300
memory_limit = 256M

; Buffer
output_buffering = Off
```

Reinicie o PHP-FPM:

```bash
sudo systemctl restart php8.2-fpm
```

### Kernel (Linux)

Para alta concorrência de streaming:

```bash
sudo nano /etc/sysctl.conf
```

Adicione:

```
net.core.somaxconn = 65535
net.ipv4.tcp_max_syn_backlog = 65535
net.ipv4.ip_local_port_range = 1024 65535
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_fin_timeout = 30
```

Aplique:

```bash
sudo sysctl -p
```

---

## 🔧 Troubleshooting

### Apache: Erro "Proxy Error"

**Sintoma:** Erro 502 ao acessar vídeos

**Solução:**

```bash
# Verifica se mod_proxy está ativado
apache2ctl -M | grep proxy

# Aumenta timeout
sudo nano /etc/apache2/apache2.conf

# Adicione:
ProxyTimeout 600
Timeout 600

sudo systemctl restart apache2
```

### Nginx: Erro 502 Bad Gateway

**Sintoma:** Erro 502 ao acessar vídeos

**Solução:**

```bash
# Aumenta timeouts no PHP-FPM
sudo nano /etc/php/8.2/fpm/pool.d/www.conf

# Altere:
request_terminate_timeout = 600

# Aumenta buffer no Nginx
sudo nano /etc/nginx/nginx.conf

# Adicione no bloco http:
proxy_buffer_size 256k;
proxy_buffers 4 256k;
proxy_busy_buffers_size 512k;

sudo systemctl restart php8.2-fpm nginx
```

### Vídeo não carrega (timeout)

**Sintoma:** Vídeo para de carregar após alguns segundos

**Solução:**

Verifique os logs:

```bash
# Apache
sudo tail -f /var/log/apache2/error.log

# Nginx
sudo tail -f /var/log/nginx/error.log

# MAGO Gateway
sudo tail -f /www/wwwroot/seu-dominio.com/proxy.log
sudo tail -f /www/wwwroot/seu-dominio.com/security.log
```

Aumente os timeouts conforme necessário.

---

## 📊 Monitoramento

### Verificar Performance

```bash
# Apache
apache2ctl status

# Nginx
curl http://localhost/nginx_status

# PHP-FPM
systemctl status php8.2-fpm
```

### Logs em Tempo Real

```bash
# Monitora todos os logs
sudo tail -f /var/log/apache2/mago_gateway_*.log \
             /var/log/nginx/mago_gateway_*.log \
             /www/wwwroot/seu-dominio.com/*.log
```

---

## 🎯 Checklist Pós-Configuração

- [ ] Módulos Apache/Nginx instalados e ativos
- [ ] Timeouts configurados (mínimo 300s)
- [ ] Buffers otimizados para streaming
- [ ] SSL configurado (Let's Encrypt)
- [ ] Logs funcionando corretamente
- [ ] Testado com arquivo de vídeo real
- [ ] Verificado que IP original não vaza nos headers

---

**🚀 MAGO GATEWAY V3 - High Performance Stealth Proxy**
