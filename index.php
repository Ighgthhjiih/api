<?php
// Não coloque espaços, HTML ou texto antes desta linha.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}


define('TIMEOUT', 30);
define('USER_AGENT', 'Mozilla/5.0');

// =====================================================
// CONFIGURAÇÕES
// =====================================================

$dominio = 'https://mgeb.site';
$usuario = 'filmesssi';
$senha = 'd1660c5ceca93149';
$embedBase = 'https://megaembed.com/embed/';

// =====================================================
// FUNÇÃO CURL
// =====================================================

function curlGet($url, $referer = '') {
    $headers = [
        'User-Agent: ' . USER_AGENT,
        'Accept: */*',
        'Accept-Language: pt-BR,pt;q=0.9'
    ];

    if ($referer !== '') {
        $headers[] = 'Referer: ' . $referer;
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => USER_AGENT
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    return [
        'success' => $response !== false &&
                     $httpCode >= 200 &&
                     $httpCode < 300,
        'content' => $response,
        'code' => $httpCode,
        'error' => $error
    ];
}

// =====================================================
// PARÂMETROS
// =====================================================

$tmdb_id = trim($_GET['tmdb_id'] ?? '');
$url_direta = trim($_GET['url'] ?? '');

// =====================================================
// MODO 1: BUSCAR FILME NA API XTREAM
// =====================================================

if ($tmdb_id !== '') {

    if (!ctype_digit($tmdb_id) || (int)$tmdb_id < 1) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'tmdb_id inválido'
        ]);

        exit;
    }

    if (
        !$usuario ||
        !$senha ||
        !filter_var($dominio, FILTER_VALIDATE_URL) ||
        str_contains($dominio, 'SEU_DOMINIO')
    ) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Configure o domínio e as credenciais da API'
        ]);

        exit;
    }

    $apiUrl = rtrim($dominio, '/') . '/player_api.php?' .
        http_build_query([
            'username' => $usuario,
            'password' => $senha,
            'action' => 'get_vod_info',
            'vod_id' => $tmdb_id
        ]);

    $data = curlGet($apiUrl);

    if (!$data['success']) {
        http_response_code(502);

        echo json_encode([
            'success' => false,
            'message' => 'Erro ao consultar a API Xtream',
            'http_code' => $data['code']
        ]);

        exit;
    }

    $filme = json_decode($data['content'], true);

    if (!is_array($filme) || empty($filme)) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Filme não encontrado',
            'tmdb_id' => $tmdb_id
        ]);

        exit;
    }

    echo json_encode([
        'success' => true,
        'tmdb_id' => $tmdb_id,
        'film' => $filme
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

// =====================================================
// MODO 2: BUSCAR FONTES NO EMBED PELO ID
// =====================================================

if (isset($_GET['embed_id']) && $_GET['embed_id'] !== '') {

    $embed_id = trim($_GET['embed_id']);

    if (!ctype_digit($embed_id)) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'embed_id inválido'
        ]);

        exit;
    }

    $embedUrl = $embedBase . rawurlencode($embed_id);
    $data = curlGet($embedUrl);

    if (!$data['success'] || empty($data['content'])) {
        http_response_code(502);

        echo json_encode([
            'success' => false,
            'message' => 'Erro ao acessar o embed'
        ]);

        exit;
    }

    $sources = [];

    if (preg_match(
        '/var\s+sources\s*=\s*(\[[\s\S]*?\]);/',
        $data['content'],
        $matches
    )) {
        $decoded = json_decode($matches[1], true);

        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (!empty($item['file'])) {
                    $sources[] = [
                        'file' => $item['file'],
                        'label' => $item['label'] ??
                            'Servidor ' . (count($sources) + 1),
                        'type' => preg_match(
                            '/\.m3u8(?:$|[?#])/i',
                            $item['file']
                        ) ? 'hls' : 'video'
                    ];
                }
            }
        }
    }

    echo json_encode([
        'success' => !empty($sources),
        'sources' => $sources,
        'total' => count($sources)
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

// =====================================================
// MODO 3: PROXY DIRETO
// =====================================================

if ($url_direta !== '') {

    $urlInfo = parse_url($url_direta);
    $hostPermitido = parse_url($dominio, PHP_URL_HOST);

    if (
        !$urlInfo ||
        ($urlInfo['scheme'] ?? '') !== 'https' ||
        empty($urlInfo['host']) ||
        !$hostPermitido ||
        strcasecmp($urlInfo['host'], $hostPermitido) !== 0
    ) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'URL inválida ou domínio não permitido'
        ]);

        exit;
    }

    $result = curlGet($url_direta);

    if (!$result['success']) {
        http_response_code(502);

        echo json_encode([
            'success' => false,
            'message' => 'Falha ao carregar o recurso',
            'http_code' => $result['code']
        ]);

        exit;
    }

    $path = $urlInfo['path'] ?? '';

    if (preg_match('/\.m3u8$/i', $path)) {
        header('Content-Type: application/vnd.apple.mpegurl');
    } else {
        header('Content-Type: application/octet-stream');
    }

    echo $result['content'];
    exit;
}

// =====================================================
// PARÂMETROS AUSENTES
// =====================================================

http_response_code(400);

echo json_encode([
    'success' => false,
    'message' => 'Informe um parâmetro válido',
    'examples' => [
        '?tmdb_id=123',
        '?embed_id=123',
        '?url=https://SEU_DOMINIO/caminho-do-recurso'
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

?>
