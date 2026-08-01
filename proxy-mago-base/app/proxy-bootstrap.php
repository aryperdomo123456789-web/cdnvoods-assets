<?php

declare(strict_types=1);

/**
 * Bootstrap MÍNIMO do caminho de proxy.
 *
 * O bootstrap completo (app/bootstrap.php) abre sessão, carrega Auth,
 * NginxGenerator, HealthCheck etc. Nada disso é necessário para servir um
 * segmento .ts e cada request de player pagaria esse custo em CPU/RAM.
 * Aqui carregamos só o essencial e NUNCA iniciamos sessão.
 */

date_default_timezone_set('America/Sao_Paulo');

require_once dirname(__DIR__) . '/app/Config.php';
require_once dirname(__DIR__) . '/app/Sql.php';
require_once dirname(__DIR__) . '/app/Database.php';
require_once dirname(__DIR__) . '/app/SettingsRepository.php';
require_once dirname(__DIR__) . '/app/Audit.php';
require_once dirname(__DIR__) . '/app/OriginRepository.php';
require_once dirname(__DIR__) . '/app/AliasRepository.php';
require_once dirname(__DIR__) . '/app/Cache.php';
// Estado vivo (Fase 2): entra ANTES de CdnSession porque o caminho quente
// consulta contador/sessão por aqui quando state_driver=redis.
require_once dirname(__DIR__) . '/app/RedisClient.php';
require_once dirname(__DIR__) . '/app/StateStore.php';

require_once dirname(__DIR__) . '/app/Tokens.php';
require_once dirname(__DIR__) . '/app/AccessGuard.php';
require_once dirname(__DIR__) . '/app/RequestContext.php';
require_once dirname(__DIR__) . '/app/RequestLog.php';
require_once dirname(__DIR__) . '/app/CredentialGuard.php';
require_once dirname(__DIR__) . '/app/CdnSession.php';
require_once dirname(__DIR__) . '/app/AuditTimeline.php';
require_once dirname(__DIR__) . '/app/UserIpLock.php';
// Roteamento por usuário no caminho quente: só 1 SELECT indexado (LbRouter::decide).
require_once dirname(__DIR__) . '/app/LbRouter.php';
require_once dirname(__DIR__) . '/app/DirectSourceParser.php';
require_once dirname(__DIR__) . '/app/DirectCatalog.php';
require_once dirname(__DIR__) . '/app/DirectSource.php';
require_once dirname(__DIR__) . '/app/XuiSeriesCompat.php';
require_once dirname(__DIR__) . '/app/Divergence.php';
require_once dirname(__DIR__) . '/app/PlaylistRewriter.php';
require_once dirname(__DIR__) . '/app/PlayerApiLocal.php';
require_once dirname(__DIR__) . '/app/StreamProxy.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/storage/logs/php-error.log');

// Nunca bufferizar: streaming precisa sair conforme chega.
while (ob_get_level() > 0) {
    @ob_end_clean();
}
@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
@set_time_limit(0);
ignore_user_abort(false);
