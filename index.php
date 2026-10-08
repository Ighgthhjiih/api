<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('TIMEOUT', 30);

define(
    'USER_AGENT',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36'
);

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

function curlGet($url, $referer = '')
{
    $headers = [
        'User-Agent: ' . USER_AGENT,
        'Accept: */*',
        'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
        'Connection: keep-alive'
    ];

    if (!empty($referer)) {
        $headers[] = 'Referer: ' . $referer;
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => ''
    ]);

    $response = curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    return [
        'success' => ($response !== false && $httpCode >= 200 && $httpCode < 400),
        'content' => $response,
        'code' => $httpCode,
        'error' => $error
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

            if (empty($source['file'])) {
                continue;
            }

            $resultado[] = [
                'file' => $source['file'],
                'label' => $source['label'] ?? 'Servidor ' . (count($resultado) + 1),
                'type' => $source['type'] ?? (
                    str_contains(
                        strtolower($source['file']),
                        '.m3u8'
                    )
                        ? 'hls'
                        : 'mp4'
                )
            ];
        }

        if (!empty($resultado)) {
            return $resultado;
        }
    }

    return [];
}

$tmdb_id = trim($_GET['tmdb_id'] ?? '');
$url_direta = trim($_GET['url'] ?? '');

if (!empty($tmdb_id)) {

    if (!preg_match('/^[0-9]+$/', $tmdb_id)) {
        resposta([
            'success' => false,
            'message' => 'TMDB ID inválido.'
        ], 400);
    }

    $embedUrl = 'https://megaembed.com/embed/' . $tmdb_id;

    $data = curlGet(
        $embedUrl,
        'https://megaembed.com/'
    );

    if (!$data['success'] || empty($data['content'])) {

        resposta([
            'success' => false,
            'message' => 'Erro ao acessar o embed.',
            'code' => $data['code'],
            'error' => $data['error']
        ], 502);
    }

    $sources = extrairSources($data['content']);

    if (empty($sources)) {

        resposta([
            'success' => false,
            'message' => 'Nenhuma fonte encontrada.',
            'sources' => [],
            'total' => 0
        ], 404);
    }

    resposta([
        'success' => true,
        'sources' => $sources,
        'total' => count($sources)
    ]);
}

if (!empty($url_direta)) {

    if (!filter_var($url_direta, FILTER_VALIDATE_URL)) {

        resposta([
            'success' => false,
            'message' => 'URL inválida.'
        ], 400);
    }

    $result = curlGet(
        $url_direta,
        'https://megaembed.com/'
    );

    if (!$result['success'] || empty($result['content'])) {

        resposta([
            'success' => false,
            'message' => 'Falha ao carregar o vídeo.',
            'code' => $result['code'],
            'error' => $result['error']
        ], 404);
    }

    if (
        str_contains(
            strtolower($url_direta),
            '.m3u8'
        )
    ) {

        header(
            'Content-Type: application/vnd.apple.mpegurl'
        );

        echo $result['content'];

        exit;
    }

    header('Content-Type: video/mp4');

    header(
        'Content-Length: ' .
        strlen($result['content'])
    );

    echo $result['content'];

    exit;
}

resposta([
    'success' => false,
    'message' => 'Use ?tmdb_id=ID ou ?url=LINK_DO_VIDEO'
], 400);

?>
