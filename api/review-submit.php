<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$slug = trim((string) ($_POST['slug'] ?? ''));
$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$text = trim((string) ($_POST['text'] ?? ''));
$ratingRaw = $_POST['rating'] ?? null;
$rating = is_numeric($ratingRaw) ? (int) $ratingRaw : null;
$normalizedEmail = strtolower($email);

if ($slug === '' || $name === '' || $email === '' || $text === '') {
    json_response(['ok' => false, 'message' => 'Data review belum lengkap'], 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'message' => 'Format email tidak valid'], 422);
}

if ($rating !== null && ($rating < 1 || $rating > 5)) {
    json_response(['ok' => false, 'message' => 'Rating harus 1-5'], 422);
}

$conn = db();
$conn->query("ALTER TABLE reviews ADD COLUMN IF NOT EXISTS reviewer_email VARCHAR(190) NULL AFTER reviewer_name");
$productStmt = $conn->prepare("SELECT id FROM products WHERE slug = ? LIMIT 1");
$productStmt->bind_param('s', $slug);
$productStmt->execute();
$productResult = $productStmt->get_result();
$product = $productResult->fetch_assoc();

if (!$product) {
    json_response(['ok' => false, 'message' => 'Produk tidak ditemukan'], 404);
}

$productId = (int) $product['id'];
$duplicateStmt = $conn->prepare(
    "SELECT id
     FROM reviews
     WHERE product_id = ?
       AND LOWER(TRIM(COALESCE(reviewer_email, ''))) = ?
     LIMIT 1"
);
$duplicateStmt->bind_param('is', $productId, $normalizedEmail);
$duplicateStmt->execute();
$existing = $duplicateStmt->get_result()->fetch_assoc();

if ($existing) {
    json_response(['ok' => false, 'message' => 'Email ini sudah pernah mengirim review untuk produk ini.'], 409);
}

$insert = $conn->prepare(
    "INSERT INTO reviews (product_id, reviewer_name, reviewer_email, review_text, rating, is_approved)
     VALUES (?, ?, ?, ?, ?, 0)"
);
$insert->bind_param('isssi', $productId, $name, $normalizedEmail, $text, $rating);
$insert->execute();

json_response([
    'ok' => true,
    'message' => 'Review berhasil dikirim dan menunggu persetujuan admin.',
]);
