<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

$conn = db();
$conn->query(
    "CREATE TABLE IF NOT EXISTS team_members (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(140) NOT NULL,
        role VARCHAR(140) NOT NULL,
        image VARCHAR(255) NOT NULL,
        social_facebook VARCHAR(255) NULL,
        social_linkedin VARCHAR(255) NULL,
        social_youtube VARCHAR(255) NULL,
        social_whatsapp VARCHAR(255) NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$result = $conn->query(
    "SELECT id, name, role, image, social_facebook, social_linkedin, social_youtube, social_whatsapp
     FROM team_members
     WHERE is_active = 1
     ORDER BY sort_order ASC, id DESC"
);

$members = [];
while ($row = $result->fetch_assoc()) {
    $whatsappRaw = preg_replace('/\D+/', '', (string) ($row['social_whatsapp'] ?? '')) ?: '';
    $members[] = [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'role' => (string) $row['role'],
        'image' => (string) $row['image'],
        'facebook' => (string) ($row['social_facebook'] ?? ''),
        'linkedin' => (string) ($row['social_linkedin'] ?? ''),
        'youtube' => (string) ($row['social_youtube'] ?? ''),
        'whatsapp' => $whatsappRaw !== '' ? 'https://wa.me/' . $whatsappRaw : '',
    ];
}

json_response([
    'ok' => true,
    'members' => $members,
]);

