<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

$conn = db();
$conn->query(
    "CREATE TABLE IF NOT EXISTS testimonials (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        company VARCHAR(180) NOT NULL,
        quote_text TEXT NOT NULL,
        avatar_image VARCHAR(255) NOT NULL,
        brand_logo VARCHAR(255) NOT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$result = $conn->query(
    "SELECT id, name, company, quote_text, avatar_image, brand_logo
     FROM testimonials
     WHERE is_active = 1
     ORDER BY sort_order ASC, id DESC
     LIMIT 20"
);

$testimonials = [];
while ($row = $result->fetch_assoc()) {
    $testimonials[] = [
        'id' => (int) $row['id'],
        'name' => (string) ($row['name'] ?? ''),
        'company' => (string) ($row['company'] ?? ''),
        'review' => (string) ($row['quote_text'] ?? ''),
        'avatar' => (string) ($row['avatar_image'] ?? ''),
        'brandLogo' => (string) ($row['brand_logo'] ?? ''),
    ];
}

json_response([
    'ok' => true,
    'testimonials' => $testimonials,
]);
