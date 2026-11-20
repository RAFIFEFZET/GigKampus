# GigKampus

GigKampus berjalan di Docker Compose dengan Laravel, PostgreSQL, dua worker database queue, dan Reverb. Acuan implementasi ada di [PRD](PRD.md), [ERD dan arsitektur](ERD_ARCHITECTURE.md), serta [daftar versi runtime](TECH_STACK.md#14-technology-versions).

## Menjalankan di Windows

Jalankan dari PowerShell di direktori repo dengan Docker Desktop dalam mode Linux containers.

```powershell
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
docker compose build

if ((Get-Content .env -Raw) -match '(?m)^APP_KEY=\r?$') {
    $key = docker compose run --rm --no-deps --entrypoint php app artisan key:generate --show
    if ($LASTEXITCODE -ne 0 -or $key -notmatch '^base64:') { throw 'Gagal membuat APP_KEY' }
    (Get-Content .env -Raw) -replace '(?m)^APP_KEY=\r?$', "APP_KEY=$key" |
        Set-Content -Encoding ascii .env
}

docker compose up -d --wait
```

- Buka [halaman status](http://localhost:8000) untuk mengecek Laravel dan PostgreSQL.
- Dari host, gunakan PostgreSQL di `127.0.0.1:5433` dengan kredensial dari `.env`. Dari container, gunakan `postgres:5432`.
- Reverb memakai `ws://localhost:8000` melalui Nginx. Laravel mengirim broadcast melalui jaringan Compose ke `reverb:8080`.

Saat app mulai, Laravel menjalankan migrasi untuk 21 tabel bisnis serta tiga tabel operasional `migrations`, `jobs`, dan `failed_jobs`. Worker `queue-ocr` memproses `ocr` dengan maksimum dua percobaan dan timeout 45 detik. Worker `queue-default` memproses `default` untuk notifikasi dan broadcast. Queue database menjadwalkan pekerjaan setelah transaksi commit.

Disk `public` dilayani di `/storage`. Disk `private` menjadi default dan hanya dipasang pada container backend. Database serta kedua disk menggunakan volume persisten. Setelah mengubah source atau dependensi, jalankan `docker compose up -d --build --wait` untuk mengganti image. Gunakan `docker compose down` untuk menghentikan stack dengan volume tetap tersimpan.

## Data demo lokal

```powershell
docker compose exec -T -u www-data app php artisan db:seed
```

Seeder mengisi skenario pada ERD dan menyalin fixture sintetis dari `resources/demo-fixtures` ke direktori `demo/` pada disk publik atau privat yang sesuai. Seeder dapat dijalankan ulang untuk melengkapi fixture tanpa mereset perkembangan demo.

| Akun | Email |
|---|---|
| Admin | `admin@gigkampus.test` |
| Buyer dan Seller verified | `buyer.demo@apps.ipb.ac.id`, `seller.demo@apps.ipb.ac.id`, `seller2.demo@apps.ipb.ac.id` |
| Belum verified, menunggu review, ditolak, dan disuspend | `unverified.demo@apps.ipb.ac.id`, `pending.demo@apps.ipb.ac.id`, `rejected.demo@apps.ipb.ac.id`, `suspended.demo@apps.ipb.ac.id` |

Kata sandi akun sintetis untuk demo lokal adalah `GigKampusDemo2026!`.

## Verifikasi pada database uji

`migrate:fresh` menghapus semua tabel di database target. Perintah berikut memakai database dan direktori storage uji yang terpisah dari demo.

```powershell
$dbSettings = (docker compose config --format json | Out-String | ConvertFrom-Json).services.postgres.environment
@'
SELECT 'CREATE DATABASE gigkampus_phase4b_test'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'gigkampus_phase4b_test')
\gexec
'@ | docker compose exec -T postgres psql -X -v ON_ERROR_STOP=1 "--username=$($dbSettings.POSTGRES_USER)" -d postgres
if ($LASTEXITCODE -ne 0) { throw 'Gagal menyiapkan database uji.' }

$testStorage = '/tmp/gigkampus-phase4b-test'
$testEnv = @('-e', 'APP_ENV=testing', '-e', 'DB_DATABASE=gigkampus_phase4b_test', '-e', "LARAVEL_STORAGE_PATH=$testStorage")
docker compose exec -T -u www-data app mkdir -p "$testStorage/framework/views" "$testStorage/framework/sessions"
docker compose exec -T -u www-data @testEnv app php artisan migrate:fresh --seed
if ($LASTEXITCODE -ne 0) { throw 'Gagal menjalankan migrasi dan seeder uji.' }
docker compose exec -T -u www-data @testEnv app php artisan test --do-not-cache-result
```

Suite memakai PostgreSQL asli untuk memeriksa skema, constraint, immutability, dan penulisan dua koneksi. Uji OCR menjalankan Tesseract `ind+eng --psm 6` pada fixture terbaca, tidak terbaca, dan ambigu.

## Persiapan dan pemulihan demo

1. Bangun image produksi dan tunggu keenam layanan berstatus `healthy`. Jalankan seeder dan periksa skenario presentasi sesuai kebutuhan.

   ```powershell
   docker compose up -d --build --wait
   docker compose exec -T -u www-data app php artisan db:seed
   docker compose ps
   ```

2. Cek halaman utama, akses Buyer, Seller, dan Admin, serta status OCR dan notifikasi. Periksa log worker dan antrean gagal. Setelah memperbaiki penyebab kegagalan, jalankan kembali worker dan gunakan `queue:retry` dengan ID job yang sesuai bila diperlukan.

   ```powershell
   docker compose logs --tail=100 queue-ocr queue-default reverb
   docker compose exec -T -u www-data app php artisan queue:failed
   ```

3. Ambil backup sebelum presentasi saat demo sedang tidak digunakan. Skrip menghentikan sementara layanan penulis yang aktif agar database dan berkas konsisten, lalu menjalankannya kembali. Backup berisi dump PostgreSQL, arsip disk publik dan privat, serta checksum SHA-256 setiap bagian. Lokasi bawaan ialah `%LOCALAPPDATA%\GigKampus\backups`. Simpan ZIP dan konfigurasi `.env` asli secara terpisah di lokasi yang hanya dapat diakses tim demo. Konfigurasi diperlukan untuk memakai kembali `APP_KEY` dan kredensial lingkungan asal.

   ```powershell
   $backup = .\scripts\backup-demo.ps1
   $backup.FullName
   docker compose ps
   ```

### Uji pemulihan ke proyek Compose baru

Gunakan proyek baru agar pemulihan memiliki database dan volume sendiri. Skrip memeriksa checksum dan menolak target yang sudah berisi data. Pulihkan sebelum memulai layanan aplikasi agar migrasi startup belum membuat tabel di target. Contoh di bawah memakai port web `8102` dan PostgreSQL `5542` agar stack demo tetap dapat berjalan di `8000` dan `5433`.

```powershell
$restoreProject = 'gigkampus-restorecheck'
$restoreOverride = Join-Path $env:TEMP 'gigkampus-restorecheck.override.yaml'
@'
x-demo-url: &demo-url
  APP_URL: http://localhost:8102
services:
  app:
    environment: *demo-url
  queue-ocr:
    environment: *demo-url
  queue-default:
    environment: *demo-url
  reverb:
    environment: *demo-url
  web:
    ports: !override
      - '127.0.0.1:8102:80'
  postgres:
    ports: !override
      - '127.0.0.1:5542:5432'
'@ | Set-Content -LiteralPath $restoreOverride -Encoding Ascii

# Isi path ini dari hasil backup yang hendak dipulihkan.
$archive = $backup.FullName
.\scripts\restore-demo.ps1 -Archive $archive -ProjectName $restoreProject -ComposeFile @('compose.yaml', $restoreOverride)
docker compose -f compose.yaml -f $restoreOverride -p $restoreProject up -d --wait
docker compose -f compose.yaml -f $restoreOverride -p $restoreProject ps
```

Buka [stack hasil pemulihan](http://localhost:8102) dengan profil browser terpisah. Login sebagai pengguna demo, buka contoh Gig berportofolio, lalu gunakan akun yang berwenang untuk membuka lampiran privat pada pesanan atau kasus. Jadikan stack yang sudah diperiksa sebagai cadangan saat presentasi. Jika pemulihan gagal, periksa pesan perintah dan gunakan nama proyek target baru untuk percobaan berikutnya. Hentikan stack pemulihan dengan `docker compose -f compose.yaml -f $restoreOverride -p $restoreProject down` untuk menyimpan volumenya.

### Uji penerimaan Fase 4B

Buat stack QA untuk pengujian tulis. Contoh ini memakai pola override di atas dengan port `8101` dan `5544`. Nama proyek yang berbeda memberi QA database dan volume berkas sendiri.

```powershell
$qaProject = 'gigkampus-phase4b-qa'
$qaOverride = Join-Path $env:TEMP 'gigkampus-phase4b-qa.override.yaml'
(Get-Content -LiteralPath $restoreOverride -Raw).Replace('8102', '8101').Replace('5542', '5544') |
    Set-Content -LiteralPath $qaOverride -Encoding Ascii
docker compose -f compose.yaml -f $qaOverride -p $qaProject up -d --wait
docker compose -f compose.yaml -f $qaOverride -p $qaProject exec -T -u www-data app php artisan db:seed
```

- Di [stack QA](http://localhost:8101), buka antrean laporan Admin untuk melihat sengketa aktif, laporan chat pra-pesan yang belum diambil, dan kasus yang sudah diputus. Di kasus sengketa aktif, buka chat serta lampiran simulasi. Masuk sebagai Seller untuk membuka klarifikasi terlambat dari inbox. Kasus putusan verifikasi pembayaran memuat jawaban historis yang masuk setelah tenggat.
- Pada pesanan QA yang menunggu validasi atau sudah berstatus hasil dikirim, ajukan komplain dengan bukti privat. Periksa status sengketa dan pembekuan aksi transaksi. Sebagai Admin, ambil kasus, buka bukti dan chat, lalu minta klarifikasi. Sebagai penerima, buka klarifikasi dari inbox dan kirim bukti susulan. Untuk mengesahkan pembayaran, catat hasil pemeriksaan bukti atau klarifikasi Seller. Konfirmasikan kesiapan kedua pihak jika pekerjaan dilanjutkan. Periksa hasil putusan dan notifikasi kedua pihak.
- Coba laporan ulasan atau chat pesanan final. Buka kasus ulasan dari panel Moderasi, ambil kasus, lalu pilih sembunyikan ulasan pada putusan. Periksa bahwa tahap pesanan final tetap sama dan ulasan hilang dari profil publik. Panel moderasi juga menyediakan fixture Gig dan akun suspended serta permintaan tersembunyi.
- Jalankan Playwright pada lebar desktop `1440` px dan perangkat seluler `390 × 844` px dengan sesi login terpisah. Catat respons HTTP 500, kesalahan konsol, dan kelebihan lebar halaman pada setiap langkah. Untuk pengukuran, jalankan lima sesi bersamaan ke halaman utama dan ukur sampai peristiwa `load`. Unggah `resources/demo-fixtures/ktm-clear.png` pada akun QA yang belum terverifikasi dan ukur dari pengiriman hingga hasil OCR tampil.
