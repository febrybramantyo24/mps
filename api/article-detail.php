<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug === '') {
    json_response(['ok' => false, 'message' => 'Slug artikel wajib diisi'], 422);
}

$conn = db();
$conn->query(
    "CREATE TABLE IF NOT EXISTS articles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(180) NOT NULL UNIQUE,
        title VARCHAR(220) NOT NULL,
        image VARCHAR(255) NOT NULL,
        excerpt TEXT NOT NULL,
        content_html LONGTEXT NULL,
        author_name VARCHAR(140) NOT NULL DEFAULT 'Admin',
        category VARCHAR(140) NOT NULL DEFAULT 'Artikel',
        published_at DATETIME NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$conn->query("ALTER TABLE articles ADD COLUMN IF NOT EXISTS content_html LONGTEXT NULL AFTER excerpt");
$conn->query("ALTER TABLE articles ADD COLUMN IF NOT EXISTS author_name VARCHAR(140) NOT NULL DEFAULT 'Admin' AFTER content_html");
$conn->query("ALTER TABLE articles ADD COLUMN IF NOT EXISTS category VARCHAR(140) NOT NULL DEFAULT 'Artikel' AFTER author_name");
$conn->query("ALTER TABLE articles ADD COLUMN IF NOT EXISTS published_at DATETIME NULL AFTER category");

$stmt = $conn->prepare(
    "SELECT id, slug, title, image, excerpt, content_html, author_name, category, published_at
     FROM articles
     WHERE slug = ? AND is_active = 1
     LIMIT 1"
);
$stmt->bind_param('s', $slug);
$stmt->execute();
$article = $stmt->get_result()->fetch_assoc();

if (!$article) {
    json_response(['ok' => false, 'message' => 'Artikel tidak ditemukan'], 404);
}

$recentStmt = $conn->prepare(
    "SELECT slug, title, image, published_at
     FROM articles
     WHERE is_active = 1 AND slug <> ?
     ORDER BY sort_order ASC, published_at DESC, id DESC
     LIMIT 3"
);
$recentStmt->bind_param('s', $slug);
$recentStmt->execute();
$recentResult = $recentStmt->get_result();

$recent = [];
while ($row = $recentResult->fetch_assoc()) {
    $recent[] = [
        'slug' => (string) $row['slug'],
        'title' => (string) $row['title'],
        'image' => (string) $row['image'],
        'publishedAt' => (string) ($row['published_at'] ?? ''),
        'detailUrl' => '/artikel-detail/?slug=' . rawurlencode((string) $row['slug']),
    ];
}

$categoryResult = $conn->query(
    "SELECT category, COUNT(*) AS total
     FROM articles
     WHERE is_active = 1 AND category <> ''
     GROUP BY category
     ORDER BY total DESC, category ASC
     LIMIT 8"
);
$categories = [];
while ($categoryRow = $categoryResult->fetch_assoc()) {
    $name = (string) ($categoryRow['category'] ?? '');
    if ($name === '') {
        continue;
    }
    $categories[] = [
        'name' => $name,
        'total' => (int) ($categoryRow['total'] ?? 0),
        'url' => '/artikel/?category=' . rawurlencode($name),
    ];
}

$galleryStmt = $conn->prepare(
    "SELECT slug, title, image
     FROM articles
     WHERE is_active = 1 AND slug <> ? AND TRIM(image) <> ''
     ORDER BY sort_order ASC, published_at DESC, id DESC
     LIMIT 6"
);
$galleryStmt->bind_param('s', $slug);
$galleryStmt->execute();
$galleryResult = $galleryStmt->get_result();
$galleryPosts = [];
while ($row = $galleryResult->fetch_assoc()) {
    $imagePath = trim((string) ($row['image'] ?? ''));
    if ($imagePath === '') {
        continue;
    }
    $galleryPosts[] = [
        'slug' => (string) $row['slug'],
        'title' => (string) $row['title'],
        'image' => $imagePath,
        'detailUrl' => '/artikel-detail/?slug=' . rawurlencode((string) $row['slug']),
    ];
}

$popularTags = [];
foreach ($categories as $item) {
    $tag = trim((string) ($item['name'] ?? ''));
    if ($tag === '') {
        continue;
    }
    $popularTags[] = [
        'name' => $tag,
        'url' => '/artikel/?category=' . rawurlencode($tag),
    ];
    if (count($popularTags) >= 10) {
        break;
    }
}

json_response([
    'ok' => true,
    'article' => [
        'id' => (int) $article['id'],
        'slug' => (string) $article['slug'],
        'title' => (string) $article['title'],
        'image' => (string) $article['image'],
        'excerpt' => (string) $article['excerpt'],
        'contentHtml' => (string) ($article['content_html'] ?? ''),
        'author' => (string) ($article['author_name'] ?? 'Admin'),
        'category' => (string) ($article['category'] ?? 'Artikel'),
        'publishedAt' => (string) ($article['published_at'] ?? ''),
    ],
    'recentArticles' => $recent,
    'sidebar' => [
        'categories' => $categories,
        'galleryPosts' => $galleryPosts,
        'popularTags' => $popularTags,
    ],
]);

