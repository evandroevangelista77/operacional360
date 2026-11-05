<?php

namespace GlpiPlugin\Operacional360;

use TCPDF;

/**
 * Relatório executivo de KPI's em PDF, A4 paisagem (sempre paisagem, em qualquer navegador).
 * Gerado no servidor a partir do mesmo resultado de Api::data(), com capa, cabeçalho com o
 * logotipo, cartões em cinza claro e, no fim, o espaço da assinatura do gestor.
 */
class Pdf
{
    private const W = 297.0;
    private const H = 210.0;
    private const MX = 12.0;
    private const CW = 273.0;
    private const TOTAL = 6;
    /** Número de páginas do relatório (a assinatura fica na última). */
    public const PAGES = 6;

    /** Campo de assinatura (mm, canto superior esquerdo): o LibreSign recebe as mesmas coordenadas em pontos. */
    public const SIGN_BOX = ['x' => 185.0, 'y' => 128.0, 'w' => 95.0, 'h' => 27.0];

    /** Campo de assinatura em pontos (origem no canto inferior esquerdo), para o LibreSign. */
    public static function signBoxPoints(): array
    {
        $k = 72 / 25.4;
        $b = self::SIGN_BOX;
        return ['llx' => (int) round($b['x'] * $k), 'lly' => (int) round((self::H - $b['y'] - $b['h']) * $k), 'urx' => (int) round(($b['x'] + $b['w']) * $k), 'ury' => (int) round((self::H - $b['y']) * $k)];
    }

    private const NAVY = [10, 27, 56];
    private const NAVY2 = [18, 48, 94];
    private const TEAL = [8, 126, 131];
    private const INK = [15, 31, 58];
    private const MUTED = [102, 119, 145];
    private const CARD = [245, 247, 250];
    private const LINE = [225, 230, 237];
    private const TRACK = [226, 232, 241];
    private const BLUE = [42, 111, 209];
    private const ORANGE = [217, 89, 38];
    private const GOOD = [21, 147, 95];
    private const WARN = [183, 121, 31];
    private const CRIT = [197, 48, 48];
    private const SEVC = [0 => [102, 119, 145], 1 => [42, 111, 209], 2 => [183, 121, 31], 3 => [224, 122, 58], 4 => [214, 69, 69], 5 => [194, 65, 122]];

    private TCPDF $p;
    private array $d;
    private array $who;
    private string $clients;
    private string $period;
    private int $page = 0;
    private ?int $only = null;

    /**
     * @param array $d resultado de Api::data()
     * @param array{name:string,email:string} $who gestor que emite o relatório
     */
    public static function build(array $d, array $who, ?int $onlyPage = null): string
    {
        $self = new self($d, $who);
        $self->only = $onlyPage; // só para conferir o layout de uma página
        return $self->render();
    }

    private function __construct(array $d, array $who)
    {
        $this->d = $d;
        $this->who = $who;
        $f = $d['filters'];
        $this->clients = $f['clients'] ? implode(', ', $f['clients']) : 'Todos os clientes';
        $this->period = self::fdate($f['from']) . ' a ' . self::fdate($f['to']);
    }

    private function render(): string
    {
        $this->p = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $this->p->setPrintHeader(false);
        $this->p->setPrintFooter(false);
        $this->p->SetMargins(0, 0, 0);
        $this->p->SetAutoPageBreak(false, 0);
        $this->p->SetCreator('Operacional 360');
        $this->p->SetAuthor('OAKMONT GROUP');
        $this->p->SetTitle("Relatório executivo de KPI's - " . $this->period);

        foreach (['cover', 'pageSummary', 'pageSla', 'pageVolume', 'pageTeam', 'pageApproval'] as $i => $step) {
            if ($this->only === null || $this->only === $i + 1) {
                $this->page = $i;
                $this->$step();
            }
        }
        return (string) $this->p->Output('', 'S');
    }

    /* ------------------------------------------------------------------ páginas */

    private function cover(): void
    {
        $p = $this->p;
        $p->AddPage();
        $this->page = 1;
        $p->LinearGradient(0, 0, self::W, self::H, self::NAVY, self::NAVY2, [0, 0, 1, 1]);
        $p->SetAlpha(0.2);
        $this->image('arvore.jpg', 0, 0, self::W, self::H);
        $p->SetAlpha(1);
        $this->image('oakmont-branco.png', 20, 16, 66);

        $this->tag(20, 78, 'RELATÓRIO DE ENTREGA DE SERVIÇOS');
        $this->text(20, 92, 230, 14, 'Indicadores de Operação', 32, 'B', [255, 255, 255]);
        $this->text(20, 108, 230, 14, 'e Nível de Serviço', 32, 'B', [255, 255, 255]);
        $this->text(20, 127, 250, 6, 'SLA · Volumetria · Tempos de atendimento e resolução · Monitoramento e alertas · Equipe responsável', 11, '', [201, 216, 238]);

        $p->SetDrawColor(120, 150, 200);
        $p->SetLineWidth(0.25);
        $p->Line(20, 142, 277, 142);
        $cols = [['CLIENTE(S)', $this->clients, 20, 120], ['PERÍODO', $this->period, 150, 60], ['EMITIDO EM', date('d/m/Y', (int) $this->d['generated_at']), 225, 50]];
        foreach ($cols as [$k, $v, $x, $w]) {
            $this->text($x, 147, $w, 4, $k, 8, '', [159, 192, 232]);
            $this->text($x, 153, $w, 7, $v, 14, 'B', [255, 255, 255]);
        }
        $items = ['1  Resumo executivo', '2  SLA e tempo de resolução', '3  Volumetria e alertas', '4  Equipe, chamados mais longos e pendências', '5  Aprovação do relatório'];
        foreach ($items as $i => $t) {
            $this->text(20 + ($i % 2) * 120, 168 + intdiv($i, 2) * 6.5, 115, 5, $t, 10, '', [201, 216, 238]);
        }
        $p->SetDrawColor(90, 120, 175);
        $p->Line(20, 192, 277, 192);
        $this->text(20, 194, 130, 4, 'OAKMONT GROUP · Documento confidencial', 8, '', [159, 192, 232]);
        $this->text(147, 194, 130, 4, 'Fonte: GLPI (chamados) e Zabbix (monitoramento) · Operacional 360', 8, '', [159, 192, 232], 'R');
    }

    private function pageSummary(): void
    {
        $d = $this->d;
        $k = $d['kpi'];
        $e = $d['exec'];
        $sl = $e['sla'];
        $pend = $e['pending'];
        $this->newPage('Resumo executivo');

        $tiles = [
            ['SLA CUMPRIDO', self::pct($sl['pct']), self::n($sl['ok']) . ' de ' . self::n($sl['n']) . ' no prazo', self::slaColor($sl['pct'])],
            ['CHAMADOS NO PERÍODO', self::n($k['tickets']), self::n($e['volume']['resolved']) . ' resolvidos · ' . self::n($k['backlog']) . ' em aberto', self::ORANGE],
            ['TME (1º ATENDIMENTO)', self::dur($k['mttd_glpi']), self::n($k['mttd_glpi_n']) . ' chamados', self::TEAL],
            ['TMA (RESOLUÇÃO)', self::dur($k['mttr_glpi']), self::n($k['mttr_glpi_n']) . ' chamados', self::TEAL],
            ['ALERTAS ZABBIX', self::n($k['alerts']), self::n($k['active']) . ' ativos · ' . self::n($k['unacked']) . ' sem ACK', self::BLUE],
            ['MTTD ZABBIX', self::dur($k['mttd_zbx']), self::n($k['mttd_zbx_n']) . ' alertas · até o ACK', self::BLUE],
            ['MTTR ZABBIX', self::dur($k['mttr_zbx']), self::n($k['mttr_zbx_n']) . ' alertas · até normalizar', self::BLUE],
            ['PENDENTE (GLPI)', self::dur($pend['total_seconds']), self::n($pend['now']) . ' pendentes · ' . self::n($k['overdue']) . ' atrasados', self::WARN],
        ];
        $tw = (self::CW - 3 * 4) / 4;
        foreach ($tiles as $i => [$l, $v, $s, $c]) {
            $x = self::MX + ($i % 4) * ($tw + 4);
            $y = 36 + intdiv($i, 4) * 25;
            $this->box($x, $y, $tw, 22);
            $this->p->SetFillColor(...$c);
            $this->p->Rect($x + 0.3, $y + 0.3, $tw - 0.6, 1.3, 'F', [], $c);
            $this->text($x + 4, $y + 3.5, $tw - 8, 3.5, $l, 7, '', self::MUTED);
            $this->text($x + 4, $y + 8.2, $tw - 8, 8, $v, 17, 'B', self::INK);
            $this->text($x + 4, $y + 16.8, $tw - 8, 3.5, $s, 7.5, '', [52, 70, 95]);
        }

        // resumo executivo
        $bul = $this->bullets();
        $this->box(self::MX, 88, 183, 100);
        $this->text(self::MX + 5, 91, 100, 5, 'Resumo executivo', 11, 'B', self::INK);
        $y = 99.5;
        foreach ($bul as $b) {
            $this->p->SetFillColor(...self::TEAL);
            $this->p->Circle(self::MX + 6.5, $y + 2.1, 0.8, 0, 360, 'F', [], self::TEAL);
            $y += $this->wrapText(self::MX + 10, $y, 170, $b, 8.6, [52, 70, 95]) + 2.2;
        }
        $att = $this->attention();
        if ($att) {
            $this->p->SetFillColor(255, 248, 230);
            $this->p->RoundedRect(self::MX + 5, 172, 173, 12, 1.5, '1111', 'F', [], [255, 248, 230]);
            $this->p->SetFillColor(...self::WARN);
            $this->p->Rect(self::MX + 5, 172, 1.2, 12, 'F', [], self::WARN);
            $this->wrapText(self::MX + 9, 173.5, 166, 'Pontos de atenção: ' . implode(' · ', $att) . '.', 8.2, [90, 60, 10]);
        } else {
            $this->p->RoundedRect(self::MX + 5, 174, 173, 8, 1.5, '1111', 'F', [], [233, 248, 241]);
            $this->text(self::MX + 9, 176, 166, 4, 'Sem pontos críticos nos indicadores do período.', 8.2, 'B', self::GOOD);
        }

        // SLA geral
        $this->box(199, 88, 86, 100);
        $this->text(204, 91, 76, 5, 'SLA geral', 11, 'B', self::INK);
        $this->donut(242, 128, 25, 5.5, $sl['pct'], self::slaColor($sl['pct']), self::pct($sl['pct']), 'meta 95%');
        $this->text(204, 164, 76, 4, self::n($sl['open_late']) . ' chamado(s) em aberto já estouraram o prazo.', 8, '', [52, 70, 95], 'C');
        $this->text(204, 172, 76, 4, self::n($sl['n']) . ' chamado(s) resolvidos avaliados', 8, '', self::MUTED, 'C');
    }

    private function pageSla(): void
    {
        $e = $this->d['exec'];
        $sl = $e['sla'];
        $k = $this->d['kpi'];
        $this->newPage('SLA e tempo de resolução');

        $prio = array_map(fn ($r) => ['name' => $r['priority'] . ' (' . $r['n'] . ')', 'value' => (float) ($r['pct'] ?? 0), 'label' => self::pct($r['pct']), 'color' => self::slaColor($r['pct'])], $sl['by_priority']);
        $cli = array_map(fn ($r) => ['name' => $r['name'] . ' (' . $r['n'] . ')', 'value' => (float) ($r['pct'] ?? 0), 'label' => self::pct($r['pct']), 'color' => self::slaColor($r['pct'])], array_slice($sl['by_client'], 0, 8));

        $this->card(self::MX, 36, 134, 52, 'SLA por prioridade', 'chamados resolvidos no período');
        $this->hbars(self::MX + 5, 48, 124, $prio, 100.0, 5.4, 'Sem chamados resolvidos.');
        $this->card(self::MX + 139, 36, 134, 52, 'SLA por cliente', 'maiores volumes');
        $this->hbars(self::MX + 144, 48, 124, $cli, 100.0, 4.4, 'Sem chamados resolvidos.');

        $this->card(self::MX, 92, self::CW, 52, 'Tendência do SLA', 'por dia de solução · meta de 95%');
        $this->slaLine(self::MX + 6, 100, self::CW - 12, 40, $sl['trend']);

        $this->card(self::MX, 148, self::CW, 46, 'Tempo de resolução', 'chamados resolvidos, por faixa · TMA ' . self::dur($k['mttr_glpi']) . ' · TME ' . self::dur($k['mttd_glpi']));
        $this->columns(self::MX + 6, 156, self::CW - 12, 32, $e['resolution']['distribution']);
    }

    private function pageVolume(): void
    {
        $d = $this->d;
        $k = $d['kpi'];
        $e = $d['exec'];
        $this->newPage('Volumetria e alertas');

        $this->card(self::MX, 36, 178, 60, 'Volumetria', 'chamados abertos × alertas por dia');
        $this->dayChart(self::MX + 6, 49, 166, 42, $d['timeline']);

        $sevs = $d['severities'];
        rsort($sevs);
        $sevRows = array_map(fn ($s) => ['name' => self::sevName($s), 'value' => (float) ($k['alerts_by_sev'][$s] ?? 0), 'label' => self::n($k['alerts_by_sev'][$s] ?? 0), 'color' => self::SEVC[$s] ?? self::BLUE], $sevs);
        $this->card(self::MX + 183, 36, 90, 60, 'Alertas por severidade', 'Zabbix');
        $this->hbars(self::MX + 188, 48, 80, $sevRows, null, 6.5, 'Sem alertas no período.');
        $this->text(self::MX + 188, 86, 80, 4, 'Total no período: ' . self::n($k['alerts']), 8, 'B', self::INK);

        $col = fn ($rows, $c) => array_map(fn ($r) => ['name' => $r['name'], 'value' => (float) $r['n'], 'label' => self::n($r['n']), 'color' => $c], $rows);
        $this->card(self::MX, 100, 88, 94, 'Chamados por cliente', 'top 10');
        $this->hbars(self::MX + 5, 111, 78, $col($e['volume']['by_client'], self::ORANGE), null, 4.9);
        $this->card(self::MX + 92.5, 100, 88, 94, 'Chamados por categoria', '');
        $this->hbars(self::MX + 97.5, 111, 78, $col($e['volume']['by_category'], self::ORANGE), null, 4.9);
        $this->text(self::MX + 97.5, 152, 78, 4, 'Por prioridade', 9, 'B', self::INK);
        $this->hbars(self::MX + 97.5, 158, 78, $col($e['volume']['by_priority'], self::ORANGE), null, 4.9);
        $this->card(self::MX + 185, 100, 88, 94, 'Alertas por cliente', 'Zabbix');
        $alCli = array_values(array_filter($d['clients'], fn ($c) => $c['alerts'] > 0));
        usort($alCli, fn ($a, $b) => $b['alerts'] <=> $a['alerts']);
        $this->hbars(self::MX + 190, 111, 78, array_map(fn ($c) => ['name' => $c['name'], 'value' => (float) $c['alerts'], 'label' => self::n($c['alerts']), 'color' => self::BLUE], array_slice($alCli, 0, 6)), null, 4.9, 'Sem alertas.');
        $this->text(self::MX + 190, 152, 78, 4, 'Hosts com mais alertas', 9, 'B', self::INK);
        $this->hbars(self::MX + 190, 158, 78, array_map(fn ($h) => ['name' => $h['host'], 'value' => (float) $h['alerts'], 'label' => self::n($h['alerts']), 'color' => self::BLUE], array_slice($d['top_hosts'], 0, 6)), null, 4.9, 'Sem alertas.');
    }

    private function pageTeam(): void
    {
        $e = $this->d['exec'];
        $pend = $e['pending'];
        $this->newPage('Equipe, chamados mais longos e pendências');

        $this->card(self::MX, 36, self::CW, 66, 'Técnicos responsáveis', 'chamados do período + em aberto');
        $cols = [['Responsável', 62, 'L'], ['Volume', 50, 'B'], ['Resolvidos', 26, 'R'], ['Em aberto', 24, 'R'], ['SLA', 20, 'R'], ['TMA', 26, 'R'], ['TME', 26, 'R'], ['Pendente', 29, 'R']];
        $rows = [];
        $max = max(1, ...array_map(fn ($t) => $t['tickets'], $e['techs'] ?: [['tickets' => 1]]));
        foreach (array_slice($e['techs'], 0, 6) as $t) {
            $rows[] = [$t['name'], ['bar' => $t['tickets'] / $max, 'text' => self::n($t['tickets'])], self::n($t['resolved']), self::n($t['open']),
                ['text' => self::pct($t['sla_pct']), 'color' => self::slaColor($t['sla_pct']), 'bold' => true], self::dur($t['tma']), self::dur($t['tme']), $t['wait_seconds'] ? self::dur($t['wait_seconds']) : '—'];
        }
        $this->table(self::MX + 4, 45, $cols, $rows, 8.2, 8.0);

        $link = fn ($t) => ['#' . $t['id'], $t['name'], $t['client'], $t['tech'], ['text' => self::dur($t['seconds']) . ($t['open'] ? ' · aberto' : ''), 'bold' => true]];
        $tcols = [['Nº', 14, 'L'], ['Título', 40, 'L'], ['Cliente', 26, 'L'], ['Responsável', 24, 'L'], ['Tempo', 30, 'R']];
        $this->card(self::MX, 106, 134, 52, 'Chamados que mais duraram', 'abertura → solução (ou até agora, se aberto)');
        $this->table(self::MX + 3, 116, $tcols, array_map($link, array_slice($e['longest'], 0, 4)), 7.3, 7.6);
        $this->card(self::MX + 139, 106, 134, 52, 'Chamados que mais ficaram pendentes', 'tempo de espera acumulado');
        $this->table(self::MX + 142, 116, $tcols, array_map($link, array_slice($pend['top_tickets'], 0, 4)), 7.3, 7.6);

        $this->card(self::MX, 162, self::CW, 32, 'Com quem ficou mais tempo pendente', 'soma do tempo de espera por responsável · total ' . self::dur($pend['total_seconds']) . ' · ' . self::n($pend['now']) . ' pendente(s) agora');
        $this->hbars(self::MX + 5, 172, self::CW - 10, array_map(fn ($t) => ['name' => $t['name'] . ' (' . $t['wait_tickets'] . ' chamados)', 'value' => (float) $t['wait_seconds'], 'label' => self::dur($t['wait_seconds']), 'color' => self::WARN], array_slice($pend['by_tech'], 0, 3)), null, 5.2, 'Nenhum chamado ficou pendente no período.');
    }

    private function pageApproval(): void
    {
        $this->newPage('Aprovação do relatório');
        $this->card(self::MX, 36, 160, 84, 'Metodologia e definições', '');
        $defs = [
            ['SLA cumprido', 'Percentual de chamados resolvidos dentro do prazo (meta por prioridade, conforme o contrato). Meta de referência: 95%.'],
            ['TME', 'Tempo médio de espera: da abertura do chamado ao primeiro atendimento.'],
            ['TMA', 'Tempo médio de atendimento: da abertura do chamado à solução.'],
            ['MTTD (Zabbix)', 'Tempo médio de detecção: do início do alerta ao primeiro reconhecimento (ACK).'],
            ['MTTR (Zabbix)', 'Tempo médio de recuperação: do início do alerta à normalização.'],
            ['Pendente', 'Tempo em que o chamado ficou aguardando retorno (status pendente), somado por responsável.'],
        ];
        $y = 47;
        foreach ($defs as [$t, $x]) {
            $this->text(self::MX + 5, $y, 36, 4, $t, 8.6, 'B', self::INK);
            $y += $this->wrapText(self::MX + 42, $y, 114, $x, 8.4, [52, 70, 95]) + 2.4;
        }

        $this->card(self::MX + 165, 36, 108, 84, 'Dados do relatório', '');
        $meta = [['Cliente(s)', $this->clients], ['Período', $this->period], ['Emitido em', date('d/m/Y H:i', (int) $this->d['generated_at'])],
            ['Fontes', 'GLPI (chamados) e Zabbix (alertas)'], ['Emitido por', $this->who['name']]];
        $y = 47;
        foreach ($meta as [$t, $x]) {
            $this->text(self::MX + 170, $y, 24, 4, $t, 8.2, 'B', self::INK);
            $y += $this->wrapText(self::MX + 196, $y, 74, $x, 8.2, [52, 70, 95]) + 3;
        }

        $this->text(self::MX, 126, 160, 5, 'Aprovação', 11, 'B', self::INK);
        $this->wrapText(self::MX, 133, 160, 'Os indicadores acima foram extraídos dos sistemas de gestão de serviços (GLPI) e de monitoramento (Zabbix) da OAKMONT GROUP no período informado. O gestor responsável valida o conteúdo deste relatório com sua assinatura digital.', 8.6, [52, 70, 95]);

        // espaço da assinatura do gestor (o LibreSign grava a assinatura sobre este retângulo)
        $b = self::SIGN_BOX;
        $this->p->SetDrawColor(...self::LINE);
        $this->p->SetLineStyle(['width' => 0.3, 'dash' => '2,1.5']);
        $this->p->RoundedRect($b['x'], $b['y'], $b['w'], $b['h'], 2, '1111', 'D');
        $this->p->SetLineStyle(['width' => 0.2, 'dash' => 0]);
        $this->p->SetDrawColor(...self::MUTED);
        $this->p->SetLineWidth(0.3);
        $this->p->Line($b['x'], $b['y'] + $b['h'] + 1, $b['x'] + $b['w'], $b['y'] + $b['h'] + 1);
        $this->text($b['x'], $b['y'] + $b['h'] + 3, $b['w'], 4, $this->who['name'], 9.5, 'B', self::INK, 'C');
        $this->text($b['x'], $b['y'] + $b['h'] + 8, $b['w'], 4, 'Gestor responsável · OAKMONT GROUP', 8, '', self::MUTED, 'C');
        $this->text(self::MX, $b['y'] + $b['h'] + 3, 140, 4, 'Local e data: ____________________,  ____ / ____ / ________', 9, '', self::MUTED);
        $this->text($b['x'], $b['y'] - 6, $b['w'], 4, 'Assinatura digital do gestor', 8, '', self::MUTED, 'C');
    }

    /* ------------------------------------------------------------------ textos do resumo */

    private function bullets(): array
    {
        $d = $this->d;
        $k = $d['kpi'];
        $e = $d['exec'];
        $sl = $e['sla'];
        $b = [];
        if ($sl['pct'] !== null) {
            $b[] = 'SLA de ' . self::pct($sl['pct']) . ' (' . self::n($sl['ok']) . ' de ' . self::n($sl['n']) . ' chamados resolvidos no prazo) — '
                . ($sl['pct'] >= 95 ? 'dentro da meta de 95%.' : number_format(95 - $sl['pct'], 1, ',', '.') . ' p.p. abaixo da meta de 95%.');
        }
        $b[] = self::n($k['tickets']) . ' chamados abertos no período; ' . self::n($e['volume']['resolved']) . ' já resolvidos e ' . self::n($k['backlog']) . ' em aberto agora'
            . ($k['overdue'] ? ', dos quais ' . self::n($k['overdue']) . ' com o prazo vencido.' : '.');
        if ($e['volume']['by_client']) {
            $c = $e['volume']['by_client'][0];
            $b[] = 'Maior volume de chamados: ' . $c['name'] . ' (' . self::n($c['n']) . ', ' . number_format($c['n'] * 100 / max(1, $k['tickets']), 1, ',', '.') . '% do total).';
        }
        $worst = array_values(array_filter($sl['by_client'], fn ($c) => $c['n'] >= 3 && $c['pct'] !== null));
        usort($worst, fn ($a, $b2) => $a['pct'] <=> $b2['pct']);
        if ($worst && $worst[0]['pct'] < 95) {
            $b[] = 'Menor SLA entre os clientes: ' . $worst[0]['name'] . ' com ' . self::pct($worst[0]['pct']) . ' (' . self::n($worst[0]['n']) . ' chamados).';
        }
        $b[] = 'TME ' . self::dur($k['mttd_glpi']) . ' (primeiro atendimento) e TMA ' . self::dur($k['mttr_glpi']) . ' (resolução) nos chamados do GLPI.';
        $b[] = self::n($k['alerts']) . ' alertas no Zabbix no período; ' . self::n($k['active']) . ' ativos agora e ' . self::n($k['unacked']) . ' sem reconhecimento. MTTD ' . self::dur($k['mttd_zbx']) . ' e MTTR ' . self::dur($k['mttr_zbx']) . '.';
        foreach ($e['techs'] as $t) {
            if ($t['name'] !== 'Sem responsável') {
                $b[] = 'Responsável com mais chamados: ' . $t['name'] . ' (' . self::n($t['tickets']) . '; SLA ' . self::pct($t['sla_pct']) . '; TMA ' . self::dur($t['tma']) . ').';
                break;
            }
        }
        if ($e['longest']) {
            $l = $e['longest'][0];
            $b[] = 'Chamado que mais durou: #' . $l['id'] . ' (' . $l['client'] . ') com ' . self::dur($l['seconds']) . ($l['open'] ? ', ainda em aberto.' : '.');
        }
        if ($e['pending']['by_tech']) {
            $w = $e['pending']['by_tech'][0];
            $b[] = 'Maior tempo pendente com ' . $w['name'] . ': ' . self::dur($w['wait_seconds']) . ' em ' . self::n($w['wait_tickets']) . ' chamado(s); total pendente no período: ' . self::dur($e['pending']['total_seconds']) . '.';
        }
        return $b;
    }

    private function attention(): array
    {
        $k = $this->d['kpi'];
        $e = $this->d['exec'];
        $a = [];
        if ($e['sla']['pct'] !== null && $e['sla']['pct'] < 95) {
            $a[] = 'SLA abaixo da meta';
        }
        if ($k['overdue']) {
            $a[] = self::n($k['overdue']) . ' chamado(s) vencido(s)';
        }
        if ($k['unacked']) {
            $a[] = self::n($k['unacked']) . ' alerta(s) ativo(s) sem ACK';
        }
        foreach ($e['techs'] as $t) {
            if ($t['name'] === 'Sem responsável' && $t['open']) {
                $a[] = 'chamados em aberto sem responsável';
            }
        }
        $sevs = $this->d['severities'];
        rsort($sevs);
        if ($sevs && ($k['active_by_sev'][$sevs[0]] ?? 0) > 0) {
            $a[] = self::n($k['active_by_sev'][$sevs[0]]) . ' alerta(s) ' . self::sevName($sevs[0]) . ' ativo(s)';
        }
        return $a;
    }

    /* ------------------------------------------------------------------ gráficos */

    private function donut(float $cx, float $cy, float $r, float $thick, ?float $pct, array $color, string $center, string $sub): void
    {
        $this->ring($cx, $cy, $r, $r - $thick, 0, 100, self::TRACK);
        if ($pct !== null && $pct > 0) {
            $this->ring($cx, $cy, $r, $r - $thick, 0, min(100, $pct), $color);
        }
        $this->text($cx - 20, $cy - 6, 40, 8, $center, 19, 'B', self::INK, 'C');
        $this->text($cx - 20, $cy + 4, 40, 4, $sub, 8, '', self::MUTED, 'C');
    }

    /** Arco de anel (percentuais de 0 a 100 a partir do topo, no sentido horário). */
    private function ring(float $cx, float $cy, float $ro, float $ri, float $from, float $to, array $c): void
    {
        $pts = [];
        $steps = max(8, (int) ceil(($to - $from) * 1.2));
        for ($i = 0; $i <= $steps; $i++) {
            $a = deg2rad(-90 + ($from + ($to - $from) * $i / $steps) * 3.6);
            $pts[] = $cx + $ro * cos($a);
            $pts[] = $cy + $ro * sin($a);
        }
        for ($i = $steps; $i >= 0; $i--) {
            $a = deg2rad(-90 + ($from + ($to - $from) * $i / $steps) * 3.6);
            $pts[] = $cx + $ri * cos($a);
            $pts[] = $cy + $ri * sin($a);
        }
        $this->p->SetFillColor(...$c);
        $this->p->Polygon($pts, 'F', [], $c);
    }

    /** Barras horizontais: rows = [name, value, label, color]. */
    private function hbars(float $x, float $y, float $w, array $rows, ?float $max, float $rowH, string $empty = 'Sem dados no período.'): void
    {
        if (!$rows) {
            $this->text($x, $y + 1, $w, 4, $empty, 8, '', self::MUTED);
            return;
        }
        $max ??= max(1.0, ...array_map(fn ($r) => $r['value'], $rows));
        $lw = $w * 0.38;
        $vw = 16;
        $bw = $w - $lw - $vw - 4;
        foreach ($rows as $i => $r) {
            $yy = $y + $i * $rowH;
            $this->text($x, $yy, $lw - 2, $rowH, $this->fit($r['name'], $lw - 2, 7.6), 7.6, '', [52, 70, 95]);
            $this->p->SetFillColor(...self::TRACK);
            $this->p->RoundedRect($x + $lw, $yy + $rowH / 2 - 1.1, $bw, 2.2, 1.1, '1111', 'F', [], self::TRACK);
            $fw = max(1.5, $bw * min(1, $r['value'] / $max));
            $this->p->SetFillColor(...$r['color']);
            $this->p->RoundedRect($x + $lw, $yy + $rowH / 2 - 1.1, $fw, 2.2, 1.1, '1111', 'F', [], $r['color']);
            $this->text($x + $lw + $bw + 3, $yy, $vw, $rowH, $r['label'], 7.8, 'B', self::INK, 'R');
        }
    }

    private function dayChart(float $x, float $y, float $w, float $h, array $tl): void
    {
        if (!$tl) {
            $this->text($x, $y, $w, 4, 'Sem dados no período.', 8, '', self::MUTED);
            return;
        }
        $lx = $x + 8;
        $iw = $w - 8;
        $ih = $h - 14;
        $max = max(1, ...array_map(fn ($t) => max($t['tickets'], $t['alerts']), $tl));
        $nice = (int) (ceil($max / (10 ** floor(log10($max)))) * (10 ** floor(log10($max))));
        foreach ([0, 0.5, 1] as $f) {
            $yy = $y + $ih - $ih * $f;
            $this->p->SetDrawColor(...self::LINE);
            $this->p->SetLineWidth(0.2);
            $this->p->Line($lx, $yy, $lx + $iw, $yy);
            $this->text($x, $yy - 1.8, 7, 3.6, (string) round($nice * $f), 6.5, '', self::MUTED, 'R');
        }
        $bw = $iw / count($tl);
        $prev = null;
        $step = max(1, (int) ceil(count($tl) / 14));
        foreach ($tl as $i => $t) {
            $cx = $lx + $i * $bw + $bw / 2;
            $bh = $ih * $t['tickets'] / $nice;
            $this->p->SetFillColor(...self::ORANGE);
            $this->p->Rect($cx - $bw * 0.32, $y + $ih - $bh, $bw * 0.64, $bh, 'F', [], self::ORANGE);
            $ay = $y + $ih - $ih * $t['alerts'] / $nice;
            if ($prev) {
                $this->p->SetDrawColor(...self::BLUE);
                $this->p->SetLineWidth(0.6);
                $this->p->Line($prev[0], $prev[1], $cx, $ay);
            }
            $prev = [$cx, $ay];
            if ($i % $step === 0) {
                $this->text($cx - 6, $y + $ih + 1.5, 12, 3.5, substr($t['day'], 8, 2) . '/' . substr($t['day'], 5, 2), 6.5, '', self::MUTED, 'C');
            }
        }
        $this->p->SetFillColor(...self::ORANGE);
        $this->p->Rect($lx, $y + $h - 3.2, 3, 3, 'F', [], self::ORANGE);
        $this->text($lx + 4.5, $y + $h - 3.6, 40, 3.6, 'Chamados abertos', 7, '', [52, 70, 95]);
        $this->p->SetFillColor(...self::BLUE);
        $this->p->Rect($lx + 42, $y + $h - 3.2, 3, 3, 'F', [], self::BLUE);
        $this->text($lx + 46.5, $y + $h - 3.6, 40, 3.6, 'Alertas do Zabbix', 7, '', [52, 70, 95]);
    }

    private function slaLine(float $x, float $y, float $w, float $h, array $tr): void
    {
        $pts = array_values(array_filter($tr, fn ($t) => $t['pct'] !== null));
        if (count($pts) < 2) {
            $this->text($x, $y + 2, $w, 4, 'Dados insuficientes para a tendência.', 8, '', self::MUTED);
            return;
        }
        $lx = $x + 10;
        $iw = $w - 10;
        $ih = $h - 7;
        $lo = max(0, floor(min(array_merge([90], array_map(fn ($p) => $p['pct'], $pts))) / 10) * 10);
        $yv = fn ($v) => $y + $ih - ($v - $lo) / (100 - $lo) * $ih;
        foreach ([$lo, ($lo + 100) / 2, 100] as $v) {
            $this->p->SetDrawColor(...self::LINE);
            $this->p->SetLineWidth(0.2);
            $this->p->Line($lx, $yv($v), $lx + $iw, $yv($v));
            $this->text($x, $yv($v) - 1.8, 8, 3.6, round($v) . '%', 6.5, '', self::MUTED, 'R');
        }
        $this->p->SetDrawColor(...self::GOOD);
        $this->p->SetLineStyle(['width' => 0.35, 'dash' => '2,1.5']);
        $this->p->Line($lx, $yv(95), $lx + $iw, $yv(95));
        $this->p->SetLineStyle(['width' => 0.2, 'dash' => 0]);
        $this->text($lx + $iw - 22, $yv(95) - 4, 22, 3.6, 'meta 95%', 6.8, '', self::GOOD, 'R');
        $step = max(1, (int) ceil(count($pts) / 12));
        $prev = null;
        foreach ($pts as $i => $p) {
            $cx = $lx + $i / (count($pts) - 1) * $iw;
            $cy = $yv($p['pct']);
            if ($prev) {
                $this->p->SetDrawColor(...self::BLUE);
                $this->p->SetLineWidth(0.7);
                $this->p->Line($prev[0], $prev[1], $cx, $cy);
            }
            $prev = [$cx, $cy];
        }
        foreach ($pts as $i => $p) {
            $cx = $lx + $i / (count($pts) - 1) * $iw;
            $c = self::slaColor($p['pct']);
            $this->p->SetFillColor(...$c);
            $this->p->Circle($cx, $yv($p['pct']), 1, 0, 360, 'F', [], $c);
            if ($i % $step === 0) {
                $this->text($cx - 6, $y + $ih + 1.5, 12, 3.5, substr($p['day'], 8, 2) . '/' . substr($p['day'], 5, 2), 6.5, '', self::MUTED, 'C');
            }
        }
    }

    private function columns(float $x, float $y, float $w, float $h, array $rows): void
    {
        $max = max(1, ...array_map(fn ($r) => $r['n'], $rows));
        $n = count($rows);
        $bw = $w / $n;
        $ih = $h - 9;
        $this->p->SetDrawColor(...self::LINE);
        $this->p->Line($x, $y + $ih, $x + $w, $y + $ih);
        foreach ($rows as $i => $r) {
            $cx = $x + $i * $bw + $bw / 2;
            $bh = max(0.6, $ih * $r['n'] / $max);
            $this->p->SetFillColor(...self::TEAL);
            $this->p->RoundedRect($cx - $bw * 0.22, $y + $ih - $bh, $bw * 0.44, $bh, 0.8, '1100', 'F', [], self::TEAL);
            $this->text($cx - 10, $y + $ih - $bh - 4, 20, 3.6, self::n($r['n']), 7.5, 'B', self::INK, 'C');
            $this->text($cx - 15, $y + $ih + 1.5, 30, 3.6, $r['label'], 7.3, '', self::MUTED, 'C');
        }
    }

    /** Tabela simples. cols = [título, largura, alinhamento L|R|B(barra)]. */
    private function table(float $x, float $y, array $cols, array $rows, float $size, float $rowH): void
    {
        $tw = array_sum(array_column($cols, 1));
        $this->p->SetFillColor(...self::TRACK);
        $this->p->Rect($x, $y, $tw, 6.4, 'F', [], self::TRACK);
        $cx = $x;
        foreach ($cols as [$t, $w, $al]) {
            $this->text($cx + 1.5, $y + 1.2, $w - 3, 4, mb_strtoupper($t), 6.6, 'B', self::MUTED, $al === 'R' ? 'R' : 'L');
            $cx += $w;
        }
        $yy = $y + 6.4;
        if (!$rows) {
            $this->text($x + 2, $yy + 2, $tw, 4, 'Sem registros no período.', 8, '', self::MUTED);
            return;
        }
        foreach ($rows as $row) {
            $cx = $x;
            foreach ($cols as $i => [$t, $w, $al]) {
                $cell = $row[$i];
                $txt = is_array($cell) ? ($cell['text'] ?? '') : (string) $cell;
                $color = is_array($cell) && isset($cell['color']) ? $cell['color'] : [52, 70, 95];
                $style = is_array($cell) && !empty($cell['bold']) ? 'B' : '';
                if (is_array($cell) && isset($cell['bar'])) {
                    $bw = $w - 14;
                    $this->p->SetFillColor(...self::TRACK);
                    $this->p->RoundedRect($cx + 1.5, $yy + $rowH / 2 - 1, $bw, 2, 1, '1111', 'F', [], self::TRACK);
                    $this->p->SetFillColor(...self::ORANGE);
                    $this->p->RoundedRect($cx + 1.5, $yy + $rowH / 2 - 1, max(1.2, $bw * $cell['bar']), 2, 1, '1111', 'F', [], self::ORANGE);
                    $this->text($cx + $bw + 2, $yy, 11, $rowH, $txt, $size, 'B', self::INK, 'R');
                } else {
                    $this->text($cx + 1.5, $yy, $w - 3, $rowH, $this->fit($txt, $w - 3, $size, $style), $size, $style, $color, $al === 'R' ? 'R' : 'L');
                }
                $cx += $w;
            }
            $yy += $rowH;
            $this->p->SetDrawColor(...self::LINE);
            $this->p->SetLineWidth(0.15);
            $this->p->Line($x, $yy, $x + $tw, $yy);
        }
    }

    /* ------------------------------------------------------------------ estrutura de página */

    private function newPage(string $title): void
    {
        $p = $this->p;
        $p->AddPage();
        $this->page++;
        $p->LinearGradient(self::MX, 10, self::CW, 20, self::NAVY, self::NAVY2, [0, 0, 1, 1]);
        $p->SetFillColor(...self::NAVY);
        // cantos arredondados: faixa por cima do gradiente (gradiente é retangular)
        $this->image('oakmont-branco.png', self::MX + 5, 11.5, 27);
        $this->text(self::MX + 38, 14.5, 190, 6, $title, 15, 'B', [255, 255, 255]);
        $this->text(self::MX + 38, 21.5, 200, 4, $this->clients . ' · ' . $this->period, 8.5, '', [201, 216, 238]);
        $this->text(self::MX + self::CW - 60, 14.5, 55, 4, 'Operacional 360', 8, '', [159, 192, 232], 'R');
        $p->SetDrawColor(...self::LINE);
        $p->SetLineWidth(0.2);
        $p->Line(self::MX, 199, self::MX + self::CW, 199);
        $this->text(self::MX, 200.5, 200, 4, 'OAKMONT GROUP · Documento confidencial · ' . $this->clients . ' · ' . $this->period, 7.2, '', self::MUTED);
        $this->text(self::MX + self::CW - 40, 200.5, 40, 4, 'Página ' . $this->page . ' de ' . self::TOTAL, 7.2, '', self::MUTED, 'R');
    }

    private function box(float $x, float $y, float $w, float $h): void
    {
        $this->p->RoundedRect($x, $y, $w, $h, 2, '1111', 'DF', ['width' => 0.25, 'color' => self::LINE], self::CARD);
    }

    private function card(float $x, float $y, float $w, float $h, string $title, string $sub): void
    {
        $this->box($x, $y, $w, $h);
        $this->text($x + 5, $y + 3, $w - 10, 5, $title, 10, 'B', self::INK);
        if ($sub !== '') {
            $tw = $this->p->GetStringWidth($title, 'helvetica', 'B', 10);
            $this->text($x + 5 + $tw + 2, $y + 3.8, $w - 12 - $tw, 4, $this->fit($sub, $w - 14 - $tw, 7.5), 7.5, '', self::MUTED);
        }
    }

    private function tag(float $x, float $y, string $t): void
    {
        $w = $this->p->GetStringWidth($t, 'helvetica', '', 8) + 9 + mb_strlen($t) * 0.55;
        $this->p->SetDrawColor(190, 215, 240);
        $this->p->SetLineWidth(0.25);
        $this->p->RoundedRect($x, $y, $w, 7, 3.5, '1111', 'D');
        $this->text($x + 4.5, $y + 1.5, $w, 4, $this->spaced($t), 8, '', [191, 233, 236]);
    }

    private function spaced(string $t): string
    {
        return $t;
    }

    /* ------------------------------------------------------------------ primitivas */

    private function text(float $x, float $y, float $w, float $h, string $t, float $size, string $style, array $color, string $align = 'L'): void
    {
        $p = $this->p;
        $p->SetFont('helvetica', $style, $size);
        $p->SetTextColor(...$color);
        $p->SetXY($x, $y);
        $p->Cell($w, $h, self::clean($t), 0, 0, $align, false, '', 0, false, 'T', 'M');
    }

    /** Texto com quebra de linha; devolve a altura usada (mm). */
    private function wrapText(float $x, float $y, float $w, string $t, float $size, array $color): float
    {
        $p = $this->p;
        $p->SetFont('helvetica', '', $size);
        $p->SetTextColor(...$color);
        $lines = $this->lines(self::clean($t), $w, $size);
        $lh = $size * 0.4 + 1.1;
        foreach ($lines as $i => $ln) {
            $p->SetXY($x, $y + $i * $lh);
            $p->Cell($w, $lh, $ln, 0, 0, 'L', false, '', 0, false, 'T', 'M');
        }
        return count($lines) * $lh;
    }

    private function lines(string $t, float $w, float $size): array
    {
        $out = [];
        $cur = '';
        foreach (preg_split('/\s+/u', trim($t)) ?: [] as $word) {
            $try = $cur === '' ? $word : $cur . ' ' . $word;
            if ($cur !== '' && $this->p->GetStringWidth($try, 'helvetica', '', $size) > $w * 0.93) {
                $out[] = $cur;
                $cur = $word;
            } else {
                $cur = $try;
            }
        }
        if ($cur !== '') {
            $out[] = $cur;
        }
        return $out ?: [''];
    }

    private function fit(string $t, float $w, float $size, string $style = ''): string
    {
        $t = self::clean($t);
        if ($this->p->GetStringWidth($t, 'helvetica', $style, $size) <= $w) {
            return $t;
        }
        while (mb_strlen($t) > 1 && $this->p->GetStringWidth($t . '…', 'helvetica', $style, $size) > $w) {
            $t = mb_substr($t, 0, -1);
        }
        return rtrim($t) . '…';
    }

    private function image(string $file, float $x, float $y, float $w, float $h = 0): void
    {
        $path = dirname(__DIR__) . '/public/static/' . $file;
        // as imagens vão como dados (a biblioteca não lê direto da pasta do plugin)
        $this->p->Image('@' . (string) file_get_contents($path), $x, $y, $w, $h, strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'png' ? 'PNG' : 'JPG');
    }

    /** Troca o que a fonte padrão não desenha. */
    private static function clean(string $t): string
    {
        return strtr($t, ['≤' => '<=', '≥' => '>=', '→' => '->', '×' => 'x', '–' => '-', "\u{00A0}" => ' ']);
    }

    /* ------------------------------------------------------------------ formatação */

    private static function n(mixed $v): string
    {
        return number_format((float) $v, 0, ',', '.');
    }

    private static function pct(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 1, ',', '.') . '%';
    }

    private static function fdate(string $s): string
    {
        return $s === '' ? '' : substr($s, 8, 2) . '/' . substr($s, 5, 2) . '/' . substr($s, 0, 4);
    }

    private static function dur(mixed $sec): string
    {
        if ($sec === null) {
            return '—';
        }
        $sec = max(0, (int) round((float) $sec));
        $d = intdiv($sec, 86400);
        $h = intdiv($sec % 86400, 3600);
        $m = intdiv($sec % 3600, 60);
        if ($d) {
            return "{$d}d {$h}h";
        }
        if ($h) {
            return sprintf('%dh %02dm', $h, $m);
        }
        return $m ? "{$m}m" : "{$sec}s";
    }

    private static function slaColor(?float $p): array
    {
        return $p === null ? self::MUTED : ($p >= 95 ? self::GOOD : ($p >= 85 ? self::WARN : self::CRIT));
    }

    private static function sevName(int $s): string
    {
        return ['Não classificada', 'Informação', 'Atenção', 'Média', 'Alta', 'Desastre'][$s] ?? (string) $s;
    }
}
