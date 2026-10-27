<section class="panel payment-settings-panel">
    <div class="payment-settings-heading">
        <div class="payment-settings-title">
            <span class="payment-settings-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/></svg>
            </span>
            <div>
                <span class="payment-eyebrow">PAYMENT SETUP</span>
                <h2>DuitNow QR</h2>
                <p>Set the merchant QR customers see when they choose QR at checkout.</p>
            </div>
        </div>
        <span class="payment-setup-badge {{ $paymentSettings?->qr_code_path ? 'is-ready' : 'is-empty' }}">
            <i></i>{{ $paymentSettings?->qr_code_path ? 'QR is ready' : 'Setup required' }}
        </span>
    </div>

    <div class="payment-settings-grid">
        <div class="qr-preview-card">
            @if($paymentSettings?->qr_code_path)
                <img class="qr-preview-image" src="{{ Storage::disk('public')->url($paymentSettings->qr_code_path) }}" alt="Current Naise merchant DuitNow QR code">
                <div class="qr-preview-caption"><b>{{ $paymentSettings->merchant_name ?: 'Naise Coffee' }}</b><span>Active merchant QR</span></div>
            @else
                <span class="qr-placeholder-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h3v3h-3zM19 14v2M14 19h2M19 19h2v2h-2z"/></svg></span>
                <b>No merchant QR uploaded</b>
                <p>Upload the official Naise QR code to enable QR checkout for customers.</p>
            @endif
        </div>

        <form class="payment-settings-form" method="POST" action="{{ route('admin.payment-settings.qr') }}" enctype="multipart/form-data">
            @csrf
            <label class="payment-field" for="merchant-display-name">
                <span>Merchant display name</span>
                <small>Shown above the QR code at checkout.</small>
                <input id="merchant-display-name" name="merchant_name" value="{{ old('merchant_name', $paymentSettings?->merchant_name) }}" maxlength="120" placeholder="Naise Coffee">
            </label>
            <label class="payment-field" for="merchant-qr-code">
                <span>Official merchant QR image</span>
                <small>Use a clear PNG, JPG, or WEBP image.</small>
                <input id="merchant-qr-code" name="qr_code" type="file" accept="image/png,image/jpeg,image/webp">
                @error('qr_code')<em class="payment-field-error">{{ $message }}</em>@enderror
            </label>
            <div class="payment-settings-note"><b>Payment confirmation</b><p>A static QR does not notify this site by itself. Orders update automatically only when a connected payment provider sends a verified callback; staff can review submitted receipts in the meantime.</p></div>
            <button class="save-qr-button" type="submit">SAVE QR SETTINGS <span aria-hidden="true">↗</span></button>
            @if(session('payment_settings_saved'))<p class="payment-saved-message" role="status">{{ session('payment_settings_saved') }}</p>@endif
        </form>
    </div>
</section>
