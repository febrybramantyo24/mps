<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

function build_project_detail_url(string $slug): string
{
    return '/proyek/detail/?slug=' . rawurlencode($slug);
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
$conn->query("ALTER TABLE projects ADD COLUMN IF NOT EXISTS client_name VARCHAR(180) NOT NULL DEFAULT '' AFTER image");
$conn->query("ALTER TABLE projects ADD COLUMN IF NOT EXISTS location_name VARCHAR(180) NOT NULL DEFAULT '' AFTER client_name");
$conn->query("ALTER TABLE projects ADD COLUMN IF NOT EXISTS project_year VARCHAR(80) NOT NULL DEFAULT '' AFTER location_name");
$conn->query("ALTER TABLE projects ADD COLUMN IF NOT EXISTS duration_text VARCHAR(120) NOT NULL DEFAULT '' AFTER project_year");
$conn->query("ALTER TABLE projects ADD COLUMN IF NOT EXISTS price_text VARCHAR(120) NOT NULL DEFAULT '' AFTER duration_text");
$conn->query("ALTER TABLE projects ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER short_description");
$conn->query("ALTER TABLE projects ADD COLUMN IF NOT EXISTS features_json JSON NULL AFTER description");
$conn->query("ALTER TABLE projects ADD COLUMN IF NOT EXISTS video_url VARCHAR(255) NULL AFTER detail_url");

$result = $conn->query(
    "SELECT id, slug, title, image, client_name, location_name, project_year, duration_text, price_text, category, short_description, detail_url, sort_order, created_at
     FROM projects
     WHERE is_active = 1
     ORDER BY sort_order ASC, id DESC"
);

$projects = [];
while ($row = $result->fetch_assoc()) {
    $slug = (string) $row['slug'];
    $detailUrl = build_project_detail_url($slug);
    $projects[] = [
        'id' => (int) $row['id'],
        'slug' => $slug,
        'title' => (string) $row['title'],
        'image' => (string) $row['image'],
        'client' => (string) ($row['client_name'] ?? ''),
        'location' => (string) ($row['location_name'] ?? ''),
        'projectYear' => (string) ($row['project_year'] ?? ''),
        'duration' => (string) ($row['duration_text'] ?? ''),
        'price' => (string) ($row['price_text'] ?? ''),
        'category' => (string) $row['category'],
        'shortDescription' => (string) $row['short_description'],
        'detailUrl' => $detailUrl,
        'sortOrder' => (int) ($row['sort_order'] ?? 0),
        'createdAt' => (string) ($row['created_at'] ?? ''),
    ];
}

json_response([
    'ok' => true,
    'projects' => $projects,
]);
