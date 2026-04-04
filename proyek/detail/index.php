<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/db.php';

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    return $scheme . '://' . $host;
}

function abs_url(string $url): string
{
    $raw = trim($url);
    if ($raw === '') return '';
    if (preg_match('~^https?://~i', $raw)) return $raw;
    if (str_starts_with($raw, '//')) return 'https:' . $raw;
    if (!str_starts_with($raw, '/')) $raw = '/' . $raw;
    return base_url() . $raw;
}

function meta_excerpt(string $text, int $limit = 155): string
{
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
    if ($plain === '') return '';
    if (mb_strlen($plain) <= $limit) return $plain;
    return rtrim(mb_substr($plain, 0, $limit - 1)) . '…';
}

function strip_scripts(string $html): string
{
    return preg_replace('~<script\b[^>]*>[\s\S]*?</script>~i', '', $html) ?? '';
}

function snippet(string $text, int $limit = 150): string
{
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
    if ($plain === '') return '';
    if (mb_strlen($plain) <= $limit) return $plain;
    return rtrim(mb_substr($plain, 0, $limit - 1)) . '…';
}

$slug = trim((string)($_GET['slug'] ?? ''));
$conn = db();

// Ensure schema exists (same as API).
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

$project = null;
$galleryImages = [];
$relatedProjects = [];

if ($slug !== '') {
    $stmt = $conn->prepare(
        "SELECT id, slug, title, image, client_name, location_name, project_year, duration_text, price_text, category, short_description, description, features_json, video_url
         FROM projects
         WHERE slug = ? AND is_active = 1
         LIMIT 1"
    );
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc() ?: null;

    if ($project) {
        $projectId = (int)$project['id'];
        $galleryStmt = $conn->prepare(
            "SELECT image_path
             FROM project_images
             WHERE project_id = ?
             ORDER BY sort_order ASC, id ASC"
        );
        $galleryStmt->bind_param('i', $projectId);
        $galleryStmt->execute();
        $galleryResult = $galleryStmt->get_result();
        while ($row = $galleryResult->fetch_assoc()) {
            $galleryImages[] = (string)$row['image_path'];
        }

        // Related projects pool (prefer same category, fallback to others)
        $cat = trim((string)($project['category'] ?? ''));
        $poolStmt = $conn->prepare(
            "SELECT slug, title, image, client_name, location_name, project_year, duration_text, price_text, category, short_description
             FROM projects
             WHERE is_active = 1 AND slug <> ?
             ORDER BY sort_order ASC, id DESC"
        );
        $poolStmt->bind_param('s', $slug);
        $poolStmt->execute();
        $poolRes = $poolStmt->get_result();
        $sameCat = [];
        $others = [];
        while ($r = $poolRes->fetch_assoc()) {
            $item = [
                'slug' => (string)($r['slug'] ?? ''),
                'title' => (string)($r['title'] ?? ''),
                'image' => (string)($r['image'] ?? ''),
                'client' => (string)($r['client_name'] ?? ''),
                'location' => (string)($r['location_name'] ?? ''),
                'projectYear' => (string)($r['project_year'] ?? ''),
                'duration' => (string)($r['duration_text'] ?? ''),
                'price' => (string)($r['price_text'] ?? ''),
                'category' => (string)($r['category'] ?? ''),
                'shortDescription' => (string)($r['short_description'] ?? ''),
                'url' => '/proyek/detail/?slug=' . rawurlencode((string)($r['slug'] ?? '')),
            ];
            if ($cat !== '' && strcasecmp($item['category'], $cat) === 0) {
                $sameCat[] = $item;
            } else {
                $others[] = $item;
            }
        }
        $relatedProjects = array_slice(array_merge($sameCat, $others), 0, 8);
    }
}

$title = $project ? (string)$project['title'] : 'Proyek Tidak Ditemukan';
  $short = $project ? (string)$project['short_description'] : 'Slug proyek tidak ditemukan atau proyek sudah tidak aktif.';
  // Normalize short description: remove empty lines so line breaks feel tight.
  $shortLines = preg_split("/\\R/", (string)$short);
  $shortClean = [];
  foreach ($shortLines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '') {
      continue;
    }
    $shortClean[] = $trimmed;
  }
  if (count($shortClean) > 0) {
    $shortHtml = '<p>' . implode('</p><p>', array_map('esc', $shortClean)) . '</p>';
  } else {
    $shortHtml = '<p>-</p>';
  }
$desc = $project ? (string)($project['description'] ?? '') : '';
$category = $project ? (string)($project['category'] ?? '') : '';
$client = $project ? (string)($project['client_name'] ?? '') : '';
$location = $project ? (string)($project['location_name'] ?? '') : '';
$projectYear = $project ? (string)($project['project_year'] ?? '') : '';
$duration = $project ? (string)($project['duration_text'] ?? '') : '';
$price = $project ? (string)($project['price_text'] ?? '') : '';
$videoUrl = $project ? (string)($project['video_url'] ?? '') : '';
$features = $project ? (json_decode((string)($project['features_json'] ?? '[]'), true) ?: []) : [];

$heroImage = $project ? (string)($project['image'] ?? '') : '';
if (!$galleryImages && $heroImage !== '') {
    $galleryImages = [$heroImage];
}

$pageTitle = $project ? ($title . ' | Proyek') : 'Detail Proyek | MPS';
$metaDesc = meta_excerpt($short !== '' ? $short : $desc);
$canonical = base_url() . '/proyek/detail/?slug=' . rawurlencode($slug);
$ogImage = ($heroImage !== '' ? abs_url($heroImage) : abs_url('/assets/images/MPS.png'));

?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="shortcut icon" type="image/x-icon" href="/assets/images/MPS.png">
  <title><?= esc($pageTitle) ?></title>
  <?php if ($metaDesc !== ''): ?>
  <meta name="description" content="<?= esc($metaDesc) ?>">
  <?php endif; ?>
  <link rel="canonical" href="<?= esc($canonical) ?>">
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= esc($title) ?>">
  <meta property="og:description" content="<?= esc($metaDesc) ?>">
  <meta property="og:url" content="<?= esc($canonical) ?>">
  <meta property="og:image" content="<?= esc($ogImage) ?>">

  <link rel="stylesheet" href="/assets/css/vendor/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/plugins/fontawesome.css">
  <link rel="stylesheet" href="/assets/css/plugins/swiper.css">
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/site-fixes.css">
  <style>
    :root { --bg:#f4f6fb; --card:#fff; --line:#e2e8f0; --text:#0f172a; --muted:#64748b; --brand:#ff5e14; }
    body { background: radial-gradient(circle at 0 0, #fff4ed 0%, var(--bg) 35%); color: var(--text); }
    .hero { position:relative; padding: 68px 0 26px; margin-bottom: 24px; background-image: linear-gradient(130deg, rgba(15,23,42,.92), rgba(30,41,59,.9)), url('/assets/images/banner/breadcrumb.webp'); background-size: cover; background-position: center; overflow:hidden; }
    .hero::before { content:""; position:absolute; inset:0; background: radial-gradient(circle at 75% -20%, rgba(255,94,20,.28), transparent 55%); }
    .hero-inner { position:relative; z-index:2; }
    .hero-top { display:flex; justify-content:space-between; gap:14px; flex-wrap:wrap; align-items:center; margin-bottom: 18px; }
    .back-chip { display:inline-flex; align-items:center; gap:8px; padding: 9px 14px; border-radius:999px; text-decoration:none; color:#fff; border:1px solid rgba(255,255,255,.25); font-weight:700; transition: .2s ease; }
    .back-chip:hover { color:#fff; background: rgba(255,255,255,.08); }
    .hero-breadcrumb { color:#cbd5e1; font-size: 13px; }
    .hero-breadcrumb a { color:#fff; text-decoration:none; }
    .hero-breadcrumb .sep { opacity: .65; padding: 0 6px; }
    .hero-breadcrumb .current { color:#ffb290; font-weight: 800; }
    .hero-title { margin:0 0 12px; color:#fff; font-size: clamp(34px, 4.5vw, 54px); line-height:1.08; max-width: 760px; font-weight: 900; }
    .hero-sub { margin:0; color:#dbe7ff; max-width: 860px; font-size: clamp(15px, 1.1vw, 18px); line-height: 1.55; }
    .hero-sub p { margin: 0 0 8px; }
    .hero-sub p:last-child { margin-bottom: 0; }
    .wrap { padding-bottom: 56px; }
    .card-shell { background: var(--card); border:1px solid var(--line); border-radius: 18px; box-shadow: 0 18px 40px rgba(15,23,42,.06); overflow:hidden; }
    .gallery-left { padding: 20px; border-right: 1px solid var(--line); background: linear-gradient(180deg,#f8fafc,#fff); height:100%; }
    .main-media { position:relative; border:1px solid #dbe3ed; border-radius:14px; overflow:hidden; min-height: 290px; background:#eef2f7; margin-bottom:10px; }
    .main-media img, .main-media iframe, .main-media video { width:100%; min-height:290px; height:100%; object-fit:cover; display:block; border:0; }
    .thumb-grid { display:flex; gap:8px; overflow-x:auto; padding:2px 2px 4px; }
    .thumb-item { border:1px solid #dbe3ed; border-radius:10px; overflow:hidden; cursor:pointer; background:#fff; flex: 0 0 92px; width:92px; }
    .thumb-item img { width:100%; height:78px; object-fit:cover; display:block; }
    .thumb-item.active { outline: 2px solid #ff5e14; border-color:#ff5e14; }
    .content { padding: 24px 24px 28px; }
    .content .title { margin:0 0 8px; font-size: 35px; line-height:1.15; color:#0f172a !important; font-weight: 900; }
    .short { color:#475569; margin-bottom: 10px; line-height: 1.55; }
    .short p { margin: 0 0 8px; }
    .short p:last-child { margin-bottom: 0; }
    .meta-row { display:grid; grid-template-columns: repeat(5, minmax(0,1fr)); gap:8px; margin: 12px 0; }
    .meta-box { border:1px solid var(--line); border-radius:10px; padding:10px; background:#fbfdff; }
    .meta-box .k { font-size: 11px; text-transform: uppercase; letter-spacing: .4px; color:#64748b; margin-bottom:2px; font-weight:800; }
    .meta-box .v { font-size: 13px; font-weight: 900; color:#0f172a; word-break: break-word; }
    .desc { color:#334155; line-height: 1.8; margin: 10px 0 10px; overflow-wrap: anywhere; word-break: break-word; }
    .desc > :first-child { margin-top: 0 !important; }
    .desc :where(p,div,ul,ol,table) { margin: 0 0 14px; }
    .desc :where(p,div,ul,ol,table):last-child { margin-bottom: 0; }
    .desc :where(h1,h2) { margin: 18px 0 10px; font-size: clamp(20px, 2.2vw, 28px); line-height: 1.2; color:#0f172a !important; font-weight: 900; }
    .desc :where(h3,h4) { margin: 14px 0 8px; font-size: 18px; line-height: 1.25; color:#0f172a !important; font-weight: 900; }
    .desc :where(ul,ol) { padding-left: 18px; }
    .desc :where(li) { margin: 6px 0; }
    .desc img { max-width:100%; height:auto; border-radius:12px; display:block; }
    .feature { border:1px solid #e5ecf6; border-radius:12px; background: linear-gradient(180deg,#fff,#f7fbff); padding:14px; margin-top: 12px; }
    .feature h3 { margin:0 0 3px; font-size: 20px; font-weight: 900; color:#0b1b38; }
    .feature p { margin:0 0 11px; font-size: 13px; color:#1f3a64; font-weight: 700; }
    .feature ul { list-style:none; margin:0; padding:0; display:grid; gap:4px; }
    .feature li { display:flex; gap:7px; padding:7px 2px 8px; border-bottom:1px dashed #dbe3ed; color:#0f2447; }
    .feature li:last-child { border-bottom:0; }
    .feature i { color: var(--brand); margin-top:2px; }
    .actions { margin-top: 18px; display:flex; gap:10px; flex-wrap:wrap; }
    .btn-solid { background: var(--brand); color:#fff; text-decoration:none; border-radius:10px; padding:10px 15px; font-weight: 900; }
    .btn-soft { background:#fff; border:1px solid #cbd5e1; color:#0f172a; text-decoration:none; border-radius:10px; padding:10px 15px; font-weight: 800; }
    .error { color:#b91c1c; }
    .related-projects-modern {
      padding-top: 58px !important;
      padding-bottom: 58px !important;
      background:
        radial-gradient(circle at 15% -20%, rgba(255, 94, 20, 0.24), transparent 55%),
        radial-gradient(circle at 95% 0%, rgba(14, 165, 233, 0.16), transparent 45%),
        #0b1220;
      border-top: 1px solid rgba(148, 163, 184, 0.25);
      border-bottom: 1px solid rgba(148, 163, 184, 0.25);
    }
    .related-projects-modern .related-head {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
      margin-bottom: 28px;
    }
    .related-projects-modern .related-head .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      letter-spacing: .22em;
      text-transform: uppercase;
      font-weight: 800;
      color: #ffb18b;
      margin-bottom: 10px;
    }
    .related-projects-modern .related-head h2 {
      margin: 0 0 8px;
      font-size: clamp(28px, 3.5vw, 40px);
      line-height: 1.1;
      color: #ffffff;
      font-weight: 900;
    }
    .related-projects-modern .related-head p {
      margin: 0;
      color: #cbd5e1;
      max-width: 620px;
    }
    .related-projects-modern .related-cta {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 16px;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.35);
      color: #ffffff;
      font-weight: 700;
      text-decoration: none;
      background: rgba(255, 255, 255, 0.08);
      transition: transform .2s ease, border-color .2s ease, background .2s ease;
    }
    .related-projects-modern .related-cta:hover {
      transform: translateY(-2px);
      border-color: #ff5e14;
      background: rgba(255, 94, 20, 0.25);
      color: #ffffff;
    }
    .related-projects-modern .related-controls {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .related-projects-modern .related-controls .swiper-button-next,
    .related-projects-modern .related-controls .swiper-button-prev {
      position: static;
      width: 40px;
      height: 40px;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.35);
      background: rgba(15, 23, 42, 0.6);
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: .2s ease;
    }
    .related-projects-modern .related-controls .swiper-button-next,
    .related-projects-modern .related-controls .swiper-button-prev {
      inset: auto !important;
      margin: 0 !important;
      transform: none !important;
    }
    .related-projects-modern .related-controls .swiper-button-next::after,
    .related-projects-modern .related-controls .swiper-button-prev::after {
      content: none !important;
    }
    .related-projects-modern .swiper-recent-project-5-wrapper {
      margin-top: 18px;
    }
    .related-projects-modern .swiper {
      overflow: visible;
    }
    .related-projects-modern .swiper-slide {
      height: auto;
    }
    .related-projects-modern .rp-card {
      border-radius: 18px;
      overflow: hidden;
      background: transparent;
      border: 0;
      box-shadow: none;
      transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
      height: 100%;
      display: flex;
      flex-direction: column;
    }
    .related-projects-modern .rp-card:hover {
      transform: translateY(-4px);
      border-color: rgba(255, 94, 20, 0.55);
      box-shadow: 0 22px 44px rgba(2, 6, 23, 0.45);
    }
    .related-projects-modern .rp-thumb {
      position: relative;
      aspect-ratio: 16 / 9;
      overflow: hidden;
      background: #0f172a;
      border-radius: 18px;
    }
    .related-projects-modern .rp-thumb::after {
      content: "";
      position: absolute;
      inset: 0;
      background:
        linear-gradient(180deg, rgba(2,6,23,0.22) 0%, rgba(2,6,23,0.78) 55%, rgba(2,6,23,0.98) 100%);
      z-index: 1;
      pointer-events: none;
    }
    .related-projects-modern .rp-thumb img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      object-position: center;
      display: block;
      transition: transform .35s ease;
      position: relative;
      z-index: 0;
    }
    .related-projects-modern .rp-card:hover .rp-thumb img {
      transform: scale(1.04);
    }
    .related-projects-modern .rp-badge {
      position: absolute;
      left: 14px;
      top: 14px;
      padding: 6px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: .04em;
      text-transform: uppercase;
      color: #fff;
      background: rgba(15, 23, 42, 0.55);
      border: 1px solid rgba(255, 255, 255, 0.25);
      backdrop-filter: blur(6px);
      z-index: 2;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .related-projects-modern .rp-badge i {
      font-size: 11px;
      color: #ffb18b;
    }
    .related-projects-modern .rp-body {
      position: absolute;
      left: 18px;
      right: 18px;
      bottom: 18px;
      background: rgba(255, 255, 255, 0.88);
      border-radius: 14px;
      padding: 14px;
      box-shadow: 0 14px 28px rgba(2, 6, 23, 0.35);
      display: grid;
      gap: 10px;
      max-width: 64%;
      opacity: 0;
      transform: translateY(10px);
      pointer-events: none;
      transition: opacity .2s ease, transform .2s ease;
      z-index: 2;
    }
    .related-projects-modern .rp-card:hover .rp-body {
      opacity: 1;
      transform: translateY(0);
      pointer-events: auto;
    }
    .related-projects-modern .rp-title {
      margin: 0;
      color: #0b1220;
      font-size: 17px;
      font-weight: 900;
      line-height: 1.3;
    }
    .related-projects-modern .rp-desc {
      margin: 0;
      color: #334155;
      font-size: 12.5px;
      line-height: 1.6;
      min-height: 36px;
    }
    .related-projects-modern .rp-meta {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px;
    }
    .related-projects-modern .rp-meta .item {
      border: 1px solid #e5edf7;
      border-radius: 10px;
      padding: 6px 8px;
      background: #f8fbff;
    }
    .related-projects-modern .rp-meta span {
      display: block;
      font-size: 10px;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: .05em;
      font-weight: 700;
      margin-bottom: 4px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .related-projects-modern .rp-meta span i {
      color: #ff5e14;
      font-size: 11px;
    }
    .related-projects-modern .rp-meta strong {
      display: block;
      color: #0b1220;
      font-size: 11px;
      font-weight: 800;
      line-height: 1.3;
      word-break: break-word;
    }
    .related-projects-modern .rp-cta {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 11px;
      border-radius: 10px;
      background: linear-gradient(135deg, #ff6a21, #ff4f0f);
      color: #fff;
      text-decoration: none;
      font-weight: 700;
      font-size: 12.5px;
      width: fit-content;
      transition: transform .2s ease, box-shadow .2s ease;
      box-shadow: 0 10px 20px rgba(255, 94, 20, 0.3);
    }
    .related-projects-modern .rp-cta:hover {
      transform: translateY(-1px);
      box-shadow: 0 12px 22px rgba(255, 94, 20, 0.4);
    }
    @media (max-width: 991px) {
      .related-projects-modern .rp-body {
        opacity: 1;
        transform: none;
        pointer-events: auto;
        max-width: 100%;
      }
      .related-projects-modern .rp-body {
        position: static;
        margin-top: -10px;
        border-radius: 0 0 14px 14px;
      }
      .related-projects-modern .rp-thumb {
        border-radius: 18px 18px 0 0;
      }
      .related-projects-modern .related-controls {
        width: 100%;
        justify-content: flex-start;
        gap: 10px;
        margin-top: 10px;
      }
    }
    .related-projects-modern .related-controls .swiper-button-next:hover,
    .related-projects-modern .related-controls .swiper-button-prev:hover {
      border-color: #ff5e14;
      background: #ff5e14;
      color: #fff;
    }
    @media (max-width: 991px) {
      .related-projects-modern .related-head { align-items: flex-start; }
    }
    @media (max-width: 991px) { .hero { padding-top: 34px; } .meta-row { grid-template-columns: 1fr 1fr; } .gallery-left { border-right:0; border-bottom: 1px solid var(--line); } .content .title { font-size: 28px; } }
  </style>
</head>
<body class="project-detail">
  <header class="header-two header--sticky">
    <div class="header-two-main-wrapper">
      <div class="container">
        <div class="header-two-wrapper">
          <a href="/" class="logo-area" aria-label="PT Maulana Prima Sejahtera">
            <span class="brand-lockup">
              <img class="brand-mark" src="/assets/images/MPS.png" alt="MPS">
              <span class="brand-text"><span class="brand-name">Maulana Prima</span><span class="brand-sub">Sejahtera</span></span>
            </span>
          </a>
          <div class="nav-area">
            <ul>
              <li class="main-nav"><a href="/">Home</a></li>
              <li class="main-nav"><a href="/layanan/">Layanan</a></li>
              <li class="main-nav"><a href="/produk/">Produk</a></li>
              <li class="main-nav"><a href="/proyek/">Proyek</a></li>
              <li class="main-nav"><a href="/artikel/">Artikel</a></li>
              <li class="main-nav"><a href="/kontak/">Kontak</a></li>
              <li class="main-nav"><a href="/tentang/">Tentang</a></li>
            </ul>
          </div>
          <div class="header-end">
            <a href="#" class="rts-btn btn-primary"><i class="fa-brands fa-whatsapp"></i> Konsultasi WhatsApp</a>
            <div class="nav-btn menu-btn"><img src="/assets/images/logo/bar.svg" alt="menu"></div>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div id="side-bar" class="side-bar header-two">
    <button class="close-icon-menu"><i class="far fa-times"></i></button>
    <div class="inner-main-wrapper-desk">
      <div class="thumbnail"><img src="/assets/images/banner/04.jpg" alt="elevate"></div>
      <div class="inner-content">
        <h4 class="title">Solusi Profesional untuk Ducting, Hydrant, dan Electrical.</h4>
        <p class="disc">Konsultasi awal tanpa biaya. Tim kami bantu rekomendasikan scope pekerjaan, timeline, dan estimasi kebutuhan teknis Anda.</p>
        <div class="footer">
          <h4 class="title">Butuh bantuan cepat?</h4>
          <a href="/kontak/" class="rts-btn btn-primary"><i class="fa-regular fa-envelope"></i> Hubungi Kami</a>
        </div>
      </div>
    </div>
    <div class="mobile-menu d-block d-xl-none">
      <nav class="nav-main mainmenu-nav mt--30">
        <ul class="mainmenu metismenu" id="mobile-menu-active"></ul>
      </nav>
      <div class="social-wrapper-one">
        <ul>
          <li><a href="#"><i class="fa-brands fa-facebook-f"></i></a></li>
          <li><a href="#"><i class="fa-brands fa-twitter"></i></a></li>
          <li><a href="#"><i class="fa-brands fa-youtube"></i></a></li>
          <li><a href="#"><i class="fa-brands fa-linkedin-in"></i></a></li>
        </ul>
      </div>
    </div>
  </div>

  <section class="hero">
    <div class="container hero-inner">
      <div class="hero-top">
        <a class="back-chip" href="/proyek/"><i class="fa-regular fa-arrow-left"></i> Kembali ke Proyek</a>
        <div class="hero-breadcrumb" aria-label="Breadcrumb">
          <a href="/">Home</a><span class="sep">/</span><a href="/proyek/">Proyek</a><span class="sep">/</span><span class="current"><?= esc($project ? 'Detail' : 'Error') ?></span>
        </div>
      </div>
      <h1 class="hero-title"><?= esc($title) ?></h1>
      <div class="hero-sub"><?= $shortHtml ?></div>
    </div>
  </section>

  <section class="wrap">
    <div class="container">
      <div class="card-shell">
        <div class="row g-0">
          <div class="col-lg-5">
            <div class="gallery-left">
              <div class="main-media" id="main-media">
                <?php if ($galleryImages): ?>
                  <img id="main-image" src="<?= esc($galleryImages[0]) ?>" alt="<?= esc($title) ?>" loading="lazy">
                <?php elseif ($heroImage !== ''): ?>
                  <img id="main-image" src="<?= esc($heroImage) ?>" alt="<?= esc($title) ?>" loading="lazy">
                <?php else: ?>
                  <img id="main-image" src="/assets/images/portfolio/05.webp" alt="Proyek" loading="lazy">
                <?php endif; ?>
              </div>
              <?php if ($galleryImages && count($galleryImages) > 1): ?>
              <div class="thumb-grid" id="thumb-grid">
                <?php foreach ($galleryImages as $idx => $img): ?>
                  <div class="thumb-item <?= $idx === 0 ? 'active' : '' ?>" data-img="<?= esc($img) ?>">
                    <img src="<?= esc($img) ?>" alt="thumb" loading="lazy">
                  </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
              <?php if ($videoUrl !== ''): ?>
                <div style="margin-top:12px;">
                  <a class="btn-soft" href="<?= esc($videoUrl) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-play"></i> Lihat Video</a>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-lg-7">
            <div class="content">
              <?php if (!$project): ?>
                <div class="error">Proyek tidak ditemukan atau slug belum diisi.</div>
              <?php endif; ?>
              <?php if ($category !== ''): ?>
                <div style="font-weight:900;color:#ff5e14;text-transform:uppercase;letter-spacing:.08em;font-size:11px;"><?= esc($category) ?></div>
              <?php endif; ?>
              <h2 class="title"><?= esc($title) ?></h2>
              <div class="short"><?= $shortHtml ?></div>

              <div class="meta-row">
                <div class="meta-box"><div class="k">Client</div><div class="v"><?= esc($client !== '' ? $client : '-') ?></div></div>
                <div class="meta-box"><div class="k">Location</div><div class="v"><?= esc($location !== '' ? $location : '-') ?></div></div>
                <div class="meta-box"><div class="k">Project Year</div><div class="v"><?= esc($projectYear !== '' ? $projectYear : '-') ?></div></div>
                <div class="meta-box"><div class="k">Duration</div><div class="v"><?= esc($duration !== '' ? $duration : '-') ?></div></div>
                <div class="meta-box"><div class="k">Price</div><div class="v"><?= esc($price !== '' ? $price : '-') ?></div></div>
              </div>

              <?php if (trim($desc) !== ''): ?>
              <div class="desc">
                <?= strip_scripts($desc) ?>
              </div>
              <?php endif; ?>

              <?php if (is_array($features) && count($features) > 0): ?>
              <div class="feature">
                <h3>Keunggulan Proyek</h3>
                <p>Dirancang untuk hasil rapi, minim risiko, dan memberi dampak bisnis yang terasa sejak awal operasional.</p>
                <ul>
                  <?php foreach ($features as $f): ?>
                    <?php if (trim((string)$f) === '') continue; ?>
                    <li><i class="fa-solid fa-circle-check"></i><span><?= esc((string)$f) ?></span></li>
                  <?php endforeach; ?>
                </ul>
              </div>
              <?php endif; ?>

              <div class="actions">
                <a href="/kontak/" class="btn-solid"><i class="fa-regular fa-envelope"></i> Konsultasi Proyek</a>
                <a href="/proyek/" class="btn-soft">Lihat Semua Proyek</a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <?php if ($project && $relatedProjects): ?>
  <section id="related-projects-slider" class="rts-portfolio-area-case rts-section-gap related-projects-modern">
    <div class="container">
      <div class="related-head">
        <div class="copy">
          <span class="eyebrow">OUR WORKS</span>
          <h2>Proyek Terkait</h2>
          <p>Inspirasi proyek modern yang kami kerjakan untuk berbagai kebutuhan industri dan komersial.</p>
        </div>
        <div class="related-controls">
          <a class="related-cta" href="/proyek/">Lihat Semua Proyek</a>
          <div class="swiper-button-prev"><i class="fa-sharp-duotone fa-light fa-arrow-left"></i></div>
          <div class="swiper-button-next"><i class="fa-sharp-duotone fa-light fa-arrow-right"></i></div>
        </div>
      </div>
    </div>
    <div class="container-full">
      <div class="row">
        <div class="col-lg-12">
          <div class="swiper-recent-project-5-wrapper">
            <div class="swiper mySwiper-case-5-related">
              <div class="swiper-wrapper">
                <?php foreach ($relatedProjects as $rp): ?>
                  <div class="swiper-slide">
                    <article class="rp-card">
                      <a class="rp-thumb" href="<?= esc($rp['url']) ?>">
                        <img src="<?= esc($rp['image'] !== '' ? $rp['image'] : '/assets/images/portfolio/16.webp') ?>" alt="<?= esc($rp['title']) ?>" loading="lazy">
                        <span class="rp-badge"><i class="fa-regular fa-pen-ruler"></i><?= esc($rp['category'] !== '' ? $rp['category'] : 'Project') ?></span>
                      </a>
                      <div class="rp-body">
                        <h3 class="rp-title"><?= esc($rp['title']) ?></h3>
                        <p class="rp-desc"><?= esc(snippet($rp['shortDescription'] !== '' ? $rp['shortDescription'] : 'Lihat detail proyek untuk informasi lengkap.', 110)) ?></p>
                        <div class="rp-meta">
                          <div class="item">
                            <span><i class="fa-regular fa-user"></i>Client</span>
                            <strong><?= esc($rp['client'] !== '' ? $rp['client'] : '-') ?></strong>
                          </div>
                          <div class="item">
                            <span><i class="fa-regular fa-calendar"></i>Year</span>
                            <strong><?= esc($rp['projectYear'] !== '' ? $rp['projectYear'] : '-') ?></strong>
                          </div>
                          <div class="item">
                            <span><i class="fa-regular fa-location-dot"></i>Location</span>
                            <strong><?= esc($rp['location'] !== '' ? $rp['location'] : '-') ?></strong>
                          </div>
                          <div class="item">
                            <span><i class="fa-regular fa-clock"></i>Duration</span>
                            <strong><?= esc($rp['duration'] !== '' ? $rp['duration'] : '-') ?></strong>
                          </div>
                        </div>
                        <a class="rp-cta" href="<?= esc($rp['url']) ?>"><i class="fa-regular fa-eye"></i> Lihat Detail</a>
                      </div>
                    </article>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- Shared footer (full) -->
  <div class="rts-footer-area three rts-section-gapTop bg_footer-1 bg_image">
    <div class="container">
      <div class="row">
        <div class="col-lg-12">
          <div class="contact-area-footer-top">
            <div class="single-contact-area-box">
              <div class="icon"><i class="fas fa-phone-alt"></i></div>
              <h6 class="title">Call Us Now</h6>
              <a id="footer-phone-1" href="#">-</a>
              <a id="footer-phone-2" href="#">-</a>
            </div>
            <div class="single-contact-area-box">
              <div class="icon"><i class="fa-solid fa-clock"></i></div>
              <h6 class="title">Office Time</h6>
              <a id="footer-hours-1" href="#">-</a>
            </div>
            <div class="single-contact-area-box">
              <div class="icon"><i class="fa-solid fa-envelope"></i></div>
              <h6 class="title">Need Support</h6>
              <a id="footer-email-1" href="#">-</a>
              <a id="footer-email-2" href="#">-</a>
            </div>
            <div class="single-contact-area-box">
              <div class="icon"><i class="fa-sharp fa-solid fa-location-dot"></i></div>
              <h6 class="title">Our Address</h6>
              <a id="footer-address-1" href="#">-</a>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="container-full">
      <div class="row">
        <div class="col-lg-12">
          <div class="nav-footer-wrapper-one">
            <div class="container">
              <div class="row">
                <div class="col-lg-12">
                  <ul class="footer-float-nav">
                    <li><a href="/tentang/">Tentang Kami</a></li>
                    <li><a href="/proyek/">Projects</a></li>
                    <li><a href="/proyek/">Updates</a></li>
                    <li><a href="/tentang/">Mission</a></li>
                    <li><a href="/tentang/">Inside</a></li>
                    <li><a href="/kontak/">Contact</a></li>
                    <li><a href="/tentang/">History</a></li>
                  </ul>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="container">
      <div class="row">
        <div class="col-lg-4">
          <div class="footer-wrapper-left-one">
            <a href="/" class="logo">
              <span class="brand-lockup" aria-label="PT Maulana Prima Sejahtera">
                <img class="brand-mark" src="/assets/images/MPS.png" alt="MPS">
                <span class="brand-text">
                  <span class="brand-name">Maulana Prima</span>
                  <span class="brand-sub">Sejahtera</span>
                </span>
              </span>
            </a>
            <p class="disc">
              Kami berkomitmen untuk memberikan solusi konstruksi berkualitas tinggi yang disesuaikan dengan kebutuhan klien kami. Dengan fokus yang kuat pada inovasi, ketelitian, dan keahlian, kami membangun ruang yang tahan lama.
            </p>
            <div class="social-area-wrapper-one">
              <ul>
                <li><a href="#"><i class="fa-brands fa-facebook-f"></i></a></li>
                <li><a href="#"><i class="fa-brands fa-twitter"></i></a></li>
                <li><a href="#"><i class="fa-brands fa-youtube"></i></a></li>
                <li><a href="#"><i class="fa-brands fa-linkedin-in"></i></a></li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="container-full copyright-area-one">
      <div class="row">
        <div class="col-lg-12">
          <div class="container">
            <div class="row">
              <div class="col-lg-12">
                <div class="copyright-wrapper">
                  <p class="mb-0">Copyright &copy;
                    <script>document.write(new Date().getFullYear())</script>
                    Maulana Prima Sejahtera
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Lightweight gallery: swap main image on thumbnail click (SSR-friendly).
    (function () {
      var grid = document.getElementById('thumb-grid');
      var main = document.getElementById('main-image');
      if (!grid || !main) return;
      grid.addEventListener('click', function (e) {
        var item = e.target.closest('.thumb-item');
        if (!item) return;
        var img = item.getAttribute('data-img') || '';
        if (!img) return;
        main.src = img;
        grid.querySelectorAll('.thumb-item').forEach(function (n) { n.classList.remove('active'); });
        item.classList.add('active');
      });
    })();
  </script>
  <script>
    // Fill footer contact info from Site Settings (keep in sync with homepage).
    (function () {
      var footerPhone1 = document.getElementById('footer-phone-1');
      var footerPhone2 = document.getElementById('footer-phone-2');
      var footerHours1 = document.getElementById('footer-hours-1');
      var footerEmail1 = document.getElementById('footer-email-1');
      var footerEmail2 = document.getElementById('footer-email-2');
      var footerAddress1 = document.getElementById('footer-address-1');
      var waButtons = Array.prototype.slice.call(document.querySelectorAll('a.rts-btn, a.btn-consult'));

      var normalizeUrl = function (value) {
        var raw = String(value || '').trim();
        if (!raw) return '';
        if (/^https?:\/\//i.test(raw)) return raw;
        if (/^\/\//.test(raw)) return 'https:' + raw;
        return 'https://' + raw;
      };

      var setAnchorText = function (anchor, value, href) {
        if (!anchor) return;
        var text = String(value || '').trim();
        if (!text) {
          anchor.textContent = '-';
          anchor.removeAttribute('href');
          return;
        }
        anchor.textContent = text;
        anchor.href = href || '#';
      };

      var applyWhatsappButtons = function (settings) {
        var waUrl = normalizeUrl(settings.whatsapp || '');
        waButtons.forEach(function (anchor) {
          var text = String(anchor.textContent || '').toLowerCase();
          if (text.indexOf('whatsapp') === -1 && !/\bwa\b/.test(text)) return;
          if (!waUrl) {
            anchor.removeAttribute('href');
            return;
          }
          anchor.href = waUrl;
          anchor.target = '_blank';
          anchor.rel = 'noopener';
        });
      };

      var applyFooterSettings = function (settings) {
        var pick = function (obj, camelKey, snakeKey) {
          var camel = obj && obj[camelKey];
          if (typeof camel === 'string' && camel.trim()) return camel;
          var snake = obj && obj[snakeKey];
          if (typeof snake === 'string' && snake.trim()) return snake;
          return '';
        };
        var p1 = String(pick(settings, 'footerPhonePrimary', 'footer_phone_primary')).trim();
        var p2 = String(pick(settings, 'footerPhoneSecondary', 'footer_phone_secondary')).trim();
        var e1 = String(pick(settings, 'footerSupportEmailPrimary', 'footer_support_email_primary')).trim();
        var e2 = String(pick(settings, 'footerSupportEmailSecondary', 'footer_support_email_secondary')).trim();
        var officeHours1 = String(pick(settings, 'footerOfficeHours1', 'footer_office_hours_1')).trim();
        var address1 = String(pick(settings, 'footerAddress1', 'footer_address_1')).trim();
        setAnchorText(footerPhone1, p1, p1 ? ('tel:' + p1.replace(/\\s+/g, '')) : '');
        setAnchorText(footerPhone2, p2, p2 ? ('tel:' + p2.replace(/\\s+/g, '')) : '');
        setAnchorText(footerHours1, officeHours1, '');
        setAnchorText(footerEmail1, e1, e1 ? ('mailto:' + e1) : '');
        setAnchorText(footerEmail2, e2, e2 ? ('mailto:' + e2) : '');
        setAnchorText(footerAddress1, address1, '');
      };

      fetch('/api/site-settings.php', { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok || !data.settings) return;
          applyWhatsappButtons(data.settings);
          applyFooterSettings(data.settings);
        })
        .catch(function () {
          applyWhatsappButtons({});
          applyFooterSettings({});
        });
    })();
  </script>
  <script>
    // Trim leading empty blocks from richtext (common from copy/paste).
    (function () {
      var root = document.querySelector('body.project-detail .desc');
      if (!root) return;
      var isBlank = function (el) {
        if (!el || el.nodeType !== 1) return false;
        var tag = el.tagName;
        if (tag !== 'P' && tag !== 'DIV') return false;
        if (el.querySelector('img,iframe,video,table,ul,ol')) return false;
        var text = (el.textContent || '').replace(/\u00a0/g, ' ').trim();
        return text === '';
      };
      while (root.firstElementChild && isBlank(root.firstElementChild)) {
        root.removeChild(root.firstElementChild);
      }
    })();
  </script>
  <script src="/assets/js/plugins/jquery.js"></script>
  <script src="/assets/js/vendor/bootstrap.min.js"></script>
  <script src="/assets/js/plugins/metismenu.js"></script>
  <script src="/assets/js/plugins/swiper.js"></script>
  <script src="/assets/js/main.js"></script>
  <script>
    (function () {
      var root = document.getElementById('related-projects-slider');
      if (!root) return;
      if (typeof Swiper === 'undefined') return;
      var node = root.querySelector('.mySwiper-case-5-related');
      if (!node) return;
      var nextEl = root.querySelector('.swiper-button-next');
      var prevEl = root.querySelector('.swiper-button-prev');
      try {
        new Swiper(node, {
          spaceBetween: 18,
          slidesPerView: 3,
          loop: true,
          speed: 900,
          centeredSlides: false,
          navigation: {
            nextEl: nextEl,
            prevEl: prevEl,
          },
          breakpoints: {
            1400: { slidesPerView: 3 },
            1200: { slidesPerView: 2.5 },
            992: { slidesPerView: 2 },
            768: { slidesPerView: 1.3 },
            576: { slidesPerView: 1.1 },
            0: { slidesPerView: 1 }
          }
        });
      } catch (e) {}
    })();
  </script>
</body>
</html>
