<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

header('Content-Type: application/json');

// ==================== CONFIGURAÇÕES ====================
define('TIMEOUT', 30);
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');

// ==================== FUNÇÃO CURL ====================
function curlGet($url, $referer = '') {
    $headers = [
        'User-Agent: ' . USER_AGENT,
        'Accept: */*',
        'Accept-Language: pt-BR,pt;q=0.9',
        'Connection: keep-alive',
    ];

    if (!empty($referer)) {
        $headers[] = 'Referer: ' . $referer;
    }

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
        'code' => $httpCode,
        'error' => $error
    ];
}

// ==================== RECEBE PARÂMETROS ====================
$tmdb_id = trim($_GET['tmdb_id'] ?? '');
$url_direta = trim($_GET['url'] ?? '');

// ==================== MODO 1: Buscar fontes por TMDB ====================
if (!empty($tmdb_id)) {
    $embedUrl = 'https://d1muf25xa06so8hp24v.megaembed.com/embed/' . $tmdb_id;
    $data = curlGet($embedUrl);

    if (!$data['success'] || empty($data['content'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao acessar embed'
        ]);
        exit;
    }

    $html = $data['content'];
    $sources = [];

    if (preg_match('/var\s+sources\s*=\s*(\[[\s\S]*?\]);/', $html, $matches)) {
        $decoded = json_decode($matches[1], true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (!empty($item['file'])) {
                    $sources[] = [
                        'file' => $item['file'],
                        'label' => $item['label'] ?? 'Servidor ' . (count($sources) + 1),
                        'type' => str_contains(strtolower($item['file']), '.m3u8') ? 'hls' : 'mp4'
                    ];
                }
            }
        }
    }

    echo json_encode([
        'success' => !empty($sources),
        'sources' => $sources,
        'total' => count($sources)
    ]);
    exit;
}

// ==================== MODO 2: PROXY DIRETO (Mais importante) ====================
if (!empty($url_direta)) {
    $result = curlGet($url_direta, 'https://d1muf25xa06so8hp24v.megaembed.com/');

    if ($result['success'] && !empty($result['content'])) {
        
        // Se for HLS (m3u8)
        if (str_contains(strtolower($url_direta), '.m3u8')) {
            header('Content-Type: application/vnd.apple.mpegurl');
            echo $result['content'];
        } 
        // Se for MP4
        else {
            header('Content-Type: video/mp4');
            header('Content-Length: ' . strlen($result['content']));
            echo $result['content'];
        }
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Falha ao carregar o vídeo'
        ]);
    }
    exit;
}

// ==================== ERRO ====================
echo json_encode([
    'success' => false,
    'message' => 'Use ?tmdb_id=ID ou ?url=LINK_DO_VIDEO'
]);
?>
