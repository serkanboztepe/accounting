@if ($invoice->invoice_path)
    <div style="height: 70vh;">
        <iframe
            src="{{ Storage::disk('public')->url($invoice->invoice_path) }}"
            style="width:100%;height:100%;border:none;border-radius:0.5rem;"
        ></iframe>
    </div>
@else
    <p class="text-center text-sm text-gray-500">PDF bulunamadı.</p>
@endif
