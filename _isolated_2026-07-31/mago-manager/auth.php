<?php
/**
 * ═══════════════════════════════════════════════════════════════════
 * MAGO GATEWAY V2 - Sistema de Autenticação
 * ═══════════════════════════════════════════════════════════════════
 */

require_once '../config.php';

// Inicia a sessão segura
session_name(SESSION_NAME);
session_start();

/**
 * Verifica se o usuário está autenticado
 */
function verificarAutenticacao() {
    if (!isset($_SESSION['mago_autenticado']) || $_SESSION['mago_autenticado'] !== true) {
        header('Location: login.php');
        exit;
    }

    // Verifica timeout da sessão
    if (isset($_SESSION['ultimo_acesso'])) {
        $inativo = time() - $_SESSION['ultimo_acesso'];
        if ($inativo > SESSION_TIMEOUT) {
            session_unset();
            session_destroy();
            header('Location: login.php?timeout=1');
            exit;
        }
    }

    $_SESSION['ultimo_acesso'] = time();
}

/**
 * Realiza o login do usuário
 */
function realizarLogin($usuario, $senha) {
    if ($usuario === ADMIN_USER && $senha === ADMIN_PASS) {
        $_SESSION['mago_autenticado'] = true;
        $_SESSION['mago_usuario'] = $usuario;
        $_SESSION['ultimo_acesso'] = time();
        $_SESSION['ip_login'] = $_SERVER['REMOTE_ADDR'];
        return true;
    }
    return false;
}

/**
 * Realiza o logout
 */
function realizarLogout() {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}
