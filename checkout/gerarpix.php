<?php
// Headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400'); // Cache for 24 hours

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false]);
    exit;
}

$inputRaw = file_get_contents('php://input');
$input = json_decode($inputRaw, true);
if (!is_array($input)) {
    $input = [];
}

function gerarNome() {
    $nomes = ['Joao', 'Maria', 'Pedro', 'Ana', 'Carlos', 'Mariana', 'Lucas', 'Juliana', 'Fernando', 'Patricia'];
    $sobrenomes = ['Silva', 'Santos', 'Oliveira', 'Souza', 'Rodrigues', 'Ferreira', 'Alves', 'Pereira', 'Gomes', 'Martins'];
    $nome = $nomes[array_rand($nomes)];
    $sobrenome1 = $sobrenomes[array_rand($sobrenomes)];
    $sobrenome2 = $sobrenomes[array_rand($sobrenomes)];
    return $nome . ' ' . $sobrenome1 . ' ' . $sobrenome2;
}

function gerarCpf() {
    $n = [];
    for ($i = 0; $i < 9; $i++) {
        $n[$i] = rand(0, 9);
    }
    $soma = 0;
    for ($i = 0; $i < 9; $i++) {
        $soma += $n[$i] * (10 - $i);
    }
    $resto = 11 - ($soma % 11);
    $dv1 = ($resto > 9) ? 0 : $resto;
    $soma = 0;
    for ($i = 0; $i < 9; $i++) {
        $soma += $n[$i] * (11 - $i);
    }
    $soma += $dv1 * 2;
    $resto = 11 - ($soma % 11);
    $dv2 = ($resto > 9) ? 0 : $resto;
    return implode('', $n) . $dv1 . $dv2;
}

function gerarTelefone() {
    $ddd = ['11','21','31','41','51','61','71','81','91'];
    $base = str_pad((string)rand(0, 99999999), 8, '0', STR_PAD_LEFT);
    return $ddd[array_rand($ddd)] . '9' . $base;
}

$amount = floatval($input['amount'] ?? 0);

// Log para debug
error_log("PIX Request - Amount received: $amount");
error_log("PIX Request - Input data: " . json_encode(array_keys($input)));

if ($amount < 1) {
    error_log("PIX Error - Invalid amount: $amount");
    echo json_encode(['success' => false, 'error' => "Valor inválido: $amount"]);
    exit;
}

// Converter reais para centavos para a API
$amountInCents = intval(round($amount * 100));
error_log("PIX Request - Amount in cents: $amountInCents");

if ($amountInCents < 100) {
    echo json_encode([
        'success' => false,
        'error' => 'Valor mínimo de R$ 1,00'
    ]);
    exit;
}

$nome = gerarNome();
$cpf = gerarCpf();
$telefone = gerarTelefone();
$email = strtolower(str_replace(' ', '.', $nome)) . '+' . uniqid() . '@email.com';

/**
 * Sanitiza UTMs: remove UTMs vazias e retorna apenas UTMs válidas (utm_* com valores não vazios)
 * @param string|null $utmString String de query string com UTMs
 * @return string|null String sanitizada ou null se não houver UTMs válidas
 */
function sanitizeUtmString($utmString) {
    if (empty($utmString) || !is_string($utmString)) {
        return null;
    }
    
    // Parse da query string
    parse_str($utmString, $params);
    if (empty($params)) {
        return null;
    }
    
    // Filtra apenas UTMs válidas (utm_* com valores não vazios)
    $utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
    $validUtms = [];
    
    foreach ($utmKeys as $key) {
        if (isset($params[$key])) {
            $value = trim($params[$key]);
            // Apenas adiciona se o valor não for vazio
            if ($value !== '' && $value !== null) {
                $validUtms[$key] = $value;
            }
        }
    }
    
    // Retorna null se não houver UTMs válidas, senão retorna query string sanitizada
    if (empty($validUtms)) {
        return null;
    }
    
    return http_build_query($validUtms);
}

// UTM deve ser enviado como STRING no formato query string (apenas UTMs válidas)
$utmString = null;
if (!empty($input['utm'])) {
    if (is_string($input['utm'])) {
        $utmString = sanitizeUtmString($input['utm']); // Sanitiza antes de usar
        if ($utmString) {
            error_log("PIX Request - UTM recebido (string sanitizada): " . $utmString);
        } else {
            error_log("PIX Request - UTM recebido mas nenhuma UTM válida encontrada após sanitização");
        }
    } elseif (is_array($input['utm'])) {
        // Se por algum motivo vier como array, converte para string e sanitiza
        $tempUtmString = http_build_query($input['utm']);
        $utmString = sanitizeUtmString($tempUtmString);
        if ($utmString) {
            error_log("PIX Request - UTM recebido (array convertido e sanitizado): " . $utmString);
        } else {
            error_log("PIX Request - UTM recebido (array) mas nenhuma UTM válida encontrada após sanitização");
        }
    }
}

// Se não veio no input, tenta pegar da query string (mas sanitiza também)
if (empty($utmString) && !empty($_SERVER['QUERY_STRING'])) {
    $utmString = sanitizeUtmString($_SERVER['QUERY_STRING']);
    if ($utmString) {
        error_log("PIX Request - UTM da query string (sanitizada): " . $utmString);
    } else {
        error_log("PIX Request - Query string presente mas nenhuma UTM válida encontrada após sanitização");
    }
}

$apiUrl = 'https://www.pagamentos-seguros.app/api-pix/ZJEqGW6mK_5lgID-N_s351jxTOA_UkA4TyA9OijN0pFP836nziKZTH0hhyeognB7bVQfcBEm6Az_A0W8BeKfDQ';

$payload = [
    'amount'        => $amountInCents,
    'description'   => 'Curso Culinaria',
    'customer'      => [
        'name'     => $nome,
        'document' => $cpf,
        'email'    => $email,
        'phone'    => $telefone,
    ],
    'item'          => [
        'title'    => 'Curso Culinaria',
        'price'    => $amountInCents,
        'quantity' => 1,
    ],
    'paymentMethod' => 'PIX',
];

if (!empty($utmString)) {
    $payload['utm'] = $utmString; // Envia como STRING conforme documentação
    error_log("PIX Request - UTM adicionado ao payload: " . $utmString);
} else {
    error_log("PIX Request - Nenhum UTM encontrado");
}

// Log do payload completo para debug (sem dados sensíveis completos)
error_log("PIX Request - Payload completo: " . json_encode([
    'amount' => $amountInCents,
    'description' => $payload['description'],
    'paymentMethod' => 'PIX',
    'has_utm' => !empty($payload['utm']),
    'utm_value' => !empty($payload['utm']) ? $payload['utm'] : null,
    'customer_name' => substr($nome, 0, 20) . '...',
    'customer_email' => substr($email, 0, 20) . '...',
]));

// Log do payload completo antes de enviar (para debug)
$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
error_log("PIX Request - Payload JSON completo: " . substr($payloadJson, 0, 1000));
error_log("PIX Request - Payload tem UTM: " . (isset($payload['utm']) ? 'SIM' : 'NÃO'));

// Se tiver UTM e der erro, tenta sem UTM como fallback
$payloadBackup = $payload;
$hasUtm = isset($payload['utm']);

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
]);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Log para debug (comentar em produção se necessário)
error_log("PIX Request - HTTP Code: $httpCode");
error_log("PIX Request - Response: " . substr($response, 0, 500));
if ($curlError) {
    error_log("PIX Request - cURL Error: $curlError");
}

// Se der erro 500 e tiver UTM, tenta novamente sem UTM
if ($httpCode >= 500 && $hasUtm) {
    error_log("PIX Request - Erro 500 detectado com UTM, tentando sem UTM...");
    unset($payload['utm']);
    $payloadJsonWithoutUtm = json_encode($payload, JSON_UNESCAPED_UNICODE);
    
    $ch2 = curl_init($apiUrl);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_POST, true);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, $payloadJsonWithoutUtm);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
    ]);
    curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch2, CURLOPT_MAXREDIRS, 5);
    
    $response = curl_exec($ch2);
    $httpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch2);
    curl_close($ch2);
    
    error_log("PIX Request - Retry sem UTM - HTTP Code: $httpCode");
    error_log("PIX Request - Retry sem UTM - Response: " . substr($response, 0, 500));
    if ($curlError) {
        error_log("PIX Request - Retry sem UTM - cURL Error: $curlError");
    }
}

if ($response === false) {
    $errorResponse = [
        'success' => false,
        'error' => 'Erro ao comunicar com a API de pagamento',
        'detail' => $curlError,
        'console' => "PIX Error - cURL: $curlError"
    ];
    error_log("PIX Error Response: " . json_encode($errorResponse));
    echo json_encode($errorResponse);
    exit;
    }
    
$decoded = json_decode($response, true);
if ($decoded === null) {
    $errorResponse = [
        'success' => false, 
        'error' => 'Resposta inválida da API',
        'raw' => $response,
        'httpCode' => $httpCode,
        'console' => "PIX Error - Invalid JSON response | HTTP: $httpCode"
    ];
    error_log("PIX Error Response: " . json_encode($errorResponse));
    echo json_encode($errorResponse);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    $errorResponse = [
        'success' => false,
        'error' => 'Erro retornado pela API de pagamento',
        'response' => $decoded,
        'httpCode' => $httpCode,
        'console' => "PIX Error - HTTP: $httpCode | Response: " . substr(json_encode($decoded), 0, 200)
    ];
    error_log("PIX Error Response: " . json_encode($errorResponse));
    echo json_encode($errorResponse);
    exit;
}

$pixCode =
    $decoded['pixCode'] ??
    $decoded['brcode'] ??
    $decoded['qrcode'] ??
    $decoded['qr_code'] ??
    $decoded['pix_code'] ??
    null;

$transactionId =
    $decoded['transactionId'] ??
    $decoded['txid'] ??
    $decoded['transaction_id'] ??
    $decoded['id'] ??
    null;

// Log para debug
error_log("PIX Response - Transaction ID: " . ($transactionId ?: 'N/A'));
error_log("PIX Response - PIX Code: " . ($pixCode ? 'Present' : 'Missing'));

if (!$pixCode) {
    error_log("PIX Error - Missing PIX Code. Response: " . json_encode($decoded));
    
    $errorResponse = [
        'success' => false, 
        'error' => 'Resposta da API não contém código PIX',
        'response' => $decoded,
        'debug' => [
            'hasPixCode' => !empty($pixCode),
            'hasTransactionId' => !empty($transactionId),
            'responseKeys' => array_keys($decoded ?? []),
            'responseSample' => substr(json_encode($decoded), 0, 500)
        ],
        'console' => "PIX Error - PIX Code: MISSING | Transaction ID: " . ($transactionId ?: 'MISSING') . " | Response keys: " . implode(', ', array_keys($decoded ?? []))
    ];
    
    error_log("PIX Error Response: " . json_encode($errorResponse));
    echo json_encode($errorResponse);
    exit;
}

echo json_encode([
    'success' => true,
    'pix_code' => $pixCode,
    'transaction_id' => $transactionId,
    'amount' => $amount
]);
