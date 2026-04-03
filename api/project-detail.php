<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug === '') {
    json_response(['ok' => false, 'message' => 'Slug proyek wajib diisi'], 422);
}

$conn = db();
$conn->query(
    "CREATE TABLE IF NOT EXISTS projects (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(180) NOT NULL UNIQUE,
        title VARCHAR(180) NOT NULL,
        image VARCHAR(255) NOT NULL,
        client_name VARCHAR(180) NOT NULL DEFAULT '',
        location_name VARCHAR(180) NOT NULL DEFAULT '',
        project_year VARCHAR(80) NOT NULL DEFAULT '',
        duration_text VARCHAR(120) NOT NULL DEFAULT '',
        price_text VARCHAR(120) NOT NULL DEFAULT '',
        category VARCHAR(140) NOT NULL DEFAULT '',
        short_description TEXT NOT NULL,
        detail_url VARCHAR(255) NOT NULL,
        description TEXT NULL,
        features_json JSON NULL,
        video_url VARCHAR(255) NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$conn->query(
    "CREATE TABLE IF NOT EXISTS project_images (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id INT UNSIGNED NOT NULL,
        image_path VARCHAR(255) NOT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_project_images_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$conn->query("ALTER TABLE projects ADD COLUMN IF NOT EXISTS client_name VARCHAR(180) NOT NULL DEFAULT '' AFTER image");
$conn->query("ALTER TABLE projects ADD COLUMN IF NOT EXISTS location_name VARCHAR(180) NOT NULL DEFAULT '' AFTER client_name");
$conn->query("ALTER TABLE projects ADD COLUMN IF NOT EXISTS project_year VARCHAR(80) NOT NULL DEFAULT '' AFTER location_name");
$conn->query("ALTER TABLE projects ADD COLUMN IF NOT EXISTS duration_text VARCHAR(120) NOT NULL DEFAULT '' AFTER project_year");
$conn->query("ALTER TABLE projects ADD COLUMN IF NOT EXISTS price_text VARCHAR(120) NOT NULL DEFAULT '' AFTER duration_text");
$conn->query("ALTER TABLE projects ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER short_description");
$conn->query("ALTER TABLE projects ADD COLUMN IF NOT EXISTS features_json JSON NULL AFTER description");
$conn->query("ALTER TABLE projects ADD COLUMN IF NOT EXISTS video_url VARCHAR(255) NULL AFTER detail_url");

$stmt = $conn->prepare(
    "SELECT id, slug, title, image, client_name, location_name, project_year, duration_text, price_text, category, short_description, description, features_json, video_url
     FROM projects
     WHERE slug = ? AND is_active = 1
     LIMIT 1"
);
$stmt->bind_param('s', $slug);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();

if (!$project) {
    json_response(['ok' => false, 'message' => 'Proyek tidak ditemukan'], 404);
}

$projectId = (int) $project['id'];
$galleryStmt = $conn->prepare(
    "SELECT image_path
     FROM project_images
     WHERE project_id = ?
     ORDER BY sort_order ASC, id ASC"
);
$galleryStmt->bind_param('i', $projectId);
$galleryStmt->execute();
$galleryResult = $galleryStmt->get_result();
$galleryImages = [];
while ($row = $galleryResult->fetch_assoc()) {
    $galleryImages[] = (string) $row['image_path'];
}

json_response([
    'ok' => true,
    'project' => [
        'id' => $projectId,
        'slug' => (string) $project['slug'],
        'title' => (string) $project['title'],
        'image' => (string) $project['image'],
        'client' => (string) ($project['client_name'] ?? ''),
        'location' => (string) ($project['location_name'] ?? ''),
        'projectYear' => (string) ($project['project_year'] ?? ''),
        'duration' => (string) ($project['duration_text'] ?? ''),
        'price' => (string) ($project['price_text'] ?? ''),
        'category' => (string) $project['category'],
        'shortDescription' => (string) $project['short_description'],
        'description' => (string) ($project['description'] ?? ''),
        'features' => json_decode((string) ($project['features_json'] ?? '[]'), true) ?: [],
        'videoUrl' => (string) ($project['video_url'] ?? ''),
        'galleryImages' => $galleryImages,
    ],
]);
