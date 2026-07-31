<?php
/**
 * ═══════════════════════════════════════════════════════════════════
 * MAGO GATEWAY V2 - Tela de Login
 * ═══════════════════════════════════════════════════════════════════
 */

require_once '../config.php';
require_once 'auth.php';

$erro = '';
$timeout = isset($_GET['timeout']) ? true : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = $_POST['usuario'] ?? '';
    $senha = $_POST['senha'] ?? '';

    if (realizarLogin($usuario, $senha)) {
        header('Location: index.php');
        exit;
    } else {
        $erro = 'Usuário ou senha inválidos!';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MAGO GATEWAY V2 - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0a0015 0%, #1a0033 50%, #2d0050 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        /* Efeito de partículas 3D */
        body::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background:
                radial-gradient(circle at 20% 30%, rgba(138, 43, 226, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(147, 51, 234, 0.15) 0%, transparent 50%);
            animation: pulse 8s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 0.5; }
            50% { opacity: 1; }
        }

        .login-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 450px;
            padding: 20px;
        }

        .login-card {
            background: rgba(13, 0, 26, 0.95);
            border: 2px solid #7c3aed;
            border-radius: 25px;
            padding: 50px 40px;
            box-shadow:
                0 0 60px rgba(124, 58, 237, 0.4),
                inset 0 0 30px rgba(124, 58, 237, 0.1);
            backdrop-filter: blur(10px);
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo-section {
            text-align: center;
            margin-bottom: 40px;
        }

        .logo-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #7c3aed 0%, #a855f7 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 0 40px rgba(124, 58, 237, 0.6);
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        .logo-icon i {
            font-size: 40px;
            color: #fff;
            text-shadow: 0 0 20px rgba(255, 255, 255, 0.8);
        }

        .logo-title {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(135deg, #a855f7 0%, #ec4899 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-transform: uppercase;
            letter-spacing: 3px;
            text-shadow: 0 0 30px rgba(168, 85, 247, 0.5);
            margin-bottom: 10px;
        }

        .logo-subtitle {
            color: #a855f7;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .form-floating {
            margin-bottom: 25px;
        }

        .form-control {
            background: rgba(13, 0, 26, 0.8);
            border: 2px solid #6b21a8;
            border-radius: 15px;
            color: #fff;
            height: 60px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            background: rgba(13, 0, 26, 0.95);
            border-color: #a855f7;
            box-shadow: 0 0 20px rgba(168, 85, 247, 0.4);
            color: #fff;
        }

        .form-control::placeholder {
            color: #9333ea;
        }

        .form-floating label {
            color: #a855f7;
            font-weight: 600;
        }

        .btn-login {
            width: 100%;
            height: 60px;
            background: linear-gradient(135deg, #7c3aed 0%, #a855f7 100%);
            border: none;
            border-radius: 15px;
            color: #fff;
            font-size: 18px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 10px 30px rgba(124, 58, 237, 0.4);
            position: relative;
            overflow: hidden;
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .btn-login:hover::before {
            left: 100%;
        }

        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(124, 58, 237, 0.6);
        }

        .alert {
            border: 2px solid;
            border-radius: 15px;
            padding: 15px 20px;
            margin-bottom: 25px;
            font-weight: 600;
            animation: shake 0.5s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }

        .alert-danger {
            background: rgba(220, 38, 38, 0.2);
            border-color: #dc2626;
            color: #fca5a5;
        }

        .alert-warning {
            background: rgba(245, 158, 11, 0.2);
            border-color: #f59e0b;
            color: #fcd34d;
        }

        .version-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(124, 58, 237, 0.3);
            border: 1px solid #7c3aed;
            color: #a855f7;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
        }
    </style>
</head>
<body>
    <div class="version-badge">V2.0</div>

    <div class="login-container">
        <div class="login-card">
            <div class="logo-section">
                <div class="logo-icon">
                    <i class="fas fa-network-wired"></i>
                </div>
                <h1 class="logo-title">MAGO</h1>
                <p class="logo-subtitle">Gateway System</p>
            </div>

            <?php if ($timeout): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-clock"></i> Sessão expirada! Faça login novamente.
                </div>
            <?php endif; ?>

            <?php if ($erro): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($erro); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-floating">
                    <input type="text" class="form-control" id="usuario" name="usuario" placeholder="Usuário" required autofocus>
                    <label for="usuario"><i class="fas fa-user"></i> Usuário</label>
                </div>

                <div class="form-floating">
                    <input type="password" class="form-control" id="senha" name="senha" placeholder="Senha" required>
                    <label for="senha"><i class="fas fa-lock"></i> Senha</label>
                </div>

                <button type="submit" class="btn btn-login">
                    <i class="fas fa-sign-in-alt"></i> Entrar
                </button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
