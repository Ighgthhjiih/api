<?php

$API = 'https://php-api-studio.lovable.app/api/public/proxy';

// Repassa só os parâmetros que a API entende
$parametros = [];
foreach (['tmdb_id', 'url'] as $chave) {
    if (isset($_GET[$chave]) && $_GET[$chave] !== '') {
        $parametros[$chave] = (string) $_GET[$chave];
    }
}

$alvo = $API . ($parametros ? '?' . http_build_query($parametros) : '');

$ch = curl_init($alvo);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 40,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => array_filter([
        'Accept: */*',
        'Accept-Language: pt-BR,pt;q=0.9',
        isset($_SERVER['HTTP_RANGE']) ? 'Range: ' . $_SERVER['HTTP_RANGE'] : null,
    ]),
]);

$resposta = curl_exec($ch);

if ($resposta === false) {
    $erro = curl_error($ch);
    curl_close($ch);
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Falha ao chamar a API', 'detalhe' => $erro]);
    exit;
}

$info      = curl_getinfo($ch);
$status    = (int) $info['http_code'];
$corpo     = (string) substr((string) $resposta, (int) $info['header_size']);
$cabecalho = (string) substr((string) $resposta, 0, (int) $info['header_size']);
curl_close($ch);

// Devolve o content-type que a API mandou (JSON, playlist ou vídeo)
if (preg_match('~^content-type:\s*(.+)$~mi', $cabecalho, $m)) {
    header('Content-Type: ' . trim($m[1]), true, $status);
} else {
    http_response_code($status);
}

echo $corpo;
?>
