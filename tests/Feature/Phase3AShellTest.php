<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\PostgresTestCase;

class Phase3AShellTest extends PostgresTestCase
{
    public function test_inbox_shows_only_the_signed_in_students_entries(): void
    {
        $this->withoutVite();
        $seller = User::whereEmail('seller.demo@apps.ipb.ac.id')->firstOrFail();
        $buyer = User::whereEmail('buyer.demo@apps.ipb.ac.id')->firstOrFail();

        $seller->notifications()->forceCreate([
            'type' => 'offer.rejected', 'title' => 'Tawaran demo ditutup', 'body' => 'Permintaan jasa sudah ditutup.',
        ]);
        $buyer->notifications()->forceCreate([
            'type' => 'offer.submitted', 'title' => 'Penawaran privat Buyer', 'body' => 'Ada penawaran baru.',
        ]);

        $this->get('/notifikasi')->assertRedirect('/login');
        $this->actingAs($seller)->get('/notifikasi')->assertOk()
            ->assertSee('Tawaran demo ditutup')->assertDontSee('Penawaran privat Buyer');
    }

    public function test_chat_and_order_placeholders_require_an_eligible_student(): void
    {
        $this->withoutVite();
        $student = User::whereEmail('unverified.demo@apps.ipb.ac.id')->firstOrFail();
        $buyer = User::whereEmail('buyer.demo@apps.ipb.ac.id')->firstOrFail();

        $this->get('/chat')->assertRedirect('/login');
        $this->actingAs($student)->get('/chat')->assertForbidden();
        $this->get('/pesanan')->assertForbidden();

        $this->actingAs($buyer)->get('/chat')->assertOk()->assertSee('Chat');
        $this->get('/pesanan')->assertOk()->assertSee('Pesanan Saya');
    }
}
