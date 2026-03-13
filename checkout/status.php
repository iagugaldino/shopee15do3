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

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
if (!is_array($input)) {
    $input = [];
}

// pega o ID da transação vindo do JS, POST, GET ou por fallback do path
$transactionId =
    ($input['id'] ?? null) ??
    ($input['transactionId'] ?? null) ??
    ($_POST['transaction_id'] ?? null) ??
    ($_GET['transactionId'] ?? null) ??
    ($_GET['id'] ?? null);

// Se não tiver na query string, tenta pegar do path (ex: status.php/123)
if (!$transactionId) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pathParts = explode('/', trim($path, '/'));
    $lastPart = end($pathParts);
    if ($lastPart && $lastPart !== 'status.php') {
        $transactionId = $lastPart;
    }
}

if (!$transactionId) {
    echo json_encode([
        'success' => false,
        'error' => 'Transaction ID não informado',
        'status' => 'waiting_payment',
        'message' => 'ID da transação não encontrado.'
    ]);
    exit;
}

// ===== WinnerPay Gateway =====
$winnerpayBaseUrl = 'https://api.winnerpayy.com.br/api';
$winnerpayClientId = 'mateusnunesmkt@gmail.com_cc06d97fa68b00c1b64499bf4a9316c3';
$winnerpayClientSecret = 'a36b3f57b333bd02bc95f52ecd0ad6106bc613a3895746f80bef4903226be58b';

// 1) Verificar status salvo pelo webhook (mais rápido e confiável)
$statusFile = __DIR__ . '/transactions/' . $transactionId . '.json';
if (file_exists($statusFile)) {
    $savedData = json_decode(file_get_contents($statusFile), true);
    if ($savedData && is_array($savedData)) {
        $status = strtolower($savedData['status'] ?? 'pending');
        $paid = in_array($status, ['paid', 'approved', 'completed', 'success', 'pago', 'aprovado'], true);

        echo json_encode([
            'success' => true,
            'paid' => $paid,
            'status' => $status,
            'transaction' => $savedData,
            'data' => $savedData,
            'response' => $savedData,
            'source' => 'webhook_cache'
        ]);
        exit;
    }
}

// 2) Fallback: consultar API WinnerPay
$consultUrl = $winnerpayBaseUrl . '/financial/transactions?per_page=50';

$ch = curl_init($consultUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Client-Id: ' . $winnerpayClientId,
        'X-Client-Secret: ' . $winnerpayClientSecret,
    ],
    CURLOPT_TIMEOUT => 15,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode([
        'success' => false,
        'status'  => 'pending',
        'paid'    => false,
        'error'   => 'Erro ao consultar API: ' . $curlError
    ]);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    echo json_encode([
        'success' => false,
        'status'  => 'pending',
        'paid'    => false,
        'error'   => 'Erro HTTP ao verificar status',
        'httpCode' => $httpCode
    ]);
    exit;
}

$decoded = json_decode($response, true);
if ($decoded === null) {
    echo json_encode([
        'success' => false,
        'status'  => 'pending',
        'paid'    => false,
        'error'   => 'Resposta inválida da API'
    ]);
    exit;
}

// Procurar a transação pelo transaction_id na lista
$transactionData = null;
$transactions = $decoded['transactions']['data'] ?? $decoded['data'] ?? [];

if (is_array($transactions)) {
    foreach ($transactions as $txn) {
        if (
            (isset($txn['transaction_id']) && $txn['transaction_id'] === $transactionId) ||
            (isset($txn['id']) && (string)$txn['id'] === (string)$transactionId)
        ) {
            $transactionData = $txn;
            break;
        }
    }
}

if (!$transactionData) {
    echo json_encode([
        'success' => true,
        'paid'    => false,
        'status'  => 'pending',
        'transaction' => null,
        'data'    => null,
        'response' => $decoded
    ]);
    exit;
}

$status = strtolower($transactionData['status'] ?? 'pending');
$paid = in_array($status, ['paid', 'approved', 'completed', 'success', 'pago', 'aprovado'], true);

// Salva o status atualizado no cache local
$statusDir = __DIR__ . '/transactions';
if (!is_dir($statusDir)) {
    @mkdir($statusDir, 0755, true);
}
@file_put_contents($statusDir . '/' . $transactionId . '.json', json_encode([
    'status'         => $status,
    'paid'           => $paid,
    'transaction_id' => $transactionId,
    'amount'         => $transactionData['amount'] ?? 0,
    'updated_at'     => date('Y-m-d H:i:s'),
    'data'           => $transactionData,
]));

echo json_encode([
    'success'     => true,
    'paid'        => $paid,
    'status'      => $status,
    'transaction' => $transactionData,
    'data'        => $transactionData,
    'response'    => $transactionData,
    'source'      => 'api'
]);
