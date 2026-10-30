const counter = document.getElementById('retry-seconds');
const submit = document.querySelector('button.submit');
if (counter && submit) {
    const deadline = Date.now() + Number(document.body.dataset.retryAfter) * 1000;
    const timer = setInterval(() => {
        const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        counter.textContent = String(remaining);
        if (remaining === 0) {
            clearInterval(timer);
            submit.disabled = false;
            document.querySelector('.lockout').textContent = 'You can try signing in again now.';
        }
    }, 1000);
}
