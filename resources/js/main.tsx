import React, { useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Search, Plus, Coffee, UserRound, ArrowLeft, X, Leaf, Settings, Pencil, LockKeyhole, Bell, ShieldCheck, Camera, ChevronRight, QrCode, Wallet } from 'lucide-react';
import { Button } from './components/ui/button';
import { Input } from './components/ui/input';
import rawMenu from '../menu.json';
import './style.css';

if ('serviceWorker' in navigator) {
 window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
}

type Item={name:string;price:number;category:string;bestSeller:boolean;image:string};
const menu=rawMenu as Item[];
const categories=['Best Seller','Coffee','Matcha','Non Coffee','Yoghurt','Refresher'];
const money=(price:number)=>`RM ${price.toFixed(2)}`;

function OrderCard({order,onView}:{order:any;onView:()=>void}){
 const createdAt=new Date(order.created_at); const elapsed=Math.max(0,Date.now()-createdAt.getTime()); const minutes=Math.floor(elapsed/60000); const age=minutes<60?`${minutes} mins ago`:minutes<1440?`${Math.floor(minutes/60)} hour${Math.floor(minutes/60)===1?'':'s'} ago`:`${Math.floor(minutes/1440)} days ago`; const isPending=['received','pending','awaiting_payment','awaiting_verification'].includes(order.status); const isComplete=['completed','picked_up'].includes(order.status); const statusLabel=isPending?'Pending':isComplete?'Completed':order.status==='ready'?'Ready for pickup':order.status==='paid'?'Preparing':'In progress';
 return <article className="profile-order-card"><div className="order-card-top"><div><h4>#{order.id}</h4><small>{age}</small></div><div className="order-card-status"><span className={`order-status ${isPending?'pending':isComplete?'completed':'preparing'}`}><i/>{statusLabel}</span><small>{order.payment_method === "duitnow_qr" ? "DuitNow QR" : "Cash"}</small></div></div><div className="order-card-items">{order.items.map((item:any,index:number)=><div className="order-card-item" key={`${order.id}-${index}`}><span>{item.quantity}</span><b>{item.name}</b><strong>{money(item.unit_price*item.quantity)}</strong></div>)}</div>{Number(order.discount_total)>0&&<div className="order-card-discount"><div><span>Subtotal</span><b>{money(order.subtotal)}</b></div><div><span>Voucher · Free Drink</span><b>−{money(order.discount_total)}</b></div></div>}<div className="order-card-total"><b>Total&nbsp; {money(order.total)}</b><button onClick={onView}>View <ChevronRight size={16}/></button></div></article>
}

type CheckoutItem={item:Item;quantity:number};

function CheckoutScreen({items,total,onBack,onOrderPlaced,onSignInRequired}:{items:CheckoutItem[];total:number;onBack:()=>void;onOrderPlaced:()=>void;onSignInRequired:()=>void}){
 const [method,setMethod]=useState<'cash'|'duitnow_qr'>('cash');
 const [qrSettings,setQrSettings]=useState<any>(null);
 const [placed,setPlaced]=useState<any>(null);
 const [busy,setBusy]=useState(false);
 const [receipt,setReceipt]=useState<File|null>(null);
 const [receiptBusy,setReceiptBusy]=useState(false);
 const [message,setMessage]=useState('');
 const [error,setError]=useState('');
 const [customerName,setCustomerName]=useState('');
 const [customerPhone,setCustomerPhone]=useState('');
 const [googleCustomer,setGoogleCustomer]=useState(false);
 const csrf=()=>document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content||'';

 useEffect(()=>{
  fetch('/api/payment-settings',{headers:{Accept:'application/json'}}).then(r=>r.json()).then(setQrSettings).catch(()=>setQrSettings({configured:false}));
  fetch('/api/profile',{headers:{Accept:'application/json'}}).then(r=>r.json()).then((result)=>{
   if(result.authenticated){setGoogleCustomer(true);
    setCustomerName(current=>current||result.user?.name||'');
    setCustomerPhone(current=>current||result.user?.whatsapp_number||'');
   }
  }).catch(()=>{});
 },[]);

 useEffect(()=>{
  if(!placed?.id||method!=='duitnow_qr'||placed.payment_status==='paid')return;
  let alive=true;
  let timer=0;
  const poll=async()=>{
   try{
    const response=await fetch(`/api/orders/${placed.id}/payment-status`,{headers:{Accept:'application/json'}});
    if(!response.ok)return;
    const status=await response.json();
    if(!alive)return;
    if(status.payment_status==='paid'){
     window.clearInterval(timer);
     setPlaced((current:any)=>({...current,...status}));
     setMessage('Payment received. Keep your receipt ready for the store worker.');
    }
   }catch{}
  };
  poll();
  timer=window.setInterval(poll,5000);
  return()=>{alive=false;window.clearInterval(timer)};
 },[placed?.id,method,placed?.payment_status]);

 const placeOrder=async()=>{
  setError('');
  setMessage('');
  if(!customerName.trim()||!customerPhone.trim()){
   setError('Enter your name and phone number so the store can identify your order.');
   return;
  }
  setBusy(true);
  try{
   const response=await fetch('/api/orders',{
    method:'POST',
    headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf()},
    body:JSON.stringify({
     payment_method:method,
     customer_name:customerName.trim(),
     customer_phone:customerPhone.trim(),
     items:items.map(({item,quantity})=>({name:item.name,quantity})),
    }),
   });
   const result=await response.json();
   if(response.status===401){setError(result.message||'Sign in before choosing QR payment.');return}
   if(!response.ok)throw new Error(result.errors?.customer_name?.[0]||result.errors?.customer_phone?.[0]||result.message||'Could not place the order.');
   setPlaced({...result,id:result.order_id,items,total:result.total});
   onOrderPlaced();
   if(method==='cash')setMessage(`Order #${result.order_id} placed. Pay cash when you pick it up.`);
  }catch(problem){setError(problem instanceof Error?problem.message:'Could not reach the server.')}finally{setBusy(false)}
 };

 const submitReceipt=async(event:React.FormEvent)=>{
  event.preventDefault();
  if(!receipt||!placed?.id)return;
  setReceiptBusy(true);setError('');setMessage('');
  const form=new FormData();form.append('receipt',receipt);
  try{
   const response=await fetch(`/api/orders/${placed.id}/receipt`,{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf()},body:form});
   const result=await response.json();
   if(!response.ok)throw new Error(result.message||result.errors?.receipt?.[0]||'Could not upload receipt.');
   setPlaced((current:any)=>({...current,receipt_submitted:true,payment_status:result.payment_status}));
   setMessage(result.message);
  }catch(problem){setError(problem instanceof Error?problem.message:'Could not upload receipt.')}finally{setReceiptBusy(false)}
 };

 return <section className="checkout-page">
  <div className="checkout-header"><button onClick={onBack} aria-label="Back"><ArrowLeft/></button><h1>{placed?.payment_method==='duitnow_qr'?'QR PAYMENT':placed?'ORDER PLACED':'CHECKOUT'}</h1></div>
  {placed?.payment_method==='duitnow_qr'?<div className="qr-payment-view">
   <p className="checkout-intro">Scan the merchant QR with your banking or e-wallet app.</p>
   <div className="amount-due"><small>AMOUNT DUE</small><strong>{money(placed.total)}</strong><span>Malaysian Ringgit (MYR)</span></div>
   {qrSettings?.qr_code_url?<div className="merchant-qr"><span>SCAN TO PAY · {qrSettings.merchant_name||'NAISE COFFEE'}</span><img src={qrSettings.qr_code_url} alt="Naise merchant DuitNow QR code"/><small>Order reference #{placed.order_id}</small><a className="download-qr" href={qrSettings.qr_code_url} download>Download QR code</a></div>:<div className="qr-not-configured">Merchant QR is not configured yet. Please contact the store.</div>}
   <div className={`payment-waiting ${placed.payment_status==='paid'?'payment-confirmed':''}`}><i/>{placed.payment_status==='paid'?'Payment confirmed':placed.receipt_submitted?'Receipt submitted · waiting for staff review':'Payment pending · upload your receipt for staff review'}</div>
   <p className="payment-proof-note">A static QR does not send payment notifications to this website. The order stays pending until a connected provider confirms it or store staff verifies your receipt.</p>
   {!placed.receipt_submitted&&<form className="receipt-upload-form" onSubmit={submitReceipt}><label htmlFor="payment-receipt">Upload payment receipt for the store worker</label><Input id="payment-receipt" type="file" accept="image/png,image/jpeg,image/webp" onChange={event=>setReceipt(event.target.files?.[0]||null)} required/><Button variant="primary" disabled={!receipt||receiptBusy}>{receiptBusy?'UPLOADING…':'SEND RECEIPT'}</Button></form>}
   {placed.receipt_submitted&&<div className="receipt-submitted-note"><ShieldCheck size={16}/><span>Receipt sent. Keep the original receipt available for pickup.</span></div>}
   {message&&<p className="checkout-feedback" role="status">{message}</p>}{error&&<p className="checkout-error" role="alert">{error}{error.toLowerCase().includes('sign in')&&<button onClick={onSignInRequired}>SIGN IN</button>}</p>}
  </div>:placed?<div className="order-placed-card"><span className="placed-check">✓</span><h2>Order #{placed.id}</h2><p>{message}</p><p className="payment-proof-note">Your order is with the store. Pay cash at pickup.</p><Button className="checkout-place-button" onClick={onBack}>BACK TO MENU</Button></div>:<>
   <p className="checkout-label">CUSTOMER DETAILS</p>
   <div className="checkout-customer-info">
    <label htmlFor="checkout-customer-name">Name</label>
    <Input id="checkout-customer-name" name="customer_name" autoComplete="name" maxLength={120} readOnly={googleCustomer} value={customerName} onChange={event=>setCustomerName(event.target.value)} placeholder="Your name" required/>
    <label htmlFor="checkout-customer-phone">Phone / WhatsApp</label>
    <Input id="checkout-customer-phone" name="customer_phone" type="tel" inputMode="tel" autoComplete="tel" maxLength={30} readOnly={googleCustomer} value={customerPhone} onChange={event=>setCustomerPhone(event.target.value)} placeholder="e.g. +60 12-345 6789" required/>
    <small>The store uses these details to identify and update you about this order.</small>
   </div>
   <p className="checkout-label payment-method-label">PAYMENT METHOD</p>
   <div className="payment-methods">
    <Button variant="ghost" type="button" aria-pressed={method==='cash'} className={`payment-method ${method==='cash'?'active':''}`} onClick={()=>setMethod('cash')}><span className="payment-method-icon"><Wallet size={19}/></span>{method==='cash'&&<b className="method-check">✓</b>}<strong>Cash</strong><small>Pay at the counter on pickup</small></Button>
    <Button variant="ghost" type="button" aria-pressed={method==='duitnow_qr'} disabled={qrSettings?.configured===false} className={`payment-method ${method==='duitnow_qr'?'active':''} ${qrSettings?.configured===false?'unavailable':''}`} onClick={()=>{if(qrSettings?.configured)setMethod('duitnow_qr')}}><span className="payment-method-icon"><QrCode size={19}/></span>{method==='duitnow_qr'&&<b className="method-check">✓</b>}<strong>DuitNow QR</strong><small>{qrSettings?.configured===false?'QR not set up yet':'Scan with a banking or e-wallet app'}</small></Button>
   </div>
   <p className="checkout-label order-summary-label">ORDER SUMMARY</p>
   <div className="checkout-summary">{items.map(({item,quantity})=><div className="checkout-item" key={item.name}>{item.image?<img src={item.image} alt=""/>:<span className="checkout-qty">{quantity}</span>}<div><b>{item.name}</b><small>Regular</small></div><strong>{money(item.price*quantity)}</strong></div>)}</div>
   <div className="checkout-totals"><div><span>Subtotal</span><b>{money(total)}</b></div><div><span>Delivery/Pick-up</span><b>{money(0)}</b></div><div className="grand-total"><strong>Total</strong><strong>{money(total)}</strong></div></div>
   <p className="checkout-store-note"><ShieldCheck size={14}/> Your order goes straight to the store once you place it.</p>
   {error&&<p className="checkout-error" role="alert">{error}{error.toLowerCase().includes('sign in')&&<button onClick={onSignInRequired}>SIGN IN</button>}</p>}
   <Button className="checkout-place-button" onClick={placeOrder} disabled={busy||!items.length}>{busy?'PLACING ORDER…':'PLACE ORDER'}<b>{money(total)}</b></Button>
  </>}
 </section>
}
function EmailSignIn({refreshProfile}:{refreshProfile:()=>Promise<void>}){
 const [purpose,setPurpose]=useState<'signin'|'signup'>('signin');
 const [method,setMethod]=useState<'code'|'password'>('code');
 const [stage,setStage]=useState<'details'|'code'|'complete'>('details');
 const [email,setEmail]=useState(''),[code,setCode]=useState(''),[challenge,setChallenge]=useState('');
 const [name,setName]=useState(''),[phone,setPhone]=useState(''),[password,setPassword]=useState(''),[confirmation,setConfirmation]=useState('');
 const [busy,setBusy]=useState(false),[error,setError]=useState(''),[remaining,setRemaining]=useState(0);
 useEffect(()=>{if(remaining<=0)return;const timer=window.setTimeout(()=>setRemaining(remaining-1),1000);return()=>clearTimeout(timer)},[remaining]);
 function reset(nextPurpose=purpose,nextMethod=method){setPurpose(nextPurpose);setMethod(nextMethod);setStage('details');setCode('');setChallenge('');setPassword('');setConfirmation('');setError('')}
 async function post(path:string,body:Record<string,unknown>){
  const response=await fetch(path,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content||''},body:JSON.stringify(body)});
  const result=await response.json();
  if(!response.ok)throw new Error(response.status===429?'Too many attempts. Please wait a minute and try again.':Object.values(result.errors||{}).flat()[0] as string||result.message||'Could not continue. Please try again.');
  return result;
 }
 async function send(){setBusy(true);setError('');try{const result=await post('/api/auth/email/code',{email,purpose});setChallenge(result.challenge);setRemaining(result.resend_after);setCode('');setStage('code')}catch(problem){setError(problem instanceof Error?problem.message:'Could not connect. Please try again.')}finally{setBusy(false)}}
 async function submit(event:React.FormEvent){
  event.preventDefault();
  if(stage==='details'&&purpose==='signup'&&method==='password'&&password!==confirmation){setError('Passwords do not match. Please enter them again.');return}
  if(stage==='details'&&(purpose==='signup'||method==='code')){await send();return}
  setBusy(true);setError('');
  try{
   if(stage==='code'){
    const result=await post('/api/auth/email/verify',{challenge,code});
    if(result.needs_signup){setStage('complete');setCode('');return}
   }else if(stage==='complete'){
    await post('/api/auth/signup',{name,whatsapp_number:phone,password:password||null,password_confirmation:confirmation});
   }else{
    await post('/api/auth/password',{email,password});
   }
   await refreshProfile();
  }catch(problem){setError(problem instanceof Error?problem.message:'Could not connect. Please try again.')}finally{setBusy(false)}
 }
 const signup=purpose==='signup';
 const profileFields=<><label htmlFor="signup-name">YOUR NAME</label><Input id="signup-name" autoComplete="name" value={name} onChange={e=>setName(e.target.value)} maxLength={120} required placeholder="What should we call you?"/><label htmlFor="signup-phone">WHATSAPP NUMBER</label><Input id="signup-phone" type="tel" autoComplete="tel" value={phone} onChange={e=>setPhone(e.target.value)} maxLength={30} required placeholder="e.g. +60 12-345 6789"/></>;
 const passwordFields=<><label htmlFor="account-password">{signup?'PASSWORD (AT LEAST 8 CHARACTERS)':'PASSWORD'}</label><Input id="account-password" type="password" autoComplete={signup?'new-password':'current-password'} value={password} onChange={e=>setPassword(e.target.value)} minLength={signup?8:undefined} maxLength={128} required/>{signup&&<><label htmlFor="account-password-confirmation">CONFIRM PASSWORD</label><Input id="account-password-confirmation" type="password" autoComplete="new-password" value={confirmation} onChange={e=>setConfirmation(e.target.value)} minLength={8} maxLength={128} required/></>}</>;
 return <section className="email-signin profile-page">
  <div className="profile-title">{stage==='code'?'CHECK YOUR EMAIL':stage==='complete'?'COMPLETE SIGN-UP':signup?'SIGN UP':'SIGN IN'}</div>
  <div className="profile-orb"><Coffee size={34}/></div><p className="eyebrow">GOOD COFFEE, GOOD DAYS</p>
  <h1>{stage==='code'?<>Your code,<br/><em>your coffee.</em></>:signup?<>Join the<br/><em>Naise family.</em></>:<>Welcome<br/><em>back to Naise.</em></>}</h1>
  {stage==='details'&&<><div className="auth-tabs" aria-label="Account access"><button disabled={busy} aria-pressed={!signup} onClick={()=>reset('signin')}>Sign in</button><button disabled={busy} aria-pressed={signup} onClick={()=>reset('signup')}>Sign up</button></div><div className="auth-methods" aria-label="Authentication method"><button disabled={busy} aria-pressed={method==='code'} onClick={()=>reset(purpose,'code')}>Email code</button><button disabled={busy} aria-pressed={method==='password'} onClick={()=>reset(purpose,'password')}>{signup?'Email & password':'Password'}</button></div></>}
  <p className="profile-copy">{stage==='code'?<>We sent a six-digit code to <b>{email}</b>. Check your inbox or spam folder. It expires in 10 minutes.</>:stage==='complete'?<>Your email <b>{email}</b> is verified. Confirm your details to create your account.</>:signup?'Use Gmail or any email address. We will verify your email before creating your account.':method==='code'?'Use your registered Gmail or any email address. Verify a code to sign in.':'Sign in with your registered email and password.'}</p>
  <form className="email-signin-form" onSubmit={submit}>
   {stage==='code'?<><label htmlFor="email-login-code">SIX-DIGIT CODE</label><Input key="code" id="email-login-code" className="email-code-input" inputMode="numeric" autoComplete="one-time-code" maxLength={6} pattern="[0-9]{6}" value={code} onChange={e=>setCode(e.target.value.replace(/\D/g,'').slice(0,6))} placeholder="000000" required autoFocus/></>:stage==='complete'?<>{profileFields}{method==='password'&&passwordFields}</>:<><label htmlFor="email-login-address">EMAIL ADDRESS</label><Input key="email" id="email-login-address" type="email" autoComplete="email" maxLength={254} value={email} onChange={e=>setEmail(e.target.value)} placeholder="you@gmail.com" required/>{signup&&method==='password'&&profileFields}{method==='password'&&passwordFields}</>}
   {error&&<p className="edit-error" role="alert">{error}</p>}
   <Button type="submit" className="save-profile-button" disabled={busy||(stage==='code'&&code.length!==6)}>{busy?'PLEASE WAIT…':stage==='code'?'VERIFY CODE':stage==='complete'?'CREATE ACCOUNT':signup||method==='code'?'EMAIL ME A CODE':'SIGN IN'}<ChevronRight size={15}/></Button>
  </form>
  {stage==='code'&&<div className="email-code-actions"><button disabled={busy||remaining>0} onClick={send}>{remaining>0?`Resend code in ${remaining}s`:'Resend code'}</button><button disabled={busy} onClick={()=>reset()}>Use a different email</button></div>}
  {stage==='complete'&&<div className="email-code-actions"><button disabled={busy} onClick={()=>reset()}>Start again</button></div>}
  <p className="email-signin-note">{signup?'Your WhatsApp number lets Naise staff send your pickup update.':<>New to Naise? <button className="auth-text-link" disabled={busy} onClick={()=>reset('signup')}>Create an account</button><br/>Forgot your password? Use the email code option.</>}</p>
  {document.querySelector<HTMLMetaElement>('meta[name="demo-login-enabled"]')?.content==='true'&&<a className="demo-login-link" href="/demo/login">Preview with demo customer</a>}
 </section>
}

function CompleteProfile({user,refreshProfile,onSignOut,googleConnected=false}:{user:any;refreshProfile:()=>Promise<void>;onSignOut:()=>void;googleConnected?:boolean}){
 const [name,setName]=useState(user.name||'');const [phone,setPhone]=useState(user.whatsapp_number||'');const [busy,setBusy]=useState(false);const [error,setError]=useState('');
 async function complete(e:React.FormEvent){e.preventDefault();setBusy(true);setError('');try{const response=await fetch('/api/profile',{method:'PUT',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content||''},body:JSON.stringify({name:name.trim(),whatsapp_number:phone})});const result=await response.json();if(!response.ok)throw new Error(result.errors?.name?.[0]||result.errors?.whatsapp_number?.[0]||result.message||'Could not save your details.');await refreshProfile()}catch(problem){setError(problem instanceof Error?problem.message:'Could not connect. Please try again.')}finally{setBusy(false)}}
 return <section className="complete-profile"><div className="profile-title">ONE LAST THING</div><div className="onboarding-avatar">{user.avatar_url?<img src={user.avatar_url} alt=""/>:<UserRound size={28}/>}<span><ShieldCheck size={16}/></span></div><p className="eyebrow">{googleConnected?'SIGNED IN WITH GOOGLE':'EMAIL VERIFIED'}</p><h1>Hi, {user.name.split(' ')[0]||'there'}.</h1><p className="onboarding-intro">Where should we send your pickup update?</p><div className="google-identity">{user.name&&<b>{user.name}</b>}<span>{user.email}</span><small>{googleConnected?'Your name and email are saved from Google.':'Your email is verified and saved to your account.'}</small></div><form onSubmit={complete}>{!googleConnected&&<><label htmlFor="onboarding-name">YOUR NAME</label><Input id="onboarding-name" autoComplete="name" maxLength={120} value={name} onChange={e=>setName(e.target.value)} placeholder="What should we call you?" required/></>}<label htmlFor="onboarding-phone">WHATSAPP NUMBER</label><Input id="onboarding-phone" type="tel" inputMode="tel" autoComplete="tel" value={phone} onChange={e=>setPhone(e.target.value)} placeholder="e.g. +60 12-345 6789" maxLength={30} required autoFocus/><p className="onboarding-help">Use a number with WhatsApp. Naise staff will message you when your order is ready for pickup.</p>{error&&<p className="edit-error" role="alert">{error}</p>}<Button type="submit" className="save-profile-button" disabled={busy}>{busy?'SAVING…':'SAVE & CONTINUE'} <ChevronRight size={16}/></Button></form><button className="onboarding-signout" onClick={onSignOut}>Use a different email</button></section>
}

function ProfileScreen({data,refreshProfile,onSignOut}:{data:any;refreshProfile:()=>Promise<void>;onSignOut:()=>void}){
 const [page,setPage]=useState<'profile'|'orders'|'order'|'settings'|'edit'>('profile'); const [returnPage,setReturnPage]=useState<'profile'|'orders'>('profile'); const [allOrders,setAllOrders]=useState<any[]>([]); const [selectedOrder,setSelectedOrder]=useState<any>(null); const [notice,setNotice]=useState(''); const [saving,setSaving]=useState(false); const [formError,setFormError]=useState(''); const [displayName,setDisplayName]=useState(data?.user?.name||''); const [whatsapp,setWhatsapp]=useState(data?.user?.whatsapp_number||''); const [photo,setPhoto]=useState<File|null>(null); const [photoPreview,setPhotoPreview]=useState('');

 useEffect(()=>{setDisplayName(data?.user?.name||'');setWhatsapp(data?.user?.whatsapp_number||'')},[data?.user?.name,data?.user?.whatsapp_number]);
 const back=()=>{if(!data?.user?.whatsapp_number){setFormError('Add your phone or WhatsApp number to continue.');return}setPage('profile');setNotice('');window.scrollTo({top:0,behavior:'smooth'})};
 const showAllOrders=async()=>{const response=await fetch('/api/profile?all_orders=1',{headers:{Accept:'application/json'}});const result=await response.json();setAllOrders(result.recent_orders||[]);setPage('orders');window.scrollTo({top:0,behavior:'smooth'})};
 const editPhoto=(event:React.ChangeEvent<HTMLInputElement>)=>{const file=event.target.files?.[0];if(file){setPhoto(file);setPhotoPreview(URL.createObjectURL(file))}};
 const saveProfile=async(event:React.FormEvent)=>{event.preventDefault();setSaving(true);setFormError('');const form=new FormData();form.append('_method','PUT');form.append('name',displayName);form.append('whatsapp_number',whatsapp);if(photo)form.append('avatar',photo);try{const response=await fetch('/api/profile',{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content||''},body:form});const result=await response.json();if(!response.ok){setFormError(result.errors?.name?.[0]||result.errors?.whatsapp_number?.[0]||result.errors?.avatar?.[0]||result.message||'Could not save your profile.');return}await refreshProfile();setPhoto(null);setPhotoPreview('');setNotice('Your profile has been updated.');setPage('profile');window.scrollTo({top:0,behavior:'smooth'})}catch{setFormError('Could not reach the server. Try again.')}finally{setSaving(false)}};
 const redeemPoints=async()=>{setNotice('');const response=await fetch('/api/vouchers/redeem',{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content||''}});const result=await response.json();if(response.ok){await refreshProfile();setNotice('Free drink voucher added to My Vouchers.')}else{setNotice(result.message||'Could not redeem points.')}};
 if(!data?.authenticated)return data?<EmailSignIn refreshProfile={refreshProfile}/>:<section className="profile-page"><p className="profile-loading">Loading your profile…</p></section>;
 if(data.needs_profile||data.needs_phone||data.needs_name)return <CompleteProfile googleConnected={data.google_connected} user={data.user} refreshProfile={refreshProfile} onSignOut={onSignOut}/>;
 const user=data.user; const totalPoints=Number(user.total_points??user.points); const redeemedPoints=Number(user.redeemed_points||0); const availablePoints=Number(user.available_points??user.points); const pointsCost=Number(data.points_per_free_drink||100);
 return <section className="profile-page">
  {page==='profile'&&<><div className="profile-title">PROFILE</div><div className="profile-user"><img src={user.avatar_url||'/icons/naise-512.png'} alt=""/><h2>{user.name}</h2><p>Member since {user.member_since}</p></div><div className="points-card"><span className="points-star">☆</span><span><b>{totalPoints.toLocaleString()} Points</b><small>{availablePoints.toLocaleString()} available · {redeemedPoints.toLocaleString()} redeemed</small></span><span className="points-chevron">›</span></div><p className="points-earn-note">Earn 1 point per RM 1 after payment is confirmed · {pointsCost} points = 1 free drink.</p><div className="points-breakdown"><div><small>TOTAL POINTS</small><b>{totalPoints.toLocaleString()}</b></div><div><small>REDEEMED</small><b>{redeemedPoints.toLocaleString()}</b></div><div><small>AVAILABLE</small><b>{availablePoints.toLocaleString()}</b></div></div>{availablePoints>=pointsCost&&<Button variant="secondary" className="redeem-points" onClick={redeemPoints}>REDEEM {pointsCost} POINTS · FREE DRINK</Button>}{notice&&<p className="profile-notice" role="status">{notice}</p>}<div className="profile-block"><h3>MY VOUCHERS</h3>{data.vouchers.length?data.vouchers.map((voucher:any)=><div className="profile-voucher" key={voucher.id}><b>{voucher.name}</b><small>{voucher.description||'Naise member reward'}</small><span>{voucher.status.toUpperCase()}</span></div>):<p className="profile-empty">Your vouchers will show up here.</p>}</div><div className="profile-block"><h3>ACCOUNT</h3><div className="profile-link">{user.email}</div><button className="profile-link profile-link-button" onClick={()=>{setPage('edit');setNotice('')}}><Pencil size={15}/> Edit profile <ChevronRight size={16}/></button><button className="profile-link profile-link-button" onClick={()=>{setPage('settings');setNotice('')}}><Settings size={16}/> Settings <ChevronRight size={16}/></button></div><div className="profile-block recent-orders-block"><div className="profile-section-heading"><h3>RECENT ORDERS</h3>{data.recent_orders?.length>0&&<button onClick={showAllOrders}>See all <ChevronRight size={15}/></button>}</div>{data.recent_orders?.length?data.recent_orders.slice(0,3).map((order:any)=><OrderCard key={order.id} order={order} onView={()=>{setReturnPage('profile');setSelectedOrder(order);setPage('order')}}/>):<p className="profile-empty">Your recent orders will show here.</p>}</div><button className="profile-signout" onClick={onSignOut}>SIGN OUT</button></>}
  {page==='orders'&&<><div className="profile-subheader"><button onClick={back} aria-label="Back"><ArrowLeft/></button><h1>MY ORDERS</h1></div><div className="profile-section-heading"><h3>ALL ORDERS</h3><span>{allOrders.length} ORDERS</span></div>{allOrders.length?allOrders.map((order:any)=><OrderCard key={order.id} order={order} onView={()=>{setReturnPage('orders');setSelectedOrder(order);setPage('order')}}/>):<p className="profile-empty">You have no orders yet.</p>}</>}
  {page==='order'&&selectedOrder&&<><div className="profile-subheader"><button onClick={()=>setPage(returnPage)} aria-label="Back"><ArrowLeft/></button><h1>ORDER DETAILS</h1></div><OrderCard order={selectedOrder} onView={()=>{}}/><div className="order-detail-breakdown"><div><span>Subtotal</span><b>{money(selectedOrder.subtotal)}</b></div>{Number(selectedOrder.discount_total)>0&&<div className="discount-line"><span>Voucher</span><b>−{money(selectedOrder.discount_total)}</b></div>}<div><strong>Total</strong><strong>{money(selectedOrder.total)}</strong></div></div></>}
  {page==='settings'&&<><div className="profile-subheader"><button onClick={back} aria-label="Back"><ArrowLeft/></button><h1>SETTINGS</h1></div><p className="settings-label">SECURITY</p><div className="settings-list">{[{title:'Change Password',description:'Update your password',Icon:LockKeyhole},{title:'Notifications',description:'Manage how we reach you',Icon:Bell},{title:'Privacy',description:'Control your data',Icon:ShieldCheck}].map(({title,description,Icon})=><button className="settings-row" key={title} onClick={()=>setNotice(`${title} settings are coming soon.`)}><span className="settings-icon"><Icon size={18}/></span><span className="settings-copy"><b>{title}</b><small>{description}</small></span><span className="soon-pill">SOON</span></button>)}</div>{notice&&<p className="profile-notice" role="status">{notice}</p>}</>}
  {page==='edit'&&<><div className="profile-subheader"><button onClick={back} aria-label="Back"><ArrowLeft/></button><h1>EDIT PROFILE</h1></div><form className="edit-profile-form" onSubmit={saveProfile}><label className="profile-photo-picker">{photoPreview||user.avatar_url?<img src={photoPreview||user.avatar_url} alt="Profile preview"/>:<span className="profile-photo-fallback">N</span>}<span className="camera-badge"><Camera size={16}/></span><input type="file" accept="image/png,image/jpeg,image/webp" onChange={editPhoto}/></label><p className="change-photo-label">Change photo</p><label className="edit-field-label" htmlFor="display-name">DISPLAY NAME</label><Input id="display-name" className="edit-profile-input" value={displayName} readOnly={!!data.google_connected} onChange={event=>setDisplayName(event.target.value)} required maxLength={120}/><label className="edit-field-label" htmlFor="whatsapp-number">WHATSAPP NUMBER</label><div className="whatsapp-input"><Input id="whatsapp-number" type="tel" inputMode="tel" autoComplete="tel" value={whatsapp} onChange={event=>setWhatsapp(event.target.value)} placeholder="e.g. +60 12-345 6789" maxLength={30} required/></div><small className="edit-help">We’ll use this number for order pickup notifications. Store staff will send messages through WhatsApp.</small>{formError&&<p className="edit-error" role="alert">{formError}</p>}<Button className="save-profile-button" type="submit" disabled={saving}>{saving?'SAVING…':'SAVE CHANGES'}</Button></form></>}
 </section>
}

function App(){
 const [category,setCategory]=useState('Best Seller'); const [search,setSearch]=useState(''); const [sort,setSort]=useState('Recommended'); const [catalog,setCatalog]=useState<Item[]>(menu); const [profileData,setProfileData]=useState<any>(null);
 const [storeMode,setStoreMode]=useState(document.querySelector<HTMLMetaElement>(`meta[name="store-mode-enabled"]`)?.content === `true`); const [holdProgress,setHoldProgress]=useState(0); const [adminPrompt,setAdminPrompt]=useState(false); const [storePasscode,setStorePasscode]=useState(''); const [storeError,setStoreError]=useState(''); const [storeBusy,setStoreBusy]=useState(false);
 const holdTimer=React.useRef<number|undefined>(undefined); const holdInterval=React.useRef<number|undefined>(undefined);
 useEffect(()=>{const params=new URLSearchParams();if(category!=='Best Seller')params.set('category',category);else params.set('category','Best Seller');if(search)params.set('search',search);if(sort==='Price: Low to High')params.set('sort','asc');if(sort==='Price: High to Low')params.set('sort','desc');const controller=new AbortController();fetch(`/api/menu?${params}`,{signal:controller.signal}).then(r=>{if(!r.ok)throw new Error('Menu API unavailable');return r.json()}).then((result:{data:Item[]})=>setCatalog(result.data)).catch(()=>{if(!controller.signal.aborted)setCatalog(menu.filter(i=>(category==='Best Seller'?i.bestSeller:i.category===category)&&i.name.toLowerCase().includes(search.toLowerCase())).sort((a,b)=>sort==='Price: Low to High'?a.price-b.price:sort==='Price: High to Low'?b.price-a.price:0))});return()=>controller.abort()},[category,search,sort]);
 const [cart,setCart]=useState<Record<string,number>>({}); const [checkoutOpen,setCheckoutOpen]=useState(false); const [profile,setProfile]=useState(document.querySelector<HTMLMetaElement>('meta[name="profile-tab-enabled"]')?.content==='true'); const [detail,setDetail]=useState<Item|null>(null);
 const refreshProfile=()=>fetch('/api/profile',{headers:{Accept:'application/json'}}).then(r=>r.json()).then(setProfileData).catch(()=>setProfileData(null));
 const exitStoreMode=async()=>{await fetch('/admin/store-mode/exit',{method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content||''}});setStoreMode(false)};
 const signOut=()=>fetch('/logout',{method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content||''}}).then(()=>window.location.reload());
 useEffect(()=>{refreshProfile()},[]);
 useEffect(()=>{if(profileData?.authenticated&&(profileData?.needs_profile||profileData?.needs_phone))setProfile(true)},[profileData]);
 useEffect(()=>{if(profile)refreshProfile()},[profile]);
 const filtered=useMemo(()=>catalog,[catalog]);
 const qty=Object.values(cart).reduce((a,b)=>a+b,0); const total=menu.reduce((sum,i)=>sum+i.price*(cart[i.name]||0),0);
 const cartItems=menu.filter(item=>cart[item.name]).map(item=>({item,quantity:cart[item.name]}));
 const add=(i:Item)=>setCart(c=>({...c,[i.name]:(c[i.name]||0)+1}));
 const changeCategory=(c:string)=>{setCategory(c);setSearch('');window.scrollTo({top:0,behavior:'smooth'});};
 const beginStoreHold=()=>{window.clearTimeout(holdTimer.current);window.clearInterval(holdInterval.current);setHoldProgress(0);const started=Date.now();holdInterval.current=window.setInterval(()=>setHoldProgress(Math.min((Date.now()-started)/1800*100,100)),30);holdTimer.current=window.setTimeout(()=>{setHoldProgress(100);window.clearInterval(holdInterval.current);setAdminPrompt(true)},1800)};
 const endStoreHold=()=>{window.clearTimeout(holdTimer.current);window.clearInterval(holdInterval.current);setHoldProgress(0)};
 const unlockStoreMode=async(e:React.FormEvent)=>{e.preventDefault();setStoreBusy(true);setStoreError('');try{const response=await fetch('/admin/store-mode',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content||''},body:JSON.stringify({passcode:storePasscode})});if(response.status===401){window.location.assign('/admin');return}if(!response.ok){const result=await response.json().catch(()=>({}));setStoreError(result.errors?.passcode?.[0]||'Could not unlock store mode. Please try again.');return}setStoreMode(true);setAdminPrompt(false);setStorePasscode('')}catch{setStoreError('Could not reach the server. Try again.')}finally{setStoreBusy(false)}};
 return <div className={`app-shell${storeMode?' store-mode-active':''}`}>
  <button className="secret-store-button" aria-label="Enter store mode (press and hold)" title="Enter store mode (press and hold)" onPointerDown={beginStoreHold} onPointerUp={endStoreHold} onPointerLeave={endStoreHold} onContextMenu={e=>e.preventDefault()} onKeyDown={e=>{if(e.key===' '||e.key==='Enter'){e.preventDefault();beginStoreHold()}}} onKeyUp={endStoreHold}>{holdProgress>0&&<span style={{transform:`scaleX(${holdProgress/100})`}}/>}</button>
  {storeMode&&<div className="store-mode-banner"><span>STORE MODE</span><a href="/staff/orders">ORDERS</a><button onClick={exitStoreMode}>EXIT ×</button></div>}
  {adminPrompt&&<div className="scrim" onClick={()=>setAdminPrompt(false)}><div className="notice-modal" onClick={e=>e.stopPropagation()}><button className="modal-close" type="button" onClick={()=>setAdminPrompt(false)}><X/></button><span className="modal-icon"><Leaf/></span><p className="eyebrow">ADMIN VERIFIED</p><h2>Enter store mode</h2><p>Enter the store passcode to unlock this session.</p><form className="store-passcode-form" onSubmit={unlockStoreMode}><input aria-label="Store passcode" autoComplete="one-time-code" inputMode="numeric" type="password" value={storePasscode} onChange={e=>setStorePasscode(e.target.value)} placeholder="Store passcode" required/><button className="modal-action" disabled={storeBusy}>{storeBusy?'CHECKING…':'UNLOCK STORE MODE'} <span>↗</span></button></form>{storeError&&<p className="store-passcode-error" role="alert">{storeError}</p>}<a className="admin-link" href="/admin">Open admin dashboard ↗</a></div></div>}
  <header className="topbar"><div className="brand-mark"><img src="/icons/naise-192.png?v=5" alt="Naise Coffee logo"/></div><div className="brand-name">NAISE <small>COFFEE</small></div><button className="store-mode" onClick={()=>setDetail({name:'Visit Naise',price:0,category:'',bestSeller:false,image:''})}>ORDER FOR PICKUP <span className="status-dot"/></button></header>
  <main>
   {(profile||profileData?.needs_profile||profileData?.needs_phone)?<ProfileScreen data={profileData} refreshProfile={refreshProfile} onSignOut={signOut}/>:checkoutOpen?<CheckoutScreen items={cartItems} total={total} onBack={()=>setCheckoutOpen(false)} onOrderPlaced={()=>setCart({})} onSignInRequired={()=>{setCheckoutOpen(false);setProfile(true);window.scrollTo({top:0,behavior:'smooth'})}}/>:<>
    <section className="menu-header"><div className="menu-title">MENU</div><div className="searchbox"><Search size={16}/><input placeholder="Search drinks…" value={search} onChange={e=>setSearch(e.target.value)}/>{search&&<button onClick={()=>setSearch('')}><X size={14}/></button>}</div></section>
    <section className="menu-section">
     <div className="filters"><div className="sortbox"><span>SORT BY</span><select value={sort} onChange={e=>setSort(e.target.value)}><option>Recommended</option><option>Price: Low to High</option><option>Price: High to Low</option></select></div></div>
     <div className="category-row">{categories.map(c=><button className={category===c?'active':''} key={c} onClick={()=>changeCategory(c)}>{c==='Best Seller'&&<span className="star">✳</span>}{c}</button>)}</div>
     <div className="catalog-top"><h3>{category==='Best Seller'?'✦ ':''}{category}</h3><span>{filtered.length.toString().padStart(2,'0')} ITEMS</span></div>
     <div className="product-list">{filtered.map((item,index)=><article className="product" key={item.name} style={{'--delay':`${index*35}ms`} as React.CSSProperties}>
       <button className="product-image" onClick={()=>setDetail(item)} aria-label={`View ${item.name}`}><span className="image-wash"/>{item.image?<img src={item.image} alt={item.name}/>:<div className={`cup cup-${item.category.toLowerCase().replace(' ','-')}`}><i/><b>NAISE</b></div>}{item.bestSeller&&<span className="seller-tag">BEST SELLER</span>}</button>
       <div className="product-meta"><div className="product-title-row"><h4>{item.name}</h4>{cart[item.name]>0&&<small className="inline-qty">IN BAG · {cart[item.name]}</small>}</div><div className="product-bottom"><span>{money(item.price)}</span><button className={cart[item.name]?'add-btn added':'add-btn'} onClick={()=>add(item)} aria-label={`Customize and add ${item.name}`}><Plus size={16}/></button></div></div>
      </article>)}</div>{filtered.length===0&&<div className="empty">No drinks found. Try another search.</div>}
    </section>
   </>}
  </main>
  {qty>0&&!profile&&!checkoutOpen&&<div className="cart-checkout-bar"><div className="cart-bar-photo">{cartItems[0]?.item.image&&<img src={cartItems[0].item.image} alt=""/>}<span>{qty}</span></div><div className="cart-bar-total"><small>{qty} item{qty===1?'':'s'} in your bag</small><b>{money(total)}</b></div><button onClick={()=>setCheckoutOpen(true)}>Checkout</button></div>}
  <nav className="bottom-nav"><button className={!profile?'selected':''} onClick={()=>{setProfile(false);window.scrollTo({top:0,behavior:'smooth'})}}><Coffee size={18}/><span>MENU</span></button><div className="nav-divider"/><button className={profile?'selected':''} onClick={()=>{setProfile(true);window.scrollTo({top:0,behavior:'smooth'})}}><UserRound size={18}/><span>PROFILE</span></button></nav>
  {detail&&<div className="scrim" onClick={()=>setDetail(null)}><div className="notice-modal" onClick={e=>e.stopPropagation()}><button className="modal-close" onClick={()=>setDetail(null)}><X/></button><span className="modal-icon"><Leaf/></span><p className="eyebrow">NAISE COFFEE</p><h2>{detail.name}</h2><p>The public menu lets us recreate the drinks and prices. This part of the original experience needs an authenticated Naise account, so its live options are not visible yet.</p><button className="modal-action" onClick={()=>setDetail(null)}>GOT IT <span>↗</span></button></div></div>}
 </div>
}
createRoot(document.getElementById('root')!).render(<App/>);
