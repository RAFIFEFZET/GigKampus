<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Category;
use App\Services\Audit;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminCategoryController extends Controller
{
    public function index(): View
    {
        return view('pages.admin.categories', [
            'categories' => Category::query()->orderBy('name')->paginate(15),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $name = $this->validatedName($request);
        $this->changeName(function () use ($request, $name): void {
            $category = Category::query()->create(['name' => $name]);
            Audit::record($request->user(), 'category.created', $category, after: $category->only('name', 'is_active'));
        });

        return to_route('admin.categories.index')->with('status', 'Kategori berhasil ditambahkan.');
    }

    public function rename(Request $request, Category $category): RedirectResponse
    {
        $request->merge(['category_id' => $category->id]);
        $name = $this->validatedName($request);
        $this->changeName(function () use ($request, $category, $name): void {
            $this->update($request->user(), $category, 'category.renamed', fn (Category $locked) => $locked->name = $name);
        }, $category->id);

        return to_route('admin.categories.index')->with('status', 'Nama kategori berhasil disimpan.');
    }

    public function changeStatus(Request $request, Category $category): RedirectResponse
    {
        $request->validate(['is_active' => ['required', 'boolean']]);
        $active = $request->boolean('is_active');
        DB::transaction(function () use ($request, $category, $active): void {
            $this->update($request->user(), $category, $active ? 'category.activated' : 'category.deactivated',
                fn (Category $locked) => $active ? $locked->activate() : $locked->deactivate()
            );
        });

        return to_route('admin.categories.index')->with('status', $active ? 'Kategori diaktifkan.' : 'Kategori dinonaktifkan.');
    }

    private function validatedName(Request $request): string
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
        ], [
            'name.required' => 'Masukkan nama kategori.',
            'name.min' => 'Nama kategori harus berisi minimal 2 karakter.',
            'name.max' => 'Nama kategori harus berisi maksimal 100 karakter.',
        ])['name'];
    }

    private function changeName(Closure $write, ?int $categoryId = null): void
    {
        try {
            DB::transaction($write);
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->errorInfo[2] ?? '', '"categories_name_unique"')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'name' => 'Nama kategori ini sudah digunakan.',
            ])->redirectTo(route('admin.categories.index').($categoryId ? '#kategori-'.$categoryId : ''));
        }
    }

    private function update(Admin $actor, Category $category, string $action, Closure $change): void
    {
        $locked = Category::query()->lockForUpdate()->findOrFail($category->id);
        $before = $locked->only('name', 'is_active');
        $change($locked);

        if ($locked->isDirty()) {
            $locked->saveOrFail();
            Audit::record($actor, $action, $locked, $before, $locked->only('name', 'is_active'));
        }
    }
}
