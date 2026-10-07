'use strict';

document.querySelectorAll('[data-toggle-password]').forEach((button) => {
    const input = document.getElementById(button.dataset.togglePassword);
    if (!input) return;
    button.hidden = false;
    button.addEventListener('click', () => {
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(visible));
        const label = input.id === 'confirmation' ? 'konfirmasi kata sandi' : 'kata sandi';
        button.setAttribute('aria-label', `${visible ? 'Sembunyikan' : 'Tampilkan'} ${label}`);
    });
});

document.querySelectorAll('[data-auth-form]').forEach((form) => {
    form.addEventListener('submit', () => {
        const button = form.querySelector('[type="submit"]');
        const status = form.querySelector('[data-submit-status]');
        if (button) {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        }
        if (status) status.textContent = 'Memproses permintaan Anda…';
    });
});

window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-auth-form] [aria-busy="true"]').forEach((button) => {
        button.disabled = false;
        button.removeAttribute('aria-busy');
    });
    document.querySelectorAll('[data-submit-status]').forEach((status) => {
        status.textContent = '';
    });
});
