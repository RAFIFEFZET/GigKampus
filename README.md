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
@'
SELECT 'CREATE DATABASE gigkampus_phase2_test'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'gigkampus_phase2_test')
\gexec
'@ | docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1'
$testStorage = '/tmp/gigkampus-phase2-test'
docker compose exec -T -u www-data app mkdir -p "$testStorage/framework/views" "$testStorage/framework/sessions"
docker compose exec -T -u www-data -e APP_ENV=testing -e DB_DATABASE=gigkampus_phase2_test -e "LARAVEL_STORAGE_PATH=$testStorage" app php artisan migrate:fresh --seed
docker compose exec -T -u www-data -e APP_ENV=testing -e DB_DATABASE=gigkampus_phase2_test -e "LARAVEL_STORAGE_PATH=$testStorage" app php artisan test --do-not-cache-result
```

Suite memakai PostgreSQL asli untuk memeriksa skema, constraint, immutability, dan penulisan dua koneksi. Uji OCR menjalankan Tesseract `ind+eng --psm 6` pada fixture terbaca, tidak terbaca, dan ambigu.
