<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

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
    // Basic safety: remove <script> blocks, keep the rest of HTML (admin controls content).
    return preg_replace('~<script\b[^>]*>[\s\S]*?</script>~i', '', $html) ?? '';
}

$slug = trim((string)($_GET['slug'] ?? ''));
$conn = db();

// Ensure schema exists (same as API).
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

$article = null;
$recent = [];
$categories = [];
$galleryPosts = [];
$popularTags = [];

if ($slug !== '') {
    $stmt = $conn->prepare(
        "SELECT id, slug, title, image, excerpt, content_html, author_name, category, published_at
         FROM articles
         WHERE slug = ? AND is_active = 1
         LIMIT 1"
    );
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $article = $stmt->get_result()->fetch_assoc() ?: null;

    if ($article) {
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
        while ($row = $recentResult->fetch_assoc()) {
            $recent[] = [
                'slug' => (string)$row['slug'],
                'title' => (string)$row['title'],
                'image' => (string)$row['image'],
                'publishedAt' => (string)($row['published_at'] ?? ''),
                'url' => '/artikel-detail/?slug=' . rawurlencode((string)$row['slug']),
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
        while ($categoryRow = $categoryResult->fetch_assoc()) {
            $name = trim((string)($categoryRow['category'] ?? ''));
            if ($name === '') continue;
            $categories[] = [
                'name' => $name,
                'total' => (int)($categoryRow['total'] ?? 0),
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
        while ($row = $galleryResult->fetch_assoc()) {
            $img = trim((string)($row['image'] ?? ''));
            if ($img === '') continue;
            $galleryPosts[] = [
                'slug' => (string)$row['slug'],
                'title' => (string)$row['title'],
                'image' => $img,
                'url' => '/artikel-detail/?slug=' . rawurlencode((string)$row['slug']),
            ];
        }

        foreach ($categories as $item) {
            $tag = trim((string)($item['name'] ?? ''));
            if ($tag === '') continue;
            $popularTags[] = [
                'name' => $tag,
                'url' => '/artikel/?category=' . rawurlencode($tag),
            ];
            if (count($popularTags) >= 10) break;
        }
    }
}

$title = $article ? (string)$article['title'] : 'Artikel Tidak Ditemukan';
$excerpt = $article ? (string)$article['excerpt'] : 'Slug artikel tidak ditemukan atau artikel sudah tidak aktif.';
$contentHtml = $article ? (string)($article['content_html'] ?? '') : '';
$author = $article ? (string)($article['author_name'] ?? 'Admin') : 'Admin';
$category = $article ? (string)($article['category'] ?? 'Artikel') : 'Artikel';
$publishedAt = $article ? (string)($article['published_at'] ?? '') : '';
$image = $article ? (string)($article['image'] ?? '') : '';

$pageTitle = $article ? ($title . ' | Artikel') : 'Artikel | MPS';
$metaDesc = meta_excerpt($excerpt !== '' ? $excerpt : $contentHtml);
$canonical = base_url() . '/artikel-detail/?slug=' . rawurlencode($slug);
$ogImage = $image !== '' ? abs_url($image) : abs_url('/assets/images/MPS.png');

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
  <meta property="og:type" content="article">
  <meta property="og:title" content="<?= esc($title) ?>">
  <meta property="og:description" content="<?= esc($metaDesc) ?>">
  <meta property="og:url" content="<?= esc($canonical) ?>">
  <meta property="og:image" content="<?= esc($ogImage) ?>">

  <link rel="stylesheet" href="/assets/css/vendor/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/plugins/fontawesome.css">
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/site-fixes.css">
  <style>
    .mps-article-hero { padding: 46px 0 18px; }
    .mps-article-hero .kicker { display:inline-flex; gap:8px; align-items:center; font-weight:800; font-size:12px; letter-spacing:.08em; text-transform:uppercase; color:#ff5e14; }
    .mps-hero-top { display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; margin-top: 10px; }
    .mps-back-chip { display:inline-flex; align-items:center; gap:8px; padding: 9px 14px; border-radius:999px; text-decoration:none; color:#0f172a; border:1px solid #dbe4ef; background:#fff; font-weight:900; transition:.2s ease; }
    .mps-back-chip:hover { border-color:#ff5e14; color:#ff5e14; transform: translateY(-1px); }
    .mps-article-crumb { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top: 0; color:#64748b; font-weight:800; font-size: 13px; }
    .mps-article-crumb a { color:#0f172a; text-decoration:none; }
    .mps-article-crumb a:hover { color:#ff5e14; }
    .mps-article-crumb .sep { opacity:.55; }
    .mps-article-crumb .current { color:#ff5e14; }
    .mps-article-hero h1 { font-size: clamp(28px, 3.2vw, 44px); line-height: 1.15; font-weight: 900; margin: 10px 0 10px; color:#0f172a; }
    .mps-article-meta { display:flex; flex-wrap:wrap; gap:12px; color:#64748b; font-weight:700; font-size:13px; }
    .mps-article-meta .dot { width:4px; height:4px; border-radius:999px; background:#cbd5e1; margin:0 2px; align-self:center; }
    .mps-article-cover { border-radius:16px; border:1px solid #e2e8f0; overflow:hidden; background:#f1f5f9; box-shadow: 0 18px 44px rgba(15,23,42,.08); }
    .mps-article-cover img { width:100%; height: clamp(220px, 38vw, 420px); object-fit: cover; display:block; }
    .mps-article-body { color:#334155; font-size:16px; line-height:1.85; }
    .mps-article-body h2 { margin: 26px 0 10px; font-size: 30px; font-weight: 900; color:#0f172a; line-height: 1.2; }
    .mps-article-body h3 { margin: 18px 0 8px; font-size: 22px; font-weight: 900; color:#0f172a; line-height: 1.25; }
    .mps-article-body p { margin: 0 0 16px; }
    .mps-article-body img { max-width: 100%; height: auto; border-radius: 12px; }
    .mps-article-body blockquote { margin: 16px 0; padding: 12px 14px; border-left: 4px solid #ff5e14; background: #fff7f2; border-radius: 10px; }
    .mps-aside .cardish { border:1px solid #d9e3ef; border-radius:14px; background: linear-gradient(180deg,#fff,#f8fbff); box-shadow: 0 10px 24px rgba(15,23,42,.07); overflow:hidden; margin-bottom:16px; }
    .mps-aside .cardish .hd { padding: 14px 16px 10px; border-bottom:1px solid #e4ebf3; background:#fff; }
    .mps-aside .cardish .hd h4 { margin:0; font-size:20px; font-weight:900; color:#0f172a; }
    .mps-aside .cardish .bd { padding: 14px 16px 16px; }
    .mps-aside .cat a { display:flex; align-items:center; justify-content:space-between; gap:10px; min-height:42px; border-radius:10px; border:1px solid #dce6f1; background:#fff; margin-bottom:8px; padding:0 12px; color:#334155; font-weight:800; text-decoration:none; transition:.2s ease; }
    .mps-aside .cat a:hover { border-color:#ff5e14; color:#ff5e14; transform: translateX(1px); }
    .mps-aside .recent a { display:flex; gap:10px; text-decoration:none; color:inherit; border:1px solid #dbe5f0; border-radius:12px; padding:10px; background:#fff; margin-bottom:10px; }
    .mps-aside .recent a:last-child { margin-bottom:0; }
    .mps-aside .recent img { width:64px; height:64px; border-radius:10px; object-fit:cover; flex:0 0 auto; }
    .mps-aside .recent .t { font-weight:900; color:#0f172a; line-height:1.25; font-size:14px; margin: 2px 0 4px; }
    .mps-aside .recent .d { color:#64748b; font-weight:700; font-size:12px; }
    .mps-aside .tags { display:flex; flex-wrap:wrap; gap:8px; }
    .mps-aside .tags a { text-decoration:none; font-weight:800; font-size:12px; padding:7px 10px; border-radius:999px; border:1px solid #dbe4ef; background:#fff; color:#334155; }
    .mps-aside .tags a:hover { border-color:#ff5e14; color:#ff5e14; }
    .mps-sharebar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top: 14px; }
    .mps-sharebar .lbl { font-size: 12px; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; color:#64748b; margin-right: 2px; }
    .mps-sharebar .btn { display:inline-flex; align-items:center; gap:8px; height: 40px; padding: 0 12px; border-radius: 999px; border: 1px solid #dbe4ef; background:#fff; color:#0f172a; text-decoration:none; font-weight: 900; transition: .2s ease; }
    .mps-sharebar .btn:hover { border-color:#ff5e14; color:#ff5e14; transform: translateY(-1px); }
    .mps-sharebar .btn.ghost { background: rgba(15,23,42,.03); }
    .mps-sharebar .btn span { font-size: 13px; }
    .mps-sharebar .btn.ok { border-color: rgba(34,197,94,.5); color:#166534; background: rgba(34,197,94,.08); }
  </style>
</head>
<body class="inner article-detail">
  <header class="header-two header--sticky">
    <div class="header-top">
      <div class="container">
        <div class="row">
          <div class="col-lg-12">
            <div class="header-top-wrapper">
              <div class="left">
                <div class="call"><i class="fa-light fa-mobile"></i><a href="#"></a><a href="#"></a></div>
                <div class="call"><i class="fa-solid fa-envelope"></i><a href="#"></a></div>
              </div>
              <div class="right">
                <div class="social-header"><span>Follow Us On:</span>
                  <ul>
                    <li><a href="#"><i class="fa-brands fa-facebook-f"></i></a></li>
                    <li><a href="#"><i class="fa-brands fa-twitter"></i></a></li>
                    <li><a href="#"><i class="fa-brands fa-whatsapp"></i></a></li>
                    <li><a href="#"><i class="fa-brands fa-linkedin-in"></i></a></li>
                  </ul>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="header-two-main-wrapper">
      <div class="container">
        <div class="row">
          <div class="col-lg-12">
            <div class="header-two-wrapper">
              <a href="/" class="logo-area">
                <span class="brand-lockup" aria-label="PT Maulana Prima Sejahtera">
                  <img class="brand-mark" src="/assets/images/MPS.png" alt="MPS">
                  <span class="brand-text">
                    <span class="brand-name">Maulana Prima</span>
                    <span class="brand-sub">Sejahtera</span>
                  </span>
                </span>
              </a>
              <div class="nav-area">
                <ul class="">
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
          <h4 class="title">Got a project in mind?</h4>
          <a href="#" class="rts-btn btn-primary"><i class="fa-brands fa-whatsapp"></i> Konsultasi via WhatsApp</a>
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

  <main class="rts-section-gap">
    <div class="container">
      <div class="row g-5">
        <div class="col-xl-8 col-12">
          <section class="mps-article-hero">
            <div class="kicker"><i class="fa-regular fa-file-lines"></i> Artikel</div>
            <div class="mps-hero-top">
              <a class="mps-back-chip" href="/artikel/"><i class="fa-regular fa-arrow-left"></i> Kembali ke Artikel</a>
              <div class="mps-article-crumb" aria-label="Breadcrumb">
                <a href="/">Home</a><span class="sep">/</span><a href="/artikel/">Artikel</a><span class="sep">/</span><span class="current" title="<?= esc($title !== '' ? $title : 'Detail') ?>"><?= esc($title !== '' ? $title : 'Detail') ?></span>
              </div>
            </div>
            <h1><?= esc($title) ?></h1>
            <div class="mps-article-meta">
              <span><i class="far fa-user-circle"></i> <?= esc($author) ?></span>
              <span class="dot"></span>
              <span><i class="far fa-clock"></i> <?= esc($publishedAt !== '' ? $publishedAt : '-') ?></span>
              <span class="dot"></span>
              <span><i class="far fa-tags"></i> <?= esc($category) ?></span>
            </div>
            <div class="mps-sharebar" aria-label="Share">
              <span class="lbl">Bagikan</span>
              <button type="button" class="btn" data-share="copy" aria-label="Copy link"><i class="fa-regular fa-copy"></i><span>Copy</span></button>
              <a class="btn" data-share="wa" href="#" target="_blank" rel="noopener" aria-label="Share WhatsApp"><i class="fa-brands fa-whatsapp"></i><span>WhatsApp</span></a>
              <a class="btn" data-share="li" href="#" target="_blank" rel="noopener" aria-label="Share LinkedIn"><i class="fa-brands fa-linkedin-in"></i><span>LinkedIn</span></a>
              <a class="btn" data-share="x" href="#" target="_blank" rel="noopener" aria-label="Share X"><i class="fa-brands fa-x-twitter"></i><span>X</span></a>
              <button type="button" class="btn ghost" data-share="native" aria-label="Share"><i class="fa-solid fa-arrow-up-from-bracket"></i><span>Share</span></button>
            </div>
          </section>

          <?php if ($image !== ''): ?>
          <div class="mps-article-cover mb--30">
            <img src="<?= esc($image) ?>" alt="<?= esc($title) ?>" loading="lazy">
          </div>
          <?php endif; ?>

          <article class="mps-article-body">
            <?php if ($article): ?>
              <?php
                $html = $contentHtml !== '' ? $contentHtml : nl2br(esc($excerpt));
                echo strip_scripts((string)$html);
              ?>
            <?php else: ?>
              <div class="alert alert-warning mb-0">Artikel tidak ditemukan atau slug belum diisi.</div>
            <?php endif; ?>
          </article>
        </div>

        <aside class="col-xl-4 col-12 mps-aside">
          <div class="cardish">
            <div class="hd"><h4>Kategori</h4></div>
            <div class="bd cat">
              <?php if (!$categories): ?>
                <div style="color:#64748b;font-weight:700;">Belum ada kategori.</div>
              <?php else: ?>
                <?php foreach ($categories as $c): ?>
                  <a href="<?= esc($c['url']) ?>"><span><?= esc($c['name']) ?></span><span style="opacity:.75;"><?= (int)$c['total'] ?></span></a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="cardish">
            <div class="hd"><h4>Artikel Terbaru</h4></div>
            <div class="bd recent">
              <?php if (!$recent): ?>
                <div style="color:#64748b;font-weight:700;">Belum ada artikel lain.</div>
              <?php else: ?>
                <?php foreach ($recent as $r): ?>
                  <a href="<?= esc($r['url']) ?>">
                    <img src="<?= esc($r['image'] ?: '/assets/images/blog/01.webp') ?>" alt="<?= esc($r['title']) ?>" loading="lazy">
                    <div>
                      <div class="t"><?= esc($r['title']) ?></div>
                      <div class="d"><?= esc($r['publishedAt'] ?: '-') ?></div>
                    </div>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="cardish">
            <div class="hd"><h4>Tag Populer</h4></div>
            <div class="bd tags">
              <?php if (!$popularTags): ?>
                <div style="color:#64748b;font-weight:700;">Belum ada tag.</div>
              <?php else: ?>
                <?php foreach ($popularTags as $t): ?>
                  <a href="<?= esc($t['url']) ?>"><?= esc($t['name']) ?></a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </aside>
      </div>
    </div>
  </main>

  <!-- Shared footer (full) -->
  <div class="rts-footer-area rts-section-gapTop bg_footer-1 bg_image">
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
      <div class="row g-5">
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
            <p class="disc">Kami berkomitmen untuk memberikan solusi konstruksi berkualitas tinggi yang disesuaikan dengan kebutuhan klien kami.</p>
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
        <div class="col-lg-8">
          <div class="footer-wrapper-right">
            <div class="single-nav-area-footer use-link">
              <h4 class="title">Useful Links</h4>
              <ul>
                <li><a href="/tentang/"><i class="fa-regular fa-arrow-right-long"></i>Tentang Kami</a></li>
                <li><a href="/layanan/"><i class="fa-regular fa-arrow-right-long"></i>Layanan Kami</a></li>
                <li><a href="/produk/"><i class="fa-regular fa-arrow-right-long"></i>Produk</a></li>
                <li><a href="/proyek/"><i class="fa-regular fa-arrow-right-long"></i>Proyek</a></li>
                <li><a href="/kontak/"><i class="fa-regular fa-arrow-right-long"></i>Hubungi Kami</a></li>
              </ul>
            </div>
            <div class="single-nav-area-footer news-letter">
              <h4 class="title">Newsletter</h4>
              <p>Update seputar proyek, tips teknis, dan insight lapangan dari tim kami.</p>
              <form action="#">
                <input type="email" placeholder="Email Address" required>
                <button class="btn-subscribe mt--15">Subscribe Now</button>
              </form>
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

  <div id="anywhere-home" class=""></div>
  <script src="/assets/js/plugins/jquery.js"></script>
  <script src="/assets/js/vendor/bootstrap.min.js"></script>
  <script src="/assets/js/plugins/metismenu.js"></script>
  <script src="/assets/js/main.js"></script>
  <script>
    (function () {
      var url = window.location.href;
      var title = document.querySelector('.mps-article-hero h1') ? (document.querySelector('.mps-article-hero h1').textContent || '').trim() : document.title;
      var sharebar = document.querySelector('.mps-sharebar');
      if (!sharebar) return;

      var enc = encodeURIComponent;
      var build = function () {
        var wa = 'https://wa.me/?text=' + enc(title + '\n' + url);
        var li = 'https://www.linkedin.com/sharing/share-offsite/?url=' + enc(url);
        var x = 'https://twitter.com/intent/tweet?text=' + enc(title) + '&url=' + enc(url);
        var setHref = function (key, href) {
          var a = sharebar.querySelector('a[data-share="' + key + '"]');
          if (a) a.setAttribute('href', href);
        };
        setHref('wa', wa);
        setHref('li', li);
        setHref('x', x);
      };
      build();

      var btnCopy = sharebar.querySelector('button[data-share="copy"]');
      if (btnCopy) {
        btnCopy.addEventListener('click', async function () {
          try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
              await navigator.clipboard.writeText(url);
            } else {
              var ta = document.createElement('textarea');
              ta.value = url;
              ta.style.position = 'fixed';
              ta.style.left = '-9999px';
              document.body.appendChild(ta);
              ta.select();
              document.execCommand('copy');
              document.body.removeChild(ta);
            }
            btnCopy.classList.add('ok');
            setTimeout(function () { btnCopy.classList.remove('ok'); }, 900);
          } catch (e) {}
        });
      }

      var btnNative = sharebar.querySelector('button[data-share="native"]');
      if (btnNative) {
        if (!navigator.share) {
          btnNative.style.display = 'none';
        } else {
          btnNative.addEventListener('click', function () {
            navigator.share({ title: title, text: title, url: url }).catch(function () {});
          });
        }
      }
    })();
  </script>
  <script>
    // Desktop: push sidebar widgets to start below the title block (hero),
    // so the right column aligns visually with the left content.
    (function () {
      function applyAsideOffset() {
        if (!window.matchMedia || !window.matchMedia('(min-width: 1200px)').matches) return;
        var hero = document.querySelector('.mps-article-hero');
        var aside = document.querySelector('.mps-aside');
        if (!hero || !aside) return;
        var h = hero.getBoundingClientRect().height || 0;
        // Small extra spacing so it doesn't feel glued to the hero bottom.
        var offset = Math.max(0, Math.round(h + 8));
        document.documentElement.style.setProperty('--mps-article-aside-offset', offset + 'px');
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyAsideOffset, { once: true });
      } else {
        applyAsideOffset();
      }
      window.addEventListener('resize', applyAsideOffset);
      if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(applyAsideOffset).catch(function () {});
      }
    })();
  </script>
</body>
</html>
