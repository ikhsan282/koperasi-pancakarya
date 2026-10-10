// Mobile menu toggle
document.addEventListener('click', e => {
    const toggle = e.target.closest('.menu');
    if (toggle) {
        document.querySelector('.app-shell')?.classList.toggle('menu-open');
    }
    if (!e.target.closest('.sidebar') && !e.target.closest('.menu')) {
        document.querySelector('.app-shell')?.classList.remove('menu-open');
    }
});

// Dark mode toggle
document.addEventListener('click', e => {
    if (e.target.closest('.dark-toggle')) {
        const current = document.documentElement.getAttribute('data-bs-theme') || 'light';
        const next = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        try { localStorage.setItem('pancakarya_theme', next); } catch(err) {}
        e.target.closest('.dark-toggle').textContent = next === 'dark' ? '☀️' : '🌙';
    }
});

// PWA install: leave the native browser prompt untouched (no custom banner yet)

// Service Worker (URL injected from header.php)
if ('serviceWorker' in navigator && window.APP_URL) {
  navigator.serviceWorker.register(window.APP_URL + '/sw.js').catch(err => console.warn('SW registration failed:', err));
}
