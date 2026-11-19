<?php

namespace Tests\Feature;

use App\Enums\KtmVerificationStatus;
use App\Models\Admin;
use App\Models\Category;
use App\Models\KtmVerificationRequest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresTestCase;

class KtmVerificationFlowTest extends PostgresTestCase
{
    public function test_upload_is_private_and_queued_only_after_commit(): void
    {
        config(['queue.default' => 'database']);
        $private = Storage::fake('private');
        $public = Storage::fake('public');
        $student = $this->student('unverified');
        $jobsBefore = DB::table('jobs')->where('queue', 'ocr')->count();
        $historyBefore = $student->ktmVerificationRequests()->count();

        DB::beginTransaction();
        try {
            $this->actingAs($student)->post('/verifikasi-ktm', ['photo' => $this->photo('ktm-clear.png')])
                ->assertRedirect('/verifikasi-ktm');

            self::assertSame($jobsBefore, DB::table('jobs')->where('queue', 'ocr')->count());
            $verification = $student->ktmVerificationRequests()->latest('id')->firstOrFail();
            self::assertSame(KtmVerificationStatus::Processing, $verification->status);
            self::assertSame($student->name, $verification->name_snapshot);
            $private->assertExists($verification->file_path);
            $public->assertMissing($verification->file_path);
        } finally {
            DB::commit();
        }

        self::assertSame($jobsBefore + 1, DB::table('jobs')->where('queue', 'ocr')->count());
        $this->actingAs($student)->post('/verifikasi-ktm', ['photo' => $this->photo('ktm-clear.png')])
            ->assertSessionHasErrors('photo');
        self::assertSame($historyBefore + 1, $student->ktmVerificationRequests()->count());
        self::assertCount(1, $private->allFiles());
    }

    public function test_upload_rejects_large_images_and_wrong_content_or_extension(): void
    {
        Storage::fake('private');
        $student = $this->student('unverified');
        $this->actingAs($student);

        $this->post('/verifikasi-ktm', ['photo' => UploadedFile::fake()->create('ktm.png', 5121, 'image/png')])
            ->assertSessionHasErrors('photo');
        $text = UploadedFile::fake()->createWithContent('ktm.png', 'not an image');
        $this->post('/verifikasi-ktm', ['photo' => new UploadedFile($text->getPathname(), 'ktm.png', test: true)])
            ->assertSessionHasErrors('photo');
        $this->post('/verifikasi-ktm', ['photo' => $this->photo('ktm-clear.png', 'ktm.gif')])
            ->assertSessionHasErrors('photo');

        self::assertSame([], Storage::disk('private')->allFiles());
    }

    public static function ocrImages(): iterable
    {
        yield 'one readable candidate' => ['ktm-clear.png', KtmVerificationStatus::PendingReview, 'DEMOIPB119'];
        yield 'blurred' => ['ktm-unreadable.png', KtmVerificationStatus::OcrFailed, null];
        yield 'ambiguous' => ['ktm-ambiguous.png', KtmVerificationStatus::OcrFailed, null];
    }

    #[DataProvider('ocrImages')]
    public function test_real_ocr_updates_the_student_history(string $fixture, KtmVerificationStatus $status, ?string $candidate): void
    {
        config(['queue.default' => 'database']);
        Storage::fake('private');
        $student = $this->student('unverified');
        $previous = $student->ktmVerificationRequests()->firstOrFail();
        $this->actingAs($student)->post('/verifikasi-ktm', ['photo' => $this->photo($fixture)])
            ->assertRedirect('/verifikasi-ktm');
        $this->workOcrQueue();

        $result = $student->ktmVerificationRequests()->latest('id')->firstOrFail();
        self::assertSame($status, $result->status);
        self::assertSame($candidate, $result->ocr_nim_candidate);
        self::assertNotNull($result->ocr_finished_at);
        self::assertSame(KtmVerificationStatus::OcrFailed, $previous->refresh()->status);
        self::assertFalse($student->refresh()->is_verified);

        if ($status === KtmVerificationStatus::OcrFailed) {
            self::assertStringContainsString('Unggah ulang', $result->ocr_error);
            self::assertTrue($student->notifications()->where('type', 'ktm.ocr_failed')
                ->where('data->verification_id', $result->id)->exists());
        } else {
            $admins = Admin::all();
            self::assertNotEmpty($admins);
            foreach ($admins as $admin) {
                $notification = $admin->notifications()->where('type', 'ktm.pending_review')
                    ->where('data->verification_id', $result->id)->sole();
                self::assertSame(route('admin.ktm.show', $result, false), $notification->destinationUrl());
            }
        }
    }

    public function test_worker_retries_an_operational_failure_then_records_a_safe_final_result(): void
    {
        config(['queue.default' => 'database']);
        Storage::fake('private');
        Process::fake(['*' => Process::result(errorOutput: 'internal command and private data', exitCode: 1)]);
        $student = $this->student('unverified');
        $this->actingAs($student)->post('/verifikasi-ktm', ['photo' => $this->photo('ktm-clear.png')])
            ->assertRedirect('/verifikasi-ktm');
        $result = $student->ktmVerificationRequests()->latest('id')->firstOrFail();

        $this->workOcrQueue();
        self::assertSame(KtmVerificationStatus::Processing, $result->refresh()->status);
        DB::table('jobs')->where('queue', 'ocr')->update(['available_at' => now()->timestamp]);
        $this->workOcrQueue();

        self::assertSame(KtmVerificationStatus::OcrFailed, $result->refresh()->status);
        self::assertStringContainsString('Unggah ulang', $result->ocr_error);
        self::assertStringNotContainsString('internal command', $result->ocr_error);
        Process::assertRanTimes(fn () => true, 2);
    }

    public function test_admin_decisions_preserve_the_ocr_candidate_and_are_audited(): void
    {
        $verification = $this->student('pending')->ktmVerificationRequests()->firstOrFail();
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->post("/admin/ktm/{$verification->id}/tolak", ['reason' => 'abc'])
            ->assertSessionHasErrors('reason');
        self::assertSame(KtmVerificationStatus::PendingReview, $verification->refresh()->status);

        $this->post("/admin/ktm/{$verification->id}/setujui", ['nim' => 'ATTACKER123'])
            ->assertRedirect("/admin/ktm/{$verification->id}");

        self::assertSame(KtmVerificationStatus::Approved, $verification->refresh()->status);
        self::assertSame('DEMOIPB115', $verification->user->nim);
        self::assertTrue($verification->user->is_verified);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ktm.approved', 'entity_id' => $verification->id, 'actor_admin_id' => $admin->id]);
        self::assertTrue($verification->user->notifications()->where('type', 'ktm.approved')
            ->where('data->verification_id', $verification->id)->exists());
        $this->post("/admin/ktm/{$verification->id}/tolak", ['reason' => 'Sudah pernah ditinjau.'])
            ->assertSessionHasErrors('review');
    }

    public function test_duplicate_candidate_leaves_the_second_student_unverified_and_can_be_rejected(): void
    {
        $student = $this->student('unverified');
        $duplicate = KtmVerificationRequest::query()->forceCreate([
            'user_id' => $student->id,
            'name_snapshot' => $student->name,
            'file_path' => 'demo/ktm-clear.png',
            'original_filename' => 'ktm-clear.png',
            'mime_type' => 'image/png',
            'file_size' => 10,
            'status' => KtmVerificationStatus::PendingReview,
            'ocr_nim_candidate' => $this->student('buyer')->nim,
            'ocr_finished_at' => now(),
            'submitted_at' => now()->subSecond(),
        ]);

        $this->actingAs($this->admin(), 'admin')->post("/admin/ktm/{$duplicate->id}/setujui")
            ->assertSessionHasErrors('review');
        self::assertSame(KtmVerificationStatus::PendingReview, $duplicate->refresh()->status);
        self::assertFalse($student->refresh()->is_verified);
        self::assertNull($student->nim);

        $this->post("/admin/ktm/{$duplicate->id}/tolak", ['reason' => 'NIM sudah digunakan.'])
            ->assertRedirect("/admin/ktm/{$duplicate->id}");
        self::assertSame(KtmVerificationStatus::Rejected, $duplicate->refresh()->status);
        self::assertSame('NIM sudah digunakan.', $duplicate->review_reason);
    }

    public function test_private_documents_require_the_matching_actor_and_use_order_qris_snapshot(): void
    {
        /** @var Order $order */
        $order = Order::query()->whereNotNull('qris_image_path_snapshot')->firstOrFail();
        $seller = $order->seller;
        $ktm = $seller->ktmVerificationRequests()->firstOrFail();
        $ktmUrl = "/berkas/ktm/{$ktm->id}";
        $qrisUrl = "/berkas/qris/{$seller->id}";
        $snapshotUrl = "/berkas/qris-pesanan/{$order->id}";
        $disk = Storage::disk('private');
        $snapshot = $disk->get($order->qris_image_path_snapshot);
        $this->get($ktmUrl)->assertRedirect('/login');

        $this->actingAs($order->buyer)->get($ktmUrl)->assertForbidden();
        $this->get($qrisUrl)->assertForbidden();
        $this->get($snapshotUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get("/admin/ktm/{$ktm->id}")->assertRedirect('/login');

        $outsider = User::query()->whereNotIn('id', [$order->buyer_id, $order->seller_id])->firstOrFail();
        $this->actingAs($outsider)->get($snapshotUrl)->assertForbidden();
        $this->get("/berkas/demo/{$ktm->id}")->assertNotFound();

        $this->actingAs($seller)->get($ktmUrl)->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get($qrisUrl)->assertOk();

        $seller->forceFill(['qris_image_path' => 'demo/ktm-clear.png'])->saveOrFail();
        self::assertSame($snapshot, $this->get($snapshotUrl)->streamedContent());
        self::assertNotSame($snapshot, $this->get($qrisUrl)->streamedContent());

        $this->app['auth']->guard('web')->forgetUser();
        $this->actingAs($this->admin(), 'admin')->get($ktmUrl)->assertOk();
        $this->get($snapshotUrl)->assertForbidden();
        $this->get($qrisUrl)->assertForbidden();
        $claimedOrder = Order::query()->whereNotNull('qris_image_path_snapshot')
            ->whereHas('reports', fn ($reports) => $reports->where('status', 'pending')
                ->where('handled_by_admin_id', $this->admin()->id))->firstOrFail();
        $this->get("/berkas/qris-pesanan/{$claimedOrder->id}")->assertOk();
    }

    public function test_admin_can_manage_categories_without_changing_order_snapshots(): void
    {
        $order = Order::query()->with('gig.category')->whereNotNull('gig_id')->firstOrFail();
        $category = $order->gig->category;
        $nameSnapshot = $order->category_name_snapshot;
        $categoryUrl = "/admin/kategori/{$category->id}";
        $this->actingAs($this->admin(), 'admin');
        $this->get('/admin')->assertOk();
        $this->get('/admin/ktm')->assertOk();
        $this->get('/admin/kategori')->assertOk();

        $this->post('/admin/kategori', ['name' => '  Kategori Phase 3A  '])->assertRedirect('/admin/kategori');
        $this->post('/admin/kategori', ['name' => 'kategori PHASE 3a'])->assertSessionHasErrors('name');
        self::assertTrue(Category::query()->where('name', 'Kategori Phase 3A')->firstOrFail()->is_active);

        $this->patch($categoryUrl, ['name' => 'Nama kategori baru'])->assertRedirect('/admin/kategori');
        $this->patch($categoryUrl.'/status', ['is_active' => '0'])->assertRedirect('/admin/kategori');
        self::assertSame('Nama kategori baru', $category->refresh()->name);
        self::assertFalse($category->is_active);
        self::assertSame($nameSnapshot, $order->refresh()->category_name_snapshot);
        $this->patch($categoryUrl.'/status', ['is_active' => '1'])->assertRedirect('/admin/kategori');
        self::assertTrue($category->refresh()->is_active);
        $this->delete($categoryUrl)->assertMethodNotAllowed();
    }

    private function admin(): Admin
    {
        return Admin::query()->where('email', 'admin@gigkampus.test')->firstOrFail();
    }

    private function student(string $fixture): User
    {
        return User::query()->where('email', $fixture.'.demo@apps.ipb.ac.id')->firstOrFail();
    }

    private function photo(string $fixture, ?string $name = null): UploadedFile
    {
        return new UploadedFile(resource_path('demo-fixtures/'.$fixture), $name ?? $fixture, test: true);
    }

    private function workOcrQueue(): void
    {
        self::assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'ocr', '--once' => true]));
    }
}
