# 🎮 MAGO GATEWAY V3 - Guia de Configuração de Players

Este guia explica como configurar diferentes players IPTV para usar o **User-Agent correto** e funcionar com o Modo Stealth.

---

## 📋 Índice

- [User-Agent Padrão](#user-agent-padrão)
- [Configuração por Player](#configuração-por-player)
- [Desenvolvimento de App Personalizado](#desenvolvimento-de-app-personalizado)
- [Troubleshooting](#troubleshooting)

---

## 🔑 User-Agent Padrão

Por padrão, o MAGO GATEWAY V3 está configurado com:

```
MagoPlayer/3.0
```

**⚠️ IMPORTANTE:** Você pode alterar isso em `config.php`:

```php
define('ALLOWED_USER_AGENT', 'SeuPlayerCustomizado/1.0');
```

---

## 📱 Configuração por Player

### 1. VLC Media Player

**Desktop (via linha de comando):**

```bash
# Windows
vlc --http-user-agent="MagoPlayer/3.0" "http://proxy.com/playlist.m3u"

# Linux/Mac
vlc --http-user-agent="MagoPlayer/3.0" http://proxy.com/playlist.m3u
```

**Desktop (via arquivo de configuração):**

1. Abra VLC → Ferramentas → Preferências
2. Selecione "Todos" (embaixo à esquerda)
3. Navegue: Input / Codecs → Access modules → HTTP(S)
4. Em "User Agent", coloque: `MagoPlayer/3.0`
5. Salve e reinicie o VLC

**Android VLC:**

VLC para Android não permite configurar User-Agent nativamente. Use outro player.

---

### 2. Kodi (com plugin IPTV Simple Client)

**Método 1: Modificar plugin (Requer conhecimento técnico)**

1. Localize o plugin:
   ```
   ~/.kodi/addons/pvr.iptvsimple/
   ```

2. Edite o arquivo de configuração para adicionar User-Agent customizado

**Método 2: Usar addon personalizado**

Desenvolva ou use um addon que permita configurar User-Agent.

**Recomendação:** Para Kodi, é mais fácil desenvolver um addon próprio que faça as requisições com o User-Agent correto.

---

### 3. Perfect Player (Android/iOS)

O Perfect Player não permite configurar User-Agent diretamente.

**Alternativas:**
1. Desabilitar validação de User-Agent no servidor (modo compatibilidade):
   ```php
   define('ENFORCE_USER_AGENT', false);
   ```

2. Usar proxy intermediário que adiciona o User-Agent

3. Usar outro player que suporte configuração

---

### 4. IPTV Smarters Pro

IPTV Smarters Pro não permite configurar User-Agent personalizado.

**Alternativas:**
1. Contatar desenvolvedores do app para adicionar esta funcionalidade
2. Usar modo compatibilidade no servidor
3. Desenvolver app próprio

---

### 5. TiviMate (Android TV)

TiviMate não permite configuração de User-Agent.

**Alternativas:**
1. Modo compatibilidade no servidor
2. Desenvolver app próprio para Android TV

---

### 6. GSE Smart IPTV (iOS)

GSE Smart IPTV permite configurar headers HTTP.

**Configuração:**

1. Abra o app
2. Vá em Configurações → Avançado
3. Procure opção "HTTP Headers" ou "Custom Headers"
4. Adicione:
   ```
   User-Agent: MagoPlayer/3.0
   ```

---

### 7. OTT Navigator (Android)

OTT Navigator não suporta User-Agent personalizado nativamente.

**Alternativa:** Modo compatibilidade ou app próprio.

---

## 💻 Desenvolvimento de App Personalizado

Se você desenvolve seu próprio player IPTV, aqui estão exemplos de como configurar o User-Agent:

### Android (Java/Kotlin)

**ExoPlayer (Recomendado):**

```kotlin
import com.google.android.exoplayer2.upstream.DefaultHttpDataSource

val dataSourceFactory = DefaultHttpDataSource.Factory()
    .setUserAgent("MagoPlayer/3.0")
    .setAllowCrossProtocolRedirects(true)

val mediaSource = ProgressiveMediaSource.Factory(dataSourceFactory)
    .createMediaSource(MediaItem.fromUri(videoUrl))

player.setMediaSource(mediaSource)
player.prepare()
```

**HttpURLConnection:**

```kotlin
val url = URL("http://proxy.com/playlist.m3u")
val connection = url.openConnection() as HttpURLConnection
connection.setRequestProperty("User-Agent", "MagoPlayer/3.0")
connection.connect()

val inputStream = connection.inputStream
// Processar stream
```

### iOS (Swift)

**AVPlayer:**

```swift
import AVFoundation

let url = URL(string: "http://proxy.com/video.mp4")!
var request = URLRequest(url: url)
request.setValue("MagoPlayer/3.0", forHTTPHeaderField: "User-Agent")

let asset = AVURLAsset(url: url, options: [
    "AVURLAssetHTTPHeaderFieldsKey": [
        "User-Agent": "MagoPlayer/3.0"
    ]
])

let playerItem = AVPlayerItem(asset: asset)
let player = AVPlayer(playerItem: playerItem)
player.play()
```

### React Native

**react-native-video:**

```javascript
import Video from 'react-native-video';

<Video
  source={{
    uri: 'http://proxy.com/video.mp4',
    headers: {
      'User-Agent': 'MagoPlayer/3.0'
    }
  }}
  style={styles.video}
/>
```

### Flutter

**video_player:**

```dart
import 'package:video_player/video_player.dart';
import 'package:http/http.dart' as http;

final controller = VideoPlayerController.network(
  'http://proxy.com/video.mp4',
  httpHeaders: {
    'User-Agent': 'MagoPlayer/3.0',
  },
);

await controller.initialize();
controller.play();
```

### Web (JavaScript)

**HLS.js:**

```javascript
import Hls from 'hls.js';

const video = document.getElementById('video');
const hls = new Hls({
  xhrSetup: function(xhr, url) {
    xhr.setRequestHeader('User-Agent', 'MagoPlayer/3.0');
  }
});

hls.loadSource('http://proxy.com/playlist.m3u8');
hls.attachMedia(video);
```

**Fetch API:**

```javascript
fetch('http://proxy.com/playlist.m3u', {
  headers: {
    'User-Agent': 'MagoPlayer/3.0'
  }
})
.then(response => response.text())
.then(m3u => {
  // Processar M3U
  console.log(m3u);
});
```

---

## 🔧 Troubleshooting

### Problema: Player não envia User-Agent personalizado

**Teste se o player suporta:**

1. Configure um servidor web simples que loga headers
2. Faça requisição do player
3. Verifique se User-Agent aparece nos logs

**Se não suportar:**
- Use modo compatibilidade no MAGO V3
- Desenvolva app próprio
- Use proxy intermediário

### Problema: Erro 403 mesmo configurando User-Agent

**Verificações:**

1. **User-Agent exato:**
   ```bash
   # Errado (espaços/maiúsculas diferentes)
   MagoPlayer /3.0
   magoplayer/3.0

   # Correto
   MagoPlayer/3.0
   ```

2. **Verifique logs do servidor:**
   ```bash
   tail -f /www/wwwroot/seu-dominio.com/security.log
   ```

3. **Teste com curl:**
   ```bash
   curl -H "User-Agent: MagoPlayer/3.0" http://proxy.com/playlist.m3u
   ```

### Modo Compatibilidade (Desabilita validação)

Se seus usuários usam players que não suportam User-Agent personalizado:

**Em `config.php`:**

```php
// Desabilita validação de User-Agent
define('ENFORCE_USER_AGENT', false);
```

**⚠️ AVISO:** Isso reduz a segurança! Ferramentas de sniffing poderão acessar seu conteúdo.

**Alternativa mais segura:**

Configure múltiplos User-Agents permitidos:

```php
// Em security.php, modifique a função validateUserAgent:

$allowedAgents = [
    'MagoPlayer/3.0',
    'VLC/3.0',
    'ExoPlayer/2.0',
    'iOS/AVPlayer'
];

if (!in_array($userAgent, $allowedAgents)) {
    return false;
}
```

---

## 📊 Comparação de Players

| Player | Suporta User-Agent Custom | Dificuldade | Recomendado |
|--------|---------------------------|-------------|-------------|
| **VLC Desktop** | ✅ Sim (via config) | Fácil | ✅ |
| **VLC Android** | ❌ Não | - | ❌ |
| **Kodi** | ⚠️ Via addon | Difícil | ⚠️ |
| **Perfect Player** | ❌ Não | - | ❌ |
| **IPTV Smarters** | ❌ Não | - | ❌ |
| **TiviMate** | ❌ Não | - | ❌ |
| **GSE Smart IPTV** | ✅ Sim | Fácil | ✅ |
| **App Próprio** | ✅ Sim (total controle) | Médio | ✅✅ |

---

## 🎯 Recomendações

### Para Usuários Finais

1. **Desktop:** Use VLC com configuração de User-Agent
2. **Mobile:** Use GSE Smart IPTV (se disponível)
3. **Alternativa:** Peça ao provedor para desabilitar validação

### Para Desenvolvedores/Provedores

1. **Desenvolva app próprio** com User-Agent configurado
2. **Distribua APK/IPA** pré-configurado
3. **Documente** como configurar para usuários técnicos
4. **Ofereça suporte** para configuração

### Para Administradores de Sistema

1. **Use modo Stealth** para VOD premium
2. **Configure User-Agent único** por provedor/app
3. **Monitore security.log** regularmente
4. **Documente** User-Agent para seus clientes

---

## 📞 Suporte

Se você desenvolveu um player que precisa funcionar com MAGO V3:

1. Consulte a [documentação da API](#desenvolvimento-de-app-personalizado)
2. Teste com curl primeiro
3. Verifique logs em `security.log`
4. Entre em contato se precisar de modo compatibilidade

---

**🔒 MAGO GATEWAY V3 (STEALTH MODE)**

*Proteção profissional com flexibilidade para seus players*
