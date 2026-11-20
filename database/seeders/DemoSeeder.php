<?php

namespace Database\Seeders;

use App\Enums\ActorType;
use App\Enums\CancellationStatus;
use App\Enums\ChatMessageType;
use App\Enums\ChatRoomStatus;
use App\Enums\GigStatus;
use App\Enums\KtmVerificationStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProofStatus;
use App\Enums\PaymentStatus;
use App\Enums\PriceOfferStatus;
use App\Enums\RefundStatus;
use App\Enums\ReportCategory;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\RevisionStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\TransactionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DemoSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'admin@gigkampus.test';

    public const PASSWORD = 'GigKampusDemo2026!';

    private const SEED_COMPLETED = 'demo.seeded';

    private CarbonImmutable $clock;

    private int $admin;

    /** @var array<string, int> */
    private array $students = [];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Demo data can only be seeded in local and testing environments.');
        }

        $this->clock = CarbonImmutable::now('UTC')->startOfSecond();

        DB::transaction(function (): void {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('gigkampus.demo.seed'))");

            $admin = DB::table('admins')->where('email', self::ADMIN_EMAIL)->value('id');
            if ($admin !== null && DB::table('audit_logs')->where([
                'actor_type' => ActorType::System,
                'action' => self::SEED_COMPLETED,
                'entity_type' => 'admins',
                'entity_id' => $admin,
            ])->exists()) {
                $this->installFiles();
                $this->seedActiveDisputeChat();

                return;
            }

            $this->seedAccounts();
            [$posterGig, $revisionGig, $pendingOffer, $customOffer] = $this->seedMarketplace();
            $this->seedOrders($posterGig, $revisionGig, $customOffer);
            $this->seedPreOrderChats($posterGig, $pendingOffer);
            $this->installFiles();
            $this->seedActiveDisputeChat();
            $this->audit(self::SEED_COMPLETED, 'admins', $this->admin, 0, ['status' => 'ready']);
        });
    }

    private function seedAccounts(): void
    {
        $banks = [];
        foreach (['BCA', 'BNI', 'BRI', 'Mandiri', 'BSI', 'CIMB Niaga', 'Bank Jago'] as $name) {
            $banks[$name] = $this->reference('banks', $name);
        }

        $this->admin = $this->insert('admins', [
            'name' => 'Admin Demo',
            'email' => self::ADMIN_EMAIL,
            'password_hash' => Hash::driver('argon2id')->make(self::PASSWORD),
            ...$this->timestamps(-900),
        ]);

        $accounts = [
            'buyer' => ['Pembeli Demo', KtmVerificationStatus::Approved, 'DEMOIPB111'],
            'seller' => ['Desainer Demo', KtmVerificationStatus::Approved, 'DEMOIPB112'],
            'seller2' => ['Analis Demo', KtmVerificationStatus::Approved, 'DEMOIPB113'],
            'suspended' => ['Mahasiswa Demo Ditangguhkan', KtmVerificationStatus::Approved, 'DEMOIPB114'],
            'pending' => ['Mahasiswa Demo Menunggu', KtmVerificationStatus::PendingReview, 'DEMOIPB115'],
            'rejected' => ['Mahasiswa Demo Ditolak', KtmVerificationStatus::Rejected, 'DEMOIPB116'],
            'unverified' => ['Mahasiswa Demo Baru', KtmVerificationStatus::OcrFailed, null],
        ];

        $payouts = [
            'seller' => [
                'bank_id' => $banks['BCA'],
                'bank_account_number' => '0000000000',
                'bank_account_holder_name' => 'Penerima Desain Demo',
                'qris_image_path' => $this->diskPath('qris-demo.png'),
                'qris_holder_name' => 'Penerima Desain Demo',
                'bio' => 'Membuat poster kegiatan dan materi visual untuk komunitas kampus.',
            ],
            'seller2' => [
                'bank_id' => $banks['BNI'],
                'bank_account_number' => '0000000001',
                'bank_account_holder_name' => 'Penerima Analisis Demo',
                'bio' => 'Merapikan, menganalisis, dan memvisualisasikan data survei kegiatan.',
            ],
            'suspended' => [
                'bank_id' => $banks['BRI'],
                'bank_account_number' => '0000000002',
                'bank_account_holder_name' => 'Penerima Dokumentasi Demo',
            ],
        ];

        foreach ($accounts as $key => [$name, $status, $candidate]) {
            $approved = $status === KtmVerificationStatus::Approved;
            $reviewed = $approved || $status === KtmVerificationStatus::Rejected;
            $suspended = $key === 'suspended';
            $reason = $status === KtmVerificationStatus::Rejected ? 'Nama pada kartu uji tidak cocok dengan data pengajuan.' : null;

            $user = $this->students[$key] = $this->insert('users', [
                'name' => $name,
                'email' => $key.'.demo@apps.ipb.ac.id',
                'password_hash' => Hash::driver('argon2id')->make(self::PASSWORD),
                'phone_number' => '+620000000000',
                'nim' => $approved ? $candidate : null,
                'is_verified' => $approved,
                'is_suspended' => $suspended,
                'created_at' => $this->at(-800),
                'updated_at' => $this->at($suspended ? -20 : ($approved ? -760 : -800)),
                ...($payouts[$key] ?? []),
            ]);
            $this->audit('account.registered', 'users', $user, -800, ['is_verified' => false], user: $user);

            $ktm = $this->insert('ktm_verification_requests', [
                'user_id' => $user,
                'name_snapshot' => $name,
                ...$this->attachment($key === 'unverified' ? 'ktm-unreadable.png' : "ktm-{$key}.png"),
                'ocr_nim_candidate' => $candidate,
                'status' => $status,
                'ocr_error' => $status === KtmVerificationStatus::OcrFailed ? 'NIM belum terbaca. Unggah foto kartu uji yang lebih jelas.' : null,
                'reviewed_by_admin_id' => $reviewed ? $this->admin : null,
                'review_reason' => $reason,
                'submitted_at' => $this->at(-770),
                'ocr_finished_at' => $this->at(-770),
                'reviewed_at' => $reviewed ? $this->at(-760) : null,
            ]);
            $this->audit('ktm.submitted', 'ktm_verification_requests', $ktm, -770, ['status' => KtmVerificationStatus::Processing], user: $user);
            $ocrStatus = $candidate === null ? KtmVerificationStatus::OcrFailed : KtmVerificationStatus::PendingReview;
            $this->audit('ktm.'.$ocrStatus->value, 'ktm_verification_requests', $ktm, -770, ['status' => $ocrStatus]);
            if ($reviewed) {
                $this->audit('ktm.'.$status->value, 'ktm_verification_requests', $ktm, -760, ['status' => $status], admin: $this->admin, reason: $reason);
            }
            if ($suspended) {
                $this->audit('account.suspended', 'users', $user, -20, ['is_suspended' => true], admin: $this->admin, reason: 'Pelanggaran berulang pada skenario moderasi demo.');
            }
        }

        $this->notify('ktm.pending_review', 'KTM menunggu pemeriksaan', 'Pengajuan baru dari Mahasiswa Demo Menunggu.', -770, admin: $this->admin);
        $this->notify('ktm.ocr_failed', 'NIM belum terbaca', 'Unggah ulang dengan gambar yang menampilkan NIM secara jelas.', -770, user: $this->students['unverified']);
    }

    private function seedMarketplace(): array
    {
        $categories = [];
        foreach (['Desain & UI/UX', 'Pemrograman & Web', 'Olah Data', 'Video & Foto', 'Penulisan', 'Akademik', 'Lainnya'] as $name) {
            $categories[$name] = $this->reference('categories', $name);
        }
        $inactiveCategory = $this->reference('categories', 'Arsip Kegiatan Demo', ['is_active' => false]);
        $this->audit('category.deactivated', 'categories', $inactiveCategory, -200, ['is_active' => false], admin: $this->admin);

        $posterGig = $this->gig('seller', $categories['Desain & UI/UX'], 'Desain Poster Acara Kampus', 'Desain satu poster digital berdasarkan brief, jadwal, dan logo dari pemesan.', 150000, GigStatus::Active, 'portfolio-poster.png');
        $revisionGig = $this->gig('seller', $categories['Desain & UI/UX'], 'Desain Poster Pameran Kampus', 'Satu poster pameran mahasiswa dengan jadwal dan logo dari pemesan, termasuk dua kali revisi.', 150000, GigStatus::Active, revisions: 2);
        $this->gig('seller2', $categories['Video & Foto'], 'Dokumentasi Luring Kegiatan Kampus', 'Dokumentasi foto selama dua jam di area kampus dengan jadwal yang disepakati melalui chat.', 200000, GigStatus::Active);
        $this->gig('seller2', $categories['Olah Data'], 'Visualisasi Data Survei', 'Ringkasan data dan grafik sederhana untuk laporan kegiatan mahasiswa.', 120000, GigStatus::Inactive);
        $moderatedGig = $this->gig('seller', $categories['Desain & UI/UX'], 'Paket Ilustrasi Media Sosial Demo', 'Ilustrasi untuk publikasi kegiatan kampus dan konten media sosial komunitas.', 100000, GigStatus::Suspended);
        $this->gig('suspended', $categories['Video & Foto'], 'Foto Kegiatan Komunitas Demo', 'Sesi dokumentasi luring kegiatan komunitas dengan hasil foto pilihan dalam satu paket.', 125000, GigStatus::Active);

        $this->seedContentReport(ReportType::GigReport, 'gig_id', $moderatedGig, ReportCategory::CopyrightPrivacy, 'Ilustrasi diduga menggunakan materi kreator lain tanpa izin pada skenario demo.', 'suspend_gig', 'Pelanggaran lisensi terkonfirmasi pada materi pendukung kasus demo.');

        $this->serviceRequest($categories['Desain & UI/UX'], 'Butuh Ilustrasi Peta Kegiatan Kampus', 'Membutuhkan ilustrasi digital peta kegiatan, dengan daftar lokasi dan aset yang disediakan.', ServiceRequestStatus::Open, -76, 240);
        $open = $this->serviceRequest($categories['Pemrograman & Web'], 'Butuh Halaman Informasi Acara', 'Membutuhkan halaman informasi untuk acara komunitas yang mudah dibaca dari ponsel.', ServiceRequestStatus::Open, -96, 192);
        $closed = $this->serviceRequest($categories['Video & Foto'], 'Dokumentasi Diskusi Mahasiswa', 'Membutuhkan dokumentasi foto kegiatan diskusi kelompok di ruang komunitas kampus.', ServiceRequestStatus::Closed, -320, 192, -270);
        $ordered = $this->serviceRequest($categories['Olah Data'], 'Olah Data Survei Perpustakaan', 'Rapikan data survei fiktif, lalu susun ringkasan jawaban dan satu grafik frekuensi kunjungan.', ServiceRequestStatus::Ordered, -190, -96, -150);
        $this->serviceRequest($categories['Penulisan'], 'Penyuntingan Buletin Komunitas', 'Memerlukan penyuntingan ejaan pada buletin berisi kabar kegiatan komunitas mahasiswa.', ServiceRequestStatus::Open, -320, -24);
        $hidden = $this->serviceRequest($categories['Akademik'], 'Permintaan Akademik yang Dimoderasi', 'Permintaan contoh untuk memperlihatkan penindakan atas konten akademik yang melanggar aturan.', ServiceRequestStatus::Closed, -185, 72, -95, true);
        $this->seedContentReport(ReportType::RequestReport, 'service_request_id', $hidden, ReportCategory::AcademicIntegrity, 'Permintaan memuat pelanggaran integritas akademik pada skenario demo.', 'hide_service_request', 'Konten permintaan telah ditinjau dan disembunyikan.', reporter: $this->students['seller2']);

        $pendingOffer = $this->offer($open, 'seller2', 175000, PriceOfferStatus::Pending, -52);
        $this->offer($closed, 'seller2', 150000, PriceOfferStatus::Withdrawn, -309, -301);
        $this->offer($closed, 'seller', 150000, PriceOfferStatus::Rejected, -310, -270, reason: 'Pemilik menutup permintaan.');
        $customOffer = $this->offer($ordered, 'seller2', 180000, PriceOfferStatus::Accepted, -168, -150, revisions: 0);
        $this->offer($ordered, 'seller', 190000, PriceOfferStatus::Rejected, -170, -150, reason: 'Pemilik menerima penawaran lain.');

        return [$posterGig, $revisionGig, $pendingOffer, $customOffer];
    }

    private function gig(string $seller, int $category, string $title, string $description, int $price, GigStatus $status, ?string $portfolio = null, int $revisions = 1): int
    {
        $updated = match ($status) {
            GigStatus::Active => -500,
            GigStatus::Inactive => -190,
            GigStatus::Suspended => -95,
        };
        $id = $this->insert('gigs', [
            'seller_id' => $this->students[$seller],
            'category_id' => $category,
            'title' => $title,
            'description' => $description,
            'base_price' => $price,
            'delivery_days' => 3,
            'max_revisions' => $revisions,
            'portfolio_path' => $portfolio === null ? null : $this->diskPath($portfolio),
            'status' => $status,
            ...$this->timestamps(-500, $updated),
        ]);
        $this->audit('gig.published', 'gigs', $id, -500, ['status' => GigStatus::Active], user: $this->students[$seller]);
        if ($status === GigStatus::Inactive) {
            $this->audit('gig.inactivated', 'gigs', $id, $updated, ['status' => $status], user: $this->students[$seller]);
        }

        return $id;
    }

    private function serviceRequest(int $category, string $title, string $description, ServiceRequestStatus $status, int $created, int $deadline, ?int $updated = null, bool $hidden = false): int
    {
        $buyer = $this->students['buyer'];
        $id = $this->insert('service_requests', [
            'buyer_id' => $buyer,
            'category_id' => $category,
            'title' => $title,
            'description' => $description,
            'budget_min' => 100000,
            'budget_max' => 250000,
            'deadline_date' => $this->clock->addHours($deadline)->setTimezone('Asia/Jakarta')->toDateString(),
            'status' => $status,
            'is_hidden' => $hidden,
            ...$this->timestamps($created, $updated),
        ]);
        $this->audit('request.published', 'service_requests', $id, $created, ['status' => ServiceRequestStatus::Open], user: $buyer);
        if ($status === ServiceRequestStatus::Closed && ! $hidden) {
            $this->audit('request.closed', 'service_requests', $id, $updated, ['status' => $status], user: $buyer);
        }

        return $id;
    }

    private function offer(int $request, string $seller, int $price, PriceOfferStatus $status, int $created, ?int $updated = null, int $revisions = 1, ?string $reason = null): int
    {
        $user = $this->students[$seller];
        $reason ??= $status === PriceOfferStatus::Withdrawn ? 'Penyedia menarik penawarannya.' : null;
        $id = $this->insert('price_offers', [
            'service_request_id' => $request,
            'seller_id' => $user,
            'offered_price' => $price,
            'max_revisions' => $revisions,
            'proposal_note' => 'Saya akan mengirim hasil dalam satu berkas sesuai lingkup dan tenggat permintaan.',
            'status' => $status,
            'status_reason' => $reason,
            'offered_at' => $this->at($created),
            'updated_at' => $this->at($updated ?? $created),
        ]);
        $this->audit('offer.submitted', 'price_offers', $id, $created, ['status' => PriceOfferStatus::Pending], user: $user);
        if ($status !== PriceOfferStatus::Pending) {
            $this->audit('offer.'.$status->value, 'price_offers', $id, $updated, ['status' => $status], user: $status === PriceOfferStatus::Withdrawn ? $user : $this->students['buyer'], reason: $reason);
        }

        return $id;
    }

    private function seedOrders(int $gig, int $revisionGig, int $customOffer): void
    {
        $this->directOrder(1, $gig, OrderStatus::AwaitingPayment, 1, -12, -12, method: PaymentMethod::Qris);

        [$order, $payment] = $this->directOrder(2, $gig, OrderStatus::AwaitingValidation, 2, -18, -17);
        $this->proof($order, $payment, 1, 'proof-bank.png', -17);
        $this->notify('payment.proof_submitted', 'Bukti pembayaran baru', 'Periksa bukti transfer demo untuk pesanan poster.', -17, user: $this->students['seller'], order: $order);

        [$order, $payment] = $this->directOrder(3, $gig, OrderStatus::AwaitingPayment, 3, -55, -53);
        $this->proof($order, $payment, 1, 'proof-wrong-amount.png', -54, -53, 'Nominal pada bukti lebih kecil dari total pesanan.');
        $this->rejectPayment($payment, -53);
        $this->notify('payment.rejected', 'Bukti pembayaran ditolak', 'Periksa nominal transfer dan unggah bukti pengganti.', -53, user: $this->students['buyer'], order: $order, read: -52);

        [$order, $payment] = $this->directOrder(4, $gig, OrderStatus::InProgress, 5, -52, -12);
        $this->proof($order, $payment, 1, 'proof-wrong-amount.png', -51, -50, 'Nominal pada bukti lebih kecil dari total pesanan.');
        $this->rejectPayment($payment, -50);
        $this->pay($order, $payment, -49, -48, attempt: 2);
        $this->cancel($order, CancellationStatus::Pending, -12);

        [$order, $payment] = $this->directOrder(5, $gig, OrderStatus::Delivered, 4, -46, -26);
        $this->pay($order, $payment, -45, -44);
        $this->cancel($order, CancellationStatus::Rejected, -40, -38, 'Pekerjaan poster sudah siap dikirim sesuai brief.');
        $this->delivery($order, 'delivery-poster.png', -26);

        [$order, $payment] = $this->directOrder(6, $gig, OrderStatus::Revision, 5, -43, -21);
        $this->pay($order, $payment, -42, -41);
        $this->delivery($order, 'delivery-poster.png', -31);
        $this->revision($order, -21);

        [$order, $payment] = $this->directOrder(7, $gig, OrderStatus::Completed, 7, -120, -85, archive: -85);
        $this->pay($order, $payment, -119, -118);
        $this->delivery($order, 'delivery-poster.png', -99);
        $revision = $this->revision($order, -97, -88);
        $this->cancel($order, CancellationStatus::Voided, -90, -85, 'Permintaan ditutup karena pesanan telah selesai.');
        $this->delivery($order, 'delivery-poster-revisi.png', -88, $revision);
        $this->complete($order, -85);
        $this->review($order, -84);

        [$order, $payment] = $this->directOrder(8, $gig, OrderStatus::Cancelled, 4, -80, -63);
        $this->pay($order, $payment, -79, -78);
        $this->cancel($order, CancellationStatus::Approved, -69, -63);
        $this->refund($order, $payment, -63);

        [$order, $payment, $room] = $this->directOrder(9, $gig, OrderStatus::Cancelled, 4, -130, -96, archive: -96);
        $this->pay($order, $payment, -129, -128);
        $this->cancel($order, CancellationStatus::Approved, -120, -117);
        $this->refund($order, $payment, -117);
        $this->message($room, $this->students['seller'], 1, -116, 'Saya lampirkan bukti simulasi pengembalian penuh.', 'proof-refund.png', type: ChatMessageType::RefundProof, read: -115);
        $refundReport = $this->complaint($order, OrderStatus::Cancelled, 4, -114, 'Bukti pengembalian dikirim, tetapi dana belum terlihat saat diperiksa.', 'proof-refund.png', 'confirm_refund', 'Riwayat mutasi simulasi dari kedua pihak menunjukkan dana telah diterima.', -96);
        $clarification = $this->clarify($refundReport, 'buyer', -110, 'Mohon konfirmasi apakah dana pengembalian sudah diterima.');
        $this->reply($clarification, 'buyer', -105, 'Dana pengembalian simulasi tercatat masuk secara penuh.');
        $this->confirmRefund($order, $payment, $refundReport, -96);

        [$order, $payment] = $this->directOrder(10, $gig, OrderStatus::Disputed, 3, -90, -82);
        $this->proof($order, $payment, 1, 'proof-wrong-amount.png', -89, -88, 'Nominal pada bukti lebih kecil dari total pesanan.');
        $this->rejectPayment($payment, -88);
        $report = $this->complaint($order, OrderStatus::AwaitingPayment, 3, -82, 'Pemesan mempertanyakan penolakan bukti transfer dalam simulasi.', 'proof-wrong-amount.png');
        $this->clarify($report, 'seller', -79, 'Mohon lampirkan mutasi yang relevan untuk memeriksa nominal.');
        $clarification = $this->clarify($report, 'buyer', -12, 'Mohon jelaskan apakah bukti yang dikirim adalah percobaan pertama.');
        $this->reply($clarification, 'buyer', -11, 'Ini adalah bukti percobaan pertama. Saya akan menyiapkan dokumen pendukung.');

        [$order, $payment] = $this->directOrder(11, $gig, OrderStatus::InProgress, 4, -115, -73);
        $proof = $this->proof($order, $payment, 1, 'proof-bank.png', -114, -113, 'Referensi transfer belum ditemukan saat pemeriksaan awal.');
        $this->rejectPayment($payment, -113);
        $overrideReport = $this->complaint($order, OrderStatus::AwaitingPayment, 3, -111, 'Nominal bukti sesuai tagihan. Pemesan meminta pemeriksaan mutasi simulasi.', 'proof-bank.png', 'verify_payment_continue', 'Mutasi yang diklarifikasi cocok dengan nominal, referensi, dan penerima. Kedua pihak siap melanjutkan.', -73);
        $clarification = $this->clarify($overrideReport, 'seller', -110, 'Mohon periksa mutasi masuk dan konfirmasi kesediaan melanjutkan pesanan.');
        $this->reply($clarification, 'seller', -80, 'Referensi transfer ditemukan. Saya lampirkan mutasi simulasi.', 'proof-statement.png');
        $this->reply($clarification, 'seller', -76, 'Saya dapat melanjutkan pekerjaan sesuai lingkup dan tenggat yang disepakati.');
        $this->verifyPayment($order, $payment, $proof, -73, $overrideReport);

        [$order, $payment] = $this->directOrder(12, $gig, OrderStatus::Delivered, 4, -100, -60);
        $this->pay($order, $payment, -99, -98);
        $this->cancel($order, CancellationStatus::Withdrawn, -94, -90);
        $this->delivery($order, 'delivery-poster.png', -82);
        $this->complaint($order, OrderStatus::Delivered, 4, -79, 'Pemesan menduga berkas hasil tidak dapat dibuka pada perangkat yang digunakan.', 'delivery-poster.png', 'dismiss_restore', 'Berkas dapat dibuka dan sesuai brief. Tahap delivered dipulihkan; kuota revisi tetap tersedia.', -60, dismissed: true);

        [$order, $payment] = $this->customOrder(13, $customOffer, -150, -126);
        $this->pay($order, $payment, -148, -147, file: 'proof-custom.png');
        $this->delivery($order, 'delivery-custom.png', -138);
        $this->complete($order, -126);
        $this->review($order, -125, hidden: true);

        [$order] = $this->directOrder(14, $gig, OrderStatus::Cancelled, 2, -30, -29, archive: -29);
        $this->audit('order.cancelled', 'orders', $order, -29, ['status' => OrderStatus::Cancelled, 'stage_version' => 2], user: $this->students['buyer'], order: $order, before: ['status' => OrderStatus::AwaitingPayment, 'stage_version' => 1]);

        [$order, $payment] = $this->directOrder(15, $gig, OrderStatus::Completed, 5, -48, -20, archive: -20);
        $this->pay($order, $payment, -47, -46);
        $this->delivery($order, 'delivery-poster.png', -22);
        $this->complete($order, -20);

        [$order, $payment] = $this->directOrder(16, $revisionGig, OrderStatus::Delivered, 4, -39, -16);
        $this->pay($order, $payment, -38, -37);
        $this->delivery($order, 'delivery-poster.png', -16);
    }

    private function directOrder(int $number, int $gigId, OrderStatus $status, int $stage, int $created, int $updated, ?int $archive = null, PaymentMethod $method = PaymentMethod::BankTransfer): array
    {
        $gig = DB::table('gigs')->find($gigId);

        return $this->createOrder($number, $status, $stage, $created, $updated, $archive, $method, [
            'buyer_id' => $this->students['buyer'],
            'seller_id' => $gig->seller_id,
            'transaction_type' => TransactionType::DirectGig,
            'gig_id' => $gigId,
            'title_snapshot' => $gig->title,
            'scope_description_snapshot' => $gig->description,
            'buyer_brief' => "Siapkan poster pameran mahasiswa dengan warna biru.\nGunakan logo kegiatan dan cantumkan lokasi di aula kampus.",
            'category_name_snapshot' => DB::table('categories')->find($gig->category_id)->name,
            'total_price' => $gig->base_price,
            'max_revisions_snapshot' => $gig->max_revisions,
            'delivery_days_snapshot' => $gig->delivery_days,
        ]);
    }

    private function customOrder(int $number, int $offerId, int $created, int $completed): array
    {
        $offer = DB::table('price_offers')->find($offerId);
        $request = DB::table('service_requests')->find($offer->service_request_id);
        $deadline = CarbonImmutable::parse($request->deadline_date, 'Asia/Jakarta')->endOfDay()->utc();

        return $this->createOrder($number, OrderStatus::Completed, 5, $created, $completed, $completed, PaymentMethod::BankTransfer, [
            'buyer_id' => $request->buyer_id,
            'seller_id' => $offer->seller_id,
            'transaction_type' => TransactionType::CustomRequest,
            'price_offer_id' => $offerId,
            'title_snapshot' => $request->title,
            'scope_description_snapshot' => $request->description,
            'seller_proposal_snapshot' => $offer->proposal_note,
            'category_name_snapshot' => DB::table('categories')->find($request->category_id)->name,
            'total_price' => $offer->offered_price,
            'max_revisions_snapshot' => $offer->max_revisions,
            'delivery_deadline_at' => $deadline->format('Y-m-d H:i:s.uP'),
        ]);
    }

    private function createOrder(int $number, OrderStatus $status, int $stage, int $created, int $updated, ?int $archive, PaymentMethod $method, array $snapshot): array
    {
        $seller = DB::table('users')->find($snapshot['seller_id']);
        $order = $this->insert('orders', [
            'creation_key' => $this->key($number),
            'status' => $status,
            'stage_version' => $stage,
            'bank_name_snapshot' => DB::table('banks')->find($seller->bank_id)->name,
            'bank_account_number_snapshot' => $seller->bank_account_number,
            'bank_account_holder_name_snapshot' => $seller->bank_account_holder_name,
            'qris_image_path_snapshot' => $seller->qris_image_path,
            'qris_holder_name_snapshot' => $seller->qris_holder_name,
            'order_date' => $this->at($created),
            'updated_at' => $this->at($updated),
            ...$snapshot,
        ]);
        $payment = $this->insert('payments', [
            'order_id' => $order,
            'amount' => $snapshot['total_price'],
            'payment_method' => $method,
            ...$this->timestamps($created),
        ]);
        $room = $this->room(
            $snapshot['buyer_id'],
            $snapshot['seller_id'],
            $created,
            gig: $snapshot['gig_id'] ?? null,
            offer: $snapshot['price_offer_id'] ?? null,
            order: $order,
            archive: $archive,
        );
        $this->audit('order.created', 'orders', $order, $created, ['status' => OrderStatus::AwaitingPayment, 'stage_version' => 1], user: $snapshot['buyer_id'], order: $order);

        return [$order, $payment, $room];
    }

    private function proof(int $order, int $payment, int $attempt, string $file, int $submitted, ?int $reviewed = null, ?string $reason = null): int
    {
        $orderRow = DB::table('orders')->find($order);
        $status = $reviewed === null ? PaymentProofStatus::Pending : ($reason === null ? PaymentProofStatus::Verified : PaymentProofStatus::Rejected);
        $id = $this->insert('payment_proofs', [
            'payment_id' => $payment,
            'attempt_no' => $attempt,
            ...$this->attachment($file),
            'seller_review_status' => $status,
            'reviewed_by_seller_id' => $reviewed === null ? null : $orderRow->seller_id,
            'rejection_reason' => $reason,
            'submitted_at' => $this->at($submitted),
            'reviewed_at' => $reviewed === null ? null : $this->at($reviewed),
        ]);
        $this->audit('payment.proof_submitted', 'payment_proofs', $id, $submitted, ['seller_review_status' => PaymentProofStatus::Pending, 'attempt_no' => $attempt], user: $orderRow->buyer_id, order: $order);
        if ($reviewed !== null) {
            $this->audit('payment.proof_'.$status->value, 'payment_proofs', $id, $reviewed, ['seller_review_status' => $status], user: $orderRow->seller_id, order: $order, reason: $reason);
        }
        $this->update('payments', $payment, ['status' => PaymentStatus::Pending, 'updated_at' => $this->at($submitted)]);

        return $id;
    }

    private function rejectPayment(int $payment, int $rejected): void
    {
        $this->update('payments', $payment, ['status' => PaymentStatus::Rejected, 'updated_at' => $this->at($rejected)]);
    }

    private function pay(int $order, int $payment, int $submitted, int $verified, int $attempt = 1, string $file = 'proof-bank.png'): void
    {
        $proof = $this->proof($order, $payment, $attempt, $file, $submitted, $verified);
        $this->verifyPayment($order, $payment, $proof, $verified);
    }

    private function verifyPayment(int $order, int $payment, int $proof, int $verified, ?int $report = null): void
    {
        $orderRow = DB::table('orders')->find($order);
        $bySeller = $report === null ? $orderRow->seller_id : null;
        $byAdmin = $report === null ? null : $this->admin;
        $this->update('payments', $payment, [
            'status' => PaymentStatus::Verified,
            'verified_proof_id' => $proof,
            'verified_by_user_id' => $bySeller,
            'verified_by_admin_id' => $byAdmin,
            'verified_at' => $this->at($verified),
            'updated_at' => $this->at($verified),
        ]);
        if ($orderRow->transaction_type === TransactionType::DirectGig->value) {
            $this->update('orders', $order, [
                'delivery_deadline_at' => $this->at($verified + 24 * $orderRow->delivery_days_snapshot),
            ]);
        }
        $this->audit(
            $report === null ? 'payment.verified' : 'payment.admin_verified',
            'payments',
            $payment,
            $verified,
            ['status' => PaymentStatus::Verified, 'verified_proof_id' => $proof],
            user: $bySeller,
            admin: $byAdmin,
            order: $order,
            report: $report,
            before: ['status' => $report === null ? PaymentStatus::Pending : PaymentStatus::Rejected],
            reason: $report === null ? null : 'Referensi dan nominal pembayaran terkonfirmasi melalui mutasi simulasi.',
        );
    }

    private function cancel(int $order, CancellationStatus $status, int $requested, ?int $closed = null, ?string $reason = null): void
    {
        $buyer = $this->students['buyer'];
        $seller = $this->students['seller'];
        $requestReason = 'Jadwal kegiatan berubah dan perlu dibicarakan kembali dengan penyedia.';
        $this->update('orders', $order, [
            'cancellation_status' => $status,
            'cancellation_requested_by_id' => $buyer,
            'cancellation_reason' => $requestReason,
            'cancellation_requested_at' => $this->at($requested),
            'cancellation_version' => 1,
            'cancellation_response_reason' => $reason,
            'cancellation_closed_at' => $closed === null ? null : $this->at($closed),
        ]);
        $this->audit('cancellation.requested', 'orders', $order, $requested, ['cancellation_status' => CancellationStatus::Pending, 'cancellation_version' => 1], user: $buyer, order: $order, reason: $requestReason);
        if ($closed !== null) {
            $state = ['cancellation_status' => $status];
            if ($status === CancellationStatus::Approved) {
                $state['status'] = OrderStatus::Cancelled;
                $state['stage_version'] = 4;
            }
            $actor = match ($status) {
                CancellationStatus::Voided => null,
                CancellationStatus::Withdrawn => $buyer,
                default => $seller,
            };
            $this->audit('cancellation.'.$status->value, 'orders', $order, $closed, $state, user: $actor, order: $order, before: ['cancellation_status' => CancellationStatus::Pending], reason: $reason);
        }
    }

    private function refund(int $order, int $payment, int $requested): void
    {
        $this->update('payments', $payment, [
            'refund_status' => RefundStatus::Pending,
            'refund_requested_at' => $this->at($requested),
            'updated_at' => $this->at($requested),
        ]);
        $this->audit('refund.requested', 'payments', $payment, $requested, ['refund_status' => RefundStatus::Pending], order: $order);
    }

    private function confirmRefund(int $order, int $payment, int $report, int $confirmed): void
    {
        $this->update('payments', $payment, [
            'refund_status' => RefundStatus::Confirmed,
            'refund_confirmed_by_admin_id' => $this->admin,
            'refund_confirmed_at' => $this->at($confirmed),
            'updated_at' => $this->at($confirmed),
        ]);
        $this->audit('refund.admin_confirmed', 'payments', $payment, $confirmed, ['refund_status' => RefundStatus::Confirmed], admin: $this->admin, order: $order, report: $report, before: ['refund_status' => RefundStatus::Pending], reason: 'Mutasi simulasi menunjukkan dana pengembalian diterima Pemesan.');
    }

    private function revision(int $order, int $requested, ?int $completed = null): int
    {
        $id = $this->insert('revisions', [
            'order_id' => $order,
            'revision_no' => 1,
            'revision_note' => 'Mohon ubah jam acara sesuai jadwal pameran yang telah diperbarui.',
            'status' => $completed === null ? RevisionStatus::Requested : RevisionStatus::Completed,
            'requested_at' => $this->at($requested),
            'completed_at' => $completed === null ? null : $this->at($completed),
        ]);
        $this->audit('revision.requested', 'revisions', $id, $requested, ['status' => RevisionStatus::Requested, 'revision_no' => 1], user: $this->students['buyer'], order: $order);

        return $id;
    }

    private function delivery(int $order, string $file, int $submitted, ?int $revision = null): void
    {
        $id = $this->insert('order_deliveries', [
            'order_id' => $order,
            'revision_id' => $revision,
            ...$this->attachment($file),
            'delivery_note' => $revision === null ? 'Hasil pertama sesuai lingkup pesanan saya lampirkan.' : 'Jam acara telah saya perbarui sesuai catatan revisi.',
            'submitted_at' => $this->at($submitted),
        ]);
        $seller = DB::table('orders')->find($order)->seller_id;
        $this->audit('delivery.submitted', 'order_deliveries', $id, $submitted, ['revision_id' => $revision], user: $seller, order: $order);
        if ($revision !== null) {
            $this->audit('revision.completed', 'revisions', $revision, $submitted, ['status' => RevisionStatus::Completed], user: $seller, order: $order);
        }
    }

    private function complete(int $order, int $completed): void
    {
        $stage = DB::table('orders')->where('id', $order)->value('stage_version');
        $this->audit('order.completed', 'orders', $order, $completed, ['status' => OrderStatus::Completed, 'stage_version' => $stage], user: $this->students['buyer'], order: $order);
    }

    private function review(int $order, int $reviewed, bool $hidden = false): void
    {
        $buyer = $this->students['buyer'];
        $seller = DB::table('orders')->find($order)->seller_id;
        $reason = 'Ulasan menyertakan informasi kontak, yang disamarkan pada skenario demo.';
        $review = $this->insert('reviews', [
            'order_id' => $order,
            'reviewer_id' => $buyer,
            'seller_id' => $seller,
            'rating' => $hidden ? 4 : 5,
            'comment' => $hidden ? 'Ringkasan jelas. Kontak simulasi saya: 0000 (DEMO).' : 'Poster rapi dan perubahan jadwal diterapkan sesuai catatan.',
            'is_hidden' => $hidden,
            'hidden_by_admin_id' => $hidden ? $this->admin : null,
            'hide_reason' => $hidden ? $reason : null,
            'hidden_at' => $hidden ? $this->at(-95) : null,
            'review_date' => $this->at($reviewed),
        ]);
        $this->audit('review.created', 'reviews', $review, $reviewed, ['is_hidden' => false], user: $buyer, order: $order);
        if ($hidden) {
            $this->seedContentReport(ReportType::ReviewReport, 'review_id', $review, ReportCategory::CopyrightPrivacy, 'Ulasan contoh memuat informasi kontak yang disimulasikan.', 'hide_review', $reason, reporter: $seller);
        }
    }

    private function seedPreOrderChats(int $gig, int $offer): void
    {
        $buyer = $this->students['buyer'];
        $seller = $this->students['seller'];
        $seller2 = $this->students['seller2'];
        $gigRoom = $this->room($buyer, $seller, -50, gig: $gig);
        $this->message($gigRoom, $buyer, 1, -50, 'Apakah poster juga bisa dibuat untuk format layar presentasi?', read: -49);
        $this->message($gigRoom, $seller, 2, -48, 'Bisa. Kirim ukuran target yang diinginkan di brief pesanan.');
        $this->message($gigRoom, $buyer, 3, -18, 'Ada pesan lain sebagai contoh untuk laporan chat pada demo.');

        $offerRoom = $this->room($buyer, $seller2, -51, offer: $offer);
        $this->message($offerRoom, $buyer, 1, -51, 'Apakah lingkup penawaran mencakup tampilan ponsel?');
        $this->message($offerRoom, $seller2, 2, -47, 'Ya, halaman akan disesuaikan untuk layar ponsel.', read: -46);

        $order = DB::table('orders')->where('creation_key', $this->key(7))->first();
        $completedRoom = DB::table('chat_rooms')->where('order_id', $order->id)->value('id');
        $this->message($completedRoom, $buyer, 1, -119, 'Logo dan jadwal sudah saya cantumkan di brief pesanan.', read: -118);
        $this->message($completedRoom, $seller, 2, -88, 'Jam acara pada poster sudah saya perbarui sesuai jadwal.', read: -87);

        $this->report(ReportType::ChatReport, 'chat_room_id', $gigRoom, $seller, ReportCategory::MisleadingSpam, 'Pesan berulang di ruang pra-pesan ini dilaporkan untuk uji antrean moderasi.', -17);
    }

    private function seedActiveDisputeChat(): void
    {
        $dispute = DB::table('orders')
            ->join('chat_rooms', 'chat_rooms.order_id', '=', 'orders.id')
            ->join('reports', 'reports.order_id', '=', 'orders.id')
            ->where('orders.creation_key', $this->key(10))
            ->where('orders.status', OrderStatus::Disputed)
            ->where('chat_rooms.status', ChatRoomStatus::Active)
            ->where('reports.status', ReportStatus::Pending)
            ->where('reports.order_status_before_dispute', OrderStatus::AwaitingPayment)
            ->where('reports.order_stage_version', 3)
            ->first(['orders.seller_id', 'chat_rooms.id as room_id', 'reports.created_at']);

        if ($dispute === null || DB::table('chat_messages')->where('chat_room_id', $dispute->room_id)->exists()) {
            return;
        }

        $this->insert('chat_messages', [
            'chat_room_id' => $dispute->room_id,
            'sender_id' => $dispute->seller_id,
            'client_message_key' => $this->key(1),
            'message_type' => ChatMessageType::Regular,
            'message_body' => 'Saya lampirkan mutasi simulasi untuk membantu pemeriksaan Admin.',
            ...$this->attachment('proof-statement.png'),
            'sent_at' => CarbonImmutable::parse($dispute->created_at)->addHour()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    private function room(int $buyer, int $seller, int $created, ?int $gig = null, ?int $offer = null, ?int $order = null, ?int $archive = null): int
    {
        return $this->insert('chat_rooms', [
            'buyer_id' => $buyer,
            'seller_id' => $seller,
            'gig_id' => $gig,
            'price_offer_id' => $offer,
            'order_id' => $order,
            'status' => $archive === null ? ChatRoomStatus::Active : ChatRoomStatus::Archived,
            'created_at' => $this->at($created),
            'archived_at' => $archive === null ? null : $this->at($archive),
        ]);
    }

    private function message(int $room, int $sender, int $number, int $sent, string $body, ?string $file = null, ChatMessageType $type = ChatMessageType::Regular, ?int $read = null): void
    {
        $id = $this->insert('chat_messages', [
            'chat_room_id' => $room,
            'sender_id' => $sender,
            'client_message_key' => $this->key($number),
            'message_type' => $type,
            'message_body' => $body,
            ...($file === null ? [] : $this->attachment($file)),
            'sent_at' => $this->at($sent),
            'read_at' => $read === null ? null : $this->at($read),
        ]);
        if ($type === ChatMessageType::RefundProof) {
            $order = DB::table('chat_rooms')->where('id', $room)->value('order_id');
            $this->audit('refund.proof_sent', 'chat_messages', $id, $sent, ['message_type' => $type], user: $sender, order: $order);
        }
    }

    private function seedContentReport(ReportType $type, string $target, int $id, ReportCategory $category, string $description, string $decision, string $note, ?int $reporter = null): void
    {
        $report = $this->report($type, $target, $id, $reporter ?? $this->students['buyer'], $category, $description, -100, $decision, $note, -95);
        [$table, $state] = match ($type) {
            ReportType::GigReport => ['gigs', ['status' => GigStatus::Suspended]],
            ReportType::RequestReport => ['service_requests', ['status' => ServiceRequestStatus::Closed, 'is_hidden' => true]],
            ReportType::ReviewReport => ['reviews', ['is_hidden' => true]],
        };
        $this->audit('moderation.'.$decision, $table, $id, -95, $state, admin: $this->admin, report: $report, reason: $note);
    }

    private function complaint(int $order, OrderStatus $previous, int $stage, int $created, string $description, string $file, ?string $decision = null, ?string $note = null, ?int $decided = null, bool $dismissed = false): int
    {
        return $this->report(
            ReportType::OrderComplaint, 'order_id', $order, $this->students['buyer'], ReportCategory::TransactionIssue, $description, $created,
            $decision, $note, $decided, $dismissed, [
                'order_status_before_dispute' => $previous,
                'order_stage_version' => $stage,
                ...$this->attachment($file),
            ],
        );
    }

    private function report(ReportType $type, string $target, int $id, int $reporter, ReportCategory $category, string $description, int $created, ?string $decision = null, ?string $note = null, ?int $decided = null, bool $dismissed = false, array $details = []): int
    {
        $order = $target === 'order_id' ? $id : null;
        $handled = $order !== null || $decided !== null;
        $status = $decided === null ? ReportStatus::Pending : ($dismissed ? ReportStatus::Dismissed : ReportStatus::Resolved);
        $report = $this->insert('reports', [
            'reporter_id' => $reporter,
            'report_type' => $type,
            $target => $id,
            'category' => $category,
            'description' => $description,
            'status' => $status,
            'handled_by_admin_id' => $handled ? $this->admin : null,
            'decision_code' => $decision,
            'decision_note' => $note,
            'created_at' => $this->at($created),
            'decided_at' => $decided === null ? null : $this->at($decided),
            ...$details,
        ]);
        $this->audit('report.opened', 'reports', $report, $created, ['status' => ReportStatus::Pending], user: $reporter, order: $order, report: $report);
        if ($handled) {
            $this->audit('report.claimed', 'reports', $report, $created + 1, ['handled_by_admin_id' => $this->admin], admin: $this->admin, order: $order, report: $report);
        }
        $parties = [$reporter];
        if ($order !== null) {
            $current = DB::table('orders')->find($order);
            $parties = [$current->buyer_id, $current->seller_id];
            $stage = $details['order_stage_version'];
            $this->audit('order.disputed', 'orders', $order, $created, ['status' => OrderStatus::Disputed, 'stage_version' => $stage], user: $reporter, order: $order, report: $report, before: ['status' => $details['order_status_before_dispute'], 'stage_version' => $stage]);
            if ($decided !== null) {
                $this->audit('order.dispute_closed', 'orders', $order, $decided, ['status' => $current->status, 'stage_version' => $current->stage_version], admin: $this->admin, order: $order, report: $report, before: ['status' => OrderStatus::Disputed], reason: $note);
            } else {
                $opponent = $current->buyer_id === $reporter ? $current->seller_id : $current->buyer_id;
                $this->notify('order.disputed', 'Pesanan dalam sengketa', 'Tindakan transaksi dijeda selama komplain diperiksa.',
                    $created, user: $opponent, order: $order, report: $report, url: route('orders.show', $order, false));
            }
        }
        if ($decided !== null) {
            $this->audit('report.'.$status->value, 'reports', $report, $decided, ['status' => $status, 'decision_code' => $decision], admin: $this->admin, order: $order, report: $report, reason: $note);
            foreach ($parties as $party) {
                $this->notify('report.decided', 'Putusan laporan tersedia', 'Admin telah menetapkan putusan akhir.',
                    $decided, user: $party, order: $order, report: $report, url: route('reports.show', $report, false));
            }
        } else {
            $this->notify('report.opened', 'Laporan demo baru', $description, $created, admin: $this->admin,
                order: $order, report: $report, url: route('admin.reports.show', $report, false));
        }

        return $report;
    }

    private function clarify(int $report, string $recipient, int $requested, string $question): int
    {
        $user = $this->students[$recipient];
        $id = $this->insert('report_clarifications', [
            'report_id' => $report,
            'requested_by_admin_id' => $this->admin,
            'recipient_user_id' => $user,
            'question' => $question,
            'requested_at' => $this->at($requested),
            'due_at' => $this->at($requested + 24),
        ]);
        $this->audit('clarification.requested', 'report_clarifications', $id, $requested, ['recipient_user_id' => $user], admin: $this->admin, report: $report);
        $this->notify('clarification.requested', 'Permintaan klarifikasi', 'Admin meminta klarifikasi dalam 24 jam.',
            $requested, user: $user, report: $report, url: route('reports.clarifications.show', $id, false));

        return $id;
    }

    private function reply(int $clarification, string $respondent, int $submitted, string $response, ?string $file = null): void
    {
        $user = $this->students[$respondent];
        $question = DB::table('report_clarifications')->find($clarification);
        $id = $this->insert('report_clarification_responses', [
            'clarification_id' => $clarification,
            'respondent_id' => $user,
            'response_text' => $response,
            ...($file === null ? [] : $this->attachment($file)),
            'submitted_at' => $this->at($submitted),
        ]);
        $this->audit('clarification.responded', 'report_clarification_responses', $id, $submitted, ['clarification_id' => $clarification], user: $user, report: $question->report_id);
        $this->notify('clarification.responded', 'Jawaban klarifikasi diterima', 'Penerima telah mengirim jawaban atau bukti susulan.',
            $submitted, admin: $question->requested_by_admin_id, report: $question->report_id, url: route('admin.reports.show', $question->report_id, false));
    }

    private function notify(string $type, string $title, string $body, int $created, ?int $user = null, ?int $admin = null, ?int $order = null, ?int $report = null, ?int $read = null, ?string $url = null): void
    {
        $this->insert('notifications', [
            'user_id' => $user,
            'admin_id' => $admin,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'order_id' => $order,
            'report_id' => $report,
            ...($url === null ? [] : ['data' => json_encode(['relative_url' => $url], JSON_THROW_ON_ERROR)]),
            'created_at' => $this->at($created),
            'read_at' => $read === null ? null : $this->at($read),
        ]);
    }

    private function audit(string $action, string $entity, int $id, int $occurred, array $after, ?int $user = null, ?int $admin = null, ?int $order = null, ?int $report = null, ?array $before = null, ?string $reason = null): void
    {
        $this->insert('audit_logs', [
            'actor_type' => $admin !== null ? ActorType::Admin : ($user !== null ? ActorType::User : ActorType::System),
            'actor_user_id' => $user,
            'actor_admin_id' => $admin,
            'action' => $action,
            'entity_type' => $entity,
            'entity_id' => $id,
            'order_id' => $order,
            'report_id' => $report,
            'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_data' => json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'occurred_at' => $this->at($occurred),
        ]);
    }

    private function installFiles(): void
    {
        foreach (glob(resource_path('demo-fixtures/*.png')) as $source) {
            $file = basename($source);
            $path = $this->diskPath($file);
            $disk = Storage::disk($file === 'portfolio-poster.png' ? 'public' : 'private');
            $contents = file_get_contents($source);
            if ($contents === false) {
                throw new RuntimeException("Demo fixture cannot be read: {$file}");
            }
            if ($disk->exists($path)) {
                if ($disk->get($path) !== $contents) {
                    throw new RuntimeException("Demo fixture path is already occupied: {$path}");
                }

                continue;
            }
            if (! $disk->put($path, $contents)) {
                throw new RuntimeException("Demo fixture cannot be installed: {$path}");
            }
        }
    }

    private function attachment(string $file): array
    {
        return [
            'file_path' => $this->diskPath($file),
            'original_filename' => $file,
            'mime_type' => 'image/png',
            'file_size' => filesize(resource_path('demo-fixtures/'.$file)),
        ];
    }

    private function diskPath(string $file): string
    {
        return 'demo/'.$file;
    }

    private function at(int $hours): string
    {
        return $this->clock->addHours($hours)->format('Y-m-d H:i:s.uP');
    }

    private function timestamps(int $created, ?int $updated = null): array
    {
        return ['created_at' => $this->at($created), 'updated_at' => $this->at($updated ?? $created)];
    }

    private function key(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }

    private function reference(string $table, string $name, array $values = []): int
    {
        $existing = DB::table($table)->whereRaw('lower(name) = lower(?)', [$name])->first();
        if ($existing === null) {
            return $this->insert($table, ['name' => $name, ...$values]);
        }
        if ($table === 'categories' && $existing->is_active !== ($values['is_active'] ?? true)) {
            throw new RuntimeException("Existing category has a different active state: {$name}");
        }

        return $existing->id;
    }

    private function insert(string $table, array $values): int
    {
        return DB::table($table)->insertGetId($values);
    }

    private function update(string $table, int $id, array $values): void
    {
        DB::table($table)->where('id', $id)->update($values);
    }
}
