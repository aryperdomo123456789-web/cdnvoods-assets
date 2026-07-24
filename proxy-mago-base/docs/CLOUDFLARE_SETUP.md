# Cloudflare + HTTPS + Firewall — obrigatório em produção

Sem esta configuração o painel expõe o IP real da VPS e o admin trafega em HTTP.
Siga todos os passos antes de considerar o sistema "em produção".

## 1. DNS na Cloudflare

- Zona: `vr766.com`.
- Registro do main:
  - Tipo: `A`
  - Nome: `cdnvoods`
  - Conteúdo: IP público da VPS (ex.: `45.140.192.237`)
  - Proxy: **laranja (Proxied)** — obrigatório para esconder o IP.
- Aliases extras (opcional):
  - Tipo: `CNAME`
  - Nome: alias desejado (ex.: `desprotegida`)
  - Conteúdo: `cdnvoods.vr766.com`
  - Proxy: laranja.

**Nunca** publique um registro DNS público apontando para a origem XUI (`38.190.176.170`).
A origem fica apenas no SQLite / painel.

## 2. SSL/TLS

1. Cloudflare → SSL/TLS → Overview → modo **Full (strict)**.
2. Cloudflare → SSL/TLS → Origin Server → **Create Certificate** (RSA 2048, 15 anos)
   com hosts `cdnvoods.vr766.com` e `*.vr766.com`.
3. Salve os PEMs na VPS:
   ```
   sudo mkdir -p /etc/ssl/cloudflare
   sudo tee /etc/ssl/cloudflare/cdnvoods.pem   >/dev/null   # certificado
   sudo tee /etc/ssl/cloudflare/cdnvoods.key   >/dev/null   # chave privada
   sudo chmod 600 /etc/ssl/cloudflare/cdnvoods.key
   ```
4. No painel, ajuste `NginxGenerator` (ou edite `/etc/nginx/sites-available/proxy-mago`)
   para escutar `443 ssl http2` com esses arquivos e redirecionar `80 → 443`.
5. Cloudflare → SSL/TLS → Edge Certificates → ative
   **Always Use HTTPS** e **Automatic HTTPS Rewrites**.

## 3. Firewall (UFW) na VPS

Somente Cloudflare + SSH:

```bash
sudo apt-get install -y ufw
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp                       # SSH (troque para sua porta se aplicável)

# Faixas oficiais Cloudflare (https://www.cloudflare.com/ips-v4)
for cidr in 173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22 \
            141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20 \
            197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13 \
            104.24.0.0/14 172.64.0.0/13 131.0.72.0/22; do
  sudo ufw allow from $cidr to any port 443 proto tcp
  sudo ufw allow from $cidr to any port 80  proto tcp
done

sudo ufw --force enable
sudo ufw status verbose
```

Depois disso, qualquer request fora de Cloudflare falha no TCP, e o painel/proxy
só aceita o IP real via `CF-Connecting-IP` (o `AccessGuard` valida a faixa antes
de confiar no header).

## 4. Cloudflare — regras recomendadas

- WAF → Managed Rules: ativar OWASP Core Ruleset em nível médio.
- Security → Bots → **Bot Fight Mode** ligado.
- Rules → Configuration Rules: para `/get.php` e `/proxy.php`, definir
  Cache Level = Bypass (streaming não pode ser cacheado no edge).
- Rules → Rate Limiting: 300 req/min por IP em `/get.php` (complemento ao
  rate limit interno do PHP).

## 5. Verificação final

```bash
# HTTPS chega:
curl -sSI https://cdnvoods.vr766.com/health.php | head -5

# HTTP redireciona:
curl -sSI http://cdnvoods.vr766.com/ | grep -i location

# IP público não bate direto (deve dar timeout / conexão recusada):
curl --max-time 5 -sSI http://45.140.192.237/ || echo "OK: origem escondida"
```

Se o último comando retornar HTTP 200, o UFW não está bloqueando: revise os passos 3 e 4.
