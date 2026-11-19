<?php

namespace Tests\Feature;

use App\Livewire\Inbox\AdminNotifications;
use App\Livewire\Inbox\Notifications;
use App\Models\Admin;
use App\Models\Notification;
use App\Models\User;
use Livewire\Livewire;
use Tests\PostgresTestCase;

class InboxTest extends PostgresTestCase
{
    public function test_student_can_read_one_or_all_without_changing_another_recipients_entries(): void
    {
        $student = User::whereEmail('seller.demo@apps.ipb.ac.id')->firstOrFail();
        $other = User::whereEmail('buyer.demo@apps.ipb.ac.id')->firstOrFail();
        $one = $student->notifications()->forceCreate($this->entry('Notifikasi pertama'));
        $two = $student->notifications()->forceCreate($this->entry('Notifikasi kedua'));
        $private = $other->notifications()->forceCreate($this->entry('Notifikasi mahasiswa lain'));
        $adminEntry = Admin::firstOrFail()->notifications()->forceCreate($this->entry('Notifikasi Admin'));

        $this->actingAs($student);

        Livewire::test(Notifications::class)->call('markRead', $private->id)->assertStatus(404);
        Livewire::test(Notifications::class)
            ->assertSee('Notifikasi pertama')
            ->assertDontSee('Notifikasi mahasiswa lain')
            ->assertDontSee('Notifikasi Admin')
            ->call('markRead', $one->id)
            ->assertDispatched('gigkampus:counts');

        $firstRead = $one->fresh()->read_at;
        self::assertNotNull($firstRead);
        self::assertNull($two->fresh()->read_at);

        Livewire::test(Notifications::class)
            ->call('markAllRead')
            ->assertDispatched('gigkampus:counts');

        self::assertEquals($firstRead, $one->fresh()->read_at);
        self::assertNotNull($two->fresh()->read_at);
        self::assertNull($private->fresh()->read_at);
        self::assertNull($adminEntry->fresh()->read_at);
    }

    public function test_admin_inbox_requires_admin_and_excludes_student_entries_even_for_matching_ids(): void
    {
        $this->withoutVite();
        $admin = Admin::firstOrFail();
        $student = User::findOrFail($admin->id);
        $adminEntry = $admin->notifications()->forceCreate($this->entry('Review KTM yang baru'));
        $studentEntry = $student->notifications()->forceCreate($this->entry('Pesan privat mahasiswa'));

        $this->get('/admin/notifikasi')->assertRedirect('/login');
        $this->actingAs($student)->get('/admin/notifikasi')->assertRedirect('/login');

        $this->actingAs($admin, 'admin')->get('/admin/notifikasi')->assertOk()
            ->assertSee('Review KTM yang baru')->assertDontSee('Pesan privat mahasiswa');
        Livewire::test(AdminNotifications::class)->call('markRead', $studentEntry->id)->assertStatus(404);
        Livewire::test(AdminNotifications::class)->call('markAllRead')->assertDispatched('gigkampus:counts');
        self::assertNotNull($adminEntry->fresh()->read_at);
        self::assertNull($studentEntry->fresh()->read_at);
    }

    public function test_inbox_paginates_and_preserves_safe_and_legacy_destinations(): void
    {
        $student = User::whereEmail('seller.demo@apps.ipb.ac.id')->firstOrFail();
        $student->notifications()->forceCreate([
            ...$this->entry('Notifikasi lama'),
            'created_at' => now()->subDay(),
        ]);

        foreach (range(1, 9) as $number) {
            $student->notifications()->forceCreate($this->entry('Notifikasi '.$number));
        }

        $student->notifications()->forceCreate([
            ...$this->entry('Tautan aman'),
            'data' => ['relative_url' => '/permintaan/123?tab=detail#penawaran'],
        ]);
        $student->notifications()->forceCreate([
            ...$this->entry('Tautan lama'),
            'data' => ['relative_url' => '/%2fexample.com', 'service_request_id' => 456],
        ]);
        $student->notifications()->forceCreate([
            ...$this->entry('KTM lama'),
            'type' => 'ktm.approved',
            'data' => ['relative_url' => 'https://example.com/'],
        ]);

        $this->actingAs($student);
        Livewire::test(Notifications::class)
            ->assertSeeInOrder(['KTM lama', 'Tautan lama', 'Tautan aman'])
            ->assertSeeHtml('href="/permintaan/123?tab=detail#penawaran"')
            ->assertSeeHtml('href="'.route('requests.show', 456, false).'"')
            ->assertSeeHtml('href="'.route('ktm.index', absolute: false).'"')
            ->assertDontSee('example.com')
            ->assertDontSee('Notifikasi lama')
            ->call('nextPage')
            ->assertSee('Notifikasi lama')
            ->assertDontSee('Tautan aman');
    }

    public function test_destination_accepts_root_relative_urls_and_rejects_external_or_malformed_values(): void
    {
        $entry = (new Notification)->forceFill($this->entry('Tautan'));

        foreach ([
            '//example.com',
            '///example.com',
            '/\example.com',
            '/%255cexample.com',
            '/%0A/example.com',
            " /\t/example.com",
            'https://example.com',
            'javascript:alert(1)',
            ['invalid' => '/valid'],
        ] as $invalid) {
            $entry->data = ['relative_url' => $invalid];
            self::assertNull($entry->destinationUrl());
        }

        $entry->data = ['relative_url' => '/notifikasi?q=dua%20kata#detail'];
        self::assertSame('/notifikasi?q=dua%20kata#detail', $entry->destinationUrl());
    }

    private function entry(string $title): array
    {
        return ['type' => 'test.inbox', 'title' => $title, 'body' => 'Isi notifikasi.'];
    }
}
