{{-- Installable-app (PWA) tags + service worker registration. Shared by the app and guest layouts. --}}
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0a7f50">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="BlastIQ">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="icon" type="image/png" sizes="64x64" href="/icons/favicon-64.png">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
        });
    }
</script>
