<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

$debug = [];

function logDebug($mensagem, $dados = null)
{
    global $debug;

    $item = [
        'mensagem' => $mensagem
    ];

    if ($dados !== null) {
        $item['dados'] = $dados;
    }

    $debug[] = $item;
}

logDebug('PHP iniciou a execução');

define('TIMEOUT', 30);

define(
    'USER_AGENT',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
);

logDebug('Configurações carregadas', [
    'timeout' => TIMEOUT,
    'user_agent' => USER_AGENT
]);

function curlGet($url, $referer = '')
{
    logDebug('curlGet() iniciou', [
        'url' => $url,
        'referer' => $referer
    ]);

    $headers = [
        'User-Agent: ' . USER_AGENT,
        'Accept: */*',
        'Accept-Language: pt-BR,pt;q=0.9',
        'Connection: keep-alive',
    ];

    if (!empty($referer)) {
        $headers[] = 'Referer: ' . $referer;

        logDebug('Referer adicionado', [
            'referer' => $referer
        ]);
    }

    logDebug('Inicializando cURL');

    $ch = curl_init($url);

    if ($ch === false) {
        logDebug('ERRO: curl_init() falhou');

        return [
            'success' => false,
            'content' => '',
            'code' => 0,
            'error' => 'curl_init falhou'
        ];
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');

    logDebug('Opções do cURL configuradas');

    logDebug('Executando curl_exec()');

    $response = curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);

    $error = curl_error($ch);
    $errorNumber = curl_errno($ch);

    curl_close($ch);

    logDebug('curl_exec() terminou', [
        'http_code' => $httpCode,
        'content_type' => $contentType,
        'final_url' => $finalUrl,
        'tempo' => $totalTime,
        'curl_errno' => $errorNumber,
        'curl_error' => $error,
        'tamanho_resposta' => $response !== false ? strlen($response) : 0
    ]);

    if ($response === false) {

        logDebug('ERRO: curl_exec retornou false');

        return [
            'success' => false,
            'content' => '',
            'code' => $httpCode,
            'error' => $error
        ];
    }

    $success = ($httpCode >= 200 && $httpCode < 400);

    logDebug(
        $success
            ? 'cURL retornou HTTP válido'
            : 'ERRO: cURL retornou HTTP inválido',
        [
            'http_code' => $httpCode
        ]
    );

    return [
        'success' => $success,
        'content' => $response,
        'code' => $httpCode,
        'error' => $error
    ];
}

logDebug('Lendo parâmetros GET');

$tmdb_id = trim($_GET['tmdb_id'] ?? '');
$url_direta = trim($_GET['url'] ?? '');

logDebug('Parâmetros recebidos', [
    'tmdb_id' => $tmdb_id,
    'url' => $url_direta
]);

/*
|--------------------------------------------------------------------------
| MODO 1 - TMDB
|--------------------------------------------------------------------------
*/

if (!empty($tmdb_id)) {

    logDebug('MODO TMDB detectado');

    $embedUrl = 'https://megaembed.com/embed/' . $tmdb_id;

    logDebug('URL do embed montada', [
        'embed_url' => $embedUrl
    ]);

    logDebug('Iniciando acesso ao embed');

    $data = curlGet(
        $embedUrl,
        'https://megaembed.com/'
    );

    logDebug('Retorno do curlGet recebido', [
        'success' => $data['success'],
        'code' => $data['code'],
        'error' => $data['error'],
        'content_size' => strlen($data['content'])
    ]);

 if (!$data['success'] || empty($data['content'])) {

    logDebug('Conteúdo retornado mesmo com erro HTTP', [
        'inicio_html' => substr($data['content'], 0, 2000)
    ]);

    echo json_encode([
        'success' => false,
        'message' => 'Erro ao acessar embed',
        'http_code' => $data['code'],
        'content_size' => strlen($data['content']),
        'html_inicio' => substr($data['content'], 0, 5000),
        'debug' => $debug
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}
    logDebug('HTML recebido com sucesso');

    $html = $data['content'];

    logDebug('Tamanho do HTML', [
        'bytes' => strlen($html),
        'kb' => round(strlen($html) / 1024, 2)
    ]);

    $sources = [];

    logDebug('Iniciando procura pela variável sources');

    $padrao = '/var\s+sources\s*=\s*(\[[\s\S]*?\]);/';

    logDebug('Regex utilizada', [
        'regex' => $padrao
    ]);

    $resultadoRegex = preg_match(
        $padrao,
        $html,
        $matches
    );

    logDebug('Resultado do preg_match()', [
        'resultado' => $resultadoRegex,
        'quantidade_matches' => count($matches)
    ]);

    if ($resultadoRegex === false) {

        logDebug('PAROU: erro interno no preg_match');

    } elseif ($resultadoRegex === 0) {

        logDebug('PAROU: variável sources NÃO encontrada');

        echo json_encode([
            'success' => false,
            'message' => 'Variável sources não encontrada',
            'sources' => [],
            'total' => 0,
            'debug' => $debug
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;

    } else {

        logDebug('Variável sources encontrada');

        $jsonSources = $matches[1] ?? '';

        logDebug('Conteúdo capturado pela regex', [
            'tamanho' => strlen($jsonSources),
            'inicio' => substr($jsonSources, 0, 500)
        ]);

        logDebug('Executando json_decode()');

        $decoded = json_decode(
            $jsonSources,
            true
        );

        $jsonError = json_last_error();
        $jsonErrorMessage = json_last_error_msg();

        logDebug('Resultado do json_decode()', [
            'erro_codigo' => $jsonError,
            'erro_mensagem' => $jsonErrorMessage,
            'eh_array' => is_array($decoded)
        ]);

        if (!is_array($decoded)) {

            logDebug('PAROU: sources não virou array');

            echo json_encode([
                'success' => false,
                'message' => 'Erro ao decodificar sources',
                'json_error' => $jsonErrorMessage,
                'debug' => $debug
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            exit;
        }

        logDebug('Sources convertidas para array', [
            'quantidade' => count($decoded)
        ]);

        foreach ($decoded as $index => $item) {

            logDebug('Processando source', [
                'index' => $index,
                'item' => $item
            ]);

            if (!is_array($item)) {

                logDebug('Source ignorada: não é array');

                continue;
            }

            if (empty($item['file'])) {

                logDebug('Source ignorada: não possui file');

                continue;
            }

            $file = $item['file'];

            $type = str_contains(
                strtolower($file),
                '.m3u8'
            )
                ? 'hls'
                : 'mp4';

            $label = $item['label']
                ?? 'Servidor ' . (count($sources) + 1);

            $sources[] = [
                'file' => $file,
                'label' => $label,
                'type' => $type
            ];

            logDebug('Source adicionada', [
                'file' => $file,
                'label' => $label,
                'type' => $type
            ]);
        }
    }

    logDebug('Extração finalizada', [
        'total_sources' => count($sources)
    ]);

    if (empty($sources)) {

        logDebug('PAROU: nenhuma source válida encontrada');

        echo json_encode([
            'success' => false,
            'message' => 'Nenhuma fonte válida encontrada',
            'sources' => [],
            'total' => 0,
            'debug' => $debug
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }

    logDebug('SUCESSO: fontes encontradas');

    echo json_encode([
        'success' => true,
        'sources' => $sources,
        'total' => count($sources),
        'debug' => $debug
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

/*
|--------------------------------------------------------------------------
| MODO 2 - URL DIRETA
|--------------------------------------------------------------------------
*/

if (!empty($url_direta)) {

    logDebug('MODO URL DIRETA detectado');

    logDebug('URL recebida', [
        'url' => $url_direta
    ]);

    logDebug('Iniciando download da URL');

    $result = curlGet(
        $url_direta,
        'https://megaembed.com/'
    );

    logDebug('Download finalizado', [
        'success' => $result['success'],
        'code' => $result['code'],
        'error' => $result['error'],
        'content_size' => strlen($result['content'])
    ]);

    if ($result['success'] && !empty($result['content'])) {

        logDebug('Conteúdo recebido');

        if (
            str_contains(
                strtolower($url_direta),
                '.m3u8'
            )
        ) {

            logDebug('Tipo detectado: HLS/M3U8');

            header(
                'Content-Type: application/vnd.apple.mpegurl'
            );

            logDebug('Enviando conteúdo M3U8');

            echo $result['content'];

        } else {

            logDebug('Tipo detectado: MP4');

            header('Content-Type: video/mp4');

            header(
                'Content-Length: ' .
                strlen($result['content'])
            );

            logDebug('Enviando conteúdo MP4');

            echo $result['content'];
        }

    } else {

        logDebug('PAROU: falha ao carregar vídeo');

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Falha ao carregar o vídeo',
            'debug' => $debug
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    exit;
}

/*
|--------------------------------------------------------------------------
| NENHUM PARÂMETRO
|--------------------------------------------------------------------------
*/

logDebug('PAROU: nenhum parâmetro recebido');

echo json_encode([
    'success' => false,
    'message' => 'Use ?tmdb_id=ID ou ?url=LINK_DO_VIDEO',
    'debug' => $debug
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

?>
