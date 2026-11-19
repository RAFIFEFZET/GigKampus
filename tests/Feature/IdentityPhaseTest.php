<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\PostgresTestCase;

class IdentityPhaseTest extends PostgresTestCase
{
    public function test_registration_normalizes_student_identity_and_retains_the_complete_password(): void
    {
        $password = '  kata sandi  ';

        $this->post('/register', [
            'name' => '  Rafif  ',
            'email' => '  UJI.REGISTRASI@APPS.IPB.AC.ID  ',
            'phone_number' => '0812-3456-7890',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertRedirect('/verifikasi-ktm');

        $user = User::query()->where('email', 'uji.registrasi@apps.ipb.ac.id')->sole();
        $this->assertSame('Rafif', $user->name);
        $this->assertSame('+6281234567890', $user->phone_number);
        $this->assertFalse($user->is_verified);
        $this->assertSame('argon2id', password_get_info($user->getAuthPassword())['algoName']);
        $this->assertTrue(Hash::check($password, $user->getAuthPassword()));
        $this->assertFalse(Hash::check(trim($password), $user->getAuthPassword()));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('admin');
    }

    public function test_registration_rejects_non_campus_duplicate_and_invalid_credentials(): void
    {
        $input = [
            'name' => 'Mahasiswa Uji',
            'email' => 'uji.aturan@apps.ipb.ac.id',
            'phone_number' => '081234567890',
            'password' => 'password valid',
            'password_confirmation' => 'password valid',
        ];
        Admin::query()->forceCreate([
            'name' => 'Admin kampus',
            'email' => 'admin.kampus@apps.ipb.ac.id',
            'password_hash' => $input['password'],
        ]);

        foreach (['uji@example.test', 'admin.kampus@apps.ipb.ac.id', 'buyer.demo@apps.ipb.ac.id'] as $email) {
            $this->postJson('/register', array_replace($input, ['email' => $email]))
                ->assertUnprocessable()->assertJsonValidationErrors('email');
        }

        $this->postJson('/register', array_replace($input, [
            'phone_number' => '+15551234567',
            'password' => str_repeat('a', 129),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['phone_number', 'password']);
        $this->postJson('/register', array_replace($input, [
            'password' => ' ',
            'password_confirmation' => ' ',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertGuest('web');
    }

    public function test_the_same_login_accepts_both_accounts_with_one_guard_at_a_time(): void
    {
        $this->post('/login', ['email' => ' ADMIN@GIGKAMPUS.TEST ', 'password' => DemoSeeder::PASSWORD])
            ->assertRedirect('/admin');
        $this->assertAuthenticated('admin');
        $this->assertGuest('web');
        $this->post('/register')->assertRedirect();
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest('web');
        $this->assertGuest('admin');

        $this->post('/login', ['email' => 'buyer.demo@apps.ipb.ac.id', 'password' => DemoSeeder::PASSWORD])
            ->assertRedirect('/');
        $this->assertAuthenticated('web');
        $this->assertGuest('admin');
    }

    public function test_login_failures_do_not_identify_the_account_and_are_rate_limited(): void
    {
        $failed = $this->postJson('/login', ['email' => DemoSeeder::ADMIN_EMAIL, 'password' => 'salah sekali'])
            ->assertUnprocessable()->json('errors.email.0');
        $this->postJson('/login', ['email' => 'unknown@example.test', 'password' => 'salah sekali'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', $failed);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->postJson('/login', ['email' => ' ADMIN@GIGKAMPUS.TEST ', 'password' => 'salah sekali'])
                ->assertUnprocessable();
        }

        $this->postJson('/login', ['email' => DemoSeeder::ADMIN_EMAIL, 'password' => DemoSeeder::PASSWORD])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertGuest('admin');
    }

    public function test_a_locked_name_can_be_kept_while_updating_other_profile_fields(): void
    {
        Storage::fake('public');
        $user = $this->student('pending');
        $this->actingAs($user, 'web')->patchJson('/profil', ['name' => 'Nama diganti'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson('/profil', ['email' => 'diganti@apps.ipb.ac.id'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patch('/profil', [
            'name' => $user->name,
            'phone_number' => '0812 3456 7890',
            'bio' => 'Bio yang bisa disunting.',
            'avatar' => $this->demoPng(),
        ])->assertRedirect('/profil');

        $updated = $user->fresh();
        $this->assertSame($user->name, $updated->name);
        $this->assertSame($user->email, $updated->email);
        $this->assertSame('+6281234567890', $updated->phone_number);
        $this->assertSame('Bio yang bisa disunting.', $updated->bio);
        Storage::disk('public')->assertExists($updated->avatar_path);
    }

    public function test_name_can_change_after_ktm_rejection_but_cannot_change_after_approval(): void
    {
        $rejected = $this->student('rejected');
        $this->actingAs($rejected, 'web')->patch('/profil', ['name' => 'Nama yang dikoreksi'])
            ->assertRedirect('/profil');
        $this->assertSame('Nama yang dikoreksi', $rejected->fresh()->name);

        $approved = $this->student('buyer');
        $this->actingAs($approved, 'web')->patchJson('/profil', ['name' => 'Nama berbeda'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame($approved->name, $approved->fresh()->name);
    }

    public function test_invalid_form_values_redisplay_profile_and_payment_validation_errors(): void
    {
        $seller = $this->student('seller2');

        $this->actingAs($seller, 'web')->from('/profil')->followingRedirects()
            ->patch('/profil', ['bio' => ['bukan teks'], 'phone_number' => ['bukan nomor']])
            ->assertOk()->assertSee('id="bio-error"', false)->assertSee('id="phone_number-error"', false);

        $this->from('/studio/rek-qr')->followingRedirects()
            ->patch('/studio/rek-qr', ['bank_id' => [1]])
            ->assertOk()->assertSee('id="bank_id-error"', false);
    }

    public function test_password_change_requires_the_current_password_and_preserves_whitespace(): void
    {
        $user = $this->student('buyer');
        $password = '  sandi baru  ';
        $credentials = ['password' => $password, 'password_confirmation' => $password];

        $this->actingAs($user, 'web')->putJson('/profil/password', [
            'current_password' => 'password salah',
            ...$credentials,
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->put('/profil/password', [
            'current_password' => DemoSeeder::PASSWORD,
            ...$credentials,
        ])->assertRedirect('/profil');

        $hash = $user->fresh()->getAuthPassword();
        $this->assertTrue(Hash::check($password, $hash));
        $this->assertFalse(Hash::check(trim($password), $hash));
        $this->assertFalse(Hash::check(DemoSeeder::PASSWORD, $hash));
    }

    public function test_payment_methods_reject_partial_data_and_preserve_bank_digits(): void
    {
        $seller = $this->student('seller2');
        $this->actingAs($seller, 'web')->patchJson('/studio/rek-qr', ['bank_account_number' => '000-12 34'])
            ->assertUnprocessable()->assertJsonValidationErrors(['bank_id', 'bank_account_holder_name']);
        $this->patchJson('/studio/rek-qr', ['qris_holder_name' => 'Merchant Uji'])
            ->assertUnprocessable()->assertJsonValidationErrors('qris_image');
        $this->patch('/studio/rek-qr', [
            'bank_id' => $seller->bank_id,
            'bank_account_number' => '0001234',
            'bank_account_holder_name' => 'Nama pemilik',
        ])->assertRedirect('/studio/rek-qr');
        $this->assertSame('0001234', $seller->fresh()->bank_account_number);
    }

    public function test_last_payment_method_cannot_be_removed_with_an_active_gig_or_pending_offer(): void
    {
        $seller = $this->student('seller2');
        $this->actingAs($seller, 'web')->deleteJson('/studio/rek-qr/bank')->assertConflict();

        DB::table('gigs')->where('seller_id', $seller->id)->where('status', 'active')->update(['status' => 'inactive']);
        $this->deleteJson('/studio/rek-qr/bank')->assertConflict();

        $this->assertNotNull($seller->fresh()->bank_account_number);

        DB::table('price_offers')->where('seller_id', $seller->id)->where('status', 'pending')
            ->update(['status' => 'withdrawn', 'status_reason' => 'Penawaran ditarik.']);
        $this->delete('/studio/rek-qr/bank')->assertRedirect('/studio/rek-qr');
        $this->assertFalse($seller->fresh()->hasCompletePaymentMethod());
    }

    public function test_replacing_and_removing_qris_preserves_the_order_snapshot(): void
    {
        Storage::fake('public');
        Storage::fake('private');
        $seller = $this->student('seller');
        $order = $seller->sellerOrders()->whereNotNull('qris_image_path_snapshot')->firstOrFail();
        $oldPath = $order->qris_image_path_snapshot;
        Storage::disk('private')->put($oldPath, 'fixture QRIS');

        $this->actingAs($seller, 'web')->patch('/studio/rek-qr', [
            'qris_image' => $this->demoPng(),
            'qris_holder_name' => 'Merchant Baru',
        ])->assertRedirect('/studio/rek-qr');
        $newPath = $seller->fresh()->qris_image_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('private')->assertExists($newPath);
        Storage::disk('public')->assertMissing($newPath);

        $this->delete('/studio/rek-qr/qris')->assertRedirect('/studio/rek-qr');
        $this->assertNull($seller->fresh()->qris_image_path);
        $this->assertSame($oldPath, $order->fresh()->qris_image_path_snapshot);
        Storage::disk('private')->assertExists($oldPath);
    }

    private function student(string $key): User
    {
        return User::query()->where('email', $key.'.demo@apps.ipb.ac.id')->sole();
    }

    private function demoPng(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('test.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/rfcAAAAASUVORK5CYII=',
        ));
    }
}
