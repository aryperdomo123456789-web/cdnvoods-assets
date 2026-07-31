<?php

/**
 * Origem XUI única do sistema.
 *
 * Este painel protege UM xui. Portanto existe uma única origem interna
 * (IP ou DNS do main) e vários hostnames públicos apontando para ela.
 * O id da origem fica em settings.xui_origin_id — nunca sai para o público.
 */
final class XuiOrigin
{
    private const KEY = 'xui_origin_id';

    public static function get(): ?array
    {
        $id = (int) SettingsRepository::get(self::KEY, 0);
        if ($id > 0) {
            $o = OriginRepository::find($id);
            if ($o) {
                return $o;
            }
        }
        // Fallback: primeira origem existente (bases criadas antes desta versão).
        $all = OriginRepository::all();
        if ($all) {
            SettingsRepository::set(self::KEY, (int) $all[0]['id']);
            return $all[0];
        }
        return null;
    }

    public static function id(): ?int
    {
        $o = self::get();
        return $o ? (int) $o['id'] : null;
    }

    /**
     * Cria ou atualiza a origem XUI. $type: 'a' (IP) ou 'cname' (DNS do main).
     * Em CNAME o Host header vai igual ao destino: o XUI só responde ao vhost dele.
     */
    public static function save(string $type, string $target, int $port): int
    {
        $type = $type === 'cname' ? 'cname' : 'a';
        $data = [
            'name'        => 'XUI',
            'host'        => $target,
            'port'        => $port,
            'scheme'      => $port === 443 ? 'https' : 'http',
            'base_path'   => '',
            'host_header' => $type === 'cname' ? $target : '',
            'extra_hosts' => '',
            'active'      => true,
            'type'        => $type,
        ];

        $current = self::get();
        if ($current) {
            OriginRepository::update((int) $current['id'], $data + [
                'auth_user' => (string) ($current['auth_user'] ?? ''),
                'auth_pass' => (string) ($current['auth_pass'] ?? ''),
            ]);
            $id = (int) $current['id'];
        } else {
            $data['auth_user'] = '';
            $data['auth_pass'] = '';
            $id = OriginRepository::create($data);
        }

        SettingsRepository::set(self::KEY, $id);

        // Todos os domínios de proteção seguem a mesma origem.
        $stmt = Database::pdo()->prepare('UPDATE aliases SET origin_id = :id, updated_at = :now');
        $stmt->execute([':id' => $id, ':now' => date('c')]);

        return $id;
    }
}