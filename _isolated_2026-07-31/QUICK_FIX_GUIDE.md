# 🚀 MAGO GATEWAY V3 - Guia Rápido de Correção

**Para usuários que estão vendo apenas o prefixo do domínio (ex: "03") ao invés do domínio completo (ex: "03.dnsmain.site")**

---

## 🎯 Solução Rápida em 3 Passos

### **Passo 1: Verifique o Problema**

1. Acesse o painel: `http://seu-dominio.com/mago-manager/`
2. Faça login
3. Veja a tabela "Proxies Configurados"
4. **Se aparecer apenas "03" ao invés de "03.dnsmain.site"** → Continue para Passo 2

---

### **Passo 2: Execute o Script de Correção**

**Via SSH/Terminal:**

```bash
# 1. Acesse o diretório
cd /www/wwwroot/seu-dominio.com/mago-manager

# 2. Execute o script de correção
php fix_domains.php
```

**Saída Esperada:**
```
═══════════════════════════════════════════════════════════════
🔧 MAGO GATEWAY V3 - Correção de Domínios
═══════════════════════════════════════════════════════════════

📊 Total de proxies encontrados: 3

🔍 Analisando proxies...

🔧 Proxy #1:
   Antes: 03
   Agora: 03.dnsmain.site

🔧 Proxy #2:
   Antes: ryzen
   Agora: ryzen.dnsmain.site

✅ Proxy #3: wave.dnsmain.site (OK)

═══════════════════════════════════════════════════════════════
📊 RESUMO DA CORREÇÃO
═══════════════════════════════════════════════════════════════

Total de proxies:      3
Já estavam corretos:   1
Corrigidos agora:      2
Erros/Ignorados:       0

💾 Salvando alterações...
📦 Backup criado: proxies.json.backup_2026-01-10_11-30-00
✅ Arquivo proxies.json atualizado com sucesso!
```

---

### **Passo 3: Verifique a Correção**

1. **Recarregue o painel** (Ctrl+Shift+R)
2. **Veja a tabela** novamente
3. **Agora deve aparecer**: `03.dnsmain.site` (completo) ✅
4. **Teste o botão copiar** - deve copiar o domínio completo

---

## 🔧 Customizar Domínio Base

Se seu domínio base **NÃO é** `dnsmain.site`, edite o script:

```bash
nano /www/wwwroot/seu-dominio.com/mago-manager/fix_domains.php
```

**Altere a linha 13:**
```php
// ANTES
$DOMAIN_BASE = '.dnsmain.site';

// PARA SEU DOMÍNIO
$DOMAIN_BASE = '.seudominio.com';
```

**Salve** (Ctrl+O) e **execute** novamente.

---

## 📋 Checklist de Verificação

Após executar o script:

- [ ] Script executou sem erros
- [ ] Backup foi criado automaticamente
- [ ] Domínios agora aparecem completos na tabela
- [ ] Texto está visível em **roxo neon** (não mais branco)
- [ ] Botão copiar funciona corretamente
- [ ] Copia domínio COMPLETO (ex: `03.dnsmain.site`)

---

## 🆘 Se Algo Der Errado

### Restaurar Backup

O script cria backup automaticamente antes de fazer mudanças:

```bash
cd /www/wwwroot/seu-dominio.com/mago-manager

# Listar backups
ls -la proxies.json.backup_*

# Restaurar backup (use o mais recente)
cp proxies.json.backup_2026-01-10_11-30-00 proxies.json

# Recarregue o painel
```

---

## 💡 Solução Alternativa (Manual)

Se não pode executar o script PHP, edite manualmente:

### **Via Painel (Recomendado)**

1. **Delete** o proxy antigo (ex: "03")
2. **Adicione novamente** com domínio completo
3. **Digite**: `03.dnsmain.site` (completo!)
4. **Configure** IP e modo conforme antes

### **Via Arquivo JSON (Avançado)**

```bash
nano /www/wwwroot/seu-dominio.com/mago-manager/proxies.json
```

**Altere de:**
```json
{
  "dominio": "03",
  "ip_xui": "198.13.16.162:80"
}
```

**Para:**
```json
{
  "dominio": "03.dnsmain.site",
  "ip_xui": "198.13.16.162:80"
}
```

---

## 🎨 Cores Agora Estão Corretas!

Após a correção de bugs, você verá:

| Elemento | Cor | Visibilidade |
|----------|-----|--------------|
| **Domínio** | Roxo neon #a855f7 | ✅ Perfeita |
| **IP** | Rosa neon #e879f9 | ✅ Perfeita |
| **Botão Copiar** | Roxo neon | ✅ Visível |
| **Hover** | Gradiente roxo | ✅ Animado |

---

## 📞 Ainda com Problemas?

### Problema: Script não executa

**Solução:**
```bash
# Verifique permissões
chmod 644 fix_domains.php

# Verifique PHP
which php
php -v
```

### Problema: Texto ainda invisível

**Solução:**
1. Limpe cache do navegador (Ctrl+Shift+R)
2. Verifique se está acessando a V3 (não V1 ou V2)
3. Verifique URL: `mago_gateway_v3/mago-manager/`

### Problema: Domínio ainda aparece incompleto

**Solução:**
1. Verifique se o script realmente salvou:
   ```bash
   cat proxies.json
   ```
2. Procure por `"dominio": "xxx.dnsmain.site"`
3. Se ainda estiver errado, edite manualmente

---

## ✅ Resumo Final

**Bugs corrigidos:**
- ✅ Cores invisíveis → Agora em roxo/rosa neon
- ✅ Placeholder genérico → Agora específico com exemplo
- ✅ Instruções ambíguas → Agora com aviso ⚠️

**Script de correção:**
- ✅ Corrige dados antigos automaticamente
- ✅ Cria backup antes de modificar
- ✅ Simples de executar (`php fix_domains.php`)

**Resultado:**
- ✅ Painel 100% funcional
- ✅ Texto perfeitamente visível
- ✅ Domínios completos exibidos
- ✅ Botão copiar funcionando

---

**🎉 Seu painel está pronto para uso profissional!**

---

## 🔗 Links Úteis

- **Relatório Completo de Bugs:** `BUGFIX_REPORT.md`
- **Melhorias do Painel:** `PANEL_IMPROVEMENTS.md`
- **README Principal:** `README.md`
- **Script de Correção:** `mago-manager/fix_domains.php`

---

**Última atualização:** 2026-01-10
**Versão:** V3.0.1
**Status:** ✅ **BUGS CORRIGIDOS**
