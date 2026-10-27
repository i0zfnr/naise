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
            ->whereNull('orders.deleted_at')
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
            ->select(
                'orders.*',
                DB::raw('COALESCE(orders.customer_name, users.name) as customer_name'),
                DB::raw('COALESCE(orders.customer_phone, users.whatsapp_number) as customer_phone'),
                'users.email as customer_email',
            )
            ->get()
            ->map(function (object $order): object {
                $order->items = DB::table('order_items')->where('order_id', $order->id)->get();

                return $order;
            });

        return view('admin.orders', ['orders' => $orders]);
    }

    public function receipt(int $order): StreamedResponse
    {
        $receiptPath = DB::table('orders')->whereNull('deleted_at')->where('id', $order)->value('receipt_path');
        abort_unless($receiptPath, 404);

        return Storage::disk('local')->response($receiptPath);
    }

    public function verifyReceipt(Request $request, int $order): RedirectResponse
    {
        $updated = DB::table('orders')
            ->where('id', $order)
            ->whereNull('deleted_at')
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
            ->whereNull('deleted_at')
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

    public function destroy(int $order): RedirectResponse
    {
        $archived = DB::table('orders')
            ->where('id', $order)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        return back()->with('receipt_review_message', $archived ? 'Order removed from active lists. The order record and receipt are retained.' : 'This order is already archived or was not found.');
    }
}
