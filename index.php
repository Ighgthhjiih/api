<?php

$API = 'https://php-api-studio.lovable.app/api/public/proxy';

$tmdb = isset($_GET['tmdb_id']) ? trim((string) $_GET['tmdb_id']) : '';
$url  = isset($_GET['url'])     ? trim((string) $_GET['url'])     : '';

$alvo = $API;
if ($tmdb !== '') {
    $alvo .= '?' . http_build_query(['tmdb_id' => $tmdb]);
} elseif ($url !== '') {
    $alvo .= '?' . http_build_query(['url' => $url]);
}

function chamar_api(string $alvo): array
{
    $ch = curl_init($alvo);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,   // queremos os cabeçalhos de resposta
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json, application/vnd.apple.mpegurl, video/mp4, */*',
            'Accept-Language: pt-BR,pt;q=0.9',
        ],
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    ]);

    $resposta = curl_exec($ch);

    if ($resposta === false) {
        $erro = curl_error($ch);
        curl_close($ch);
        return [0, [], '', 0.0, $erro];
    }

    $info     = curl_getinfo($ch);
    $tamanho  = $info['header_size'];
    $cabecalho = substr((string) $resposta, 0, (int) $tamanho);
    $corpo     = (string) substr((string) $resposta, (int) $tamanho);
    curl_close($ch);

    // Converte a string de cabeçalhos em array (último bloco = resposta final)
    $blocos = array_filter(explode("\r\n\r\n", str_replace("\n", "\r\n", $cabecalho)));
    $final  = (string) end($blocos);
    $linhas = array_slice(explode("\r\n", $final), 1);

    $cabecalhos = [];
    foreach ($linhas as $l) {
        if ($l === '' || !str_contains($l, ':')) continue;
        [$k, $v] = explode(':', $l, 2);
        $cabecalhos[trim($k)] = trim($v);
    }

    return [(int) $info['http_code'], $cabecalhos, $corpo, (float) $info['total_time'], ''];
}

[$status, $cabecalhos, $corpo, $tempo, $erro] = chamar_api($alvo);

// Tenta interpretar o corpo como JSON
$dados = json_decode($corpo, true);
$eh_json = is_array($dados);

if (php_sapi_name() === 'cli') {
    echo "Chamada: $alvo\n";
    if ($erro !== '') echo "ERRO DE CONEXAO: $erro\n";
    echo "Status:  $status  ({$tempo}s)\n";
    echo "Cabecalhos:\n";
    foreach ($cabecalhos as $k => $v) echo "  $k: $v\n";
    echo "Corpo:\n";
    echo $eh_json ? json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" : "$corpo\n";
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Teste da API de proxy</title>
<style>
  body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; margin: 0; background: #0f172a; color: #e2e8f0; }
  .wrap { max-width: 900px; margin: 0 auto; padding: 24px 16px 60px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .sub { color: #94a3b8; font-size: 13px; margin-bottom: 18px; }
  form { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
  input { flex: 1 1 240px; padding: 10px 12px; border-radius: 8px; border: 1px solid #334155; background: #1e293b; color: #e2e8f0; font-size: 14px; }
  button { padding: 10px 18px; border: 0; border-radius: 8px; background: #3b82f6; color: #fff; font-size: 14px; cursor: pointer; }
  .pill { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 13px; font-weight: 600; }
  .ok { background: #14532d; color: #bbf7d0; }
  .ruim { background: #7f1d1d; color: #fecaca; }
  .caixa { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 14px; margin-bottom: 14px; overflow-x: auto; }
  .caixa h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; margin: 0 0 10px; }
  pre { margin: 0; white-space: pre-wrap; word-break: break-word; font-size: 13px; line-height: 1.5; }
  a { color: #60a5fa; }
  .fonte { border-top: 1px solid #334155; padding: 10px 0; }
  .fonte:first-child { border-top: 0; }
  .fonte b { display: block; margin-bottom: 4px; }
  .fonte code { font-size: 12px; color: #cbd5e1; word-break: break-all; }
  .dica { font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Teste da API de proxy</h1>
  <div class="sub">Mostra tudo o que a API respondeu: status, cabeçalhos e corpo.</div>

  <form method="get">
    <input type="text" name="tmdb_id" placeholder="tmdb_id (ex.: 550)" value="<?= htmlspecialchars($tmdb) ?>">
    <input type="text" name="url" placeholder="ou url do vídeo (ex.: https://...m3u8)" value="<?= htmlspecialchars($url) ?>">
    <button type="submit">Chamar API</button>
  </form>

  <div class="caixa">
    <h2>Requisição</h2>
    <pre><a href="<?= htmlspecialchars($alvo) ?>"><?= htmlspecialchars($alvo) ?></a></pre>
  </div>

  <?php if ($erro !== ''): ?>
    <div class="caixa">
      <h2>Erro de conexão</h2>
      <pre class="ruim" style="padding:6px 10px; border-radius:6px; display:inline-block"><?= htmlspecialchars($erro) ?></pre>
    </div>
  <?php endif; ?>

  <div class="caixa">
    <h2>Resposta</h2>
    <p style="margin:0 0 12px">
      <span class="pill <?= $status >= 200 && $status < 400 ? 'ok' : 'ruim' ?>">HTTP <?= $status ?></span>
      <span class="dica">&nbsp; <?= number_format($tempo, 2) ?> s &nbsp;·&nbsp; <?= strlen($corpo) ?> bytes &nbsp;·&nbsp;
        <?= $eh_json ? 'JSON' : 'texto puro' ?></span>
    </p>

    <h2>Cabeçalhos recebidos</h2>
    <pre><?php foreach ($cabecalhos as $k => $v): echo htmlspecialchars("$k: $v\n"); endforeach; ?></pre>
  </div>

  <?php if ($eh_json && !empty($dados['sources'])): ?>
    <div class="caixa">
      <h2>Fontes encontradas (<?= (int) $dados['total'] ?>)</h2>
      <?php foreach ($dados['sources'] as $i => $f): ?>
        <div class="fonte">
          <b><?= htmlspecialchars($f['label'] ?? ('Servidor ' . ($i + 1))) ?> — <?= htmlspecialchars($f['type'] ?? '?') ?></b>
          <code><?= htmlspecialchars($f['file']) ?></code><br>
          <a href="<?= htmlspecialchars($alvo . (str_contains($alvo, '?') ? '&' : '?') . 'url=' . rawurlencode($f['file'])) ?>">abrir pela API</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="caixa">
    <h2>Corpo retornado</h2>
    <pre><?= $eh_json
        ? htmlspecialchars(json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
        : htmlspecialchars($corpo) ?></pre>
  </div>

  <div class="dica">
    Dica: para usar em outro PHP, copie só a função <code>chamar_api()</code> e chame
    <code>chamar_api('https://php-api-studio.lovable.app/api/public/proxy?tmdb_id=550')</code>.
  </div>
</div>
</body>
</html>
