<?php

/**
 * Reescrita de playlists para esconder host/porta/credenciais da origem.
 *
 * Regra: qualquer URL absoluta que apareça na playlist e aponte para a origem
 * (host:porta do XUI) é reescrita para https://<alias-publico>/<mesmo-path>?t=<token>.
 * URLs de outros hosts (raro em XUI) NÃO são reescritas — evitamos vazar
 * credenciais em playlists de terceiros por engano.
 */
final class PlaylistRewriter
{
    public static function rewrite(string $body, array $origin, string $publicHost, string $token): string
    {
        $originHost = $origin['host'];
        $originPort = (int) $origin['port'];
        $originScheme = $origin['scheme'];
        // Variações possíveis do host absoluto na playlist:
        $needles = [
            sprintf('%s://%s:%d', $originScheme, $originHost, $originPort),
            sprintf('%s://%s', $originScheme, $originHost),
        ];
        // O painel opera atrás de Cloudflare com TLS na borda, então servimos como https.
        $publicBase = 'https://' . $publicHost;
        $tokenQs = 't=' . rawurlencode($token);

        $rewritten = $body;
        foreach ($needles as $n) {
            $rewritten = str_replace($n, $publicBase, $rewritten);
        }

        // Também limpa credenciais que aparecem via ?username=&password= — trocamos
        // por ?u=REDACTED&p=REDACTED antes de anexar o token, de qualquer forma
        // o proxy do lado servidor usa o token, não esses parâmetros.
        $rewritten = preg_replace('/([?&])username=[^&\r\n"]*/i', '$1username=', $rewritten);
        $rewritten = preg_replace('/([?&])password=[^&\r\n"]*/i', '$1password=', $rewritten);

        // Anexa token em cada linha que aponta para o publicBase e ainda não tem t=.
        $lines = preg_split('/(\r\n|\n|\r)/', $rewritten, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        foreach ($lines as $line) {
            if ($line === '' || $line[0] === '#' || preg_match('/^(\r\n|\n|\r)$/', $line)) {
                $out .= $line;
                continue;
            }
            if (stripos($line, $publicBase) === 0 && stripos($line, 't=') === false) {
                $sep = strpos($line, '?') === false ? '?' : '&';
                $line .= $sep . $tokenQs;
            }
            $out .= $line;
        }
        return $out;
    }
}
