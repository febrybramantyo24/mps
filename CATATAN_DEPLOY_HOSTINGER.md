# Catatan Deploy Hostinger (Praktis)

Panduan singkat untuk deploy project ini ke Hostinger tanpa panik.

## 1) Sebelum Deploy
- Pastikan website lokal berjalan normal.
- Login admin dan klik **Export SQL Backup** dari `/admin/`.
- Simpan file `.sql` hasil export.
- Commit perubahan ke Git dulu.

Contoh:
```bash
git add .
git commit -m "prepare deploy hostinger"
git push origin main
```

## 2) Setup Database di Hostinger
- Masuk hPanel -> **Databases** -> buat MySQL baru.
- Catat:
  - `DB_HOST`
  - `DB_NAME`
  - `DB_USER`
  - `DB_PASS`
- Masuk phpMyAdmin Hostinger -> import file `.sql` hasil export admin.

## 3) Upload File Project
- Upload source ke `public_html` (boleh via File Manager / FTP).
- Pastikan folder penting ikut:
  - `admin/`
  - `api/`
  - `backend/`
  - `assets/`
  - `layanan/`
  - `produk/`
  - `proyek/`
- Pastikan folder upload bisa ditulis:
  - `assets/images/uploads/`

## 4) Ubah Konfigurasi Production
- Edit `backend/config.php` sesuai kredensial Hostinger.
- Set akun admin production:
  - `ADMIN_USER`
  - `ADMIN_PASS`

## 5) Smoke Test Setelah Live
- Buka halaman:
  - `/`
  - `/layanan/`
  - `/produk/`
  - `/proyek/`
  - `/admin/`
- Cek:
  - data tampil dari DB
  - upload gambar dari admin berhasil
  - detail page tidak error
  - pagination/filter berfungsi

## Workflow Fixing (Setelah Live)
Kalau ada bug:
1. Reproduce bug di lokal
2. Fix di lokal
3. Commit kecil dan jelas
4. Push ke Git
5. Deploy ulang file yang berubah
6. Retest di live

Contoh commit fixing:
```bash
git add .
git commit -m "fix layanan scroll jump on nav click"
git push origin main
```

## Catatan Penting
- Data lokal **tidak hilang** saat deploy ke Hostinger.
- Data hosting adalah DB terpisah, jadi wajib import `.sql` ke server.
- Backup rutin:
  - backup DB (`.sql`)
  - backup folder `assets/images/uploads/`

---
Referensi setup awal yang sudah ada: `backend/SETUP_HOSTINGER.md`
