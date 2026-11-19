@props(['review'])

<article class="rounded-xl border border-campus-border p-4">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="flex items-center gap-2">
            <x-ui.avatar :user="$review->reviewer" class="size-7"/>
            <span class="font-semibold">{{ $review->reviewer->name }}</span>
        </div>
        <x-ui.wib-time :date="$review->review_date" :with-time="false" class="text-xs text-campus-muted"/>
    </div>
    <p class="my-2 tracking-wider text-amber-700" aria-label="{{ $review->rating }} dari 5 bintang">{{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }}</p>
    @if ($review->comment)<p class="whitespace-pre-wrap text-sm">{{ $review->comment }}</p>@endif
</article>
