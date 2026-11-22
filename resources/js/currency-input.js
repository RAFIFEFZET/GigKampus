const integer = /^\d+$/;
const groupedInteger = /^\d{1,3}(\.\d{3})+$/;

export default function currencyInput(minimum, maximum) {
    return {
        validSyntax: true,

        sync(input, event = null) {
            const text = input.value;
            // A digit edit can leave the separators from the previous formatted value
            // in the wrong positions. A new pasted number must use valid grouping.
            const digitEdit = this.validSyntax && event
                && (event.inputType?.startsWith('delete') || integer.test(event.data ?? ''))
                && /^[\d.]*$/.test(text);
            this.validSyntax = text === '' || integer.test(text) || groupedInteger.test(text) || !!digitEdit;

            const raw = this.validSyntax ? text.replace(/\./g, '').replace(/^0+(?=\d)/, '') : '';
            const formatted = raw.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            if (this.validSyntax && formatted !== text) {
                const digitsBeforeCaret = text.slice(0, input.selectionStart).replace(/\D/g, '').length;
                input.value = formatted;
                let caret = 0;
                let digits = 0;
                while (caret < formatted.length && digits < digitsBeforeCaret) {
                    if (formatted[caret] !== '.') digits += 1;
                    caret += 1;
                }
                input.setSelectionRange(caret, caret);
            }

            let error = '';
            if (!this.validSyntax) {
                error = 'Masukkan Rupiah bulat, tanpa pecahan.';
            } else if (raw !== '' && ((minimum !== null && Number(raw) < minimum)
                || (maximum !== null && Number(raw) > maximum))) {
                error = 'Nominal di luar batas yang diizinkan.';
            }
            input.setCustomValidity(error);

            if (this.$refs.raw.value !== raw) {
                this.$refs.raw.value = raw;
                this.$refs.raw.dispatchEvent(new Event('input', { bubbles: true }));
            }
        },
    };
}
