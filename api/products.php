<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

$conn = db();
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS marketplace_shopee VARCHAR(255) NULL AFTER additional_json");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS marketplace_tokopedia VARCHAR(255) NULL AFTER marketplace_shopee");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS category VARCHAR(140) NOT NULL DEFAULT '' AFTER name");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS product_badge VARCHAR(30) NOT NULL DEFAULT '' AFTER category");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS use_case_tags_json JSON NULL AFTER additional_json");
$sql = "SELECT id, slug, name, category, product_badge, price, image, short_description, use_case_tags_json, marketplace_shopee, marketplace_tokopedia FROM products ORDER BY id DESC";
$result = $conn->query($sql);

$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = [
        'id' => (int) $row['id'],
        'slug' => $row['slug'],
        'name' => $row['name'],
        'category' => (string) ($row['category'] ?? ''),
        'badgeType' => (string) ($row['product_badge'] ?? ''),
        'price' => (int) $row['price'],
        'image' => $row['image'],
        'shortDescription' => $row['short_description'],
        'useCaseTags' => json_decode((string) ($row['use_case_tags_json'] ?? '[]'), true) ?: [],
        'shopeeUrl' => (string) ($row['marketplace_shopee'] ?? ''),
        'tokopediaUrl' => (string) ($row['marketplace_tokopedia'] ?? ''),
    ];
}

json_response(['ok' => true, 'products' => $products]);
