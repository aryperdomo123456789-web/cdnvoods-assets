<?php

/**
 * Anti-embaralhamento de usuário.
 *
 * O risco a eliminar: entra `get.php?username=A&password=SENHA_A` e a resposta
 * reescrita contém URLs com `username=B`. Isso significaria conteúdo do usuário
 * errado saindo para o assinante.
 *
 * Como funciona:
 *  - arm() guarda o username esperado do request atual;
 *  - inspect() é chamado pelo rewriter em cada linha que contenha "username=";
 *  - se aparecer um username diferente, marca `tripped` e o proxy ABORTA a
 *    entrega (502), registra auditoria crítica e marca a inconsistência no
 *    evento do request.
 *
 * Custo: um strpos por linha. Só é armado em rotas textuais com credencial.
 */
final class CredentialGuard
{
    private static string $expected = '';
    private static bool $armed = false;
    private static bool $tripped = false;
    private static string $observed = '';
    private static int $checked = 0;

    public static function arm(string $expectedUsername): void
    {
        self::$expected = $expectedUsername;
        self::$armed = $expectedUsername !== '';
        self::$tripped = false;
        self::$observed = '';
        self::$checked = 0;
    }

    public static function disarm(): void
    {
        self::$armed = false;
    }

    public static function armed(): bool
    {
        return self::$armed;
    }

    public static function inspect(string $line): void
    {
        if (!self::$armed || self::$tripped) {
            return;
        }
        if (stripos($line, 'username') === false) {
            return;
        }
        if (!preg_match_all('/[?&"\']username["\']?\s*[=:]\s*["\']?([^&"\'\s,}\\\\]+)/i', $line, $m)) {
            return;
        }
        foreach ($m[1] as $found) {
            self::$checked++;
            $found = rawurldecode($found);
            if ($found === '' || $found === self::$expected) {
                continue;
            }
            self::$tripped = true;
            self::$observed = substr($found, 0, 120);
            return;
        }
    }

    public static function tripped(): bool
    {
        return self::$tripped;
    }

    public static function checked(): int
    {
        return self::$checked;
    }

    public static function expected(): string
    {
        return self::$expected;
    }

    public static function observed(): string
    {
        return self::$observed;
    }

    public static function reason(): string
    {
        return self::$tripped ? 'invalid_credentials_swap' : '';
    }
}