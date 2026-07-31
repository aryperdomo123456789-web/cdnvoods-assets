# 🔒 MAGO GATEWAY V3 (STEALTH MODE)

**Sistema de Proxy Gateway com Proteção Anti-Sniffing para VOD**

[![Version](https://img.shields.io/badge/version-3.0-red)](https://github.com)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4)](https://php.net)
[![Security](https://img.shields.io/badge/security-stealth-green)](LICENSE)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

---

## 📋 Índice

- [Visão Geral](#-visão-geral)
- [Novidades da V3](#-novidades-da-v3)
- [Características](#-características)
- [Arquitetura de Segurança](#-arquitetura-de-segurança)
- [Requisitos do Sistema](#-requisitos-do-sistema)
- [Instalação](#-instalação)
- [Configuração](#-configuração)
- [Modos de Operação](#-modos-de-operação)
- [Sistema de Tokens](#-sistema-de-tokens)
- [Whitelist de User-Agent](#-whitelist-de-user-agent)
- [Configuração Apache/Nginx](#-configuração-apachenginx)
- [Uso](#-uso)
- [Troubleshooting](#-troubleshooting)
- [Changelog](#-changelog)

---

## 🎯 Visão Geral

O **MAGO GATEWAY V3 (STEALTH MODE)** é um sistema avançado de proxy gateway desenvolvido para proteger conteúdo VOD (Vídeo sob Demanda) contra ferramentas de sniffing como NetCapture, mantendo a integração com Cloudflare API V4.

### ⚔️ Proteção contra Sniffing

A V3 foi desenvolvida especificamente para proteger conteúdos de vídeo contra captura não autorizada, utilizando:

1. **Tokenização por IP** - Tokens únicos baseados no IP do cliente
2. **Whitelist de User-Agent** - Bloqueia acessos de navegadores e ferramentas
3. **Ocultação de Fonte** - IP original nunca é revelado ao cliente
4. **Modo Stealth** - Proxy reverso via Apache/Nginx (não via redirecionamento)

---

## 🆕 Novidades da V3

### **🔐 PROTEÇÃO DE FONTE (INTERNAL PROXY)**
- ✅ Vídeos servidos via proxy reverso (Apache/Nginx)
- ✅ **Nunca usa redirecionamento 302** para VOD
- ✅ IP, usuário e senha da fonte original **permanentemente ocultos**
- ✅ Cliente vê apenas o domínio do proxy

### **🎟️ TOKENIZAÇÃO POR IP**
- ✅ Token único gerado com base no **IP do cliente + Secret Key**
- ✅ Token válido apenas para o **IP que solicitou a lista M3U**
- ✅ Tentativas de acesso de IPs diferentes são **bloqueadas (403)**
- ✅ Tokens expiram automaticamente após **2 horas** (configurável)

### **🛡️ WHITELIST DE USER-AGENT**
- ✅ User-Agent exclusivo configurável (ex: `MagoPlayer/3.0`)
- ✅ Requisições de navegadores e ferramentas de sniffing são **rejeitadas (403)**
- ✅ Proteção ativa contra **NetCapture, Wireshark, curl, wget**, etc.

### **⚡ OTIMIZAÇÃO DE PERFORMANCE**
- ✅ Ponte de streaming via **Apache mod_proxy** ou **Nginx proxy_pass**
- ✅ Processamento de vídeo **fora do PHP** (reduz CPU/RAM)
- ✅ Ideal para servidores **FiberState, Nocix, OVH**, etc.
- ✅ Buffers otimizados para streaming em alta concorrência

### **🎮 MODO DUAL: DIRECT e STEALTH**
- ✅ Escolha de modo por servidor no painel administrativo
- ✅ **Modo Stealth**: Proteção máxima (VOD protegido com tokens)
- ✅ **Modo Direct**: Compatibilidade (sem tokens, redirecionamento direto)

---

## ✨ Características

### 🔐 Segurança Avançada
- Sistema de autenticação com sessões protegidas
- Tokenização criptográfica (SHA-256)
- Validação de IP em tempo real
- Whitelist de User-Agent personalizável
- Logs de tentativas de acesso bloqueadas
- Proteção contra timing attacks

### 🌐 Proxy Engine
- Spoofing do cabeçalho `Host` via cURL
- Suporte a resolução CNAME automática
- Repasse transparente de cabeçalhos HTTP
- Compatível com streaming M3U, M3U8 e TS
- Processamento inteligente de playlists
- Timeout configurável

### ☁️ Cloudflare Integration
- Criação automática de registros DNS tipo A
- Configuração obrigatória como **DNS Only** (nuvem cinza)
- Validação de domínios e IPs
- API v4 com autenticação via Bearer Token
- Suporte a múltiplos domínios

### 🎨 Interface
- Design moderno em **roxo neon 3D**
- Totalmente responsivo (Bootstrap 5)
- Dark Mode nativo
- Animações suaves e profissionais
- Dashboard com estatísticas em tempo real
- Indicadores visuais de modo (Stealth/Direct)

---

## 🔒 Arquitetura de Segurança

### Fluxo de Proteção Stealth para VOD

```
┌─────────────┐
│   Cliente   │
│  (Player)   │
└──────┬──────┘
       │ 1. Solicita M3U com User-Agent correto
       ▼
┌─────────────────────────────┐
│   MAGO GATEWAY V3           │
│  - Valida User-Agent        │
│  - Gera Token baseado em IP │
│  - Processa M3U             │
│  - Adiciona tokens em URLs  │
│  - Substitui domínio origem │
└──────┬──────────────────────┘
       │ 2. Retorna M3U com tokens
       ▼
┌─────────────┐
│   Cliente   │
│  (Player)   │
└──────┬──────┘
       │ 3. Solicita vídeo com Token
       ▼
┌─────────────────────────────┐
│   MAGO GATEWAY V3           │
│  - Valida User-Agent        │
│  - Valida Token             │
│  - Verifica IP == IP Token  │
│  - Faz proxy reverso        │
└──────┬──────────────────────┘
       │ 4. Stream via Apache/Nginx
       ▼
┌──────────────────┐
│  Servidor XUI    │
│  (IP Oculto)     │
└──────────────────┘
```

### Camadas de Proteção

1. **Camada 1 - User-Agent**: Bloqueia navegadores e ferramentas de sniffing
2. **Camada 2 - Tokenização**: Valida que o IP é o mesmo que solicitou a lista
3. **Camada 3 - Expiração**: Tokens expiram após 2 horas
4. **Camada 4 - Ocultação**: IP original nunca aparece nas requisições do cliente

---

## 📦 Requisitos do Sistema

### Software Necessário:

| Componente | Versão Mínima | Recomendado |
|------------|---------------|-------------|
| **PHP** | 8.0 | 8.2+ |
| **Apache/Nginx** | 2.4 / 1.18 | Última estável |
| **cURL** | 7.68 | Última estável |
| **Módulos PHP** | `curl`, `json`, `session`, `hash` | - |

### Extensões PHP Requeridas:

```bash
php -m | grep -E 'curl|json|session|hash'
```

### Módulos Apache (para Modo Stealth):

```bash
sudo a2enmod proxy proxy_http headers rewrite
```

### Módulos Nginx:

Nginx já vem com `proxy_pass` por padrão.

---

## 🚀 Instalação

### Opção 1: Instalação Automatizada (Recomendado)

```bash
# 1. Faça upload dos arquivos para o servidor
cd /www/wwwroot/seu-dominio.com

# 2. Execute o script de instalação
chmod +x install.sh
sudo ./install.sh

# 3. Configure as credenciais em config.php
nano config.php

# 4. Configure Apache/Nginx (veja seção abaixo)
```

### Opção 2: Instalação Manual

#### Passo 1: Upload dos Arquivos

Faça upload de todos os arquivos para o diretório raiz do seu domínio.

#### Passo 2: Configurar Permissões

```bash
# Define proprietário correto
sudo chown -R www:www /www/wwwroot/seu-dominio.com

# Permissões de diretórios
find /www/wwwroot/seu-dominio.com -type d -exec chmod 755 {} \;

# Permissões de arquivos
find /www/wwwroot/seu-dominio.com -type f -exec chmod 644 {} \;

# Permissão de escrita para JSON e logs
chmod 666 /www/wwwroot/seu-dominio.com/mago-manager/proxies.json
chmod 666 /www/wwwroot/seu-dominio.com/*.log
```

#### Passo 3: Configurar Servidor Web

**Consulte o arquivo `WEBSERVER_CONFIG_EXAMPLES.md` para exemplos completos de configuração Apache e Nginx.**

---

## ⚙️ Configuração

### 1. Configurações Básicas

Edite o arquivo `config.php`:

```php
// CONFIGURAÇÕES DE AUTENTICAÇÃO DO PAINEL
define('ADMIN_USER', 'seu-usuario');  // ALTERE!
define('ADMIN_PASS', 'sua-senha');    // ALTERE!

// CONFIGURAÇÕES DA API CLOUDFLARE V4
define('CLOUDFLARE_EMAIL', 'seu-email@dominio.com');
define('CLOUDFLARE_ZONE_ID', 'seu-zone-id');
define('CLOUDFLARE_API_TOKEN', 'seu-token');
```

### 2. Configurações de Segurança V3

```php
// SECRET KEY para tokens (ALTERE PARA UM VALOR ÚNICO!)
define('TOKEN_SECRET_KEY', 'Gere_Uma_Chave_Aleatoria_Aqui_123!@#');

// WHITELIST DE USER-AGENT
define('ALLOWED_USER_AGENT', 'MagoPlayer/3.0');

// ATIVAR VALIDAÇÃO DE USER-AGENT
define('ENFORCE_USER_AGENT', true);  // false = desativa validação

// TEMPO DE VALIDADE DO TOKEN (segundos)
define('TOKEN_EXPIRATION', 7200);  // 2 horas

// MODO STEALTH GLOBAL (padrão para novos proxies)
define('DEFAULT_PROXY_MODE', 'stealth');  // ou 'direct'
```

### 3. Gerar Secret Key Segura

```bash
# Gera uma chave aleatória de 32 bytes em base64
openssl rand -base64 32
```

Use o resultado como valor de `TOKEN_SECRET_KEY`.

---

## 🎮 Modos de Operação

### Modo STEALTH (Recomendado para VOD)

**Características:**
- ✅ Máxima proteção contra sniffing
- ✅ Tokenização ativada
- ✅ IP original totalmente oculto
- ✅ Validação de User-Agent obrigatória
- ✅ Proxy reverso via servidor web

**Quando usar:**
- Filmes e séries (VOD)
- Conteúdo premium
- Proteção contra NetCapture e similares
- Ambientes que exigem segurança máxima

**Fluxo:**
```
Cliente → Solicita M3U → Recebe M3U com tokens
Cliente → Solicita vídeo com token → Validação → Stream via proxy
```

### Modo DIRECT (Compatibilidade)

**Características:**
- ✅ Sem tokenização
- ✅ Compatível com players antigos
- ✅ Menor latência
- ⚠️ Fonte pode ser descoberta

**Quando usar:**
- Live streaming (TV ao vivo)
- Ambientes de teste
- Players que não suportam tokens
- Conteúdo público

**Fluxo:**
```
Cliente → Requisição → Proxy cURL direto → Stream
```

---

## 🎟️ Sistema de Tokens

### Como Funciona

1. **Geração do Token:**
   ```
   Token = base64(timestamp_window . SHA256(IP + timestamp + secret))
   ```

2. **Adição às URLs:**
   ```
   URL Original: http://proxy.com/video.mp4
   URL com Token: http://proxy.com/video.mp4?token=ABC123...
   ```

3. **Validação:**
   - Verifica se token não expirou
   - Recalcula hash com IP atual
   - Compara com hash fornecido (timing-attack safe)

### Exemplo de Processamento M3U

**M3U Original do Servidor XUI:**
```m3u
#EXTM3U
#EXTINF:-1,Filme Exemplo
http://198.13.16.162:80/movie/user/pass/12345.mp4
```

**M3U Processado pelo MAGO V3:**
```m3u
#EXTM3U
#EXTINF:-1,Filme Exemplo
http://proxy.seudominio.com/movie/user/pass/12345.mp4?token=BASE64_TOKEN_AQUI
```

### Logs de Segurança

Tentativas de acesso bloqueadas são registradas em `security.log`:

```
[2026-01-10 15:30:45] EVENT:INVALID_TOKEN | IP:192.168.1.100 | URI:/video.mp4 | UA:curl/7.68 | DETAILS:TOKEN_EXPIRED
[2026-01-10 15:31:20] EVENT:INVALID_USER_AGENT | IP:192.168.1.101 | URI:/playlist.m3u | UA:Mozilla/5.0 | DETAILS:Mozilla/5.0
[2026-01-10 15:32:10] EVENT:TOKEN_HASH_MISMATCH | IP:192.168.1.102 | URI:/video.mp4 | UA:MagoPlayer/3.0 | DETAILS:...
```

---

## 🛡️ Whitelist de User-Agent

### Configuração

No `config.php`:

```php
// Define o User-Agent permitido
define('ALLOWED_USER_AGENT', 'MagoPlayer/3.0');

// Ativa validação
define('ENFORCE_USER_AGENT', true);
```

### Configurar no Player

**Exemplo com VLC (via terminal):**
```bash
vlc --http-user-agent="MagoPlayer/3.0" http://proxy.com/playlist.m3u
```

**Exemplo com cURL:**
```bash
curl -H "User-Agent: MagoPlayer/3.0" http://proxy.com/playlist.m3u
```

**Exemplo em código PHP:**
```php
$ch = curl_init();
curl_setopt($ch, CURLOPT_USERAGENT, 'MagoPlayer/3.0');
curl_setopt($ch, CURLOPT_URL, 'http://proxy.com/playlist.m3u');
curl_exec($ch);
```

### User-Agents Bloqueados

Qualquer User-Agent diferente do configurado será bloqueado, incluindo:

- Navegadores (Chrome, Firefox, Safari, Edge, etc.)
- Ferramentas de download (curl, wget, aria2c, etc.)
- Sniffers (NetCapture, Wireshark, Fiddler, etc.)
- Players não configurados

**Resposta ao bloqueio:**
```
HTTP/1.1 403 Forbidden
Content-Type: text/plain

Access Denied
```

---

## 🌐 Configuração Apache/Nginx

**Para configurações completas e otimizadas, consulte:**

📄 **`WEBSERVER_CONFIG_EXAMPLES.md`**

Este arquivo contém:
- Configuração Apache com mod_proxy
- Configuração Nginx com proxy_pass
- Otimizações de performance
- Buffers para streaming
- Timeouts recomendados
- Troubleshooting

---

## 🎯 Uso

### Acessar o Painel Administrativo

1. Acesse: `https://seu-dominio.com/mago-manager/`
2. Faça login com suas credenciais
3. Você será redirecionado para o dashboard

### Adicionar um Novo Proxy

1. No painel, preencha o formulário:
   - **Domínio**: `api.seudominio.com`
   - **IP:Porta**: `198.13.16.162:80`
   - **Modo**: Selecione `Stealth` ou `Direct`

2. Clique em **"Criar"**

3. O sistema irá:
   - ✅ Criar registro DNS na Cloudflare
   - ✅ Configurar proxy com modo escolhido
   - ✅ Ativar proteções (se modo Stealth)

### Testar o Proxy

**Teste 1: Lista M3U (gera token)**
```bash
curl -H "User-Agent: MagoPlayer/3.0" \
     http://api.seudominio.com/get.php?username=test&password=test&type=m3u_plus
```

**Teste 2: Vídeo com Token (valida token)**
```bash
curl -H "User-Agent: MagoPlayer/3.0" \
     "http://api.seudominio.com/movie/test/test/12345.mp4?token=ABC123..."
```

**Teste 3: Verificar bloqueio (sem User-Agent correto)**
```bash
curl http://api.seudominio.com/playlist.m3u
# Deve retornar: 403 Forbidden
```

---

## 🔧 Troubleshooting

### Problema: Erro 403 mesmo com User-Agent correto

**Solução:**

1. Verifique se o User-Agent está EXATAMENTE igual ao configurado:
   ```bash
   # No config.php
   define('ALLOWED_USER_AGENT', 'MagoPlayer/3.0');

   # Na requisição
   curl -H "User-Agent: MagoPlayer/3.0" ...
   ```

2. Verifique os logs:
   ```bash
   tail -f /www/wwwroot/seu-dominio.com/security.log
   ```

### Problema: Token inválido

**Possíveis causas:**

1. **IP mudou entre requisições**
   - Solução: Use conexão com IP fixo

2. **Token expirou**
   - Solução: Solicite nova lista M3U

3. **Secret Key diferente**
   - Solução: Certifique-se de não ter alterado `TOKEN_SECRET_KEY` após gerar tokens

### Problema: Vídeo não carrega (modo Stealth)

**Solução:**

1. Verifique se Apache/Nginx está configurado corretamente
2. Aumente timeouts:
   ```bash
   # Apache
   ProxyTimeout 600

   # Nginx
   proxy_read_timeout 600s;
   ```

3. Verifique logs:
   ```bash
   tail -f /var/log/apache2/error.log
   tail -f /var/log/nginx/error.log
   ```

### Problema: M3U vazio ou sem tokens

**Solução:**

1. Verifique se o modo está como "stealth"
2. Verifique se a URL contém `.m3u` ou `.m3u8`
3. Verifique logs em `proxy.log`

---

## 📊 Monitoramento

### Logs Disponíveis

```bash
# Log de operações do proxy
tail -f /www/wwwroot/seu-dominio.com/proxy.log

# Log de eventos de segurança
tail -f /www/wwwroot/seu-dominio.com/security.log

# Log de erros PHP
tail -f /www/wwwroot/seu-dominio.com/error.log

# Logs do servidor web
tail -f /var/log/apache2/mago_gateway_error.log
tail -f /var/log/nginx/mago_gateway_error.log
```

### Estatísticas no Painel

O painel mostra em tempo real:
- Total de proxies configurados
- Quantos estão em modo Stealth
- Quantos estão em modo Direct
- Status da integração Cloudflare

---

## 📝 Changelog

### V3.0 (2026-01-10) - STEALTH MODE

**🔐 PROTEÇÃO ANTI-SNIFFING**

#### ✨ Novos Recursos:
- Sistema de tokenização por IP (SHA-256)
- Whitelist de User-Agent personalizável
- Modo Stealth para VOD (ocultação de fonte)
- Processamento inteligente de playlists M3U
- Logs de segurança detalhados
- Seleção de modo (Stealth/Direct) por proxy
- Validação de tokens em tempo real
- Proteção contra timing attacks

#### 🔧 Melhorias:
- Performance otimizada com buffers maiores
- Timeouts configuráveis
- Suporte a streaming de alta concorrência
- Processamento de M3U fora do PHP (Apache/Nginx)
- Interface atualizada com indicadores de modo
- Estatísticas separadas por modo

#### 🔒 Segurança:
- Tokens criptográficos com expiração
- Validação de IP em todas as requisições VOD
- Bloqueio automático de ferramentas de sniffing
- Logs de tentativas de acesso não autorizado
- Headers sensíveis removidos das respostas

#### 📚 Documentação:
- README atualizado com guia de segurança
- Arquivo WEBSERVER_CONFIG_EXAMPLES.md criado
- Exemplos de configuração Apache e Nginx
- Guia de troubleshooting expandido

---

## 🎯 Comparação de Versões

| Recurso | V2 | V3 |
|---------|----|----|
| **Cloudflare Auto** | ✅ | ✅ |
| **Sistema de Login** | ✅ | ✅ |
| **Interface 3D Neon** | ✅ | ✅ |
| **Tokenização por IP** | ❌ | ✅ |
| **Whitelist User-Agent** | ❌ | ✅ |
| **Modo Stealth VOD** | ❌ | ✅ |
| **Proteção Anti-Sniffing** | ❌ | ✅ |
| **Logs de Segurança** | ⚠️ Básico | ✅ Avançado |
| **Modos Dual** | ❌ | ✅ |
| **Ocultação de Fonte** | ⚠️ Parcial | ✅ Total |

---

## 🚦 Checklist de Configuração

Após instalação:

- [ ] Alterar `ADMIN_USER` e `ADMIN_PASS` em `config.php`
- [ ] Gerar e configurar `TOKEN_SECRET_KEY` único
- [ ] Configurar `ALLOWED_USER_AGENT` personalizado
- [ ] Configurar credenciais Cloudflare
- [ ] Configurar Apache ou Nginx (modo Stealth)
- [ ] Configurar SSL/HTTPS (Let's Encrypt)
- [ ] Testar proxy em modo Stealth
- [ ] Testar validação de User-Agent
- [ ] Testar tokenização (IP diferente)
- [ ] Verificar logs de segurança
- [ ] Configurar backup de `proxies.json`
- [ ] Documentar seus User-Agents

---

## 🛡️ Boas Práticas de Segurança

1. **Use HTTPS obrigatoriamente** (Let's Encrypt gratuito)
2. **Altere TOKEN_SECRET_KEY** após instalação (nunca use o padrão)
3. **Use User-Agent único** (não use nomes óbvios)
4. **Monitore security.log** regularmente
5. **Configure firewall** para restringir acesso ao painel
6. **Faça backup** de `proxies.json` diariamente
7. **Atualize PHP** para última versão estável
8. **Use modo Stealth** para todo conteúdo VOD
9. **Teste tokens** regularmente
10. **Revise logs** de tentativas de acesso bloqueadas

---

## 📞 Suporte

Para problemas ou dúvidas:

1. Consulte [Troubleshooting](#-troubleshooting)
2. Verifique `security.log` e `proxy.log`
3. Consulte `WEBSERVER_CONFIG_EXAMPLES.md`
4. Revise configuração em `config.php`

---

## 📄 Licença

Este projeto está sob a licença MIT. Você é livre para usar, modificar e distribuir.

---

## 👨‍💻 Autor

**MAGO PD**
Desenvolvido com ❤️ e muito ☕

---

**🔒 MAGO GATEWAY V3 (STEALTH MODE) - Anti-Sniffing Proxy System**

*Proteção profissional contra NetCapture e ferramentas de sniffing*
