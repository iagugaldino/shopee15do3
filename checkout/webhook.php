<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
error_log("WinnerPay Webhook - Received: " . substr($rawBody, 0, 1000));

$data = json_decode($rawBody, true);
if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$transactionId = $data['transaction_id'] ?? $data['external_id'] ?? null;
$status = strtolower($data['status'] ?? 'unknown');
$event = $data['event'] ?? 'unknown';

error_log("WinnerPay Webhook - Event: $event | TxnID: $transactionId | Status: $status");

if (!$transactionId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing transaction_id']);
    exit;
}

$paid = in_array($status, ['paid', 'approved', 'completed', 'success'], true);

$statusDir = __DIR__ . '/transactions';
if (!is_dir($statusDir)) {
    @mkdir($statusDir, 0755, true);
}

$statusData = [
    'status'                  => $status,
    'paid'                    => $paid,
    'transaction_id'          => $transactionId,
    'event'                   => $event,
    'amount'                  => $data['amount'] ?? 0,
    'fee'                     => $data['fee'] ?? 0,
    'total_amount'            => $data['total_amount'] ?? 0,
    'type'                    => $data['type'] ?? '',
    'payment_method'          => $data['payment_method'] ?? 'PIX',
    'payer'                   => $data['payer'] ?? null,
    'acquirer_transaction_id' => $data['acquirer_transaction_id'] ?? null,
    'previous_status'         => $data['previous_status'] ?? null,
    'updated_at'              => date('Y-m-d H:i:s'),
    'webhook_raw'             => $data,
];

$filePath = $statusDir . '/' . $transactionId . '.json';
@file_put_contents($filePath, json_encode($statusData, JSON_PRETTY_PRINT));

error_log("WinnerPay Webhook - Saved status '$status' (paid=$paid) for $transactionId");

http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Webhook processed']);
