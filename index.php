<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

define('TIMEOUT', 30);
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');
define('DEBUG', true); // modo auditoria: true = inclui etapas no JSON

// ==================== FUNÇÃO CURL ====================
function curlGet($url) {
    $headers = [
        'User-Agent: ' . USER_AGENT,
        'Accept: */*',
        'Accept-Language: pt-BR,pt;q=0.9',
        'Referer: ' . $url,
        'Connection: keep-alive',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error    = curl_error($ch);
    curl_close($ch);

    return [
        'success'   => ($httpCode >= 200 && $httpCode < 400),
        'content'   => (string)$response,
        'code'      => $httpCode,
        'final_url' => $finalUrl,
        'error'     => $error,
    ];
}

// ==================== AUDITORIA ====================
$tmdb_id = trim($_GET['tmdb_id'] ?? '') ?: 'tt22084616';
$debug   = [];

$embedUrl = 'https://mgeb.site/embed/' . $tmdb_id;
$debug['etapa_1_url'] = $embedUrl;

$data = curlGet($embedUrl);
$debug['etapa_2_http'] = [
    'success'      => $data['success'] ? 'SIM' : 'NÃO',
    'http_code'    => $data['code'],
    'final_url'    => $data['final_url'],
    'curl_error'   => $data['error'] ?: 'nenhum',
    'tamanho_html' => strlen($data['content']) . ' bytes',
];

$html = $data['content'];

$debug['etapa_3_conteudo'] = [
    'contem "sources"' => stripos($html, 'sources') !== false ? 'SIM' : 'NÃO',
    'contem ".m3u8"'   => stripos($html, '.m3u8') !== false ? 'SIM' : 'NÃO',
    'contem ".mp4"'    => stripos($html, '.mp4') !== false ? 'SIM' : 'NÃO',
];

$casou = preg_match('/var\s+sources\s*=\s*(\[[\s\S]*?\]);/', $html, $matches);
$debug['etapa_4_regex_atual'] = $casou ? 'CASOU' : 'nao casou';

$achou = null;
$variantes = [
    'const/let/var sources' => '/(?:const|let|var)\s+sources\s*=\s*(\[[\s\S]*?\])\s*;/',
    'array com "file"'      => '/(\[\s*\{[\s\S]*?"file"[\s\S]*?\])\s*;/',
];
foreach ($variantes as $nome => $rx) {
    if (preg_match($rx, $html, $m)) {
        $achou = ['variante' => $nome, 'trecho' => substr($m[1], 0, 300)];
        break;
    }
}
$debug['etapa_5_variantes'] = $achou ?? 'nenhuma casou';

$sources = [];
$bruto = $matches[1] ?? ($achou['trecho'] ?? '');
if ($bruto !== '') {
    $decoded = json_decode($bruto, true);
    $debug['etapa_6_decode'] = is_array($decoded)
        ? 'OK - ' . count($decoded) . ' itens'
        : 'FALHOU: ' . json_last_error_msg();

    if (is_array($decoded)) {
        foreach ($decoded as $item) {
            if (!empty($item['file'])) {
                $sources[] = [
                    'file'   => $item['file'],
                    'label'  => $item['label'] ?? null,
                    'type'   => $item['type'] ?? null,
                ];
            }
        }
    }
}
$debug['etapa_7_resultado'] = $sources ?: 'nenhuma source extraída';

// HTML bruto salvo pra inspeção manual
$arquivo = sys_get_temp_dir() . '/debug_' . preg_replace('/[^a-z0-9]/i', '', $tmdb_id) . '.html';
file_put_contents($arquivo, $html);

// ==================== RESPOSTA DA API ====================
echo json_encode([
    'success'     => !empty($sources),
    'tmdb_id'     => $tmdb_id,
    'embed_url'   => $embedUrl,
    'http_code'   => $data['code'],
    'sources'     => $sources,
    'html_salvo'  => $arquivo,
    'auditoria'   => DEBUG ? $debug : null,
    'timestamp'   => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
