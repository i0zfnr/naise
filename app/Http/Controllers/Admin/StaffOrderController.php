<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffOrderController extends Controller
{
    public function index(): View
    {
        $orders = DB::table('orders')
            ->leftJoin('users', 'orders.user_id', '=', 'users.id')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $qrOrders): void {
                    $qrOrders->where('orders.payment_method', 'duitnow_qr')
                        ->whereIn('orders.payment_status', ['pending', 'receipt_submitted', 'paid']);
                })->orWhere(function (Builder $cashOrders): void {
                    $cashOrders->where('orders.payment_method', 'cash')
                        ->where('orders.payment_status', 'cash_on_pickup')
                        ->whereNotIn('orders.status', ['paid', 'completed', 'picked_up', 'cancelled']);
                });
            })
            ->orderByDesc('orders.created_at')
            ->select('orders.*', 'users.name as customer_name', 'users.email as customer_email')
            ->get()
            ->map(function (object $order): object {
                $order->items = DB::table('order_items')->where('order_id', $order->id)->get();

                return $order;
            });

        return view('admin.orders', ['orders' => $orders]);
    }

    public function receipt(int $order): StreamedResponse
    {
        $receiptPath = DB::table('orders')->where('id', $order)->value('receipt_path');
        abort_unless($receiptPath, 404);

        return Storage::disk('local')->response($receiptPath);
    }

    public function verifyReceipt(Request $request, int $order): RedirectResponse
    {
        $updated = DB::table('orders')
            ->where('id', $order)
            ->where('payment_method', 'duitnow_qr')
            ->where('payment_status', 'receipt_submitted')
            ->whereNotNull('receipt_path')
            ->update([
                'payment_status' => 'paid',
                'status' => 'paid',
                'paid_at' => now(),
                'updated_at' => now(),
            ]);

        return back()->with('receipt_review_message', $updated ? 'Receipt verified and order approved.' : 'This order has no pending receipt to verify.');
    }

    public function markCashReceived(int $order): RedirectResponse
    {
        $updated = DB::table('orders')
            ->where('id', $order)
            ->where('payment_method', 'cash')
            ->where('payment_status', 'cash_on_pickup')
            ->whereNotIn('status', ['paid', 'completed', 'picked_up', 'cancelled'])
            ->update([
                'payment_status' => 'paid',
                'status' => 'completed',
                'paid_at' => now(),
                'updated_at' => now(),
            ]);

        return back()->with('receipt_review_message', $updated ? 'Cash received and order completed.' : 'This cash order is no longer awaiting payment.');
    }
}
