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
$conn->query(
    "CREATE TABLE IF NOT EXISTS service_reviews (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        service_id INT UNSIGNED NOT NULL,
        reviewer_name VARCHAR(120) NOT NULL,
        reviewer_email VARCHAR(190) NULL,
        review_text TEXT NOT NULL,
        rating TINYINT UNSIGNED NULL,
        is_approved TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_service_reviews_service
        FOREIGN KEY (service_id) REFERENCES services(id)
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$serviceStmt = $conn->prepare("SELECT id FROM services WHERE slug = ? AND is_active = 1 LIMIT 1");
$serviceStmt->bind_param('s', $slug);
$serviceStmt->execute();
$service = $serviceStmt->get_result()->fetch_assoc();

if (!$service) {
    json_response(['ok' => false, 'message' => 'Layanan tidak ditemukan'], 404);
}

$serviceId = (int) $service['id'];
$duplicateStmt = $conn->prepare(
    "SELECT id
     FROM service_reviews
     WHERE service_id = ?
       AND LOWER(TRIM(COALESCE(reviewer_email, ''))) = ?
     LIMIT 1"
);
$duplicateStmt->bind_param('is', $serviceId, $normalizedEmail);
$duplicateStmt->execute();
$existing = $duplicateStmt->get_result()->fetch_assoc();

if ($existing) {
    json_response(['ok' => false, 'message' => 'Email ini sudah pernah mengirim review untuk layanan ini.'], 409);
}

$insert = $conn->prepare(
    "INSERT INTO service_reviews (service_id, reviewer_name, reviewer_email, review_text, rating, is_approved)
     VALUES (?, ?, ?, ?, ?, 0)"
);
$insert->bind_param('isssi', $serviceId, $name, $normalizedEmail, $text, $rating);
$insert->execute();

json_response([
    'ok' => true,
    'message' => 'Review layanan berhasil dikirim dan menunggu persetujuan admin.',
]);
