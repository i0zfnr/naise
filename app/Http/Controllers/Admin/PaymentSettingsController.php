<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PaymentSettingsController extends Controller
{
    public function updateQr(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'merchant_name' => ['nullable', 'string', 'max:120'],
            'qr_code' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $settings = DB::table('payment_settings')->first();
        $path = $settings?->qr_code_path;

        if ($request->hasFile('qr_code')) {
            $path = $request->file('qr_code')->store('payment-qr', 'public');

            if ($settings?->qr_code_path) {
                Storage::disk('public')->delete($settings->qr_code_path);
            }
        }

        DB::table('payment_settings')->updateOrInsert(
            ['id' => 1],
            [
                'merchant_name' => $validated['merchant_name'] ?? null,
                'qr_code_path' => $path,
                'created_at' => $settings?->created_at ?? now(),
                'updated_at' => now(),
            ],
        );

        return back()->with('payment_settings_saved', 'QR payment settings saved.');
    }
}
