<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug === '') {
    json_response(['ok' => false, 'message' => 'Slug produk wajib diisi'], 422);
}

$conn = db();
$conn->query(
    "CREATE TABLE IF NOT EXISTS product_images (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        image_path VARCHAR(255) NOT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_product_images_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS marketplace_shopee VARCHAR(255) NULL AFTER additional_json");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS marketplace_tokopedia VARCHAR(255) NULL AFTER marketplace_shopee");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS category VARCHAR(140) NOT NULL DEFAULT '' AFTER name");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS product_badge VARCHAR(30) NOT NULL DEFAULT '' AFTER category");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS use_case_tags_json JSON NULL AFTER additional_json");
$stmt = $conn->prepare(
    "SELECT id, slug, name, category, product_badge, price, image, short_description, description, features_json, additional_json, use_case_tags_json, marketplace_shopee, marketplace_tokopedia
     FROM products
     WHERE slug = ?
     LIMIT 1"
);
$stmt->bind_param('s', $slug);
$stmt->execute();
$productResult = $stmt->get_result();
$product = $productResult->fetch_assoc();

if (!$product) {
    json_response(['ok' => false, 'message' => 'Produk tidak ditemukan'], 404);
}

$productId = (int) $product['id'];

$reviewStmt = $conn->prepare(
    "SELECT reviewer_name, review_text, rating, created_at
     FROM reviews
     WHERE product_id = ? AND is_approved = 1
     ORDER BY id DESC"
);
$reviewStmt->bind_param('i', $productId);
$reviewStmt->execute();
$reviewResult = $reviewStmt->get_result();

$reviews = [];
while ($row = $reviewResult->fetch_assoc()) {
    $reviews[] = [
        'name' => $row['reviewer_name'],
        'text' => $row['review_text'],
        'rating' => $row['rating'] !== null ? (int) $row['rating'] : null,
        'createdAt' => $row['created_at'],
    ];
}

$galleryStmt = $conn->prepare(
    "SELECT image_path
     FROM product_images
     WHERE product_id = ?
     ORDER BY sort_order ASC, id ASC"
);
$galleryStmt->bind_param('i', $productId);
$galleryStmt->execute();
$galleryResult = $galleryStmt->get_result();
$galleryImages = [];
while ($row = $galleryResult->fetch_assoc()) {
    $galleryImages[] = $row['image_path'];
}

json_response([
    'ok' => true,
    'product' => [
        'id' => $productId,
        'slug' => $product['slug'],
        'name' => $product['name'],
        'category' => (string) ($product['category'] ?? ''),
        'badgeType' => (string) ($product['product_badge'] ?? ''),
        'price' => (int) $product['price'],
        'image' => $product['image'],
        'shortDescription' => $product['short_description'],
        'description' => $product['description'],
        'features' => json_decode((string) $product['features_json'], true) ?: [],
        'additional' => json_decode((string) $product['additional_json'], true) ?: [],
        'useCaseTags' => json_decode((string) ($product['use_case_tags_json'] ?? '[]'), true) ?: [],
        'shopeeUrl' => (string) ($product['marketplace_shopee'] ?? ''),
        'tokopediaUrl' => (string) ($product['marketplace_tokopedia'] ?? ''),
        'galleryImages' => $galleryImages,
    ],
    'reviews' => $reviews,
]);
