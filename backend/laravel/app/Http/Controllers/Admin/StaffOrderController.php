<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LoyaltyPointsService;
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
                        ->whereIn('orders.payment_status', ['cash_on_pickup', 'paid']);
                });
            })
            ->whereNotIn('orders.status', ['completed', 'picked_up', 'cancelled'])
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
                $order->whatsapp_url = $this->whatsappUrl($order);

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

    public function verifyReceipt(Request $request, int $order, LoyaltyPointsService $loyaltyPoints): RedirectResponse
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

        if ($updated) {
            $loyaltyPoints->awardForPaidOrder($order);
        }

        return back()->with('receipt_review_message', $updated ? 'Receipt verified and order approved.' : 'This order has no pending receipt to verify.');
    }

    public function markCashReceived(int $order, LoyaltyPointsService $loyaltyPoints): RedirectResponse
    {
        $updated = DB::table('orders')
            ->where('id', $order)
            ->whereNull('deleted_at')
            ->where('payment_method', 'cash')
            ->where('payment_status', 'cash_on_pickup')
            ->whereNotIn('status', ['paid', 'completed', 'picked_up', 'cancelled'])
            ->update([
                'payment_status' => 'paid',
                'status' => 'paid',
                'paid_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated) {
            $loyaltyPoints->awardForPaidOrder($order);
        }

        return back()->with('receipt_review_message', $updated ? 'Cash received. Order payment confirmed and points credited.' : 'This cash order is no longer awaiting payment.');
    }

    public function markReady(int $order): RedirectResponse
    {
        $updated = DB::table('orders')
            ->where('id', $order)
            ->whereNull('deleted_at')
            ->where('payment_status', 'paid')
            ->whereNotIn('status', ['ready', 'completed', 'picked_up', 'cancelled'])
            ->update(['status' => 'ready', 'updated_at' => now()]);

        return back()->with('receipt_review_message', $updated ? 'Order marked ready. Use the WhatsApp button to open a message to the customer.' : 'Only a paid order can be marked ready.');
    }

    public function destroy(int $order): RedirectResponse
    {
        $archived = DB::table('orders')
            ->where('id', $order)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        return back()->with('receipt_review_message', $archived ? 'Order removed from active lists. The order record and receipt are retained.' : 'This order is already archived or was not found.');
    }

    private function whatsappUrl(object $order): ?string
    {
        $phone = preg_replace('/\D+/', '', (string) $order->customer_phone) ?? '';

        if (strlen($phone) < 9) {
            return null;
        }

        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        } elseif (str_starts_with($phone, '0')) {
            $phone = '60'.ltrim($phone, '0');
        } elseif (! str_starts_with($phone, '60')) {
            $phone = '60'.$phone;
        }

        $message = 'Hi '.$order->customer_name.', your Naise order #'.$order->id.' is ready for pickup. See you soon!';

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($message);
    }
}
