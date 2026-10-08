<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| CONFIGURAÇÕES
|--------------------------------------------------------------------------
*/

const TIMEOUT = 30;

const USER_AGENT =
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' .
    'AppleWebKit/537.36 (KHTML, like Gecko) ' .
    'Chrome/154.0.0.0 Safari/537.36';

/*
|--------------------------------------------------------------------------
| AUDITORIA
|--------------------------------------------------------------------------
*/

$debug = isset($_GET['debug']) && $_GET['debug'] == '1';

$logs = [];

function logDebug($mensagem, $dados = null)
{
    global $logs;

    $item = [
        'time' => date('H:i:s'),
        'message' => $mensagem
    ];

    if ($dados !== null) {
        $item['data'] = $dados;
    }

    $logs[] = $item;
}

function resposta($dados, $status = 200)
{
    global $debug, $logs;

    http_response_code($status);

    if ($debug) {
        $dados['debug'] = $logs;
    }

    echo json_encode(
        $dados,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| DETECTAR CLOUDFLARE
|--------------------------------------------------------------------------
*/

function detectarCloudflare($html, $httpCode)
{
    if ($httpCode == 403) {
        return true;
    }

    $indicadores = [
        'Just a moment',
        'Enable JavaScript and cookies to continue',
        'challenges.cloudflare.com',
        '_cf_chl_opt',
        'cf-chl',
        'Cloudflare'
    ];

    foreach ($indicadores as $indicador) {

        if (stripos($html, $indicador) !== false) {
            return true;
        }
    }

    return false;
}

/*
|--------------------------------------------------------------------------
| CURL GET
|--------------------------------------------------------------------------
*/

function curlGet($url, $referer = null)
{
    logDebug('Iniciando requisição cURL', [
        'url' => $url
    ]);

    if (!filter_var($url, FILTER_VALIDATE_URL)) {

        logDebug('URL inválida');

        return [
            'success' => false,
            'error' => 'URL inválida',
            'http_code' => 0,
            'content' => ''
        ];
    }

    $ch = curl_init();

    if (!$ch) {

        logDebug('Falha ao inicializar cURL');

        return [
            'success' => false,
            'error' => 'Não foi possível inicializar cURL',
            'http_code' => 0,
            'content' => ''
        ];
    }

    $headers = [
        'User-Agent: ' . USER_AGENT,
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
        'Accept-Encoding: gzip, deflate',
        'Connection: keep-alive'
    ];

    if ($referer) {
        $headers[] = 'Referer: ' . $referer;

        logDebug('Referer configurado', [
            'referer' => $referer
        ]);
    }

    curl_setopt_array($ch, [

        CURLOPT_URL => $url,

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_FOLLOWLOCATION => true,

        CURLOPT_MAXREDIRS => 5,

        CURLOPT_CONNECTTIMEOUT => 10,

        CURLOPT_TIMEOUT => TIMEOUT,

        CURLOPT_HTTPHEADER => $headers,

        CURLOPT_ENCODING => '',

        CURLOPT_SSL_VERIFYPEER => true,

        CURLOPT_SSL_VERIFYHOST => 2,

        CURLOPT_HEADER => false,

    ]);

    logDebug('cURL configurado');

    $inicio = microtime(true);

    $content = curl_exec($ch);

    $tempo = round(microtime(true) - $inicio, 3);

    $errno = curl_errno($ch);
    $error = curl_error($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    curl_close($ch);

    $tamanho = strlen($content ?: '');

    logDebug('cURL finalizado', [
        'http_code' => $httpCode,
        'content_type' => $contentType,
        'final_url' => $finalUrl,
        'tempo' => $tempo,
        'curl_errno' => $errno,
        'curl_error' => $error,
        'content_size' => $tamanho
    ]);

    if ($errno !== 0) {

        logDebug('Erro de transporte do cURL', [
            'errno' => $errno,
            'error' => $error
        ]);

        return [
            'success' => false,
            'error' => $error,
            'http_code' => $httpCode,
            'content' => $content ?: '',
            'content_type' => $contentType,
            'final_url' => $finalUrl,
            'time' => $tempo
        ];
    }

    $cloudflare = detectarCloudflare(
        $content ?: '',
        $httpCode
    );

    logDebug('Resultado da detecção de proteção', [
        'cloudflare_detected' => $cloudflare
    ]);

    return [
        'success' => true,
        'error' => null,
        'http_code' => $httpCode,
        'content' => $content ?: '',
        'content_type' => $contentType,
        'final_url' => $finalUrl,
        'time' => $tempo,
        'cloudflare' => $cloudflare
    ];
}

/*
|--------------------------------------------------------------------------
| EXTRAIR SOURCES
|--------------------------------------------------------------------------
*/

function extrairSources($html)
{
    logDebug('Iniciando procura pela variável sources');

    if (!$html) {

        logDebug('HTML vazio');

        return [
            'success' => false,
            'reason' => 'empty_html',
            'sources' => []
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | MÉTODO 1
    | var sources = [...]
    |--------------------------------------------------------------------------
    */

    $padrao1 = '/var\s+sources\s*=\s*(\[[\s\S]*?\]);/i';

    if (preg_match($padrao1, $html, $match)) {

        logDebug('Variável "sources" encontrada pelo padrão 1');

        $json = trim($match[1]);

        $sources = json_decode($json, true);

        if (json_last_error() === JSON_ERROR_NONE) {

            logDebug('JSON de sources decodificado corretamente', [
                'quantidade' => count($sources)
            ]);

            return [
                'success' => true,
                'method' => 'var_sources',
                'sources' => $sources
            ];
        }

        logDebug('sources encontrada, mas JSON inválido', [
            'json_error' => json_last_error_msg()
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | MÉTODO 2
    | window.sources = [...]
    |--------------------------------------------------------------------------
    */

    $padrao2 = '/window\.sources\s*=\s*(\[[\s\S]*?\]);/i';

    if (preg_match($padrao2, $html, $match)) {

        logDebug('Variável "window.sources" encontrada');

        $json = trim($match[1]);

        $sources = json_decode($json, true);

        if (json_last_error() === JSON_ERROR_NONE) {

            logDebug('window.sources decodificado corretamente');

            return [
                'success' => true,
                'method' => 'window_sources',
                'sources' => $sources
            ];
        }

        logDebug('window.sources encontrada, mas JSON inválido');
    }

    /*
    |--------------------------------------------------------------------------
    | MÉTODO 3
    | Procurar apenas pela palavra sources
    |--------------------------------------------------------------------------
    */

    if (stripos($html, 'sources') !== false) {

        logDebug('A palavra "sources" existe no HTML, mas o padrão esperado não foi encontrado');

        return [
            'success' => false,
            'reason' => 'sources_found_but_not_parsed',
            'sources' => []
        ];
    }

    logDebug('Nenhuma variável sources encontrada');

    return [
        'success' => false,
        'reason' => 'sources_not_found',
        'sources' => []
    ];
}

/*
|--------------------------------------------------------------------------
| INÍCIO
|--------------------------------------------------------------------------
*/

logDebug('PHP iniciado');

$tmdbId = isset($_GET['tmdb_id'])
    ? trim($_GET['tmdb_id'])
    : '';

$url = isset($_GET['url'])
    ? trim($_GET['url'])
    : '';

logDebug('Parâmetros recebidos', [
    'tmdb_id' => $tmdbId,
    'url' => $url,
    'debug' => $debug
]);

/*
|--------------------------------------------------------------------------
| VALIDAÇÃO
|--------------------------------------------------------------------------
*/

if ($tmdbId === '' && $url === '') {

    logDebug('Nenhum parâmetro recebido');

    resposta([
        'success' => false,
        'message' => 'Informe tmdb_id ou url.',
        'example' => '?tmdb_id=tt22084616&debug=1'
    ], 400);
}

/*
|--------------------------------------------------------------------------
| MODO TMDB
|--------------------------------------------------------------------------
*/

if ($tmdbId !== '') {

    logDebug('Modo TMDB ativado');

    if (!preg_match('/^tt\d+$/', $tmdbId)) {

        logDebug('TMDB ID parece inválido', [
            'tmdb_id' => $tmdbId
        ]);

        resposta([
            'success' => false,
            'message' => 'TMDB/IMDb ID inválido.',
            'tmdb_id' => $tmdbId
        ], 400);
    }

    $embedUrl = 'https://megaembed.com/embed/' . rawurlencode($tmdbId);

    logDebug('URL do embed criada', [
        'embed_url' => $embedUrl
    ]);

    logDebug('Tentando acessar o embed diretamente pelo servidor');

    $resultado = curlGet(
        $embedUrl,
        'https://megaembed.com/'
    );

    /*
    |--------------------------------------------------------------------------
    | ERRO CURL
    |--------------------------------------------------------------------------
    */

    if (!$resultado['success']) {

        logDebug('Não foi possível completar a requisição');

        resposta([
            'success' => false,
            'message' => 'Erro ao acessar o embed pelo servidor.',
            'reason' => 'curl_error',
            'error' => $resultado['error'],
            'http_code' => $resultado['http_code'],
            'embed_url' => $embedUrl
        ], 502);
    }

    /*
    |--------------------------------------------------------------------------
    | CLOUDFLARE
    |--------------------------------------------------------------------------
    */

    if ($resultado['cloudflare']) {

        logDebug('Cloudflare detectado');

        logDebug(
            'O servidor recebeu uma página de proteção em vez do HTML do embed'
        );

        resposta([
            'success' => false,

            'message' =>
                'O servidor recebeu uma página de proteção do Cloudflare em vez do HTML do embed.',

            'reason' => 'cloudflare_challenge',

            'http_code' => $resultado['http_code'],

            'embed_url' => $embedUrl,

            'content_type' => $resultado['content_type'],

            'content_size' => strlen($resultado['content']),

            'next_step' =>
                'O embed precisa ser carregado em um navegador ou acessado por uma API/endereço autorizado pelo provedor.'
        ], 403);
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP DIFERENTE DE 200
    |--------------------------------------------------------------------------
    */

    if ($resultado['http_code'] < 200 ||
        $resultado['http_code'] >= 300) {

        logDebug('Servidor respondeu com HTTP diferente de 2xx', [
            'http_code' => $resultado['http_code']
        ]);

        resposta([
            'success' => false,
            'message' => 'O servidor do embed respondeu com erro HTTP.',
            'reason' => 'http_error',
            'http_code' => $resultado['http_code'],
            'embed_url' => $embedUrl,
            'content_size' => strlen($resultado['content'])
        ], 502);
    }

    /*
    |--------------------------------------------------------------------------
    | EXTRAIR SOURCES
    |--------------------------------------------------------------------------
    */

    logDebug('HTML recebido. Iniciando extração');

    $extracao = extrairSources(
        $resultado['content']
    );

    if (!$extracao['success']) {

        logDebug('Não foi possível extrair sources', [
            'reason' => $extracao['reason']
        ]);

        resposta([
            'success' => false,
            'message' => 'HTML recebido, mas a variável sources não foi encontrada ou não pôde ser interpretada.',
            'reason' => $extracao['reason'],
            'http_code' => $resultado['http_code'],
            'embed_url' => $embedUrl,
            'content_size' => strlen($resultado['content'])
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZAR SOURCES
    |--------------------------------------------------------------------------
    */

    $sourcesFinal = [];

    foreach ($extracao['sources'] as $index => $source) {

        if (!is_array($source)) {

            logDebug('Source ignorada porque não é objeto', [
                'index' => $index
            ]);

            continue;
        }

        $file = isset($source['file'])
            ? trim($source['file'])
            : '';

        $label = isset($source['label'])
            ? trim($source['label'])
            : '';

        if ($file === '') {

            logDebug('Source sem file ignorada', [
                'index' => $index
            ]);

            continue;
        }

        $tipo = 'mp4';

        if (stripos($file, '.m3u8') !== false) {
            $tipo = 'hls';
        }

        $sourcesFinal[] = [
            'file' => $file,
            'label' => $label,
            'type' => $tipo
        ];

        logDebug('Source adicionada', [
            'index' => $index,
            'file' => $file,
            'label' => $label,
            'type' => $tipo
        ]);
    }

    if (count($sourcesFinal) === 0) {

        logDebug('Nenhuma source válida encontrada');

        resposta([
            'success' => false,
            'message' => 'A variável sources foi encontrada, mas nenhuma source válida foi extraída.',
            'reason' => 'empty_sources',
            'embed_url' => $embedUrl
        ], 422);
    }

    logDebug('Processamento concluído com sucesso', [
        'total_sources' => count($sourcesFinal)
    ]);

    resposta([
        'success' => true,
        'message' => 'Sources encontradas com sucesso.',
        'tmdb_id' => $tmdbId,
        'embed_url' => $embedUrl,
        'sources' => $sourcesFinal,
        'total' => count($sourcesFinal)
    ]);
}

/*
|--------------------------------------------------------------------------
| MODO URL
|--------------------------------------------------------------------------
*/

if ($url !== '') {

    logDebug('Modo URL ativado', [
        'url' => $url
    ]);

    if (!filter_var($url, FILTER_VALIDATE_URL)) {

        logDebug('URL fornecida é inválida');

        resposta([
            'success' => false,
            'message' => 'URL inválida.'
        ], 400);
    }

    $resultado = curlGet($url);

    if (!$resultado['success']) {

        resposta([
            'success' => false,
            'message' => 'Erro ao acessar URL.',
            'reason' => 'curl_error',
            'error' => $resultado['error'],
            'http_code' => $resultado['http_code']
        ], 502);
    }

    if ($resultado['cloudflare']) {

        logDebug('Cloudflare detectado na URL fornecida');

        resposta([
            'success' => false,
            'message' => 'A URL retornou uma página de proteção do Cloudflare.',
            'reason' => 'cloudflare_challenge',
            'http_code' => $resultado['http_code'],
            'url' => $url,
            'content_size' => strlen($resultado['content'])
        ], 403);
    }

    $extracao = extrairSources(
        $resultado['content']
    );

    if ($extracao['success']) {

        resposta([
            'success' => true,
            'message' => 'Sources encontradas.',
            'url' => $url,
            'sources' => $extracao['sources'],
            'total' => count($extracao['sources'])
        ]);
    }

    resposta([
        'success' => false,
        'message' => 'A página foi acessada, mas não foi possível encontrar sources.',
        'reason' => $extracao['reason'],
        'url' => $url,
        'content_size' => strlen($resultado['content'])
    ], 422);
}

/*
|--------------------------------------------------------------------------
| FALLBACK
|--------------------------------------------------------------------------
*/

logDebug('Nenhum fluxo conseguiu processar a requisição');

resposta([
    'success' => false,
    'message' => 'Não foi possível processar a requisição.'
], 500);
?>

