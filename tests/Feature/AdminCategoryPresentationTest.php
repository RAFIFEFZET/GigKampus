<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use Tests\PostgresTestCase;

class AdminCategoryPresentationTest extends PostgresTestCase
{
    public function test_admin_category_rows_show_domain_icons_and_keep_category_actions_available(): void
    {
        $this->withoutVite();
        $this->actingAs(Admin::query()->firstOrFail(), 'admin');
        $category = Category::query()->where('name', 'Desain & UI/UX')->firstOrFail();

        $this->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee('design/category-design.svg')
            ->assertSee('Ubah Nama')
            ->assertSee('Nonaktifkan')
            ->assertSee('Aktifkan')
            ->assertSee('Nonaktif');

        $this->patch(route('admin.categories.rename', $category), ['name' => 'Desain Komunikasi Kampus'])->assertRedirect();
        $this->patch(route('admin.categories.status', $category), ['is_active' => false])->assertRedirect();
        $category->refresh();
        self::assertSame('Desain Komunikasi Kampus', $category->name);
        self::assertFalse($category->is_active);
    }
}
