<?php

namespace GlpiPlugin\Operacional360;

use CommonDBTM;
use CronTask;
use GLPIKey;
use GLPIMailer;
use RuntimeException;
use Session;
use Symfony\Component\Mime\Address;
use Throwable;
use User;

/**
 * Relatório de KPI's enviado para assinatura do gestor no LibreSign e, depois de assinado, por e-mail ao cliente.
 *
 *   pending  → aguardando o gestor assinar no LibreSign
 *   signed   → assinado; o envio ao cliente falhou ou ainda não foi feito (dá para reenviar)
 *   sent     → assinado e enviado ao cliente
 *   canceled → cancelado
 */
class Signoff extends CommonDBTM
{
    public const LABELS = ['pending' => 'Aguardando assinatura', 'signed' => 'Assinado, e-mail pendente', 'sent' => 'Enviado ao cliente', 'canceled' => 'Cancelado'];

    public static function getTypeName($nb = 0)
    {
        return "Relatório de KPI's assinado";
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_operacional360_signoffs';
    }

    /** Código que a página envia junto das ações (evita que outro site dispare envios com a sessão do gestor). */
    public static function token(): string
    {
        return hash_hmac('sha256', 'op360|' . Session::getLoginUserID(), (string) (new GLPIKey())->get());
    }

    public static function checkToken(string $t): void
    {
        if (!hash_equals(self::token(), $t)) {
            throw new HttpError(403, 'Ação não autorizada (recarregue a página).');
        }
    }

    public static function createTable(): void
    {
        global $DB;
        $DB->doQuery("CREATE TABLE IF NOT EXISTS `" . self::getTable() . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `signer_name` varchar(255) DEFAULT NULL,
            `signer_email` varchar(255) DEFAULT NULL,
            `client_email` varchar(255) DEFAULT NULL,
            `title` varchar(255) DEFAULT NULL,
            `clients` text,
            `period_from` date DEFAULT NULL,
            `period_to` date DEFAULT NULL,
            `file_uuid` varchar(64) DEFAULT NULL,
            `file_id` int unsigned NOT NULL DEFAULT 0,
            `sign_url` varchar(500) DEFAULT NULL,
            `status` varchar(16) NOT NULL DEFAULT 'pending',
            `pdf_file` varchar(255) DEFAULT NULL,
            `last_error` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_signed` timestamp NULL DEFAULT NULL,
            `date_sent` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    public static function dropTable(): void
    {
        global $DB;
        $DB->doQuery('DROP TABLE IF EXISTS `' . self::getTable() . '`');
    }

    /** Gestor logado: nome e e-mail (o e-mail é onde o LibreSign avisa quem deve assinar). */
    public static function manager(): array
    {
        $u = new User();
        if (!$u->getFromDB((int) Session::getLoginUserID())) {
            throw new RuntimeException('Usuário não encontrado.');
        }
        return ['id' => (int) $u->getID(), 'name' => $u->getFriendlyName(), 'email' => (string) $u->getDefaultEmail()];
    }

    /** Gera o PDF, envia ao LibreSign para o gestor assinar e registra a solicitação. */
    public static function start(array $data, string $clientEmail): array
    {
        $clientEmail = trim($clientEmail);
        if (!filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError(400, 'Informe um e-mail válido do cliente.');
        }
        $mgr = self::manager();
        if ($mgr['email'] === '') {
            throw new HttpError(400, sprintf('O usuário %s não tem e-mail cadastrado no GLPI (o LibreSign avisa o gestor por e-mail).', $mgr['name']));
        }
        $client = new LibreSign();
        $pdf = Pdf::build($data, ['name' => $mgr['name'], 'email' => $mgr['email']]);
        $f = $data['filters'];
        $clients = $f['clients'] ? implode(', ', $f['clients']) : 'Todos os clientes';
        $title = sprintf("Relatório de KPI's - %s - %s a %s", $clients, $f['from'], $f['to']);

        $file = $client->requestSignature(mb_substr($title, 0, 200), $pdf, [[
            'identifyMethods' => [['method' => 'email', 'value' => $mgr['email']]],
            'displayName'     => $mgr['name'],
            'description'     => 'Relatório executivo de KPI\'s para assinatura do gestor.',
            'notify'          => 1,
        ]]);
        if (empty($file['uuid'])) {
            throw new RuntimeException('O LibreSign não devolveu o identificador do documento.');
        }
        $warning = null;
        try {
            $client->addVisibleSignature($file['uuid'], (int) $file['id'], (int) ($file['signers'][0]['signRequestId'] ?? 0), Pdf::PAGES, Pdf::signBoxPoints());
        } catch (Throwable $e) {
            $warning = 'Campo visível não criado (a assinatura digital continua válida): ' . $e->getMessage();
        }
        // o LibreSign só informa o link de assinatura por e-mail; aqui fica o endereço do aplicativo
        $url = $client->base() . '/index.php/apps/libresign/';

        $row = new self();
        $id = $row->add([
            'users_id'      => $mgr['id'],
            'signer_name'   => $mgr['name'],
            'signer_email'  => $mgr['email'],
            'client_email'  => $clientEmail,
            'title'         => $title,
            'clients'       => $clients,
            'period_from'   => $f['from'],
            'period_to'     => $f['to'],
            'file_uuid'     => $file['uuid'],
            'file_id'       => (int) ($file['id'] ?? 0),
            'sign_url'      => $url,
            'status'        => 'pending',
            'last_error'    => $warning,
            'date_creation' => $_SESSION['glpi_currenttime'],
            'date_mod'      => $_SESSION['glpi_currenttime'],
        ]);
        return ['id' => (int) $id, 'sign_url' => $url, 'signer_email' => $mgr['email'], 'warning' => $warning];
    }

    /** Solicitações recentes (as do próprio usuário; o administrador vê todas). */
    public static function recent(): array
    {
        $out = [];
        $crit = ['NOT' => ['status' => 'canceled']];
        if (!Session::haveRight('config', UPDATE)) {
            $crit['users_id'] = (int) Session::getLoginUserID();
        }
        foreach ((new self())->find($crit, ['id DESC'], 15) as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'title' => $r['title'], 'clients' => $r['clients'], 'period' => $r['period_from'] . ' a ' . $r['period_to'],
                'signer' => $r['signer_name'], 'client_email' => $r['client_email'], 'status' => $r['status'], 'label' => self::LABELS[$r['status']] ?? $r['status'],
                'sign_url' => $r['status'] === 'pending' ? $r['sign_url'] : null, 'error' => $r['last_error'],
                'created' => $r['date_creation'], 'signed' => $r['date_signed'], 'sent' => $r['date_sent'],
            ];
        }
        return $out;
    }

    /** Consulta o LibreSign; assinado → guarda o PDF e envia ao cliente. */
    public function sync(): string
    {
        if ($this->fields['status'] !== 'pending') {
            return $this->fields['status'];
        }
        try {
            $ls = new LibreSign();
            $data = $ls->validate($this->fields['file_uuid']);
            $signers = $data['signers'] ?? [];
            $allSigned = $signers !== [] && count(array_filter($signers, fn ($s) => !empty($s['signed']))) === count($signers);
            if (!$allSigned && (int) ($data['status'] ?? 0) !== 3) {
                return 'pending';
            }
            $pdf = $ls->downloadPdf($this->fields['file_uuid']);
            $dir = GLPI_PLUGIN_DOC_DIR . '/operacional360';
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
            $name = sprintf('kpi-%d-%s.pdf', $this->getID(), bin2hex(random_bytes(6)));
            file_put_contents($dir . '/' . $name, $pdf);
            $signedAt = $signers[0]['signed'] ?? null;
            $this->update(['id' => $this->getID(), 'status' => 'signed', 'pdf_file' => $name, 'last_error' => null,
                'date_signed' => $signedAt ? date('Y-m-d H:i:s', strtotime($signedAt)) : $_SESSION['glpi_currenttime'], 'date_mod' => $_SESSION['glpi_currenttime']]);
            $this->getFromDB($this->getID());
            return $this->deliver();
        } catch (Throwable $e) {
            $this->update(['id' => $this->getID(), 'last_error' => $e->getMessage(), 'date_mod' => $_SESSION['glpi_currenttime']]);
            return 'pending';
        }
    }

    /** Envia o PDF assinado ao e-mail do cliente. */
    public function deliver(): string
    {
        $path = GLPI_PLUGIN_DOC_DIR . '/operacional360/' . basename((string) $this->fields['pdf_file']);
        if ($this->fields['pdf_file'] === '' || !is_file($path)) {
            return $this->fields['status'];
        }
        global $CFG_GLPI;
        $r = $this->fields;
        $mail = new GLPIMailer();
        $em = $mail->getEmail();
        $fromAddr = (string) ($CFG_GLPI['from_email'] ?? '') ?: (string) ($CFG_GLPI['admin_email'] ?? '');
        $fromName = (string) ($CFG_GLPI['from_email_name'] ?? '') ?: (string) ($CFG_GLPI['admin_email_name'] ?? 'OAKMONT GROUP');
        if ($fromAddr !== '') {
            $em->from(new Address($fromAddr, $fromName));
        }
        $em->replyTo(new Address($r['signer_email'], $r['signer_name']));
        $em->to(new Address($r['client_email']));
        $period = date('d/m/Y', strtotime($r['period_from'])) . ' a ' . date('d/m/Y', strtotime($r['period_to']));
        $em->subject('Relatório de indicadores de serviço - ' . $r['clients'] . ' - ' . $period);
        $nome = htmlspecialchars($r['signer_name']);
        $em->html('<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#0f1f3a;line-height:1.55">'
            . '<p>Prezado(a),</p>'
            . '<p>Segue em anexo o <b>relatório executivo de indicadores de serviço</b> referente ao período <b>' . $period . '</b> '
            . '(' . htmlspecialchars($r['clients']) . '), assinado digitalmente por <b>' . $nome . '</b>, Gestor responsável.</p>'
            . '<p>O relatório reúne SLA, volumetria, tempos de atendimento e resolução, monitoramento e alertas, e a equipe responsável.</p>'
            . '<p>Em caso de dúvidas, responda a este e-mail.</p>'
            . '<p style="margin-top:22px">Atenciosamente,<br><b>' . $nome . '</b><br>OAKMONT GROUP</p></div>');
        $em->attach((string) file_get_contents($path), 'Relatorio-KPIs-' . $r['period_from'] . '-a-' . $r['period_to'] . '-assinado.pdf', 'application/pdf');
        if ($mail->send()) {
            $this->update(['id' => $this->getID(), 'status' => 'sent', 'date_sent' => $_SESSION['glpi_currenttime'], 'last_error' => null, 'date_mod' => $_SESSION['glpi_currenttime']]);
            return 'sent';
        }
        $this->update(['id' => $this->getID(), 'status' => 'signed', 'last_error' => 'E-mail não enviado: ' . ($mail->getError() ?: 'verifique a configuração de e-mail do GLPI'), 'date_mod' => $_SESSION['glpi_currenttime']]);
        return 'signed';
    }

    /** Carrega uma solicitação do próprio usuário (ou de qualquer uma, para o administrador). */
    public static function load(int $id): self
    {
        $o = new self();
        if (!$o->getFromDB($id) || (!Session::haveRight('config', UPDATE) && (int) $o->fields['users_id'] !== (int) Session::getLoginUserID())) {
            throw new HttpError(404, 'Solicitação não encontrada.');
        }
        return $o;
    }

    public function cancel(): void
    {
        if ($this->fields['status'] === 'pending' && (int) $this->fields['file_id'] > 0) {
            try {
                (new LibreSign())->delete((int) $this->fields['file_id']);
            } catch (Throwable $e) {
                // se o LibreSign já não tem o arquivo, só marca como cancelado
            }
        }
        $this->update(['id' => $this->getID(), 'status' => 'canceled', 'date_mod' => $_SESSION['glpi_currenttime']]);
    }

    public static function cronInfo($name)
    {
        return ['description' => "Confere as assinaturas dos relatórios de KPI's e envia o PDF assinado ao cliente"];
    }

    public static function cronSyncsignoffs(CronTask $task): int
    {
        $n = 0;
        foreach ((new self())->find(['status' => 'pending'], ['id ASC'], 30) as $row) {
            $item = new self();
            if ($item->getFromDB($row['id']) && in_array($item->sync(), ['sent', 'signed'], true)) {
                $n++;
            }
            $task->addVolume(1);
        }
        $task->log("$n relatório(s) assinado(s)");
        return 1;
    }
}
