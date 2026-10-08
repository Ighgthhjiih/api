<?php
header('Access-Control-Allow-Origin: *');

define('TIMEOUT', 30);
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');

// ==================== FUNÇÃO CURL ====================
function curlGet($url) {
    $headers = [
        'User-Agent: ' . USER_AGENT,
        'Accept: */*',
        'Accept-Language: pt-BR,pt;q=0.9',
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
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'success' => ($httpCode >= 200 && $httpCode < 400),
        'content' => $response,
        'code'    => $httpCode,
        'error'   => $error
    ];
}

// ==================== AUDITORIA ====================
$tmdb_id = trim($_GET['tmdb_id'] ?? '');
if ($tmdb_id === '') {
    $tmdb_id = 'tt22084616';
}

$debug = [];

// ETAPA 1: URL montada
$embedUrl = 'https://megaembed.com/embed/' . $tmdb_id;
$debug['etapa_1_url'] = $embedUrl;

// ETAPA 2: requisição HTTP
$data = curlGet($embedUrl);
$debug['etapa_2_http'] = [
    'success'      => $data['success'] ? 'SIM' : 'NÃO',
    'http_code'    => $data['code'],
    'curl_error'   => $data['error'] ?: 'nenhum',
    'tamanho_html' => strlen((string)$data['content']) . ' bytes',
];

$html = (string)$data['content'];

// ETAPA 3: o que veio no HTML
$debug['etapa_3_conteudo'] = [
    'contem "sources"' => stripos($html, 'sources') !== false ? 'SIM' : 'NÃO',
    'contem ".m3u8"'   => stripos($html, '.m3u8') !== false ? 'SIM' : 'NÃO',
    'contem ".mp4"'    => stripos($html, '.mp4') !== false ? 'SIM' : 'NÃO',
];

// ETAPA 4: regex original
$casou = preg_match('/var\s+sources\s*=\s*(\[[\s\S]*?\]);/', $html, $matches);
$debug['etapa_4_regex_atual'] = $casou ? 'CASOU' : 'nao casou';

// ETAPA 5: variantes
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

// ETAPA 6: decode
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
                $sources[] = $item['file'];
            }
        }
    }
}
$debug['etapa_7_resultado'] = $sources ?: 'nenhuma source extraída';

// salva HTML bruto pra inspeção manual
$arquivo = sys_get_temp_dir() . '/debug_' . preg_replace('/[^a-z0-9]/i', '', $tmdb_id) . '.html';
file_put_contents($arquivo, $html);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Auditoria</title>
    <style>
        body { font-family: monospace; background: #111; color: #0f0; padding: 20px; }
        h1, h2 { color: #ff0; }
        table { border-collapse: collapse; }
        td { border: 1px solid #333; padding: 6px 12px; vertical-align: top; }
        pre { background: #000; padding: 10px; white-space: pre-wrap; }
    </style>
</head>
<body>

<h1>Auditoria — <?= htmlspecialchars($tmdb_id) ?></h1>

<table>
<?php foreach ($debug as $etapa => $valor): ?>
    <tr>
        <td><strong><?= htmlspecialchars($etapa) ?></strong></td>
        <td><?= htmlspecialchars(is_array($valor) ? print_r($valor, true) : $valor) ?></td>
    </tr>
<?php endforeach; ?>
</table>

<h2>Primeiros 1000 caracteres do HTML recebido:</h2>
<pre><?= htmlspecialchars(substr($html, 0, 10000000000)) ?></pre>

<p>HTML completo salvo em: <code><?= htmlspecialchars($arquivo) ?></code></p>

<iframe
    src="https://megaembed.com/embed/<?= htmlspecialchars($tmdb_id) ?>"
    width="100%" height="500"
    frameborder="0"
    allowfullscreen>
</iframe>

</body>
</html>
