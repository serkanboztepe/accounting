{{-- Cari kilidi: sayfada işlem yapılmazsa süre dolunca yenile; sunucu şifre ekranına yönlendirir. --}}
<script>
    (() => {
        const ms = {{ (int) config('app.cari_lock_minutes') }} * 60 * 1000 + 5000;
        let timer;
        const reset = () => { clearTimeout(timer); timer = setTimeout(() => location.reload(), ms); };
        ['click', 'keydown', 'scroll', 'touchstart'].forEach((e) => window.addEventListener(e, reset, { passive: true }));
        reset();
    })();
</script>
