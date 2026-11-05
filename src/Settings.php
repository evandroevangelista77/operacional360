<?php

namespace GlpiPlugin\Operacional360;

use Session;

/**
 * Configuração do plugin: não há tela de configuração. Os acessos (Zabbix e GLPI da OAK) são os
 * MESMOS do NOC 360: o plugin lê /etc/datacenter360/config.php (e o que o NOC 360 salvou em
 * /var/lib/datacenter360/config.json). Os clientes e os host groups são escolhidos no próprio painel.
 */
class Settings
{
    public const CONTEXT = 'plugin:operacional360';

    /** Valores padrão (texto, como o Config do GLPI guarda). */
    public const DEFAULT_ZABBIX_URL = 'https://noc-lnx-zab-01.eastus.cloudapp.azure.com/zabbix-oakmont/api_jsonrpc.php';
    public const DEFAULT_GLPI_API = 'https://itsm.oakmontgroup.com.br/apirest.php';
    public const DEFAULT_ROOT = 'OAKMONT > TI > NOC';

    public const DEFAULTS = [
        'zabbix_url'       => self::DEFAULT_ZABBIX_URL,
        'zabbix_token'     => '',
        'zabbix_verify'    => '1',
        'zabbix_front'     => '',
        'glpi_api_url'     => self::DEFAULT_GLPI_API,
        'glpi_app_token'   => '',
        'glpi_user_token'  => '',
        'glpi_verify'      => '1',
        'entity_root'      => self::DEFAULT_ROOT,
        'viewers'          => '',
        'glpi_front'       => '',
        'severities'       => '5,4,2',
        'sla_hours'        => '6:1,5:2,4:4,3:8,2:24,1:48',
        'list_max'         => '400',
        'glpi_max_tickets' => '8000',
        'cache_seconds'    => '20',
    ];

    /** Rótulos das severidades do Zabbix. */
    public const SEVERITY_LABELS = [
        5 => 'Desastre', 4 => 'Alta', 3 => 'Média', 2 => 'Atenção', 1 => 'Informação', 0 => 'Não classificada',
    ];

    /** Tipos de alerta do navegador: [rótulo, grupo, ícone, cor, canais padrão, som padrão]. */
    public const ALERT_TYPES = [
        'zbx_5'        => ['Desastre', 'Zabbix', 'ti-flame', '#e45959', ['flag', 'sound', 'popup', 'voice', 'sticky'], 'sirene'],
        'zbx_4'        => ['Alta', 'Zabbix', 'ti-alert-octagon', '#e97659', ['flag', 'sound', 'popup', 'voice', 'sticky'], 'duplo'],
        'zbx_3'        => ['Média', 'Zabbix', 'ti-alert-triangle', '#ffa059', ['flag', 'popup', 'voice'], 'suave'],
        'zbx_2'        => ['Atenção', 'Zabbix', 'ti-alert-circle', '#ffc859', ['flag', 'popup', 'voice'], 'suave'],
        'zbx_1'        => ['Informação', 'Zabbix', 'ti-info-circle', '#7499ff', [], 'suave'],
        'zbx_0'        => ['Não classificada', 'Zabbix', 'ti-help-circle', '#97aab3', [], 'suave'],
        'zbx_ok'       => ['Problema resolvido', 'Zabbix', 'ti-circle-check', '#22c55e', ['popup', 'voice'], 'sino'],
        'glpi_new'     => ['Chamado novo', 'GLPI', 'ti-ticket', '#3769B2', ['flag', 'sound', 'popup', 'voice'], 'sino'],
        'glpi_sla1h'   => ['SLA vence em até 1 h', 'GLPI', 'ti-hourglass-high', '#f59e0b', ['flag', 'sound', 'popup', 'voice'], 'duplo'],
        'glpi_slalate' => ['SLA vencido', 'GLPI', 'ti-hourglass-empty', '#ef4444', ['flag', 'sound', 'popup', 'voice', 'sticky'], 'sirene'],
    ];

    /** Arquivos do NOC 360 (acessos compartilhados); o 2º só existe depois que alguém salva ajustes na tela do NOC 360. */
    public const NOC360_CONFIG = '/etc/datacenter360/config.php';
    public const NOC360_OVERRIDES = '/var/lib/datacenter360/config.json';

    private static ?array $cache = null;
    private static ?array $noc = null;

    /** Configuração do NOC 360 (config.php + ajustes salvos), ou [] se este servidor não tem o NOC 360. */
    public static function noc360(): array
    {
        if (self::$noc === null) {
            $cfg = [];
            if (is_readable(self::NOC360_CONFIG)) {
                $r = @include self::NOC360_CONFIG;
                $cfg = is_array($r) ? $r : [];
            }
            if (is_readable(self::NOC360_OVERRIDES)) {
                $j = json_decode((string) @file_get_contents(self::NOC360_OVERRIDES), true);
                foreach (is_array($j) ? $j : [] as $k => $v) {
                    if ($v !== null && $v !== '') {
                        $cfg[$k] = $v;
                    }
                }
            }
            self::$noc = $cfg;
        }
        return self::$noc;
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            $n = self::noc360();
            $rest = is_array($n['glpi_rest'] ?? null) ? $n['glpi_rest'] : [];
            $v = self::DEFAULTS;
            $map = [
                'zabbix_url'      => $n['zabbix_url'] ?? null,
                'zabbix_token'    => $n['zabbix_token'] ?? null,
                'zabbix_front'    => $n['zabbix_front'] ?? null,
                'zabbix_verify'   => isset($n['zabbix_verify']) ? ($n['zabbix_verify'] ? '1' : '0') : null,
                'glpi_api_url'    => $rest['url'] ?? null,
                'glpi_app_token'  => $rest['app_token'] ?? null,
                'glpi_user_token' => $rest['user_token'] ?? null,
                'glpi_front'      => $n['glpi_front'] ?? null,
                'entity_root'     => $n['glpi_entity_prefix'] ?? null,
                'severities'      => isset($n['severities']) ? implode(',', (array) $n['severities']) : null,
                'sla_hours'       => isset($n['sla_hours']) ? implode(',', array_map(fn ($p, $h) => "$p:$h", array_keys((array) $n['sla_hours']), (array) $n['sla_hours'])) : null,
                'list_max'        => $n['list_max'] ?? null,
                'glpi_max_tickets' => $n['glpi_max_tickets'] ?? null,
                'cache_seconds'   => isset($n['cache_ttl']) ? min(600, (int) $n['cache_ttl']) : null,
            ];
            foreach ($map as $k => $val) {
                if ($val !== null && $val !== '') {
                    $v[$k] = (string) $val;
                }
            }
            self::$cache = $v;
        }
        return self::$cache;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? '';
    }

    public static function int(string $key, int $min, int $max): int
    {
        return max($min, min($max, (int) self::get($key)));
    }

    public static function isConfigured(): bool
    {
        return self::get('zabbix_url') !== '' && self::get('zabbix_token') !== '';
    }

    /** Severidades exibidas no painel (padrão: Desastre, Alta e Atenção). */
    public static function severities(): array
    {
        $out = [];
        foreach (explode(',', self::get('severities')) as $v) {
            $v = trim($v);
            if (ctype_digit($v) && isset(self::SEVERITY_LABELS[(int) $v])) {
                $out[(int) $v] = (int) $v;
            }
        }
        $out = array_values($out);
        rsort($out);
        return $out ?: [5, 4, 2];
    }

    /** Meta de solução por prioridade do GLPI (horas), no formato "prioridade:horas,...". */
    public static function slaHours(): array
    {
        $out = [];
        foreach (explode(',', self::get('sla_hours')) as $pair) {
            [$p, $h] = array_pad(explode(':', trim($pair), 2), 2, '');
            if (ctype_digit($p) && ctype_digit($h)) {
                $out[(int) $p] = (int) $h;
            }
        }
        return $out ?: [6 => 1, 5 => 2, 4 => 4, 3 => 8, 2 => 24, 1 => 48];
    }

    /** Usuários (IDs) que veem o painel. Vazio = todo perfil com o direito de ver. */
    public static function viewerIds(): array
    {
        $out = [];
        foreach (explode(',', self::get('viewers')) as $v) {
            $v = trim($v);
            if (ctype_digit($v)) {
                $out[] = (int) $v;
            }
        }
        return array_values(array_unique($out));
    }

    /** O usuário logado pode ver o painel: tem o direito e, se houver lista, está nela. */
    public static function canView(): bool
    {
        if (!Session::haveRight(Menu::$rightname, READ)) {
            return false;
        }
        $ids = self::viewerIds();
        return !$ids || in_array((int) Session::getLoginUserID(), $ids, true);
    }

    /** Entidade raiz do painel: só ela e as abaixo dela (NOC para baixo) podem entrar no mapa. */
    public static function entityRoot(): string
    {
        return Glpi::norm(self::get('entity_root'));
    }

    /** A entidade está na raiz configurada (ela mesma ou uma filha). */
    public static function inEntityRoot(string $fullName): bool
    {
        $root = self::entityRoot();
        return $root === '' || $fullName === $root || str_starts_with($fullName, $root . ' > ');
    }

    /** Endereço do Zabbix para os links (padrão: o endereço da API sem "api_jsonrpc.php"). */
    public static function zabbixFront(): string
    {
        $front = self::get('zabbix_front');
        if ($front === '') {
            $front = preg_replace('#/api_jsonrpc\.php$#', '', self::get('zabbix_url'));
        }
        return rtrim((string) $front, '/');
    }

    /** Endereço do GLPI para os links de chamado (padrão: o GLPI da API configurada, ou o do próprio GLPI). */
    public static function glpiFront(): string
    {
        global $CFG_GLPI;
        $front = self::get('glpi_front');
        if ($front === '' && GlpiRest::enabled()) {
            $front = preg_replace('#/apirest\.php/?$#', '', self::get('glpi_api_url'));
        }
        if ($front === '' || $front === null) {
            $front = $CFG_GLPI['url_base'] ?? $CFG_GLPI['root_doc'] ?? '';
        }
        return rtrim((string) $front, '/');
    }

    /** Alertas do navegador (canais, som, voz e pop-up), com o que foi salvo sobre o padrão. */
    public static function alerts(): array
    {
        $saved = [];
        $tipos = [];
        foreach (self::ALERT_TYPES as $key => [, , , , $canais, $tom]) {
            $o = $saved['tipos'][$key] ?? [];
            $tipos[$key] = [
                'channels' => is_array($o['channels'] ?? null) ? array_values(array_intersect($o['channels'], ['flag', 'sound', 'popup', 'voice', 'sticky'])) : $canais,
                'tone'     => is_string($o['tone'] ?? null) ? $o['tone'] : $tom,
            ];
        }
        return [
            'tipos'           => $tipos,
            'poll'            => (int) ($saved['poll'] ?? 20),
            'volume'          => (int) ($saved['volume'] ?? 80),
            'voice_rate'      => (float) ($saved['voice_rate'] ?? 1.05),
            'popup_seconds'   => (int) ($saved['popup_seconds'] ?? 20),
            'popup_max'       => (int) ($saved['popup_max'] ?? 5),
            'mute_minutes'    => (int) ($saved['mute_minutes'] ?? 60),
            'sla_warn_minutes' => (int) ($saved['sla_warn_minutes'] ?? 60),
            'quiet'           => $saved['quiet'] ?? null,
        ];
    }
}
