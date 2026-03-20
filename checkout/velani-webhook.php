<?php
/**
 * Webhook Velani Pagamentos
 * Recebe notificações de mudança de status das transações PIX
 * Documentação: https://api.velanipagamentos.com.br/
 * 
 * Eventos suportados:
 * - transaction.paid: Transação PIX confirmada
 * - transaction.failed: Transação falhou ou expirou
 * - transaction.refunded: Transação estornada
 */

// Headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Velani-Signature');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Apenas aceita POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ==========================
// CONFIGURAÇÕES
// ==========================
// Preencha AQUI sua Webhook Secret da Velani (diferente da API Key)
define('VELANI_WEBHOOK_SECRET', 'SUA_WEBHOOK_SECRET_AQUI'); // Substitua pela sua webhook secret

// ==========================
// FUNÇÕES AUXILIARES
// ==========================

/**
 * Valida a assinatura do webhook usando HMAC-SHA256
 * @param string $payload Body raw da requisição
 * @param string $signature Header X-Velani-Signature (ou campo signature no JSON)
 * @param string $secret Webhook secret
 * @return bool
 */
function validarAssinaturaWebhook($payload, $signature, $secret) {
    if (empty($signature) || empty($secret)) {
        return false;
    }
    
    // Calcula HMAC-SHA256 do payload
    $calculated = hash_hmac('sha256', $payload, $secret);
    
    // Comparação segura contra timing attacks
    return hash_equals($calculated, $signature);
}

/**
 * Salva log do webhook para debug/auditoria
 * @param array $data Dados do webhook
 * @param bool $valid Se a assinatura é válida
 */
function salvarLogWebhook($data, $valid) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/velani_webhook_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $entry = [
        'timestamp' => $timestamp,
        'valid' => $valid,
        'data' => $data
    ];
    
    file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Processa evento de transação paga
 * @param array $data Dados do webhook
 */
function processarTransacaoPaga($data) {
    $transactionId = $data['id'] ?? null;
    $externalId = $data['externalId'] ?? null;
    $amount = $data['amount'] ?? 0;
    $customerName = $data['customerName'] ?? '';
    $paidAt = $data['paidAt'] ?? null;
    
    error_log("[Velani Webhook] Transação paga: $transactionId | External: $externalId | Valor: $amount");
    
    // TODO: Implemente sua lógica de negócio aqui
    // Exemplos:
    // - Atualizar status no banco de dados
    // - Enviar email de confirmação
    // - Liberar acesso ao produto
    // - Notificar sistema de afiliados
    
    return true;
}

/**
 * Processa evento de transação falhou/expirou
 * @param array $data Dados do webhook
 */
function processarTransacaoFalhou($data) {
    $transactionId = $data['id'] ?? null;
    $externalId = $data['externalId'] ?? null;
    
    error_log("[Velani Webhook] Transação falhou/expirou: $transactionId | External: $externalId");
    
    // TODO: Implemente sua lógica de negócio aqui
    // Exemplo: reabrir carrinho, enviar email de recuperação, etc.
    
    return true;
}

/**
 * Processa evento de transação estornada
 * @param array $data Dados do webhook
 */
function processarTransacaoEstornada($data) {
    $transactionId = $data['id'] ?? null;
    $externalId = $data['externalId'] ?? null;
    
    error_log("[Velani Webhook] Transação estornada: $transactionId | External: $externalId");
    
    // TODO: Implemente sua lógica de negócio aqui
    // Exemplo: revogar acesso, enviar notificação, etc.
    
    return true;
}

// ==========================
// PROCESSAMENTO PRINCIPAL
// ==========================

// Lê o body raw
$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empty body']);
    exit;
}

// Parse do JSON
$payload = json_decode($rawBody, true);
if ($payload === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Extrai assinatura (pode vir no header ou no body)
$signature = null;

// Tenta pegar do header primeiro
$headers = getallheaders();
$signatureHeader = $headers['X-Velani-Signature'] ?? $headers['x-velani-signature'] ?? null;
if ($signatureHeader) {
    $signature = $signatureHeader;
}

// Se não encontrou no header, tenta do body
if (empty($signature) && isset($payload['signature'])) {
    $signature = $payload['signature'];
}

// Valida assinatura se webhook secret estiver configurado
$signatureValida = true;
if (VELANI_WEBHOOK_SECRET !== 'SUA_WEBHOOK_SECRET_AQUI' && !empty(VELANI_WEBHOOK_SECRET)) {
    $signatureValida = validarAssinaturaWebhook($rawBody, $signature, VELANI_WEBHOOK_SECRET);
    
    if (!$signatureValida) {
        error_log("[Velani Webhook] Assinatura inválida. Recebido: $signature");
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid signature']);
        exit;
    }
}

// Salva log para debug (remova em produção se não necessário)
salvarLogWebhook($payload, $signatureValida);

// Extrai dados do webhook
$event = $payload['event'] ?? null;
$timestamp = $payload['timestamp'] ?? null;
$data = $payload['data'] ?? [];
$version = $payload['version'] ?? '1.0';
$source = $payload['source'] ?? null;

// Valida campos obrigatórios
if (empty($event) || empty($data['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// Valida source
if ($source !== 'velani-gateway') {
    error_log("[Velani Webhook] Source inválido: $source");
    // Não bloqueia, apenas loga
}

// Processa evento
$success = false;
switch ($event) {
    case 'transaction.paid':
        $success = processarTransacaoPaga($data);
        break;
        
    case 'transaction.failed':
        $success = processarTransacaoFalhou($data);
        break;
        
    case 'transaction.refunded':
        $success = processarTransacaoEstornada($data);
        break;
        
    default:
        error_log("[Velani Webhook] Evento desconhecido: $event");
        // Retorna 200 mesmo para eventos desconhecidos (evita retentativas)
        $success = true;
        break;
}

// Resposta de sucesso
http_response_code(200);
echo json_encode([
    'success' => true,
    'event' => $event,
    'transactionId' => $data['id'] ?? null,
    'processed' => $success,
    'timestamp' => date('c')
]);
