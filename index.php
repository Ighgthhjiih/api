<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const TIMEOUT = 30;

function resposta($dados, $codigo = 200)
{
    http_response_code($codigo);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

function buscarPagina($url)
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8'
        ]
    ]);

    $html = curl_exec($ch);

    $erro = curl_error($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($html === false) {
        return [
            'success' => false,
            'error' => $erro
        ];
    }

    if ($codigo < 200 || $codigo >= 400) {
        return [
            'success' => false,
            'error' => 'HTTP ' . $codigo
        ];
    }

    return [
        'success' => true,
        'html' => $html
    ];
}

function extrairSources($html)
{
    $padroes = [
        '/(?:var|let|const)\s+sources\s*=\s*(\[[\s\S]*?\])\s*;/i',
        '/sources\s*=\s*(\[[\s\S]*?\])\s*;/i'
    ];

    foreach ($padroes as $padrao) {

        if (!preg_match($padrao, $html, $matches)) {
            continue;
        }

        $json = trim($matches[1]);

        $sources = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            continue;
        }

        if (!is_array($sources)) {
            continue;
        }

        $resultado = [];

        foreach ($sources as $source) {

            if (!is_array($source)) {
                continue;
            }

            $file = trim($source['file'] ?? '');

            if ($file === '') {
                continue;
            }

            $resultado[] = [
                'file' => $file,
                'type' => $source['type'] ?? null,
                'label' => $source['label'] ?? null
            ];
        }

        if (!empty($resultado)) {
            return $resultado;
        }
    }

    return [];
}

$url = trim($_GET['url'] ?? '');

if ($url === '') {
    resposta([
        'success' => false,
        'message' => 'Informe a URL da página.'
    ], 400);
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    resposta([
        'success' => false,
        'message' => 'URL inválida.'
    ], 400);
}

$pagina = buscarPagina($url);

if (!$pagina['success']) {
    resposta([
        'success' => false,
        'message' => 'Não foi possível acessar a página.',
        'error' => $pagina['error']
    ], 502);
}

$sources = extrairSources($pagina['html']);

if (empty($sources)) {
    resposta([
        'success' => false,
        'message' => 'A variável sources não foi encontrada ou não contém fontes válidas.',
        'sources' => []
    ], 404);
}

$resposta = [];

foreach ($sources as $index => $source) {

    $resposta[] = [
        'index' => $index,
        'file' => $source['file'],
        'type' => $source['type'],
        'label' => $source['label'] ?: 'Servidor ' . ($index + 1)
    ];
}

resposta([
    'success' => true,
    'total' => count($resposta),
    'sources' => $resposta
]);
?>
