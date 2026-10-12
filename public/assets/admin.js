'use strict';

document.querySelectorAll('form').forEach((form) => {
    if (form.querySelector('input[name="action"][value="generate_image"]')) {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.textContent = 'Membuat gambar... tunggu hingga 90 detik';
            }
            form.setAttribute('aria-busy', 'true');
        });
    }
});

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

const contentCreate = document.getElementById('content-create');
const plannedAt = document.getElementById('content-planned-at');
const contentTitle = document.getElementById('content-title');
const contentCompany = document.getElementById('content-company');
const contentCampaign = document.getElementById('content-campaign');

if (contentCompany && contentCampaign) {
    const filterCampaigns = () => {
        Array.from(contentCampaign.options).forEach((option) => {
            const unavailable = option.value !== '' && option.dataset.companyId !== contentCompany.value;
            option.hidden = unavailable;
            option.disabled = unavailable;
            if (unavailable && option.selected) contentCampaign.value = '';
        });
    };
    contentCompany.addEventListener('change', filterCampaigns);
    filterCampaigns();
}

if (contentCreate && plannedAt && contentTitle) {
    const openContentForm = (date) => {
        const existingTime = plannedAt.value.split('T')[1] || '09:00';
        plannedAt.value = `${date}T${existingTime}`;
        contentCreate.open = true;
        contentCreate.scrollIntoView({ block: 'start' });
        contentTitle.focus({ preventScroll: true });
    };
    document.querySelectorAll('[data-add-date]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            openContentForm(link.dataset.addDate);
        });
    });
    document.querySelectorAll('[data-calendar-date]').forEach((cell) => {
        cell.addEventListener('click', (event) => {
            if (event.target !== cell) return;
            openContentForm(cell.dataset.calendarDate);
        });
    });
}
