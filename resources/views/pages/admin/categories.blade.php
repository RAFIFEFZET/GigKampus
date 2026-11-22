@extends('layouts.admin')

@section('title', 'Kategori')

@section('content')
    <div class="stack">
        <x-ui.section-header title="Kategori" subtitle="Kelola kategori yang tersedia untuk Gig dan Permintaan Jasa."/>

        <x-ui.card class="stack">
            <h2>Tambah Kategori</h2>
            <form method="POST" action="{{ route('admin.categories.store') }}" class="flex flex-wrap items-start gap-3">
                @csrf
                <div class="field min-w-0 flex-1 basis-48">
                    <label for="category-new" class="sr-only">Nama kategori baru</label>
                    <input id="category-new" name="name" class="field-control" placeholder="Nama kategori baru" minlength="2" maxlength="100" required
                        value="{{ old('category_id') === null && is_string(old('name')) ? old('name') : '' }}"
                        @if (old('category_id') === null && $errors->has('name')) aria-invalid="true" aria-describedby="category-new-error" @endif>
                    @if (old('category_id') === null)
                        @error('name')
                            <p class="field-error" id="category-new-error" role="alert">{{ $message }}</p>
                        @enderror
                    @endif
                </div>
                <x-ui.button type="submit">Tambah Kategori</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card class="stack">
            <h2>Daftar Kategori</h2>
            @if ($categories->isEmpty())
                <x-ui.empty-state title="Belum ada kategori"/>
            @else
                <ul class="admin-category-list">
                    @foreach ($categories as $category)
                        @php($editingCategory = (int) old('category_id') === $category->id)
                        <li id="kategori-{{ $category->id }}" class="admin-category-item" x-data="{ editing: @js($editingCategory && $errors->has('name')) }">
                            <div class="admin-category-row">
                                <div class="admin-category-identity">
                                    <span class="admin-category-icon"><x-domain.category-icon :$category width="20" height="20"/></span>
                                    <div class="admin-category-name">
                                        <h3>{{ $category->name }}</h3>
                                        <x-domain.status-badge :status="$category->is_active ? 'active' : 'inactive'"/>
                                    </div>
                                </div>

                                <div class="admin-category-actions">
                                    <x-ui.button variant="secondary" class="admin-category-action"
                                        x-on:click="editing = !editing; if (editing) $nextTick(() => $refs.categoryName.focus())"
                                        x-bind:aria-expanded="editing" aria-controls="category-rename-{{ $category->id }}">
                                        <x-domain.admin-action-icon name="edit"/> Ubah Nama<span class="sr-only"> {{ $category->name }}</span>
                                    </x-ui.button>
                                    <form method="POST" action="{{ route('admin.categories.status', $category) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_active" value="{{ $category->is_active ? '0' : '1' }}">
                                        <x-ui.button type="submit" variant="outline" @class(['admin-category-action', 'admin-category-deactivate' => $category->is_active, 'admin-category-activate' => ! $category->is_active])>
                                            <x-domain.admin-action-icon :name="$category->is_active ? 'block' : 'check'"/>
                                            {{ $category->is_active ? 'Nonaktifkan' : 'Aktifkan' }}<span class="sr-only"> {{ $category->name }}</span>
                                        </x-ui.button>
                                    </form>
                                </div>
                            </div>

                            <form method="POST" action="{{ route('admin.categories.rename', $category) }}" id="category-rename-{{ $category->id }}"
                                class="admin-category-rename" x-show="editing" @unless($editingCategory && $errors->has('name')) x-cloak @endunless>
                                @csrf
                                @method('PATCH')
                                <div class="field min-w-0 flex-1 basis-36">
                                    <label for="category-name-{{ $category->id }}" class="sr-only">Nama baru untuk {{ $category->name }}</label>
                                    <input id="category-name-{{ $category->id }}" name="name" class="field-control" minlength="2" maxlength="100" required x-ref="categoryName"
                                        value="{{ $editingCategory && is_string(old('name')) ? old('name') : $category->name }}"
                                        @if ($editingCategory && $errors->has('name')) aria-invalid="true" aria-describedby="category-error-{{ $category->id }}" @endif>
                                    @if ($editingCategory)
                                        @error('name')
                                            <p class="field-error" id="category-error-{{ $category->id }}" role="alert">{{ $message }}</p>
                                        @enderror
                                    @endif
                                </div>
                                <x-ui.button type="submit" class="admin-category-action">Simpan</x-ui.button>
                            </form>
                        </li>
                    @endforeach
                </ul>
                <x-ui.pagination :items="$categories"/>
            @endif
        </x-ui.card>
    </div>
@endsection
