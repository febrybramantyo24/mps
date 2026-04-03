<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

function build_service_detail_url(string $slug): string
{
    return '/layanan/detail/?slug=' . rawurlencode($slug);
}

$conn = db();
$conn->query(
    "CREATE TABLE IF NOT EXISTS services (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(180) NOT NULL UNIQUE,
        name VARCHAR(180) NOT NULL,
        card_highlight VARCHAR(80) NOT NULL DEFAULT '',
        image VARCHAR(255) NOT NULL,
        client_name VARCHAR(180) NOT NULL DEFAULT '',
        location_name VARCHAR(180) NOT NULL DEFAULT '',
        project_year VARCHAR(80) NOT NULL DEFAULT '',
        duration_text VARCHAR(120) NOT NULL DEFAULT '',
        price_text VARCHAR(120) NOT NULL DEFAULT '',
        short_description TEXT NOT NULL,
        detail_url VARCHAR(255) NOT NULL,
        video_url VARCHAR(255) NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS client_name VARCHAR(180) NOT NULL DEFAULT '' AFTER image");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS card_highlight VARCHAR(80) NOT NULL DEFAULT '' AFTER name");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS location_name VARCHAR(180) NOT NULL DEFAULT '' AFTER client_name");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS project_year VARCHAR(80) NOT NULL DEFAULT '' AFTER location_name");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS duration_text VARCHAR(120) NOT NULL DEFAULT '' AFTER project_year");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS price_text VARCHAR(120) NOT NULL DEFAULT '' AFTER duration_text");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER short_description");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS features_json JSON NULL AFTER description");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS video_url VARCHAR(255) NULL AFTER detail_url");

$result = $conn->query(
    "SELECT id, slug, name, card_highlight, image, short_description, description, detail_url, video_url, sort_order, created_at
     FROM services
     WHERE is_active = 1
     ORDER BY sort_order ASC, id DESC"
);

$services = [];
while ($row = $result->fetch_assoc()) {
    $slug = (string) $row['slug'];
    $detailUrl = build_service_detail_url($slug);
    $services[] = [
        'id' => (int) $row['id'],
        'slug' => $slug,
        'name' => (string) $row['name'],
        'cardHighlight' => (string) ($row['card_highlight'] ?? ''),
        'image' => (string) $row['image'],
        'shortDescription' => (string) $row['short_description'],
        'description' => (string) $row['description'],
        'detailUrl' => $detailUrl,
        'videoUrl' => (string) ($row['video_url'] ?? ''),
        'sortOrder' => (int) ($row['sort_order'] ?? 0),
        'createdAt' => (string) ($row['created_at'] ?? ''),
    ];
}

json_response([
    'ok' => true,
    'services' => $services,
]);
