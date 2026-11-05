<?php

namespace GlpiPlugin\Operacional360;

use Config;
use GLPIKey;
use RuntimeException;

/**
 * Cliente mínimo da API OCS do LibreSign (Nextcloud). Usa a mesma conexão do plugin "Assinatura digital"
 * (Configurar → Plugins → Assinatura digital): endereço do Nextcloud, usuário de serviço e senha de aplicativo.
 */
class LibreSign
{
    private const CONTEXT = 'plugin:assinatura';

    private string $base;
    private string $user;
    private string $password;

    public function __construct()
    {
        $v = Config::getConfigurationValues(self::CONTEXT, ['nextcloud_url', 'username', 'app_password']);
        $this->base = rtrim((string) ($v['nextcloud_url'] ?? ''), '/');
        $this->user = (string) ($v['username'] ?? '');
        $pass = (string) ($v['app_password'] ?? '');
        $this->password = $pass !== '' ? (string) (new GLPIKey())->decrypt($pass) : '';
        if ($this->base === '' || $this->user === '' || $this->password === '') {
            throw new RuntimeException('O LibreSign não está configurado: configure o plugin "Assinatura digital" (Configurar → Plugins).');
        }
    }

    public function base(): string
    {
        return $this->base;
    }

    /** Cria a solicitação de assinatura (PDF em base64) e devolve os dados do arquivo (uuid, id, signers…). */
    public function requestSignature(string $name, string $pdf, array $signers): array
    {
        return $this->ocs('POST', '/request-signature', ['name' => $name, 'file' => ['base64' => base64_encode($pdf)], 'signers' => $signers]);
    }

    /** Campo de assinatura visível; coordenadas em pontos a partir do canto inferior esquerdo. */
    public function addVisibleSignature(string $fileUuid, int $fileId, int $signRequestId, int $page, array $box): array
    {
        return $this->ocs('POST', '/file-element/' . rawurlencode($fileUuid), [
            'signRequestId' => $signRequestId,
            'fileId'        => $fileId,
            'type'          => 'signature',
            'coordinates'   => ['page' => $page, 'llx' => $box['llx'], 'lly' => $box['lly'], 'urx' => $box['urx'], 'ury' => $box['ury']],
        ]);
    }

    public function validate(string $uuid): array
    {
        return $this->ocs('GET', '/file/validate/uuid/' . rawurlencode($uuid));
    }

    public function delete(int $fileId): array
    {
        return $this->ocs('DELETE', '/sign/file_id/' . $fileId);
    }

    /** PDF atual do arquivo (assinado, depois que todos assinam). */
    public function downloadPdf(string $uuid): string
    {
        [$code, $body, $type] = $this->http('GET', $this->base . '/index.php/apps/libresign/p/pdf/' . rawurlencode($uuid));
        if ($code !== 200 || !str_starts_with($body, '%PDF')) {
            throw new RuntimeException("Download do PDF assinado falhou (HTTP $code, $type)");
        }
        return $body;
    }

    private function ocs(string $method, string $path, ?array $json = null): array
    {
        [$code, $body] = $this->http($method, $this->base . '/ocs/v2.php/apps/libresign/api/v1' . $path, $json, ['OCS-APIRequest: true', 'Accept: application/json']);
        $decoded = json_decode($body, true);
        $data = $decoded['ocs']['data'] ?? null;
        if ($code >= 400 || !is_array($data)) {
            $msg = is_array($data) ? ($data['message'] ?? '') : '';
            throw new RuntimeException(trim("LibreSign HTTP $code " . ($msg ?: substr(strip_tags($body), 0, 200))));
        }
        return $data;
    }

    /** @return array{0:int,1:string,2:string} */
    private function http(string $method, string $url, ?array $json = null, array $headers = []): array
    {
        $ch = curl_init($url);
        $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERPWD => $this->user . ':' . $this->password, CURLOPT_HTTPHEADER => $headers];
        if ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($json);
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        if ($body === false) {
            throw new RuntimeException('Nextcloud inacessível: ' . curl_error($ch));
        }
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $body, (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
    }
}
