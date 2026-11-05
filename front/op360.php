<?php

/**
 * Operacional 360 servido pelo próprio GLPI (sem servidor à parte):
 *
 *   op360.php?p=/          → página do painel
 *   op360.php?p=/api?…     → dados (Api::route), com o login e o direito do GLPI
 *   op360.php?p=/static/…  → arquivos do painel (pasta public/static do plugin)
 *
 * O navegador só fala com o GLPI. Os caminhos absolutos da página (/api, /static) são
 * reescritos para passar por este arquivo.
 */

use GlpiPlugin\Operacional360\Api;
use GlpiPlugin\Operacional360\HttpError;
use GlpiPlugin\Operacional360\Menu;

Session::checkRight(Menu::$rightname, READ);
if (!\GlpiPlugin\Operacional360\Settings::canView()) {
    http_response_code(403);
    echo 'Acesso restrito aos usuários configurados.';
    return;
}
// a página faz várias chamadas ao mesmo tempo: libera a sessão para não enfileirar
session_write_close();

global $CFG_GLPI;
$prefix = $CFG_GLPI['root_doc'] . '/plugins/operacional360/front/op360.php?p=';

// caminho pedido: tudo depois de "p=" (ou os próprios filtros da página, sem "p=")
$qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
$path = str_starts_with($qs, 'p=') ? substr($qs, 2) : ($qs !== '' ? '/?' . $qs : '/');
if (str_starts_with($path, '%2F')) {
    $path = rawurldecode($path);
}
[$route, $query] = array_pad(explode('?', $path, 2), 2, '');
parse_str($query, $q);
$route = rawurldecode($route);
if ($route === '' || str_contains($route, '..') || !preg_match('#^/(|api|pdf|static/.+)$#', $route)) {
    http_response_code(404);
    return;
}

// ------------------------------------------------------------------ dados
if ($route === '/api') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    @set_time_limit(180);
    try {
        $out = Api::route((string) ($q['action'] ?? ''), $q);
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    } catch (HttpError $e) {
        http_response_code($e->status());
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    return;
}

// ------------------------------------------------------------------ PDF (A4 paisagem), gerado no servidor
if ($route === '/pdf') {
    try {
        @set_time_limit(180);
        $d = Api::data($q);
        $pdf = \GlpiPlugin\Operacional360\Pdf::build($d, \GlpiPlugin\Operacional360\Signoff::manager());
        $f = $d['filters'];
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Operacional 360 - KPIs - ' . $f['from'] . ' a ' . $f['to'] . '.pdf"');
        header('Cache-Control: no-store');
        echo $pdf;
    } catch (Throwable $e) {
        http_response_code($e instanceof HttpError ? $e->status() : 502);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Não foi possível gerar o PDF: ' . $e->getMessage();
    }
    return;
}

// ------------------------------------------------------------------ arquivos da página
$root = realpath(dirname(__DIR__) . '/public/static');
$file = $route === '/' ? $root . '/index.html' : $root . substr($route, strlen('/static'));
$real = $root !== false ? realpath($file) : false;
if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
    http_response_code(404);
    return;
}

$types = ['html' => 'text/html; charset=UTF-8', 'css' => 'text/css; charset=UTF-8', 'js' => 'text/javascript; charset=UTF-8',
    'json' => 'application/json', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml',
    'webp' => 'image/webp', 'gif' => 'image/gif'];
$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');

if (!in_array($ext, ['html', 'css', 'js'], true)) {
    header('Cache-Control: private, max-age=86400');
    readfile($real);
    return;
}

$body = (string) file_get_contents($real);
// caminhos absolutos (/api, /static) passam a apontar para este arquivo
$body = preg_replace('#(["\'`(=])/(api|static)(?=[/?"\'`)\s]|$)#', '$1' . str_replace('$', '\\$', $prefix) . '/$2', $body);
if ($ext === 'html') {
    $config = json_encode(['api' => $prefix . '/api', 'pdf' => $prefix . '/pdf', 't' => \GlpiPlugin\Operacional360\Signoff::token()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    $body = preg_replace('#</head>#i', '<script>window.OP360 = ' . $config . ';</script></head>', $body, 1);
    header('Cache-Control: no-cache');
} else {
    header('Cache-Control: private, max-age=300');
}
echo $body;
