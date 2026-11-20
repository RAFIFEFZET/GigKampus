@props(['type', 'target'])

<form class="moderation-stack" method="post" enctype="multipart/form-data"
    action="{{ route('reports.store', ['type' => $type->value, 'target' => $target]) }}">
    @csrf
    <x-ui.select name="category" label="Kategori" required>
        <option value="">Pilih kategori</option>
        @foreach (\App\Enums\ReportCategory::cases() as $category)
            <option value="{{ $category->value }}" @selected(old('category') === $category->value)>{{ $category->label() }}</option>
        @endforeach
    </x-ui.select>
    <x-domain.report-category-guide/>
    <x-ui.textarea name="description" label="Uraian laporan" minlength="20" maxlength="5000" rows="6" required
        help="Jelaskan kejadian dalam 20–5.000 karakter."/>
    <x-domain.report-evidence-fields/>
    <div class="moderation-actions"><x-ui.button type="submit">Kirim {{ $type === \App\Enums\ReportType::OrderComplaint ? 'Komplain' : 'Laporan' }}</x-ui.button></div>
</form>
