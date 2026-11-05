<?php
header('Content-Type: application/json; charset=utf-8');

include_once('../../config.php');

// [CORRIGIDO] Validação de ambiente com fallback
$config['melhor_envio_url']   = $_ENV['MELHOR_ENVIO_URL']   ?? getenv('MELHOR_ENVIO_URL')   ?: '';
$config['melhor_envio_token'] = $_ENV['MELHOR_ENVIO_TOKEN'] ?? getenv('MELHOR_ENVIO_TOKEN') ?: '';

if (empty($config['melhor_envio_url']) || empty($config['melhor_envio_token'])) {
    http_response_code(500);
    echo json_encode(['status' => 'erro', 'mensagem' => 'Configuração do Melhor Envio ausente (URL ou token).']);
    exit;
}

// Consulta CEP de origem do projeto
$stmt = $conn->query("SELECT title, cep FROM tb_checkout LIMIT 1");
$projeto = $stmt->fetch(PDO::FETCH_ASSOC);

$application_name = $projeto['title'] ?? 'Loja';
$config['cep_origem'] = $projeto['cep'] ?? '';

date_default_timezone_set('America/Sao_Paulo');

// [CORRIGIDO] Valida CEP de destino (8 dígitos)
$cepDestinoRaw = $_POST['cep'] ?? '';
$cepDestino    = preg_replace('/\D/', '', $cepDestinoRaw);

if (strlen($cepDestino) !== 8) {
    http_response_code(400);
    echo json_encode(['status' => 'erro', 'mensagem' => 'CEP de destino inválido.']);
    exit;
}

// [CORRIGIDO] Valida CEP de origem
$cepOrigem = preg_replace('/\D/', '', $config['cep_origem']);
if (strlen($cepOrigem) !== 8) {
    http_response_code(500);
    echo json_encode(['status' => 'erro', 'mensagem' => 'CEP de origem inválido no cadastro do projeto.']);
    exit;
}

$cart = $_POST['cart'] ?? [];
if (!is_array($cart) || empty($cart)) {
    http_response_code(400);
    echo json_encode(['status' => 'erro', 'mensagem' => 'Carrinho vazio ou formato inválido.']);
    exit;
}

// Dimensão padrão (id = 0)
$defaultDimensao = [
    'id' => 0,
    'nome' => 'Padrão',
    'altura' => 4,
    'largura' => 12,
    'comprimento' => 17,
    'peso' => 0.5
];

// Busca todas as dimensões cadastradas
$stmtDim = $conn->prepare("SELECT * FROM tb_frete_dimensoes");
$stmtDim->execute();
$dimensoesDb = $stmtDim->fetchAll(PDO::FETCH_ASSOC);

$dimensoes = [0 => $defaultDimensao];
foreach ($dimensoesDb as $dim) {
    $dimensoes[(int)$dim['id']] = [
        'id' => (int)$dim['id'],
        'nome' => $dim['nome'],
        'altura' => (float)$dim['altura'],
        'largura' => (float)$dim['largura'],
        'comprimento' => (float)$dim['comprimento'],
        'peso' => (float)$dim['peso']
    ];
}

$fixedTotal = 0.0;
$volumes = []; // cada unidade vira um "volume" para consolidar depois

foreach ($cart as $item) {
    $prodId   = intval($item['id'] ?? 0);
    $quantity = intval($item['quantity'] ?? 0);
    if ($prodId <= 0 || $quantity <= 0) {
        continue;
    }

    $stmtProd = $conn->prepare("SELECT freight_type, freight_value, freight_dimension_id FROM tb_produtos WHERE id = :id");
    $stmtProd->bindParam(':id', $prodId, PDO::PARAM_INT);
    $stmtProd->execute();
    $produto = $stmtProd->fetch(PDO::FETCH_ASSOC);

    if (!$produto) continue;

    $type       = $produto['freight_type'] ?? 'default';
    $value      = floatval($produto['freight_value'] ?? 0);
    $dimensaoId = intval($produto['freight_dimension_id'] ?? 0);
    $dimensao   = $dimensoes[$dimensaoId] ?? $dimensoes[0];

    // [CORRIGIDO] Frete fixo é somado e o produto NÃO entra no Melhor Envio
    if ($type === 'fixed' && $value > 0) {
        $fixedTotal += $value * $quantity;
        continue;
    }

    // [CORRIGIDO] Garante dimensões/peso válidos (evita 0)
    $alt  = max(2,   (float)$dimensao['altura']);
    $lar  = max(11,  (float)$dimensao['largura']);
    $comp = max(16,  (float)$dimensao['comprimento']);
    $peso = max(0.3, (float)$dimensao['peso']); // mínimo ME = 0,3 kg

    for ($i = 0; $i < $quantity; $i++) {
        $volumes[] = [
            'height' => $alt,
            'width'  => $lar,
            'length' => $comp,
            'weight' => $peso
        ];
    }
}

// [CORRIGIDO] Se só há frete fixo, retorna ele (e não um preço 0)
if (empty($volumes)) {
    if ($fixedTotal <= 0) {
        http_response_code(422);
        echo json_encode(['status' => 'erro', 'mensagem' => 'Nenhum item válido no carrinho para calcular frete.']);
        exit;
    }
    echo json_encode([
        'status'  => 'sucesso',
        'options' => [[
            'id'             => 'fixed_total',
            'name'           => 'Frete Fixo (total)',
            'price'          => number_format($fixedTotal, 2, '.', ''),
            'delivery_range' => ['min' => null, 'max' => null],
            'company'        => ['id' => null, 'name' => 'Valor Fixo', 'picture' => null],
            'error'          => null
        ]]
    ]);
    exit;
}

// [CORRIGIDO] Consolida volumes em um único pacote para o endpoint calculate
$alturaTotal      = 0;
$larguraMax       = 0;
$comprimentoMax   = 0;
$pesoTotal        = 0;

foreach ($volumes as $v) {
    // Empilha alturas, mantém maior largura e comprimento
    $alturaTotal    += $v['height'];
    $larguraMax      = max($larguraMax, $v['width']);
    $comprimentoMax  = max($comprimentoMax, $v['length']);
    $pesoTotal      += $v['weight'];
}

// Limites máximos usuais do ME (evita rejeição)
$alturaTotal     = min($alturaTotal, 200);
$larguraMax      = min($larguraMax, 100);
$comprimentoMax  = min($comprimentoMax, 100);

// [CORRIGIDO] Payload do endpoint CORRETO: /me/shipment/calculate
$payload = [
    'from' => ['postal_code' => $cepOrigem],
    'to'   => ['postal_code' => $cepDestino],
    'package' => [
        'height' => (int)ceil($alturaTotal),
        'width'  => (int)ceil($larguraMax),
        'length' => (int)ceil($comprimentoMax),
        'weight' => round($pesoTotal, 2)
    ],
    'options' => [
        'insurance_value' => 0,
        'receipt'         => false,
        'own_hand'        => false
    ]
];

// [CORRIGIDO] Endpoint correto
$url = rtrim($config['melhor_envio_url'], '/') . '/me/shipment/calculate';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Authorization: Bearer ' . $config['melhor_envio_token'],
        'Content-Type: application/json',
        'User-Agent: ' . $application_name
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$err      = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// [CORRIGIDO] Log temporário para diagnóstico (remova depois)
error_log('ME PAYLOAD: ' . json_encode($payload));
error_log('ME RESPONSE (HTTP ' . $httpCode . '): ' . $response);

if ($err) {
    http_response_code(500);
    echo json_encode(['status' => 'erro', 'mensagem' => 'Comunicação falhou: ' . $err]);
    exit;
}

$data = json_decode($response, true);

// [CORRIGIDO] Valida HTTP e estrutura da resposta
if ($httpCode >= 400 || !is_array($data)) {
    http_response_code(500);
    $msg = is_array($data) && isset($data['message'])
        ? $data['message']
        : 'Falha na API Melhor Envio (HTTP ' . $httpCode . ').';
    echo json_encode(['status' => 'erro', 'mensagem' => $msg]);
    exit;
}

// [CORRIGIDO] Monta opções; ignora serviços com erro e adiciona o frete fixo
$options = [];
foreach ($data as $svc) {
    if (!is_array($svc)) continue;
    if (!empty($svc['error'])) continue; // serviço indisponível

    $svcPrice   = floatval($svc['price'] ?? 0);
    $totalPrice = $svcPrice + $fixedTotal;

    $options[] = [
        'id'             => $svc['id'] ?? null,
        'name'           => $svc['name'] ?? '',
        'price'          => number_format($totalPrice, 2, '.', ''),
        'delivery_range' => [
            'min' => $svc['delivery_range']['min'] ?? null,
            'max' => $svc['delivery_range']['max'] ?? null
        ],
        'company' => [
            'id'      => $svc['company']['id'] ?? null,
            'name'    => $svc['company']['name'] ?? '',
            'picture' => $svc['company']['picture'] ?? ''
        ],
        'error'   => $svc['error'] ?? null
    ];
}

// [CORRIGIDO] Se nenhum serviço do ME retornou, mas há frete fixo, devolve só ele
if (empty($options) && $fixedTotal > 0) {
    $options[] = [
        'id'             => 'fixed_total',
        'name'           => 'Frete Fixo (total)',
        'price'          => number_format($fixedTotal, 2, '.', ''),
        'delivery_range' => ['min' => null, 'max' => null],
        'company'        => ['id' => null, 'name' => 'Valor Fixo', 'picture' => null],
        'error'          => null
    ];
}

if (empty($options)) {
    http_response_code(422);
    echo json_encode(['status' => 'erro', 'mensagem' => 'Nenhuma opção de frete disponível para este CEP.']);
    exit;
}

echo json_encode([
    'status'  => 'sucesso',
    'options' => $options
]);
