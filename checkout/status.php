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

// URL da API de status do novo gateway
$apiUrl = 'https://www.pagamentos-seguros.app/api-pix/ZJEqGW6mK_5lgID-N_s351jxTOA_UkA4TyA9OijN0pFP836nziKZTH0hhyeognB7bVQfcBEm6Az_A0W8BeKfDQ';

// monta URL de consulta com o transactionId
$consultUrl = $apiUrl . '?transactionId=' . urlencode($transactionId);

$ch = curl_init($consultUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json'
    ],
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode([
        'success' => false,
        'status'  => 'error',
        'error' => 'Erro ao executar requisição CURL',
        'message' => 'Erro ao executar requisição CURL: ' . $curlError
    ]);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    echo json_encode([
        'success' => false,
        'status'   => 'error',
        'error' => 'Erro ao verificar status do pagamento',
        'message'  => 'Erro ao verificar status do pagamento.',
        'httpCode' => $httpCode,
        'raw'      => $response
    ]);
    exit;
}

$decoded = json_decode($response, true);
if ($decoded === null) {
    echo json_encode([
        'success' => false,
        'status'  => 'error',
        'error' => 'Resposta inválida da API',
        'message' => 'Resposta inválida da API',
        'raw'     => $response
    ]);
    exit;
}

// tenta mapear o campo de status que a API devolve
$statusRaw =
    $decoded['status']
    ?? ($decoded['data']['status'] ?? null)
    ?? ($decoded['payment']['status'] ?? null)
    ?? 'waiting_payment';

$status = strtolower($statusRaw);

// Mapear status do novo gateway para o formato esperado
$paid = in_array($status, ['paid', 'approved', 'completed', 'success', 'pago', 'aprovado'], true);

// Retornar no formato compatível com ambos os usos
echo json_encode([
    'success' => true,
    'paid' => $paid,
    'status' => $status,
    'transaction' => $decoded, // Para compatibilidade com /api/transaction/
    'data' => $decoded,
    'response' => $decoded // Para compatibilidade com o formato do duffy
]);
