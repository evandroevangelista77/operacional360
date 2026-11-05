<?php

namespace GlpiPlugin\Operacional360;

use Session;

/**
 * O que o painel mostra: a entidade raiz do NOC 360 (ex.: OAKMONT > TI > NOC) e, abaixo dela,
 * os CLIENTES (uma entidade filha direta = um cliente). Não há tela de configuração: o usuário
 * escolhe no próprio painel os clientes (chamados do GLPI) e os host groups (alertas do Zabbix).
 *
 *  - Host groups: lista do Zabbix; cada um é ligado a um cliente (tabela client_groups do NOC 360 ou
 *    pelo nome). Sem escolha, valem os host groups ligados a algum cliente; com escolha, só os escolhidos.
 *  - Clientes escolhidos filtram chamados e hosts; se nenhum host group foi escolhido, os host groups
 *    ligados a eles entram sozinhos.
 */
class Scope
{
    /**
     * @return array{id:int,name:string,short:string,entity_ids:int[],clients:string[],groups:array,all_groups:array,severities:int[]}
     */
    public static function resolve(array $q = []): array
    {
        $rootName = Settings::entityRoot();
        $rootId = 0;
        foreach (Glpi::entities() as $id => $name) {
            if ($name === $rootName) {
                $rootId = (int) $id;
                break;
            }
        }
        if ($rootId <= 0) {
            throw new HttpError(404, "Entidade raiz '$rootName' não encontrada no GLPI (definida pelo NOC 360: glpi_entity_prefix).");
        }
        $remote = Glpi::remote();
        if (!$remote && !Session::haveAccessToEntity($rootId)) {
            throw new HttpError(403, 'A entidade do painel não está liberada para o seu perfil.');
        }

        // entidades do painel: a raiz e as filhas (no GLPI local, só as liberadas ao perfil)
        $entityIds = array_values(array_filter(Glpi::subtree($rootId), fn ($id) => $remote || Session::haveAccessToEntity($id)));

        // clientes: as filhas diretas da raiz (a própria raiz só aparece se tiver chamados ou hosts)
        $clients = Glpi::directChildren($rootId);

        // host groups do Zabbix e o cliente de cada um
        $sev = Settings::severities();
        $all = self::allGroups($clients);
        $byId = [];
        foreach ($all as $g) {
            $byId[$g['group_id']] = $g;
        }
        $picked = self::parseIds((string) ($q['group'] ?? ''));
        $picked = array_values(array_filter($picked, fn ($id) => isset($byId[$id])));
        if ($picked) {
            $chosen = array_map(fn ($id) => $byId[$id], $picked);
        } else {
            $sel = self::parseClients((string) ($q['client'] ?? ''), $clients);
            $chosen = array_values(array_filter($all, fn ($g) => $g['client'] !== null && (!$sel || in_array($g['client'], $sel, true))));
        }
        $groups = array_map(fn ($g) => $g + ['severities' => $sev], $chosen);

        return [
            'id'         => $rootId,
            'name'       => $rootName,
            'short'      => Glpi::lastSegment($rootName),
            'entity_ids' => $entityIds,
            'clients'    => $clients,
            'groups'     => $groups,
            'all_groups' => $all,
            'picked'     => $picked,
            'severities' => $sev,
        ];
    }

    /** Nome do cliente de um host group escolhido: o cliente ligado a ele ou, sem ligação, o próprio nome do grupo. */
    public static function clientOfGroup(array $scope, array $group): string
    {
        return $group['client'] ?? $group['group_name'];
    }

    /**
     * Todos os host groups do Zabbix que têm hosts: [group_id, group_name, client|null], em ordem de nome.
     * @param string[] $clients
     */
    public static function allGroups(array $clients): array
    {
        $rows = Cache::remember('zbx:hostgroups', 300, function () {
            return Zabbix::batchStrict(['g' => ['hostgroup.get', ['output' => ['groupid', 'name'], 'with_hosts' => true, 'sortfield' => 'name']]])['g'];
        });
        $names = array_column($rows, 'name');
        $map = self::groupClientMap($names, $clients);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['group_id' => (string) $r['groupid'], 'group_name' => (string) $r['name'], 'client' => $map[$r['name']] ?? null];
        }
        return $out;
    }

    /** Host group => cliente: tabela client_groups do NOC 360 e, para o resto, as mesmas palavras no nome (sem acento). */
    public static function groupClientMap(array $groupNames, array $clients): array
    {
        $map = [];
        foreach ((array) (Settings::noc360()['client_groups'] ?? []) as $client => $groups) {
            if (in_array($client, $clients, true)) {
                foreach ((array) $groups as $g) {
                    $map[$g] = $client;
                }
            }
        }
        foreach ($groupNames as $g) {
            if (isset($map[$g])) {
                continue;
            }
            $gt = self::tokens($g);
            if (!$gt) {
                continue;
            }
            $best = null;
            $bestScore = 0;
            foreach ($clients as $c) {
                $ct = self::tokens($c);
                $inter = $ct ? count(array_intersect($ct, $gt)) : 0;
                if ($inter > 0 && ($inter === count($ct) || $inter === count($gt))) {
                    $score = $inter * 10 - abs(count($ct) - count($gt));
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $c;
                    }
                }
            }
            if ($best !== null) {
                $map[$g] = $best;
            }
        }
        return $map;
    }

    private static function tokens(string $s): array
    {
        $s = strtr(mb_strtolower($s, 'UTF-8'), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        $t = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_diff($t, ['de', 'da', 'do', 'dos', 'das', 'e', 'municipal']));
    }

    /** IDs de host group pedidos na URL ("12|34"). */
    public static function parseIds(?string $raw): array
    {
        $out = [];
        foreach (explode('|', (string) $raw) as $n) {
            $n = trim($n);
            if ($n !== '' && ctype_digit($n)) {
                $out[$n] = $n;
            }
        }
        return array_values($out);
    }

    /** Clientes pedidos na URL ("A|B|C"), só os que existem no painel. */
    public static function parseClients(?string $raw, array $known): array
    {
        $out = [];
        foreach (explode('|', (string) $raw) as $n) {
            $n = trim($n);
            if ($n !== '' && in_array($n, $known, true)) {
                $out[$n] = $n;
            }
        }
        return array_values($out);
    }
}
