<?php

namespace GlpiPlugin\Operacional360;

use RuntimeException;

/**
 * Cliente de leitura da API REST de um GLPI remoto (o GLPI da OAK), como o NOC 360 faz.
 * Só GET: o painel nunca altera nada lá. Os tokens ficam cifrados no GLPI local e não vão ao navegador.
 *
 * Com o endereço e o token de usuário preenchidos na configuração, os chamados e as entidades
 * vêm daqui; sem eles, o plugin lê o banco do próprio GLPI onde está instalado.
 */
class GlpiRest
{
    /** Opções de pesquisa do chamado (estáveis no GLPI): título, id, prioridade, técnico, categoria, grupo, status, abertura, solução, prazo, entidade. */
    private const F = ['name' => 1, 'id' => 2, 'priority' => 3, 'tech' => 5, 'category' => 7, 'group' => 8, 'status' => 12,
        'date' => 15, 'solvedate' => 17, 'ttr' => 18, 'entity' => 80];

    private static ?string $session = null;

    public static function enabled(): bool
    {
        return Settings::get('glpi_api_url') !== '' && Settings::get('glpi_user_token') !== '';
    }

    /** Endereço da API sem a barra final e com "/apirest.php" garantido. */
    private static function base(): string
    {
        $url = rtrim(Settings::get('glpi_api_url'), '/');
        return str_ends_with($url, '/apirest.php') ? $url : $url . '/apirest.php';
    }

    /** Uma leitura (GET). Abre a sessão na primeira chamada e a encerra ao fim da requisição. */
    public static function get(string $path, array $params = []): mixed
    {
        if (!self::enabled()) {
            throw new HttpError(503, 'Conexão com o GLPI da OAK não configurada (Configurar → Plugins → Operacional 360).');
        }
        if (self::$session === null) {
            self::open();
        }
        return self::call($path, $params, ['Session-Token: ' . self::$session]);
    }

    private static function open(): void
    {
        $r = self::call('/initSession', [], ['Authorization: user_token ' . Settings::get('glpi_user_token')]);
        if (!is_array($r) || empty($r['session_token'])) {
            throw new RuntimeException('GLPI: não foi possível abrir a sessão da API.');
        }
        self::$session = (string) $r['session_token'];
        register_shutdown_function(static function (): void {
            if (self::$session !== null) {
                try {
                    self::call('/killSession', [], ['Session-Token: ' . self::$session]);
                } catch (\Throwable) {
                }
                self::$session = null;
            }
        });
        self::call('/changeActiveEntities', ['entities_id' => 'all', 'is_recursive' => 'true'], ['Session-Token: ' . self::$session]);
    }

    private static function call(string $path, array $params, array $headers): mixed
    {
        $headers[] = 'Content-Type: application/json';
        if (Settings::get('glpi_app_token') !== '') {
            $headers[] = 'App-Token: ' . Settings::get('glpi_app_token');
        }
        $verify = Settings::get('glpi_verify') !== '0';
        $ch = curl_init(self::base() . $path . ($params ? '?' . http_build_query($params) : ''));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('GLPI inacessível: ' . curl_error($ch));
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($code >= 400) {
            throw new RuntimeException("GLPI $path: HTTP $code " . mb_substr((string) $raw, 0, 200));
        }
        return json_decode((string) $raw, true);
    }

    /* ------------------------------------------------------------------ leituras */

    /**
     * id => nome completo da entidade (sem o prefixo "Entidade raiz").
     * Usa /getMyEntities (as entidades que o perfil alcança, com o nome completo) em vez de /Entity: assim o usuário
     * de serviço não precisa do direito "Entidades" no perfil (sem ele, /Entity responde 403 ERROR_RIGHT_MISSING).
     */
    public static function entities(): array
    {
        return Cache::remember('rest:entities:' . Settings::get('glpi_api_url'), 600, function () {
            $out = [];
            foreach (self::myEntities() as $e) {
                $out[(int) $e['id']] = Glpi::norm((string) ($e['completename'] ?? $e['name'] ?? ''));
            }
            return $out;
        });
    }

    /** Entidades que o usuário da API enxerga, com a subárvore (GET /getMyEntities). */
    private static function myEntities(): array
    {
        $r = self::get('/getMyEntities', ['is_recursive' => 'true']);
        return is_array($r) && is_array($r['myentities'] ?? null) ? $r['myentities'] : [];
    }

    /** Opções de pesquisa do chamado que variam entre versões (tempos de atendimento/solução): table.field => id. */
    private static function optionIds(): array
    {
        return Cache::remember('rest:options:' . Settings::get('glpi_api_url'), 86400, function () {
            $out = [];
            foreach (self::get('/listSearchOptions/Ticket') ?? [] as $id => $o) {
                if (is_array($o) && isset($o['table'], $o['field']) && ctype_digit((string) $id)) {
                    $out[$o['table'] . '.' . $o['field']] ??= (int) $id;
                }
            }
            return $out;
        });
    }

    /** Chamados da entidade raiz e das filhas (abertos agora ou abertos no período), já no formato do painel. */
    public static function tickets(int $rootId, int $from, int $till, bool $open, int $max): array
    {
        $key = 'rest:tickets:' . md5(Settings::get('glpi_api_url') . "|$rootId|$from|$till|" . (int) $open . "|$max");
        return Cache::remember($key, Settings::int('cache_seconds', 0, 600), function () use ($rootId, $from, $till, $open, $max) {
            $crit = [['field' => self::F['entity'], 'searchtype' => 'under', 'value' => $rootId]];
            if ($open) {
                $crit[] = ['link' => 'AND', 'field' => self::F['status'], 'searchtype' => 'equals', 'value' => 'notold'];
            } else {
                $crit[] = ['link' => 'AND', 'field' => self::F['date'], 'searchtype' => 'morethan', 'value' => date('Y-m-d H:i:s', $from)];
                $crit[] = ['link' => 'AND', 'field' => self::F['date'], 'searchtype' => 'lessthan', 'value' => date('Y-m-d H:i:s', $till)];
            }
            return self::search($crit, $max);
        });
    }

    /** Um chamado pelo número, só se estiver na entidade raiz ou abaixo dela. */
    public static function ticketIn(int $id, int $rootId): ?array
    {
        $crit = [['field' => self::F['entity'], 'searchtype' => 'under', 'value' => $rootId],
            ['link' => 'AND', 'field' => self::F['id'], 'searchtype' => 'equals', 'value' => $id]];
        return self::search($crit, 1)[0] ?? null;
    }

    private static function search(array $crit, int $max): array
    {
        $opt = self::optionIds();
        $solveDelay = $opt['glpi_tickets.solve_delay_stat'] ?? null;
        $takeDelay = $opt['glpi_tickets.takeintoaccount_delay_stat'] ?? null;
        $fields = array_values(self::F);
        $waitDelay = $opt['glpi_tickets.waiting_duration'] ?? null;
        foreach ([$solveDelay, $takeDelay, $waitDelay] as $f) {
            if ($f !== null) {
                $fields[] = $f;
            }
        }
        $rows = [];
        $page = 500;
        for ($start = 0; $start < $max; $start += $page) {
            $q = ['sort' => self::F['id'], 'order' => 'DESC', 'range' => $start . '-' . (min($max, $start + $page) - 1)];
            foreach ($crit as $i => $c) {
                foreach ($c as $k => $v) {
                    $q["criteria[$i][$k]"] = $v;
                }
            }
            foreach ($fields as $i => $f) {
                $q["forcedisplay[$i]"] = $f;
            }
            $res = self::get('/search/Ticket', $q);
            $data = is_array($res['data'] ?? null) ? $res['data'] : [];
            foreach ($data as $d) {
                $rows[] = self::row($d, $solveDelay, $takeDelay, $waitDelay);
            }
            if (count($data) < $page || count($rows) >= (int) ($res['totalcount'] ?? 0)) {
                break;
            }
        }
        return $rows;
    }

    /** Valor de uma coluna da pesquisa: texto único (lista vira "a, b"). */
    private static function text(mixed $v): string
    {
        return is_array($v) ? implode(', ', array_filter(array_map('strval', $v), fn ($x) => $x !== '')) : (string) ($v ?? '');
    }

    private static function row(array $d, ?int $solveDelay, ?int $takeDelay, ?int $waitDelay = null): array
    {
        $date = (string) ($d[self::F['date']] ?? '');
        $solve = $d[self::F['solvedate']] ?? null;
        $ttr = $d[self::F['ttr']] ?? null;
        $resolve = $solveDelay !== null ? (int) ($d[$solveDelay] ?? 0) : ($solve && $date ? max(0, strtotime((string) $solve) - strtotime($date)) : 0);
        return [
            'id'                         => (int) $d[self::F['id']],
            'name'                       => self::text($d[self::F['name']] ?? ''),
            'status'                     => (int) ($d[self::F['status']] ?? 0),
            'priority'                   => (int) ($d[self::F['priority']] ?? 3),
            'urgency'                    => 0,
            'type'                       => 0,
            'date'                       => $date,
            'solvedate'                  => $solve ?: null,
            'time_to_resolve'            => $ttr ?: null,
            'solve_delay_stat'           => $resolve,
            'takeintoaccount_delay_stat' => $takeDelay !== null ? (int) ($d[$takeDelay] ?? 0) : 0,
            'waiting_duration'           => $waitDelay !== null ? (int) ($d[$waitDelay] ?? 0) : 0,
            'entity'                     => Glpi::norm(self::text($d[self::F['entity']] ?? '')),
            'category'                   => self::text($d[self::F['category']] ?? ''),
            'group'                      => self::text($d[self::F['group']] ?? ''),
            'tech'                       => self::text($d[self::F['tech']] ?? ''),
        ];
    }

    /** Texto (HTML) do conteúdo do chamado. */
    public static function ticketContent(int $id): string
    {
        $t = self::get('/Ticket/' . $id);
        return is_array($t) ? (string) ($t['content'] ?? '') : '';
    }

    /**
     * Histórico do chamado: abertura, acompanhamentos, tarefas e solução (autores já como nomes).
     * @return array<int, array{kind:string,date:string,author:string,private:bool,text:string}>
     */
    public static function history(int $id): array
    {
        $out = [];
        $t = self::get('/Ticket/' . $id, ['expand_dropdowns' => 'true']);
        if (is_array($t)) {
            $out[] = ['kind' => 'abertura', 'date' => (string) ($t['date'] ?? ''), 'author' => (string) ($t['users_id_recipient'] ?? ''), 'private' => false, 'text' => (string) ($t['content'] ?? '')];
        }
        $sources = [
            ['acompanhamento', '/Ticket/' . $id . '/ITILFollowup', 'date_creation', 'is_private'],
            ['tarefa', '/Ticket/' . $id . '/TicketTask', 'date', 'is_private'],
            ['solução', '/Ticket/' . $id . '/ITILSolution', 'date_creation', null],
        ];
        foreach ($sources as [$kind, $path, $dateCol, $privateCol]) {
            try {
                $items = self::get($path, ['expand_dropdowns' => 'true', 'range' => '0-199']) ?? [];
            } catch (RuntimeException) {
                $items = [];
            }
            foreach (is_array($items) ? $items : [] as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $out[] = ['kind' => $kind, 'date' => (string) ($r[$dateCol] ?? ''), 'author' => (string) ($r['users_id'] ?? ''),
                    'private' => $privateCol ? (bool) ($r[$privateCol] ?? false) : false, 'text' => (string) ($r['content'] ?? '')];
            }
        }
        usort($out, fn ($x, $y) => strcmp($x['date'], $y['date']));
        return $out;
    }

    /** Teste da conexão para a tela de configuração: quantas entidades a conta enxerga. */
    public static function test(): int
    {
        return count(self::myEntities());
    }
}
