@props(['offer', 'serviceRequest'])

@php
    $student = auth('web')->user();
    $isParty = $student && ($student->hasId($offer->seller_id) || $student->hasId($serviceRequest->buyer_id));
    $canDiscuss = $isParty && ($offer->status === \App\Enums\PriceOfferStatus::Accepted
        || ($student->canCreateNewTransaction() && $offer->canBeEdited($serviceRequest)
            && $offer->seller->canPublishGig() && $serviceRequest->category->is_active));
@endphp

@if ($canDiscuss)
    <form action="{{ route('chat.offer', $offer) }}" method="post">
        @csrf
        <x-ui.button type="submit" variant="outline">Diskusikan Penawaran</x-ui.button>
    </form>
@endif
