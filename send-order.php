<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, string $message, bool $success = false): void
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

function clean_text($value, int $maxLength, bool $allowNewlines = false): string
{
    if (!is_string($value)) {
        return '';
    }

    $value = strip_tags($value);
    $pattern = $allowNewlines
        ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'
        : '/[\x00-\x1F\x7F]/';
    $value = preg_replace($pattern, '', $value) ?? '';
    return trim(substr($value, 0, $maxLength));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, 'Use the order form to submit your request.');
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || strlen($rawBody) > 20000) {
    respond(413, 'The order request is too large.');
}

$order = json_decode($rawBody, true);
if (!is_array($order)) {
    respond(400, 'The order details could not be read. Please try again.');
}

$name = clean_text($order['from_name'] ?? null, 120);
$email = clean_text($order['from_email'] ?? null, 254);
$phone = clean_text($order['phone'] ?? null, 60);
$message = clean_text($order['message'] ?? null, 2000, true);
$items = $order['items'] ?? null;

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(400, 'Enter your name and a valid email address.');
}

if (!is_array($items) || count($items) < 1 || count($items) > 50) {
    respond(400, 'Your basket is empty or contains too many items.');
}

$itemLines = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        respond(400, 'One or more basket items are invalid.');
    }

    $itemName = clean_text($item['name'] ?? null, 180);
    $sku = clean_text($item['sku'] ?? null, 60);
    $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 99],
    ]);

    if ($itemName === '' || $sku === '' || $quantity === false) {
        respond(400, 'One or more basket items are invalid.');
    }

    $itemLines[] = '- ' . $itemName . ' (SKU: ' . $sku . ') x ' . $quantity;
}

$body = implode("\r\n", [
    'New EuroBeauty order request',
    '',
    'Customer: ' . $name,
    'Email: ' . $email,
    'Phone: ' . ($phone !== '' ? $phone : 'Not provided'),
    '',
    'Basket:',
    implode("\r\n", $itemLines),
    '',
    'Message:',
    $message !== '' ? $message : 'No additional message',
]);

$headers = [
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
];

if (!mail('aminemouzannar@gmail.com', 'New EuroBeauty order request', $body, implode("\r\n", $headers))) {
    error_log('EuroBeauty order email could not be sent by PHP mail().');
    respond(503, 'The server could not send email. Please contact the website host to enable outgoing PHP mail.');
}

respond(200, 'Your order request was sent. We will contact you to confirm availability and shipping.', true);