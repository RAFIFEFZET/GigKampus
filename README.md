# GigKampus

Bootstrap Fase 1 menjalankan Laravel, PostgreSQL, dua worker database queue, dan Reverb di Docker Compose. Acuan implementasi ada di [PRD](PRD.md), [ERD dan arsitektur](ERD_ARCHITECTURE.md), serta [daftar versi runtime](TECH_STACK.md#14-technology-versions).

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

Saat app mulai, Laravel menjalankan migrasi operasional `migrations`, `jobs`, dan `failed_jobs`. Worker `queue-ocr` memproses `ocr` dengan maksimum dua percobaan dan timeout 45 detik. Worker `queue-default` memproses `default` untuk notifikasi dan broadcast. Queue database menjadwalkan pekerjaan setelah transaksi commit.

Disk `public` dilayani di `/storage`. Disk `private` menjadi default dan hanya dipasang pada container backend. Database serta kedua disk menggunakan volume persisten. Setelah mengubah source atau dependensi, jalankan `docker compose up -d --build --wait` untuk mengganti image. Gunakan `docker compose down` untuk menghentikan stack dengan volume tetap tersimpan.

## Verifikasi

```powershell
docker compose ps
docker compose exec -T -u www-data app php artisan test --do-not-cache-result
docker compose exec -T app php artisan migrate:status
docker compose exec -T queue-ocr tesseract --version
docker compose exec -T queue-ocr tesseract --list-langs
```
