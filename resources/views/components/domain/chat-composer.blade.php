@props(['room', 'canRefund' => false])

<div class="chat-compose" wire:key="compose-{{ $room->id }}-{{ (int) $canRefund }}">
    @if ($canRefund)<x-domain.simulation-banner class="mb-2"/>@endif
    <form method="post" action="{{ route('chat.messages.store', $room) }}" enctype="multipart/form-data"
        wire:ignore x-data="{ expanded: false, type: 'regular', busy: false, error: '' }"
        x-on:submit.prevent="
            if (!busy) {
                busy = true; error = '';
                gigkampusChatSubmit($el)
                    .then(() => { expanded = false; type = 'regular'; $dispatch('gigkampus:chat'); })
                    .catch(problem => { error = problem.message; })
                    .finally(() => { busy = false; });
            }">
        @csrf
        <input type="hidden" name="client_message_key">
        <input type="hidden" name="message_type" x-bind:value="type" value="regular">
        <div class="chat-compose-row">
            <button type="button" class="chat-icon-button" aria-label="Pilihan lampiran" x-bind:aria-expanded="expanded"
                x-on:click="expanded = !expanded">
                <img src="{{ asset('design/chat-attach.svg') }}" alt="">
            </button>
            <label for="message_body" class="sr-only">Tulis pesan</label>
            <textarea class="chat-input" id="message_body" name="message_body" rows="1" maxlength="5000"
                placeholder="Tulis pesan…"></textarea>
            <button class="chat-send-button" type="submit" aria-label="Kirim pesan" x-bind:disabled="busy">
                <img src="{{ asset('design/chat-send.svg') }}" alt="">
            </button>
        </div>
        <div x-cloak x-show="expanded" class="mt-3 grid gap-2 rounded-xl bg-campus-background p-3">
            @if ($canRefund)
                <label class="text-sm" for="chat-type">Jenis pesan</label>
                <select id="chat-type" class="field-control" x-model="type">
                    <option value="regular">Pesan biasa</option><option value="refund_proof">Bukti Refund</option>
                </select>
            @endif
            <x-ui.file-upload name="attachment" label="Lampiran"
                accept=".pdf,.docx,.xlsx,.pptx,.txt,.csv,.jpg,.jpeg,.png,.zip" help="Maks. 20 MB. Bukti refund wajib JPG atau PNG maks. 5 MB."
                x-bind:required="type === 'refund_proof'"/>
            <x-ui.input name="link_url" type="url" label="Tautan HTTPS" maxlength="2048" x-bind:disabled="type === 'refund_proof'"/>
        </div>
        <p x-cloak x-show="error" x-text="error" class="field-error mt-2" role="alert"></p>
    </form>
</div>
