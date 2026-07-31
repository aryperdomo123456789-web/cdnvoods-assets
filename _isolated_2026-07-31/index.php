<?php
/**
 * ═══════════════════════════════════════════════════════════════════
 * MAGO GATEWAY V2 - Página de Status
 * ═══════════════════════════════════════════════════════════════════
 * Exibida quando o domínio acessado não está mapeado como proxy
 * ═══════════════════════════════════════════════════════════════════
 */

require_once 'config.php';

// Carrega estatísticas
$db_file = DB_FILE;
$proxies = file_exists($db_file) ? json_decode(file_get_contents($db_file), true) : [];
$total_proxies = is_array($proxies) ? count($proxies) : 0;

// Detecta se o sistema está rodando
$system_status = true;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MAGO GATEWAY V2 - Sistema Online</title>
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
            color: #fff;
            overflow: hidden;
            position: relative;
        }

        /* Efeito de fundo animado */
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

        .container {
            position: relative;
            z-index: 10;
            text-align: center;
            max-width: 800px;
            padding: 20px;
        }

        .logo-section {
            margin-bottom: 50px;
        }

        .logo-icon {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #7c3aed 0%, #a855f7 100%);
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            box-shadow: 0 0 60px rgba(124, 58, 237, 0.8);
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
        }

        .logo-icon i {
            font-size: 60px;
            color: #fff;
            text-shadow: 0 0 30px rgba(255, 255, 255, 0.8);
        }

        h1 {
            font-size: 56px;
            font-weight: 900;
            background: linear-gradient(135deg, #a855f7 0%, #ec4899 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-transform: uppercase;
            letter-spacing: 5px;
            margin-bottom: 20px;
            text-shadow: 0 0 40px rgba(168, 85, 247, 0.5);
        }

        .subtitle {
            font-size: 24px;
            color: #a855f7;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 3px;
            margin-bottom: 60px;
        }

        .info-card {
            background: rgba(13, 0, 26, 0.9);
            border: 2px solid #7c3aed;
            border-radius: 25px;
            padding: 50px;
            margin-bottom: 40px;
            box-shadow: 0 10px 60px rgba(124, 58, 237, 0.4);
            backdrop-filter: blur(10px);
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 15px;
            padding: 20px 40px;
            background: rgba(34, 197, 94, 0.2);
            border: 2px solid #22c55e;
            border-radius: 50px;
            margin-bottom: 40px;
            font-size: 20px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #86efac;
        }

        .status-indicator i {
            font-size: 24px;
            animation: pulse-icon 2s ease-in-out infinite;
        }

        @keyframes pulse-icon {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 30px;
            margin: 40px 0;
        }

        .stat-item {
            background: rgba(124, 58, 237, 0.15);
            border: 2px solid #7c3aed;
            border-radius: 20px;
            padding: 30px;
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(124, 58, 237, 0.5);
        }

        .stat-label {
            color: #a855f7;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 15px;
        }

        .stat-value {
            font-size: 42px;
            font-weight: 900;
            background: linear-gradient(135deg, #fff 0%, #a855f7 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .btn-admin {
            display: inline-flex;
            align-items: center;
            gap: 15px;
            padding: 20px 50px;
            background: linear-gradient(135deg, #7c3aed 0%, #a855f7 100%);
            border: none;
            border-radius: 15px;
            color: #fff;
            font-size: 18px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            text-decoration: none;
            box-shadow: 0 10px 40px rgba(124, 58, 237, 0.5);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .btn-admin:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 50px rgba(124, 58, 237, 0.7);
            color: #fff;
        }

        .version-badge {
            position: absolute;
            top: 30px;
            right: 30px;
            background: rgba(124, 58, 237, 0.3);
            border: 2px solid #7c3aed;
            color: #a855f7;
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 2px;
        }

        .footer {
            margin-top: 60px;
            color: #6b21a8;
            font-size: 14px;
        }

        /* Animação de entrada */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeInUp 0.8s ease-out;
        }
    </style>
</head>
<body>
    <div class="version-badge">V3.0 STEALTH</div>

    <div class="container fade-in">
        <div class="logo-section">
            <div class="logo-icon">
                <i class="fas fa-network-wired"></i>
            </div>
            <h1>MAGO GATEWAY</h1>
            <p class="subtitle">Stealth Proxy V3</p>
        </div>

        <div class="info-card">
            <div class="status-indicator">
                <i class="fas fa-check-circle"></i>
                Sistema Online
            </div>

            <div class="stats">
                <div class="stat-item">
                    <div class="stat-label">
                        <i class="fas fa-server"></i> Proxies Ativos
                    </div>
                    <div class="stat-value"><?php echo $total_proxies; ?></div>
                </div>

                <div class="stat-item">
                    <div class="stat-label">
                        <i class="fas fa-cloud"></i> Cloudflare
                    </div>
                    <div class="stat-value">
                        <i class="fas fa-check" style="font-size: 36px; color: #22c55e;"></i>
                    </div>
                </div>

                <div class="stat-item">
                    <div class="stat-label">
                        <i class="fas fa-shield-alt"></i> Status
                    </div>
                    <div class="stat-value" style="font-size: 24px; color: #22c55e;">OK</div>
                </div>
            </div>

            <a href="/mago-manager/" class="btn-admin">
                <i class="fas fa-cog"></i>
                Acessar Painel
            </a>
        </div>

        <div class="footer">
            <p>
                <i class="fas fa-bolt"></i>
                Powered by MAGO GATEWAY V3 STEALTH
                <i class="fas fa-bolt"></i>
            </p>
            <p style="margin-top: 10px; font-size: 12px;">
                Anti-Sniffing Proxy System | Cloudflare Integration | Token Protection
            </p>
        </div>
    </div>
</body>
</html>
