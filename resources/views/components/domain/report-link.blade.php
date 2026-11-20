@props(['type', 'subject'])

@php
    $actor = auth('web')->user();
    $reportPolicy = app(\App\Policies\ReportPolicy::class);
@endphp
@if ($actor && $reportPolicy->submit($actor, $subject)
    && ($type !== 'order_complaint' || $reportPolicy->complaintUnavailable($actor, $subject) === null))
    <x-ui.button variant="ghost" :href="route('reports.create', ['type' => $type, 'target' => $subject->id])" {{ $attributes }}>{{ $slot }}</x-ui.button>
@endif
