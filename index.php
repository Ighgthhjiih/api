
<?php
header('Content-Type: application/json; charset=utf-8');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ==================== CONFIGURAÇÕES ====================

// Configure estas variáveis no ambiente do servidor.
$dominio = 'https://SEU_DOMINIO';
$usuario = getenv('MEGAEMBED_USER');
$senha = getenv('MEGAEMBED_PASSWORD');

// ==================== RECEBE O ID ====================

$id = trim($_GET['id'] ?? '');

if ($id === '' || !ctype_digit($id)) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Informe um ID válido. Exemplo: ?id=123'
    ]);
    exit;
}

if (!$usuario || !$senha) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Credenciais da API não configuradas'
    ]);
    exit;
}

// ==================== CONSULTA A API XTREAM ====================

$params = [
    'username' => $usuario,
    'password' => $senha,
    'action' => 'get_vod_streams'
];

$url = rtrim($dominio, '/') . '/player_api.php?' .
       http_build_query($params);

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json'
    ],
    CURLOPT_USERAGENT => 'MegaEmbedClient/1.0'
]);

$resposta = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$erro = curl_error($ch);

curl_close($ch);

// ==================== VALIDA A RESPOSTA ====================

if ($resposta === false || $status < 200 || $status >= 300) {
    http_response_code(502);

    echo json_encode([
        'success' => false,
        'message' => 'Erro ao consultar a API MegaEmbed',
        'status' => $status
    ]);
    exit;
}

$filmes = json_decode($resposta, true);

if (!is_array($filmes) || json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);

    echo json_encode([
        'success' => false,
        'message' => 'A API retornou um JSON inválido'
    ]);
    exit;
}

// ==================== FILTRA SOMENTE O ID SOLICITADO ====================

$filmeEncontrado = null;

foreach ($filmes as $filme) {
    if (
        isset($filme['stream_id']) &&
        (string) $filme['stream_id'] === $id
    ) {
        $filmeEncontrado = $filme;
        break;
    }
}

if ($filmeEncontrado === null) {
    http_response_code(404);

    echo json_encode([
        'success' => false,
        'message' => 'Filme não encontrado',
        'id' => $id
    ]);
    exit;
}

// Retorna somente o filme encontrado.
echo json_encode([
    'success' => true,
    'film' => $filmeEncontrado
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
