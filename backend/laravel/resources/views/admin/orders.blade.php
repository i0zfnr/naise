<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f8f8f7">
    <title>Payment follow-up · Naise v3</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f8f8f7;color:#20201e;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.shell{max-width:980px;margin:auto;padding:0 24px 55px}.bar{height:64px;border-bottom:1px solid #e8e8e6;display:flex;align-items:center;justify-content:space-between}.bar a{color:#343431;text-decoration:none;font-size:11px}.staff-brand{display:flex;align-items:center;gap:8px;color:#393833;font:10px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.1em}.staff-brand img{width:27px;height:27px;border-radius:6px}.intro{padding:32px 0 17px}.eyebrow{font:10px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.12em;color:#62615d}.intro h1{font-size:26px;letter-spacing:-.03em;margin:7px 0 5px}.intro p{max-width:600px;color:#62615d;font-size:12px;line-height:1.6;margin:0}.notice{background:#fffaf0;border:1px solid #eee2c6;border-radius:8px;padding:12px 14px;color:#5f594d;font-size:11px;line-height:1.65;margin:12px 0}.notice.success{background:#f0faf4;border-color:#d1ebdb;color:#286b47}.order{background:#fff;border:1px solid #e7e7e4;padding:17px;margin:11px 0;border-radius:9px}.top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.top h2{font-size:15px;margin:0 0 5px}.meta{font-size:10px;color:#62615d}.pills{display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:flex-end}.pill{border-radius:5px;background:#f2f2f0;color:#5e5d59;padding:5px 8px;font:9px ui-monospace,SFMono-Regular,Menlo,monospace}.pill.method{background:#f7f6f3;color:#4c4b47}.pill.paid{background:#edf8f1;color:#26734c}.pill.pending{background:#fff8e9;color:#8c6818}.items{padding:12px 0;margin:10px 0;border-block:1px solid #f0f0ee;display:grid;gap:7px;font-size:11px}.item{display:flex;justify-content:space-between;gap:12px}.total{font-weight:650;font-size:12px;display:flex;justify-content:space-between}.proof{display:inline-flex;margin-top:12px;color:#4f46a5;font-size:11px}.proof-missing{margin-top:12px;padding:10px 11px;border-radius:6px;background:#fbf8f0;color:#765b20;font-size:10px}.approve{display:inline-flex;align-items:center;min-height:35px;margin-top:12px;background:#171717;color:#fff;border:1px solid #171717;border-radius:6px;padding:0 12px;font-size:10px;font-weight:600;cursor:pointer}.approve:hover{background:#353532}.cash-confirm{background:#eaae38;color:#191816;border-color:#eaae38}.cash-confirm:hover{background:#d99a1e}.empty{background:#fff;padding:24px;border:1px solid #e7e7e4;border-radius:8px;font-size:12px;color:#62615d}@media(max-width:560px){.shell{padding:0 15px 36px}.intro{padding-top:25px}.intro h1{font-size:23px}.order{padding:14px}.top{gap:8px}.meta{max-width:235px;line-height:1.5}.pills{gap:4px}.pill{padding:5px 6px;font-size:8px}}
    </style>
    <style>
        .staff-brand-mark{position:relative;width:27px;height:27px;flex:none;border:1px solid #26231f;border-radius:50%;display:grid;place-items:center;color:#171715;font:italic 17px Georgia}.staff-brand-mark i{position:absolute;left:3px;bottom:3px;width:3px;height:3px;border-radius:50%;background:#eaae38}
        .customer-contact{display:grid;gap:3px;margin-top:5px;font-size:10px;color:#62615d}.customer-contact b{color:#252522;font-size:11px}.customer-contact a{width:max-content;color:#5140b6;text-decoration:none}.archive-order-form{margin-top:12px}.archive-order-button{min-height:32px;border:1px solid #e5d8d5;border-radius:6px;background:#fff;color:#a03a32;padding:0 10px;font-size:9px;font-weight:650;letter-spacing:.06em;cursor:pointer}.archive-order-button:hover{background:#fff4f2;border-color:#d8b6b1}
    </style>
</head>
<body>
<div class="shell">
   <header class="bar">
       <a href="{{ session('is_admin') ? route('admin.dashboard') : url('/') }}">← {{ session('is_admin') ? 'DASHBOARD' : 'MENU' }}</a>
       <span class="staff-brand"><span class="staff-brand-mark" aria-hidden="true">N<i></i></span>NAISE · STAFF</span>
   </header>
   <main>
       <div class="intro"><span class="eyebrow">PAYMENTS</span><h1>Payment follow-up</h1><p>See orders waiting for payment, review customer QR receipts, and record cash collected at pickup.</p></div>
       <div class="notice">QR payments are confirmed automatically only when a connected provider sends a verified callback. For static QR payments, review the uploaded receipt. Cash orders count as paid after staff confirms collection.</div>
       @if(session('receipt_review_message'))
           <div class="notice success" role="status">{{ session('receipt_review_message') }}</div>
       @endif
       @if($orders->isEmpty())
           <div class="empty">There are no orders waiting for payment follow-up.</div>
       @else
           @foreach($orders as $order)
               <article class="order">
                   <div class="top">
                       <div><h2>Order #{{ $order->id }}</h2><div class="customer-contact"><b>{{ $order->customer_name ?: 'Customer details unavailable' }}</b>@if($order->customer_phone)<a href="tel:{{ $order->customer_phone }}">{{ $order->customer_phone }}</a>@else<span>No phone number provided</span>@endif<span>{{ $order->customer_email ?: 'Guest checkout' }} · {{ \Illuminate\Support\Carbon::parse($order->created_at)->format('d M, g:i A') }}</span></div></div>
                       <div class="pills"><span class="pill method">{{ $order->payment_method === 'duitnow_qr' ? 'DUITNOW QR' : 'CASH' }}</span><span class="pill {{ $order->payment_status === 'paid' ? 'paid' : 'pending' }}">{{ str_replace('_', ' ', strtoupper($order->payment_status)) }}</span></div>
                   </div>
                   <div class="items">
                       @foreach($order->items as $item)
                           <div class="item"><span>{{ $item->quantity }} × {{ $item->product_name }}</span><b>RM {{ number_format($item->unit_price * $item->quantity, 2) }}</b></div>
                       @endforeach
                   </div>
                   <div class="total"><span>Total</span><span>RM {{ number_format($order->total, 2) }}</span></div>
                   @if($order->payment_method === 'cash')
                       <form method="POST" action="{{ route('staff.orders.mark-cash-received', $order->id) }}">
                           @csrf
                           <button class="approve cash-confirm" type="submit">MARK CASH RECEIVED · COMPLETE ORDER</button>
                       </form>
                   @elseif($order->receipt_path)
                       <a class="proof" href="{{ route('staff.orders.receipt', $order->id) }}" target="_blank" rel="noopener">View customer receipt ↗</a>
                       @if($order->payment_status === 'receipt_submitted')
                           <form method="POST" action="{{ route('staff.orders.verify-receipt', $order->id) }}">
                               @csrf
                               <button class="approve" type="submit">VERIFY RECEIPT & APPROVE ORDER</button>
                           </form>
                       @endif
                   @else
                       <div class="proof-missing">Waiting for payment confirmation or customer receipt.</div>
                   @endif
                   <form class="archive-order-form" method="POST" action="{{ route('staff.orders.delete', $order->id) }}" onsubmit="return confirm('Remove order #{{ $order->id }} from the active order lists? Its record and receipt will be kept for review.');">
                       @csrf
                       @method('DELETE')
                       <button class="archive-order-button" type="submit">DELETE ORDER</button>
                   </form>
               </article>
           @endforeach
       @endif
   </main>
</div>
</body>
</html>

