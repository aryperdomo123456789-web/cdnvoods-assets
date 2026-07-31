<?php
/**
 * ═══════════════════════════════════════════════════════════════════
 * MAGO GATEWAY V3 (STEALTH MODE) - PRO ELITE EDITION
 * ═══════════════════════════════════════════════════════════════════
 */

require_once '../config.php';
require_once 'auth.php';

verificarAutenticacao();

// Database logic
$db = DB_FILE;
if (!file_exists($db)) { file_put_contents($db, json_encode([])); }
$proxies = json_decode(file_get_contents($db), true) ?: [];

// Stats logic
$stats = ['total' => count($proxies), 'stealth' => 0, 'direct' => 0];
foreach ($proxies as $p) {
    ($p['modo'] ?? 'direct') === 'stealth' ? $stats['stealth']++ : $stats['direct']++;
}

$msgSuccess = $_SESSION['sucesso'] ?? '';
$msgError = $_SESSION['erro'] ?? '';
unset($_SESSION['sucesso'], $_SESSION['erro']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MAGO V3 - ELITE DASHBOARD</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-deep: #030008;
            --bg-card: rgba(12, 1, 28, 0.85);
            --bg-table-dark: #120122; /* ROXO ESCURO MAS NÃO MUITO */
            --neon-purple: #a855f7;
            --neon-pink: #e879f9;
            --neon-green: #22c55e;
            --neon-white: #ffffff;
            --glass-border: rgba(168, 85, 247, 0.3);
        }

        body {
            background-color: var(--bg-deep);
            background-image: 
                radial-gradient(circle at 5% 5%, rgba(124, 58, 237, 0.15) 0%, transparent 35%),
                radial-gradient(circle at 95% 95%, rgba(232, 121, 249, 0.1) 0%, transparent 35%);
            color: #f1f5f9;
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* 🧙‍♂️ SCROLLBAR NEON */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg-deep); }
        ::-webkit-scrollbar-thumb { background: var(--neon-purple); border-radius: 10px; }

        .glass-card {
            background: var(--bg-card);
            backdrop-filter: blur(15px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.6);
            transition: 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* 🎨 DESIGN DA TABELA (ROXO ESCURO) */
        .table-card {
            background: var(--bg-table-dark) !important;
            border-color: rgba(168, 85, 247, 0.4);
        }

        /* 🎨 HEADER & BRANDING */
        .navbar-custom { background: rgba(5, 1, 15, 0.95); border-bottom: 2px solid var(--glass-border); padding: 1.2rem 2.5rem; }
        .brand-neon {
            font-weight: 900; letter-spacing: 3px; text-transform: uppercase;
            background: linear-gradient(to right, #fff, var(--neon-purple), var(--neon-pink));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 0 10px rgba(168, 85, 247, 0.5));
        }

        /* 🪄 FORMULÁRIO E DESTAQUE VERDE */
        .form-control, .form-select { 
            background: rgba(0, 0, 0, 0.3); border: 1px solid var(--glass-border); 
            color: #fff; border-radius: 12px; padding: 12px 18px;
        }
        
        .neon-text-green { 
            color: var(--neon-green) !important; 
            text-shadow: 0 0 10px rgba(34, 197, 94, 0.6); 
            font-weight: 900; 
        }

        /* 📊 TABELA TECH */
        .table-custom { border-collapse: separate; border-spacing: 0 12px; }
        .table-custom tbody tr { background: rgba(255, 255, 255, 0.03); transition: 0.3s; border-radius: 15px; }
        .table-custom tbody tr:hover { background: rgba(168, 85, 247, 0.1); transform: scale(1.01); }
        .table-custom td { padding: 20px 15px; border: none; vertical-align: middle; }
        
        .id-badge {
            background: rgba(168, 85, 247, 0.15); color: var(--neon-purple);
            padding: 6px 14px; border-radius: 10px; font-weight: 900; border: 1px solid var(--neon-purple);
        }

        /* 🔒 MASCARA DE IP */
        .ip-mask { filter: blur(6px); transition: all 0.5s ease; opacity: 0.4; }
        .ip-mask.revealed { filter: blur(0); opacity: 1; }

        /* 👁️ BOTÃO OLHINHO */
        .btn-eye-neon {
            background: rgba(232, 121, 249, 0.1); border: 1px solid var(--neon-pink);
            color: var(--neon-pink); width: 38px; height: 38px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; cursor: pointer;
            transition: 0.3s;
        }
        .btn-eye-neon:hover { background: var(--neon-pink); color: white; box-shadow: 0 0 20px var(--neon-pink); }
        .btn-eye-neon.active { background: var(--neon-purple); border-color: var(--neon-purple); color: white; }

        /* 🚀 BOTÕES GERAIS */
        .btn-neon {
            background: linear-gradient(135deg, #7c3aed, #db2777); border: none; color: #fff; font-weight: 900;
            box-shadow: 0 0 20px rgba(124, 58, 237, 0.4); border-radius: 12px;
        }
        .btn-neon:hover { transform: scale(1.05); color: #fff; box-shadow: 0 0 30px rgba(124, 58, 237, 0.6); }

        .status-pill { padding: 5px 15px; border-radius: 50px; font-size: 0.65rem; font-weight: 900; text-transform: uppercase; border: 1px solid currentColor; }

        /* TELEGRAM 3D */
        .mago-telegram-float {
            position: fixed; bottom: 40px; right: 40px; width: 70px; height: 70px;
            background: linear-gradient(145deg, #0088cc, #00aaff); border-radius: 22px;
            display: flex; align-items: center; justify-content: center;
            color: white !important; font-size: 35px; z-index: 9999;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.5), 0 0 25px rgba(0, 136, 204, 0.6);
            transition: 0.5s; transform-style: preserve-3d;
        }
        .mago-telegram-float:hover { transform: translateY(-15px) rotateY(20deg); box-shadow: 0 25px 40px rgba(0, 0, 0, 0.6); }
    </style>
</head>
<body>

    <nav class="navbar navbar-custom sticky-top mb-5">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <i class="fas fa-hat-wizard me-3 fs-2 text-white"></i>
                <span class="brand-neon fs-3">MAGO GATEWAY V3</span>
            </a>
            <div class="d-flex align-items-center gap-4">
                <span class="small fw-bold text-white-50"><i class="fas fa-crown me-2 text-warning"></i><?php echo $_SESSION['mago_usuario']; ?></span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-4 fw-bold">ENCERRAR</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-md-5">
        <div class="row g-4 mb-5">
            <div class="col-md-3"><div class="glass-card p-4 text-center"><div class="stat-val"><?php echo $stats['total']; ?></div><p class="stat-label">Proxies Ativos</p></div></div>
            <div class="col-md-3"><div class="glass-card p-4 text-center"><div class="stat-val text-danger"><?php echo $stats['stealth']; ?></div><p class="stat-label text-danger">Modo Stealth</p></div></div>
            <div class="col-md-3"><div class="glass-card p-4 text-center"><div class="stat-val text-info"><?php echo $stats['direct']; ?></div><p class="stat-label text-info">Modo Direct</p></div></div>
            <div class="col-md-3"><div class="glass-card p-4 text-center border-success"><div class="stat-val text-success"><i class="fas fa-shield-check"></i></div><p class="stat-label text-success">Cloudflare API</p></div></div>
        </div>

        <div class="glass-card p-5 mb-5">
            <h4 class="mb-5 fw-900"><i class="fas fa-plus-square me-3 text-purple"></i> DESDOBRAR NOVO GATEWAY</h4>
            <form action="processa.php" method="POST" class="row g-4">
                <div class="col-md-4">
                    <label class="form-label">SUBDOMÍNIO DESEJADO</label>
                    <input type="text" class="form-control" name="dominio" placeholder="Ex: elite" required>
                    <div class="mt-2 small fw-bold text-white-50">Link: <span class="neon-text-green">cinema.<?php echo BASE_DOMAIN; ?></span></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">FONTE ORIGINAL (IP:PORTA)</label>
                    <input type="text" class="form-control" name="ip_xui" placeholder="Ex: 1.1.1.1:80" required>
                    <div class="mt-2 small fw-bold text-white-50">Exemplo: <span class="neon-text-green">38.147.106.155:80</span></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">PROTOCOLO</label>
                    <select class="form-select" name="modo">
                        <option value="stealth" class="bg-dark">🔒 STEALTH (PROTEÇÃO)</option>
                        <option value="direct" class="bg-dark">🔗 DIRECT (REDIRECIONAR)</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-neon w-100 py-3">CRIAR PROXY</button>
                </div>
            </form>
        </div>

        <div class="glass-card table-card p-5 mb-5">
            <h4 class="mb-4 fw-900"><i class="fas fa-network-wired me-3 text-pink"></i> INFRAESTRUTURA CONFIGURADA</h4>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th width="80"># ID</th>
                            <th>DOMÍNIO GATEWAY</th>
                            <th>SERVIDOR DE DESTINO</th>
                            <th width="150">MODO</th>
                            <th width="120">STATUS</th>
                            <th width="150" class="text-center">AÇÕES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proxies as $id => $p): 
                            $modo = $p['modo'] ?? 'direct';
                        ?>
                        <tr>
                            <td><span class="id-badge"><?php echo str_pad($id + 1, 2, '0', STR_PAD_LEFT); ?></span></td>
                            <td>
                                <div class="d-flex align-items-center justify-content-between bg-dark rounded-3 px-3 py-2 border border-secondary">
                                    <span class="fw-bold text-white" id="dom-<?php echo $id; ?>"><?php echo htmlspecialchars($p['dominio']) . '.' . BASE_DOMAIN; ?></span>
                                    <button class="btn btn-sm btn-link text-purple p-0" onclick="copyText('dom-<?php echo $id; ?>', this)"><i class="fas fa-copy"></i></button>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex align-items-center justify-content-between bg-dark rounded-3 px-3 py-2 border border-secondary">
                                    <span class="fw-bold text-white-50 ip-mask me-2" id="ip-<?php echo $id; ?>"><?php echo htmlspecialchars($p['ip_xui']); ?></span>
                                    <div class="d-flex gap-2">
                                        <button class="btn-eye-neon" onclick="toggleIP('ip-<?php echo $id; ?>', this)"><i class="fas fa-eye"></i></button>
                                        <button class="btn btn-sm btn-link text-pink p-0" onclick="copyText('ip-<?php echo $id; ?>', this)"><i class="fas fa-copy"></i></button>
                                    </div>
                                </div>
                            </td>
                            <td><span class="status-pill <?php echo ($modo === 'stealth' ? 'text-danger' : 'text-info'); ?>"><?php echo strtoupper($modo); ?></span></td>
                            <td><span class="status-pill text-success">ONLINE</span></td>
                            <td class="text-center">
                                <a href="excluir.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-danger border-0" onclick="return confirm('Destruir?')"><i class="fas fa-trash-alt"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <a href="https://t.me/MagoPD" target="_blank" class="mago-telegram-float"><i class="fab fa-telegram-plane"></i></a>

    <script>
        function toggleIP(id, btn) {
            const ipSpan = document.getElementById(id);
            const icon = btn.querySelector('i');
            ipSpan.classList.toggle('revealed');
            btn.classList.toggle('active');
            icon.className = ipSpan.classList.contains('revealed') ? 'fas fa-eye-slash' : 'fas fa-eye';
        }

        async function copyText(id, btn) {
            const text = document.getElementById(id).textContent;
            try {
                if (navigator.clipboard) { await navigator.clipboard.writeText(text); }
                const oldIcon = btn.innerHTML; btn.innerHTML = '<i class="fas fa-check text-success"></i>';
                setTimeout(() => { btn.innerHTML = oldIcon; }, 1500);
            } catch (err) { alert('Erro ao copiar!'); }
        }
    </script>
</body>
</html>