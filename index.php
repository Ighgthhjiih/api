<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

define('TIMEOUT', 30);
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');

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

// ===== FUNÇÃO QUE BUSCA AS SOURCES (Modo 1) =====
function buscarSources($tmdb_id) {
    $embedUrl = 'https://megaembed.com/embed/' . $tmdb_id;
    $data = curlGet($embedUrl);

    if (!$data['success'] || empty($data['content'])) {
        return ['success' => false, 'message' => 'Erro ao acessar embed'];
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

    return [
        'success' => !empty($sources),
        'sources' => $sources,
        'total' => count($sources)
    ];
}

$tmdb_id = trim($_GET['tmdb_id'] ?? '');
$json    = isset($_GET['json']); // flag interna que só o JS da página usa

// ===== JSON: só quando vem ?tmdb_id=id&json=1 =====
if (!empty($tmdb_id) && $json) {
    header('Content-Type: application/json');
    echo json_encode(buscarSources($tmdb_id));
    exit;
}

// ===== HTML: qualquer outra coisa, inclusive ?tmdb_id=id sozinho =====
$embed_id = $tmdb_id !== '' ? $tmdb_id : 'tt22084616';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Player</title>
</head>
<body>

<!-- 1º: o iframe carrega junto com a página -->
<iframe
    src="https://megaembed.com/embed/<?= htmlspecialchars($embed_id) ?>"
    width="100%" height="800"
    frameborder="0"
    allowfullscreen>
</iframe>

<!-- 2º: o PHP roda DEPOIS, em segundo plano, sem recarregar -->
<pre id="resultado">Buscando fontes...</pre>

<script>
    const tmdbId = <?= json_encode($embed_id) ?>;

    fetch(location.pathname + '?tmdb_id=' + encodeURIComponent(tmdbId) + '&json=1')
        .then(r => r.json())
        .then(data => {
            document.getElementById('resultado').textContent =
                JSON.stringify(data, null, 2);
        })
        .catch(() => {
            document.getElementById('resultado').textContent = 'Erro na API.';
        });
</script>

</body>
</html>
