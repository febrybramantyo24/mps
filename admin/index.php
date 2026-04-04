<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';
session_start();

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function normalize_bulk_int_ids(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $seen = [];
    foreach ($raw as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $seen[$id] = true;
        }
    }
    return array_values(array_map('intval', array_keys($seen)));
}

function bind_stmt_params(mysqli_stmt $stmt, string $types, array $params): void
{
    $args = [$types];
    foreach ($params as $i => $value) {
        $params[$i] = $value;
        $args[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $args);
}

function select_rows_by_ids(mysqli $conn, string $sqlPrefix, array $ids): array
{
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare($sqlPrefix . " (" . $placeholders . ")");
    bind_stmt_params($stmt, str_repeat('i', count($ids)), $ids);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function delete_table_rows_by_ids(mysqli $conn, string $table, array $ids): void
{
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("DELETE FROM {$table} WHERE id IN (" . $placeholders . ")");
    bind_stmt_params($stmt, str_repeat('i', count($ids)), $ids);
    $stmt->execute();
}

function delete_uploaded_files(array $paths, string $prefix): int
{
    $deleted = 0;
    foreach ($paths as $path) {
        $path = (string) $path;
        if ($path === '') {
            continue;
        }
        if (!str_starts_with($path, $prefix)) {
            continue;
        }
        $abs = __DIR__ . '/..' . $path;
        if (is_file($abs)) {
            if (@unlink($abs)) {
                $deleted++;
            }
        }
    }
    return $deleted;
}

function delete_uploaded_files_if_unreferenced(
    mysqli $conn,
    array $paths,
    string $prefix,
    string $table,
    string $column,
    array $excludedIds = [],
    string $idColumn = 'id'
): int {
    $normalized = [];
    foreach ($paths as $path) {
        $path = (string) $path;
        if ($path !== '') {
            $normalized[$path] = true;
        }
    }
    $paths = array_keys($normalized);
    $excludedIds = normalize_bulk_int_ids($excludedIds);

    $deleted = 0;
    foreach ($paths as $path) {
        if (!str_starts_with($path, $prefix)) {
            continue;
        }
        $abs = __DIR__ . '/..' . $path;
        if (!is_file($abs)) {
            continue;
        }

        $sql = "SELECT COUNT(*) AS cnt FROM {$table} WHERE {$column} = ?";
        $params = [$path];
        $types = 's';
        if ($excludedIds) {
            $placeholders = implode(',', array_fill(0, count($excludedIds), '?'));
            $sql .= " AND {$idColumn} NOT IN (" . $placeholders . ")";
            $types .= str_repeat('i', count($excludedIds));
            $params = array_merge($params, $excludedIds);
        }

        $stmt = $conn->prepare($sql);
        bind_stmt_params($stmt, $types, $params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $count = (int) ($row['cnt'] ?? 0);
        if ($count !== 0) {
            continue;
        }

        if (@unlink($abs)) {
            $deleted++;
        }
    }

    return $deleted;
}

function text_to_json_lines(string $input): string
{
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $input) ?: [])));
    return json_encode($lines, JSON_UNESCAPED_UNICODE) ?: '[]';
}

function sanitize_article_html(string $html): string
{
    $value = trim($html);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('#<\s*(script|style|iframe|object|embed)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $value) ?? '';
    $value = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $value) ?? '';
    $value = preg_replace_callback('/\sstyle\s*=\s*("|\')(.*?)\1/i', static function (array $matches): string {
        $raw = (string) ($matches[2] ?? '');
        $declarations = preg_split('/;/', $raw) ?: [];
        $clean = [];
        foreach ($declarations as $decl) {
            $decl = trim((string) $decl);
            if ($decl === '') {
                continue;
            }
            if (!preg_match('/^([a-zA-Z-]+)\s*:\s*(.+)$/', $decl, $parts)) {
                continue;
            }
            $prop = strtolower(trim((string) $parts[1]));
            $val = trim((string) $parts[2]);

            if ($prop === 'text-align' && preg_match('/^(left|right|center|justify)$/i', $val)) {
                $clean[] = 'text-align:' . strtolower($val);
                continue;
            }
            if (in_array($prop, ['width', 'max-width'], true) && preg_match('/^(\d{1,3}%|\d{1,4}px|auto)$/i', $val)) {
                $clean[] = $prop . ':' . strtolower($val);
                continue;
            }
            if ($prop === 'height' && preg_match('/^(\d{1,4}px|auto)$/i', $val)) {
                $clean[] = 'height:' . strtolower($val);
                continue;
            }
            if ($prop === 'display' && preg_match('/^(block|inline|inline-block)$/i', $val)) {
                $clean[] = 'display:' . strtolower($val);
                continue;
            }
            if (in_array($prop, ['margin-left', 'margin-right'], true) && preg_match('/^(auto|\d{1,3}px)$/i', $val)) {
                $clean[] = $prop . ':' . strtolower($val);
                continue;
            }
        }
        if (!$clean) {
            return '';
        }
        return ' style="' . implode(';', $clean) . '"';
    }, $value) ?? '';
    $value = preg_replace('/\s(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '', $value) ?? '';

    $allowedTags = '<p><br><strong><b><em><i><u><h2><h3><h4><ul><ol><li><a><img><blockquote><span><div>';
    $value = strip_tags($value, $allowedTags);

    return trim($value);
}

function sanitize_rich_description_html(string $html): string
{
    $value = trim($html);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('#<\s*(script|style|iframe|object|embed)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $value) ?? '';
    $value = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $value) ?? '';

    $allowedFontSizes = [12, 14, 16, 18, 20, 24, 28, 32, 36];
    $value = preg_replace_callback('/\sstyle\s*=\s*("|\')(.*?)\1/i', static function (array $matches) use ($allowedFontSizes): string {
        $raw = (string) ($matches[2] ?? '');
        $declarations = preg_split('/;/', $raw) ?: [];
        $clean = [];
        foreach ($declarations as $decl) {
            $decl = trim((string) $decl);
            if ($decl === '') {
                continue;
            }
            if (!preg_match('/^([a-zA-Z-]+)\s*:\s*(.+)$/', $decl, $parts)) {
                continue;
            }
            $prop = strtolower(trim((string) $parts[1]));
            $val = trim((string) $parts[2]);

            if ($prop === 'text-align' && preg_match('/^(left|right|center|justify)$/i', $val)) {
                $clean[] = 'text-align:' . strtolower($val);
                continue;
            }
            if ($prop === 'font-size' && preg_match('/^(\d{2})px$/i', $val, $m) === 1) {
                $size = (int) ($m[1] ?? 0);
                if (in_array($size, $allowedFontSizes, true)) {
                    $clean[] = 'font-size:' . $size . 'px';
                }
                continue;
            }
            if ($prop === 'font-weight' && preg_match('/^(400|500|600|700|800|900|bold)$/i', $val)) {
                $clean[] = 'font-weight:' . strtolower($val);
                continue;
            }
            if ($prop === 'display' && preg_match('/^(block|inline|inline-block)$/i', $val)) {
                $clean[] = 'display:' . strtolower($val);
                continue;
            }
            if (in_array($prop, ['margin-left', 'margin-right'], true) && preg_match('/^(auto|\d{1,3}px)$/i', $val)) {
                $clean[] = $prop . ':' . strtolower($val);
                continue;
            }
        }
        if (!$clean) {
            return '';
        }
        return ' style="' . implode(';', $clean) . '"';
    }, $value) ?? '';

    $value = preg_replace('/\s(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '', $value) ?? '';

    // Allow a small subset only (no images inside these descriptions).
    $allowedTags = '<p><br><strong><b><em><i><u><h2><ul><ol><li><a><blockquote><span><div>';
    $value = strip_tags($value, $allowedTags);

    return trim($value);
}

function get_site_setting(mysqli $conn, string $settingKey, string $default = ''): string
{
    $stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1");
    $stmt->bind_param('s', $settingKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (string) ($row['setting_value'] ?? $default);
}

function save_site_setting(mysqli $conn, string $settingKey, string $settingValue): void
{
    $stmt = $conn->prepare(
        "INSERT INTO site_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->bind_param('ss', $settingKey, $settingValue);
    $stmt->execute();
}

function normalize_whatsapp_number(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?: '';
    if ($digits === '') {
        return '';
    }
    if (str_starts_with($digits, '62')) {
        return $digits;
    }
    if (str_starts_with($digits, '08')) {
        return '62' . substr($digits, 1);
    }
    if (str_starts_with($digits, '8')) {
        return '62' . $digits;
    }
    return $digits;
}

function normalize_map_embed_input(string $raw): string
{
    $value = trim($raw);
    if ($value === '') {
        return '';
    }

    if (preg_match('/src\s*=\s*["\']([^"\']+)["\']/i', $value, $matches) === 1 && !empty($matches[1])) {
        $value = trim((string) $matches[1]);
    }

    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!preg_match('#^https?://#i', $value) && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(\/.*)?$/i', $value)) {
        $value = 'https://' . $value;
    }

    return trim($value);
}

function normalize_rupiah_text(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?: '';
    if ($digits === '') {
        return '';
    }
    $number = (int) $digits;
    if ($number <= 0) {
        return '';
    }
    return 'Rp ' . number_format($number, 0, ',', '.');
}

function redirect_admin(string $page, array $params = []): void
{
    $params = array_merge(['page' => $page], $params);
    header('Location: /admin/?' . http_build_query($params));
    exit;
}

function positive_int(mixed $value, int $default = 1): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT);
    if ($number === false || $number < 1) {
        return $default;
    }
    return (int) $number;
}

function admin_query_url(array $overrides = [], array $removeKeys = []): string
{
    $params = $_GET;
    foreach ($removeKeys as $key) {
        unset($params[$key]);
    }
    foreach ($overrides as $key => $value) {
        $params[$key] = (string) $value;
    }
    if (!isset($params['page'])) {
        $params['page'] = 'dashboard';
    }
    return '/admin/?' . http_build_query($params);
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array{
 *   items: array<int, array<string, mixed>>,
 *   page: int,
 *   total_pages: int,
 *   total_items: int,
 *   per_page: int,
 *   query_key: string
 * }
 */
function paginate_rows(array $rows, int $perPage, string $queryKey): array
{
    $totalItems = count($rows);
    $totalPages = max(1, (int) ceil($totalItems / max(1, $perPage)));
    $page = positive_int($_GET[$queryKey] ?? 1, 1);
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $items = array_slice($rows, $offset, $perPage);
    return [
        'items' => $items,
        'page' => $page,
        'total_pages' => $totalPages,
        'total_items' => $totalItems,
        'per_page' => $perPage,
        'query_key' => $queryKey,
    ];
}

function render_pagination(array $pager): void
{
    $totalPages = (int) ($pager['total_pages'] ?? 1);
    if ($totalPages <= 1) {
        return;
    }
    $currentPage = (int) ($pager['page'] ?? 1);
    $queryKey = (string) ($pager['query_key'] ?? 'pg');
    $prevPage = max(1, $currentPage - 1);
    $nextPage = min($totalPages, $currentPage + 1);
    $pageItems = [];
    if ($totalPages <= 7) {
        for ($p = 1; $p <= $totalPages; $p += 1) {
            $pageItems[] = $p;
        }
    } else {
        $pageItems[] = 1;
        $start = max(2, $currentPage - 1);
        $end = min($totalPages - 1, $currentPage + 1);
        if ($currentPage <= 3) {
            $start = 2;
            $end = 4;
        } elseif ($currentPage >= $totalPages - 2) {
            $start = $totalPages - 3;
            $end = $totalPages - 1;
        }
        if ($start > 2) {
            $pageItems[] = 'dots-left';
        }
        for ($mid = $start; $mid <= $end; $mid += 1) {
            $pageItems[] = $mid;
        }
        if ($end < $totalPages - 1) {
            $pageItems[] = 'dots-right';
        }
        $pageItems[] = $totalPages;
    }
    echo '<div class="pager" aria-label="Pagination">';
    echo '<a class="pg-btn ' . ($currentPage <= 1 ? 'disabled' : '') . '" href="' . e(admin_query_url([$queryKey => $prevPage])) . '"' . ($currentPage <= 1 ? ' aria-disabled="true" tabindex="-1"' : '') . '>Prev</a>';
    foreach ($pageItems as $item) {
        if (is_int($item)) {
            echo '<a class="pg-btn ' . ($item === $currentPage ? 'active' : '') . '" href="' . e(admin_query_url([$queryKey => $item])) . '">' . str_pad((string) $item, 2, '0', STR_PAD_LEFT) . '</a>';
            continue;
        }
        echo '<span class="pg-dots">...</span>';
    }
    echo '<a class="pg-btn ' . ($currentPage >= $totalPages ? 'disabled' : '') . '" href="' . e(admin_query_url([$queryKey => $nextPage])) . '"' . ($currentPage >= $totalPages ? ' aria-disabled="true" tabindex="-1"' : '') . '>Next</a>';
    echo '</div>';
}

function pager_summary_text(array $pager): string
{
    $totalItems = (int) ($pager['total_items'] ?? 0);
    if ($totalItems <= 0) {
        return 'Belum ada data.';
    }
    $perPage = max(1, (int) ($pager['per_page'] ?? 1));
    $page = max(1, (int) ($pager['page'] ?? 1));
    $start = (($page - 1) * $perPage) + 1;
    $end = min($start + $perPage - 1, $totalItems);
    return "Menampilkan {$start}-{$end} dari {$totalItems} data";
}

function build_service_detail_url(string $slug): string
{
    return '/layanan/detail/?slug=' . rawurlencode($slug);
}

function build_project_detail_url(string $slug): string
{
    return '/proyek/detail/?slug=' . rawurlencode($slug);
}

function sql_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function export_database_sql(mysqli $conn): void
{
    $dbRow = $conn->query('SELECT DATABASE() AS db_name')->fetch_assoc();
    $dbName = (string) ($dbRow['db_name'] ?? 'database');
    $safeDbName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $dbName) ?: 'database';
    $filename = $safeDbName . '_backup_' . date('Ymd_His') . '.sql';

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo "-- Backup SQL generated by Admin Panel\n";
    echo '-- Database: ' . $dbName . "\n";
    echo '-- Generated at: ' . date('Y-m-d H:i:s') . "\n\n";
    echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    echo "SET time_zone = \"+00:00\";\n";
    echo "/*!40101 SET NAMES utf8mb4 */;\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tablesResult = $conn->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
    if (!$tablesResult) {
        echo "-- Failed to read table list.\n";
        echo "SET FOREIGN_KEY_CHECKS = 1;\n";
        exit;
    }

    $tables = [];
    while ($row = $tablesResult->fetch_row()) {
        if (!isset($row[0])) {
            continue;
        }
        $tables[] = (string) $row[0];
    }
    sort($tables);

    foreach ($tables as $tableName) {
        $quotedTable = sql_identifier($tableName);
        $createResult = $conn->query('SHOW CREATE TABLE ' . $quotedTable);
        $createRow = $createResult ? $createResult->fetch_row() : null;
        $createSql = (string) ($createRow[1] ?? '');

        echo "--\n";
        echo '-- Table structure for table ' . $tableName . "\n";
        echo "--\n\n";
        echo 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n";
        if ($createSql !== '') {
            echo $createSql . ";\n\n";
        } else {
            echo "-- Failed to fetch CREATE TABLE statement.\n\n";
        }

        $dataResult = $conn->query('SELECT * FROM ' . $quotedTable);
        if (!$dataResult || $dataResult->num_rows === 0) {
            echo "-- No data for " . $tableName . "\n\n";
            continue;
        }

        $fields = $dataResult->fetch_fields();
        $columnSql = implode(', ', array_map(static function ($field): string {
            return sql_identifier((string) $field->name);
        }, $fields));

        echo "--\n";
        echo '-- Dumping data for table ' . $tableName . "\n";
        echo "--\n";

        while ($row = $dataResult->fetch_assoc()) {
            $values = [];
            foreach ($fields as $field) {
                $name = (string) $field->name;
                $value = $row[$name] ?? null;
                if ($value === null) {
                    $values[] = 'NULL';
                    continue;
                }
                $values[] = "'" . $conn->real_escape_string((string) $value) . "'";
            }
            echo 'INSERT INTO ' . $quotedTable . ' (' . $columnSql . ') VALUES (' . implode(', ', $values) . ");\n";
        }
        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    exit;
}

function is_duplicate_slug(mysqli $conn, string $table, string $slug, int $excludeId = 0): bool
{
    $allowed = ['products', 'services', 'projects', 'articles'];
    if (!in_array($table, $allowed, true)) {
        return false;
    }
    $query = "SELECT id FROM {$table} WHERE slug = ? AND id <> ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('si', $slug, $excludeId);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

function uploads_dir_path(string $folder = 'products'): string
{
    $safeFolder = preg_replace('/[^a-z0-9_-]/i', '', $folder) ?: 'products';
    $dir = __DIR__ . '/../assets/images/uploads/' . $safeFolder;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function upload_jpg_file(array $file, ?string &$uploadError = null, string $folder = 'products'): ?string
{
    // Keep legacy function name for compatibility; now supports JPG/JPEG/PNG.
    return upload_image_file($file, $uploadError, $folder);
}

function upload_image_file(array $file, ?string &$uploadError = null, string $folder = 'products'): ?string
{
    $safeFolder = preg_replace('/[^a-z0-9_-]/i', '', $folder) ?: 'products';
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !isset($file['tmp_name'])) {
        $uploadError = 'Upload file gagal. Coba ulangi.';
        return null;
    }
    $tmpName = (string) $file['tmp_name'];
    $originalName = (string) ($file['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        $uploadError = 'Format gambar harus JPG/JPEG/PNG.';
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowedMime = ['image/jpeg', 'image/pjpeg', 'image/jpg', 'image/png'];
    if (!in_array($mime, $allowedMime, true)) {
        $uploadError = 'File harus gambar JPG/JPEG/PNG valid.';
        return null;
    }

    $targetExt = ($mime === 'image/png' || $ext === 'png') ? 'png' : 'jpg';
    $targetName = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $targetExt;
    $targetPath = uploads_dir_path($safeFolder) . '/' . $targetName;
    if (!move_uploaded_file($tmpName, $targetPath)) {
        $uploadError = 'Gagal menyimpan file upload.';
        return null;
    }
    return '/assets/images/uploads/' . $safeFolder . '/' . $targetName;
}

function upload_jpg_multiple(string $inputName, ?string &$uploadError = null, string $folder = 'products'): array
{
    $result = [];
    if (!isset($_FILES[$inputName]['name']) || !is_array($_FILES[$inputName]['name'])) {
        return $result;
    }

    $names = $_FILES[$inputName]['name'];
    $tmpNames = $_FILES[$inputName]['tmp_name'] ?? [];
    $errors = $_FILES[$inputName]['error'] ?? [];

    $count = count($names);
    for ($i = 0; $i < $count; $i++) {
        $file = [
            'name' => $names[$i] ?? '',
            'tmp_name' => $tmpNames[$i] ?? '',
            'error' => $errors[$i] ?? UPLOAD_ERR_NO_FILE,
        ];
        $uploaded = upload_jpg_file($file, $uploadError, $folder);
        if ($uploadError !== null) {
            return [];
        }
        if ($uploaded !== null) {
            $result[] = $uploaded;
        }
    }
    return $result;
}

function uploads_files_dir_path(string $folder = 'settings'): string
{
    $safeFolder = preg_replace('/[^a-z0-9_-]/i', '', $folder) ?: 'settings';
    $dir = __DIR__ . '/../assets/files/uploads/' . $safeFolder;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function upload_pdf_file(array $file, ?string &$uploadError = null, string $folder = 'settings'): ?string
{
    $safeFolder = preg_replace('/[^a-z0-9_-]/i', '', $folder) ?: 'settings';
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !isset($file['tmp_name'])) {
        $uploadError = 'Upload file PDF gagal. Coba ulangi.';
        return null;
    }
    $tmpName = (string) $file['tmp_name'];
    $originalName = (string) ($file['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        $uploadError = 'Format file harus PDF (.pdf).';
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowedMime = ['application/pdf', 'application/x-pdf'];
    if (!in_array($mime, $allowedMime, true)) {
        $uploadError = 'File harus PDF valid.';
        return null;
    }

    $targetName = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.pdf';
    $targetPath = uploads_files_dir_path($safeFolder) . '/' . $targetName;
    if (!move_uploaded_file($tmpName, $targetPath)) {
        $uploadError = 'Gagal menyimpan file PDF upload.';
        return null;
    }
    return '/assets/files/uploads/' . $safeFolder . '/' . $targetName;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /admin/');
    exit;
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    $loginError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_login') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($username === ADMIN_USER && $password === ADMIN_PASS) {
            $_SESSION['admin_logged_in'] = true;
            redirect_admin('dashboard');
        }
        $loginError = 'Username atau password salah.';
    }
    ?>
    <!doctype html>
    <html lang="id">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>MPS - PT. Maulana Prima Sejahtera</title>
      <style>
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; background: radial-gradient(circle at 20% 0%, #fff4ed 0%, #f3f6fb 38%); color: #0f172a; }
        .box { max-width: 420px; margin: 88px auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px; box-shadow: 0 16px 36px rgba(15, 23, 42, 0.07); }
        h1 { margin: 0 0 6px; font-size: 24px; }
        p { margin: 0 0 14px; color: #64748b; font-size: 14px; }
        input { width: 100%; padding: 10px 12px; margin-bottom: 10px; border: 1px solid #cbd5e1; border-radius: 10px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; border: 0; background: #ff5e14; color: #fff; font-weight: 700; border-radius: 10px; cursor: pointer; }
        .err { color: #b91c1c; margin-bottom: 9px; font-size: 13px; font-weight: 600; }
      </style>
    </head>
    <body>
      <div class="box">
        <h1>Admin Login</h1>
        <p>Masuk untuk kelola produk dan review.</p>
        <?php if ($loginError !== ''): ?><div class="err"><?= e($loginError) ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="action" value="admin_login">
          <input name="username" placeholder="Username" required>
          <input type="password" name="password" placeholder="Password" required>
          <button type="submit">Masuk</button>
        </form>
      </div>
    </body>
    </html>
    <?php
    exit;
}

$allowedPages = ['dashboard', 'products', 'services', 'projects', 'articles', 'testimonials', 'reviews', 'team', 'settings'];
$activePage = (string) ($_GET['page'] ?? 'dashboard');
if (!in_array($activePage, $allowedPages, true)) {
    $activePage = 'dashboard';
}

$conn = db();
$error = '';
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
// Ensure marketplace links are not blocked by long URLs from marketplaces.
$conn->query("ALTER TABLE products MODIFY COLUMN marketplace_shopee TEXT NULL");
$conn->query("ALTER TABLE products MODIFY COLUMN marketplace_tokopedia TEXT NULL");
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
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS card_highlight VARCHAR(80) NOT NULL DEFAULT '' AFTER name");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS client_name VARCHAR(180) NOT NULL DEFAULT '' AFTER image");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS location_name VARCHAR(180) NOT NULL DEFAULT '' AFTER client_name");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS project_year VARCHAR(80) NOT NULL DEFAULT '' AFTER location_name");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS duration_text VARCHAR(120) NOT NULL DEFAULT '' AFTER project_year");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS price_text VARCHAR(120) NOT NULL DEFAULT '' AFTER duration_text");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER short_description");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS features_json JSON NULL AFTER description");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS video_url VARCHAR(255) NULL AFTER detail_url");
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
$conn->query(
    "CREATE TABLE IF NOT EXISTS site_settings (
        setting_key VARCHAR(120) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$conn->query(
    "INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
    ('social_facebook', ''),
    ('social_twitter', ''),
    ('social_instagram', ''),
    ('social_youtube', ''),
    ('social_linkedin', ''),
    ('social_whatsapp', ''),
    ('contact_whatsapp_number', ''),
    ('footer_phone_primary', ''),
    ('footer_phone_secondary', ''),
    ('footer_office_hours_1', ''),
    ('footer_office_hours_2', ''),
    ('footer_support_email_primary', ''),
    ('footer_support_email_secondary', ''),
    ('footer_address_1', ''),
    ('footer_address_2', ''),
    ('header_phone_primary', ''),
    ('header_phone_secondary', ''),
    ('header_email_primary', ''),
    ('home_show_team_section', '1'),
    ('show_menu_layanan', '1'),
    ('show_menu_produk', '1'),
    ('show_menu_proyek', '1'),
    ('show_menu_artikel', '1'),
    ('show_menu_kontak', '1'),
    ('show_menu_tentang', '1'),
    ('contact_section_pretitle', ''),
    ('contact_section_title', ''),
    ('contact_section_description', ''),
    ('contact_card_call_title', ''),
    ('contact_card_office_title', ''),
    ('company_profile_pdf_url', ''),
    ('map_embed_url', ''),
    ('map_lat', ''),
    ('map_lng', ''),
    ('map_zoom', '')"
);

$requestedAction = (string) ($_GET['action'] ?? '');
if ($requestedAction === 'export_sql') {
    export_database_sql($conn);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        $redirectPage = (string) ($_POST['redirect_page'] ?? $activePage);
        if (!in_array($redirectPage, $allowedPages, true)) {
            $redirectPage = 'dashboard';
        }

        if ($action === 'upload_article_inline_image') {
            header('Content-Type: application/json; charset=utf-8');
            $uploadError = null;
            $uploadedImage = upload_jpg_file($_FILES['inline_image_file'] ?? [], $uploadError, 'articles');
            if ($uploadError !== null || $uploadedImage === null) {
                http_response_code(422);
                echo json_encode([
                    'ok' => false,
                    'message' => $uploadError ?? 'Upload gambar gagal.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode([
                'ok' => true,
                'url' => $uploadedImage,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'save_product') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $productBadge = trim((string) ($_POST['product_badge'] ?? ''));
        $price = (int) ($_POST['price'] ?? 0);
        $image = trim((string) ($_POST['current_image'] ?? ''));
        $shortDescription = trim((string) ($_POST['short_description'] ?? ''));
        $descriptionRaw = (string) ($_POST['description'] ?? '');
        $description = preg_match('/<[a-z][\s\S]*>/i', $descriptionRaw) ? sanitize_rich_description_html($descriptionRaw) : trim($descriptionRaw);
        $featuresJson = text_to_json_lines((string) ($_POST['features'] ?? ''));
        $additionalJson = text_to_json_lines((string) ($_POST['additional'] ?? ''));
        $useCaseTagsJson = text_to_json_lines((string) ($_POST['use_case_tags'] ?? ''));
        $marketplaceShopee = trim((string) ($_POST['marketplace_shopee'] ?? ''));
        $marketplaceTokopedia = trim((string) ($_POST['marketplace_tokopedia'] ?? ''));
        $category = mb_substr($category, 0, 140);
        $allowedBadges = ['best_seller', 'most_searched', ''];
        if (!in_array($productBadge, $allowedBadges, true)) {
            $productBadge = '';
        }
        $marketplaceShopee = mb_substr($marketplaceShopee, 0, 255);
        $marketplaceTokopedia = mb_substr($marketplaceTokopedia, 0, 255);

        $slug = slugify($slug !== '' ? $slug : $name);

        $uploadError = null;
        $uploadedMainImage = upload_jpg_file($_FILES['image_file'] ?? [], $uploadError);
        $uploadedGalleryImages = upload_jpg_multiple('gallery_files', $uploadError, 'products');
        if ($uploadError !== null) {
            $error = $uploadError;
        }
        if ($uploadedMainImage !== null) {
            $image = $uploadedMainImage;
        }
        if ($image === '' && $uploadedGalleryImages) {
            $image = (string) array_shift($uploadedGalleryImages);
        }

        if ($error !== '') {
            // Upload error: stop save and show error message.
        } elseif ($slug === '') {
            $error = 'Slug produk tidak valid. Coba isi nama/slug dengan huruf atau angka.';
        } elseif (is_duplicate_slug($conn, 'products', $slug, $id)) {
            $error = 'Slug produk sudah dipakai. Gunakan slug lain yang unik.';
        } elseif ($name === '' || $slug === '' || $image === '') {
            $error = 'Nama produk, slug, dan gambar utama wajib diisi. Silakan upload JPG/JPEG/PNG untuk gambar utama.';
        } elseif ($id > 0) {
            $stmt = $conn->prepare(
                "UPDATE products
                 SET slug = ?, name = ?, category = ?, product_badge = ?, price = ?, image = ?, short_description = ?, description = ?, features_json = ?, additional_json = ?, use_case_tags_json = ?, marketplace_shopee = ?, marketplace_tokopedia = ?
                 WHERE id = ?"
            );
            $stmt->bind_param('ssssissssssssi', $slug, $name, $category, $productBadge, $price, $image, $shortDescription, $description, $featuresJson, $additionalJson, $useCaseTagsJson, $marketplaceShopee, $marketplaceTokopedia, $id);
            $stmt->execute();
            if ($uploadedGalleryImages) {
                $orderResult = $conn->query("SELECT COALESCE(MAX(sort_order), 0) AS max_order FROM product_images WHERE product_id = " . (int) $id);
                $maxOrder = (int) (($orderResult ? $orderResult->fetch_assoc()['max_order'] : 0) ?? 0);
                $insertGalleryStmt = $conn->prepare("INSERT INTO product_images (product_id, image_path, sort_order) VALUES (?, ?, ?)");
                foreach ($uploadedGalleryImages as $galleryImagePath) {
                    $maxOrder++;
                    $insertGalleryStmt->bind_param('isi', $id, $galleryImagePath, $maxOrder);
                    $insertGalleryStmt->execute();
                }
            }
            $_SESSION['flash_message'] = 'Produk berhasil diupdate.';
            redirect_admin('products');
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO products (slug, name, category, product_badge, price, image, short_description, description, features_json, additional_json, use_case_tags_json, marketplace_shopee, marketplace_tokopedia)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('ssssisssssssss', $slug, $name, $category, $productBadge, $price, $image, $shortDescription, $description, $featuresJson, $additionalJson, $useCaseTagsJson, $marketplaceShopee, $marketplaceTokopedia);
            $stmt->execute();
            $newProductId = (int) $conn->insert_id;
            if ($uploadedGalleryImages) {
                $insertGalleryStmt = $conn->prepare("INSERT INTO product_images (product_id, image_path, sort_order) VALUES (?, ?, ?)");
                $order = 0;
                foreach ($uploadedGalleryImages as $galleryImagePath) {
                    $order++;
                    $insertGalleryStmt->bind_param('isi', $newProductId, $galleryImagePath, $order);
                    $insertGalleryStmt->execute();
                }
            }
            $_SESSION['flash_message'] = 'Produk berhasil ditambahkan.';
            redirect_admin('products');
        }
    }

    if ($action === 'delete_product') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $mainPaths = [];
            $galleryPaths = [];
            $selectStmt = $conn->prepare("SELECT image FROM products WHERE id = ? LIMIT 1");
            $selectStmt->bind_param('i', $id);
            $selectStmt->execute();
            $row = $selectStmt->get_result()->fetch_assoc();
            if ($row) {
                $mainPaths[] = (string) ($row['image'] ?? '');
            }
            $galleryStmt = $conn->prepare("SELECT image_path FROM product_images WHERE product_id = ?");
            $galleryStmt->bind_param('i', $id);
            $galleryStmt->execute();
            $galleryResult = $galleryStmt->get_result();
            while ($galleryRow = $galleryResult->fetch_assoc()) {
                $galleryPaths[] = (string) ($galleryRow['image_path'] ?? '');
            }
            delete_uploaded_files_if_unreferenced(
                $conn,
                $mainPaths,
                '/assets/images/uploads/products/',
                'products',
                'image',
                [$id],
                'id'
            );
            delete_uploaded_files_if_unreferenced(
                $conn,
                $galleryPaths,
                '/assets/images/uploads/products/',
                'product_images',
                'image_path',
                [$id],
                'product_id'
            );
            $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $_SESSION['flash_message'] = 'Produk berhasil dihapus.';
        }
        redirect_admin($redirectPage);
    }

    if ($action === 'bulk_delete_products') {
        $ids = normalize_bulk_int_ids($_POST['ids'] ?? []);
        if (!$ids) {
            $_SESSION['flash_message'] = 'Tidak ada produk yang dipilih.';
            redirect_admin('products');
        }
        $mainPaths = [];
        $galleryPaths = [];
        $rows = select_rows_by_ids($conn, "SELECT image FROM products WHERE id IN", $ids);
        foreach ($rows as $row) {
            $mainPaths[] = (string) ($row['image'] ?? '');
        }
        $galleryRows = select_rows_by_ids($conn, "SELECT image_path FROM product_images WHERE product_id IN", $ids);
        foreach ($galleryRows as $row) {
            $galleryPaths[] = (string) ($row['image_path'] ?? '');
        }
        delete_uploaded_files_if_unreferenced(
            $conn,
            $mainPaths,
            '/assets/images/uploads/products/',
            'products',
            'image',
            $ids,
            'id'
        );
        delete_uploaded_files_if_unreferenced(
            $conn,
            $galleryPaths,
            '/assets/images/uploads/products/',
            'product_images',
            'image_path',
            $ids,
            'product_id'
        );
        delete_table_rows_by_ids($conn, 'products', $ids);
        $_SESSION['flash_message'] = 'Produk terpilih berhasil dihapus (' . count($ids) . ').';
        redirect_admin('products');
    }

        if ($action === 'delete_gallery_image') {
        $imageId = (int) ($_POST['image_id'] ?? 0);
        $productId = (int) ($_POST['product_id'] ?? 0);
        if ($imageId > 0) {
            $stmt = $conn->prepare("SELECT image_path FROM product_images WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $imageId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $path = (string) ($row['image_path'] ?? '');
                delete_uploaded_files_if_unreferenced(
                    $conn,
                    [$path],
                    '/assets/images/uploads/products/',
                    'product_images',
                    'image_path',
                    [$imageId],
                    'id'
                );
            }
            $deleteStmt = $conn->prepare("DELETE FROM product_images WHERE id = ?");
            $deleteStmt->bind_param('i', $imageId);
            $deleteStmt->execute();
            $_SESSION['flash_message'] = 'Gambar gallery berhasil dihapus.';
        }
        redirect_admin('products', ['edit' => $productId]);
    }

        if ($action === 'approve_review') {
        $id = (int) ($_POST['id'] ?? 0);
        $reviewType = trim((string) ($_POST['review_type'] ?? 'product'));
        if ($id > 0) {
            $table = $reviewType === 'service' ? 'service_reviews' : 'reviews';
            $stmt = $conn->prepare("UPDATE {$table} SET is_approved = 1 WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $_SESSION['flash_message'] = 'Review disetujui.';
        }
        redirect_admin($redirectPage);
    }

        if ($action === 'delete_review') {
        $id = (int) ($_POST['id'] ?? 0);
        $reviewType = trim((string) ($_POST['review_type'] ?? 'product'));
        if ($id > 0) {
            $table = $reviewType === 'service' ? 'service_reviews' : 'reviews';
            $stmt = $conn->prepare("DELETE FROM {$table} WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $_SESSION['flash_message'] = 'Review dihapus.';
        }
        redirect_admin($redirectPage);
    }

        if ($action === 'bulk_delete_reviews') {
        $keys = $_POST['review_keys'] ?? [];
        if (!is_array($keys) || !$keys) {
            $_SESSION['flash_message'] = 'Tidak ada review yang dipilih.';
            redirect_admin('reviews');
        }
        $productIds = [];
        $serviceIds = [];
        foreach ($keys as $key) {
            $key = trim((string) $key);
            if ($key === '' || strpos($key, ':') === false) {
                continue;
            }
            [$type, $idRaw] = explode(':', $key, 2);
            $id = (int) $idRaw;
            if ($id <= 0) {
                continue;
            }
            if ($type === 'service') {
                $serviceIds[] = $id;
            } else {
                $productIds[] = $id;
            }
        }
        $productIds = normalize_bulk_int_ids($productIds);
        $serviceIds = normalize_bulk_int_ids($serviceIds);
        if (!$productIds && !$serviceIds) {
            $_SESSION['flash_message'] = 'Tidak ada review yang dipilih.';
            redirect_admin('reviews');
        }
        if ($productIds) {
            delete_table_rows_by_ids($conn, 'reviews', $productIds);
        }
        if ($serviceIds) {
            delete_table_rows_by_ids($conn, 'service_reviews', $serviceIds);
        }
        $total = count($productIds) + count($serviceIds);
        $_SESSION['flash_message'] = 'Review terpilih berhasil dihapus (' . $total . ').';
        redirect_admin('reviews');
    }

        if ($action === 'save_team_member') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? ''));
        $image = trim((string) ($_POST['current_image'] ?? ''));
        $facebook = trim((string) ($_POST['social_facebook'] ?? ''));
        $linkedin = trim((string) ($_POST['social_linkedin'] ?? ''));
        $youtube = trim((string) ($_POST['social_youtube'] ?? ''));
        $whatsapp = trim((string) ($_POST['social_whatsapp'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $uploadError = null;
        $uploadedImage = upload_jpg_file($_FILES['image_file'] ?? [], $uploadError, 'team');
        if ($uploadError !== null) {
            $error = $uploadError;
        }
        if ($uploadedImage !== null) {
            $image = $uploadedImage;
        }

        if ($error !== '') {
            // Upload error: stop save and show error message.
        } elseif ($name === '' || $role === '' || $image === '') {
            $error = 'Nama tim, jabatan, dan foto JPG/JPEG/PNG wajib diisi.';
        } elseif ($id > 0) {
            $stmt = $conn->prepare(
                "UPDATE team_members
                 SET name = ?, role = ?, image = ?, social_facebook = ?, social_linkedin = ?, social_youtube = ?, social_whatsapp = ?, sort_order = ?, is_active = ?
                 WHERE id = ?"
            );
            $stmt->bind_param('sssssssiii', $name, $role, $image, $facebook, $linkedin, $youtube, $whatsapp, $sortOrder, $isActive, $id);
            $stmt->execute();
            $_SESSION['flash_message'] = 'Data tim berhasil diupdate.';
            redirect_admin('team');
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO team_members (name, role, image, social_facebook, social_linkedin, social_youtube, social_whatsapp, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('sssssssii', $name, $role, $image, $facebook, $linkedin, $youtube, $whatsapp, $sortOrder, $isActive);
            $stmt->execute();
            $_SESSION['flash_message'] = 'Data tim berhasil ditambahkan.';
            redirect_admin('team');
        }
    }

        if ($action === 'delete_team_member') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT image FROM team_members WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $path = (string) ($row['image'] ?? '');
                delete_uploaded_files_if_unreferenced(
                    $conn,
                    [$path],
                    '/assets/images/uploads/team/',
                    'team_members',
                    'image',
                    [$id],
                    'id'
                );
            }

            $deleteStmt = $conn->prepare("DELETE FROM team_members WHERE id = ?");
            $deleteStmt->bind_param('i', $id);
            $deleteStmt->execute();
            $_SESSION['flash_message'] = 'Data tim berhasil dihapus.';
        }
        redirect_admin('team');
    }

        if ($action === 'bulk_delete_team') {
        $ids = normalize_bulk_int_ids($_POST['ids'] ?? []);
        if (!$ids) {
            $_SESSION['flash_message'] = 'Tidak ada anggota tim yang dipilih.';
            redirect_admin('team');
        }
        $rows = select_rows_by_ids($conn, "SELECT image FROM team_members WHERE id IN", $ids);
        $paths = array_map(static fn(array $row): string => (string) ($row['image'] ?? ''), $rows);
        delete_uploaded_files_if_unreferenced(
            $conn,
            $paths,
            '/assets/images/uploads/team/',
            'team_members',
            'image',
            $ids,
            'id'
        );
        delete_table_rows_by_ids($conn, 'team_members', $ids);
        $_SESSION['flash_message'] = 'Anggota tim terpilih berhasil dihapus (' . count($ids) . ').';
        redirect_admin('team');
    }

        if ($action === 'save_testimonial') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $company = trim((string) ($_POST['company'] ?? ''));
        $quoteText = trim((string) ($_POST['quote_text'] ?? ''));
        $avatarImage = trim((string) ($_POST['current_avatar_image'] ?? ''));
        $brandLogo = trim((string) ($_POST['current_brand_logo'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $uploadError = null;
        $uploadedAvatarImage = upload_image_file($_FILES['avatar_file'] ?? [], $uploadError, 'testimonials');
        $uploadedBrandLogo = upload_image_file($_FILES['brand_logo_file'] ?? [], $uploadError, 'testimonials');
        if ($uploadError !== null) {
            $error = $uploadError;
        }
        if ($uploadedAvatarImage !== null) {
            $avatarImage = $uploadedAvatarImage;
        }
        if ($uploadedBrandLogo !== null) {
            $brandLogo = $uploadedBrandLogo;
        }

        if ($error !== '') {
            // Upload error: stop save and show error message.
        } elseif ($name === '' || $company === '' || $quoteText === '' || $avatarImage === '' || $brandLogo === '') {
            $error = 'Nama, perusahaan, quote, foto, dan logo wajib diisi.';
        } elseif ($id > 0) {
            $stmt = $conn->prepare(
                "UPDATE testimonials
                 SET name = ?, company = ?, quote_text = ?, avatar_image = ?, brand_logo = ?, sort_order = ?, is_active = ?
                 WHERE id = ?"
            );
            $stmt->bind_param('sssssiii', $name, $company, $quoteText, $avatarImage, $brandLogo, $sortOrder, $isActive, $id);
            $stmt->execute();
            $_SESSION['flash_message'] = 'Testimonial berhasil diupdate.';
            redirect_admin('testimonials');
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO testimonials (name, company, quote_text, avatar_image, brand_logo, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('sssssii', $name, $company, $quoteText, $avatarImage, $brandLogo, $sortOrder, $isActive);
            $stmt->execute();
            $_SESSION['flash_message'] = 'Testimonial berhasil ditambahkan.';
            redirect_admin('testimonials');
        }
    }

        if ($action === 'delete_testimonial') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT avatar_image, brand_logo FROM testimonials WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $avatar = (string) ($row['avatar_image'] ?? '');
                $logo = (string) ($row['brand_logo'] ?? '');
                delete_uploaded_files_if_unreferenced(
                    $conn,
                    [$avatar],
                    '/assets/images/uploads/testimonials/',
                    'testimonials',
                    'avatar_image',
                    [$id],
                    'id'
                );
                delete_uploaded_files_if_unreferenced(
                    $conn,
                    [$logo],
                    '/assets/images/uploads/testimonials/',
                    'testimonials',
                    'brand_logo',
                    [$id],
                    'id'
                );
            }
            $deleteStmt = $conn->prepare("DELETE FROM testimonials WHERE id = ?");
            $deleteStmt->bind_param('i', $id);
            $deleteStmt->execute();
            $_SESSION['flash_message'] = 'Testimonial berhasil dihapus.';
        }
        redirect_admin('testimonials');
    }

        if ($action === 'bulk_delete_testimonials') {
        $ids = normalize_bulk_int_ids($_POST['ids'] ?? []);
        if (!$ids) {
            $_SESSION['flash_message'] = 'Tidak ada testimonial yang dipilih.';
            redirect_admin('testimonials');
        }
        $rows = select_rows_by_ids($conn, "SELECT avatar_image, brand_logo FROM testimonials WHERE id IN", $ids);
        $avatarPaths = [];
        $logoPaths = [];
        foreach ($rows as $row) {
            $avatarPaths[] = (string) ($row['avatar_image'] ?? '');
            $logoPaths[] = (string) ($row['brand_logo'] ?? '');
        }
        delete_uploaded_files_if_unreferenced(
            $conn,
            $avatarPaths,
            '/assets/images/uploads/testimonials/',
            'testimonials',
            'avatar_image',
            $ids,
            'id'
        );
        delete_uploaded_files_if_unreferenced(
            $conn,
            $logoPaths,
            '/assets/images/uploads/testimonials/',
            'testimonials',
            'brand_logo',
            $ids,
            'id'
        );
        delete_table_rows_by_ids($conn, 'testimonials', $ids);
        $_SESSION['flash_message'] = 'Testimonial terpilih berhasil dihapus (' . count($ids) . ').';
        redirect_admin('testimonials');
    }

        if ($action === 'save_service') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $cardHighlight = trim((string) ($_POST['card_highlight'] ?? ''));
        $image = trim((string) ($_POST['current_image'] ?? ''));
        $clientName = '';
        $locationName = '';
        $projectYear = '';
        $durationText = trim((string) ($_POST['duration_text'] ?? ''));
        $priceText = normalize_rupiah_text((string) ($_POST['price_text'] ?? ''));
        $shortDescription = trim((string) ($_POST['short_description'] ?? ''));
        $descriptionRaw = (string) ($_POST['description'] ?? '');
        $description = preg_match('/<[a-z][\s\S]*>/i', $descriptionRaw) ? sanitize_rich_description_html($descriptionRaw) : trim($descriptionRaw);
        $featuresJson = text_to_json_lines((string) ($_POST['features'] ?? ''));
        $videoUrl = trim((string) ($_POST['video_url'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $cardHighlight = mb_substr($cardHighlight, 0, 80);
        $durationText = mb_substr($durationText, 0, 120);
        $videoUrl = mb_substr($videoUrl, 0, 255);

        $slug = slugify($slug !== '' ? $slug : $name);
        $detailUrl = build_service_detail_url($slug);

        $uploadError = null;
        $uploadedImage = upload_jpg_file($_FILES['image_file'] ?? [], $uploadError, 'services');
        $uploadedGalleryImages = upload_jpg_multiple('service_gallery_files', $uploadError, 'services');
        if ($uploadError !== null) {
            $error = $uploadError;
        }
        if ($uploadedImage !== null) {
            $image = $uploadedImage;
        }
        if ($image === '' && $uploadedGalleryImages) {
            $image = (string) array_shift($uploadedGalleryImages);
        }

        if ($error !== '') {
            // Upload error: stop save and show error message.
        } elseif ($slug === '') {
            $error = 'Slug layanan tidak valid. Coba isi nama/slug dengan huruf atau angka.';
        } elseif (is_duplicate_slug($conn, 'services', $slug, $id)) {
            $error = 'Slug layanan sudah dipakai. Gunakan slug lain yang unik.';
        } elseif ($name === '' || mb_strlen($name) < 3) {
            $error = 'Nama layanan minimal 3 karakter.';
        } elseif ($image === '') {
            $error = 'Nama layanan, slug, dan gambar wajib diisi.';
        } elseif ($shortDescription === '' || mb_strlen($shortDescription) < 10) {
            $error = 'Deskripsi singkat wajib diisi minimal 10 karakter.';
        } elseif ($durationText === '' && $priceText === '') {
            $error = 'Isi minimal salah satu: Estimasi Pengerjaan atau Harga/Nilai Proyek.';
        } elseif ($videoUrl !== '' && !preg_match('#^(https?://|/assets/videos/)#i', $videoUrl)) {
            $error = 'Link video harus URL http/https atau path lokal /assets/videos/...';
        } elseif ($id > 0) {
            $stmt = $conn->prepare(
                "UPDATE services
                 SET slug = ?, name = ?, card_highlight = ?, image = ?, client_name = ?, location_name = ?, project_year = ?, duration_text = ?, price_text = ?, short_description = ?, description = ?, features_json = ?, detail_url = ?, video_url = ?, sort_order = ?, is_active = ?
                 WHERE id = ?"
            );
            $stmt->bind_param('ssssssssssssssiii', $slug, $name, $cardHighlight, $image, $clientName, $locationName, $projectYear, $durationText, $priceText, $shortDescription, $description, $featuresJson, $detailUrl, $videoUrl, $sortOrder, $isActive, $id);
            $stmt->execute();
            if ($uploadedGalleryImages) {
                $orderResult = $conn->query("SELECT COALESCE(MAX(sort_order), 0) AS max_order FROM service_images WHERE service_id = " . (int) $id);
                $maxOrder = (int) (($orderResult ? $orderResult->fetch_assoc()['max_order'] : 0) ?? 0);
                $insertGalleryStmt = $conn->prepare("INSERT INTO service_images (service_id, image_path, sort_order) VALUES (?, ?, ?)");
                foreach ($uploadedGalleryImages as $galleryImagePath) {
                    $maxOrder++;
                    $insertGalleryStmt->bind_param('isi', $id, $galleryImagePath, $maxOrder);
                    $insertGalleryStmt->execute();
                }
            }
            $_SESSION['flash_message'] = 'Layanan berhasil diupdate.';
            redirect_admin('services');
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO services (slug, name, card_highlight, image, client_name, location_name, project_year, duration_text, price_text, short_description, description, features_json, detail_url, video_url, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('ssssssssssssssii', $slug, $name, $cardHighlight, $image, $clientName, $locationName, $projectYear, $durationText, $priceText, $shortDescription, $description, $featuresJson, $detailUrl, $videoUrl, $sortOrder, $isActive);
            $stmt->execute();
            $newServiceId = (int) $conn->insert_id;
            if ($uploadedGalleryImages) {
                $insertGalleryStmt = $conn->prepare("INSERT INTO service_images (service_id, image_path, sort_order) VALUES (?, ?, ?)");
                $order = 0;
                foreach ($uploadedGalleryImages as $galleryImagePath) {
                    $order++;
                    $insertGalleryStmt->bind_param('isi', $newServiceId, $galleryImagePath, $order);
                    $insertGalleryStmt->execute();
                }
            }
            $_SESSION['flash_message'] = 'Layanan berhasil ditambahkan.';
            redirect_admin('services');
        }
    }

        if ($action === 'delete_service_image') {
        $imageId = (int) ($_POST['image_id'] ?? 0);
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        if ($imageId > 0) {
            $stmt = $conn->prepare("SELECT image_path FROM service_images WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $imageId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $path = (string) ($row['image_path'] ?? '');
                delete_uploaded_files_if_unreferenced(
                    $conn,
                    [$path],
                    '/assets/images/uploads/services/',
                    'service_images',
                    'image_path',
                    [$imageId],
                    'id'
                );
            }
            $deleteStmt = $conn->prepare("DELETE FROM service_images WHERE id = ?");
            $deleteStmt->bind_param('i', $imageId);
            $deleteStmt->execute();
            $_SESSION['flash_message'] = 'Gambar gallery layanan berhasil dihapus.';
        }
        redirect_admin('services', ['edit' => $serviceId]);
    }

        if ($action === 'delete_service') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $mainPaths = [];
            $galleryPaths = [];
            $stmt = $conn->prepare("SELECT image FROM services WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $mainPaths[] = (string) ($row['image'] ?? '');
            }
            $galleryStmt = $conn->prepare("SELECT image_path FROM service_images WHERE service_id = ?");
            $galleryStmt->bind_param('i', $id);
            $galleryStmt->execute();
            $galleryResult = $galleryStmt->get_result();
            while ($galleryRow = $galleryResult->fetch_assoc()) {
                $galleryPaths[] = (string) ($galleryRow['image_path'] ?? '');
            }
            delete_uploaded_files_if_unreferenced(
                $conn,
                $mainPaths,
                '/assets/images/uploads/services/',
                'services',
                'image',
                [$id],
                'id'
            );
            delete_uploaded_files_if_unreferenced(
                $conn,
                $galleryPaths,
                '/assets/images/uploads/services/',
                'service_images',
                'image_path',
                [$id],
                'service_id'
            );
            $deleteStmt = $conn->prepare("DELETE FROM services WHERE id = ?");
            $deleteStmt->bind_param('i', $id);
            $deleteStmt->execute();
            $_SESSION['flash_message'] = 'Layanan berhasil dihapus.';
        }
        redirect_admin('services');
    }

        if ($action === 'bulk_delete_services') {
        $ids = normalize_bulk_int_ids($_POST['ids'] ?? []);
        if (!$ids) {
            $_SESSION['flash_message'] = 'Tidak ada layanan yang dipilih.';
            redirect_admin('services');
        }
        $mainPaths = [];
        $galleryPaths = [];
        $rows = select_rows_by_ids($conn, "SELECT image FROM services WHERE id IN", $ids);
        foreach ($rows as $row) {
            $mainPaths[] = (string) ($row['image'] ?? '');
        }
        $galleryRows = select_rows_by_ids($conn, "SELECT image_path FROM service_images WHERE service_id IN", $ids);
        foreach ($galleryRows as $row) {
            $galleryPaths[] = (string) ($row['image_path'] ?? '');
        }
        delete_uploaded_files_if_unreferenced(
            $conn,
            $mainPaths,
            '/assets/images/uploads/services/',
            'services',
            'image',
            $ids,
            'id'
        );
        delete_uploaded_files_if_unreferenced(
            $conn,
            $galleryPaths,
            '/assets/images/uploads/services/',
            'service_images',
            'image_path',
            $ids,
            'service_id'
        );
        delete_table_rows_by_ids($conn, 'services', $ids);
        $_SESSION['flash_message'] = 'Layanan terpilih berhasil dihapus (' . count($ids) . ').';
        redirect_admin('services');
    }

        if ($action === 'save_project') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $image = trim((string) ($_POST['current_image'] ?? ''));
        $clientName = trim((string) ($_POST['client_name'] ?? ''));
        $locationName = trim((string) ($_POST['location_name'] ?? ''));
        $projectYear = trim((string) ($_POST['project_year'] ?? ''));
        $durationText = trim((string) ($_POST['duration_text'] ?? ''));
        $priceText = trim((string) ($_POST['price_text'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $shortDescription = trim((string) ($_POST['short_description'] ?? ''));
        $descriptionRaw = (string) ($_POST['description'] ?? '');
        $description = preg_match('/<[a-z][\s\S]*>/i', $descriptionRaw) ? sanitize_rich_description_html($descriptionRaw) : trim($descriptionRaw);
        $featuresJson = text_to_json_lines((string) ($_POST['features'] ?? ''));
        $videoUrl = trim((string) ($_POST['video_url'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $slug = slugify($slug !== '' ? $slug : $title);
        $detailUrl = build_project_detail_url($slug);

        $uploadError = null;
        $uploadedImage = upload_jpg_file($_FILES['image_file'] ?? [], $uploadError, 'projects');
        $uploadedGalleryImages = upload_jpg_multiple('project_gallery_files', $uploadError, 'projects');
        if ($uploadError !== null) {
            $error = $uploadError;
        }
        if ($uploadedImage !== null) {
            $image = $uploadedImage;
        }
        if ($image === '' && $uploadedGalleryImages) {
            $image = (string) array_shift($uploadedGalleryImages);
        }

        if ($error !== '') {
            // Upload error: stop save and show error message.
        } elseif ($slug === '') {
            $error = 'Slug proyek tidak valid. Coba isi judul/slug dengan huruf atau angka.';
        } elseif (is_duplicate_slug($conn, 'projects', $slug, $id)) {
            $error = 'Slug proyek sudah dipakai. Gunakan slug lain yang unik.';
        } elseif ($title === '' || $slug === '' || $image === '') {
            $error = 'Judul proyek, slug, dan gambar wajib diisi.';
        } elseif ($id > 0) {
            $stmt = $conn->prepare(
                "UPDATE projects
                 SET slug = ?, title = ?, image = ?, client_name = ?, location_name = ?, project_year = ?, duration_text = ?, price_text = ?, category = ?, short_description = ?, description = ?, features_json = ?, detail_url = ?, video_url = ?, sort_order = ?, is_active = ?
                 WHERE id = ?"
            );
            $stmt->bind_param('ssssssssssssssiii', $slug, $title, $image, $clientName, $locationName, $projectYear, $durationText, $priceText, $category, $shortDescription, $description, $featuresJson, $detailUrl, $videoUrl, $sortOrder, $isActive, $id);
            $stmt->execute();
            if ($uploadedGalleryImages) {
                $orderResult = $conn->query("SELECT COALESCE(MAX(sort_order), 0) AS max_order FROM project_images WHERE project_id = " . (int) $id);
                $maxOrder = (int) (($orderResult ? $orderResult->fetch_assoc()['max_order'] : 0) ?? 0);
                $insertGalleryStmt = $conn->prepare("INSERT INTO project_images (project_id, image_path, sort_order) VALUES (?, ?, ?)");
                foreach ($uploadedGalleryImages as $galleryImagePath) {
                    $maxOrder++;
                    $insertGalleryStmt->bind_param('isi', $id, $galleryImagePath, $maxOrder);
                    $insertGalleryStmt->execute();
                }
            }
            $_SESSION['flash_message'] = 'Proyek berhasil diupdate.';
            redirect_admin('projects');
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO projects (slug, title, image, client_name, location_name, project_year, duration_text, price_text, category, short_description, description, features_json, detail_url, video_url, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('ssssssssssssssii', $slug, $title, $image, $clientName, $locationName, $projectYear, $durationText, $priceText, $category, $shortDescription, $description, $featuresJson, $detailUrl, $videoUrl, $sortOrder, $isActive);
            $stmt->execute();
            $newProjectId = (int) $conn->insert_id;
            if ($uploadedGalleryImages) {
                $insertGalleryStmt = $conn->prepare("INSERT INTO project_images (project_id, image_path, sort_order) VALUES (?, ?, ?)");
                $order = 0;
                foreach ($uploadedGalleryImages as $galleryImagePath) {
                    $order++;
                    $insertGalleryStmt->bind_param('isi', $newProjectId, $galleryImagePath, $order);
                    $insertGalleryStmt->execute();
                }
            }
            $_SESSION['flash_message'] = 'Proyek berhasil ditambahkan.';
            redirect_admin('projects');
        }
    }

        if ($action === 'delete_project_image') {
        $imageId = (int) ($_POST['image_id'] ?? 0);
        $projectId = (int) ($_POST['project_id'] ?? 0);
        if ($imageId > 0) {
            $stmt = $conn->prepare("SELECT image_path FROM project_images WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $imageId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $path = (string) ($row['image_path'] ?? '');
                delete_uploaded_files_if_unreferenced(
                    $conn,
                    [$path],
                    '/assets/images/uploads/projects/',
                    'project_images',
                    'image_path',
                    [$imageId],
                    'id'
                );
            }
            $deleteStmt = $conn->prepare("DELETE FROM project_images WHERE id = ?");
            $deleteStmt->bind_param('i', $imageId);
            $deleteStmt->execute();
            $_SESSION['flash_message'] = 'Gambar gallery proyek berhasil dihapus.';
        }
        redirect_admin('projects', ['edit' => $projectId]);
    }

        if ($action === 'delete_project') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $mainPaths = [];
            $galleryPaths = [];
            $stmt = $conn->prepare("SELECT image FROM projects WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $mainPaths[] = (string) ($row['image'] ?? '');
            }
            $galleryStmt = $conn->prepare("SELECT image_path FROM project_images WHERE project_id = ?");
            $galleryStmt->bind_param('i', $id);
            $galleryStmt->execute();
            $galleryResult = $galleryStmt->get_result();
            while ($galleryRow = $galleryResult->fetch_assoc()) {
                $galleryPaths[] = (string) ($galleryRow['image_path'] ?? '');
            }
            delete_uploaded_files_if_unreferenced(
                $conn,
                $mainPaths,
                '/assets/images/uploads/projects/',
                'projects',
                'image',
                [$id],
                'id'
            );
            delete_uploaded_files_if_unreferenced(
                $conn,
                $galleryPaths,
                '/assets/images/uploads/projects/',
                'project_images',
                'image_path',
                [$id],
                'project_id'
            );
            $deleteStmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
            $deleteStmt->bind_param('i', $id);
            $deleteStmt->execute();
            $_SESSION['flash_message'] = 'Proyek berhasil dihapus.';
        }
        redirect_admin('projects');
        }

        if ($action === 'bulk_delete_projects') {
        $ids = normalize_bulk_int_ids($_POST['ids'] ?? []);
        if (!$ids) {
            $_SESSION['flash_message'] = 'Tidak ada proyek yang dipilih.';
            redirect_admin('projects');
        }
        $mainPaths = [];
        $galleryPaths = [];
        $rows = select_rows_by_ids($conn, "SELECT image FROM projects WHERE id IN", $ids);
        foreach ($rows as $row) {
            $mainPaths[] = (string) ($row['image'] ?? '');
        }
        $galleryRows = select_rows_by_ids($conn, "SELECT image_path FROM project_images WHERE project_id IN", $ids);
        foreach ($galleryRows as $row) {
            $galleryPaths[] = (string) ($row['image_path'] ?? '');
        }
        delete_uploaded_files_if_unreferenced(
            $conn,
            $mainPaths,
            '/assets/images/uploads/projects/',
            'projects',
            'image',
            $ids,
            'id'
        );
        delete_uploaded_files_if_unreferenced(
            $conn,
            $galleryPaths,
            '/assets/images/uploads/projects/',
            'project_images',
            'image_path',
            $ids,
            'project_id'
        );
        delete_table_rows_by_ids($conn, 'projects', $ids);
        $_SESSION['flash_message'] = 'Proyek terpilih berhasil dihapus (' . count($ids) . ').';
        redirect_admin('projects');
    }

        if ($action === 'save_article') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $image = trim((string) ($_POST['current_image'] ?? ''));
        $excerpt = sanitize_article_html((string) ($_POST['excerpt'] ?? ''));
        $contentHtml = sanitize_article_html((string) ($_POST['content_html'] ?? ''));
        $authorName = trim((string) ($_POST['author_name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $publishedAt = trim((string) ($_POST['published_at'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $slug = slugify($slug !== '' ? $slug : $title);
        $authorName = $authorName !== '' ? mb_substr($authorName, 0, 140) : 'Admin';
        $category = $category !== '' ? mb_substr($category, 0, 140) : 'Artikel';
        if ($publishedAt !== '') {
            $publishedAt = str_replace('T', ' ', $publishedAt);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $publishedAt)) {
                $publishedAt = '';
            } elseif (strlen($publishedAt) === 16) {
                $publishedAt .= ':00';
            }
        }
        if ($publishedAt === '') {
            $publishedAt = date('Y-m-d H:i:s');
        }

        $uploadError = null;
        $uploadedImage = upload_jpg_file($_FILES['image_file'] ?? [], $uploadError, 'articles');
        if ($uploadError !== null) {
            $error = $uploadError;
        }
        if ($uploadedImage !== null) {
            $image = $uploadedImage;
        }

        if ($error !== '') {
            // Upload error: stop save and show error message.
        } elseif ($slug === '') {
            $error = 'Slug artikel tidak valid. Coba isi judul/slug dengan huruf atau angka.';
        } elseif (is_duplicate_slug($conn, 'articles', $slug, $id)) {
            $error = 'Slug artikel sudah dipakai. Gunakan slug lain yang unik.';
        } elseif ($title === '' || $image === '' || $excerpt === '') {
            $error = 'Judul, gambar, dan ringkasan artikel wajib diisi.';
        } elseif ($id > 0) {
            $stmt = $conn->prepare(
                "UPDATE articles
                 SET slug = ?, title = ?, image = ?, excerpt = ?, content_html = ?, author_name = ?, category = ?, published_at = ?, sort_order = ?, is_active = ?
                 WHERE id = ?"
            );
            $stmt->bind_param('ssssssssiii', $slug, $title, $image, $excerpt, $contentHtml, $authorName, $category, $publishedAt, $sortOrder, $isActive, $id);
            $stmt->execute();
            $_SESSION['flash_message'] = 'Artikel berhasil diupdate.';
            redirect_admin('articles');
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO articles (slug, title, image, excerpt, content_html, author_name, category, published_at, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('ssssssssii', $slug, $title, $image, $excerpt, $contentHtml, $authorName, $category, $publishedAt, $sortOrder, $isActive);
            $stmt->execute();
            $_SESSION['flash_message'] = 'Artikel berhasil ditambahkan.';
            redirect_admin('articles');
        }
        }

        if ($action === 'delete_article') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT image FROM articles WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $path = (string) ($row['image'] ?? '');
                delete_uploaded_files_if_unreferenced(
                    $conn,
                    [$path],
                    '/assets/images/uploads/articles/',
                    'articles',
                    'image',
                    [$id],
                    'id'
                );
            }
            $deleteStmt = $conn->prepare("DELETE FROM articles WHERE id = ?");
            $deleteStmt->bind_param('i', $id);
            $deleteStmt->execute();
            $_SESSION['flash_message'] = 'Artikel berhasil dihapus.';
        }
        redirect_admin('articles');
        }

        if ($action === 'bulk_delete_articles') {
        $ids = normalize_bulk_int_ids($_POST['ids'] ?? []);
        if (!$ids) {
            $_SESSION['flash_message'] = 'Tidak ada artikel yang dipilih.';
            redirect_admin('articles');
        }
        $rows = select_rows_by_ids($conn, "SELECT image FROM articles WHERE id IN", $ids);
        $paths = array_map(static fn(array $row): string => (string) ($row['image'] ?? ''), $rows);
        delete_uploaded_files_if_unreferenced(
            $conn,
            $paths,
            '/assets/images/uploads/articles/',
            'articles',
            'image',
            $ids,
            'id'
        );
        delete_table_rows_by_ids($conn, 'articles', $ids);
        $_SESSION['flash_message'] = 'Artikel terpilih berhasil dihapus (' . count($ids) . ').';
        redirect_admin('articles');
    }

        if ($action === 'save_site_settings') {
            $socialFacebook = trim((string) ($_POST['social_facebook'] ?? ''));
            $socialTwitter = trim((string) ($_POST['social_twitter'] ?? ''));
            $socialInstagram = trim((string) ($_POST['social_instagram'] ?? ''));
            $socialYoutube = trim((string) ($_POST['social_youtube'] ?? ''));
            $socialLinkedin = trim((string) ($_POST['social_linkedin'] ?? ''));
            $socialWhatsapp = trim((string) ($_POST['social_whatsapp'] ?? ''));
            $contactWhatsappNumber = normalize_whatsapp_number((string) ($_POST['contact_whatsapp_number'] ?? ''));
            $footerPhonePrimary = trim((string) ($_POST['footer_phone_primary'] ?? ''));
            $footerPhoneSecondary = trim((string) ($_POST['footer_phone_secondary'] ?? ''));
            $footerOfficeHours1 = trim((string) ($_POST['footer_office_hours_1'] ?? ''));
            $footerOfficeHours2 = trim((string) ($_POST['footer_office_hours_2'] ?? ''));
            $footerSupportEmailPrimary = trim((string) ($_POST['footer_support_email_primary'] ?? ''));
            $footerSupportEmailSecondary = trim((string) ($_POST['footer_support_email_secondary'] ?? ''));
            $footerAddress1 = trim((string) ($_POST['footer_address_1'] ?? ''));
            $footerAddress2 = trim((string) ($_POST['footer_address_2'] ?? ''));
            $headerPhonePrimary = trim((string) ($_POST['header_phone_primary'] ?? ''));
            $headerPhoneSecondary = trim((string) ($_POST['header_phone_secondary'] ?? ''));
            $headerEmailPrimary = trim((string) ($_POST['header_email_primary'] ?? ''));
            $homeShowTeamSection = isset($_POST['home_show_team_section']) ? '1' : '0';
            $showMenuLayanan = isset($_POST['show_menu_layanan']) ? '1' : '0';
            $showMenuProduk = isset($_POST['show_menu_produk']) ? '1' : '0';
            $showMenuProyek = isset($_POST['show_menu_proyek']) ? '1' : '0';
            $showMenuArtikel = isset($_POST['show_menu_artikel']) ? '1' : '0';
            $showMenuKontak = isset($_POST['show_menu_kontak']) ? '1' : '0';
            $showMenuTentang = isset($_POST['show_menu_tentang']) ? '1' : '0';
            $contactSectionPretitle = trim((string) ($_POST['contact_section_pretitle'] ?? ''));
            $contactSectionTitle = trim((string) ($_POST['contact_section_title'] ?? ''));
            $contactSectionDescription = trim((string) ($_POST['contact_section_description'] ?? ''));
            $contactCardCallTitle = trim((string) ($_POST['contact_card_call_title'] ?? ''));
            $contactCardOfficeTitle = trim((string) ($_POST['contact_card_office_title'] ?? ''));
            $companyProfilePdfUrl = trim((string) ($_POST['company_profile_pdf_url'] ?? ''));
            $removeCompanyProfilePdf = ((string) ($_POST['remove_company_profile_pdf'] ?? '0')) === '1';
            $mapEmbedUrl = normalize_map_embed_input((string) ($_POST['map_embed_url'] ?? ''));
            $mapLat = trim((string) ($_POST['map_lat'] ?? ''));
            $mapLng = trim((string) ($_POST['map_lng'] ?? ''));
            $mapZoom = trim((string) ($_POST['map_zoom'] ?? ''));
            $uploadError = null;
            $uploadedCompanyProfilePdf = upload_pdf_file($_FILES['company_profile_pdf_file'] ?? [], $uploadError, 'settings');
            if ($uploadError !== null) {
                throw new RuntimeException($uploadError);
            }

            if ($companyProfilePdfUrl !== '') {
                $isHttpUrl = (bool) preg_match('#^https?://#i', $companyProfilePdfUrl);
                $isRootRelative = str_starts_with($companyProfilePdfUrl, '/');
                if (!$isHttpUrl && !$isRootRelative) {
                    throw new RuntimeException('URL Company Profile harus diawali http://, https://, atau /');
                }
                $pdfPath = (string) parse_url($companyProfilePdfUrl, PHP_URL_PATH);
                if ($pdfPath === '' || !preg_match('/\.pdf$/i', $pdfPath)) {
                    throw new RuntimeException('URL Company Profile harus mengarah ke file PDF (.pdf).');
                }
            }
            if ($uploadedCompanyProfilePdf !== null) {
                $oldCompanyProfilePdfUrl = get_site_setting($conn, 'company_profile_pdf_url', '');
                if (str_starts_with($oldCompanyProfilePdfUrl, '/assets/files/uploads/settings/')) {
                    $oldAbsPath = __DIR__ . '/..' . $oldCompanyProfilePdfUrl;
                    if (is_file($oldAbsPath)) {
                        @unlink($oldAbsPath);
                    }
                }
                $companyProfilePdfUrl = $uploadedCompanyProfilePdf;
            } elseif ($removeCompanyProfilePdf) {
                $oldCompanyProfilePdfUrl = get_site_setting($conn, 'company_profile_pdf_url', '');
                if (str_starts_with($oldCompanyProfilePdfUrl, '/assets/files/uploads/settings/')) {
                    $oldAbsPath = __DIR__ . '/..' . $oldCompanyProfilePdfUrl;
                    if (is_file($oldAbsPath)) {
                        @unlink($oldAbsPath);
                    }
                }
                $companyProfilePdfUrl = '';
            }

            save_site_setting($conn, 'social_facebook', $socialFacebook);
            save_site_setting($conn, 'social_twitter', $socialTwitter);
            save_site_setting($conn, 'social_instagram', $socialInstagram);
            save_site_setting($conn, 'social_youtube', $socialYoutube);
            save_site_setting($conn, 'social_linkedin', $socialLinkedin);
            save_site_setting($conn, 'social_whatsapp', $socialWhatsapp);
            save_site_setting($conn, 'contact_whatsapp_number', $contactWhatsappNumber);
            save_site_setting($conn, 'footer_phone_primary', $footerPhonePrimary);
            save_site_setting($conn, 'footer_phone_secondary', $footerPhoneSecondary);
            save_site_setting($conn, 'footer_office_hours_1', $footerOfficeHours1);
            save_site_setting($conn, 'footer_office_hours_2', $footerOfficeHours2);
            save_site_setting($conn, 'footer_support_email_primary', $footerSupportEmailPrimary);
            save_site_setting($conn, 'footer_support_email_secondary', $footerSupportEmailSecondary);
            save_site_setting($conn, 'footer_address_1', $footerAddress1);
            save_site_setting($conn, 'footer_address_2', $footerAddress2);
            save_site_setting($conn, 'header_phone_primary', $headerPhonePrimary);
            save_site_setting($conn, 'header_phone_secondary', $headerPhoneSecondary);
            save_site_setting($conn, 'header_email_primary', $headerEmailPrimary);
            save_site_setting($conn, 'home_show_team_section', $homeShowTeamSection);
            save_site_setting($conn, 'show_menu_layanan', $showMenuLayanan);
            save_site_setting($conn, 'show_menu_produk', $showMenuProduk);
            save_site_setting($conn, 'show_menu_proyek', $showMenuProyek);
            save_site_setting($conn, 'show_menu_artikel', $showMenuArtikel);
            save_site_setting($conn, 'show_menu_kontak', $showMenuKontak);
            save_site_setting($conn, 'show_menu_tentang', $showMenuTentang);
            save_site_setting($conn, 'contact_section_pretitle', $contactSectionPretitle);
            save_site_setting($conn, 'contact_section_title', $contactSectionTitle);
            save_site_setting($conn, 'contact_section_description', $contactSectionDescription);
            save_site_setting($conn, 'contact_card_call_title', $contactCardCallTitle);
            save_site_setting($conn, 'contact_card_office_title', $contactCardOfficeTitle);
            save_site_setting($conn, 'company_profile_pdf_url', $companyProfilePdfUrl);
            save_site_setting($conn, 'map_embed_url', $mapEmbedUrl);
            save_site_setting($conn, 'map_lat', $mapLat);
            save_site_setting($conn, 'map_lng', $mapLng);
            save_site_setting($conn, 'map_zoom', $mapZoom);

            $_SESSION['flash_message'] = 'Pengaturan website berhasil disimpan.';
            redirect_admin('settings');
        }
    } catch (Throwable $e) {
        $error = 'Gagal simpan data: ' . $e->getMessage();
    }
}

$message = (string) ($_SESSION['flash_message'] ?? '');
unset($_SESSION['flash_message']);

$editId = (int) ($_GET['edit'] ?? 0);
$editProduct = null;
$editProductGallery = [];
if ($activePage === 'products' && $editId > 0) {
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editProduct = $stmt->get_result()->fetch_assoc();
    $galleryStmt = $conn->prepare("SELECT id, image_path FROM product_images WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
    $galleryStmt->bind_param('i', $editId);
    $galleryStmt->execute();
    $editProductGallery = $galleryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$editTeam = null;
if ($activePage === 'team' && $editId > 0) {
    $stmt = $conn->prepare("SELECT * FROM team_members WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editTeam = $stmt->get_result()->fetch_assoc();
}
$editTestimonial = null;
if ($activePage === 'testimonials' && $editId > 0) {
    $stmt = $conn->prepare("SELECT * FROM testimonials WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editTestimonial = $stmt->get_result()->fetch_assoc();
}
$editService = null;
$editServiceGallery = [];
if ($activePage === 'services' && $editId > 0) {
    $stmt = $conn->prepare("SELECT * FROM services WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editService = $stmt->get_result()->fetch_assoc();
    $galleryStmt = $conn->prepare("SELECT id, image_path FROM service_images WHERE service_id = ? ORDER BY sort_order ASC, id ASC");
    $galleryStmt->bind_param('i', $editId);
    $galleryStmt->execute();
    $editServiceGallery = $galleryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$editProject = null;
$editProjectGallery = [];
if ($activePage === 'projects' && $editId > 0) {
    $stmt = $conn->prepare("SELECT * FROM projects WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editProject = $stmt->get_result()->fetch_assoc();
    $galleryStmt = $conn->prepare("SELECT id, image_path FROM project_images WHERE project_id = ? ORDER BY sort_order ASC, id ASC");
    $galleryStmt->bind_param('i', $editId);
    $galleryStmt->execute();
    $editProjectGallery = $galleryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$editArticle = null;
if ($activePage === 'articles' && $editId > 0) {
    $stmt = $conn->prepare("SELECT * FROM articles WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editArticle = $stmt->get_result()->fetch_assoc();
}

$products = $conn->query(
    "SELECT p.id, p.slug, p.name, p.category, p.product_badge, p.price, p.image, p.short_description, p.use_case_tags_json,
            (SELECT COUNT(*) FROM product_images pi WHERE pi.product_id = p.id) AS gallery_count
     FROM products p
     ORDER BY p.id DESC"
)->fetch_all(MYSQLI_ASSOC);
$reviews = $conn->query(
    "SELECT
        r.id,
        r.reviewer_name,
        r.review_text,
        r.rating,
        r.is_approved,
        r.created_at,
        p.name AS item_name,
        p.name AS product_name,
        'product' AS review_type,
        'Produk' AS type_label
     FROM reviews r
     JOIN products p ON p.id = r.product_id
     UNION ALL
     SELECT
        sr.id,
        sr.reviewer_name,
        sr.review_text,
        sr.rating,
        sr.is_approved,
        sr.created_at,
        s.name AS item_name,
        s.name AS product_name,
        'service' AS review_type,
        'Layanan' AS type_label
     FROM service_reviews sr
     JOIN services s ON s.id = sr.service_id
     ORDER BY created_at DESC, id DESC"
)->fetch_all(MYSQLI_ASSOC);
$teamMembers = $conn->query(
    "SELECT id, name, role, image, social_facebook, social_linkedin, social_youtube, social_whatsapp, sort_order, is_active
     FROM team_members
     ORDER BY sort_order ASC, id DESC"
)->fetch_all(MYSQLI_ASSOC);
$testimonials = $conn->query(
    "SELECT id, name, company, quote_text, avatar_image, brand_logo, sort_order, is_active
     FROM testimonials
     ORDER BY sort_order ASC, id DESC"
)->fetch_all(MYSQLI_ASSOC);
$services = $conn->query(
    "SELECT id, slug, name, card_highlight, image, short_description, description, features_json, detail_url, video_url, sort_order, is_active,
            (SELECT COUNT(*) FROM service_images si WHERE si.service_id = services.id) AS gallery_count
     FROM services
     ORDER BY sort_order ASC, id DESC"
)->fetch_all(MYSQLI_ASSOC);
$projects = $conn->query(
    "SELECT id, slug, title, image, category, short_description, description, features_json, detail_url, video_url, sort_order, is_active,
            (SELECT COUNT(*) FROM project_images pi WHERE pi.project_id = projects.id) AS gallery_count
     FROM projects
     ORDER BY sort_order ASC, id DESC"
)->fetch_all(MYSQLI_ASSOC);
$articles = $conn->query(
    "SELECT id, slug, title, image, excerpt, author_name, category, published_at, sort_order, is_active
     FROM articles
     ORDER BY sort_order ASC, published_at DESC, id DESC"
)->fetch_all(MYSQLI_ASSOC);
$siteSettingsRows = $conn->query(
    "SELECT setting_key, setting_value
     FROM site_settings
     WHERE setting_key IN (
        'social_facebook',
        'social_twitter',
        'social_instagram',
        'social_youtube',
        'social_linkedin',
        'social_whatsapp',
        'contact_whatsapp_number',
        'footer_phone_primary',
        'footer_phone_secondary',
        'footer_office_hours_1',
        'footer_office_hours_2',
        'footer_support_email_primary',
        'footer_support_email_secondary',
        'footer_address_1',
        'footer_address_2',
        'header_phone_primary',
        'header_phone_secondary',
        'header_email_primary',
        'home_show_team_section',
        'show_menu_layanan',
        'show_menu_produk',
        'show_menu_proyek',
        'show_menu_artikel',
        'show_menu_kontak',
        'show_menu_tentang',
        'contact_section_pretitle',
        'contact_section_title',
        'contact_section_description',
        'contact_card_call_title',
        'contact_card_office_title',
        'company_profile_pdf_url',
        'map_embed_url',
        'map_lat',
        'map_lng',
        'map_zoom'
     )"
)->fetch_all(MYSQLI_ASSOC);
$siteSettings = [
    'social_facebook' => '',
    'social_twitter' => '',
    'social_instagram' => '',
    'social_youtube' => '',
    'social_linkedin' => '',
    'social_whatsapp' => '',
    'contact_whatsapp_number' => '',
    'footer_phone_primary' => '',
    'footer_phone_secondary' => '',
    'footer_office_hours_1' => '',
    'footer_office_hours_2' => '',
    'footer_support_email_primary' => '',
    'footer_support_email_secondary' => '',
    'footer_address_1' => '',
    'footer_address_2' => '',
    'header_phone_primary' => '',
    'header_phone_secondary' => '',
    'header_email_primary' => '',
    'home_show_team_section' => '1',
    'show_menu_layanan' => '1',
    'show_menu_produk' => '1',
    'show_menu_proyek' => '1',
    'show_menu_artikel' => '1',
    'show_menu_kontak' => '1',
    'show_menu_tentang' => '1',
    'contact_section_pretitle' => '',
    'contact_section_title' => '',
    'contact_section_description' => '',
    'contact_card_call_title' => '',
    'contact_card_office_title' => '',
    'company_profile_pdf_url' => '',
    'map_embed_url' => '',
    'map_lat' => '',
    'map_lng' => '',
    'map_zoom' => '',
];
foreach ($siteSettingsRows as $siteSettingRow) {
    $key = (string) ($siteSettingRow['setting_key'] ?? '');
    if (!array_key_exists($key, $siteSettings)) {
        continue;
    }
    $siteSettings[$key] = (string) ($siteSettingRow['setting_value'] ?? '');
}

$totalProducts = count($products);
$totalReviews = count($reviews);
$pendingReviews = count(array_filter($reviews, static fn(array $row): bool => (int) $row['is_approved'] === 0));
$approvedReviews = $totalReviews - $pendingReviews;
$activeTeamMembers = count(array_filter($teamMembers, static fn(array $row): bool => (int) $row['is_active'] === 1));
$activeTestimonials = count(array_filter($testimonials, static fn(array $row): bool => (int) $row['is_active'] === 1));
$pageLabels = [
    'dashboard' => 'Ringkasan Dashboard',
    'products' => 'Kelola Produk',
    'services' => 'Kelola Layanan',
    'projects' => 'Kelola Proyek',
    'articles' => 'Kelola Artikel',
    'testimonials' => 'Kelola Testimonial',
    'reviews' => 'Moderasi Review',
    'team' => 'Kelola Tim',
    'settings' => 'Pengaturan Sistem',
];
$currentPageLabel = $pageLabels[$activePage] ?? 'Admin Panel';

$dashboardProductsPager = paginate_rows($products, 5, 'dash_products_pg');
$dashboardReviewsPager = paginate_rows($reviews, 6, 'dash_reviews_pg');
$productsPager = paginate_rows($products, 8, 'products_pg');
$servicesPager = paginate_rows($services, 8, 'services_pg');
$projectsPager = paginate_rows($projects, 8, 'projects_pg');
$articlesPager = paginate_rows($articles, 8, 'articles_pg');
$testimonialsPager = paginate_rows($testimonials, 8, 'testimonials_pg');
$reviewsPager = paginate_rows($reviews, 10, 'reviews_pg');
$teamPager = paginate_rows($teamMembers, 8, 'team_pg');
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MPS - PT. Maulana Prima Sejahtera</title>
  <style>
    :root {
      --bg: #f3f6fb;
      --surface: #ffffff;
      --line: #e2e8f0;
      --text: #0f172a;
      --muted: #64748b;
      --brand: #ff5e14;
      --dark: #1e293b;
      --danger: #b91c1c;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", Arial, sans-serif;
      color: var(--text);
      background: radial-gradient(circle at 0 0, #fff2ea 0%, var(--bg) 30%);
    }
    .shell {
      display: grid;
      grid-template-columns: 250px 1fr;
      min-height: 100vh;
    }
    .sidebar {
      background: linear-gradient(170deg, #0f172a, #1e293b);
      color: #fff;
      padding: 22px 14px;
      border-right: 1px solid #273449;
      position: sticky;
      top: 0;
      height: 100vh;
    }
    .brand { font-size: 18px; font-weight: 800; margin-bottom: 4px; }
    .brand-sub { font-size: 12px; color: #cbd5e1; margin-bottom: 20px; }
    .menu { display: grid; gap: 6px; }
    .menu a {
      text-decoration: none;
      color: #e2e8f0;
      padding: 10px 12px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 14px;
      border: 1px solid transparent;
    }
    .menu a:hover { background: rgba(255, 255, 255, 0.08); }
    .menu a.active {
      background: rgba(255, 94, 20, 0.18);
      border-color: rgba(255, 94, 20, 0.4);
      color: #fff;
    }
    .logout {
      margin-top: 18px;
      display: inline-flex;
      text-decoration: none;
      border: 1px solid #475569;
      color: #e2e8f0;
      padding: 9px 12px;
      border-radius: 9px;
      font-weight: 600;
      font-size: 13px;
    }
    .main { padding: 18px; }
    .header {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 14px 16px;
      margin-bottom: 14px;
    }
    .header h1 { margin: 0; font-size: 20px; }
    .header p { margin: 5px 0 0; color: var(--muted); font-size: 13px; }
    .stats {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      margin-bottom: 14px;
    }
    .stat {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 12px 13px;
    }
    .stat .label { color: var(--muted); font-size: 12px; margin-bottom: 4px; }
    .stat .value { font-size: 26px; font-weight: 800; line-height: 1; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .stack { display: grid; gap: 14px; }
    .card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 14px;
    }
    .card h2 { margin: 0 0 12px; font-size: 18px; }
    .muted { color: var(--muted); font-size: 13px; margin: -4px 0 10px; }
    .alert {
      padding: 10px 12px;
      border-radius: 10px;
      margin-bottom: 12px;
      font-weight: 600;
      border: 1px solid transparent;
    }
    .ok { background: #dcfce7; color: #166534; border-color: #86efac; }
    .err { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    label { display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 5px; }
    input, textarea, select {
      width: 100%;
      padding: 10px 11px;
      border: 1px solid #cbd5e1;
      border-radius: 10px;
      margin-bottom: 10px;
      outline: none;
      font-size: 14px;
      background: #fff;
    }
    select {
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
      padding-right: 38px;
      background-image:
        linear-gradient(45deg, transparent 50%, #64748b 50%),
        linear-gradient(135deg, #64748b 50%, transparent 50%);
      background-position:
        calc(100% - 18px) calc(50% - 3px),
        calc(100% - 12px) calc(50% - 3px);
      background-size: 6px 6px, 6px 6px;
      background-repeat: no-repeat;
    }
    input:focus, textarea:focus, select:focus {
      border-color: #fdba92;
      box-shadow: 0 0 0 3px rgba(255, 94, 20, .12);
    }
    textarea { min-height: 96px; resize: vertical; }
    .btn {
      border: 0;
      border-radius: 10px;
      font-weight: 700;
      padding: 9px 12px;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .btn.brand { background: var(--brand); color: #fff; }
    .btn.dark { background: var(--dark); color: #fff; }
    .btn.danger { background: var(--danger); color: #fff; }
    .actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .bulk-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 8px 0 10px; }
    .bulk-count { font-size: 12px; color: var(--muted); }
    .bulk-col { width: 40px; text-align: center; }
    .bulk-check { width: 16px; height: 16px; accent-color: var(--brand); cursor: pointer; }
    .header-actions { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px; }
    .field-help { color: var(--muted); font-size: 12px; margin-top: -6px; margin-bottom: 10px; }
    .text-tools {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
      margin: 6px 0 10px;
    }
    .text-select {
      height: 28px;
      border-radius: 999px;
      padding: 0 10px;
      border: 1px solid #cbd5e1;
      background: #fff;
      font-size: 12px;
      font-weight: 800;
      color: #0f172a;
      cursor: pointer;
    }
    .text-select:focus {
      outline: 0;
      box-shadow: 0 0 0 3px rgba(255, 94, 20, .14);
      border-color: rgba(255, 94, 20, 0.5);
    }
    .btn.ghost {
      background: #fff;
      border: 1px solid #cbd5e1;
      color: #0f172a;
      padding: 6px 10px;
      border-radius: 999px;
      font-weight: 800;
      font-size: 12px;
      line-height: 1;
    }
    .btn.ghost:hover {
      border-color: #ff5e14;
      color: #ff5e14;
      background: #fff7f2;
    }
    .text-tools .hint {
      font-size: 12px;
      color: var(--muted);
    }
    .ui-modal__desc .preview {
      margin-top: 4px;
      max-height: 52vh;
      overflow: auto;
      padding-right: 6px;
    }
    .ui-modal__desc .preview p { margin: 0 0 12px; }
    .ui-modal__desc .preview p:last-child { margin-bottom: 0; }
    .ui-modal__desc .preview ul {
      list-style: none;
      padding: 0;
      margin: 0 0 12px;
      display: grid;
      gap: 4px;
    }
    .ui-modal__desc .preview h2 {
      margin: 0 0 10px;
      font-size: 20px;
      font-weight: 900;
      color: #0f172a;
      line-height: 1.25;
    }
    .ui-modal__desc .preview h3 {
      margin: 0 0 8px;
      font-size: 16px;
      font-weight: 900;
      color: #0f172a;
      line-height: 1.25;
    }
    .ui-modal__desc .preview .t-sm { font-size: 13px; line-height: 1.7; }
    .ui-modal__desc .preview .t-lg { font-size: 18px; line-height: 1.7; }
    .ui-modal__desc .preview .t-sz-12 { font-size: 12px; line-height: 1.7; }
    .ui-modal__desc .preview .t-sz-14 { font-size: 14px; line-height: 1.7; }
    .ui-modal__desc .preview .t-sz-16 { font-size: 16px; line-height: 1.7; }
    .ui-modal__desc .preview .t-sz-18 { font-size: 18px; line-height: 1.7; }
    .ui-modal__desc .preview .t-sz-20 { font-size: 20px; line-height: 1.6; }
    .ui-modal__desc .preview .t-sz-24 { font-size: 24px; line-height: 1.35; }
    .ui-modal__desc .preview .t-sz-28 { font-size: 28px; line-height: 1.25; }
    .ui-modal__desc .preview .t-sz-32 { font-size: 32px; line-height: 1.2; }
    .ui-modal__desc .preview .t-sz-36 { font-size: 36px; line-height: 1.15; }
    .ui-modal__desc .preview li {
      position: relative;
      padding-left: 18px;
      color: #0f2447;
      line-height: 1.7;
    }
    .ui-modal__desc .preview li::before {
      content: '';
      position: absolute;
      left: 0;
      top: 0.62em;
      width: 8px;
      height: 8px;
      border-radius: 999px;
      background: #ff5e14;
      box-shadow: 0 0 0 2px rgba(255, 94, 20, 0.12);
    }

    .ui-modal {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 18px;
      z-index: 1000;
    }
    .ui-modal[data-open="1"] { display: flex; }
    .ui-modal__backdrop {
      position: absolute;
      inset: 0;
      background: rgba(15, 23, 42, 0.62);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      animation: uiFadeIn .18s ease-out;
    }
    .ui-modal__panel {
      position: relative;
      width: min(520px, 100%);
      border-radius: 16px;
      border: 1px solid rgba(148, 163, 184, 0.35);
      background:
        radial-gradient(circle at 0 0, rgba(255, 94, 20, 0.18), transparent 48%),
        radial-gradient(circle at 100% 0, rgba(14, 165, 233, 0.14), transparent 52%),
        linear-gradient(180deg, rgba(255,255,255,0.96), rgba(248, 251, 255, 0.94));
      box-shadow:
        0 28px 80px rgba(15, 23, 42, 0.35),
        0 10px 24px rgba(15, 23, 42, 0.18);
      transform: translateY(0) scale(1);
      animation: uiPop .22s cubic-bezier(.2,.9,.2,1);
      overflow: hidden;
    }
    .ui-modal__top {
      display: flex;
      gap: 12px;
      padding: 16px 16px 10px;
      align-items: flex-start;
    }
    .ui-modal__icon {
      flex: 0 0 auto;
      width: 40px;
      height: 40px;
      border-radius: 12px;
      display: grid;
      place-items: center;
      background: rgba(255, 94, 20, 0.12);
      color: var(--brand);
      border: 1px solid rgba(255, 94, 20, 0.22);
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.85);
      font-weight: 900;
      letter-spacing: -0.02em;
    }
    .ui-modal__title {
      margin: 0;
      font-size: 16px;
      font-weight: 900;
      color: #0f172a;
      line-height: 1.25;
    }
    .ui-modal__desc {
      margin: 6px 0 0;
      font-size: 13px;
      color: #475569;
      line-height: 1.45;
      word-break: break-word;
    }
    .ui-modal__divider {
      height: 1px;
      background: linear-gradient(90deg, transparent, rgba(148,163,184,0.55), transparent);
    }
    .ui-modal__actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      padding: 12px 16px 16px;
    }
    .ui-modal__btn {
      border-radius: 12px;
      padding: 10px 12px;
      border: 1px solid rgba(148, 163, 184, 0.6);
      background: rgba(255, 255, 255, 0.88);
      color: #0f172a;
      font-weight: 800;
      cursor: pointer;
      min-width: 110px;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
    }
    .ui-modal__btn:hover { filter: brightness(0.98); }
    .ui-modal__btn:focus { outline: 0; box-shadow: 0 0 0 3px rgba(255, 94, 20, .16); border-color: rgba(255, 94, 20, 0.5); }
    .ui-modal__btn.primary {
      border-color: rgba(255, 94, 20, 0.55);
      background: linear-gradient(180deg, #ff7a3a, var(--brand));
      color: #fff;
      box-shadow:
        0 10px 22px rgba(255, 94, 20, 0.22),
        inset 0 1px 0 rgba(255, 255, 255, 0.22);
    }
    .ui-modal__btn.danger {
      border-color: rgba(220, 38, 38, 0.55);
      background: linear-gradient(180deg, #ef4444, #b91c1c);
      color: #fff;
      box-shadow:
        0 10px 22px rgba(185, 28, 28, 0.18),
        inset 0 1px 0 rgba(255, 255, 255, 0.18);
    }

    @keyframes uiFadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes uiPop { from { opacity: 0; transform: translateY(16px) scale(.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
    .settings-form {
      display: grid;
      gap: 14px;
    }
    .settings-form > h2 {
      margin: 2px 0 0;
      font-size: 17px;
      line-height: 1.2;
      color: #0f172a;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding-left: 10px;
      border-left: 3px solid #ff5e14;
    }
    .settings-form > .muted {
      margin: -6px 0 0;
      font-size: 12px;
      color: #64748b;
      padding-left: 12px;
    }
    .settings-form > .form-grid {
      padding: 12px;
      border: 1px solid #dbe7f5;
      border-radius: 12px;
      background:
        radial-gradient(circle at 0 0, rgba(255, 94, 20, 0.06), transparent 45%),
        linear-gradient(180deg, #ffffff, #f8fbff);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
      gap: 12px;
    }
    .settings-form .form-grid > div {
      border: 1px solid #d6e1ef;
      border-radius: 10px;
      background: #ffffff;
      padding: 10px 10px 8px;
      transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
    }
    .settings-form .form-grid > div:focus-within {
      border-color: #fdba92;
      box-shadow: 0 0 0 3px rgba(255, 94, 20, .12);
      transform: translateY(-1px);
    }
    .settings-form .form-grid > .span-2 {
      grid-column: 1 / -1;
    }
    .settings-form .toggle-field {
      background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
      border-color: #d2dfef;
    }
    .settings-form .toggle-field label {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      font-size: 13px;
      letter-spacing: .01em;
      line-height: 1.45;
      text-transform: none;
    }
    .settings-form .toggle-field input[type="checkbox"] {
      width: 18px;
      height: 18px;
      margin: 2px 0 0;
      accent-color: #ff5e14;
      flex: 0 0 18px;
    }
    .settings-form .toggle-field .field-help {
      margin-top: 8px;
    }
    .settings-form label {
      color: #0f172a;
      letter-spacing: .03em;
    }
    .settings-form input,
    .settings-form textarea,
    .settings-form select {
      margin-bottom: 6px;
      border-color: #cbd8ea;
      background: #fdfefe;
    }
    .settings-form .field-help {
      margin: 0;
      font-size: 11px;
      line-height: 1.5;
    }
    .settings-form .active-file-note {
      margin-top: 2px;
      margin-bottom: 6px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      max-width: 100%;
      padding: 8px 10px;
      border: 1px solid #cfe0f5;
      border-radius: 9px;
      background: linear-gradient(180deg, #f8fbff 0%, #eef5ff 100%);
      color: #0f172a;
      font-size: 12px;
      line-height: 1.45;
    }
    .settings-form .active-file-note::before {
      content: "PDF";
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 30px;
      height: 20px;
      padding: 0 6px;
      border-radius: 6px;
      background: #0f172a;
      color: #ffffff;
      font-size: 10px;
      font-weight: 800;
      letter-spacing: .04em;
    }
    .settings-form .active-file-note a {
      color: #0b4fb5;
      font-weight: 700;
      text-decoration: none;
      border-bottom: 1px dashed #0b4fb5;
    }
    .settings-form .active-file-note a:hover {
      color: #0a3f91;
      border-bottom-color: #0a3f91;
    }
    .settings-form .upload-pdf-widget {
      border: 1px dashed #c8d8ef;
      border-radius: 10px;
      background: linear-gradient(180deg, #f9fcff 0%, #f2f8ff 100%);
      padding: 10px;
      display: grid;
      gap: 8px;
    }
    .settings-form .upload-file-widget {
      border: 1px dashed #c8d8ef;
      border-radius: 10px;
      background: linear-gradient(180deg, #f9fcff 0%, #f2f8ff 100%);
      padding: 10px;
      display: grid;
      gap: 8px;
      margin-bottom: 8px;
    }
    .settings-form .sr-only-upload {
      position: absolute !important;
      width: 1px !important;
      height: 1px !important;
      padding: 0 !important;
      margin: -1px !important;
      overflow: hidden !important;
      clip: rect(0, 0, 0, 0) !important;
      white-space: nowrap !important;
      border: 0 !important;
    }
    .settings-form .upload-pdf-trigger {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-height: 40px;
      padding: 9px 12px;
      border-radius: 8px;
      border: 1px solid #0f172a;
      background: #0f172a;
      color: #ffffff;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: .02em;
      cursor: pointer;
      width: fit-content;
    }
    .settings-form .upload-file-trigger {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-height: 40px;
      padding: 9px 12px;
      border-radius: 8px;
      border: 1px solid #0f172a;
      background: #0f172a;
      color: #ffffff;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: .02em;
      text-transform: none;
      cursor: pointer;
      width: fit-content;
    }
    .settings-form .upload-pdf-trigger:hover {
      background: #1e293b;
      border-color: #1e293b;
    }
    .settings-form .upload-file-trigger:hover {
      background: #1e293b;
      border-color: #1e293b;
      color: #ffffff;
    }
    .settings-form .upload-file-name {
      display: block;
      font-size: 12px;
      color: #334155;
      background: #ffffff;
      border: 1px solid #d7e3f3;
      border-radius: 8px;
      padding: 8px 10px;
      word-break: break-word;
    }
    .settings-form .btn.brand {
      margin-top: 4px;
      min-height: 44px;
      padding-inline: 16px;
      border-radius: 10px;
      box-shadow: 0 10px 22px rgba(255, 94, 20, .22);
    }
    .settings-form.settings-modern {
      gap: 16px;
      padding: 2px;
    }
    .settings-form.settings-modern > h2 {
      margin-top: 6px;
    }
    .settings-form.settings-modern > h2:first-of-type {
      margin-top: 0;
    }
    .settings-form .section-title {
      margin-top: 8px;
    }
    .settings-form.settings-modern > .form-grid {
      padding: 14px;
      border-radius: 14px;
      box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
    }
    .settings-form.settings-modern .form-grid > div {
      border-radius: 12px;
      padding: 12px 12px 10px;
      min-height: 84px;
    }
    .settings-form.settings-modern .toggle-field {
      padding: 12px 12px 10px;
      min-height: 0;
    }
    .settings-form.settings-modern .toggle-field label {
      font-size: 13px;
      font-weight: 700;
    }
    .settings-form.settings-modern .field-help {
      font-size: 11px;
      margin-top: 4px;
    }
    .settings-form.settings-modern label {
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: #334155;
    }
    .settings-form.settings-modern input,
    .settings-form.settings-modern textarea,
    .settings-form.settings-modern select {
      min-height: 42px;
      border-radius: 10px;
      padding: 10px 12px;
    }
    .settings-form.settings-modern .upload-pdf-trigger,
    .settings-form.settings-modern .upload-pdf-trigger:hover,
    .settings-form.settings-modern .upload-file-trigger,
    .settings-form.settings-modern .upload-file-trigger:hover {
      color: #ffffff;
      text-transform: none;
    }
    .price-preview {
      font-size: 12px;
      color: var(--muted);
      margin-top: -6px;
      margin-bottom: 10px;
    }
    .gallery-preview {
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 10px;
      background: #f8fafc;
      margin-bottom: 12px;
      overflow: hidden;
    }
    .gallery-grid {
      display: flex;
      flex-wrap: nowrap;
      gap: 10px;
      width: 100%;
      max-width: 100%;
      min-width: 0;
      overflow-x: auto;
      overflow-y: hidden;
      scroll-snap-type: x proximity;
      scroll-behavior: smooth;
      padding-bottom: 4px;
    }
    .gallery-grid::-webkit-scrollbar {
      height: 8px;
    }
    .gallery-grid::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 999px;
    }
    .gallery-grid::-webkit-scrollbar-track {
      background: #e2e8f0;
      border-radius: 999px;
    }
    .gallery-item {
      flex: 0 0 calc((100% - 30px) / 4);
      min-width: calc((100% - 30px) / 4);
      scroll-snap-align: start;
      border: 1px solid #dbe3ed;
      border-radius: 10px;
      overflow: hidden;
      background: #fff;
    }
    @media (max-width: 1200px) {
      .gallery-item {
        flex-basis: calc((100% - 20px) / 3);
        min-width: calc((100% - 20px) / 3);
      }
    }
    @media (max-width: 820px) {
      .gallery-item {
        flex-basis: calc((100% - 10px) / 2);
        min-width: calc((100% - 10px) / 2);
      }
    }
    @media (max-width: 560px) {
      .gallery-item {
        flex-basis: 100%;
        min-width: 100%;
      }
    }
    .gallery-item img {
      width: 100%;
      aspect-ratio: 4 / 3;
      height: auto;
      object-fit: cover;
      display: block;
    }
    .gallery-item .item-body { padding: 8px; }
    .gallery-item .item-body { min-width: 0; }
    .gallery-item .item-body .muted {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .gallery-item .item-body form { margin-top: 6px; }
    .gallery-item .item-body button { width: 100%; }
    .upload-preview-box {
      border: 1px dashed #cbd5e1;
      border-radius: 10px;
      padding: 10px;
      background: #f8fafc;
      margin-top: 6px;
      margin-bottom: 10px;
    }
    .upload-preview-empty { color: var(--muted); font-size: 12px; }
    .upload-preview-main img {
      width: 120px;
      height: 90px;
      object-fit: cover;
      border-radius: 8px;
      border: 1px solid #dbe3ed;
      display: block;
      margin-bottom: 6px;
    }
    .upload-preview-gallery {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
      gap: 8px;
    }
    .upload-preview-gallery .item img {
      width: 100%;
      height: 78px;
      object-fit: cover;
      border-radius: 8px;
      border: 1px solid #dbe3ed;
      display: block;
      margin-bottom: 4px;
    }
    .upload-preview-gallery .item .remove-file {
      margin-top: 4px;
      width: 100%;
      border: 1px solid #fecaca;
      background: #fff1f2;
      color: #b91c1c;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 700;
      padding: 4px 6px;
      cursor: pointer;
    }
    .upload-preview-name {
      font-size: 11px;
      color: #475569;
      word-break: break-word;
    }
    .table-wrap {
      border: 1px solid var(--line);
      border-radius: 12px;
      overflow: auto;
    }
    table { width: 100%; border-collapse: collapse; min-width: 680px; }
    th, td {
      border-bottom: 1px solid var(--line);
      text-align: left;
      padding: 10px;
      vertical-align: top;
      font-size: 13px;
    }
    th {
      background: #f8fafc;
      color: #334155;
      text-transform: uppercase;
      font-size: 11px;
      letter-spacing: .35px;
    }
    tr:last-child td { border-bottom: 0; }
    .list-item-with-thumb {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      min-width: 0;
    }
    .list-thumb {
      width: 64px;
      height: 64px;
      border-radius: 10px;
      border: 1px solid #dbe3ed;
      object-fit: cover;
      background: #f8fafc;
      flex: 0 0 64px;
      display: block;
    }
    .list-thumb.placeholder {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #94a3b8;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: .03em;
      text-transform: uppercase;
    }
    .list-item-content {
      min-width: 0;
      flex: 1;
    }
    .status { border-radius: 999px; padding: 3px 8px; font-size: 11px; font-weight: 700; display: inline-block; }
    .status.pending { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
    .status.approved { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .kpi-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .small-table table { min-width: 540px; }
    .pager {
      margin-top: 10px;
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
    }
    .pager .pg-btn {
      text-decoration: none;
      border: 1px solid #d5deea;
      background: #fff;
      color: #334155;
      border-radius: 8px;
      min-width: 34px;
      height: 34px;
      padding: 0 10px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      font-weight: 700;
    }
    .pager .pg-btn:hover { border-color: #ff5e14; color: #ff5e14; }
    .pager .pg-btn.active {
      border-color: #ff5e14;
      background: #ff5e14;
      color: #fff;
    }
    .pager .pg-btn.disabled {
      opacity: .45;
      pointer-events: none;
    }
    .pager .pg-dots {
      min-width: 26px;
      height: 34px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #64748b;
      font-weight: 700;
      font-size: 13px;
      user-select: none;
    }
    code { background: #f1f5f9; padding: 1px 5px; border-radius: 6px; }
    @media (max-width: 1040px) {
      .shell { grid-template-columns: 1fr; }
      .sidebar { position: static; height: auto; }
      .stats { grid-template-columns: 1fr 1fr; }
      .grid, .kpi-row { grid-template-columns: 1fr; }
    }
    @media (max-width: 660px) {
      .stats { grid-template-columns: 1fr; }
      .form-grid { grid-template-columns: 1fr; }
      .settings-form > .form-grid {
        padding: 10px;
      }
      .settings-form .form-grid > .span-2 {
        grid-column: auto;
      }
    }

    /* Modern UI refresh */
    :root {
      --bg: #eef2f8;
      --surface: #ffffff;
      --surface-soft: #f8fafd;
      --line: #d9e2ef;
      --line-strong: #c7d2e1;
      --text: #0f172a;
      --muted: #64748b;
      --brand: #ff5e14;
      --brand-dark: #e84f0c;
      --shadow: 0 14px 34px rgba(15, 23, 42, 0.08);
    }
    body {
      background:
        radial-gradient(circle at 0 0, rgba(255, 94, 20, 0.12), transparent 42%),
        radial-gradient(circle at 100% 0, rgba(59, 130, 246, 0.09), transparent 38%),
        linear-gradient(180deg, #f4f7fb 0%, #edf2f8 100%);
    }
    .sidebar {
      box-shadow: inset -1px 0 0 rgba(255, 255, 255, 0.06);
      background:
        radial-gradient(circle at 110% -10%, rgba(255, 94, 20, 0.25), transparent 45%),
        linear-gradient(165deg, #0f172a, #16263f 58%, #1e293b);
    }
    .menu {
      max-height: calc(100vh - 165px);
      overflow: auto;
      padding-right: 2px;
    }
    .menu a {
      border-radius: 12px;
      border-color: rgba(148, 163, 184, 0.18);
      transition: all .2s ease;
    }
    .menu a:hover {
      transform: translateX(2px);
      border-color: rgba(148, 163, 184, 0.35);
    }
    .logout {
      border-radius: 10px;
      border-color: rgba(148, 163, 184, 0.42);
      transition: all .2s ease;
    }
    .logout:hover {
      border-color: #ffb290;
      color: #fff;
    }
    .main { padding: 20px; }
    .header {
      border-radius: 16px;
      border-color: var(--line);
      box-shadow: var(--shadow);
      padding: 16px 18px;
      background:
        radial-gradient(circle at 90% -5%, rgba(255, 94, 20, 0.11), transparent 40%),
        var(--surface);
    }
    .header h1 {
      font-size: clamp(21px, 3vw, 28px);
      line-height: 1.15;
      margin-bottom: 6px;
    }
    .header .top-meta {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 6px;
    }
    .top-meta .chip {
      display: inline-flex;
      align-items: center;
      border: 1px solid #d9e3ef;
      background: #f8fbff;
      color: #334155;
      border-radius: 999px;
      padding: 5px 10px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .01em;
    }
    .stats {
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 10px;
    }
    .stat {
      padding: 12px;
      border-radius: 14px;
      box-shadow: var(--shadow);
      border-color: var(--line);
      background: linear-gradient(180deg, #fff, #f8fbff);
    }
    .stat .label { font-weight: 700; margin-bottom: 6px; }
    .stat .value { font-size: clamp(24px, 2.8vw, 30px); }
    .grid { gap: 12px; }
    .stack { gap: 12px; }
    .card {
      border-radius: 16px;
      border-color: var(--line);
      box-shadow: var(--shadow);
      padding: 15px;
      background: var(--surface);
    }
    .card h2 {
      font-size: 22px;
      margin-bottom: 8px;
      line-height: 1.2;
    }
    .table-meta {
      margin: 2px 0 9px;
      color: #64748b;
      font-size: 12px;
      font-weight: 600;
    }
    .table-wrap {
      border-color: var(--line-strong);
      border-radius: 12px;
      background: #fff;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, .9);
    }
    table { min-width: 740px; }
    th, td { padding: 11px 10px; }
    th {
      position: sticky;
      top: 0;
      z-index: 1;
      background: #f2f6fc;
      border-bottom-color: #cfd8e6;
    }
    tr:hover td {
      background: #f8fbff;
    }
    input, textarea {
      border-color: #cfd8e6;
      border-radius: 11px;
      background: #fff;
      margin-bottom: 9px;
    }
    .btn {
      border-radius: 10px;
      min-height: 36px;
      transition: all .2s ease;
    }
    .btn.brand { background: linear-gradient(135deg, #ff6a21, #ff4f0f); }
    .btn.brand:hover {
      background: linear-gradient(135deg, #ff5e14, #e84f0c);
      transform: translateY(-1px);
    }
    .btn.dark:hover, .btn.danger:hover { filter: brightness(1.06); transform: translateY(-1px); }
    .actions { gap: 6px; }
    .status { font-size: 11px; letter-spacing: .01em; }
    .pager { margin-top: 12px; }
    .pager .pg-btn { border-radius: 9px; min-width: 36px; height: 36px; }
    .pager .pg-dots { height: 36px; }
    @media (max-width: 1180px) {
      .stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @media (max-width: 1040px) {
      .main { padding: 14px; }
      .sidebar {
        height: auto;
        border-right: 0;
        border-bottom: 1px solid #22324a;
      }
      .menu {
        max-height: none;
        display: flex;
        gap: 8px;
        overflow: auto;
        padding-bottom: 2px;
      }
      .menu a {
        white-space: nowrap;
        flex: 0 0 auto;
      }
      .logout { margin-top: 10px; }
      .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 660px) {
      .header { padding: 13px; }
      .card { padding: 12px; }
      .card h2 { font-size: 18px; }
      .stats { grid-template-columns: 1fr; }
      .top-meta .chip { font-size: 10px; }
      .list-thumb {
        width: 54px;
        height: 54px;
        flex-basis: 54px;
      }
    }

    /* Main form polish */
    .admin-main-form {
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 12px;
      background: linear-gradient(180deg, #ffffff, #f9fbff);
    }
    .admin-main-form .form-grid > div {
      border: 1px solid #dbe4ef;
      border-radius: 11px;
      padding: 10px;
      background: #fff;
    }
    .admin-main-form label {
      font-size: 12px;
      letter-spacing: .02em;
      text-transform: uppercase;
      color: #334155;
      margin-bottom: 6px;
    }
    .admin-main-form input,
    .admin-main-form textarea,
    .admin-main-form select {
      margin-bottom: 8px;
      min-height: 42px;
      border-color: #cbd8e8;
      background-color: #fdfefe;
    }
    .admin-main-form select {
      font-weight: 600;
    }
    .admin-main-form .field-help,
    .admin-main-form .price-preview {
      margin-bottom: 2px;
    }
    .admin-main-form .validation-summary {
      display: none;
      margin: 0 0 10px;
      padding: 9px 10px;
      border-radius: 10px;
      font-size: 12px;
      font-weight: 700;
      border: 1px solid #fecaca;
      background: #fef2f2;
      color: #991b1b;
    }
    .admin-main-form .validation-summary.show { display: block; }
    .admin-main-form .submit-progress {
      margin: 0 0 10px;
      padding: 9px 10px;
      border-radius: 10px;
      font-size: 12px;
      font-weight: 700;
      border: 1px solid #bfdbfe;
      background: #eff6ff;
      color: #1d4ed8;
      display: none;
    }
    .admin-main-form .submit-progress.show { display: block; }
    .admin-main-form .field-error {
      margin-top: -3px;
      margin-bottom: 8px;
      color: #b91c1c;
      font-size: 11px;
      font-weight: 700;
    }
    .admin-main-form .invalid {
      border-color: #f87171 !important;
      box-shadow: 0 0 0 3px rgba(248, 113, 113, .14) !important;
      background: #fff7f7;
    }
    .admin-main-form .is-valid {
      border-color: #86efac !important;
      background: #f8fff9;
    }
    .admin-main-form .section-gap {
      margin-top: 10px;
    }
    .admin-main-form > .form-grid {
      margin-bottom: 8px;
    }
    .admin-main-form .toggle-inline {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      margin: 6px 0 8px;
      padding: 8px 11px;
      border: 1px solid #d4e1f2;
      border-radius: 10px;
      background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
      font-size: 13px;
      font-weight: 700;
      text-transform: none;
      color: #0f172a;
    }
    .admin-main-form .toggle-inline input[type="checkbox"] {
      width: 18px;
      height: 18px;
      margin: 0;
      accent-color: #ff5e14;
      flex: 0 0 18px;
    }
    .admin-main-form .current-image-card {
      max-width: 240px;
      border: 1px solid #dbe4ef;
      border-radius: 12px;
      background: #ffffff;
      padding: 10px;
      display: grid;
      gap: 8px;
    }
    .admin-main-form .current-image-card img {
      width: 100%;
      height: 140px;
      object-fit: cover;
      border-radius: 10px;
      border: 1px solid #e2e8f0;
      background: #f8fafc;
      display: block;
    }
    .admin-main-form .current-image-path {
      font-size: 12px;
      color: #475569;
      line-height: 1.35;
      word-break: break-all;
      margin: 0;
    }
    .editor-toolbar {
      display: flex;
      align-items: flex-start;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 10px;
      padding: 10px;
      border: 1px solid #dbe4ef;
      border-radius: 12px;
      background: linear-gradient(180deg, #f8fbff 0%, #f1f6ff 100%);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
    }
    .editor-group {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 6px;
      padding: 4px;
      border-radius: 10px;
      border: 1px solid #d4deec;
      background: #ffffff;
      flex: 0 0 auto;
    }
    .editor-select {
      min-height: 34px;
      min-width: 100px;
      flex: 0 0 auto;
      border: 1px solid #d0dae8;
      border-radius: 8px;
      padding: 0 10px;
      font-size: 12px;
      font-weight: 700;
      color: #0f172a;
      background: #fff;
    }
    .editor-btn {
      border: 1px solid #d0dae8;
      background: #fff;
      color: #0f172a;
      border-radius: 8px;
      flex: 0 0 auto;
      min-width: 34px;
      min-height: 34px;
      padding: 0 10px;
      font-size: 12px;
      font-weight: 700;
      line-height: 1.1;
      white-space: nowrap;
      cursor: pointer;
      transition: .18s ease;
    }
    .editor-btn:hover {
      border-color: #ff5e14;
      color: #ff5e14;
      background: #fff7f3;
      transform: translateY(-1px);
    }
    .editor-btn:focus {
      outline: 2px solid rgba(255, 94, 20, 0.2);
      outline-offset: 1px;
    }
    .editor-btn.label {
      min-width: auto;
      padding: 0 12px;
      font-weight: 600;
      color: #334155;
    }
    .editor-surface {
      min-height: 340px;
      border: 1px solid #cbd5e1;
      border-radius: 12px;
      padding: 14px;
      background: #fff;
      line-height: 1.6;
      font-size: 14px;
      color: #0f172a;
      overflow: auto;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
    }
    .editor-surface:focus {
      outline: 2px solid rgba(255, 94, 20, 0.2);
      border-color: #ff5e14;
    }
    .editor-surface img {
      max-width: 100%;
      height: auto;
      border-radius: 8px;
      margin: 10px auto;
      display: block;
      user-select: none;
      -webkit-user-select: none;
      -webkit-user-drag: none;
    }
    .editor-surface img.is-selected {
      outline: 3px solid rgba(255, 94, 20, 0.45);
      outline-offset: 2px;
    }
    .editor-surface h1,
    .editor-surface h2,
    .editor-surface h3,
    .editor-surface h4 {
      margin: 14px 0 10px;
      line-height: 1.25;
      color: #0b1b38;
      font-weight: 800;
    }
    .editor-surface h1 { font-size: 30px; }
    .editor-surface h2 { font-size: 26px; }
    .editor-surface h3 { font-size: 22px; }
    .editor-surface h4 { font-size: 18px; }
    .editor-surface p { margin: 0 0 10px; }
    .editor-surface ul,
    .editor-surface ol { margin: 8px 0 10px 22px; }
    .editor-surface blockquote {
      margin: 10px 0;
      padding: 10px 12px;
      border-left: 4px solid #ff5e14;
      background: #fff7f2;
      border-radius: 8px;
    }
    .editor-meta {
      margin-top: 8px;
      font-size: 12px;
      color: #64748b;
    }
    .image-controls {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-top: 8px;
      padding: 8px 10px;
      border: 1px solid #dbe4ef;
      border-radius: 10px;
      background: #f8fbff;
      font-size: 12px;
      color: #334155;
    }
    .image-controls input[type="range"] {
      flex: 1;
      min-width: 140px;
      accent-color: #ff5e14;
    }
    .image-controls input[type="number"] {
      width: 72px;
      padding: 5px 8px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      font-size: 12px;
      font-weight: 600;
      color: #0f172a;
      background: #fff;
    }
    .image-controls .mini-btn {
      border: 1px solid #d0dae8;
      background: #fff;
      color: #334155;
      border-radius: 8px;
      padding: 5px 10px;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
    }
    .image-controls .mini-btn:hover {
      border-color: #ff5e14;
      color: #ff5e14;
      background: #fff7f3;
    }
    .hidden-control {
      display: none !important;
    }
  </style>
</head>
<body>
  <div class="shell">
    <aside class="sidebar">
      <div class="brand">Admin Panel</div>
      <div class="brand-sub">PT. Maulana Prima Sejahtera</div>
      <nav class="menu">
        <a class="<?= $activePage === 'dashboard' ? 'active' : '' ?>" href="/admin/?page=dashboard">Dashboard</a>
        <a class="<?= $activePage === 'products' ? 'active' : '' ?>" href="/admin/?page=products">Produk</a>
        <a class="<?= $activePage === 'services' ? 'active' : '' ?>" href="/admin/?page=services">Layanan</a>
        <a class="<?= $activePage === 'projects' ? 'active' : '' ?>" href="/admin/?page=projects">Proyek</a>
        <a class="<?= $activePage === 'articles' ? 'active' : '' ?>" href="/admin/?page=articles">Artikel</a>
        <a class="<?= $activePage === 'testimonials' ? 'active' : '' ?>" href="/admin/?page=testimonials">Testimonial</a>
        <a class="<?= $activePage === 'reviews' ? 'active' : '' ?>" href="/admin/?page=reviews">Review</a>
        <a class="<?= $activePage === 'team' ? 'active' : '' ?>" href="/admin/?page=team">Tim</a>
        <a class="<?= $activePage === 'settings' ? 'active' : '' ?>" href="/admin/?page=settings">Pengaturan</a>
      </nav>
      <a class="logout" href="/admin/?logout=1">Logout</a>
    </aside>

    <main class="main">
      <div class="header">
        <div class="top-meta">
          <span class="chip">Page: <?= e($currentPageLabel) ?></span>
          <span class="chip">Updated: <?= e(date('d M Y H:i')) ?></span>
        </div>
        <h1><?= e($currentPageLabel) ?></h1>
        <p>Panel admin terpisah per menu untuk alur kerja yang lebih rapi dan profesional.</p>
        <div class="header-actions">
          <a class="btn dark" href="/admin/?page=<?= e($activePage) ?>&action=export_sql">Export SQL Backup</a>
        </div>
      </div>

      <?php if ($message !== ''): ?><div class="alert ok"><?= e($message) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>

      <div class="stats">
        <div class="stat"><div class="label">Total Produk</div><div class="value"><?= $totalProducts ?></div></div>
        <div class="stat"><div class="label">Total Review</div><div class="value"><?= $totalReviews ?></div></div>
        <div class="stat"><div class="label">Testimonial Aktif</div><div class="value"><?= $activeTestimonials ?></div></div>
        <div class="stat"><div class="label">Tim Aktif</div><div class="value"><?= $activeTeamMembers ?></div></div>
        <div class="stat"><div class="label">Pending</div><div class="value"><?= $pendingReviews ?></div></div>
      </div>

      <?php if ($activePage === 'dashboard'): ?>
        <div class="grid">
          <div class="stack">
            <section class="card small-table">
              <h2>Produk Terbaru</h2>
              <div class="table-meta"><?= e(pager_summary_text($dashboardProductsPager)) ?></div>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Produk</th><th>Harga</th><th>Slug</th></tr></thead>
                  <tbody>
                  <?php foreach ($dashboardProductsPager['items'] as $product): ?>
                    <tr>
                      <td><?= e($product['name']) ?></td>
                      <td>Rp <?= number_format((int) $product['price'], 0, ',', '.') ?></td>
                      <td><?= e($product['slug']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$dashboardProductsPager['items']): ?><tr><td colspan="3">Belum ada produk.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
              <?php render_pagination($dashboardProductsPager); ?>
            </section>
          </div>
          <div class="stack">
            <section class="card small-table">
              <h2>Review Terbaru</h2>
              <div class="table-meta"><?= e(pager_summary_text($dashboardReviewsPager)) ?></div>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Produk</th><th>Nama</th><th>Status</th></tr></thead>
                  <tbody>
                  <?php foreach ($dashboardReviewsPager['items'] as $review): ?>
                    <tr>
                      <td><?= e($review['product_name']) ?></td>
                      <td><?= e($review['reviewer_name']) ?></td>
                      <td>
                        <?php if ((int) $review['is_approved'] === 1): ?>
                          <span class="status approved">Approved</span>
                        <?php else: ?>
                          <span class="status pending">Pending</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$dashboardReviewsPager['items']): ?><tr><td colspan="3">Belum ada review.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
              <?php render_pagination($dashboardReviewsPager); ?>
            </section>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($activePage === 'products'): ?>
        <div class="grid">
          <div class="stack">
            <section class="card">
              <h2><?= $editProduct ? 'Edit Produk' : 'Tambah Produk Baru' ?></h2>
              <p class="muted">Isi data produk dengan nama field yang jelas. Upload JPG/JPEG/PNG bisa langsung dari form.</p>
              <form method="post" enctype="multipart/form-data" class="admin-main-form settings-form settings-modern">
                <input type="hidden" name="action" value="save_product">
                <input type="hidden" name="redirect_page" value="products">
                <input type="hidden" name="id" value="<?= (int) ($editProduct['id'] ?? 0) ?>">
                <input type="hidden" name="current_image" value="<?= e((string) ($editProduct['image'] ?? '')) ?>">

                <div class="form-grid">
                  <div>
                    <label>Nama Produk</label>
                    <input name="name" required value="<?= e((string) ($editProduct['name'] ?? '')) ?>" placeholder="Contoh: Pompa Hydrant">
                  </div>
                  <div>
                    <label>Slug URL (opsional)</label>
                    <input name="slug" value="<?= e((string) ($editProduct['slug'] ?? '')) ?>" placeholder="contoh: pompa-hydrant">
                  </div>
                  <div>
                    <label>Kategori Produk (opsional)</label>
                    <input name="category" value="<?= e((string) ($editProduct['category'] ?? '')) ?>" placeholder="Contoh: Pompa, Aksesoris, Safety">
                  </div>
                  <div>
                    <label>Label Promosi (opsional)</label>
                    <select name="product_badge">
                      <?php $badgeValue = (string) ($editProduct['product_badge'] ?? ''); ?>
                      <option value="" <?= $badgeValue === '' ? 'selected' : '' ?>>Tanpa Label</option>
                      <option value="best_seller" <?= $badgeValue === 'best_seller' ? 'selected' : '' ?>>Best Seller</option>
                      <option value="most_searched" <?= $badgeValue === 'most_searched' ? 'selected' : '' ?>>Paling Dicari</option>
                    </select>
                  </div>
                  <div>
                    <label>Harga Produk (Rupiah)</label>
                    <input type="hidden" name="price" id="price-hidden" value="<?= e((string) ($editProduct['price'] ?? 0)) ?>">
                    <input type="text" id="price-display" inputmode="numeric" data-price-display value="<?= e(number_format((int) ($editProduct['price'] ?? 0), 0, ',', '.')) ?>" placeholder="Contoh: 1.000.000">
                    <div class="price-preview">Masukkan angka, sistem otomatis format dengan titik.</div>
                  </div>
                  <div>
                    <label>Link Shopee (opsional)</label>
                    <input name="marketplace_shopee" value="<?= e((string) ($editProduct['marketplace_shopee'] ?? '')) ?>" placeholder="https://shopee.co.id/...">
                  </div>
                  <div>
                    <label>Link Tokopedia (opsional)</label>
                    <input name="marketplace_tokopedia" value="<?= e((string) ($editProduct['marketplace_tokopedia'] ?? '')) ?>" placeholder="https://tokopedia.com/...">
                  </div>
                </div>
                <label>Upload Gambar Utama (JPG/PNG)</label>
                <div class="upload-file-widget">
                  <input class="sr-only-upload" type="file" name="image_file" id="image-file-input" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                  <label for="image-file-input" class="upload-file-trigger">
                    <i class="fa-solid fa-file-arrow-up"></i>
                    Pilih Gambar
                  </label>
                  <span id="image-file-name" class="upload-file-name">Belum ada file dipilih</span>
                </div>
                <div class="field-help">Format: .jpg / .jpeg / .png. <?= $editProduct ? 'Kosongkan jika tidak ingin ganti gambar utama.' : 'Wajib diisi untuk produk baru.' ?></div>
                <div class="upload-preview-box upload-preview-main" id="main-upload-preview">
                  <div class="upload-preview-empty">Belum ada file dipilih.</div>
                </div>
                <?php if ($editProduct && !empty($editProduct['image'])): ?>
                  <label>Gambar Utama Saat Ini</label>
                  <div class="current-image-card">
                    <img src="<?= e((string) $editProduct['image']) ?>" alt="Gambar utama">
                    <p class="current-image-path"><?= e((string) $editProduct['image']) ?></p>
                  </div>
                <?php endif; ?>

                <label>Upload Gallery Gambar (Multiple JPG/PNG)</label>
                <div class="upload-file-widget">
                  <input class="sr-only-upload" type="file" name="gallery_files[]" id="gallery-files-input" accept=".jpg,.jpeg,.png,image/jpeg,image/png" multiple>
                  <label for="gallery-files-input" class="upload-file-trigger">
                    <i class="fa-solid fa-images"></i>
                    Pilih Gallery
                  </label>
                  <span id="gallery-files-file-name" class="upload-file-name">Belum ada file dipilih</span>
                </div>
                <div class="field-help" id="gallery-help-text">Bisa pilih banyak file JPG/JPEG/PNG sekaligus. Kamu juga bisa pilih file lagi berkali-kali, nanti ditumpuk sampai klik Simpan Produk.</div>
                <div class="upload-preview-box">
                  <div class="upload-preview-gallery" id="gallery-upload-preview">
                    <div class="upload-preview-empty">Belum ada file dipilih.</div>
                  </div>
                </div>

                <?php if ($editProductGallery): ?>
                  <label>Gallery Saat Ini (<?= count($editProductGallery) ?> gambar)</label>
                  <div class="gallery-preview">
                    <div class="gallery-grid">
                      <?php foreach ($editProductGallery as $galleryImage): ?>
                        <div class="gallery-item">
                          <img src="<?= e((string) $galleryImage['image_path']) ?>" alt="Gallery">
                          <div class="item-body">
                            <div class="muted"><?= e((string) $galleryImage['image_path']) ?></div>
                            <button
                              class="btn danger"
                              type="button"
                              onclick="deleteProductGalleryImage(<?= (int) $galleryImage['id'] ?>, <?= (int) $editProduct['id'] ?>)">
                              Hapus Gambar
                            </button>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>

                <label>Deskripsi Singkat Produk</label>
                <div id="product-short-desc-surface" class="editor-surface" contenteditable="true" spellcheck="false" style="min-height:130px;"></div>
                <textarea name="short_description" id="product-short-desc-hidden" style="display:none;"><?= e((string) ($editProduct['short_description'] ?? '')) ?></textarea>
                <div class="field-help">Disimpan sebagai teks biasa (tanpa HTML). Cocok untuk ringkasan singkat di kartu/hero.</div>

                <label>Deskripsi Detail Produk</label>
                <div class="editor-toolbar" id="product-desc-toolbar">
                  <div class="editor-group">
                    <button type="button" class="editor-btn label" data-block="p">Normal</button>
                    <button type="button" class="editor-btn" data-block="h2">H2</button>
                  </div>
                  <div class="editor-group">
                    <select id="product-desc-font-size" class="editor-select" title="Ukuran Font">
                      <option value="">Uk Font</option>
                      <option value="12">12px</option>
                      <option value="14">14px</option>
                      <option value="16">16px</option>
                      <option value="18">18px</option>
                      <option value="20">20px</option>
                      <option value="24">24px</option>
                      <option value="28">28px</option>
                      <option value="32">32px</option>
                      <option value="36">36px</option>
                    </select>
                    <button type="button" class="editor-btn" data-cmd="bold"><b>B</b></button>
                    <button type="button" class="editor-btn" data-cmd="italic"><i>I</i></button>
                    <button type="button" class="editor-btn" data-cmd="underline"><u>U</u></button>
                    <button type="button" class="editor-btn" data-cmd="insertUnorderedList">Bullets</button>
                    <button type="button" class="editor-btn" data-cmd="insertOrderedList">Number</button>
                  </div>
                  <div class="editor-group">
                    <button type="button" class="editor-btn" data-link="1">Link</button>
                    <button type="button" class="editor-btn" data-cmd="removeFormat">Clear</button>
                  </div>
                </div>
                <div id="product-desc-surface" class="editor-surface" contenteditable="true" spellcheck="false" style="min-height:180px;"></div>
                <textarea name="description" id="product-desc-hidden" style="display:none;"><?= e((string) ($editProduct['description'] ?? '')) ?></textarea>
                <div class="field-help">Editor ini seperti artikel: blok teks untuk bold/italic/ukuran font/bullets. Tidak perlu nulis token.</div>

                <div class="form-grid">
                  <div>
                    <label>Use-case Chips (1 baris = 1 item, opsional)</label>
                    <textarea name="use_case_tags" spellcheck="false" placeholder="Contoh: Untuk Pabrik&#10;Untuk Gedung&#10;Area Komersial"><?= e(implode("\n", json_decode((string) ($editProduct['use_case_tags_json'] ?? '[]'), true) ?: [])) ?></textarea>
                  </div>
                  <div>
                    <label>Fitur (1 baris = 1 item)</label>
                    <textarea name="features" spellcheck="false"><?= e(implode("\n", json_decode((string) ($editProduct['features_json'] ?? '[]'), true) ?: [])) ?></textarea>
                  </div>
                  <div>
                    <label>Additional Info (1 baris = 1 item)</label>
                    <textarea name="additional" spellcheck="false"><?= e(implode("\n", json_decode((string) ($editProduct['additional_json'] ?? '[]'), true) ?: [])) ?></textarea>
                  </div>
                </div>
                <button class="btn brand" type="submit">Simpan Produk</button>
              </form>
              <?php if ($editProduct): ?>
                <form id="delete-product-image-form" method="post" style="display:none;">
                  <input type="hidden" name="action" value="delete_gallery_image">
                  <input type="hidden" name="redirect_page" value="products">
                  <input type="hidden" name="image_id" id="delete-product-image-id" value="">
                  <input type="hidden" name="product_id" id="delete-product-id" value="<?= (int) $editProduct['id'] ?>">
                </form>
              <?php endif; ?>
            </section>
          </div>

          <div class="stack">
            <section class="card">
              <h2>Daftar Produk</h2>
              <div class="table-meta"><?= e(pager_summary_text($productsPager)) ?></div>
              <form id="bulk-products-form" class="bulk-bar" method="post" data-bulk-entity="produk" onsubmit="return window.bulkConfirmDelete(event, this);">
                <input type="hidden" name="action" value="bulk_delete_products">
                <input type="hidden" name="redirect_page" value="products">
                <button class="btn danger" type="submit">Hapus Terpilih</button>
                <span class="bulk-count" data-bulk-count-for="bulk-products-form">0 dipilih</span>
              </form>
              <div class="table-wrap">
                <table>
                  <thead><tr><th class="bulk-col"><input class="bulk-check bulk-check-all" type="checkbox" data-bulk-form="bulk-products-form"></th><th>ID</th><th>Produk</th><th>Kategori</th><th>Harga</th><th>Slug</th><th>Aksi</th></tr></thead>
                  <tbody>
                  <?php foreach ($productsPager['items'] as $product): ?>
                    <tr>
                      <td class="bulk-col"><input class="bulk-check bulk-item" type="checkbox" form="bulk-products-form" data-bulk-form="bulk-products-form" name="ids[]" value="<?= (int) $product['id'] ?>"></td>
                      <td><?= (int) $product['id'] ?></td>
                      <td>
                        <?php $productImage = trim((string) ($product['image'] ?? '')); ?>
                        <div class="list-item-with-thumb">
                          <?php if ($productImage !== ''): ?>
                            <img class="list-thumb" src="<?= e($productImage) ?>" alt="<?= e((string) $product['name']) ?>" onerror="this.outerHTML='<div class=&quot;list-thumb placeholder&quot;>No Image</div>'">
                          <?php else: ?>
                            <div class="list-thumb placeholder">No Image</div>
                          <?php endif; ?>
                          <div class="list-item-content">
                            <strong><?= e($product['name']) ?></strong>
                            <div class="muted"><?= e($productImage !== '' ? $productImage : '-') ?></div>
                            <?php if (!empty($product['product_badge'])): ?>
                              <div class="muted">Label: <?= e((string) $product['product_badge']) ?></div>
                            <?php endif; ?>
                            <div class="muted">Gallery: <?= (int) $product['gallery_count'] ?> gambar</div>
                          </div>
                        </div>
                      </td>
                      <td><?= e((string) ($product['category'] ?? '-')) ?></td>
                      <td>Rp <?= number_format((int) $product['price'], 0, ',', '.') ?></td>
                      <td><?= e($product['slug']) ?></td>
                      <td>
                        <div class="actions">
                          <a class="btn dark" href="/admin/?page=products&edit=<?= (int) $product['id'] ?>">Edit</a>
                          <form method="post" onsubmit="return window.uiConfirmSubmit(event, this, 'Hapus produk ini?', { title: 'Konfirmasi Hapus', okText: 'Hapus', cancelText: 'Batal', variant: 'danger', icon: '!' });">
                            <input type="hidden" name="action" value="delete_product">
                            <input type="hidden" name="redirect_page" value="products">
                            <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                            <button class="btn danger" type="submit">Hapus</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$productsPager['items']): ?><tr><td colspan="7">Belum ada data produk.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
              <?php render_pagination($productsPager); ?>
            </section>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($activePage === 'services'): ?>
        <div class="grid">
          <div class="stack">
            <section class="card">
              <h2><?= $editService ? 'Edit Layanan' : 'Tambah Layanan' ?></h2>
              <p class="muted">Data layanan ini akan tampil di halaman <strong>/layanan/</strong>.</p>
              <form method="post" enctype="multipart/form-data" class="admin-main-form settings-form settings-modern">
                <input type="hidden" name="action" value="save_service">
                <input type="hidden" name="redirect_page" value="services">
                <input type="hidden" name="id" value="<?= (int) ($editService['id'] ?? 0) ?>">
                <input type="hidden" name="current_image" value="<?= e((string) ($editService['image'] ?? '')) ?>">
                <div class="form-grid">
                  <div>
                    <label>Nama Layanan</label>
                    <input name="name" required minlength="3" value="<?= e((string) ($editService['name'] ?? '')) ?>" placeholder="Contoh: Ducting System">
                  </div>
                  <div>
                    <label>Slug (opsional)</label>
                    <input name="slug" value="<?= e((string) ($editService['slug'] ?? '')) ?>" placeholder="ducting-system">
                  </div>
                  <div>
                    <label>Label Card Layanan (opsional)</label>
                    <input name="card_highlight" maxlength="80" value="<?= e((string) ($editService['card_highlight'] ?? '')) ?>" placeholder="Contoh: Tim Tersertifikasi">
                    <div class="field-help">Tampil di card layanan website (contoh: Implementasi Terukur).</div>
                  </div>
                  <div>
                    <label>Estimasi Pengerjaan</label>
                    <input name="duration_text" maxlength="120" value="<?= e((string) ($editService['duration_text'] ?? '')) ?>" placeholder="Contoh: 3 Bulan">
                    <div class="field-help">Isi minimal salah satu: Estimasi Pengerjaan atau Harga/Nilai Proyek.</div>
                  </div>
                  <div>
                    <label>Harga / Nilai Proyek</label>
                    <?php $servicePriceDigits = preg_replace('/\D+/', '', (string) ($editService['price_text'] ?? '')) ?: ''; ?>
                    <input type="hidden" name="price_text" id="service-price-hidden" value="<?= e($servicePriceDigits) ?>">
                    <input type="text" id="service-price-display" inputmode="numeric" value="<?= e($servicePriceDigits !== '' ? number_format((int) $servicePriceDigits, 0, ',', '.') : '') ?>" placeholder="Contoh: 180.000.000">
                    <div class="price-preview">Masukkan angka, sistem otomatis format dengan titik dan simpan sebagai Rupiah.</div>
                  </div>
                  <div>
                    <label>Link Detail (otomatis)</label>
                    <input disabled value="<?= e((string) ($editService['detail_url'] ?? '/layanan/detail/?slug=...')) ?>">
                    <div class="field-help">Dibuat otomatis dari slug. Contoh: <code>/layanan/detail/?slug=nama-layanan</code></div>
                  </div>
                  <div>
                    <label>Urutan Tampil</label>
                    <input type="number" min="0" name="sort_order" value="<?= (int) ($editService['sort_order'] ?? 0) ?>">
                  </div>
                </div>
                <label>Upload Gambar Layanan (JPG/PNG)</label>
                <div class="upload-file-widget">
                  <input class="sr-only-upload" type="file" name="image_file" id="service-image-file-input" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                  <label for="service-image-file-input" class="upload-file-trigger">
                    <i class="fa-solid fa-file-arrow-up"></i>
                    Pilih Gambar
                  </label>
                  <span id="service-image-file-name" class="upload-file-name">Belum ada file dipilih</span>
                </div>
                <div class="field-help"><?= $editService ? 'Kosongkan jika tidak ingin ganti gambar.' : 'Wajib diisi untuk data baru.' ?></div>
                <div class="upload-preview-box upload-preview-main" id="service-main-upload-preview">
                  <div class="upload-preview-empty">Belum ada file dipilih.</div>
                </div>
                <?php if ($editService && !empty($editService['image'])): ?>
                  <label>Gambar Utama Saat Ini</label>
                  <div class="current-image-card">
                    <img src="<?= e((string) $editService['image']) ?>" alt="Gambar layanan">
                    <p class="current-image-path"><?= e((string) $editService['image']) ?></p>
                  </div>
                <?php endif; ?>
                <label>Upload Gallery Layanan (Multiple JPG/PNG)</label>
                <div class="upload-file-widget">
                  <input class="sr-only-upload" type="file" name="service_gallery_files[]" id="service-gallery-files-input" accept=".jpg,.jpeg,.png,image/jpeg,image/png" multiple>
                  <label for="service-gallery-files-input" class="upload-file-trigger">
                    <i class="fa-solid fa-images"></i>
                    Pilih Gallery
                  </label>
                  <span id="service-gallery-files-file-name" class="upload-file-name">Belum ada file dipilih</span>
                </div>
                <div class="field-help" id="service-gallery-help-text">Bisa pilih banyak file JPG/JPEG/PNG sekaligus. Kamu juga bisa pilih file lagi berkali-kali, nanti ditumpuk sampai klik Simpan Layanan.</div>
                <div class="upload-preview-box">
                  <div class="upload-preview-gallery" id="service-gallery-upload-preview">
                    <div class="upload-preview-empty">Belum ada file dipilih.</div>
                  </div>
                </div>
                <?php if ($editServiceGallery): ?>
                  <label>Gallery Layanan Saat Ini (<?= count($editServiceGallery) ?> gambar)</label>
                  <div class="gallery-preview">
                    <div class="gallery-grid">
                      <?php foreach ($editServiceGallery as $galleryImage): ?>
                        <div class="gallery-item">
                          <img src="<?= e((string) $galleryImage['image_path']) ?>" alt="Gallery layanan">
                          <div class="item-body">
                            <div class="muted"><?= e((string) $galleryImage['image_path']) ?></div>
                            <button
                              class="btn danger"
                              type="button"
                              onclick="deleteServiceGalleryImage(<?= (int) $galleryImage['id'] ?>, <?= (int) $editService['id'] ?>)">
                              Hapus Gambar
                            </button>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>
                <label>Deskripsi Singkat</label>
                <div id="service-short-desc-surface" class="editor-surface" contenteditable="true" spellcheck="false" style="min-height:130px;"></div>
                <textarea name="short_description" id="service-short-desc-hidden" style="display:none;" required minlength="10"><?= e((string) ($editService['short_description'] ?? '')) ?></textarea>
                <div class="field-help">Disimpan sebagai teks biasa (tanpa HTML). Cocok untuk ringkasan layanan.</div>
                <label>Deskripsi Detail Layanan</label>
                <div class="editor-toolbar" id="service-desc-toolbar">
                  <div class="editor-group">
                    <button type="button" class="editor-btn label" data-block="p">Normal</button>
                    <button type="button" class="editor-btn" data-block="h2">H2</button>
                  </div>
                  <div class="editor-group">
                    <select id="service-desc-font-size" class="editor-select" title="Ukuran Font">
                      <option value="">Uk Font</option>
                      <option value="12">12px</option>
                      <option value="14">14px</option>
                      <option value="16">16px</option>
                      <option value="18">18px</option>
                      <option value="20">20px</option>
                      <option value="24">24px</option>
                      <option value="28">28px</option>
                      <option value="32">32px</option>
                      <option value="36">36px</option>
                    </select>
                    <button type="button" class="editor-btn" data-cmd="bold"><b>B</b></button>
                    <button type="button" class="editor-btn" data-cmd="italic"><i>I</i></button>
                    <button type="button" class="editor-btn" data-cmd="underline"><u>U</u></button>
                    <button type="button" class="editor-btn" data-cmd="insertUnorderedList">Bullets</button>
                    <button type="button" class="editor-btn" data-cmd="insertOrderedList">Number</button>
                  </div>
                  <div class="editor-group">
                    <button type="button" class="editor-btn" data-link="1">Link</button>
                    <button type="button" class="editor-btn" data-cmd="removeFormat">Clear</button>
                  </div>
                </div>
                <div id="service-desc-surface" class="editor-surface" contenteditable="true" spellcheck="false" style="min-height:180px;"></div>
                <textarea name="description" id="service-desc-hidden" style="display:none;"><?= e((string) ($editService['description'] ?? '')) ?></textarea>
                <div class="field-help">Editor ini seperti artikel: blok teks untuk bold/italic/ukuran font/bullets. Tidak perlu nulis token.</div>
                <label>Poin Keunggulan (1 baris = 1 item)</label>
                <textarea name="features" spellcheck="false"><?= e(implode("\n", json_decode((string) ($editService['features_json'] ?? '[]'), true) ?: [])) ?></textarea>
                <label>Link Video (opsional)</label>
                <input name="video_url" value="<?= e((string) ($editService['video_url'] ?? '')) ?>" placeholder="YouTube URL atau /assets/videos/file.mp4">
                <div class="field-help">Contoh: <code>https://www.youtube.com/watch?v=...</code>, link Google Drive, atau file MP4 lokal.</div>
                <label class="toggle-inline">
                  <input type="checkbox" name="is_active" <?= ((int) ($editService['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                  Tampilkan di website
                </label>
                <button class="btn brand" type="submit">Simpan Layanan</button>
              </form>
              <?php if ($editService): ?>
                <form id="delete-service-image-form" method="post" style="display:none;">
                  <input type="hidden" name="action" value="delete_service_image">
                  <input type="hidden" name="image_id" id="delete-service-image-id" value="">
                  <input type="hidden" name="service_id" id="delete-service-id" value="<?= (int) $editService['id'] ?>">
                </form>
              <?php endif; ?>
            </section>
          </div>
          <div class="stack">
            <section class="card">
              <h2>Daftar Layanan</h2>
              <div class="table-meta"><?= e(pager_summary_text($servicesPager)) ?></div>
              <form id="bulk-services-form" class="bulk-bar" method="post" data-bulk-entity="layanan" onsubmit="return window.bulkConfirmDelete(event, this);">
                <input type="hidden" name="action" value="bulk_delete_services">
                <input type="hidden" name="redirect_page" value="services">
                <button class="btn danger" type="submit">Hapus Terpilih</button>
                <span class="bulk-count" data-bulk-count-for="bulk-services-form">0 dipilih</span>
              </form>
              <div class="table-wrap">
                <table>
                  <thead><tr><th class="bulk-col"><input class="bulk-check bulk-check-all" type="checkbox" data-bulk-form="bulk-services-form"></th><th>ID</th><th>Nama</th><th>Slug</th><th>Link</th><th>Status</th><th>Aksi</th></tr></thead>
                  <tbody>
                  <?php foreach ($servicesPager['items'] as $service): ?>
                    <tr>
                      <td class="bulk-col"><input class="bulk-check bulk-item" type="checkbox" form="bulk-services-form" data-bulk-form="bulk-services-form" name="ids[]" value="<?= (int) $service['id'] ?>"></td>
                      <td><?= (int) $service['id'] ?></td>
                      <td>
                        <?php $serviceImage = trim((string) ($service['image'] ?? '')); ?>
                        <div class="list-item-with-thumb">
                          <?php if ($serviceImage !== ''): ?>
                            <img class="list-thumb" src="<?= e($serviceImage) ?>" alt="<?= e((string) $service['name']) ?>" onerror="this.outerHTML='<div class=&quot;list-thumb placeholder&quot;>No Image</div>'">
                          <?php else: ?>
                            <div class="list-thumb placeholder">No Image</div>
                          <?php endif; ?>
                          <div class="list-item-content">
                            <strong><?= e((string) $service['name']) ?></strong>
                            <?php if (trim((string) ($service['card_highlight'] ?? '')) !== ''): ?>
                              <div class="muted">Label Card: <?= e((string) $service['card_highlight']) ?></div>
                            <?php endif; ?>
                            <div class="muted"><?= e($serviceImage !== '' ? $serviceImage : '-') ?></div>
                            <div class="muted">Gallery: <?= (int) $service['gallery_count'] ?> gambar</div>
                          </div>
                        </div>
                      </td>
                      <td><?= e((string) $service['slug']) ?></td>
                      <td><code><?= e(build_service_detail_url((string) $service['slug'])) ?></code></td>
                      <td><?= (int) $service['is_active'] === 1 ? '<span class="status approved">Aktif</span>' : '<span class="status pending">Nonaktif</span>' ?></td>
                      <td>
                        <div class="actions">
                          <a class="btn dark" href="/admin/?page=services&edit=<?= (int) $service['id'] ?>">Edit</a>
                          <form method="post" onsubmit="return window.uiConfirmSubmit(event, this, 'Hapus layanan ini?', { title: 'Konfirmasi Hapus', okText: 'Hapus', cancelText: 'Batal', variant: 'danger', icon: '!' });">
                            <input type="hidden" name="action" value="delete_service">
                            <input type="hidden" name="id" value="<?= (int) $service['id'] ?>">
                            <button class="btn danger" type="submit">Hapus</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$servicesPager['items']): ?><tr><td colspan="7">Belum ada data layanan.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
              <?php render_pagination($servicesPager); ?>
            </section>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($activePage === 'projects'): ?>
        <div class="grid">
          <div class="stack">
            <section class="card">
              <h2><?= $editProject ? 'Edit Proyek' : 'Tambah Proyek' ?></h2>
              <p class="muted">Data proyek ini akan tampil di halaman <strong>/proyek/</strong>.</p>
              <form method="post" enctype="multipart/form-data" class="admin-main-form settings-form settings-modern">
                <input type="hidden" name="action" value="save_project">
                <input type="hidden" name="redirect_page" value="projects">
                <input type="hidden" name="id" value="<?= (int) ($editProject['id'] ?? 0) ?>">
                <input type="hidden" name="current_image" value="<?= e((string) ($editProject['image'] ?? '')) ?>">
                <div class="form-grid">
                  <div>
                    <label>Judul Proyek</label>
                    <input name="title" required value="<?= e((string) ($editProject['title'] ?? '')) ?>" placeholder="Contoh: Instalasi Hydrant Gudang Logistik">
                  </div>
                  <div>
                    <label>Slug (opsional)</label>
                    <input name="slug" value="<?= e((string) ($editProject['slug'] ?? '')) ?>" placeholder="instalasi-hydrant-gudang">
                  </div>
                  <div>
                    <label>Client</label>
                    <input name="client_name" value="<?= e((string) ($editProject['client_name'] ?? '')) ?>" placeholder="Contoh: PT XYZ Manufaktur">
                  </div>
                  <div>
                    <label>Lokasi</label>
                    <input name="location_name" value="<?= e((string) ($editProject['location_name'] ?? '')) ?>" placeholder="Contoh: Karawang, Jawa Barat">
                  </div>
                  <div>
                    <label>Tahun Proyek</label>
                    <input name="project_year" value="<?= e((string) ($editProject['project_year'] ?? '')) ?>" placeholder="Contoh: 2025">
                  </div>
                  <div>
                    <label>Durasi</label>
                    <input name="duration_text" value="<?= e((string) ($editProject['duration_text'] ?? '')) ?>" placeholder="Contoh: 5 Bulan">
                  </div>
                  <div>
                    <label>Harga / Nilai Proyek</label>
                    <?php $projectPriceDigits = preg_replace('/\D+/', '', (string) ($editProject['price_text'] ?? '')) ?: ''; ?>
                    <input name="price_text" id="project-price-display" inputmode="numeric" value="<?= e($projectPriceDigits !== '' ? number_format((int) $projectPriceDigits, 0, ',', '.') : '') ?>" placeholder="Contoh: 1.200.000.000">
                    <div class="price-preview">Masukkan angka, sistem otomatis format dengan titik.</div>
                  </div>
                  <div>
                    <label>Kategori/Label</label>
                    <input name="category" value="<?= e((string) ($editProject['category'] ?? '')) ?>" placeholder="Building, Renovation">
                  </div>
                  <div>
                    <label>Link Detail (otomatis)</label>
                    <input disabled value="<?= e((string) ($editProject['detail_url'] ?? '/proyek/detail/?slug=...')) ?>">
                    <div class="field-help">Sistem otomatis membuat link ke detail proyek berdasarkan slug.</div>
                  </div>
                  <div>
                    <label>Urutan Tampil</label>
                    <input type="number" min="0" name="sort_order" value="<?= (int) ($editProject['sort_order'] ?? 0) ?>">
                  </div>
                </div>
                <label>Upload Gambar Proyek (JPG/PNG)</label>
                <div class="upload-file-widget">
                  <input class="sr-only-upload" type="file" name="image_file" id="project-image-file-input" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                  <label for="project-image-file-input" class="upload-file-trigger">
                    <i class="fa-solid fa-file-arrow-up"></i>
                    Pilih Gambar
                  </label>
                  <span id="project-image-file-name" class="upload-file-name">Belum ada file dipilih</span>
                </div>
                <div class="field-help"><?= $editProject ? 'Kosongkan jika tidak ingin ganti gambar.' : 'Wajib diisi untuk data baru.' ?></div>
                <div class="upload-preview-box upload-preview-main" id="project-main-upload-preview">
                  <div class="upload-preview-empty">Belum ada file dipilih.</div>
                </div>
                <?php if ($editProject && !empty($editProject['image'])): ?>
                  <label>Gambar Utama Saat Ini</label>
                  <div class="current-image-card">
                    <img src="<?= e((string) $editProject['image']) ?>" alt="Gambar proyek">
                    <p class="current-image-path"><?= e((string) $editProject['image']) ?></p>
                  </div>
                <?php endif; ?>
                <label>Upload Gallery Proyek (Multiple JPG/PNG)</label>
                <div class="upload-file-widget">
                  <input class="sr-only-upload" type="file" name="project_gallery_files[]" id="project-gallery-files-input" accept=".jpg,.jpeg,.png,image/jpeg,image/png" multiple>
                  <label for="project-gallery-files-input" class="upload-file-trigger">
                    <i class="fa-solid fa-images"></i>
                    Pilih Gallery
                  </label>
                  <span id="project-gallery-files-file-name" class="upload-file-name">Belum ada file dipilih</span>
                </div>
                <div class="field-help" id="project-gallery-help-text">Bisa pilih banyak file JPG/JPEG/PNG sekaligus. Kamu juga bisa pilih file lagi berkali-kali, nanti ditumpuk sampai klik Simpan Proyek.</div>
                <div class="upload-preview-box">
                  <div class="upload-preview-gallery" id="project-gallery-upload-preview">
                    <div class="upload-preview-empty">Belum ada file dipilih.</div>
                  </div>
                </div>
                <?php if ($editProjectGallery): ?>
                  <label>Gallery Proyek Saat Ini (<?= count($editProjectGallery) ?> gambar)</label>
                  <div class="gallery-preview">
                    <div class="gallery-grid">
                      <?php foreach ($editProjectGallery as $galleryImage): ?>
                        <div class="gallery-item">
                          <img src="<?= e((string) $galleryImage['image_path']) ?>" alt="Gallery proyek">
                          <div class="item-body">
                            <div class="muted"><?= e((string) $galleryImage['image_path']) ?></div>
                            <button
                              class="btn danger"
                              type="button"
                              onclick="deleteProjectGalleryImage(<?= (int) $galleryImage['id'] ?>, <?= (int) $editProject['id'] ?>)">
                              Hapus Gambar
                            </button>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>
                <label>Deskripsi Singkat</label>
                <div id="project-short-desc-surface" class="editor-surface" contenteditable="true" spellcheck="false" style="min-height:130px;"></div>
                <textarea name="short_description" id="project-short-desc-hidden" style="display:none;"><?= e((string) ($editProject['short_description'] ?? '')) ?></textarea>
                <div class="field-help">Disimpan sebagai teks biasa (tanpa HTML). Cocok untuk ringkasan yang rapi dan mudah dibaca.</div>
                <label>Deskripsi Detail Proyek</label>
                <div class="editor-toolbar" id="project-desc-toolbar">
                  <div class="editor-group">
                    <button type="button" class="editor-btn label" data-block="p">Normal</button>
                    <button type="button" class="editor-btn" data-block="h2">H2</button>
                  </div>
                  <div class="editor-group">
                    <select id="project-desc-font-size" class="editor-select" title="Ukuran Font">
                      <option value="">Uk Font</option>
                      <option value="12">12px</option>
                      <option value="14">14px</option>
                      <option value="16">16px</option>
                      <option value="18">18px</option>
                      <option value="20">20px</option>
                      <option value="24">24px</option>
                      <option value="28">28px</option>
                      <option value="32">32px</option>
                      <option value="36">36px</option>
                    </select>
                    <button type="button" class="editor-btn" data-cmd="bold"><b>B</b></button>
                    <button type="button" class="editor-btn" data-cmd="italic"><i>I</i></button>
                    <button type="button" class="editor-btn" data-cmd="underline"><u>U</u></button>
                    <button type="button" class="editor-btn" data-cmd="insertUnorderedList">Bullets</button>
                    <button type="button" class="editor-btn" data-cmd="insertOrderedList">Number</button>
                  </div>
                  <div class="editor-group">
                    <button type="button" class="editor-btn" data-link="1">Link</button>
                    <button type="button" class="editor-btn" data-cmd="removeFormat">Clear</button>
                  </div>
                </div>
                <div id="project-desc-surface" class="editor-surface" contenteditable="true" spellcheck="false" style="min-height:200px;"></div>
                <textarea name="description" id="project-desc-hidden" style="display:none;"><?= e((string) ($editProject['description'] ?? '')) ?></textarea>
                <div class="field-help">Editor ini seperti artikel: blok teks untuk bold/italic/ukuran font/bullets. Tidak perlu nulis token.</div>
                <label>Keunggulan Proyek (1 baris = 1 item)</label>
                <textarea name="features" spellcheck="false"><?= e(implode("\n", json_decode((string) ($editProject['features_json'] ?? '[]'), true) ?: [])) ?></textarea>
                <label>Link Video (opsional)</label>
                <input name="video_url" value="<?= e((string) ($editProject['video_url'] ?? '')) ?>" placeholder="YouTube / Google Drive / MP4">
                <label class="toggle-inline">
                  <input type="checkbox" name="is_active" <?= ((int) ($editProject['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                  Tampilkan di website
                </label>
                <button class="btn brand" type="submit">Simpan Proyek</button>
              </form>
              <?php if ($editProject): ?>
                <form id="delete-project-image-form" method="post" style="display:none;">
                  <input type="hidden" name="action" value="delete_project_image">
                  <input type="hidden" name="image_id" id="delete-project-image-id" value="">
                  <input type="hidden" name="project_id" id="delete-project-id" value="<?= (int) $editProject['id'] ?>">
                </form>
              <?php endif; ?>
            </section>
          </div>
          <div class="stack">
            <section class="card">
              <h2>Daftar Proyek</h2>
              <div class="table-meta"><?= e(pager_summary_text($projectsPager)) ?></div>
              <form id="bulk-projects-form" class="bulk-bar" method="post" data-bulk-entity="proyek" onsubmit="return window.bulkConfirmDelete(event, this);">
                <input type="hidden" name="action" value="bulk_delete_projects">
                <input type="hidden" name="redirect_page" value="projects">
                <button class="btn danger" type="submit">Hapus Terpilih</button>
                <span class="bulk-count" data-bulk-count-for="bulk-projects-form">0 dipilih</span>
              </form>
              <div class="table-wrap">
                <table>
                  <thead><tr><th class="bulk-col"><input class="bulk-check bulk-check-all" type="checkbox" data-bulk-form="bulk-projects-form"></th><th>ID</th><th>Judul</th><th>Kategori</th><th>Link</th><th>Status</th><th>Aksi</th></tr></thead>
                  <tbody>
                  <?php foreach ($projectsPager['items'] as $project): ?>
                    <tr>
                      <td class="bulk-col"><input class="bulk-check bulk-item" type="checkbox" form="bulk-projects-form" data-bulk-form="bulk-projects-form" name="ids[]" value="<?= (int) $project['id'] ?>"></td>
                      <td><?= (int) $project['id'] ?></td>
                      <td>
                        <?php $projectImage = trim((string) ($project['image'] ?? '')); ?>
                        <div class="list-item-with-thumb">
                          <?php if ($projectImage !== ''): ?>
                            <img class="list-thumb" src="<?= e($projectImage) ?>" alt="<?= e((string) $project['title']) ?>" onerror="this.outerHTML='<div class=&quot;list-thumb placeholder&quot;>No Image</div>'">
                          <?php else: ?>
                            <div class="list-thumb placeholder">No Image</div>
                          <?php endif; ?>
                          <div class="list-item-content">
                            <strong><?= e((string) $project['title']) ?></strong>
                            <div class="muted"><?= e($projectImage !== '' ? $projectImage : '-') ?></div>
                            <div class="muted">Gallery: <?= (int) $project['gallery_count'] ?> gambar</div>
                          </div>
                        </div>
                      </td>
                      <td><?= e((string) $project['category']) ?></td>
                      <td><code><?= e(build_project_detail_url((string) $project['slug'])) ?></code></td>
                      <td><?= (int) $project['is_active'] === 1 ? '<span class="status approved">Aktif</span>' : '<span class="status pending">Nonaktif</span>' ?></td>
                      <td>
                        <div class="actions">
                          <a class="btn dark" href="/admin/?page=projects&edit=<?= (int) $project['id'] ?>">Edit</a>
                          <form method="post" onsubmit="return window.uiConfirmSubmit(event, this, 'Hapus proyek ini?', { title: 'Konfirmasi Hapus', okText: 'Hapus', cancelText: 'Batal', variant: 'danger', icon: '!' });">
                            <input type="hidden" name="action" value="delete_project">
                            <input type="hidden" name="id" value="<?= (int) $project['id'] ?>">
                            <button class="btn danger" type="submit">Hapus</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$projectsPager['items']): ?><tr><td colspan="7">Belum ada data proyek.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
              <?php render_pagination($projectsPager); ?>
            </section>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($activePage === 'articles'): ?>
        <div class="grid">
          <div class="stack">
            <section class="card">
              <h2><?= $editArticle ? 'Edit Artikel' : 'Tambah Artikel Baru' ?></h2>
              <p class="muted">Form dibuat sederhana supaya tim bisa input konten cepat: isi judul, ringkasan, konten, lalu publish.</p>
              <form method="post" enctype="multipart/form-data" class="admin-main-form settings-form settings-modern">
                <input type="hidden" name="action" value="save_article">
                <input type="hidden" name="redirect_page" value="articles">
                <input type="hidden" name="id" value="<?= (int) ($editArticle['id'] ?? 0) ?>">
                <input type="hidden" name="current_image" value="<?= e((string) ($editArticle['image'] ?? '')) ?>">

                <div class="form-grid">
                  <div>
                    <label>Judul Artikel</label>
                    <input name="title" required value="<?= e((string) ($editArticle['title'] ?? '')) ?>" placeholder="Contoh: Strategi Instalasi Ducting Lebih Efisien">
                  </div>
                  <div>
                    <label>Slug (opsional)</label>
                    <input name="slug" value="<?= e((string) ($editArticle['slug'] ?? '')) ?>" placeholder="strategi-instalasi-ducting-efisien">
                  </div>
                  <div>
                    <label>Penulis</label>
                    <input name="author_name" value="<?= e((string) ($editArticle['author_name'] ?? 'Tim MPS')) ?>" placeholder="Contoh: Tim MPS">
                  </div>
                  <div>
                    <label>Kategori</label>
                    <input name="category" value="<?= e((string) ($editArticle['category'] ?? 'Artikel')) ?>" placeholder="Contoh: Konstruksi">
                  </div>
                  <div>
                    <label>Tanggal Publish</label>
                    <?php $articlePublishedAt = (string) ($editArticle['published_at'] ?? ''); ?>
                    <input type="datetime-local" name="published_at" value="<?= e($articlePublishedAt !== '' ? str_replace(' ', 'T', substr($articlePublishedAt, 0, 16)) : '') ?>">
                    <div class="field-help">Kosongkan jika ingin pakai waktu sekarang.</div>
                  </div>
                  <div>
                    <label>Urutan Tampil</label>
                    <input type="number" min="0" name="sort_order" value="<?= (int) ($editArticle['sort_order'] ?? 0) ?>">
                  </div>
                </div>

                <label>Upload Gambar Card Artikel (JPG/PNG)</label>
                <div class="upload-file-widget">
                  <input class="sr-only-upload" type="file" name="image_file" id="article-image-file-input" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                  <label for="article-image-file-input" class="upload-file-trigger">
                    <i class="fa-solid fa-file-arrow-up"></i>
                    Pilih Gambar Card
                  </label>
                  <span id="article-image-file-name" class="upload-file-name">Belum ada file dipilih</span>
                </div>
                <div class="field-help"><?= $editArticle ? 'Kosongkan jika tidak ingin ganti gambar.' : 'Wajib diisi untuk artikel baru.' ?></div>
                <div class="upload-preview-box upload-preview-main" id="article-main-upload-preview">
                  <div class="upload-preview-empty">Belum ada file dipilih.</div>
                </div>
                <?php if ($editArticle && !empty($editArticle['image'])): ?>
                  <label>Gambar Card Saat Ini</label>
                  <div class="current-image-card">
                    <img src="<?= e((string) $editArticle['image']) ?>" alt="Gambar artikel">
                    <p class="current-image-path"><?= e((string) $editArticle['image']) ?></p>
                  </div>
                <?php endif; ?>

                <label>Ringkasan Artikel</label>
                <div class="editor-toolbar" id="article-excerpt-toolbar">
                  <div class="editor-group">
                    <select id="article-excerpt-font-size-select" class="editor-select" title="Ukuran Font Ringkasan">
                      <option value="">Uk Font</option>
                      <option value="14">14px</option>
                      <option value="16">16px</option>
                      <option value="18">18px</option>
                      <option value="20">20px</option>
                    </select>
                    <button type="button" class="editor-btn" data-cmd="bold"><b>B</b></button>
                    <button type="button" class="editor-btn" data-cmd="italic"><i>I</i></button>
                    <button type="button" class="editor-btn" data-cmd="underline"><u>U</u></button>
                    <button type="button" class="editor-btn" data-cmd="insertUnorderedList">Bullets</button>
                  </div>
                  <div class="editor-group">
                    <button type="button" class="editor-btn" data-cmd="justifyLeft">Left</button>
                    <button type="button" class="editor-btn" data-cmd="justifyCenter">Center</button>
                    <button type="button" class="editor-btn" data-cmd="justifyRight">Right</button>
                    <button type="button" class="editor-btn" data-link="1">Link</button>
                    <button type="button" class="editor-btn" data-cmd="removeFormat">Clear</button>
                  </div>
                </div>
                <div id="article-excerpt-surface" class="editor-surface" contenteditable="true" style="min-height:150px;"></div>
                <textarea name="excerpt" id="article-excerpt-hidden" required style="display:none;"><?= e((string) ($editArticle['excerpt'] ?? '')) ?></textarea>
                <div class="field-help">Ringkasan mendukung format ringan (bold/italic/link/alignment).</div>

                <label>Isi Artikel</label>
                <div class="editor-toolbar" id="article-editor-toolbar">
                  <div class="editor-group">
                    <button type="button" class="editor-btn label" data-block="p">Normal</button>
                    <button type="button" class="editor-btn" data-block="h1">H1</button>
                    <button type="button" class="editor-btn" data-block="h2">H2</button>
                    <button type="button" class="editor-btn" data-block="h3">H3</button>
                    <button type="button" class="editor-btn" data-block="h4">H4</button>
                  </div>
                  <div class="editor-group">
                    <select id="article-font-size-select" class="editor-select" title="Ukuran Font">
                      <option value="">Uk Font</option>
                      <option value="14">14px</option>
                      <option value="16">16px</option>
                      <option value="18">18px</option>
                      <option value="20">20px</option>
                      <option value="24">24px</option>
                      <option value="30">30px</option>
                    </select>
                    <button type="button" class="editor-btn" data-cmd="bold"><b>B</b></button>
                    <button type="button" class="editor-btn" data-cmd="italic"><i>I</i></button>
                    <button type="button" class="editor-btn" data-cmd="underline"><u>U</u></button>
                    <button type="button" class="editor-btn" data-cmd="insertUnorderedList">Bullets</button>
                    <button type="button" class="editor-btn" data-cmd="insertOrderedList">Number</button>
                  </div>
                  <div class="editor-group">
                    <button type="button" class="editor-btn" data-cmd="justifyLeft">Left</button>
                    <button type="button" class="editor-btn" data-cmd="justifyCenter">Center</button>
                    <button type="button" class="editor-btn" data-cmd="justifyRight">Right</button>
                  </div>
                  <div class="editor-group">
                    <button type="button" class="editor-btn" data-link="1">Link</button>
                    <button type="button" class="editor-btn" data-image-upload="1">Gambar</button>
                    <button type="button" class="editor-btn" data-image-align="left">Img Left</button>
                    <button type="button" class="editor-btn" data-image-align="center">Img Center</button>
                    <button type="button" class="editor-btn" data-image-align="right">Img Right</button>
                    <button type="button" class="editor-btn" data-image-size="35">Img S</button>
                    <button type="button" class="editor-btn" data-image-size="65">Img M</button>
                    <button type="button" class="editor-btn" data-image-size="100">Img L</button>
                    <button type="button" class="editor-btn" data-cmd="removeFormat">Clear</button>
                  </div>
                </div>
                <div id="article-editor-surface" class="editor-surface" contenteditable="true"></div>
                <div class="editor-meta">Tips: ketik seperti biasa lalu blok teks untuk bold/center/list. Tombol Gambar akan upload lalu sisip otomatis ke isi artikel.</div>
                <div id="article-image-controls" class="image-controls hidden-control">
                  <span>Ukuran Gambar:</span>
                  <input type="range" id="article-image-size-range" min="20" max="100" step="1" value="65">
                  <input type="number" id="article-image-size-input" min="20" max="100" step="1" value="65">
                  <span>%</span>
                  <button type="button" class="mini-btn" id="article-image-size-reset">Reset</button>
                </div>
                <input type="file" id="article-inline-image-file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" style="display:none;">
                <textarea name="content_html" id="article-content-html" style="display:none;"><?= e((string) ($editArticle['content_html'] ?? '')) ?></textarea>
                <div class="field-help">Editor ini lebih user-friendly, tidak perlu nulis HTML manual.</div>

                <label class="toggle-inline">
                  <input type="checkbox" name="is_active" <?= ((int) ($editArticle['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                  Tampilkan di website
                </label>
                <button class="btn brand" type="submit">Simpan Artikel</button>
              </form>
            </section>
          </div>

          <div class="stack">
            <section class="card">
              <h2>Daftar Artikel</h2>
              <div class="table-meta"><?= e(pager_summary_text($articlesPager)) ?></div>
              <form id="bulk-articles-form" class="bulk-bar" method="post" data-bulk-entity="artikel" onsubmit="return window.bulkConfirmDelete(event, this);">
                <input type="hidden" name="action" value="bulk_delete_articles">
                <input type="hidden" name="redirect_page" value="articles">
                <button class="btn danger" type="submit">Hapus Terpilih</button>
                <span class="bulk-count" data-bulk-count-for="bulk-articles-form">0 dipilih</span>
              </form>
              <div class="table-wrap">
                <table>
                  <thead><tr><th class="bulk-col"><input class="bulk-check bulk-check-all" type="checkbox" data-bulk-form="bulk-articles-form"></th><th>ID</th><th>Artikel</th><th>Kategori</th><th>Publish</th><th>Status</th><th>Aksi</th></tr></thead>
                  <tbody>
                  <?php foreach ($articlesPager['items'] as $article): ?>
                    <tr>
                      <td class="bulk-col"><input class="bulk-check bulk-item" type="checkbox" form="bulk-articles-form" data-bulk-form="bulk-articles-form" name="ids[]" value="<?= (int) $article['id'] ?>"></td>
                      <td><?= (int) $article['id'] ?></td>
                      <td>
                        <?php $articleImage = trim((string) ($article['image'] ?? '')); ?>
                        <div class="list-item-with-thumb">
                          <?php if ($articleImage !== ''): ?>
                            <img class="list-thumb" src="<?= e($articleImage) ?>" alt="<?= e((string) $article['title']) ?>" onerror="this.outerHTML='<div class=&quot;list-thumb placeholder&quot;>No Image</div>'">
                          <?php else: ?>
                            <div class="list-thumb placeholder">No Image</div>
                          <?php endif; ?>
                          <div class="list-item-content">
                            <strong><?= e((string) $article['title']) ?></strong>
                            <div class="muted">Penulis: <?= e((string) ($article['author_name'] ?? 'Admin')) ?></div>
                            <div class="muted"><?= e($articleImage !== '' ? $articleImage : '-') ?></div>
                            <div class="muted"><code>/artikel-detail/?slug=<?= e((string) $article['slug']) ?></code></div>
                          </div>
                        </div>
                      </td>
                      <td><?= e((string) ($article['category'] ?? 'Artikel')) ?></td>
                      <td><?= e((string) ($article['published_at'] ?? '-')) ?></td>
                      <td><?= (int) $article['is_active'] === 1 ? '<span class="status approved">Aktif</span>' : '<span class="status pending">Nonaktif</span>' ?></td>
                      <td>
                        <div class="actions">
                          <a class="btn dark" href="/admin/?page=articles&edit=<?= (int) $article['id'] ?>">Edit</a>
                          <form method="post" onsubmit="return window.uiConfirmSubmit(event, this, 'Hapus artikel ini?', { title: 'Konfirmasi Hapus', okText: 'Hapus', cancelText: 'Batal', variant: 'danger', icon: '!' });">
                            <input type="hidden" name="action" value="delete_article">
                            <input type="hidden" name="id" value="<?= (int) $article['id'] ?>">
                            <button class="btn danger" type="submit">Hapus</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$articlesPager['items']): ?><tr><td colspan="7">Belum ada artikel. Tambahkan artikel pertama Anda.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
              <?php render_pagination($articlesPager); ?>
            </section>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($activePage === 'testimonials'): ?>
        <div class="grid">
          <div class="stack">
            <section class="card">
              <h2><?= $editTestimonial ? 'Edit Testimonial' : 'Tambah Testimonial' ?></h2>
              <p class="muted">Data ini tampil di section testimonial homepage. Isi manual nama, perusahaan, quote, foto, dan logo.</p>
              <form method="post" enctype="multipart/form-data" class="admin-main-form settings-form settings-modern">
                <input type="hidden" name="action" value="save_testimonial">
                <input type="hidden" name="redirect_page" value="testimonials">
                <input type="hidden" name="id" value="<?= (int) ($editTestimonial['id'] ?? 0) ?>">
                <input type="hidden" name="current_avatar_image" value="<?= e((string) ($editTestimonial['avatar_image'] ?? '')) ?>">
                <input type="hidden" name="current_brand_logo" value="<?= e((string) ($editTestimonial['brand_logo'] ?? '')) ?>">
                <div class="form-grid">
                  <div>
                    <label>Nama Klien</label>
                    <input name="name" required value="<?= e((string) ($editTestimonial['name'] ?? '')) ?>" placeholder="Contoh: Daniel Kobak">
                  </div>
                  <div>
                    <label>Perusahaan/Brand</label>
                    <input name="company" required value="<?= e((string) ($editTestimonial['company'] ?? '')) ?>" placeholder="Contoh: MARKWTO.INC">
                  </div>
                  <div>
                    <label>Urutan Tampil</label>
                    <input type="number" min="0" name="sort_order" value="<?= (int) ($editTestimonial['sort_order'] ?? 0) ?>">
                  </div>
                  <div>
                    <label>Status</label>
                    <label class="toggle-inline">
                      <input type="checkbox" name="is_active" <?= ((int) ($editTestimonial['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                      Tampilkan di website
                    </label>
                  </div>
                </div>

                <label>Isi Testimonial</label>
                <div id="testimonial-quote-surface" class="editor-surface" contenteditable="true" spellcheck="false" style="min-height:130px;"></div>
                <textarea name="quote_text" id="testimonial-quote-hidden" style="display:none;" required><?= e((string) ($editTestimonial['quote_text'] ?? '')) ?></textarea>
                <div class="field-help">Disimpan sebagai teks biasa (tanpa HTML). Cocok untuk kutipan singkat dari klien.</div>

                <div class="form-grid">
                  <div>
                    <label>Upload Foto Klien (JPG/PNG)</label>
                    <div class="upload-file-widget">
                      <input class="sr-only-upload" type="file" name="avatar_file" id="testimonial-avatar-file-input" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                      <label for="testimonial-avatar-file-input" class="upload-file-trigger">
                        <i class="fa-solid fa-file-arrow-up"></i>
                        Pilih Foto
                      </label>
                      <span id="testimonial-avatar-file-name" class="upload-file-name">Belum ada file dipilih</span>
                    </div>
                    <div class="field-help">Format .jpg/.jpeg/.png. <?= $editTestimonial ? 'Kosongkan jika tidak ingin ganti foto.' : 'Wajib diisi untuk testimonial baru.' ?></div>
                    <div class="upload-preview-box upload-preview-main" id="testimonial-avatar-upload-preview">
                      <div class="upload-preview-empty">Belum ada file dipilih.</div>
                    </div>
                    <?php if ($editTestimonial && !empty($editTestimonial['avatar_image'])): ?>
                      <div class="gallery-preview" style="max-width:220px;">
                        <img src="<?= e((string) $editTestimonial['avatar_image']) ?>" alt="Foto klien" style="width:100%;height:150px;object-fit:cover;border-radius:10px;">
                      </div>
                    <?php endif; ?>
                  </div>
                  <div>
                    <label>Upload Logo Brand (JPG/PNG)</label>
                    <div class="upload-file-widget">
                      <input class="sr-only-upload" type="file" name="brand_logo_file" id="testimonial-logo-file-input" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                      <label for="testimonial-logo-file-input" class="upload-file-trigger">
                        <i class="fa-solid fa-file-arrow-up"></i>
                        Pilih Logo
                      </label>
                      <span id="testimonial-logo-file-name" class="upload-file-name">Belum ada file dipilih</span>
                    </div>
                    <div class="field-help">Format .jpg/.jpeg/.png. <?= $editTestimonial ? 'Kosongkan jika tidak ingin ganti logo.' : 'Wajib diisi untuk testimonial baru.' ?></div>
                    <div class="upload-preview-box upload-preview-main" id="testimonial-logo-upload-preview">
                      <div class="upload-preview-empty">Belum ada file dipilih.</div>
                    </div>
                    <?php if ($editTestimonial && !empty($editTestimonial['brand_logo'])): ?>
                      <div class="gallery-preview" style="max-width:220px;">
                        <img src="<?= e((string) $editTestimonial['brand_logo']) ?>" alt="Logo brand" style="width:100%;height:150px;object-fit:contain;border-radius:10px;background:#fff;padding:8px;">
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <button class="btn brand" type="submit">Simpan Testimonial</button>
              </form>
            </section>
          </div>

          <div class="stack">
            <section class="card">
              <h2>Daftar Testimonial</h2>
              <div class="table-meta"><?= e(pager_summary_text($testimonialsPager)) ?></div>
              <form id="bulk-testimonials-form" class="bulk-bar" method="post" data-bulk-entity="testimonial" onsubmit="return window.bulkConfirmDelete(event, this);">
                <input type="hidden" name="action" value="bulk_delete_testimonials">
                <input type="hidden" name="redirect_page" value="testimonials">
                <button class="btn danger" type="submit">Hapus Terpilih</button>
                <span class="bulk-count" data-bulk-count-for="bulk-testimonials-form">0 dipilih</span>
              </form>
              <div class="table-wrap">
                <table>
                  <thead><tr><th class="bulk-col"><input class="bulk-check bulk-check-all" type="checkbox" data-bulk-form="bulk-testimonials-form"></th><th>ID</th><th>Klien</th><th>Perusahaan</th><th>Status</th><th>Aksi</th></tr></thead>
                  <tbody>
                  <?php foreach ($testimonialsPager['items'] as $item): ?>
                    <tr>
                      <td class="bulk-col"><input class="bulk-check bulk-item" type="checkbox" form="bulk-testimonials-form" data-bulk-form="bulk-testimonials-form" name="ids[]" value="<?= (int) $item['id'] ?>"></td>
                      <td><?= (int) $item['id'] ?></td>
                      <td>
                        <strong><?= e((string) $item['name']) ?></strong>
                        <div class="muted"><?= e((string) $item['avatar_image']) ?></div>
                      </td>
                      <td>
                        <strong><?= e((string) $item['company']) ?></strong>
                        <div class="muted"><?= e((string) $item['brand_logo']) ?></div>
                      </td>
                      <td><?= (int) $item['is_active'] === 1 ? '<span class="status approved">Aktif</span>' : '<span class="status pending">Nonaktif</span>' ?></td>
                      <td>
                        <div class="actions">
                          <a class="btn dark" href="/admin/?page=testimonials&edit=<?= (int) $item['id'] ?>">Edit</a>
                          <form method="post" onsubmit="return window.uiConfirmSubmit(event, this, 'Hapus testimonial ini?', { title: 'Konfirmasi Hapus', okText: 'Hapus', cancelText: 'Batal', variant: 'danger', icon: '!' });">
                            <input type="hidden" name="action" value="delete_testimonial">
                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <button class="btn danger" type="submit">Hapus</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$testimonialsPager['items']): ?><tr><td colspan="6">Belum ada testimonial. Tambahkan data pertama.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
              <?php render_pagination($testimonialsPager); ?>
            </section>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($activePage === 'reviews'): ?>
        <section class="card">
          <h2>Semua Review</h2>
          <div class="table-meta"><?= e(pager_summary_text($reviewsPager)) ?></div>
          <form id="bulk-reviews-form" class="bulk-bar" method="post" data-bulk-entity="review" onsubmit="return window.bulkConfirmDelete(event, this);">
            <input type="hidden" name="action" value="bulk_delete_reviews">
            <input type="hidden" name="redirect_page" value="reviews">
            <button class="btn danger" type="submit">Hapus Terpilih</button>
            <span class="bulk-count" data-bulk-count-for="bulk-reviews-form">0 dipilih</span>
          </form>
          <div class="table-wrap">
            <table>
              <thead><tr><th class="bulk-col"><input class="bulk-check bulk-check-all" type="checkbox" data-bulk-form="bulk-reviews-form"></th><th>Tipe</th><th>Item</th><th>Nama</th><th>Review</th><th>Rating</th><th>Status</th><th>Aksi</th></tr></thead>
              <tbody>
              <?php foreach ($reviewsPager['items'] as $review): ?>
                <tr>
                  <td class="bulk-col"><input class="bulk-check bulk-item" type="checkbox" form="bulk-reviews-form" data-bulk-form="bulk-reviews-form" name="review_keys[]" value="<?= e((string) ($review['review_type'] ?? 'product')) ?>:<?= (int) $review['id'] ?>"></td>
                  <td><?= e((string) ($review['type_label'] ?? 'Produk')) ?></td>
                  <td><?= e((string) ($review['item_name'] ?? '')) ?></td>
                  <td><?= e($review['reviewer_name']) ?></td>
                  <td><?= nl2br(e($review['review_text'])) ?></td>
                  <td><?= $review['rating'] !== null ? (int) $review['rating'] . '/5' : '-' ?></td>
                  <td>
                    <?php if ((int) $review['is_approved'] === 1): ?>
                      <span class="status approved">Approved</span>
                    <?php else: ?>
                      <span class="status pending">Pending</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="actions">
                      <?php if ((int) $review['is_approved'] === 0): ?>
                        <form method="post">
                          <input type="hidden" name="action" value="approve_review">
                          <input type="hidden" name="redirect_page" value="reviews">
                          <input type="hidden" name="review_type" value="<?= e((string) ($review['review_type'] ?? 'product')) ?>">
                          <input type="hidden" name="id" value="<?= (int) $review['id'] ?>">
                          <button class="btn dark" type="submit">Approve</button>
                        </form>
                      <?php endif; ?>
                      <form method="post" onsubmit="return window.uiConfirmSubmit(event, this, 'Hapus review ini?', { title: 'Konfirmasi Hapus', okText: 'Hapus', cancelText: 'Batal', variant: 'danger', icon: '!' });">
                        <input type="hidden" name="action" value="delete_review">
                        <input type="hidden" name="redirect_page" value="reviews">
                        <input type="hidden" name="review_type" value="<?= e((string) ($review['review_type'] ?? 'product')) ?>">
                        <input type="hidden" name="id" value="<?= (int) $review['id'] ?>">
                        <button class="btn danger" type="submit">Hapus</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$reviewsPager['items']): ?>
                <tr><td colspan="8">Belum ada review produk/layanan.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?php render_pagination($reviewsPager); ?>
        </section>
      <?php endif; ?>

      <?php if ($activePage === 'team'): ?>
        <div class="grid">
          <div class="stack">
            <section class="card">
              <h2><?= $editTeam ? 'Edit Anggota Tim' : 'Tambah Anggota Tim' ?></h2>
              <p class="muted">Data ini akan tampil otomatis di section <strong>Expert Team Members</strong> pada homepage.</p>
              <form method="post" enctype="multipart/form-data" class="admin-main-form settings-form settings-modern">
                <input type="hidden" name="action" value="save_team_member">
                <input type="hidden" name="redirect_page" value="team">
                <input type="hidden" name="id" value="<?= (int) ($editTeam['id'] ?? 0) ?>">
                <input type="hidden" name="current_image" value="<?= e((string) ($editTeam['image'] ?? '')) ?>">

                <div class="form-grid">
                  <div>
                    <label>Nama Lengkap</label>
                    <input name="name" required value="<?= e((string) ($editTeam['name'] ?? '')) ?>" placeholder="Contoh: Budi Santoso">
                  </div>
                  <div>
                    <label>Jabatan</label>
                    <input name="role" required value="<?= e((string) ($editTeam['role'] ?? '')) ?>" placeholder="Contoh: Supervisor Project">
                  </div>
                  <div>
                    <label>Urutan Tampil</label>
                    <input type="number" min="0" name="sort_order" value="<?= (int) ($editTeam['sort_order'] ?? 0) ?>">
                    <div class="field-help">Angka kecil tampil lebih dulu (misal 1, 2, 3).</div>
                  </div>
                  <div>
                    <label>Status</label>
                    <label class="toggle-inline">
                      <input type="checkbox" name="is_active" <?= ((int) ($editTeam['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                      Tampilkan di website
                    </label>
                  </div>
                </div>

                    <label>Upload Foto Tim (JPG/PNG)</label>
                    <div class="upload-file-widget">
                      <input class="sr-only-upload" type="file" name="image_file" id="team-image-file-input" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                      <label for="team-image-file-input" class="upload-file-trigger">
                        <i class="fa-solid fa-file-arrow-up"></i>
                        Pilih Foto
                      </label>
                      <span id="team-image-file-name" class="upload-file-name">Belum ada file dipilih</span>
                    </div>
                    <div class="field-help">Format .jpg/.jpeg/.png. <?= $editTeam ? 'Kosongkan jika tidak ingin ganti foto.' : 'Wajib diisi untuk anggota baru.' ?></div>
                <div class="upload-preview-box upload-preview-main" id="team-main-upload-preview">
                  <div class="upload-preview-empty">Belum ada file dipilih.</div>
                </div>
                <?php if ($editTeam && !empty($editTeam['image'])): ?>
                  <label>Foto Tim Saat Ini</label>
                  <div class="current-image-card">
                    <img src="<?= e((string) $editTeam['image']) ?>" alt="Foto tim">
                    <p class="current-image-path"><?= e((string) $editTeam['image']) ?></p>
                  </div>
                <?php endif; ?>

                <div class="form-grid">
                  <div>
                    <label>Link Facebook (opsional)</label>
                    <input name="social_facebook" value="<?= e((string) ($editTeam['social_facebook'] ?? '')) ?>" placeholder="https://facebook.com/...">
                  </div>
                  <div>
                    <label>Link LinkedIn (opsional)</label>
                    <input name="social_linkedin" value="<?= e((string) ($editTeam['social_linkedin'] ?? '')) ?>" placeholder="https://linkedin.com/in/...">
                  </div>
                  <div>
                    <label>Link YouTube (opsional)</label>
                    <input name="social_youtube" value="<?= e((string) ($editTeam['social_youtube'] ?? '')) ?>" placeholder="https://youtube.com/...">
                  </div>
                  <div>
                    <label>No. WhatsApp (opsional)</label>
                    <input name="social_whatsapp" value="<?= e((string) ($editTeam['social_whatsapp'] ?? '')) ?>" placeholder="62812xxxxxxx">
                    <div class="field-help">Isi angka saja, nanti otomatis jadi link WhatsApp.</div>
                  </div>
                </div>
                <button class="btn brand" type="submit">Simpan Anggota Tim</button>
              </form>
            </section>
          </div>

          <div class="stack">
            <section class="card">
              <h2>Daftar Tim</h2>
              <div class="table-meta"><?= e(pager_summary_text($teamPager)) ?></div>
              <form id="bulk-team-form" class="bulk-bar" method="post" data-bulk-entity="anggota tim" onsubmit="return window.bulkConfirmDelete(event, this);">
                <input type="hidden" name="action" value="bulk_delete_team">
                <input type="hidden" name="redirect_page" value="team">
                <button class="btn danger" type="submit">Hapus Terpilih</button>
                <span class="bulk-count" data-bulk-count-for="bulk-team-form">0 dipilih</span>
              </form>
              <div class="table-wrap">
                <table>
                  <thead><tr><th class="bulk-col"><input class="bulk-check bulk-check-all" type="checkbox" data-bulk-form="bulk-team-form"></th><th>ID</th><th>Foto</th><th>Nama</th><th>Jabatan</th><th>Urutan</th><th>Status</th><th>Aksi</th></tr></thead>
                  <tbody>
                  <?php foreach ($teamPager['items'] as $member): ?>
                    <tr>
                      <td class="bulk-col"><input class="bulk-check bulk-item" type="checkbox" form="bulk-team-form" data-bulk-form="bulk-team-form" name="ids[]" value="<?= (int) $member['id'] ?>"></td>
                      <td><?= (int) $member['id'] ?></td>
                      <td><img src="<?= e((string) $member['image']) ?>" alt="foto" style="width:58px;height:58px;border-radius:8px;object-fit:cover;border:1px solid #e2e8f0;"></td>
                      <td><?= e((string) $member['name']) ?></td>
                      <td><?= e((string) $member['role']) ?></td>
                      <td><?= (int) $member['sort_order'] ?></td>
                      <td>
                        <?php if ((int) $member['is_active'] === 1): ?>
                          <span class="status approved">Aktif</span>
                        <?php else: ?>
                          <span class="status pending">Nonaktif</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div class="actions">
                          <a class="btn dark" href="/admin/?page=team&edit=<?= (int) $member['id'] ?>">Edit</a>
                          <form method="post" onsubmit="return window.uiConfirmSubmit(event, this, 'Hapus anggota tim ini?', { title: 'Konfirmasi Hapus', okText: 'Hapus', cancelText: 'Batal', variant: 'danger', icon: '!' });">
                            <input type="hidden" name="action" value="delete_team_member">
                            <input type="hidden" name="redirect_page" value="team">
                            <input type="hidden" name="id" value="<?= (int) $member['id'] ?>">
                            <button class="btn danger" type="submit">Hapus</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$teamPager['items']): ?>
                    <tr><td colspan="8">Belum ada data tim. Tambahkan minimal 1 anggota.</td></tr>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <?php render_pagination($teamPager); ?>
            </section>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($activePage === 'settings'): ?>
        <div class="grid">
          <div class="stack">
            <section class="card">
              <h2>Pengaturan Sosial & WhatsApp</h2>
              <p class="muted">Data ini dipakai otomatis di semua halaman website (header, footer, mobile menu, dan tombol WhatsApp).</p>
              <form method="post" enctype="multipart/form-data" class="settings-form settings-modern">
                <input type="hidden" name="action" value="save_site_settings">
                <input type="hidden" name="redirect_page" value="settings">
                <div class="form-grid">
                  <div>
                    <label>Facebook URL</label>
                    <input name="social_facebook" value="<?= e((string) ($siteSettings['social_facebook'] ?? '')) ?>" placeholder="https://facebook.com/...">
                  </div>
                  <div>
                    <label>Twitter/X URL</label>
                    <input name="social_twitter" value="<?= e((string) ($siteSettings['social_twitter'] ?? '')) ?>" placeholder="https://x.com/...">
                  </div>
                  <div>
                    <label>Instagram URL</label>
                    <input name="social_instagram" value="<?= e((string) ($siteSettings['social_instagram'] ?? '')) ?>" placeholder="https://instagram.com/...">
                  </div>
                  <div>
                    <label>YouTube URL</label>
                    <input name="social_youtube" value="<?= e((string) ($siteSettings['social_youtube'] ?? '')) ?>" placeholder="https://youtube.com/...">
                  </div>
                  <div>
                    <label>LinkedIn URL</label>
                    <input name="social_linkedin" value="<?= e((string) ($siteSettings['social_linkedin'] ?? '')) ?>" placeholder="https://linkedin.com/company/...">
                  </div>
                  <div>
                    <label>WhatsApp URL (opsional)</label>
                    <input name="social_whatsapp" value="<?= e((string) ($siteSettings['social_whatsapp'] ?? '')) ?>" placeholder="https://wa.me/62812xxxxxxx">
                  </div>
                  <div>
                    <label>Nomor WhatsApp Utama</label>
                    <input name="contact_whatsapp_number" value="<?= e((string) ($siteSettings['contact_whatsapp_number'] ?? '')) ?>" placeholder="62812xxxxxxx">
                    <div class="field-help">Isi angka saja. Nomor ini dipakai untuk tombol konsultasi WhatsApp otomatis.</div>
                  </div>
                </div>
                <h2 class="section-title">Header Top Bar</h2>
                <p class="muted">Ini untuk nomor telepon dan email di bagian paling atas website.</p>
                <div class="form-grid">
                  <div>
                    <label>Header Telepon Utama</label>
                    <input name="header_phone_primary" value="<?= e((string) ($siteSettings['header_phone_primary'] ?? '')) ?>" placeholder="08xxxxxxxxxx">
                  </div>
                  <div>
                    <label>Header Telepon Kedua</label>
                    <input name="header_phone_secondary" value="<?= e((string) ($siteSettings['header_phone_secondary'] ?? '')) ?>" placeholder="08xxxxxxxxxx">
                  </div>
                  <div>
                    <label>Header Email</label>
                    <input name="header_email_primary" value="<?= e((string) ($siteSettings['header_email_primary'] ?? '')) ?>" placeholder="info@perusahaananda.com">
                  </div>
                </div>
                <h2 class="section-title">Kontrol Visibility Menu & Section</h2>
                <p class="muted">Atur bagian menu utama dan section homepage agar bisa ditampilkan/disembunyikan secara dinamis.</p>
                <div class="form-grid">
                  <div class="toggle-field span-2">
                    <label>
                      <input type="checkbox" name="home_show_team_section" value="1" <?= ((string) ($siteSettings['home_show_team_section'] ?? '1') !== '0') ? 'checked' : '' ?>>
                      <span>Tampilkan section <strong>"Expert Team Members"</strong> di Homepage</span>
                    </label>
                    <div class="field-help">Uncheck untuk menyembunyikan section tim dari homepage.</div>
                  </div>
                  <div class="toggle-field">
                    <label>
                      <input type="checkbox" name="show_menu_layanan" value="1" <?= ((string) ($siteSettings['show_menu_layanan'] ?? '1') !== '0') ? 'checked' : '' ?>>
                      <span>Tampilkan menu <strong>Layanan</strong></span>
                    </label>
                    <div class="field-help">Berlaku untuk menu header desktop dan mobile di halaman utama/detail.</div>
                  </div>
                  <div class="toggle-field">
                    <label>
                      <input type="checkbox" name="show_menu_produk" value="1" <?= ((string) ($siteSettings['show_menu_produk'] ?? '1') !== '0') ? 'checked' : '' ?>>
                      <span>Tampilkan menu <strong>Produk</strong></span>
                    </label>
                    <div class="field-help">Berlaku untuk menu header desktop dan mobile di halaman utama/detail.</div>
                  </div>
                  <div class="toggle-field">
                    <label>
                      <input type="checkbox" name="show_menu_proyek" value="1" <?= ((string) ($siteSettings['show_menu_proyek'] ?? '1') !== '0') ? 'checked' : '' ?>>
                      <span>Tampilkan menu <strong>Proyek</strong></span>
                    </label>
                    <div class="field-help">Berlaku untuk menu header desktop dan mobile di halaman utama/detail.</div>
                  </div>
                  <div class="toggle-field">
                    <label>
                      <input type="checkbox" name="show_menu_artikel" value="1" <?= ((string) ($siteSettings['show_menu_artikel'] ?? '1') !== '0') ? 'checked' : '' ?>>
                      <span>Tampilkan menu <strong>Artikel</strong></span>
                    </label>
                    <div class="field-help">Berlaku untuk menu header desktop dan mobile di halaman utama/detail.</div>
                  </div>
                  <div class="toggle-field">
                    <label>
                      <input type="checkbox" name="show_menu_kontak" value="1" <?= ((string) ($siteSettings['show_menu_kontak'] ?? '1') !== '0') ? 'checked' : '' ?>>
                      <span>Tampilkan menu <strong>Kontak</strong></span>
                    </label>
                    <div class="field-help">Berlaku untuk menu header desktop dan mobile di halaman utama/detail.</div>
                  </div>
                  <div class="toggle-field">
                    <label>
                      <input type="checkbox" name="show_menu_tentang" value="1" <?= ((string) ($siteSettings['show_menu_tentang'] ?? '1') !== '0') ? 'checked' : '' ?>>
                      <span>Tampilkan menu <strong>Tentang</strong></span>
                    </label>
                    <div class="field-help">Berlaku untuk menu header desktop dan mobile di halaman utama/detail.</div>
                  </div>
                </div>
                <h2 class="section-title">Footer Contact Info</h2>
                <p class="muted">Ini untuk blok: Call Us Now, Office Time, Need Support, dan Our Address.</p>
                <div class="form-grid">
                  <div>
                    <label>Telepon Utama</label>
                    <input name="footer_phone_primary" value="<?= e((string) ($siteSettings['footer_phone_primary'] ?? '')) ?>" placeholder="0856xxxxxxx atau +62...">
                  </div>
                  <div>
                    <label>Telepon Kedua</label>
                    <input name="footer_phone_secondary" value="<?= e((string) ($siteSettings['footer_phone_secondary'] ?? '')) ?>" placeholder="021-xxxxxxx">
                  </div>
                  <div>
                    <label>Jam Kantor Baris 1</label>
                    <input name="footer_office_hours_1" value="<?= e((string) ($siteSettings['footer_office_hours_1'] ?? '')) ?>" placeholder="Mon-Fri: 9:00 am to 5:00 pm">
                  </div>
                  <div>
                    <label>Jam Kantor Baris 2</label>
                    <input name="footer_office_hours_2" value="<?= e((string) ($siteSettings['footer_office_hours_2'] ?? '')) ?>" placeholder="Sat: 9:00 am to 2:00 pm">
                  </div>
                  <div>
                    <label>Email Support Utama</label>
                    <input name="footer_support_email_primary" value="<?= e((string) ($siteSettings['footer_support_email_primary'] ?? '')) ?>" placeholder="support@domain.com">
                  </div>
                  <div>
                    <label>Email Support Kedua</label>
                    <input name="footer_support_email_secondary" value="<?= e((string) ($siteSettings['footer_support_email_secondary'] ?? '')) ?>" placeholder="help@domain.com">
                  </div>
                  <div>
                    <label>Alamat Baris 1</label>
                    <input name="footer_address_1" value="<?= e((string) ($siteSettings['footer_address_1'] ?? '')) ?>" placeholder="Jl. Contoh No. 1, Jakarta">
                  </div>
                  <div>
                    <label>Alamat Baris 2</label>
                    <input name="footer_address_2" value="<?= e((string) ($siteSettings['footer_address_2'] ?? '')) ?>" placeholder="Indonesia 12345">
                  </div>
                </div>
                <h2 class="section-title">Contact Page Section</h2>
                <p class="muted">Ini untuk section "Get In Touch" di halaman kontak.</p>
                <div class="form-grid">
                  <div>
                    <label>Contact Pretitle</label>
                    <input name="contact_section_pretitle" value="<?= e((string) ($siteSettings['contact_section_pretitle'] ?? '')) ?>" placeholder="Get In Touch">
                  </div>
                  <div>
                    <label>Contact Title</label>
                    <input name="contact_section_title" value="<?= e((string) ($siteSettings['contact_section_title'] ?? '')) ?>" placeholder="We are always ready to help you...">
                  </div>
                  <div>
                    <label>Contact Description</label>
                    <input name="contact_section_description" value="<?= e((string) ($siteSettings['contact_section_description'] ?? '')) ?>" placeholder="Deskripsi singkat section kontak">
                  </div>
                  <div>
                    <label>Title Card Call Center</label>
                    <input name="contact_card_call_title" value="<?= e((string) ($siteSettings['contact_card_call_title'] ?? '')) ?>" placeholder="Call Center">
                  </div>
                  <div>
                    <label>Title Card Office</label>
                    <input name="contact_card_office_title" value="<?= e((string) ($siteSettings['contact_card_office_title'] ?? '')) ?>" placeholder="Our Office">
                  </div>
                  <div>
                    <label>Upload Company Profile PDF</label>
                    <input type="hidden" name="company_profile_pdf_url" value="<?= e((string) ($siteSettings['company_profile_pdf_url'] ?? '')) ?>">
                    <div class="upload-pdf-widget">
                      <input class="sr-only-upload" type="file" name="company_profile_pdf_file" id="company-profile-pdf-file" accept="application/pdf,.pdf">
                      <label for="company-profile-pdf-file" class="upload-pdf-trigger">
                        <i class="fa-solid fa-file-arrow-up"></i>
                        Pilih File PDF
                      </label>
                      <span id="company-profile-file-name" class="upload-file-name">Belum ada file PDF dipilih</span>
                      <?php if ((string) ($siteSettings['company_profile_pdf_url'] ?? '') !== ''): ?>
                        <div class="active-file-note">File aktif:
                          <a href="<?= e((string) $siteSettings['company_profile_pdf_url']) ?>" target="_blank" rel="noopener">Lihat PDF saat ini</a>
                        </div>
                      <?php endif; ?>
                      <input type="hidden" name="remove_company_profile_pdf" id="remove-company-profile-pdf" value="0">
                      <div class="upload-actions-row">
                        <button class="btn danger" type="button" id="company-profile-delete-btn">Hapus PDF Aktif</button>
                        <button class="btn light" type="button" id="company-profile-clear-btn">Reset Pilihan File</button>
                      </div>
                      <div class="field-help">Upload file baru untuk mengganti company profile PDF di halaman Tentang.</div>
                    </div>
                    <?php if ((string) ($siteSettings['company_profile_pdf_url'] ?? '') !== ''): ?>
                      <div class="field-help">Link aktif saat ini: <code><?= e((string) $siteSettings['company_profile_pdf_url']) ?></code></div>
                    <?php else: ?>
                      <div class="field-help">Belum ada PDF aktif. Upload file agar tombol unduh tampil di halaman Tentang.</div>
                    <?php endif; ?>
                  </div>
                  <div>
                    <label>Map Embed URL (disarankan)</label>
                    <input name="map_embed_url" value="<?= e((string) ($siteSettings['map_embed_url'] ?? '')) ?>" placeholder="https://www.google.com/maps/embed?pb=...">
                    <div class="field-help">Bisa isi URL embed langsung atau tempel iframe penuh (otomatis diambil src).</div>
                  </div>
                  <div>
                    <label>Map Latitude</label>
                    <input name="map_lat" value="<?= e((string) ($siteSettings['map_lat'] ?? '')) ?>" placeholder="-6.2088">
                  </div>
                  <div>
                    <label>Map Longitude</label>
                    <input name="map_lng" value="<?= e((string) ($siteSettings['map_lng'] ?? '')) ?>" placeholder="106.8456">
                  </div>
                  <div>
                    <label>Map Zoom</label>
                    <input name="map_zoom" value="<?= e((string) ($siteSettings['map_zoom'] ?? '')) ?>" placeholder="14">
                  </div>
                </div>
                <button class="btn brand" type="submit">Simpan Pengaturan</button>
              </form>
            </section>
          </div>
          <div class="stack">
            <section class="card">
              <h2>Konfigurasi Aktif</h2>
              <p class="muted">Untuk keamanan, ubah kredensial default di file konfigurasi sebelum deploy production.</p>
              <p><strong>Database Host:</strong> <code><?= e(DB_HOST) ?></code></p>
              <p><strong>Database Port:</strong> <code><?= (int) DB_PORT ?></code></p>
              <p><strong>Database Name:</strong> <code><?= e(DB_NAME) ?></code></p>
              <p><strong>Admin User:</strong> <code><?= e(ADMIN_USER) ?></code></p>
            </section>
            <section class="card">
              <h2>Checklist Produksi</h2>
              <ul>
                <li>Ganti <code>ADMIN_PASS</code> dengan password kuat.</li>
                <li>Gunakan akun MySQL khusus selain <code>root</code>.</li>
                <li>Aktifkan SSL di hosting dan backup database berkala.</li>
                <li>Batasi akses URL admin jika memungkinkan.</li>
              </ul>
            </section>
          </div>
        </div>
      <?php endif; ?>
    </main>
  </div>
<?php if ($message !== ''): ?>
<script>
  window.addEventListener('load', function () {
    if (window.uiAlert) {
      window.uiAlert(<?= json_encode($message, JSON_UNESCAPED_UNICODE) ?>, { title: 'Info' });
    } else {
      alert(<?= json_encode($message, JSON_UNESCAPED_UNICODE) ?>);
    }
  });
</script>
<?php endif; ?>
<?php if ($error !== ''): ?>
<script>
  window.addEventListener('load', function () {
    if (window.uiAlert) {
      window.uiAlert(<?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>, { title: 'Perhatian' });
    } else {
      alert(<?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>);
    }
  });
</script>
<?php endif; ?>

<div id="ui-modal" class="ui-modal" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="ui-modal__backdrop" data-ui-close="1"></div>
  <div class="ui-modal__panel" role="document" aria-labelledby="ui-modal-title" aria-describedby="ui-modal-desc">
    <div class="ui-modal__top">
      <div class="ui-modal__icon" id="ui-modal-icon">!</div>
      <div>
        <div class="ui-modal__title" id="ui-modal-title">Konfirmasi</div>
        <div class="ui-modal__desc" id="ui-modal-desc">...</div>
      </div>
    </div>
    <div class="ui-modal__divider"></div>
    <div class="ui-modal__actions">
      <button type="button" class="ui-modal__btn" id="ui-modal-cancel">Batal</button>
      <button type="button" class="ui-modal__btn primary" id="ui-modal-ok">OK</button>
    </div>
  </div>
</div>
<script>
  (function () {
    var modalEl = document.getElementById('ui-modal');
    var modalTitleEl = document.getElementById('ui-modal-title');
    var modalDescEl = document.getElementById('ui-modal-desc');
    var modalIconEl = document.getElementById('ui-modal-icon');
    var modalOkBtn = document.getElementById('ui-modal-ok');
    var modalCancelBtn = document.getElementById('ui-modal-cancel');
    var lastActiveEl = null;
    var pendingResolve = null;

    var closeModal = function () {
      if (!modalEl) return;
      modalEl.setAttribute('data-open', '0');
      modalEl.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      if (pendingResolve) {
        var fn = pendingResolve;
        pendingResolve = null;
        fn(false);
      }
      if (lastActiveEl && typeof lastActiveEl.focus === 'function') {
        lastActiveEl.focus();
      }
    };

    var openModal = function (opts, resolve) {
      if (!modalEl || !modalTitleEl || !modalDescEl || !modalOkBtn || !modalCancelBtn) {
        resolve(false);
        return;
      }

      lastActiveEl = document.activeElement;
      pendingResolve = resolve;

      var title = (opts && opts.title) ? String(opts.title) : 'Konfirmasi';
      var message = (opts && opts.message) ? String(opts.message) : '';
      var html = (opts && typeof opts.html === 'string') ? String(opts.html) : '';
      var okText = (opts && opts.okText) ? String(opts.okText) : 'OK';
      var cancelText = (opts && opts.cancelText) ? String(opts.cancelText) : 'Batal';
      var showCancel = opts && opts.showCancel === false ? false : true;
      var variant = (opts && opts.variant) ? String(opts.variant) : 'primary';
      var icon = (opts && opts.icon) ? String(opts.icon) : '!';

      modalTitleEl.textContent = title;
      if (html) {
        modalDescEl.innerHTML = html;
      } else {
        modalDescEl.textContent = message;
      }
      if (modalIconEl) {
        modalIconEl.textContent = icon;
        if (variant === 'danger') {
          modalIconEl.style.color = '#b91c1c';
          modalIconEl.style.borderColor = 'rgba(185, 28, 28, 0.28)';
          modalIconEl.style.background = 'rgba(239, 68, 68, 0.12)';
        } else {
          modalIconEl.style.color = '';
          modalIconEl.style.borderColor = '';
          modalIconEl.style.background = '';
        }
      }

      modalOkBtn.textContent = okText;
      modalCancelBtn.textContent = cancelText;
      modalCancelBtn.style.display = showCancel ? '' : 'none';

      modalOkBtn.classList.remove('primary', 'danger');
      if (variant === 'danger') {
        modalOkBtn.classList.add('danger');
      } else {
        modalOkBtn.classList.add('primary');
      }

      modalEl.setAttribute('data-open', '1');
      modalEl.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';

      window.setTimeout(function () {
        try { modalOkBtn.focus(); } catch (e) {}
      }, 0);
    };

    var uiConfirm = function (opts, onDone) {
      return openModal(opts, function (ok) {
        if (typeof onDone === 'function') onDone(ok);
      });
    };

    window.uiAlert = function (message, opts) {
      uiConfirm({
        title: (opts && opts.title) ? opts.title : 'Info',
        message: String(message || ''),
        okText: (opts && opts.okText) ? opts.okText : 'OK',
        showCancel: false,
        variant: (opts && opts.variant) ? opts.variant : 'primary',
        icon: (opts && opts.icon) ? opts.icon : '!'
      }, function () {});
    };

    window.uiConfirm = function (opts, onDone) {
      uiConfirm(opts, onDone);
    };

    window.uiConfirmSubmit = function (event, formEl, message, opts) {
      if (!event || !formEl) return false;
      event.preventDefault();
      var title = (opts && opts.title) ? opts.title : 'Konfirmasi';
      var okText = (opts && opts.okText) ? opts.okText : 'Hapus';
      var cancelText = (opts && opts.cancelText) ? opts.cancelText : 'Batal';
      var variant = (opts && opts.variant) ? opts.variant : 'danger';
      var icon = (opts && opts.icon) ? opts.icon : '!';
      window.uiConfirm({
        title: title,
        message: String(message || ''),
        okText: okText,
        cancelText: cancelText,
        showCancel: true,
        variant: variant,
        icon: icon
      }, function (ok) {
        if (!ok) return;
        try {
          formEl.submit();
        } catch (e) {}
      });
      return false;
    };

    if (modalEl) {
      modalEl.addEventListener('click', function (e) {
        var t = e.target;
        if (t && t.getAttribute && t.getAttribute('data-ui-close') === '1') {
          closeModal();
        }
      });
    }
    if (modalCancelBtn) {
      modalCancelBtn.addEventListener('click', function () {
        closeModal();
      });
    }
    if (modalOkBtn) {
      modalOkBtn.addEventListener('click', function () {
        if (!pendingResolve) {
          closeModal();
          return;
        }
        var fn = pendingResolve;
        pendingResolve = null;
        modalEl.setAttribute('data-open', '0');
        modalEl.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        fn(true);
      });
    }
    document.addEventListener('keydown', function (e) {
      if (!modalEl) return;
      if (modalEl.getAttribute('data-open') !== '1') return;
      if (e.key === 'Escape') {
        e.preventDefault();
        closeModal();
      }
    });

    window.deleteServiceGalleryImage = function (imageId, serviceId) {
      var form = document.getElementById('delete-service-image-form');
      var imageInput = document.getElementById('delete-service-image-id');
      var serviceInput = document.getElementById('delete-service-id');
      if (!form || !imageInput || !serviceInput) return;
      window.uiConfirm({
        title: 'Hapus Gambar',
        message: 'Hapus gambar ini?',
        okText: 'Hapus',
        cancelText: 'Batal',
        showCancel: true,
        variant: 'danger',
        icon: '!'
      }, function (ok) {
        if (!ok) return;
        imageInput.value = String(imageId || '');
        serviceInput.value = String(serviceId || '');
        form.submit();
      });
    };

    window.deleteProductGalleryImage = function (imageId, productId) {
      var form = document.getElementById('delete-product-image-form');
      var imageInput = document.getElementById('delete-product-image-id');
      var productInput = document.getElementById('delete-product-id');
      if (!form || !imageInput || !productInput) return;
      window.uiConfirm({
        title: 'Hapus Gambar',
        message: 'Hapus gambar ini?',
        okText: 'Hapus',
        cancelText: 'Batal',
        showCancel: true,
        variant: 'danger',
        icon: '!'
      }, function (ok) {
        if (!ok) return;
        imageInput.value = String(imageId || '');
        productInput.value = String(productId || '');
        form.submit();
      });
    };

    window.deleteProjectGalleryImage = function (imageId, projectId) {
      var form = document.getElementById('delete-project-image-form');
      var imageInput = document.getElementById('delete-project-image-id');
      var projectInput = document.getElementById('delete-project-id');
      if (!form || !imageInput || !projectInput) return;
      window.uiConfirm({
        title: 'Hapus Gambar',
        message: 'Hapus gambar ini?',
        okText: 'Hapus',
        cancelText: 'Batal',
        showCancel: true,
        variant: 'danger',
        icon: '!'
      }, function (ok) {
        if (!ok) return;
        imageInput.value = String(imageId || '');
        projectInput.value = String(projectId || '');
        form.submit();
      });
    };

    var getBulkItems = function (formId) {
      if (!formId) return [];
      return Array.prototype.slice.call(document.querySelectorAll('input.bulk-item[data-bulk-form="' + formId + '"]'));
    };

    var getBulkCountLabel = function (formId) {
      if (!formId) return null;
      return document.querySelector('[data-bulk-count-for="' + formId + '"]');
    };

    var getBulkSubmitButton = function (formEl) {
      if (!formEl) return null;
      return formEl.querySelector('button[type="submit"], input[type="submit"]');
    };

    var updateBulkUi = function (formEl) {
      if (!formEl) return;
      var formId = formEl.getAttribute('id') || '';
      var items = getBulkItems(formId);
      var count = 0;
      for (var i = 0; i < items.length; i += 1) {
        if (items[i].checked) count += 1;
      }

      var label = getBulkCountLabel(formId);
      if (label) {
        label.textContent = count + ' dipilih';
      }

      var submitBtn = getBulkSubmitButton(formEl);
      if (submitBtn) {
        submitBtn.disabled = count === 0;
      }

      var checkAll = document.querySelector('input.bulk-check-all[data-bulk-form="' + formId + '"]');
      if (checkAll) {
        if (!items.length) {
          checkAll.checked = false;
          checkAll.indeterminate = false;
          checkAll.disabled = true;
        } else if (count === 0) {
          checkAll.checked = false;
          checkAll.indeterminate = false;
          checkAll.disabled = false;
        } else if (count === items.length) {
          checkAll.checked = true;
          checkAll.indeterminate = false;
          checkAll.disabled = false;
        } else {
          checkAll.checked = false;
          checkAll.indeterminate = true;
          checkAll.disabled = false;
        }
      }
    };

    window.bulkConfirmDelete = function (event, formEl) {
      if (!event || !formEl) return false;
      var formId = formEl.getAttribute('id') || '';
      var items = getBulkItems(formId);
      var count = 0;
      for (var i = 0; i < items.length; i += 1) {
        if (items[i].checked) count += 1;
      }
      if (count === 0) {
        var entityEmpty = (formEl.getAttribute('data-bulk-entity') || 'item');
        window.uiAlert('Pilih minimal 1 ' + entityEmpty + ' dulu.', { title: 'Belum Ada Pilihan', icon: 'i' });
        return false;
      }
      var entity = (formEl.getAttribute('data-bulk-entity') || 'item') + '';
      var noun = count > 1 ? entity + ' terpilih' : entity + ' ini';
      return window.uiConfirmSubmit(event, formEl, 'Hapus ' + count + ' ' + noun + '?', {
        title: 'Konfirmasi Hapus',
        okText: 'Hapus',
        cancelText: 'Batal',
        variant: 'danger',
        icon: '!'
      });
    };

    var initBulkActions = function () {
      var bulkForms = Array.prototype.slice.call(document.querySelectorAll('form.bulk-bar[id]'));
      bulkForms.forEach(function (formEl) {
        var formId = formEl.getAttribute('id') || '';
        if (!formId) return;

        var items = getBulkItems(formId);
        items.forEach(function (cb) {
          cb.addEventListener('change', function () {
            updateBulkUi(formEl);
          });
        });

        var checkAll = document.querySelector('input.bulk-check-all[data-bulk-form="' + formId + '"]');
        if (checkAll) {
          checkAll.addEventListener('change', function () {
            var newItems = getBulkItems(formId);
            newItems.forEach(function (cb) {
              cb.checked = !!checkAll.checked;
            });
            updateBulkUi(formEl);
          });
        }

        updateBulkUi(formEl);
      });
    };
    initBulkActions();

    var escHtml = function (v) {
      return String(v || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    };

    var formatProjectDescriptionHtml = function (text) {
      var raw = String(text || '').replace(/\r/g, '').trim();
      if (!raw) return '';
      var lines = raw.split('\n');
      var blocks = [];
      var current = [];
      var pushBlock = function () {
        if (!current.length) return;
        blocks.push(current.slice());
        current = [];
      };
      lines.forEach(function (line) {
        if (!String(line || '').trim()) {
          pushBlock();
          return;
        }
        current.push(String(line || ''));
      });
      pushBlock();

      var isBullet = function (line) {
        return /^\s*[-*•]\s+/.test(String(line || ''));
      };
      var stripBullet = function (line) {
        return String(line || '').replace(/^\s*[-*•]\s+/, '').trim();
      };
      var applyInline = function (escaped) {
        var t = String(escaped || '');
        // Bold: **text**
        t = t.replace(/\*\*([^\n*][\s\S]*?)\*\*/g, '<strong>$1</strong>');
        // Italic: _text_
        t = t.replace(/_([^\n_][\s\S]*?)_/g, '<em>$1</em>');
        // Size tokens (safe, non-HTML): [[small]]...[[/small]], [[large]]...[[/large]]
        t = t.replace(/\[\[small\]\]([\s\S]*?)\[\[\/small\]\]/g, '<span class="t-sm">$1</span>');
        t = t.replace(/\[\[large\]\]([\s\S]*?)\[\[\/large\]\]/g, '<span class="t-lg">$1</span>');
        // Size dropdown tokens: [[size=16]]...[[/size]] (whitelist for safety)
        t = t.replace(/\[\[size=(\d{2})\]\]([\s\S]*?)\[\[\/size\]\]/g, function (_m, size, inner) {
          var allowed = { '12': true, '14': true, '16': true, '18': true, '20': true, '24': true, '28': true, '32': true, '36': true };
          var key = String(size || '');
          if (!allowed[key]) return inner;
          return '<span class="t-sz-' + key + '">' + inner + '</span>';
        });
        return t;
      };
      var joinTextWithBr = function (arr) {
        return arr
          .map(function (l) { return String(l || '').trim(); })
          .filter(Boolean)
          .map(function (s) { return applyInline(escHtml(s)); })
          .join('<br>');
      };
      var parseHeading = function (line) {
        var m = String(line || '').match(/^\s*(#{2,3})\s+(.+)\s*$/);
        if (!m) return null;
        // We only use H2 in UI; accept ### from older content but map to H2.
        var lvl = 'h2';
        var title = applyInline(escHtml(m[2]));
        return { tag: lvl, html: title };
      };

      return blocks.map(function (block) {
        var heading = parseHeading(block[0]);
        var rest = heading ? block.slice(1) : block.slice();
        var bulletCount = block.filter(isBullet).length;
        var firstBulletIdx = -1;
        for (var i = 0; i < rest.length; i += 1) {
          if (isBullet(rest[i])) { firstBulletIdx = i; break; }
        }
        var out = '';
        if (heading) {
          out += '<' + heading.tag + '>' + heading.html + '</' + heading.tag + '>';
        }
        if (!rest.length) {
          return out;
        }

        var restBulletCount = rest.filter(isBullet).length;
        if (firstBulletIdx > 0 && rest.slice(firstBulletIdx).every(isBullet)) {
          var head = joinTextWithBr(rest.slice(0, firstBulletIdx));
          var listItems = rest.slice(firstBulletIdx).map(function (l) { return '<li>' + applyInline(escHtml(stripBullet(l))) + '</li>'; }).join('');
          return out + '<p>' + head + '</p><ul>' + listItems + '</ul>';
        }
        if (restBulletCount && restBulletCount >= Math.max(2, Math.ceil(rest.length * 0.6))) {
          return out + '<ul>' + rest.map(function (l) { return '<li>' + applyInline(escHtml(stripBullet(l))) + '</li>'; }).join('') + '</ul>';
        }
        return out + '<p>' + joinTextWithBr(rest) + '</p>';
      }).join('');
    };

    var insertTextAtSelection = function (textarea, text) {
      if (!textarea) return;
      var start = textarea.selectionStart || 0;
      var end = textarea.selectionEnd || 0;
      var value = String(textarea.value || '');
      textarea.value = value.slice(0, start) + text + value.slice(end);
      var caret = start + String(text).length;
      textarea.selectionStart = caret;
      textarea.selectionEnd = caret;
      textarea.focus();
    };

    var prefixSelectedLines = function (textarea, prefix) {
      if (!textarea) return;
      var value = String(textarea.value || '');
      var start = textarea.selectionStart || 0;
      var end = textarea.selectionEnd || 0;

      var selStart = value.lastIndexOf('\n', Math.max(0, start - 1)) + 1;
      var selEndNl = value.indexOf('\n', end);
      var selEnd = selEndNl === -1 ? value.length : selEndNl;
      var chunk = value.slice(selStart, selEnd);
      var lines = chunk.split('\n');
      var changed = lines.map(function (line) {
        if (!line.trim()) return line;
        if (line.trim().indexOf(prefix.trim()) === 0) return line;
        return prefix + line;
      }).join('\n');

      textarea.value = value.slice(0, selStart) + changed + value.slice(selEnd);
      textarea.selectionStart = selStart;
      textarea.selectionEnd = selStart + changed.length;
      textarea.focus();
    };

    var wrapSelection = function (textarea, before, after) {
      if (!textarea) return;
      var value = String(textarea.value || '');
      var start = textarea.selectionStart || 0;
      var end = textarea.selectionEnd || 0;
      var selected = value.slice(start, end);
      var a = typeof after === 'string' ? after : before;
      if (!selected) {
        textarea.value = value.slice(0, start) + before + a + value.slice(end);
        var caret = start + before.length;
        textarea.selectionStart = caret;
        textarea.selectionEnd = caret;
        textarea.focus();
        return;
      }
      textarea.value = value.slice(0, start) + before + selected + a + value.slice(end);
      textarea.selectionStart = start + before.length;
      textarea.selectionEnd = end + before.length;
      textarea.focus();
    };

    var initTextToolboxes = function () {
      var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-text-tool][data-target]'));
      buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
          var action = btn.getAttribute('data-text-tool') || '';
          var targetId = btn.getAttribute('data-target') || '';
          var textarea = targetId ? document.getElementById(targetId) : null;
          if (!textarea) return;

          if (action === 'bullet') {
            prefixSelectedLines(textarea, '- ');
            return;
          }
          if (action === 'bold') {
            wrapSelection(textarea, '**', '**');
            return;
          }
          if (action === 'italic') {
            wrapSelection(textarea, '_', '_');
            return;
          }
          if (action === 'small') {
            wrapSelection(textarea, '[[small]]', '[[/small]]');
            return;
          }
          if (action === 'large') {
            wrapSelection(textarea, '[[large]]', '[[/large]]');
            return;
          }
          if (action === 'h2') {
            prefixSelectedLines(textarea, '## ');
            return;
          }
          if (action === 'paragraph') {
            insertTextAtSelection(textarea, '\n\n');
            return;
          }
          if (action === 'template') {
            insertTextAtSelection(textarea, (textarea.value && !/\n$/.test(textarea.value)) ? '\n\nFitur:\n- \n- \n\nNote: ' : 'Fitur:\n- \n- \n\nNote: ');
            return;
          }
          if (action === 'preview') {
            var html = formatProjectDescriptionHtml(textarea.value || '');
            window.uiConfirm({
              title: 'Preview Deskripsi',
              html: '<div class="preview">' + (html || '<p class=\"muted\">Belum ada isi.</p>') + '</div>',
              okText: 'Tutup',
              showCancel: false,
              variant: 'primary',
              icon: 'i'
            }, function () {});
          }
        });
      });
    };
    initTextToolboxes();

    var initTextSizeSelectors = function () {
      var selects = Array.prototype.slice.call(document.querySelectorAll('select[data-text-size-target]'));
      selects.forEach(function (sel) {
        sel.addEventListener('change', function () {
          var targetId = sel.getAttribute('data-text-size-target') || '';
          var textarea = targetId ? document.getElementById(targetId) : null;
          var value = String(sel.value || '').trim();
          if (!textarea || !value) return;
          wrapSelection(textarea, '[[size=' + value + ']]', '[[/size]]');
          sel.value = '';
        });
      });
    };
    initTextSizeSelectors();

    var priceHidden = document.getElementById('price-hidden');
    var priceDisplay = document.getElementById('price-display');
    var servicePriceHidden = document.getElementById('service-price-hidden');
    var servicePriceDisplay = document.getElementById('service-price-display');
    var projectPriceDisplay = document.getElementById('project-price-display');

    var normalizeNumber = function (value) {
      return String(value || '').replace(/[^\d]/g, '');
    };
    var formatIdr = function (value) {
      var clean = normalizeNumber(value);
      if (!clean) return '';
      return Number(clean).toLocaleString('id-ID');
    };

    if (priceHidden && priceDisplay) {
      priceDisplay.addEventListener('input', function () {
        var clean = normalizeNumber(priceDisplay.value);
        priceHidden.value = clean || '0';
        priceDisplay.value = formatIdr(clean);
      });
    }
    if (servicePriceHidden && servicePriceDisplay) {
      servicePriceDisplay.addEventListener('input', function () {
        var clean = normalizeNumber(servicePriceDisplay.value);
        servicePriceHidden.value = clean;
        servicePriceDisplay.value = formatIdr(clean);
      });
    }
    if (projectPriceDisplay) {
      var projectInitial = normalizeNumber(projectPriceDisplay.value);
      projectPriceDisplay.value = formatIdr(projectInitial);
      projectPriceDisplay.addEventListener('input', function () {
        var clean = normalizeNumber(projectPriceDisplay.value);
        projectPriceDisplay.value = formatIdr(clean);
      });
    }

    var mainInput = document.getElementById('image-file-input');
    var mainPreview = document.getElementById('main-upload-preview');
    var serviceMainInput = document.getElementById('service-image-file-input');
    var serviceMainPreview = document.getElementById('service-main-upload-preview');
    var projectMainInput = document.getElementById('project-image-file-input');
    var projectMainPreview = document.getElementById('project-main-upload-preview');
    var articleMainInput = document.getElementById('article-image-file-input');
    var articleMainPreview = document.getElementById('article-main-upload-preview');
    var teamMainInput = document.getElementById('team-image-file-input');
    var teamMainPreview = document.getElementById('team-main-upload-preview');
    var testimonialAvatarInput = document.getElementById('testimonial-avatar-file-input');
    var testimonialAvatarPreview = document.getElementById('testimonial-avatar-upload-preview');
    var testimonialLogoInput = document.getElementById('testimonial-logo-file-input');
    var testimonialLogoPreview = document.getElementById('testimonial-logo-upload-preview');
    var galleryInput = document.getElementById('gallery-files-input');
    var galleryPreview = document.getElementById('gallery-upload-preview');
    var galleryHelpText = document.getElementById('gallery-help-text');
    var serviceGalleryInput = document.getElementById('service-gallery-files-input');
    var serviceGalleryPreview = document.getElementById('service-gallery-upload-preview');
    var serviceGalleryHelpText = document.getElementById('service-gallery-help-text');
    var projectGalleryInput = document.getElementById('project-gallery-files-input');
    var projectGalleryPreview = document.getElementById('project-gallery-upload-preview');
    var projectGalleryHelpText = document.getElementById('project-gallery-help-text');
    var companyProfilePdfInput = document.getElementById('company-profile-pdf-file');
    var companyProfilePdfName = document.getElementById('company-profile-file-name');
    var imageFileName = document.getElementById('image-file-name');
    var galleryFilesFileName = document.getElementById('gallery-files-file-name');
    var serviceImageFileName = document.getElementById('service-image-file-name');
    var serviceGalleryFilesFileName = document.getElementById('service-gallery-files-file-name');
    var projectImageFileName = document.getElementById('project-image-file-name');
    var projectGalleryFilesFileName = document.getElementById('project-gallery-files-file-name');
    var articleImageFileName = document.getElementById('article-image-file-name');
    var articleEditorToolbar = document.getElementById('article-editor-toolbar');
    var articleEditorSurface = document.getElementById('article-editor-surface');
    var articleContentHtml = document.getElementById('article-content-html');
    var articleInlineImageFile = document.getElementById('article-inline-image-file');
    var articleExcerptToolbar = document.getElementById('article-excerpt-toolbar');
    var articleExcerptSurface = document.getElementById('article-excerpt-surface');
    var articleExcerptHidden = document.getElementById('article-excerpt-hidden');
    var articleExcerptFontSizeSelect = document.getElementById('article-excerpt-font-size-select');
    var articleImageControls = document.getElementById('article-image-controls');
    var articleImageSizeRange = document.getElementById('article-image-size-range');
    var articleImageSizeInput = document.getElementById('article-image-size-input');
    var articleImageSizeReset = document.getElementById('article-image-size-reset');
    var articleFontSizeSelect = document.getElementById('article-font-size-select');
    var testimonialAvatarFileName = document.getElementById('testimonial-avatar-file-name');
    var testimonialLogoFileName = document.getElementById('testimonial-logo-file-name');
    var teamImageFileName = document.getElementById('team-image-file-name');

    var formatFileSizeKb = function (bytes) {
      var sizeKb = Math.max(1, Math.round((Number(bytes) || 0) / 1024));
      return sizeKb + ' KB';
    };
    var updateSingleFileName = function (labelEl, file, emptyText) {
      if (!labelEl) return;
      if (!file) {
        labelEl.textContent = emptyText || 'Belum ada file dipilih';
        return;
      }
      labelEl.textContent = file.name + ' (' + formatFileSizeKb(file.size) + ')';
    };
    var updateMultiFileName = function (labelEl, totalFiles, emptyText) {
      if (!labelEl) return;
      if (!totalFiles) {
        labelEl.textContent = emptyText || 'Belum ada file dipilih';
        return;
      }
      labelEl.textContent = totalFiles + ' file dipilih';
    };

    var setupSinglePreview = function (inputEl, previewEl, altText, labelEl) {
      if (!inputEl || !previewEl) return;
      inputEl.addEventListener('change', function () {
        if (!inputEl.files || !inputEl.files.length) {
          previewEl.innerHTML = '<div class="upload-preview-empty">Belum ada file dipilih.</div>';
          updateSingleFileName(labelEl, null);
          return;
        }
        var file = inputEl.files[0];
        var url = URL.createObjectURL(file);
        previewEl.innerHTML =
          '<img src="' + url + '" alt="' + altText + '">' +
          '<div class="upload-preview-name">' + file.name + '</div>';
        updateSingleFileName(labelEl, file);
      });
    };
    setupSinglePreview(mainInput, mainPreview, 'Preview gambar utama', imageFileName);
    setupSinglePreview(serviceMainInput, serviceMainPreview, 'Preview gambar layanan', serviceImageFileName);
    setupSinglePreview(projectMainInput, projectMainPreview, 'Preview gambar proyek', projectImageFileName);
    setupSinglePreview(articleMainInput, articleMainPreview, 'Preview gambar artikel', articleImageFileName);
    setupSinglePreview(teamMainInput, teamMainPreview, 'Preview foto tim', teamImageFileName);
    setupSinglePreview(testimonialAvatarInput, testimonialAvatarPreview, 'Preview foto klien', testimonialAvatarFileName);
    setupSinglePreview(testimonialLogoInput, testimonialLogoPreview, 'Preview logo brand', testimonialLogoFileName);
    if (companyProfilePdfInput && companyProfilePdfName) {
      companyProfilePdfInput.addEventListener('change', function () {
        if (!companyProfilePdfInput.files || !companyProfilePdfInput.files.length) {
          companyProfilePdfName.textContent = 'Belum ada file PDF dipilih';
          return;
        }
        var file = companyProfilePdfInput.files[0];
        companyProfilePdfName.textContent = file.name + ' (' + formatFileSizeKb(file.size) + ')';
      });
    }
    var companyProfileClearBtn = document.getElementById('company-profile-clear-btn');
    if (companyProfileClearBtn && companyProfilePdfInput && companyProfilePdfName) {
      companyProfileClearBtn.addEventListener('click', function () {
        companyProfilePdfInput.value = '';
        companyProfilePdfName.textContent = 'Belum ada file PDF dipilih';
      });
    }
    var companyProfileDeleteBtn = document.getElementById('company-profile-delete-btn');
    var removeCompanyProfilePdfInput = document.getElementById('remove-company-profile-pdf');
    if (companyProfileDeleteBtn && removeCompanyProfilePdfInput) {
      companyProfileDeleteBtn.addEventListener('click', function () {
        var confirmed = window.confirm('Hapus PDF aktif? File akan dihapus dari server.');
        if (!confirmed) return;
        removeCompanyProfilePdfInput.value = '1';
        if (companyProfilePdfInput) companyProfilePdfInput.value = '';
        if (companyProfilePdfName) companyProfilePdfName.textContent = 'Belum ada file PDF dipilih';
        var form = companyProfileDeleteBtn.closest('form');
        if (form) form.submit();
      });
    }

    var insertHtmlAtCursor = function (html) {
      if (!html) return;
      document.execCommand('insertHTML', false, html);
    };

    var escapeHtmlLite = function (text) {
      return String(text || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    };

    var legacyTextToHtml = function (text) {
      var raw = String(text || '').replace(/\r/g, '').trim();
      if (!raw) return '<p></p>';
      // If it already looks like HTML, keep it.
      if (/<[a-z][\s\S]*>/i.test(raw)) return raw;

      // Support old lightweight tokens so existing content becomes WYSIWYG on next save.
      var applyInline = function (escaped) {
        var t = String(escaped || '');
        t = t.replace(/\*\*([^\n*][\s\S]*?)\*\*/g, '<strong>$1</strong>');
        t = t.replace(/_([^\n_][\s\S]*?)_/g, '<em>$1</em>');
        // [[size=NN]]...[[/size]]
        t = t.replace(/\[\[size=(\d{2})\]\]([\s\S]*?)\[\[\/size\]\]/g, function (_m, size, inner) {
          var allowed = { '12': true, '14': true, '16': true, '18': true, '20': true, '24': true, '28': true, '32': true, '36': true };
          var key = String(size || '');
          if (!allowed[key]) return inner;
          return '<span style="font-size:' + key + 'px">' + inner + '</span>';
        });
        // [[small]] / [[large]]
        t = t.replace(/\[\[small\]\]([\s\S]*?)\[\[\/small\]\]/g, '<span style="font-size:14px">$1</span>');
        t = t.replace(/\[\[large\]\]([\s\S]*?)\[\[\/large\]\]/g, '<span style="font-size:18px">$1</span>');
        return t;
      };

      var lines = raw.split('\n');
      var blocks = [];
      var current = [];
      var pushBlock = function () {
        if (!current.length) return;
        blocks.push(current.slice());
        current = [];
      };
      lines.forEach(function (line) {
        if (!String(line || '').trim()) {
          pushBlock();
          return;
        }
        current.push(String(line || ''));
      });
      pushBlock();

      var isBullet = function (line) { return /^\s*[-*•]\s+/.test(String(line || '')); };
      var stripBullet = function (line) { return String(line || '').replace(/^\s*[-*•]\s+/, '').trim(); };
      var parseHeading = function (line) {
        var m = String(line || '').match(/^\s*(#{2,3})\s+(.+)\s*$/);
        if (!m) return null;
        return { tag: 'h2', html: applyInline(escapeHtmlLite(m[2])) };
      };
      var joinTextWithBr = function (arr) {
        return arr
          .map(function (l) { return String(l || '').trim(); })
          .filter(Boolean)
          .map(function (s) { return applyInline(escapeHtmlLite(s)); })
          .join('<br>');
      };

      return blocks.map(function (block) {
        var heading = parseHeading(block[0]);
        var rest = heading ? block.slice(1) : block.slice();
        var out = '';
        if (heading) out += '<h2>' + heading.html + '</h2>';
        if (!rest.length) return out;
        var bulletCount = rest.filter(isBullet).length;
        if (bulletCount && bulletCount >= Math.max(2, Math.ceil(rest.length * 0.6))) {
          return out + '<ul>' + rest.map(function (l) { return '<li>' + applyInline(escapeHtmlLite(stripBullet(l))) + '</li>'; }).join('') + '</ul>';
        }
        return out + '<p>' + joinTextWithBr(rest) + '</p>';
      }).join('');
    };

    var syncHiddenFromSurface = function (surfaceEl, hiddenEl) {
      if (!surfaceEl || !hiddenEl) return;
      hiddenEl.value = String(surfaceEl.innerHTML || '').trim();
    };
    var syncHiddenFromSurfacePlain = function (surfaceEl, hiddenEl) {
      if (!surfaceEl || !hiddenEl) return;
      var text = String(surfaceEl.innerText || surfaceEl.textContent || '').replace(/\r\n/g, '\n');
      hiddenEl.value = text.trim();
    };

    var setupDescriptionEditor = function (toolbarId, surfaceId, hiddenId, fontSelectId) {
      var toolbar = document.getElementById(toolbarId);
      var surface = document.getElementById(surfaceId);
      var hidden = document.getElementById(hiddenId);
      var fontSelect = document.getElementById(fontSelectId);
      if (!toolbar || !surface || !hidden) return;

      surface.innerHTML = legacyTextToHtml(hidden.value || '');
      if (String(surface.innerHTML || '').trim() === '') surface.innerHTML = '<p></p>';

      surface.addEventListener('input', function () {
        syncHiddenFromSurface(surface, hidden);
      });

      var form = surface.closest('form');
      if (form) {
        form.addEventListener('submit', function () {
          syncHiddenFromSurface(surface, hidden);
        });
      }

      if (fontSelect) {
        fontSelect.addEventListener('change', function () {
          var value = String(fontSelect.value || '').trim();
          if (!value) return;
          applyFontSizeToSelection(value, surface);
          syncHiddenFromSurface(surface, hidden);
          fontSelect.value = '';
        });
      }

      toolbar.addEventListener('click', function (event) {
        var button = event.target.closest('button');
        if (!button) return;
        surface.focus();

        if (button.hasAttribute('data-cmd')) {
          var cmd = button.getAttribute('data-cmd') || '';
          if (cmd) document.execCommand(cmd, false, null);
          syncHiddenFromSurface(surface, hidden);
          return;
        }
        if (button.hasAttribute('data-block')) {
          var block = button.getAttribute('data-block') || 'p';
          document.execCommand('formatBlock', false, block.toUpperCase());
          syncHiddenFromSurface(surface, hidden);
          return;
        }
        if (button.hasAttribute('data-link')) {
          var link = window.prompt('Masukkan URL link (contoh: https://...)', 'https://');
          if (!link) return;
          document.execCommand('createLink', false, link);
          syncHiddenFromSurface(surface, hidden);
        }
      });
    };
    var setupPlainTextEditor = function (toolbarId, surfaceId, hiddenId) {
      var toolbar = toolbarId ? document.getElementById(toolbarId) : null;
      var surface = document.getElementById(surfaceId);
      var hidden = document.getElementById(hiddenId);
      if (!surface || !hidden) return;

      surface.innerHTML = legacyTextToHtml(hidden.value || '');
      if (String(surface.innerHTML || '').trim() === '') surface.innerHTML = '<p></p>';

      surface.addEventListener('input', function () {
        syncHiddenFromSurfacePlain(surface, hidden);
      });

      var form = surface.closest('form');
      if (form) {
        form.addEventListener('submit', function () {
          syncHiddenFromSurfacePlain(surface, hidden);
        });
      }

      if (toolbar) {
        toolbar.addEventListener('click', function (event) {
          var button = event.target.closest('button');
          if (!button) return;
          surface.focus();

          if (button.hasAttribute('data-cmd')) {
            var cmd = button.getAttribute('data-cmd') || '';
            if (cmd) document.execCommand(cmd, false, null);
            syncHiddenFromSurfacePlain(surface, hidden);
            return;
          }
          if (button.hasAttribute('data-block')) {
            var block = button.getAttribute('data-block') || 'p';
            document.execCommand('formatBlock', false, block.toUpperCase());
            syncHiddenFromSurfacePlain(surface, hidden);
            return;
          }
        });
      }
    };

    if (articleEditorSurface && articleContentHtml) {
      var initialContent = String(articleContentHtml.value || '').trim();
      articleEditorSurface.innerHTML = initialContent !== '' ? initialContent : '<p></p>';
    }
    if (articleExcerptSurface && articleExcerptHidden) {
      var excerptInitial = String(articleExcerptHidden.value || '').trim();
      if (/<[a-z][\s\S]*>/i.test(excerptInitial)) {
        articleExcerptSurface.innerHTML = excerptInitial;
      } else {
        articleExcerptSurface.textContent = excerptInitial;
      }
      if (String(articleExcerptSurface.innerHTML || '').trim() === '') {
        articleExcerptSurface.innerHTML = '<p></p>';
      }
    }

    var selectedEditorImage = null;
    var syncImageSizeControls = function (imageEl) {
      if (!articleImageControls || !articleImageSizeRange || !articleImageSizeInput) return;
      if (!imageEl) {
        articleImageControls.classList.add('hidden-control');
        return;
      }
      articleImageControls.classList.remove('hidden-control');
      var widthValue = String(imageEl.style.width || '').trim();
      var percent = parseInt(widthValue.replace('%', ''), 10);
      if (!Number.isFinite(percent) || percent < 20 || percent > 100) {
        percent = 65;
      }
      articleImageSizeRange.value = String(percent);
      articleImageSizeInput.value = String(percent);
    };
    var clearSelectedEditorImage = function () {
      if (selectedEditorImage) {
        selectedEditorImage.classList.remove('is-selected');
      }
      selectedEditorImage = null;
      syncImageSizeControls(null);
    };
    var resizeSelectedEditorImage = function (sizePercent) {
      if (!selectedEditorImage) {
        window.uiAlert('Klik gambar dulu untuk mengatur ukurannya.', { title: 'Info', icon: 'i' });
        return;
      }
      var size = Number(sizePercent);
      if (!Number.isFinite(size) || size <= 0) return;
      selectedEditorImage.style.width = String(size) + '%';
      selectedEditorImage.style.maxWidth = '100%';
      selectedEditorImage.style.height = 'auto';
      selectedEditorImage.style.display = 'block';
      selectedEditorImage.style.margin = '10px auto';
      syncImageSizeControls(selectedEditorImage);
    };
    var alignSelectedEditorImage = function (align) {
      if (!selectedEditorImage) {
        window.uiAlert('Klik gambar dulu untuk mengatur posisinya.', { title: 'Info', icon: 'i' });
        return;
      }
      selectedEditorImage.style.display = 'block';
      selectedEditorImage.style.height = 'auto';
      selectedEditorImage.style.maxWidth = '100%';
      if (align === 'left') {
        selectedEditorImage.style.margin = '10px auto 10px 0';
      } else if (align === 'right') {
        selectedEditorImage.style.margin = '10px 0 10px auto';
      } else {
        selectedEditorImage.style.margin = '10px auto';
      }
    };
    var applyFontSizeToSelection = function (px, surfaceEl) {
      var targetSurface = surfaceEl || articleEditorSurface;
      if (!targetSurface) return;
      var size = Number(px);
      if (!Number.isFinite(size) || size < 10 || size > 60) return;
      targetSurface.focus();
      var selection = window.getSelection ? window.getSelection() : null;
      if (!selection || selection.rangeCount === 0) return;
      var range = selection.getRangeAt(0);
      var anchorNode = selection.anchorNode;
      if (!anchorNode || !targetSurface.contains(anchorNode)) return;
      if (selection.isCollapsed) {
        window.uiAlert('Blok teks dulu, lalu pilih ukuran font.', { title: 'Info', icon: 'i' });
        return;
      }

      var span = document.createElement('span');
      span.style.fontSize = String(size) + 'px';
      try {
        range.surroundContents(span);
      } catch (err) {
        var fragment = range.extractContents();
        span.appendChild(fragment);
        range.insertNode(span);
      }
      selection.removeAllRanges();
      var newRange = document.createRange();
      newRange.selectNodeContents(span);
      selection.addRange(newRange);
    };

    if (articleEditorSurface) {
      articleEditorSurface.addEventListener('click', function (event) {
        var img = event.target && event.target.tagName === 'IMG' ? event.target : null;
        clearSelectedEditorImage();
        if (img) {
          var selection = window.getSelection ? window.getSelection() : null;
          if (selection && selection.removeAllRanges) {
            selection.removeAllRanges();
          }
          selectedEditorImage = img;
          selectedEditorImage.classList.add('is-selected');
          syncImageSizeControls(selectedEditorImage);
        }
      });
      document.addEventListener('click', function (event) {
        var isToolbarClick = articleEditorToolbar ? articleEditorToolbar.contains(event.target) : false;
        if (!articleEditorSurface.contains(event.target) && !isToolbarClick) {
          clearSelectedEditorImage();
        }
      });
    }

    if (articleImageSizeRange) {
      articleImageSizeRange.addEventListener('input', function () {
        if (!selectedEditorImage) return;
        var value = Number(articleImageSizeRange.value || 65);
        if (!Number.isFinite(value)) return;
        articleImageSizeInput.value = String(value);
        resizeSelectedEditorImage(value);
      });
    }
    if (articleImageSizeInput) {
      articleImageSizeInput.addEventListener('input', function () {
        if (!selectedEditorImage) return;
        var value = Number(articleImageSizeInput.value || 65);
        if (!Number.isFinite(value)) return;
        if (value < 20) value = 20;
        if (value > 100) value = 100;
        articleImageSizeRange.value = String(value);
        resizeSelectedEditorImage(value);
      });
    }
    if (articleImageSizeReset) {
      articleImageSizeReset.addEventListener('click', function () {
        if (!selectedEditorImage) return;
        resizeSelectedEditorImage(65);
      });
    }
    if (articleFontSizeSelect) {
      articleFontSizeSelect.addEventListener('change', function () {
        var value = String(articleFontSizeSelect.value || '').trim();
        if (!value) return;
        applyFontSizeToSelection(value, articleEditorSurface);
        articleFontSizeSelect.value = '';
      });
    }
    if (articleExcerptFontSizeSelect) {
      articleExcerptFontSizeSelect.addEventListener('change', function () {
        var value = String(articleExcerptFontSizeSelect.value || '').trim();
        if (!value) return;
        applyFontSizeToSelection(value, articleExcerptSurface);
        articleExcerptFontSizeSelect.value = '';
      });
    }

    // Product/Service/Project description editors (WYSIWYG like article).
    setupDescriptionEditor('product-desc-toolbar', 'product-desc-surface', 'product-desc-hidden', 'product-desc-font-size');
    setupDescriptionEditor('service-desc-toolbar', 'service-desc-surface', 'service-desc-hidden', 'service-desc-font-size');
    setupDescriptionEditor('project-desc-toolbar', 'project-desc-surface', 'project-desc-hidden', 'project-desc-font-size');
    setupPlainTextEditor(null, 'project-short-desc-surface', 'project-short-desc-hidden');
    setupPlainTextEditor(null, 'product-short-desc-surface', 'product-short-desc-hidden');
    setupPlainTextEditor(null, 'service-short-desc-surface', 'service-short-desc-hidden');
    setupPlainTextEditor(null, 'testimonial-quote-surface', 'testimonial-quote-hidden');

    if (articleEditorToolbar && articleEditorSurface) {
      articleEditorToolbar.addEventListener('click', function (event) {
        var button = event.target.closest('button');
        if (!button) return;
        articleEditorSurface.focus();

        if (button.hasAttribute('data-cmd')) {
          var cmd = button.getAttribute('data-cmd') || '';
          if (cmd) document.execCommand(cmd, false, null);
          return;
        }
        if (button.hasAttribute('data-block')) {
          var block = button.getAttribute('data-block') || 'p';
          document.execCommand('formatBlock', false, block.toUpperCase());
          return;
        }
        if (button.hasAttribute('data-link')) {
          var link = window.prompt('Masukkan URL link (contoh: https://...)', 'https://');
          if (!link) return;
          document.execCommand('createLink', false, link);
          return;
        }
        if (button.hasAttribute('data-image-align')) {
          var imageAlign = button.getAttribute('data-image-align') || 'center';
          alignSelectedEditorImage(imageAlign);
          return;
        }
        if (button.hasAttribute('data-image-size')) {
          var imageSize = button.getAttribute('data-image-size') || '';
          resizeSelectedEditorImage(imageSize);
          return;
        }
        if (button.hasAttribute('data-image-upload') && articleInlineImageFile) {
          articleInlineImageFile.click();
        }
      });
    }
    if (articleExcerptToolbar && articleExcerptSurface) {
      articleExcerptToolbar.addEventListener('click', function (event) {
        var button = event.target.closest('button');
        if (!button) return;
        articleExcerptSurface.focus();

        if (button.hasAttribute('data-cmd')) {
          var cmd = button.getAttribute('data-cmd') || '';
          if (cmd) document.execCommand(cmd, false, null);
          return;
        }
        if (button.hasAttribute('data-link')) {
          var link = window.prompt('Masukkan URL link (contoh: https://...)', 'https://');
          if (!link) return;
          document.execCommand('createLink', false, link);
        }
      });
    }

    if (articleInlineImageFile && articleEditorSurface) {
      articleInlineImageFile.addEventListener('change', function () {
        if (!articleInlineImageFile.files || !articleInlineImageFile.files.length) return;
        var file = articleInlineImageFile.files[0];
        var formData = new FormData();
        formData.append('action', 'upload_article_inline_image');
        formData.append('redirect_page', 'articles');
        formData.append('inline_image_file', file);

        fetch(window.location.pathname + window.location.search, {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
        })
          .then(function (res) { return res.json(); })
          .then(function (json) {
            if (!json || !json.ok || !json.url) {
              throw new Error((json && json.message) ? json.message : 'Upload gagal');
            }
            articleEditorSurface.focus();
            insertHtmlAtCursor('<p style="text-align:center;"><img src="' + String(json.url).replace(/"/g, '&quot;') + '" alt="Gambar artikel" style="width:65%;max-width:100%;height:auto;display:block;margin:10px auto;"></p>');
          })
          .catch(function (err) {
            window.uiAlert('Upload gambar gagal: ' + (err && err.message ? err.message : 'Unknown error'), { title: 'Gagal Upload', variant: 'danger', icon: '!' });
          })
          .finally(function () {
            articleInlineImageFile.value = '';
          });
      });
    }

    var setupMultiFileQueue = function (inputEl, previewEl, helpEl, idleText, activePrefix, labelEl) {
      if (!inputEl || !previewEl) return;
      var queue = [];
      var fileKey = function (file) {
        return [file.name, file.size, file.lastModified].join('|');
      };
      var syncInputFiles = function () {
        var dataTransfer = new DataTransfer();
        queue.forEach(function (file) {
          dataTransfer.items.add(file);
        });
        inputEl.files = dataTransfer.files;
      };
      var renderGalleryQueue = function () {
        if (!queue.length) {
          previewEl.innerHTML = '<div class="upload-preview-empty">Belum ada file dipilih.</div>';
          if (helpEl) {
            helpEl.textContent = idleText;
          }
          updateMultiFileName(labelEl, 0);
          return;
        }
        var html = '';
        queue.forEach(function (file, index) {
          var url = URL.createObjectURL(file);
          html += '' +
            '<div class="item">' +
              '<img src="' + url + '" alt="Preview gallery">' +
              '<div class="upload-preview-name">' + file.name + '</div>' +
              '<button type="button" class="remove-file" data-remove-index="' + index + '">Hapus</button>' +
            '</div>';
        });
        previewEl.innerHTML = html;
        if (helpEl) {
          helpEl.textContent = activePrefix + queue.length + '. Kamu masih bisa tambah file lagi sebelum simpan.';
        }
        updateMultiFileName(labelEl, queue.length);
      };

      inputEl.addEventListener('change', function () {
        if (!inputEl.files || !inputEl.files.length) return;
        Array.prototype.forEach.call(inputEl.files, function (file) {
          var exists = queue.some(function (queuedFile) {
            return fileKey(queuedFile) === fileKey(file);
          });
          if (!exists) {
            queue.push(file);
          }
        });
        syncInputFiles();
        renderGalleryQueue();
      });

      previewEl.addEventListener('click', function (event) {
        var button = event.target.closest('button[data-remove-index]');
        if (!button) return;
        var index = parseInt(button.getAttribute('data-remove-index') || '-1', 10);
        if (index < 0 || index >= queue.length) return;
        queue.splice(index, 1);
        syncInputFiles();
        renderGalleryQueue();
      });
    };

    setupMultiFileQueue(
      galleryInput,
      galleryPreview,
      galleryHelpText,
      'Bisa pilih banyak file JPG/JPEG/PNG sekaligus. Kamu juga bisa pilih file lagi berkali-kali, nanti ditumpuk sampai klik Simpan Produk.',
      'File dalam antrian: ',
      galleryFilesFileName
    );
    setupMultiFileQueue(
      serviceGalleryInput,
      serviceGalleryPreview,
      serviceGalleryHelpText,
      'Bisa pilih banyak file JPG/JPEG/PNG sekaligus. Kamu juga bisa pilih file lagi berkali-kali, nanti ditumpuk sampai klik Simpan Layanan.',
      'File layanan dalam antrian: ',
      serviceGalleryFilesFileName
    );
    setupMultiFileQueue(
      projectGalleryInput,
      projectGalleryPreview,
      projectGalleryHelpText,
      'Bisa pilih banyak file JPG/JPEG/PNG sekaligus. Kamu juga bisa pilih file lagi berkali-kali, nanti ditumpuk sampai klik Simpan Proyek.',
      'File proyek dalam antrian: ',
      projectGalleryFilesFileName
    );

    var isValidHttpUrl = function (value) {
      if (!value) return true;
      try {
        var url = new URL(value);
        return url.protocol === 'http:' || url.protocol === 'https:';
      } catch (err) {
        return false;
      }
    };
    var isValidEmail = function (value) {
      if (!value) return true;
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    };
    var isValidSlug = function (value) {
      if (!value) return true;
      return /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value);
    };
    var normalizeSlug = function (value) {
      return String(value || '')
        .toLowerCase()
        .trim()
        .replace(/[_\s]+/g, '-')
        .replace(/[^a-z0-9-]/g, '')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
    };
    var normalizeHttpUrl = function (value) {
      var raw = String(value || '').trim();
      if (!raw) return '';
      if (/^https?:\/\//i.test(raw)) return raw;
      if (/^[a-z0-9.-]+\.[a-z]{2,}(\/.*)?$/i.test(raw)) return 'https://' + raw;
      return raw;
    };
    var normalizeMapEmbedInput = function (value) {
      var raw = String(value || '').trim();
      if (!raw) return '';
      var iframeSrcMatch = raw.match(/src\s*=\s*["']([^"']+)["']/i);
      if (iframeSrcMatch && iframeSrcMatch[1]) {
        return normalizeHttpUrl(iframeSrcMatch[1]);
      }
      return normalizeHttpUrl(raw);
    };
    var isValidWhatsAppDigits = function (value) {
      if (!value) return true;
      var clean = String(value).replace(/[^\d+]/g, '');
      return /^\+?\d{8,16}$/.test(clean);
    };
    var isValidLat = function (value) {
      if (!value) return true;
      var num = Number(value);
      return Number.isFinite(num) && num >= -90 && num <= 90;
    };
    var isValidLng = function (value) {
      if (!value) return true;
      var num = Number(value);
      return Number.isFinite(num) && num >= -180 && num <= 180;
    };
    var isValidZoom = function (value) {
      if (!value) return true;
      var num = Number(value);
      return Number.isInteger(num) && num >= 1 && num <= 22;
    };
    var clearFieldState = function (input) {
      input.classList.remove('invalid');
      input.classList.remove('is-valid');
      var next = input.nextElementSibling;
      if (next && next.classList && next.classList.contains('field-error')) {
        next.remove();
      }
    };
    var setFieldError = function (input, message) {
      clearFieldState(input);
      input.classList.add('invalid');
      var el = document.createElement('div');
      el.className = 'field-error';
      el.textContent = message;
      input.insertAdjacentElement('afterend', el);
    };
    var setFieldValid = function (input) {
      clearFieldState(input);
      input.classList.add('is-valid');
    };

    var saveForms = Array.prototype.slice.call(document.querySelectorAll('form[method="post"]')).filter(function (form) {
      var actionInput = form.querySelector('input[name="action"]');
      return actionInput && /^save_/.test(String(actionInput.value || ''));
    });
    saveForms.forEach(function (form) {
      var actionInput = form.querySelector('input[name="action"]');
      var formAction = actionInput ? String(actionInput.value || '') : '';
      var isProductSaveForm = formAction === 'save_product';
      var isArticleSaveForm = formAction === 'save_article';
      form.classList.add('admin-main-form');
      form.setAttribute('novalidate', 'novalidate');
      var summary = document.createElement('div');
      summary.className = 'validation-summary';
      summary.textContent = 'Periksa kembali form. Ada input yang belum valid.';
      form.insertAdjacentElement('afterbegin', summary);
      var progress = document.createElement('div');
      progress.className = 'submit-progress';
      progress.textContent = 'Menyimpan data... mohon tunggu.';
      form.insertAdjacentElement('afterbegin', progress);
      var submitBtn = form.querySelector('button[type="submit"]');
      var submitBtnLabel = submitBtn ? submitBtn.textContent : '';
      var pendingTimer = null;

      form.addEventListener('submit', function (event) {
        if (form.dataset.submitting === '1') {
          event.preventDefault();
          return;
        }
        form.dataset.submitting = '1';
        progress.classList.add('show');
        summary.classList.remove('show');
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.textContent = 'Menyimpan...';
        }
        pendingTimer = window.setTimeout(function () {
          progress.classList.add('show');
          progress.textContent = 'Proses simpan lebih lama dari biasanya. Jika belum berubah, cek koneksi lalu coba lagi.';
        }, 7000);

        var hasError = false;

        var inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(function (input) { clearFieldState(input); });

        // Product edit/save should stay non-blocking to avoid blocking updates (e.g., update marketplace links only).
        if (isProductSaveForm) {
          var slugInput = form.querySelector('input[name="slug"]');
          if (slugInput) {
            slugInput.value = normalizeSlug(slugInput.value);
          }
          var shopeeInput = form.querySelector('input[name="marketplace_shopee"]');
          if (shopeeInput) {
            var normalizedShopee = normalizeHttpUrl(shopeeInput.value);
            if (isValidHttpUrl(normalizedShopee)) shopeeInput.value = normalizedShopee;
          }
          var tokopediaInput = form.querySelector('input[name="marketplace_tokopedia"]');
          if (tokopediaInput) {
            var normalizedTokopedia = normalizeHttpUrl(tokopediaInput.value);
            if (isValidHttpUrl(normalizedTokopedia)) tokopediaInput.value = normalizedTokopedia;
          }
          var productPriceHidden = form.querySelector('input[name="price"]');
          if (productPriceHidden) {
            var raw = String(productPriceHidden.value || '').replace(/[^\d]/g, '');
            productPriceHidden.value = raw === '' ? '0' : raw;
          }
          return;
        }

        if (isArticleSaveForm && articleEditorSurface && articleContentHtml) {
          var html = String(articleEditorSurface.innerHTML || '').trim();
          articleContentHtml.value = html;
          if (articleExcerptSurface && articleExcerptHidden) {
            articleExcerptHidden.value = String(articleExcerptSurface.innerHTML || '').trim();
          }
        }

        inputs.forEach(function (input) {
          if (input.type === 'hidden' || input.disabled) return;
          var value = String(input.value || '').trim();
          var label = (input.closest('div') && input.closest('div').querySelector('label')) ? input.closest('div').querySelector('label').textContent : 'Field';
          var isRequired = input.hasAttribute('required');
          if (isRequired && !value) {
            hasError = true;
            setFieldError(input, (label || 'Field') + ' wajib diisi.');
            return;
          }
          if (!value) return;
          if (input.type === 'url' && !isValidHttpUrl(value)) {
            hasError = true;
            setFieldError(input, 'URL harus diawali http:// atau https://');
            return;
          }
          if (input.type === 'email' && !isValidEmail(value)) {
            hasError = true;
            setFieldError(input, 'Format email tidak valid.');
            return;
          }
          if (input.name === 'slug') {
            var normalizedSlug = normalizeSlug(value);
            input.value = normalizedSlug;
            value = normalizedSlug;
          }
          if (input.name === 'slug' && !isValidSlug(value)) {
            hasError = true;
            setFieldError(input, 'Slug hanya boleh huruf kecil, angka, dan tanda -');
            return;
          }
          if (input.name === 'marketplace_shopee' || input.name === 'marketplace_tokopedia') {
            var normalizedMarketplaceUrl = normalizeHttpUrl(value);
            if (normalizedMarketplaceUrl !== value && isValidHttpUrl(normalizedMarketplaceUrl)) {
              input.value = normalizedMarketplaceUrl;
              value = normalizedMarketplaceUrl;
            }
            // Marketplace link is optional and can be non-standard URL format.
            // Do not block submit for this field.
            setFieldValid(input);
            return;
          }
          if (input.name === 'social_whatsapp' && !isValidWhatsAppDigits(value)) {
            hasError = true;
            setFieldError(input, 'Nomor WhatsApp tidak valid. Gunakan format 628xxxx atau +628xxxx.');
            return;
          }
          if (input.name === 'contact_whatsapp_number' && !isValidWhatsAppDigits(value)) {
            hasError = true;
            setFieldError(input, 'Nomor WhatsApp utama tidak valid.');
            return;
          }
          if (input.name === 'map_lat' && !isValidLat(value)) {
            hasError = true;
            setFieldError(input, 'Latitude harus antara -90 sampai 90.');
            return;
          }
          if (input.name === 'map_lng' && !isValidLng(value)) {
            hasError = true;
            setFieldError(input, 'Longitude harus antara -180 sampai 180.');
            return;
          }
          if (input.name === 'map_zoom' && !isValidZoom(value)) {
            hasError = true;
            setFieldError(input, 'Zoom map harus angka 1 sampai 22.');
            return;
          }
          if (input.name === 'map_embed_url') {
            var normalizedMapEmbed = normalizeMapEmbedInput(value);
            input.value = normalizedMapEmbed;
            if (normalizedMapEmbed && !isValidHttpUrl(normalizedMapEmbed)) {
              hasError = true;
              setFieldError(input, 'Map Embed bisa URL atau iframe. Pastikan src diawali http:// atau https://');
              return;
            }
            setFieldValid(input);
            return;
          }
          if (/social_|marketplace_|_url$/.test(input.name) || input.name === 'map_embed_url' || input.type === 'url') {
            var normalizedUrl = normalizeHttpUrl(value);
            input.value = normalizedUrl;
            if (!isValidHttpUrl(normalizedUrl)) {
              hasError = true;
              setFieldError(input, 'URL tidak valid. Pastikan format http:// atau https://');
              return;
            }
          }
          if (/email/.test(input.name) && !isValidEmail(value)) {
            hasError = true;
            setFieldError(input, 'Format email tidak valid.');
            return;
          }
          setFieldValid(input);
        });

        var productPriceHidden = form.querySelector('input[name="price"]');
        if (productPriceHidden) {
          var productPriceRaw = String(productPriceHidden.value || '').replace(/[^\d]/g, '');
          if (productPriceRaw === '') {
            productPriceHidden.value = '0';
          }
          var productPrice = Number(String(productPriceHidden.value || '0').replace(/[^\d]/g, ''));
          if (!Number.isFinite(productPrice) || productPrice < 0) {
            hasError = true;
            var visible = form.querySelector('#price-display');
            if (visible) {
              setFieldError(visible, 'Nilai harga produk tidak valid.');
            }
          }
        }

        var checkFileInput = function (selector, allowExt, maxMb) {
          var fileInput = form.querySelector(selector);
          if (!fileInput || !fileInput.files || !fileInput.files.length) return;
          var maxBytes = maxMb * 1024 * 1024;
          for (var i = 0; i < fileInput.files.length; i += 1) {
            var f = fileInput.files[i];
            var ext = (f.name.split('.').pop() || '').toLowerCase();
            if (allowExt.indexOf(ext) === -1) {
              hasError = true;
              setFieldError(fileInput, 'Format file tidak didukung. Hanya: ' + allowExt.join(', ').toUpperCase());
              return;
            }
            if (f.size > maxBytes) {
              hasError = true;
              setFieldError(fileInput, 'Ukuran file maksimal ' + maxMb + 'MB per file.');
              return;
            }
          }
          setFieldValid(fileInput);
        };
        checkFileInput('input[name="image_file"]', ['jpg', 'jpeg', 'png'], 4);
        checkFileInput('input[name="gallery_files[]"]', ['jpg', 'jpeg', 'png'], 4);
        checkFileInput('input[name="service_gallery_files[]"]', ['jpg', 'jpeg', 'png'], 4);
        checkFileInput('input[name="project_gallery_files[]"]', ['jpg', 'jpeg', 'png'], 4);
        checkFileInput('input[name="avatar_file"]', ['jpg', 'jpeg', 'png'], 4);
        checkFileInput('input[name="brand_logo_file"]', ['jpg', 'jpeg', 'png'], 4);

        if (hasError) {
          summary.classList.add('show');
          if (pendingTimer) window.clearTimeout(pendingTimer);
          form.dataset.submitting = '0';
          progress.classList.remove('show');
          progress.textContent = 'Menyimpan data... mohon tunggu.';
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = submitBtnLabel;
          }
          event.preventDefault();
          var firstInvalid = form.querySelector('.invalid');
          if (firstInvalid) {
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstInvalid.focus();
          }
        }
      });
    });
  })();
</script>
</body>
</html>


