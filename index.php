<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// ==================== FUNÇÃO CURL ====================
function curlGet($url) {
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Referer: https://d1muf25xa06so8hp24v.megaembed.com/embed/',
        'Accept-Language: pt-BR,pt;q=0.9',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['html' => $html, 'code' => $httpCode];
}

// ==================== RECEBE TMDB ID ====================
$tmdb_id = trim($_GET['tmdb_id'] ?? '');

if (empty($tmdb_id)) {
    echo json_encode([
        'success' => false,
        'message' => 'TMDB ID não informado. Exemplo: ?tmdb_id=550'
    ]);
    exit;
}

// ==================== MONTA URL DO EMBED ====================
$embedUrl = 'https://d1muf25xa06so8hp24v.megaembed.com/embed/' . $tmdb_id;

// ==================== BUSCA A PÁGINA ====================
$data = curlGet($embedUrl);

if ($data['code'] >= 400 || empty($data['html'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao acessar a página do player',
        'http_code' => $data['code'],
        'url' => $embedUrl
    ]);
    exit;
}

$html = $data['html'];
$sources = [];

// ==================== EXTRAI SOURCES ====================
if (preg_match('/var\s+sources\s*=\s*(\[[\s\S]*?\]);/', $html, $matches)) {
    $decoded = json_decode($matches[1], true);
    
    if (is_array($decoded)) {
        foreach ($decoded as $item) {
            if (!empty($item['file'])) {
                $sources[] = [
                    'file'  => $item['file'],
                    'label' => $item['label'] ?? 'Qualidade ' . (count($sources) + 1),
                    'type'  => str_contains($item['file'], '.m3u8') ? 'hls' : 'mp4'
                ];
            }
        }
    }
}

// ==================== RETORNA RESPOSTA ====================
if (empty($sources)) {
    echo json_encode([
        'success' => false,
        'message' => 'Nenhum link de vídeo encontrado',
        'tmdb_id' => $tmdb_id,
        'url_testada' => $embedUrl
    ]);
} else {
    echo json_encode([
        'success' => true,
        'tmdb_id' => $tmdb_id,
        'embed_url' => $embedUrl,
        'sources' => $sources,
        'total' => count($sources)
    ]);
}
?>
