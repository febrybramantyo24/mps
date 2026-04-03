<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

function build_article_detail_url(string $slug): string
{
    return '/artikel-detail/?slug=' . rawurlencode($slug);
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

$q = trim((string) ($_GET['q'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$sort = strtolower(trim((string) ($_GET['sort'] ?? 'newest')));
$allowedSort = ['newest', 'oldest'];
if (!in_array($sort, $allowedSort, true)) {
    $sort = 'newest';
}
$page = (int) ($_GET['page'] ?? 1);
$limit = (int) ($_GET['limit'] ?? 6);
if ($page < 1) {
    $page = 1;
}
if ($limit < 1) {
    $limit = 6;
}
if ($limit > 50) {
    $limit = 50;
}
$offset = ($page - 1) * $limit;

$whereSql = "WHERE is_active = 1";
$searchLike = '%' . $q . '%';
$hasQ = $q !== '';
$hasCategory = $category !== '';

if ($hasQ) {
    $whereSql .= " AND (title LIKE ? OR excerpt LIKE ? OR content_html LIKE ?)";
}
if ($hasCategory) {
    $whereSql .= " AND category = ?";
}

if ($hasQ && $hasCategory) {
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM articles {$whereSql}");
    $countStmt->bind_param('ssss', $searchLike, $searchLike, $searchLike, $category);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
} elseif ($hasQ) {
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM articles {$whereSql}");
    $countStmt->bind_param('sss', $searchLike, $searchLike, $searchLike);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
} elseif ($hasCategory) {
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM articles {$whereSql}");
    $countStmt->bind_param('s', $category);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
} else {
    $countResult = $conn->query("SELECT COUNT(*) AS total FROM articles {$whereSql}");
}
$total = (int) (($countResult ? $countResult->fetch_assoc()['total'] : 0) ?? 0);
$totalPages = max(1, (int) ceil($total / max(1, $limit)));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$orderSql = $sort === 'oldest'
    ? "ORDER BY COALESCE(published_at, '1970-01-01 00:00:00') ASC, id ASC"
    : "ORDER BY COALESCE(published_at, '1970-01-01 00:00:00') DESC, id DESC";

$sql =
    "SELECT id, slug, title, image, excerpt, author_name, category, published_at
     FROM articles
     {$whereSql}
     {$orderSql}
     LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($hasQ && $hasCategory) {
    $stmt->bind_param('ssssii', $searchLike, $searchLike, $searchLike, $category, $limit, $offset);
} elseif ($hasQ) {
    $stmt->bind_param('sssii', $searchLike, $searchLike, $searchLike, $limit, $offset);
} elseif ($hasCategory) {
    $stmt->bind_param('sii', $category, $limit, $offset);
} else {
    $stmt->bind_param('ii', $limit, $offset);
}
$stmt->execute();
$result = $stmt->get_result();

$articles = [];
while ($row = $result->fetch_assoc()) {
    $slug = (string) $row['slug'];
    $articles[] = [
        'id' => (int) $row['id'],
        'slug' => $slug,
        'title' => (string) $row['title'],
        'image' => (string) $row['image'],
        'excerpt' => (string) $row['excerpt'],
        'author' => (string) ($row['author_name'] ?? 'Admin'),
        'category' => (string) ($row['category'] ?? 'Artikel'),
        'publishedAt' => (string) ($row['published_at'] ?? ''),
        'detailUrl' => build_article_detail_url($slug),
    ];
}

json_response([
    'ok' => true,
    'articles' => $articles,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'totalPages' => $totalPages,
    ],
    'filters' => [
        'q' => $q,
        'category' => $category,
        'sort' => $sort,
    ],
]);

