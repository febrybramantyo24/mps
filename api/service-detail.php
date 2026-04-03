<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug === '') {
    json_response(['ok' => false, 'message' => 'Slug layanan wajib diisi'], 422);
}

$conn = db();
$conn->query(
    "CREATE TABLE IF NOT EXISTS services (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(180) NOT NULL UNIQUE,
        name VARCHAR(180) NOT NULL,
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
$conn->query(
    "CREATE TABLE IF NOT EXISTS service_images (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        service_id INT UNSIGNED NOT NULL,
        image_path VARCHAR(255) NOT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_service_images_service
        FOREIGN KEY (service_id) REFERENCES services(id)
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
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
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS client_name VARCHAR(180) NOT NULL DEFAULT '' AFTER image");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS location_name VARCHAR(180) NOT NULL DEFAULT '' AFTER client_name");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS project_year VARCHAR(80) NOT NULL DEFAULT '' AFTER location_name");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS duration_text VARCHAR(120) NOT NULL DEFAULT '' AFTER project_year");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS price_text VARCHAR(120) NOT NULL DEFAULT '' AFTER duration_text");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER short_description");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS features_json JSON NULL AFTER description");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS video_url VARCHAR(255) NULL AFTER detail_url");

$stmt = $conn->prepare(
    "SELECT id, slug, name, image, client_name, location_name, project_year, duration_text, price_text, short_description, description, features_json, video_url
     FROM services
     WHERE slug = ? AND is_active = 1
     LIMIT 1"
);
$stmt->bind_param('s', $slug);
$stmt->execute();
$service = $stmt->get_result()->fetch_assoc();

if (!$service) {
    json_response(['ok' => false, 'message' => 'Layanan tidak ditemukan'], 404);
}

$serviceId = (int) $service['id'];
$galleryStmt = $conn->prepare(
    "SELECT image_path
     FROM service_images
     WHERE service_id = ?
     ORDER BY sort_order ASC, id ASC"
);
$galleryStmt->bind_param('i', $serviceId);
$galleryStmt->execute();
$galleryResult = $galleryStmt->get_result();
$galleryImages = [];
while ($row = $galleryResult->fetch_assoc()) {
    $galleryImages[] = (string) $row['image_path'];
}

$reviewStmt = $conn->prepare(
    "SELECT reviewer_name, review_text, rating, created_at
     FROM service_reviews
     WHERE service_id = ? AND is_approved = 1
     ORDER BY id DESC"
);
$reviewStmt->bind_param('i', $serviceId);
$reviewStmt->execute();
$reviewResult = $reviewStmt->get_result();
$reviews = [];
while ($row = $reviewResult->fetch_assoc()) {
    $reviews[] = [
        'name' => (string) ($row['reviewer_name'] ?? ''),
        'text' => (string) ($row['review_text'] ?? ''),
        'rating' => $row['rating'] !== null ? (int) $row['rating'] : null,
        'createdAt' => (string) ($row['created_at'] ?? ''),
    ];
}

json_response([
    'ok' => true,
    'service' => [
        'id' => $serviceId,
        'slug' => (string) $service['slug'],
        'name' => (string) $service['name'],
        'image' => (string) $service['image'],
        'client' => (string) ($service['client_name'] ?? ''),
        'location' => (string) ($service['location_name'] ?? ''),
        'projectYear' => (string) ($service['project_year'] ?? ''),
        'duration' => (string) ($service['duration_text'] ?? ''),
        'price' => (string) ($service['price_text'] ?? ''),
        'shortDescription' => (string) $service['short_description'],
        'description' => (string) $service['description'],
        'features' => json_decode((string) $service['features_json'], true) ?: [],
        'videoUrl' => (string) ($service['video_url'] ?? ''),
        'galleryImages' => $galleryImages,
        'reviews' => $reviews,
    ],
]);
