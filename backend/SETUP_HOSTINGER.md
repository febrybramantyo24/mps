# Setup Shop PHP + MySQL (Hostinger)

## 1) Buat database di hPanel
- Buat database MySQL baru.
- Catat `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.

## 2) Import schema dan seed
- Buka phpMyAdmin.
- Import file:
  - `backend/schema.sql`
  - `backend/seed.sql` (opsional, untuk data awal)

## 3) Ubah konfigurasi
- Edit file `backend/config.php`:
  - `DB_HOST`
  - `DB_NAME`
  - `DB_USER`
  - `DB_PASS`
  - `ADMIN_USER`
  - `ADMIN_PASS`

## 4) Upload project
- Upload semua file ke `public_html`.
- Pastikan folder ini ikut ter-upload:
  - `api/`
  - `admin/`
  - `backend/`
  - `produk/`
  - `assets/`

## 5) Akses
- Shop: `/produk/`
- Detail: `/produk/detail/?slug=pipa-hydrant`
- Dashboard admin: `/admin/`

## Catatan penting
- Review user masuk status `pending` dan perlu di-approve di `/admin/`.
- Fitur upload gambar admin membutuhkan izin tulis pada folder:
  - `assets/images/uploads/products`
- Untuk production, sangat disarankan tambah login admin (auth/session) sebelum go live.
