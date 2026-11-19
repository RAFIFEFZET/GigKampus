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
                <ul class="divide-y divide-campus-border">
                    @foreach ($categories as $category)
                        @php($editingCategory = (int) old('category_id') === $category->id)
                        <li id="kategori-{{ $category->id }}" class="grid min-w-0 gap-3 py-4 first:pt-0 last:pb-0 lg:grid-cols-[minmax(11rem,1fr)_auto] lg:items-start">
                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                <h3>{{ $category->name }}</h3>
                                <x-domain.status-badge :status="$category->is_active ? 'active' : 'inactive'"/>
                            </div>

                            <div class="flex flex-wrap items-start gap-2">
                                <details class="flex-1 basis-32" @if ($editingCategory && $errors->has('name')) open @endif>
                                    <summary class="btn btn-outline list-none">Ubah Nama<span class="sr-only"> {{ $category->name }}</span></summary>
                                    <form method="POST" action="{{ route('admin.categories.rename', $category) }}" class="mt-3 flex min-w-0 flex-wrap items-start gap-2">
                                        @csrf
                                        @method('PATCH')
                                        <div class="field min-w-0 flex-1 basis-36">
                                            <label for="category-name-{{ $category->id }}" class="sr-only">Nama baru untuk {{ $category->name }}</label>
                                            <input id="category-name-{{ $category->id }}" name="name" class="field-control" minlength="2" maxlength="100" required
                                                value="{{ $editingCategory && is_string(old('name')) ? old('name') : $category->name }}"
                                                @if ($editingCategory && $errors->has('name')) aria-invalid="true" aria-describedby="category-error-{{ $category->id }}" @endif>
                                            @if ($editingCategory)
                                                @error('name')
                                                    <p class="field-error" id="category-error-{{ $category->id }}" role="alert">{{ $message }}</p>
                                                @enderror
                                            @endif
                                        </div>
                                        <x-ui.button type="submit">Simpan</x-ui.button>
                                    </form>
                                </details>
                                <form method="POST" action="{{ route('admin.categories.status', $category) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $category->is_active ? '0' : '1' }}">
                                    <x-ui.button type="submit" :variant="$category->is_active ? 'outline' : 'secondary'">
                                        {{ $category->is_active ? 'Nonaktifkan' : 'Aktifkan' }}<span class="sr-only"> {{ $category->name }}</span>
                                    </x-ui.button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <x-ui.pagination :items="$categories"/>
            @endif
        </x-ui.card>
    </div>
@endsection
