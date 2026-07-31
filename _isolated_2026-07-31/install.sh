#!/bin/bash

################################################################################
# MAGO GATEWAY V2 - Script de Instalação Automatizada
################################################################################
# Este script configura automaticamente as permissões necessárias para o
# funcionamento do MAGO GATEWAY V2 em ambientes aPanel (Ubuntu + Apache/Nginx)
################################################################################

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Caracteres especiais
CHECK="${GREEN}✓${NC}"
CROSS="${RED}✗${NC}"
ARROW="${PURPLE}➜${NC}"
STAR="${YELLOW}★${NC}"

################################################################################
# Banner
################################################################################
clear
echo -e "${PURPLE}"
cat << "EOF"
═══════════════════════════════════════════════════════════════════
███╗   ███╗ █████╗  ██████╗  ██████╗     ██╗   ██╗██████╗
████╗ ████║██╔══██╗██╔════╝ ██╔═══██╗    ██║   ██║╚════██╗
██╔████╔██║███████║██║  ███╗██║   ██║    ██║   ██║ █████╔╝
██║╚██╔╝██║██╔══██║██║   ██║██║   ██║    ╚██╗ ██╔╝██╔═══╝
██║ ╚═╝ ██║██║  ██║╚██████╔╝╚██████╔╝     ╚████╔╝ ███████╗
╚═╝     ╚═╝╚═╝  ╚═╝ ╚═════╝  ╚═════╝       ╚═══╝  ╚══════╝

         GATEWAY SYSTEM - SCRIPT DE INSTALAÇÃO
═══════════════════════════════════════════════════════════════════
EOF
echo -e "${NC}"

echo -e "${CYAN}Versão:${NC} 2.0"
echo -e "${CYAN}Autor:${NC} MAGO PD"
echo -e "${CYAN}Data:${NC} $(date '+%d/%m/%Y %H:%M:%S')"
echo ""

################################################################################
# Funções de Utilidade
################################################################################

# Função para exibir mensagens de sucesso
success() {
    echo -e "${CHECK} ${GREEN}$1${NC}"
}

# Função para exibir mensagens de erro
error() {
    echo -e "${CROSS} ${RED}$1${NC}"
}

# Função para exibir mensagens de aviso
warning() {
    echo -e "${STAR} ${YELLOW}$1${NC}"
}

# Função para exibir mensagens informativas
info() {
    echo -e "${ARROW} ${CYAN}$1${NC}"
}

# Função para verificar se o comando foi executado com sucesso
check_result() {
    if [ $? -eq 0 ]; then
        success "$1"
    else
        error "$2"
        exit 1
    fi
}

# Função para verificar se está rodando como root
check_root() {
    if [ "$EUID" -ne 0 ]; then
        error "Este script precisa ser executado como root (use sudo)"
        exit 1
    fi
}

################################################################################
# Verificações Iniciais
################################################################################

info "Iniciando verificações do sistema..."
echo ""

# Verifica se está rodando como root
check_root

# Detecta o diretório atual
INSTALL_DIR=$(pwd)
info "Diretório de instalação: ${INSTALL_DIR}"

# Detecta o usuário do servidor web
if id "www-data" &>/dev/null; then
    WEB_USER="www-data"
    success "Usuário do servidor web detectado: www-data"
elif id "www" &>/dev/null; then
    WEB_USER="www"
    success "Usuário do servidor web detectado: www"
elif id "nginx" &>/dev/null; then
    WEB_USER="nginx"
    success "Usuário do servidor web detectado: nginx"
elif id "apache" &>/dev/null; then
    WEB_USER="apache"
    success "Usuário do servidor web detectado: apache"
else
    warning "Usuário do servidor web não detectado automaticamente"
    read -p "Digite o usuário do servidor web (padrão: www): " WEB_USER
    WEB_USER=${WEB_USER:-www}
    info "Usando usuário: ${WEB_USER}"
fi

echo ""

# Verifica se os arquivos essenciais existem
info "Verificando arquivos essenciais..."
REQUIRED_FILES=(
    "config.php"
    "index.php"
    "router.php"
    ".htaccess"
    "mago-manager/index.php"
    "mago-manager/auth.php"
    "mago-manager/cloudflare.php"
    "mago-manager/proxies.json"
)

for file in "${REQUIRED_FILES[@]}"; do
    if [ -f "${INSTALL_DIR}/${file}" ]; then
        success "Encontrado: ${file}"
    else
        error "Arquivo não encontrado: ${file}"
        exit 1
    fi
done

echo ""

################################################################################
# Verificação de Dependências
################################################################################

info "Verificando dependências do sistema..."
echo ""

# Verifica PHP
if command -v php &> /dev/null; then
    PHP_VERSION=$(php -r "echo PHP_VERSION;")
    success "PHP instalado: versão ${PHP_VERSION}"

    # Verifica versão mínima (8.0)
    if php -r "exit(version_compare(PHP_VERSION, '8.0.0', '<') ? 1 : 0);"; then
        success "Versão do PHP compatível (>= 8.0)"
    else
        error "Versão do PHP incompatível. Requer PHP 8.0 ou superior"
        exit 1
    fi
else
    error "PHP não está instalado"
    exit 1
fi

# Verifica extensões PHP necessárias
REQUIRED_EXTENSIONS=("curl" "json" "session")
for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if php -m | grep -q "^${ext}$"; then
        success "Extensão PHP instalada: ${ext}"
    else
        error "Extensão PHP não encontrada: ${ext}"
        warning "Instale com: sudo apt-get install php-${ext}"
        exit 1
    fi
done

echo ""

################################################################################
# Configuração de Permissões
################################################################################

info "Configurando permissões dos arquivos..."
echo ""

# Define proprietário de todos os arquivos
info "Definindo proprietário: ${WEB_USER}:${WEB_USER}"
chown -R ${WEB_USER}:${WEB_USER} "${INSTALL_DIR}"
check_result "Proprietário definido com sucesso" "Falha ao definir proprietário"

# Permissões para diretórios (755)
info "Configurando permissões de diretórios (755)..."
find "${INSTALL_DIR}" -type d -exec chmod 755 {} \;
check_result "Permissões de diretórios configuradas" "Falha ao configurar permissões de diretórios"

# Permissões para arquivos (644)
info "Configurando permissões de arquivos (644)..."
find "${INSTALL_DIR}" -type f -exec chmod 644 {} \;
check_result "Permissões de arquivos configuradas" "Falha ao configurar permissões de arquivos"

# Permissão especial para proxies.json (666 - leitura e escrita)
info "Configurando permissão de escrita para proxies.json..."
chmod 666 "${INSTALL_DIR}/mago-manager/proxies.json"
check_result "Permissão de escrita configurada para proxies.json" "Falha ao configurar permissão do proxies.json"

# Permissão para o diretório mago-manager (755)
chmod 755 "${INSTALL_DIR}/mago-manager"
check_result "Permissões do diretório mago-manager configuradas" "Falha ao configurar permissões do mago-manager"

# Cria arquivo de log se não existir
if [ ! -f "${INSTALL_DIR}/proxy.log" ]; then
    touch "${INSTALL_DIR}/proxy.log"
    chown ${WEB_USER}:${WEB_USER} "${INSTALL_DIR}/proxy.log"
    chmod 666 "${INSTALL_DIR}/proxy.log"
    success "Arquivo de log criado: proxy.log"
fi

echo ""

################################################################################
# Verificação de Servidor Web
################################################################################

info "Verificando configuração do servidor web..."
echo ""

# Detecta Apache
if systemctl is-active --quiet apache2 || systemctl is-active --quiet httpd; then
    success "Apache detectado e ativo"

    # Verifica mod_rewrite
    if apache2ctl -M 2>/dev/null | grep -q "rewrite_module" || httpd -M 2>/dev/null | grep -q "rewrite_module"; then
        success "mod_rewrite está habilitado"
    else
        warning "mod_rewrite não está habilitado"
        info "Habilitando mod_rewrite..."
        a2enmod rewrite 2>/dev/null || true
        info "Reinicie o Apache para aplicar as mudanças: sudo systemctl restart apache2"
    fi
fi

# Detecta Nginx
if systemctl is-active --quiet nginx; then
    success "Nginx detectado e ativo"
    warning "Para Nginx, certifique-se de que o arquivo de configuração do site está correto"
    info "Consulte o README.md para a configuração do Nginx"
fi

echo ""

################################################################################
# Configurações Finais
################################################################################

info "Aplicando configurações finais..."
echo ""

# Cria link simbólico se necessário (opcional)
# ln -sf "${INSTALL_DIR}/index.php" "${INSTALL_DIR}/index.html"

success "Configuração concluída!"

echo ""

################################################################################
# Resumo da Instalação
################################################################################

echo -e "${PURPLE}═══════════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}              INSTALAÇÃO CONCLUÍDA COM SUCESSO!${NC}"
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""
echo -e "${CYAN}📁 Diretório:${NC} ${INSTALL_DIR}"
echo -e "${CYAN}👤 Usuário Web:${NC} ${WEB_USER}"
echo -e "${CYAN}🐘 Versão PHP:${NC} ${PHP_VERSION}"
echo ""
echo -e "${YELLOW}⚠️  PRÓXIMOS PASSOS IMPORTANTES:${NC}"
echo ""
echo -e "${ARROW} 1. Edite o arquivo ${PURPLE}config.php${NC} e configure:"
echo -e "     • Credenciais da Cloudflare (Email, Zone ID, API Token)"
echo -e "     • Credenciais de login (ALTERE admin/admin!)"
echo ""
echo -e "${ARROW} 2. Acesse o painel: ${BLUE}http://seu-dominio.com/mago-manager/${NC}"
echo ""
echo -e "${ARROW} 3. Faça login com as credenciais padrão:"
echo -e "     • Usuário: ${GREEN}admin${NC}"
echo -e "     • Senha: ${GREEN}admin${NC}"
echo -e "     ${RED}(ALTERE IMEDIATAMENTE!)${NC}"
echo ""
echo -e "${ARROW} 4. Configure SSL/HTTPS para segurança adicional"
echo ""
echo -e "${ARROW} 5. Consulte o ${PURPLE}README.md${NC} para instruções completas"
echo ""
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""
echo -e "${GREEN}✨ MAGO GATEWAY V2 está pronto para uso!${NC}"
echo ""

################################################################################
# Verificação Pós-Instalação
################################################################################

info "Deseja fazer um teste de conectividade agora? (s/n)"
read -r -p "→ " RESPONSE

if [[ "$RESPONSE" =~ ^([sS][iI][mM]|[sS])$ ]]; then
    echo ""
    info "Testando conectividade..."

    # Testa se o PHP está funcionando
    if php -r "echo 'OK';" > /dev/null 2>&1; then
        success "PHP está funcionando corretamente"
    else
        error "Problema ao executar PHP"
    fi

    # Testa se o arquivo config.php é válido
    if php -l "${INSTALL_DIR}/config.php" > /dev/null 2>&1; then
        success "config.php válido"
    else
        error "Erro de sintaxe em config.php"
    fi

    # Testa se o router.php é válido
    if php -l "${INSTALL_DIR}/router.php" > /dev/null 2>&1; then
        success "router.php válido"
    else
        error "Erro de sintaxe em router.php"
    fi

    echo ""
    success "Testes concluídos!"
fi

echo ""
echo -e "${CYAN}Para logs e troubleshooting, consulte:${NC}"
echo -e "  • ${INSTALL_DIR}/proxy.log"
echo -e "  • /var/log/apache2/error.log (ou nginx/error.log)"
echo ""

exit 0
