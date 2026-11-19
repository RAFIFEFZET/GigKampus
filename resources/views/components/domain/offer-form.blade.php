@props(['serviceRequest', 'offer' => null, 'retained' => []])

<form class="stack" action="{{ $offer ? route('offers.update', $offer) : route('offers.store', $serviceRequest) }}" method="post">
    @csrf
    @if ($offer)
        @method('PATCH')
        <input type="hidden" name="offer_version" value="{{ $offer->version }}">
    @endif
    <input type="hidden" name="request_version" value="{{ $serviceRequest->version }}">
    <x-ui.input name="offered_price" label="Nominal Harga Tawar (Rp)" type="number" :value="$retained['offered_price'] ?? $offer?->offered_price" min="1" :max="$serviceRequest->budget_max" required/>
    <x-ui.input name="max_revisions" label="Kuota Revisi" type="number" :value="$retained['max_revisions'] ?? $offer?->max_revisions ?? 0" min="0" max="20" required/>
    <x-ui.textarea name="proposal_note" label="Catatan Penawaran" :value="$retained['proposal_note'] ?? $offer?->proposal_note" minlength="20" maxlength="3000" required/>
    <x-ui.button type="submit">{{ $offer ? 'Simpan Penawaran' : 'Kirim Penawaran Harga' }}</x-ui.button>
</form>
