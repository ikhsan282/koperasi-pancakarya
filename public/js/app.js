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
