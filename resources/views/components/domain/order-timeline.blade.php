@props(['order'])

@php
    $step = match ($order->status) {
        \App\Enums\OrderStatus::AwaitingPayment, \App\Enums\OrderStatus::AwaitingValidation => 0,
        \App\Enums\OrderStatus::InProgress => 1,
        \App\Enums\OrderStatus::Delivered, \App\Enums\OrderStatus::Revision => 2,
        \App\Enums\OrderStatus::Completed => 3,
        default => null,
    };
@endphp

@if ($step !== null)
    <ol class="order-stage" aria-label="Tahap pesanan">
        @foreach (['Dibuat', 'Bayar tervalidasi', 'Hasil dikirim', 'Selesai'] as $index => $label)
            <li data-passed="{{ $index < $step ? 'true' : 'false' }}" @if ($index === $step) aria-current="step" @endif>
                <span class="step-marker" aria-hidden="true">{{ $index < $step ? '✓' : $index + 1 }}</span>
                <span>{{ $label }}</span>
            </li>
        @endforeach
    </ol>
@endif
