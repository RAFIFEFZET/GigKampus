<div class="moderation-grid">
    <x-ui.input name="attachment" label="Berkas privat" type="file"
        accept=".pdf,.docx,.xlsx,.pptx,.txt,.csv,.jpg,.jpeg,.png,.zip"
        help="Maksimal 20 MB. PDF, DOCX, XLSX, PPTX, TXT, CSV, JPG, PNG, atau ZIP."/>
    <x-ui.input name="evidence_url" label="Atau tautan bukti HTTPS" type="url" maxlength="2048" placeholder="https://"/>
</div>
