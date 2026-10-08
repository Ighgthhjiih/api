<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| CONFIGURAÇÃO
|--------------------------------------------------------------------------
*/

const TIMEOUT = 30;

const USER_AGENT =
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' .
    'AppleWebKit/537.36 (KHTML, like Gecko) ' .
    'Chrome/131.0.0.0 Safari/537.36';

/*
|--------------------------------------------------------------------------
| AUDITORIA
|--------------------------------------------------------------------------
*/

$debug = [];

function logDebug(string $mensagem, $dados = null): void
{
    global $debug;

    $item = [
        'hora' => date('Y-m-d H:i:s'),
        'mensagem' => $mensagem
    ];

    if ($dados !== null) {
        $item['dados'] = $dados;
    }

    $debug[] = $item;
}

function resposta(array $dados, int $httpCode = 200): void
{
    http_response_code($httpCode);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| DETECÇÃO DE CLOUDFLARE
|--------------------------------------------------------------------------
*/

function detectarCloudflare(string $html, int $httpCode): bool
{
    if ($httpCode === 403) {
        return true;
    }

    $indicadores = [
        'Just a moment',
        'Enable JavaScript and cookies to continue',
        'challenge-platform',
        'challenges.cloudflare.com',
        '_cf_chl_opt',
        'cf-ray',
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
| cURL
|--------------------------------------------------------------------------
*/

function curlGet(string $url, string $referer = ''): array
{
    logDebug('curlGet() iniciou', [
        'url' => $url,
        'referer' => $referer
    ]);

    if (!filter_var($url, FILTER_VALIDATE_URL)) {

        logDebug('ERRO: URL inválida', [
            'url' => $url
        ]);

        return [
            'success' => false,
            'content' => '',
            'code' => 0,
            'error' => 'URL inválida',
            'cloudflare' => false,
            'content_type' => '',
            'final_url' => '',
            'time' => 0
        ];
    }

    $headers = [
        'User-Agent: ' . USER_AGENT,
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
        'Connection: keep-alive'
    ];

    if ($referer !== '') {
        $headers[] = 'Referer: ' . $referer;

        logDebug('Referer configurado', [
            'referer' => $referer
        ]);
    }

    logDebug('Inicializando cURL');

    $ch = curl_init();

    if ($ch === false) {

        logDebug('ERRO: curl_init() falhou');

        return [
            'success' => false,
            'content' => '',
            'code' => 0,
            'error' => 'curl_init falhou',
            'cloudflare' => false,
            'content_type' => '',
            'final_url' => '',
            'time' => 0
        ];
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

        CURLOPT_HEADER => false

    ]);

    logDebug('Opções do cURL configuradas');

    $inicio = microtime(true);

    logDebug('Executando curl_exec()');

    $response = curl_exec($ch);

    $tempo = microtime(true) - $inicio;

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $contentType = curl_getinfo(
        $ch,
        CURLINFO_CONTENT_TYPE
    );

    $finalUrl = curl_getinfo(
        $ch,
        CURLINFO_EFFECTIVE_URL
    );

    $totalTime = curl_getinfo(
        $ch,
        CURLINFO_TOTAL_TIME
    );

    $error = curl_error($ch);

    $errorNumber = curl_errno($ch);

    curl_close($ch);

    $content = ($response !== false)
        ? $response
        : '';

    $cloudflare = detectarCloudflare(
        $content,
        $httpCode
    );

    logDebug('curl_exec() terminou', [
        'http_code' => $httpCode,
        'content_type' => $contentType,
        'final_url' => $finalUrl,
        'tempo' => round($tempo, 4),
        'curl_errno' => $errorNumber,
        'curl_error' => $error,
        'tamanho_resposta' => strlen($content),
        'cloudflare_detectado' => $cloudflare
    ]);

    if ($response === false) {

        logDebug('ERRO: curl_exec() retornou false', [
            'curl_errno' => $errorNumber,
            'curl_error' => $error
        ]);

        return [
            'success' => false,
            'content' => '',
            'code' => $httpCode,
            'error' => $error,
            'cloudflare' => false,
            'content_type' => $contentType,
            'final_url' => $finalUrl,
            'time' => $totalTime
        ];
    }

    if ($cloudflare) {

        logDebug(
            'CLOUDFLARE DETECTADO: resposta não contém o HTML real do embed',
            [
                'http_code' => $httpCode,
                'content_size' => strlen($content)
            ]
        );
    }

    $success = (
        $httpCode >= 200 &&
        $httpCode < 400 &&
        !$cloudflare
    );

    if ($success) {

        logDebug('Resposta HTTP válida e HTML aparentemente acessível');

    } else {

        logDebug('Resposta não pode ser processada como HTML do embed', [
            'http_code' => $httpCode,
            'cloudflare' => $cloudflare
        ]);
    }

    return [
        'success' => $success,
        'content' => $content,
        'code' => $httpCode,
        'error' => $error,
        'cloudflare' => $cloudflare,
        'content_type' => $contentType,
        'final_url' => $finalUrl,
        'time' => $totalTime
    ];
}

/*
|--------------------------------------------------------------------------
| EXTRAÇÃO DE SOURCES
|--------------------------------------------------------------------------
*/

function extrairSources(string $html): array
{
    logDebug('Iniciando procura por sources');

    $sources = [];

    /*
     * Aceita:
     *
     * var sources = [...]
     * let sources = [...]
     * const sources = [...]
     */

    $padroes = [

        '/(?:var|let|const)\s+sources\s*=\s*(\[[\s\S]*?\])\s*;/i',

        '/sources\s*=\s*(\[[\s\S]*?\])\s*;/i'

    ];

    $encontrou = false;

    foreach ($padroes as $indice => $padrao) {

        logDebug('Testando regex', [
            'indice' => $indice,
            'regex' => $padrao
        ]);

        $resultado = preg_match(
            $padrao,
            $html,
            $matches
        );

        if ($resultado === false) {

            logDebug('Erro interno no preg_match()', [
                'indice' => $indice,
                'erro' => preg_last_error_msg()
            ]);

            continue;
        }

        if ($resultado === 1) {

            $encontrou = true;

            logDebug('Variável sources encontrada', [
                'regex' => $indice,
                'tamanho' => strlen($matches[1] ?? '')
            ]);

            $jsonSources = $matches[1] ?? '';

            /*
             * Tentativa normal
             */

            $decoded = json_decode(
                $jsonSources,
                true
            );

            /*
             * Caso o conteúdo use aspas simples,
             * não tentamos executar JavaScript.
             * Apenas registramos o problema.
             */

            if (!is_array($decoded)) {

                logDebug(
                    'sources encontrada, mas não foi possível interpretar como JSON',
                    [
                        'json_error' => json_last_error_msg(),
                        'inicio' => substr(
                            $jsonSources,
                            0,
                            300
                        )
                    ]
                );

                continue;
            }

            logDebug('sources convertida para array', [
                'quantidade' => count($decoded)
            ]);

            foreach ($decoded as $index => $item) {

                logDebug('Processando source', [
                    'index' => $index
                ]);

                if (!is_array($item)) {

                    logDebug('Source ignorada: não é objeto');

                    continue;
                }

                if (empty($item['file'])) {

                    logDebug(
                        'Source ignorada: campo file ausente'
                    );

                    continue;
                }

                $file = trim(
                    (string)$item['file']
                );

                if (!filter_var($file, FILTER_VALIDATE_URL)) {

                    logDebug(
                        'Source ignorada: file não é URL válida',
                        [
                            'file' => $file
                        ]
                    );

                    continue;
                }

                $label = $item['label']
                    ?? ('Servidor ' . (count($sources) + 1));

                $tipo = 'mp4';

                if (
                    stripos($file, '.m3u8') !== false
                ) {
                    $tipo = 'hls';
                }

                $sources[] = [
                    'file' => $file,
                    'label' => $label,
                    'type' => $tipo
                ];

                logDebug('Source válida adicionada', [
                    'index' => $index,
                    'label' => $label,
                    'type' => $tipo
                ]);
            }

            /*
             * Se encontrou sources válidas,
             * não precisa testar outra regex.
             */

            if (!empty($sources)) {
                break;
            }
        }
    }

    if (!$encontrou) {

        logDebug(
            'Nenhuma variável sources foi encontrada no HTML'
        );
    }

    logDebug('Extração finalizada', [
        'total_sources' => count($sources)
    ]);

    return $sources;
}

/*
|--------------------------------------------------------------------------
| PARÂMETROS
|--------------------------------------------------------------------------
*/

logDebug('PHP iniciou a execução');

logDebug('Lendo parâmetros GET');

$tmdb_id = trim(
    $_GET['tmdb_id'] ?? ''
);

$url_direta = trim(
    $_GET['url'] ?? ''
);

$debugMode = (
    isset($_GET['debug']) &&
    $_GET['debug'] === '1'
);

logDebug('Parâmetros recebidos', [
    'tmdb_id' => $tmdb_id,
    'url' => $url_direta,
    'debug' => $debugMode
]);

/*
|--------------------------------------------------------------------------
| MODO TMDB
|--------------------------------------------------------------------------
*/

if ($tmdb_id !== '') {

    logDebug('MODO TMDB detectado');

    /*
     * Validação básica.
     *
     * Aceitamos IDs no formato:
     * tt1234567
     */

    if (
        !preg_match(
            '/^tt\d+$/i',
            $tmdb_id
        )
    ) {

        logDebug('TMDB ID inválido');

        resposta([
            'success' => false,
            'message' => 'TMDB ID inválido',
            'tmdb_id' => $tmdb_id,
            'debug' => $debugMode ? $debug : []
        ], 400);
    }

    $embedUrl =
        'https://megaembed.com/embed/' .
        rawurlencode($tmdb_id);

    logDebug('URL do embed montada', [
        'embed_url' => $embedUrl
    ]);

    /*
     * IMPORTANTE:
     *
     * Fazemos uma tentativa apenas para diagnóstico.
     *
     * Não tentamos contornar Cloudflare.
     */

    logDebug(
        'Testando acessibilidade do embed pelo servidor'
    );

    $data = curlGet(
        $embedUrl,
        'https://megaembed.com/'
    );

    /*
     * CLOUDFLARE
     */

    if ($data['cloudflare']) {

        logDebug(
            'Acesso bloqueado por proteção anti-bot'
        );

        resposta([
            'success' => false,

            'message' =>
                'O servidor recebeu uma página de proteção do Cloudflare em vez do HTML do embed.',

            'reason' => 'cloudflare_challenge',

            'http_code' => $data['code'],

            'embed_url' => $embedUrl,

            'content_size' =>
                strlen($data['content']),

            /*
             * Isto informa o frontend que
             * ele precisa carregar o embed diretamente
             * no navegador, se permitido pelo serviço.
             */

            'next_step' => 'load_embed_in_browser',

            'debug' => $debugMode ? $debug : []

        ], 502);
    }

    /*
     * OUTROS ERROS
     */

    if (!$data['success']) {

        logDebug(
            'Falha ao acessar embed',
            [
                'http_code' => $data['code'],
                'error' => $data['error']
            ]
        );

        resposta([
            'success' => false,

            'message' =>
                'Não foi possível acessar o embed pelo servidor.',

            'reason' => 'embed_request_failed',

            'http_code' => $data['code'],

            'error' => $data['error'],

            'embed_url' => $embedUrl,

            'debug' => $debugMode ? $debug : []

        ], 502);
    }

    /*
     * HTML ACESSÍVEL
     */

    logDebug('HTML do embed recebido');

    $html = $data['content'];

    /*
     * Procura sources
     */

    $sources = extrairSources($html);

    if (empty($sources)) {

        logDebug(
            'HTML foi recebido, mas nenhuma source válida foi encontrada'
        );

        resposta([
            'success' => false,

            'message' =>
                'O HTML foi acessado, mas nenhuma source válida foi encontrada.',

            'reason' => 'sources_not_found',

            'tmdb_id' => $tmdb_id,

            'embed_url' => $embedUrl,

            'content_size' => strlen($html),

            'sources' => [],

            'total' => 0,

            'debug' => $debugMode ? $debug : []

        ], 422);
    }

    /*
     * SUCESSO
     */

    logDebug(
        'SUCESSO: sources encontradas',
        [
            'total' => count($sources)
        ]
    );

    resposta([
        'success' => true,

        'message' => 'Sources encontradas com sucesso.',

        'tmdb_id' => $tmdb_id,

        'sources' => $sources,

        'total' => count($sources),

        'debug' => $debugMode ? $debug : []

    ]);
}

/*
|--------------------------------------------------------------------------
| MODO URL
|--------------------------------------------------------------------------
*/

if ($url_direta !== '') {

    logDebug('MODO URL DIRETA detectado');

    /*
     * Só aceitamos HTTP/HTTPS
     */

    $parsed = parse_url($url_direta);

    if (
        !$parsed ||
        !isset($parsed['scheme']) ||
        !in_array(
            strtolower($parsed['scheme']),
            ['http', 'https'],
            true
        )
    ) {

        logDebug('URL direta inválida');

        resposta([
            'success' => false,
            'message' => 'URL inválida. Use HTTP ou HTTPS.',
            'debug' => $debugMode ? $debug : []
        ], 400);
    }

    logDebug('Testando URL direta');

    $result = curlGet(
        $url_direta,
        'https://megaembed.com/'
    );

    /*
     * Cloudflare
     */

    if ($result['cloudflare']) {

        logDebug(
            'URL direta retornou proteção Cloudflare'
        );

        resposta([
            'success' => false,

            'message' =>
                'A URL retornou uma página de proteção do Cloudflare.',

            'reason' => 'cloudflare_challenge',

            'http_code' => $result['code'],

            'url' => $url_direta,

            'debug' => $debugMode ? $debug : []

        ], 502);
    }

    /*
     * Erro HTTP
     */

    if (!$result['success']) {

        logDebug(
            'Falha na URL direta'
        );

        resposta([
            'success' => false,

            'message' =>
                'Falha ao acessar a URL.',

            'reason' => 'request_failed',

            'http_code' => $result['code'],

            'error' => $result['error'],

            'debug' => $debugMode ? $debug : []

        ], 502);
    }

    /*
     * Tenta encontrar sources
     */

    $sources = extrairSources(
        $result['content']
    );

    if (!empty($sources)) {

        logDebug(
            'Sources encontradas na URL direta'
        );

        resposta([
            'success' => true,

            'message' =>
                'Sources encontradas.',

            'sources' => $sources,

            'total' => count($sources),

            'debug' => $debugMode ? $debug : []

        ]);
    }

    /*
     * Caso seja conteúdo de vídeo
     */

    $contentType = strtolower(
        $result['content_type'] ?? ''
    );

    if (
        str_contains($contentType, 'video/') ||
        str_contains($contentType, 'mpegurl')
    ) {

        logDebug(
            'Conteúdo identificado como mídia'
        );

        resposta([
            'success' => true,

            'message' =>
                'URL de mídia acessível.',

            'url' => $url_direta,

            'content_type' =>
                $result['content_type'],

            'debug' => $debugMode ? $debug : []

        ]);
    }

    /*
     * HTML sem sources
     */

    logDebug(
        'URL acessível, porém nenhuma source encontrada'
    );

    resposta([
        'success' => false,

        'message' =>
            'A URL foi acessada, mas nenhuma source foi encontrada.',

        'reason' => 'sources_not_found',

        'url' => $url_direta,

        'content_type' =>
            $result['content_type'],

        'content_size' =>
            strlen($result['content']),

        'debug' => $debugMode ? $debug : []

    ], 422);
}

/*
|--------------------------------------------------------------------------
| NENHUM PARÂMETRO
|--------------------------------------------------------------------------
*/

logDebug(
    'Nenhum parâmetro recebido'
);

resposta([
    'success' => false,

    'message' =>
        'Informe ?tmdb_id=ID ou ?url=LINK',

    'exemplos' => [
        '?tmdb_id=tt22084616',
        '?url=https://exemplo.com/video.m3u8'
    ],

    'debug' => $debugMode ? $debug : []

], 400);
