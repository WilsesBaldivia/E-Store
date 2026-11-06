<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

$productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$productId || $productId < 1) {
    http_response_code(404);
    exit;
}

ensure_product_media_table();
$query = db()->prepare('SELECT mime_type, image_data, updated_at FROM product_media WHERE product_id = :id');
$query->execute(['id' => $productId]);
$image = $query->fetch();

if (!$image) {
    http_response_code(404);
    exit;
}

$allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($image['mime_type'], $allowedTypes, true)) {
    http_response_code(415);
    exit;
}

header('Content-Type: ' . $image['mime_type']);
header('Content-Length: ' . strlen($image['image_data']));
header('Cache-Control: public, max-age=86400, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo $image['image_data'];
