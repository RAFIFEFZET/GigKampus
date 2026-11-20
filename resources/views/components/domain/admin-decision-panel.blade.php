@props(['report', 'outcomes', 'actions', 'proofs', 'payment'])

<x-ui.card class="moderation-panel moderation-stack">
    <x-ui.section-header level="2" title="Putusan Mediasi" subtitle="Putusan akhir bersifat permanen."/>
    <form class="moderation-stack" method="post" action="{{ route('admin.reports.decide', $report) }}"
        x-data="{ decision: @js(old('decision_code', '')), paymentVerified: @js($payment?->isVerified() ?? false),
            get checksPayment() { return this.decision === 'verify_payment_continue' || (this.decision === 'cancel_and_refund' && !this.paymentVerified) } }">
        @csrf
        <input type="hidden" name="status" x-bind:value="decision.startsWith('dismiss_') ? 'dismissed' : 'resolved'">
        <x-ui.select name="decision_code" label="Hasil putusan" x-model="decision" required>
            <option value="">Pilih hasil</option>
            @foreach ($outcomes as $code => $label)
                <option value="{{ $code }}">{{ $label }}</option>
            @endforeach
            <option value="{{ $report->order_id ? 'dismiss_restore' : 'dismiss_report' }}">Tolak laporan{{ $report->order_id ? ' dan pulihkan tahap' : '' }}</option>
        </x-ui.select>
        @if ($payment)
            <div class="moderation-stack" x-cloak x-show="checksPayment">
                <x-ui.select name="verified_proof_id" label="Bukti pembayaran yang disahkan"
                    x-bind:disabled="!checksPayment" x-bind:required="checksPayment">
                    <option value="">Pilih bukti</option>
                    @foreach ($proofs as $proof)
                        <option value="{{ $proof->id }}" @selected((string) old('verified_proof_id') === (string) $proof->id)>Percobaan {{ $proof->attempt_no }} · {{ $proof->original_filename }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.field name="seller_evidence_reviewed">
                    <label class="flex items-start gap-2 text-sm">
                        <input class="mt-1" type="checkbox" name="seller_evidence_reviewed" value="1"
                            x-bind:disabled="!checksPayment" x-bind:required="checksPayment" @checked(old('seller_evidence_reviewed'))>
                        <span>Saya telah memeriksa bukti atau klarifikasi Seller dan mencatat dasar verifikasi. Jika Seller belum menjawab, dasar verifikasi lain dicatat setelah tenggat.</span>
                    </label>
                </x-ui.field>
                <div x-show="decision === 'verify_payment_continue'">
                    <x-ui.field name="parties_ready">
                        <label class="flex items-start gap-2 text-sm">
                            <input class="mt-1" type="checkbox" name="parties_ready" value="1"
                                x-bind:disabled="decision !== 'verify_payment_continue'" x-bind:required="decision === 'verify_payment_continue'" @checked(old('parties_ready'))>
                            <span>Kedua pihak siap melanjutkan pesanan.</span>
                        </label>
                    </x-ui.field>
                </div>
            </div>
        @endif
        <x-ui.textarea name="decision_note" label="Alasan putusan" minlength="5" maxlength="5000" required
            help="Cantumkan dasar putusan tanpa menyalin keterangan atau data pribadi dari klarifikasi."/>
        @if ($actions)
            <x-ui.select name="moderation_action" label="Tindakan moderasi tambahan" :options="['' => 'Tanpa tindakan tambahan', ...$actions]"/>
            <x-ui.textarea name="moderation_reason" label="Alasan moderasi" minlength="5" maxlength="1000"
                help="Wajib bila memilih tindakan. Alasan ini terlihat bagi pihak yang dikenai tindakan."/>
        @endif
        <div><x-ui.button variant="danger" type="submit">Tetapkan Putusan Akhir</x-ui.button></div>
    </form>
</x-ui.card>
