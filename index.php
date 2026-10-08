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

// ===== MODO API: ?tmdb_id= responde JSON puro =====
$tmdb_id = trim($_GET['tmdb_id'] ?? '');

if (!empty($tmdb_id)) {
    header('Content-Type: application/json');

    $embedUrl = 'https://ighgthhjiih.github.io/teste2/' . $tmdb_id;
    $data = curlGet($embedUrl);

    if (!$data['success'] || empty($data['content'])) {
        echo json_encode(['success' => false, 'message' => 'Erro ao acessar embed']);
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

// ===== MESMA ROTA, SEM parâmetro: página com iframe oculto =====
$embed_id = trim($_GET['embed'] ?? 'tt22084616');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>API + Embed</title>
</head>
<body>

<pre id="json">Carregando...</pre>

<!-- iframe oculto, mas carregando normalmente -->
<iframe
    src="https://megaembed.com/embed/<?= htmlspecialchars($embed_id) ?>"
    width="0" height="0"
    style="border:0; visibility:hidden; position:absolute;"
    allowfullscreen
    loading="eager">
</iframe>

<script>
    // Consome a API na mesma rota, só que com ?tmdb_id=
    fetch(location.pathname + '?tmdb_id=<?= urlencode($embed_id) ?>')
        .then(r => r.json())
        .then(data => {
            document.getElementById('json').textContent =
                JSON.stringify(data, null, 2);
        })
        .catch(() => {
            document.getElementById('json').textContent = 'Erro na API.';
        });
</script>

</body>
</html>
