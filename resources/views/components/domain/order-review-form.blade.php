@props(['order'])

@php($selected = is_scalar(old('rating')) ? (int) old('rating') : 0)
<x-ui.card class="stack">
    <x-ui.badge tone="warning" class="self-start">Pesanan Selesai</x-ui.badge>
    <x-ui.section-header level="2" title="Beri Rating & Ulasan"/>
    <form method="post" action="{{ route('orders.reviews.store', $order) }}" class="stack">
        @csrf
        <fieldset x-data="{ rating: {{ $selected }} }">
            <legend class="mb-1 text-sm font-medium">Rating (1–5)</legend>
            <div class="review-stars">
                @foreach (range(1, 5) as $star)
                    <label x-bind:class="{ 'star-selected': rating >= {{ $star }} }">
                        <input class="sr-only" type="radio" name="rating" value="{{ $star }}" x-on:change="rating = {{ $star }}"
                            @checked($selected === $star) aria-label="{{ $star }} dari 5 bintang" required>
                        <span aria-hidden="true">★</span>
                    </label>
                @endforeach
            </div>
            @error('rating')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </fieldset>
        <x-ui.textarea name="comment" label="Ulasan Anda (opsional)" maxlength="2000" rows="3"/>
        <x-ui.button type="submit">Kirim Ulasan</x-ui.button>
    </form>
</x-ui.card>
