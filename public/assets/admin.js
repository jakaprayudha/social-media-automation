'use strict';

const toggle = document.querySelector('.menu-toggle');
const sidebar = document.getElementById('admin-sidebar');
const shell = document.querySelector('.admin-shell');

if (toggle && sidebar && shell) {
    const setExpanded = (expanded) => {
        sidebar.hidden = !expanded;
        shell.classList.toggle('sidebar-collapsed', !expanded);
        toggle.setAttribute('aria-expanded', String(expanded));
        toggle.setAttribute('aria-label', `${expanded ? 'Tutup' : 'Buka'} menu admin`);
    };
    toggle.hidden = false;
    const mobile = window.matchMedia('(max-width: 760px)');
    setExpanded(!mobile.matches);
    mobile.addEventListener('change', (event) => setExpanded(!event.matches));
    toggle.addEventListener('click', () => setExpanded(sidebar.hidden));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && mobile.matches && !sidebar.hidden) {
            setExpanded(false);
            toggle.focus();
        }
    });
}
