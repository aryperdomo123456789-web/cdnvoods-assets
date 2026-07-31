<?php

final class Audit
{
    public static function log(string $eventType, string $message, string $clientIp = '-', string $userAgent = '-'): void
    {
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO audit_logs (event_type, client_ip, user_agent, message, created_at)
                 VALUES (:event_type, :client_ip, :user_agent, :message, :created_at)'
            );

            $stmt->execute([
                ':event_type' => $eventType,
                ':client_ip' => $clientIp,
                ':user_agent' => $userAgent,
                ':message' => $message,
                ':created_at' => date('c'),
            ]);
        } catch (Throwable $e) {
            error_log('[audit] log falhou: ' . $e->getMessage());
        }
    }
}
