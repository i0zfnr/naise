<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('is_admin', false)) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function dashboard(): View
    {
        $menu = json_decode(file_get_contents(resource_path('menu.json')), true, flags: JSON_THROW_ON_ERROR);
        $businessTimezone = new \DateTimeZone('Asia/Kuala_Lumpur');
        $utcTimezone = new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable('today', $businessTimezone);
        $tomorrow = $today->modify('+1 day');
        $todayStartUtc = $today->setTimezone($utcTimezone)->format('Y-m-d H:i:s');
        $tomorrowStartUtc = $tomorrow->setTimezone($utcTimezone)->format('Y-m-d H:i:s');
        $weekStartUtc = $today->modify('-6 days')->setTimezone($utcTimezone)->format('Y-m-d H:i:s');

        $dailySalesRows = $this->paidSalesQuery()
            ->where('paid_at', '>=', $weekStartUtc)
            ->where('paid_at', '<', $tomorrowStartUtc)
            ->selectRaw('DATE(DATE_ADD(paid_at, INTERVAL 8 HOUR)) as sales_day, COUNT(*) as paid_order_count, SUM(total) as sales_total')
            ->groupByRaw('DATE(DATE_ADD(paid_at, INTERVAL 8 HOUR))')
            ->get()
            ->keyBy('sales_day');

        $dailySales = collect(range(6, 0))->map(function (int $daysAgo) use ($today, $dailySalesRows): array {
            $day = $today->modify("-{$daysAgo} days");
            $sales = $dailySalesRows->get($day->format('Y-m-d'));

            return [
                'date' => $day->format('Y-m-d'),
                'label' => $daysAgo === 0 ? 'Today' : $day->format('D'),
                'short_date' => $day->format('d M'),
                'order_count' => (int) ($sales?->paid_order_count ?? 0),
                'sales_total' => (float) ($sales?->sales_total ?? 0),
            ];
        });

        $todayPaidOrders = $this->paidSalesQuery()
            ->where('paid_at', '>=', $todayStartUtc)
            ->where('paid_at', '<', $tomorrowStartUtc);
        $unpaidOrders = DB::table('orders')
            ->whereNotIn('status', ['paid', 'completed', 'picked_up', 'cancelled'])
            ->where('payment_status', '!=', 'paid');

        return view('admin.dashboard', [
            'menuCount' => count($menu),
            'categoryCounts' => collect($menu)->countBy('category')->sortKeys(),
            'orderCount' => DB::table('orders')->count(),
            'customerCount' => DB::table('users')->count(),
            'totalSales' => $this->paidSalesQuery()->sum('total'),
            'todaySales' => (float) (clone $todayPaidOrders)->sum('total'),
            'todayPaidOrderCount' => (clone $todayPaidOrders)->count(),
            'todayOrderCount' => DB::table('orders')->where('created_at', '>=', $todayStartUtc)->where('created_at', '<', $tomorrowStartUtc)->count(),
            'unpaidOrderCount' => (clone $unpaidOrders)->count(),
            'unpaidOrderTotal' => (float) (clone $unpaidOrders)->sum('total'),
            'dailySales' => $dailySales,
            'dailySalesMax' => max(1, (float) $dailySales->max('sales_total')),
            'pointsLiability' => DB::table('users')->sum('points'),
            'paymentSettings' => DB::table('payment_settings')->first(),
            'recentOrders' => DB::table('orders')
                ->leftJoin('users', 'orders.user_id', '=', 'users.id')
                ->orderByDesc('orders.created_at')
                ->limit(6)
                ->select('orders.id', 'orders.status', 'orders.payment_method', 'orders.total', 'orders.created_at', 'users.name as customer_name', 'users.email as customer_email')
                ->get(),
        ]);
    }

    public function overview(): JsonResponse
    {
        $orders = DB::table('orders');
        $paidOrders = $this->paidSalesQuery();

        return response()->json([
            'menu_items' => count(json_decode(file_get_contents(resource_path('menu.json')), true, flags: JSON_THROW_ON_ERROR)),
            'customers' => DB::table('users')->count(),
            'orders' => $orders->count(),
            'paid_orders' => $paidOrders->count(),
            'sales_total' => (float) $paidOrders->sum('total'),
            'store_mode' => (bool) session('store_mode', false),
        ]);
    }

    private function paidSalesQuery(): Builder
    {
        return DB::table('orders')
            ->where('status', '!=', 'cancelled')
            ->where('payment_status', 'paid')
            ->whereNotNull('paid_at');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        $adminEmail = strtolower((string) config('services.naise.admin_email'));
        $adminPasswordHash = (string) config('services.naise.admin_password_hash');
        $emailIsValid = hash_equals($adminEmail, strtolower($credentials['email']));
        $passwordIsValid = $adminPasswordHash !== '' && Hash::check($credentials['password'], $adminPasswordHash);

        if (! $emailIsValid || ! $passwordIsValid) {
            return back()->withErrors(['email' => 'The administrator credentials were not recognized.'])->onlyInput('email');
        }

        $request->session()->regenerate();
        $request->session()->put('is_admin', true);
        $request->session()->put('staff_last_activity', now()->timestamp);

        return redirect()->route('admin.dashboard');
    }

    public function enterStoreMode(Request $request): RedirectResponse
    {
        $validated = $request->validate(['passcode' => ['required', 'string', 'max:128']]);
        $passcodeHash = (string) config('services.naise.store_passcode_hash');

        if ($passcodeHash === '' || ! Hash::check($validated['passcode'], $passcodeHash)) {
            return back()->withErrors(['passcode' => 'The store passcode was not recognized.']);
        }

        $request->session()->put('store_mode', true);
        $request->session()->put('staff_last_activity', now()->timestamp);
        $request->session()->regenerate();

        return back()->with('store_mode_enabled', true);
    }

    public function exitStoreMode(Request $request): RedirectResponse
    {
        $request->session()->forget('store_mode');
        $request->session()->regenerate();

        return back();
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
