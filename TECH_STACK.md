# GigKampus — Technology Stack

Arsitektur dan pilihan teknologi disetujui pada 18 November 2026. Bootstrap Fase 1 dibangun dan diuji pada 19 November 2026. Rilis sasaran adalah demo lokal, siap maksimal 22 November malam. Dokumen ini mendukung [PRD.md](PRD.md) dan [ERD_ARCHITECTURE.md](ERD_ARCHITECTURE.md).

## 1. Technology Overview

| Bagian | Pilihan yang disepakati |
|---|---|
| Aplikasi | Modular monolith Laravel, MVC + Service Layer |
| UI | Blade, Livewire, Alpine bawaan Livewire, komponen Blade mengikuti Figma |
| Bahasa | PHP; JavaScript seperlunya; SQL untuk skema/constraint |
| Data | PostgreSQL, Eloquent, migration Laravel |
| Autentikasi | Fortify sebagai fondasi actions, sesi cookie, guard/provider mahasiswa dan Admin terpisah, satu /login |
| Hash | Argon2id melalui Laravel Hash |
| Realtime | Laravel Reverb + Echo, private channels |
| Latar belakang | Database queue, antrean OCR dan notifikasi terpisah |
| OCR | Tesseract lokal melalui KtmOcrService dan Laravel Process |
| Berkas | Laravel Filesystem, disk publik/privat terpisah |
| Styling/build | Tailwind CSS, Vite, Composer, npm, Node LTS |
| Pengujian | PHPUnit dan Playwright |
| Runtime demo | Docker Compose lokal; Linux container, Nginx, PHP-FPM, PostgreSQL, workers, Reverb |

Tidak diperlukan backend REST terpisah, microservices, Redis, layanan OCR berbayar, cloud storage, atau payment gateway untuk cakupan ini.

## 2. Programming Languages

- **PHP 8.5.x:** model, layanan bisnis, autentikasi, policy, job, dan server-rendered UI.
- **JavaScript:** interaksi Alpine, Echo, state tampilan lokal, token idempotensi pesan.
- **SQL PostgreSQL:** relasi, CHECK, unique/partial index, trigger integritas/immutability, dan penguncian.
- **HTML/CSS:** markup Blade dan styling Tailwind.
- **Markdown/Mermaid:** ketiga dokumen spesifikasi; bukan dependency runtime produk.

PHP class mengikuti kebutuhan PBO: abstract BaseAccount turunan Authenticatable, diwarisi Admin dan User; User mengimplementasikan BuyerInterface dan SellerInterface dengan trait kemampuan. Interface/trait tidak membuat dua akun atau dua subclass mahasiswa.

## 3. Frontend Technologies

Blade menjadi template utama; Livewire menangani form, filter, pagination, dashboard, status OCR, dan aksi transaksi. Alpine dipakai untuk menu, modal, tray, dan state ringan.

Alpine menggunakan instance bawaan Livewire. Dokumentasi Livewire memperingatkan agar Alpine tidak dimuat dua kali. [Instalasi Livewire](https://livewire.laravel.com/docs/4.x/installation)

Prinsip:
- State harga, identitas, izin, kuota, dan status transaksi tidak dipercaya dari browser.
- Tombol dinonaktifkan selama submit untuk UX, tetapi integritas tetap dijaga server/database.
- Input brief/proposal dipertahankan saat stale; password/file tidak direpopulasi secara tidak aman.
- URL filter/sort dapat dibagikan; pagination dipakai untuk daftar, bukan mengambil seluruh dataset.
- HTML user selalu di-escape. Paragraf teks menggunakan pre-wrap, tidak dirender sebagai HTML mentah.
- Berkas PDF portofolio berupa unduhan; ZIP/berkas pengguna tidak dieksekusi atau diekstrak otomatis.
- Reconnect realtime memuat ulang state terbaru dari server; pesan dikenali dengan ID agar event berulang tidak menggandakan UI.

## 4. Backend Technologies

Laravel menyediakan routing, middleware, validasi, Eloquent, sesi, filesystem, queue, event, hashing, dan testing.

Pembagian tanggung jawab:
1. **Livewire/Controller:** menerima input, memanggil validasi/policy/service, menyajikan hasil.
2. **Form validation:** tipe, panjang, format, berkas, token versi.
3. **Policies/Gates:** aktor, kepemilikan, verifikasi, suspend, akses kasus/berkas/channel.
4. **Service Layer:** transaksi lintas model, lock, snapshot, idempotensi, audit, notifikasi.
5. **Model/domain methods:** state guard lokal, misalnya larangan mulai kerja sebelum verified.
6. **Eloquent/query scopes:** akses data dan filter yang dapat digunakan ulang.

Modul: Identity & KTM, Catalog, Requests & Offers, Orders & Payments, Chat, Moderation, Notifications. Repository tambahan hanya dibuat jika mengurangi duplikasi nyata; tidak menjadi lapisan wajib yang menyalin seluruh Eloquent.

## 5. Database Technologies

PostgreSQL dipakai sesuai kebutuhan SBD dan aturan relasional:
- 21 tabel bisnis; primary key BIGSERIAL, foreign key BIGINT.
- NUMERIC(12,0) untuk uang; nilai Rupiah tidak melewati float.
- TIMESTAMPTZ untuk timestamp, DATE untuk tanggal tenggat sumber custom.
- VARCHAR + CHECK untuk status tetap.
- Unique, partial unique index, CHECK lokal, foreign key komposit, dan trigger yang diperlukan.
- ON DELETE RESTRICT / ON UPDATE RESTRICT untuk seluruh relasi bisnis.
- READ COMMITTED menjadi isolation level aplikasi; transaksi dan row locks melindungi keputusan bersamaan.

CHECK tidak digunakan untuk membaca baris/tabel lain. Batas waktu berjalan diperiksa pada aksi, bukan CHECK yang menjadi salah hanya karena hari berganti. [Constraint PostgreSQL](https://www.postgresql.org/docs/current/ddl-constraints.html)

Seluruh waktu internal aplikasi dan koneksi database memakai UTC. Kalender input/tampilan dikonversi eksplisit melalui Asia/Jakarta. Validasi “hari ini” harus menggunakan tanggal WIB, bukan tanggal UTC server.

## 6. ORM and Data Access

Eloquent menjadi ORM dan migration Laravel menjadi sumber implementasi struktur.

Ketentuan:
- Relasi didefinisikan eksplisit; nama kolom/domain diselaraskan dengan dictionary.
- password_hash dipetakan sebagai atribut autentikasi, bukan diasumsikan bernama password.
- Penamaan timestamp nonstandar, seperti order_date/offered_at/sent_at, dikonfigurasi eksplisit pada model.
- Tabel immutable dan referensi tanpa timestamp tidak otomatis diberi created_at/updated_at.
- Nilai uang diperlakukan sebagai integer yang tervalidasi atau decimal string eksak, tidak float.
- Input pecahan ditolak sebelum casting/penyimpanan. NUMERIC dengan scale 0 dapat membulatkan input saat konversi; tipe tersebut bukan pengganti validasi Rupiah bulat di boundary aplikasi.
- Gunakan eager loading/pagination untuk mencegah query berulang per kartu.
- Aksi kritis membaca ulang state terkini di dalam transaksi; data Livewire lama bukan dasar keputusan.
- stage_version mengikuti matriks transisi eksplisit ERD_ARCHITECTURE §12.4; siklus bukti/revisi yang sah menaikkannya, sedangkan masuk/pemulihan disputed tidak. Counter tidak diubah oleh chat atau metadata pembatalan.
- Sumber actor/owner diambil dari auth dan relasi, bukan field ID yang bisa dipilih client.
- Kolom snapshot tidak mass-assignable dari request; sistem menyalin dari sumber yang dikunci.
- Tidak ada soft-delete/hard-delete bisnis baru yang belum disetujui.

## 7. UI Framework and Styling

Tailwind CSS 4.3.x + Vite; komponen Blade mengikuti Figma, tanpa kewajiban library komponen berbayar. [Integrasi Tailwind–Vite](https://tailwindcss.com/docs/installation/using-vite)

Token visual:
- Putih #FFFFFF; latar lembut #F5F5F7.
- Soft Sky Blue #EEF5FF.
- Primary Blue #0071E3.
- Electric Yellow #FFD60A untuk sorotan/rating/badge.

Target utama mobile 390×844 dan desktop 1440 px. UI memakai main navigation dan contextual sub-navigation yang sudah disepakati, tanpa role switcher Buyer/Seller. Profil/kategori tanpa gambar mempunyai fallback lokal.

Browser uji utama adalah Chromium/Chrome/Edge modern. Dukungan browser lama belum menjadi target demo; tidak mengklaim kelulusan aksesibilitas standar formal sebelum diuji.

## 8. Authentication and Authorization

### Login terpadu, akun terpisah

- GET/POST **/login** adalah satu pintu mahasiswa dan Admin, tanpa pilihan role.
- Provider users/model User memakai guard web; provider admins/model Admin memakai guard admin.
- Handler login menentukan sumber akun dari email yang dinormalisasi, lalu mengautentikasi melalui guard/provider yang sesuai.
- Form login menerima email valid domain mana pun karena Admin boleh memakai domain lain; pembatasan @apps.ipb.ac.id khusus registrasi mahasiswa.
- Integrasi Fortify disesuaikan agar tidak mendaftarkan handler login publik yang bertabrakan atau mengautentikasi Admin melalui provider mahasiswa. Fortify tetap menjadi fondasi actions registrasi/ubah password.
- Setelah berhasil, sesi diregenerasi dan hanya satu identitas aktif pada sesi tersebut; logout membersihkan kedua guard dan menginvalidasi sesi/token CSRF.
- Pesan kredensial gagal generik; pembatasan percobaan login diterapkan.
- Satu identitas untuk tiap profil browser; demonstrasi beberapa aktor memakai sesi/profil browser berbeda.

Laravel menyediakan guard/provider dan regenerasi sesi untuk pola ini. [Autentikasi Laravel](https://laravel.com/framework/docs/13.x/authentication)

### Email dan password

Email harus unik lintas users/admins, selain unique index masing-masing tabel. Aplikasi dan trigger database mengambil kunci transaksi untuk email yang dinormalisasi, memeriksa kedua tabel, dan menolak benturan. Pengujian dua koneksi wajib membuktikan tidak terjadi duplikasi lintas tabel. Tidak menambah tabel akun ke-22.

Hash memakai **Argon2id**, didukung Laravel; runtime harus mendukung algoritme tersebut. Password 8–128 karakter, konfirmasi pada registrasi/ubah password, password lama diperlukan ketika mengubah. Tidak ada pemangkasan/trim password otomatis. [Hashing Laravel](https://laravel.com/framework/docs/hashing)

### Otorisasi

- Pisahkan canCreateNewTransaction dari canAccessExistingOrder; suspend tidak menghapus kewajiban lama.
- Gate publikasi/offer membutuhkan verified, akun tidak disuspend, serta metode lengkap.
- Semua akses berkas privat diperiksa berdasarkan relasi dan konteks kasus, bukan kemampuan menebak path.
- Admin tidak menjadi peserta chat; ia membaca lewat kasus pending yang diklaimnya.
- Klarifikasi/bukti privat terbatas kepada penerima dan Admin penangan; verdict/reason tersedia bagi pihak terkait.
- ID channel mahasiswa/Admin dibedakan prefix dan guard, tidak hanya integer ID yang mungkin sama.
- CSRF, escaping, session HttpOnly/SameSite, validasi MIME/ukuran, dan server-owned actor wajib. Cookie Secure/HTTPS digunakan pada deployment publik; demo localhost disesuaikan dengan origin lokal.

## 9. API and Integration Technologies

Aplikasi first-party menggunakan Livewire actions dan HTTP endpoint internal seperlunya. Tidak ada API publik terpisah atau kebutuhan token mobile/Sanctum pada demo.

| Kelompok operasi | Boundary |
|---|---|
| Login/register/profile | Handler server + guard + validasi |
| Checkout/offer acceptance | Service transaksi, version token, idempotency key |
| Proof/delivery/revision/cancellation | Service state machine; row lock order/payment |
| Reports/clarification/decision | Policy penangan, transaksi, audit |
| Private downloads | Handler terotorisasi, bukan URL storage publik |
| Broadcast authentication | Guard/channel policy di server |
| OCR | Proses lokal Tesseract; argumen tervalidasi; tanpa OCR API eksternal |

Eksternal yang boleh hanya tautan hasil HTTPS milik pengguna. Server tidak otomatis mengambil isi URL, membuat preview, atau menjalankan berkas yang dirujuk. Pembayaran di luar sistem disimulasikan; tidak ada API bank/QRIS/payment gateway.

## 10. Libraries and Dependencies

| Dependency | Tujuan |
|---|---|
| laravel/framework | Fondasi aplikasi |
| livewire/livewire | UI server-driven |
| laravel/fortify | Fondasi actions autentikasi |
| laravel/reverb | Server WebSocket |
| laravel-echo, pusher-js | Client subscription realtime melalui protokol Reverb |
| Alpine bawaan Livewire | Interaksi ringan; jangan instal instance kedua |
| tailwindcss, @tailwindcss/vite | Styling |
| vite, laravel-vite-plugin | Bundling/integrasi aset |
| phpunit/phpunit | Pengujian domain/integrasi |
| @playwright/test | Pengujian browser |

Dependensi transitif mengikuti resolusi Composer/npm. Versi yang dipakai pada Fase 1 dicatat di Bagian 14. Tidak menambah library chat, OCR cloud, editor HTML, kalender, atau bank lookup eksternal tanpa kebutuhan baru.

Ekstensi PHP mengikuti persyaratan Laravel, PostgreSQL PDO, pemeriksaan file, dukungan Argon2id, dan worker Linux. Tesseract membutuhkan Leptonica serta traineddata yang sesuai. Model bahasa/parameter OCR dicatat dan diuji pada fixture; tidak menebak NIM atau menganggap confidence sebagai persetujuan mahasiswa.

## 11. Development Tools

- Composer untuk PHP, npm untuk frontend, Vite untuk development/build.
- Docker Compose untuk runtime lokal terisolasi. Node digunakan untuk build aset dan tersedia di image app.
- Migration dan seeder Laravel; data contoh fiktif dengan seed deterministik.
- Source aplikasi dan lockfile Composer/npm disimpan di repo Git.
- Log aplikasi terstruktur/rotasi lokal dan failed_jobs untuk diagnosis; tidak menambahkan SaaS monitoring.
- File .env/rahasia tidak dimasukkan ke audit atau artefak publik.
- Aset demo dibundel lokal, bukan bergantung CDN saat presentasi.

Fase 1 dibangun dengan Docker Desktop di Windows menggunakan Linux containers. Runtime aplikasi dan dependensi berasal dari image dan lockfile proyek.

## 12. Testing Tools

| Lapisan | Pengujian bermakna |
|---|---|
| PHPUnit domain | Transisi status, kuota, peran, finalitas, batas nominal, pembatalan/refund |
| PHPUnit + PostgreSQL nyata | CHECK, FK komposit, partial unique, immutable triggers, cross-email uniqueness, rollback |
| Uji dua koneksi | Dua penerimaan offer, checkout retry, revisi/kasus ganda, email lintas tabel, respons cancellation lama |
| Playwright | Dua jalur utama, login dua jenis akun, form errors, private downloads, chat/realtime, viewport |
| Fixture OCR | Foto jelas/ambigu/buram, normalisasi, queue retry/failure, approval/rejection tanpa edit NIM |
| Pemeriksaan performa | Target PRD ≤3 detik/5 sesi dan OCR ≤15 detik; rekam hasil aktual |

Jangan mengganti PostgreSQL dengan SQLite untuk pengujian constraint karena partial index, TIMESTAMPTZ, locking, dan trigger berbeda. Event/realtime diuji setelah commit dan tidak dikirim untuk rollback.

Semua Must Have harus lulus acceptance. Should Have diuji ketika benar-benar diimplementasikan; tidak dinyatakan selesai hanya karena tabelnya tersedia.

## 13. Deployment and Infrastructure

### Lingkungan utama

Docker Compose lokal, satu instance aplikasi:
- Nginx melayani aset publik di port host 8000, meneruskan request ke PHP-FPM, dan memproksi WebSocket Reverb.
- PHP-FPM menjalankan Laravel.
- PostgreSQL 18 pada port 5432 di jaringan internal dan port 5433 di loopback host.
- Worker OCR khusus queue ocr.
- Worker notifikasi/broadcast khusus queue default.
- Reverb sebagai proses realtime dari codebase aplikasi yang sama. Laravel mengirim broadcast melalui jaringan internal ke `reverb:8080`.

Ini beberapa proses runtime untuk satu aplikasi, bukan pemisahan kepemilikan data per modul. Database PostgreSQL host 16 tidak dimigrasi/dihapus untuk menyiapkan demo. Port host dipilih agar tidak berbenturan dengan Herd/database yang sudah tersedia.

### Queue dan storage

- Queue memakai database PostgreSQL yang sama; job OCR dan pengajuan dibuat konsisten. Worker hanya memproses pekerjaan yang datanya sudah committed.
- Notifikasi inbox disimpan bersama aksi yang berhasil; broadcast dijadwalkan setelah commit. Reconnect/reload mengambil state server; retry tidak boleh menggandakan data.
- Proses OCR maksimal 30 detik, maksimal dua percobaan. Timeout job dibuat lebih besar dari proses; retry_after lebih besar lagi, agar pekerjaan tidak diproses dua worker bersamaan.
- Kegagalan final mengubah pengajuan menjadi ocr_failed dan memberikan alasan aman.
- Session/cache filesystem dan storage bersama disediakan bagi proses aplikasi sesuai kebutuhan; data persisten memakai volume.
- Disk publik hanya avatar/portofolio; berkas privat tidak mempunyai symlink publik.
- Backup database **dan** storage dilakukan sebelum presentasi; cadangan diuji dapat dibaca/dipulihkan oleh pengembang.
- Tidak ada purge otomatis bukti atau data bisnis pada demo.

### Operasional framework

21 tabel pada ERD adalah tabel bisnis. Migration bookkeeping dan database queue memerlukan tabel operasional seperti migrations, jobs, dan failed_jobs. Tabel job_batches hanya digunakan jika fitur batching benar-benar dipakai; session/cache berbasis file tidak membutuhkan tabel tambahan. Pemulihan password hold tidak diaktifkan hanya karena dukungan framework tersedia.

Fase 1 membuat tiga tabel operasional `migrations`, `jobs`, dan `failed_jobs`. Compose menjalankan migrasi saat app mulai dan menunggu PHP-FPM siap sebelum memulai worker dan Reverb.

### VPS

VPS adalah opsi sesudah inti stabil; provider, biaya, kapasitas, domain, dan provisioning masih TBD. Tidak ada pembelian/deployment yang diotorisasi oleh dokumen ini. Jika dipilih kemudian, gunakan HTTPS/WSS, backup, process restart, dan konfigurasi single-instance yang diuji.

## 14. Technology Versions

Versi aktual Fase 1 diverifikasi pada 19 November 2026 dari lockfile, container, dan browser. Seluruh versi mayor baseline berhasil dibangun.

| Teknologi | Versi aktual | Acuan |
|---|---|---|
| Laravel | 13.43.0 | `composer.lock` |
| PHP | 8.5.12 | PHP-FPM dan CLI di image app |
| Livewire | 4.4.8 | `composer.lock` |
| Alpine.js | 3.17.5 | Bundel Livewire yang dimuat di browser |
| Tailwind CSS dan plugin Vite Tailwind | 4.3.5 | `package-lock.json` |
| PostgreSQL | 18.7 | Image PostgreSQL |
| Tesseract | 5.5.4 | Build dari sumber dengan Leptonica 1.84.1 dan traineddata `eng`, `ind`, `osd` |
| Node.js | 26.13.1 LTS (Lithium) | Image Node dan runtime app |
| Fortify | 1.43.0 | `composer.lock` |
| Reverb | 1.14.0 | `composer.lock` |
| Echo dan Pusher JS | 2.6.3 dan 8.6.0 | `package-lock.json` |
| Vite dan Laravel Vite plugin | 8.3.8 dan 3.2.1 | `package-lock.json` |
| Composer dan npm | 2.11.2 dan 11.20.0 | Toolchain di image app |
| PHPUnit dan Playwright | 12.5.44 dan 1.65.1 | Lockfile Composer dan npm |
| Docker Engine dan Compose | 29.9.2 dan 5.6.1 | Host Windows melalui Docker Desktop |
| Nginx dan Linux | 1.30.7 dan Debian 13 (Trixie) | Image Nginx dan app |
| Argon2id | Driver hash Laravel aktif | Hash aktual di container menghasilkan Argon2id |

Referensi utama: [Laravel support](https://laravel.com/framework/docs/13.x/releases), [PHP downloads](https://www.php.net/downloads.php), [Livewire 4](https://livewire.laravel.com/docs/4.x/installation), [Tailwind Vite](https://tailwindcss.com/docs/installation/using-vite), [PostgreSQL versioning](https://www.postgresql.org/support/versioning/), [Tesseract 5.5.4](https://github.com/tesseract-ocr/tesseract/releases/tag/5.5.4), [Node releases](https://nodejs.org/en/about/previous-releases).

Lockfile Composer/npm tersedia di repo. Digest image teruji tercantum di [Dockerfile](Dockerfile) dan [Compose](compose.yaml).

## 15. Rationale for Technology Selection

| Pilihan | Alasan / trade-off |
|---|---|
| Laravel + Livewire | Sesuai PBO/MVC dan dominasi aturan transaksi; mengurangi perpindahan PHP–frontend; beberapa interaksi bergantung perjalanan ke server |
| PostgreSQL + Eloquent | Sesuai SBD, relasi/constraint/transaction; constraint khusus tetap membutuhkan SQL eksplisit |
| Blade + Tailwind | Kendali tampilan mengikuti Figma tanpa library berbayar; komponen tetap perlu disusun dan diuji responsif |
| Guard terpisah, satu /login | UX tunggal dengan batas autentikasi dua model; memerlukan resolver yang benar dan email unik lintas tabel |
| Argon2id | Driver hashing yang telah dipilih, mendukung kebijakan password 8–128; runtime harus disiapkan dan diuji |
| Tesseract lokal | Gratis/open source, tidak mengirim KTM ke OCR pihak ketiga; kualitas foto memengaruhi hasil, CPU/worker perlu dikelola |
| Reverb + database queue | Selaras Laravel dan realtime privat; proses worker/Reverb harus hidup; queue OCR dipisah dari notifikasi |
| Local Filesystem | Cukup untuk demo satu instance; perlu backup file dan tidak cocok langsung untuk banyak instance tanpa penyesuaian |
| Docker lokal | Runtime terisolasi dan demo tidak bergantung layanan eksternal; engine/image tetap harus siap sebelum presentasi |
| PHPUnit + Playwright | Memverifikasi aturan server, database nyata, dan pengalaman lintas aktor |

Alternatif yang dibahas adalah Laravel + React/TypeScript melalui Inertia. Opsi tersebut tidak dipilih karena menambah pengelolaan frontend/state untuk cakupan demo. Google Cloud Vision juga tidak dipilih: Tesseract lokal sesuai keputusan pengguna dan menghindari dependensi OCR eksternal. Tidak ada dependency alternatif tersebut dalam runtime yang direncanakan.

Tesseract berlisensi Apache 2.0 tanpa biaya lisensi/per-pembacaan; pemakaian sumber daya komputer/server tetap mempunyai biaya operasional. [Lisensi Tesseract](https://github.com/tesseract-ocr/tesseract/blob/main/LICENSE)

