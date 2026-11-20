@props(['entry', 'kind'])

@if ($entry->file_path)
    <a class="text-campus-link underline" href="{{ route('files.show', ['jenis' => $kind, 'id' => $entry->id]) }}">
        Unduh {{ $entry->original_filename }}
    </a>
@elseif ($entry->evidence_url)
    <a class="text-campus-link underline" href="{{ $entry->evidence_url }}" target="_blank" rel="noopener noreferrer">Buka tautan bukti</a>
@endif
