<?php
// ===== AUDITORIA EM HTML, MESMA ROTA =====
$tmdb_id = trim($_GET['tmdb_id'] ?? '');

if ($tmdb_id === '') {
    $tmdb_id = 'tt22084616'; // padrão pra teste
}

$debug = [];
$debug['passo_1_parametro'] = $tmdb_id;

// ETAPA 1: montar URL
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
    'contem ".m3u8"'   => stripos($html, '.m3u8')   !== false ? 'SIM' : 'NÃO',
    'contem ".mp4"'    => stripos($html, '.mp4')    !== false ? 'SIM' : 'NÃO',
];

// ETAPA 4: regex atual
$casou = preg_match('/var\s+sources\s*=\s*(\[[\s\S]*?\]);/', $html, $matches);
$debug['etapa_4_regex_atual'] = $casou ? 'CASOU' : 'não casou';

// ETAPA 5: variantes
$achou = null;
$variantes = [
    'const/let sources' => '/(?:const|let|var)\s+sources\s*=\s*(\[[\s\S]*?\])\s*;/',
    'array com "file"'  => '/(\[\s*\{[\s\S]*?"file"[\s\S]*?\])\s*;/',
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

// salva HTML bruto pra você abrir e comparar com o F12
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
        h2 { color: #ff0; }
        table { border-collapse: collapse; }
        td { border: 1px solid #333; padding: 6px 12px; vertical-align: top; }
        .falha { color: #f55; font-weight: bold; }
        .ok { color: #5f5; }
    </style>
</head>
<body>

<h1>Auditoria — <?= htmlspecialchars($tmdb_id) ?></h1>

<table>
<?php foreach ($debug as $etapa => $valor): ?>
    <tr>
        <td><strong><?= htmlspecialchars($etapa) ?></strong></td>
        <td class="<?= (is_string($valor) && str_contains($valor, 'não')) || (is_array($valor) && empty($valor)) ? 'falha' : 'ok' ?>">
            <?= htmlspecialchars(is_array($valor) ? print_r($valor, true) : $valor) ?>
        </td>
    </tr>
<?php endforeach; ?>
</table>

<h2>Primeiros 1000 caracteres do HTML recebido:</h2>
<pre style="background:#000; padding:10px; white-space:pre-wrap;"><?= htmlspecialchars(substr($html, 0, 1000)) ?></pre>

<p>HTML completo salvo em: <code><?= htmlspecialchars($arquivo) ?></code></p>

<!-- iframe carrega igual, na mesma rota -->
<iframe
    src="https://megaembed.com/embed/<?= htmlspecialchars($tmdb_id) ?>"
    width="100%" height="500"
    frameborder="0"
    allowfullscreen>
</iframe>

</body>
</html>
