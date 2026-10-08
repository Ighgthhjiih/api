<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

define('FLARE_URL', 'http://127.0.0.1:8191/v1');   // endereço do FlareSolverr
define('TIMEOUT', 70);                              // desafio CF pode demorar
define('DEBUG', true);

// ==================== PASSO A: PEGAR COOKIE DO FLARESOLVERR ====================
function flareSolve($url) {
    $ch = curl_init(FLARE_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => TIMEOUT,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'cmd'        => 'request.get',
            'url'        => $url,
            'maxTimeout' => 60000,
        ]),
    ]);
    $raw    = curl_exec($ch);
    $err    = curl_error($ch);
    curl_close($ch);

    $res = json_decode((string)$raw, true);

    if ($err || !is_array($res) || ($res['status'] ?? '') !== 'ok') {
        return ['success' => false, 'error' => $err ?: ($res['message'] ?? 'resposta inválida do FlareSolverr')];
    }

    $sol = $res['solution'];
    return [
        'success'      => true,
        'content'      => $sol['response'] ?? '',
        'http_code'    => $sol['status'] ?? 0,
        'final_url'    => $sol['url'] ?? $url,
        'user_agent'   => $sol['userAgent'] ?? '',
        'cookie_header'=> implode('; ', array_map(
            fn($c) => $c['name'] . '=' . $c['value'],
            $sol['cookies'] ?? []
        )),
        'cookies_raw'  => $sol['cookies'] ?? [],
    ];
}

// ==================== PASSO B: BUSCAR O STREAM DIRETO NO ALVO (com cookie/UA do flare) ====================
// (método "limpo": menos pesado que passar de novo pelo FlareSolverr)
function curlGet($url, $cookie, $ua) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING       => 'gzip, deflate',
        CURLOPT_COOKIE         => $cookie,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_HTTPHEADER     => [
            'Accept: */*',
            'Accept-Language: pt-BR,pt;q=0.9',
            'Referer: ' . $url,
        ],
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    return ['content' => (string)$response, 'code' => $code, 'error' => $error];
}

// ==================== EXTRAÇÃO DAS SOURCES ====================
function extrairSources($html) {
    if (preg_match('/var\s+sources\s*=\s*(\[[\s\S]*?\]);/', $html, $m)) {
        $bruto = $m[1];
    } elseif (preg_match('/(?:const|let|var)\s+sources\s*=\s*(\[[\s\S]*?\])\s*;/', $html, $m)) {
        $bruto = $m[1];
    } elseif (preg_match('/(\[\s*\{[\s\S]*?"file"[\s\S]*?\])\s*;/', $html, $m)) {
        $bruto = $m[1];
    } else {
        return ['sources' => [], 'debug' => 'nenhuma regex casou'];
    }

    $decoded = json_decode($bruto, true);
    if (!is_array($decoded)) {
        return ['sources' => [], 'debug' => 'decode falhou: ' . json_last_error_msg()];
    }

    $sources = [];
    foreach ($decoded as $item) {
        if (!empty($item['file'])) {
            $sources[] = [
                'file'  => $item['file'],
                'label' => $item['label'] ?? null,
                'type'  => $item['type'] ?? null,
            ];
        }
    }
    return ['sources' => $sources, 'debug' => count($sources) . ' itens extraídos'];
}

// ==================== FLUXO PRINCIPAL ====================
$tmdb_id = trim($_GET['tmdb_id'] ?? '') ?: 'tt22084616';
$embedUrl = 'https://mgeb.site/embed/' . $tmdb_id;
$debug = [];

// Passo A: FlareSolverr resolve o Cloudflare
$debug['passo_A_flaresolver'] = ['url_alvo' => $embedUrl];
$flare = flareSolve($embedUrl);

if (!$flare['success']) {
    $debug['passo_A_flaresolver']['resultado'] = 'FALHOU: ' . $flare['error'];
    echo json_encode([
        'success'   => false,
        'tmdb_id'   => $tmdb_id,
        'sources'   => [],
        'auditoria' => DEBUG ? $debug : null,
        'timestamp' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$debug['passo_A_flaresolver'] = [
    'url_alvo'     => $embedUrl,
    'resultado'    => 'OK',
    'http_code'    => $flare['http_code'],
    'user_agent'   => $flare['user_agent'],
    'cookie_header'=> substr($flare['cookie_header'], 0, 80) . '...(' . strlen($flare['cookie_header']) . ' chars)',
];

$html = $flare['content'];

// Passo B: verifica se passou do Cloudflare
$bloqueado = stripos($html, 'Just a moment') !== false
          || stripos($html, 'challenges.cloudflare.com') !== false;

$debug['passo_B_cloudflare'] = $bloqueado
    ? 'AINDA BLOQUEADO (FlareSolverr não contornou o desafio)'
    : 'PASSOU';

// Passo C: extração
if ($bloqueado) {
    $sources = [];
    $debug['passo_C_extracao'] = 'não executado';
} else {
    $ext = extrairSources($html);
    $sources = $ext['sources'];
    $debug['passo_C_extracao'] = $ext['debug'];

    // Passo D (bônus): se o HTML não tem sources inline, tenta achar URL de API do player
    if (empty($sources)) {
        if (preg_match('/["\'](https?:\/\/[^"\']+\/api\/[^"\']+)["\']/', $html, $m)) {
            $debug['passo_D_api_interna'] = 'possível endpoint: ' . $m[1];
            $api = curlGet($m[1], $flare['cookie_header'], $flare['user_agent']);
            $debug['passo_D_api_interna']['http_code'] = $api['code'];
            $debug['passo_D_api_interna']['trecho'] = substr($api['content'], 0, 300);
            $ext2 = extrairSources($api['content']);
            $sources = $ext2['sources'];
        } else {
            $debug['passo_D_api_interna'] = 'nenhum endpoint /api/ encontrado no HTML';
        }
    }
}

// HTML bruto pra inspeção manual
$arquivo = sys_get_temp_dir() . '/debug_' . preg_replace('/[^a-z0-9]/i', '', $tmdb_id) . '.html';
file_put_contents($arquivo, $html);

// ==================== RESPOSTA ====================
echo json_encode([
    'success'   => !empty($sources),
    'tmdb_id'   => $tmdb_id,
    'embed_url' => $embedUrl,
    'sources'   => $sources,
    'html_salvo'=> $arquivo,
    'auditoria' => DEBUG ? $debug : null,
    'timestamp' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
