@props(['serviceRequest' => null, 'categories'])

<x-ui.input name="title" label="Judul" :value="$serviceRequest?->title" maxlength="150" minlength="5" required/>
<x-ui.select name="category_id" label="Kategori" :value="$serviceRequest?->category_id" :options="['' => 'Pilih kategori'] + $categories->pluck('name', 'id')->all()" required/>
<x-ui.textarea name="description" label="Brief" :value="$serviceRequest?->description" minlength="20" maxlength="5000" required placeholder="Jelaskan kebutuhan dan hasil yang diharapkan."/>
<div class="form-grid">
    <x-ui.input name="budget_min" label="Anggaran Minimum (Rp)" type="number" :value="$serviceRequest?->budget_min" min="0" max="100000000" required/>
    <x-ui.input name="budget_max" label="Anggaran Maksimum (Rp)" type="number" :value="$serviceRequest?->budget_max" min="1" max="100000000" required/>
</div>
<x-ui.input name="deadline_date" label="Tenggat (WIB)" type="date" :value="$serviceRequest?->deadline_date?->toDateString()" :min="now('Asia/Jakarta')->toDateString()" required/>
