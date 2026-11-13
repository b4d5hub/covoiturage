// Comportements communs à toutes les pages : navigation mobile, menu du compte,
// confirmation des actions irréversibles et indication d'envoi des formulaires.
// Sans JavaScript, la navigation reste visible et les formulaires s'envoient normalement.

// Navigation mobile.
const navToggle = document.querySelector('[data-nav-toggle]');
const nav = document.getElementById('site-nav');

navToggle?.addEventListener('click', () => {
    const open = navToggle.getAttribute('aria-expanded') !== 'true';
    navToggle.setAttribute('aria-expanded', String(open));
    nav.classList.toggle('is-open', open);
});

// Menu du compte : ouverture au clic, fermeture par Échap ou clic à l'extérieur.
document.querySelectorAll('[data-menu]').forEach((menu) => {
    const button = menu.querySelector('[data-menu-button]');
    const setOpen = (open) => {
        menu.classList.toggle('is-open', open);
        button.setAttribute('aria-expanded', String(open));
    };

    button.addEventListener('click', () => setOpen(!menu.classList.contains('is-open')));
    document.addEventListener('click', (event) => {
        if (!menu.contains(event.target)) {
            setOpen(false);
        }
    });
    menu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menu.classList.contains('is-open')) {
            setOpen(false);
            button.focus();
        }
    });
});

// Confirmation : un formulaire portant data-confirm demande une validation avant l'envoi.
const dialog = document.getElementById('confirm-dialog');

const askConfirmation = (form) => new Promise((resolve) => {
    if (!dialog || typeof dialog.showModal !== 'function') {
        resolve(window.confirm(form.dataset.confirm));
        return;
    }

    dialog.querySelector('#confirm-title').textContent = form.dataset.confirmTitle || 'Confirmer';
    dialog.querySelector('[data-confirm-message]').textContent = form.dataset.confirm;
    const accept = dialog.querySelector('[data-confirm-accept]');
    accept.textContent = form.dataset.confirmLabel || 'Confirmer';
    accept.className = form.dataset.confirmTone === 'danger' ? 'button button-danger' : 'button';

    dialog.addEventListener('close', () => resolve(dialog.returnValue === 'confirm'), { once: true });
    dialog.returnValue = '';
    dialog.showModal();
    dialog.querySelector('[data-confirm-cancel]').focus();
});

// Indication d'envoi : le bouton est désactivé pour éviter un double envoi.
const markBusy = (form, submitter) => {
    const button = submitter || form.querySelector('button[type="submit"], button:not([type])');
    if (!button) {
        return;
    }
    button.setAttribute('aria-busy', 'true');
    if (button.dataset.busyLabel) {
        button.dataset.idleLabel = button.textContent;
        button.textContent = button.dataset.busyLabel;
    }
    // Désactivé après l'envoi pour que la valeur du bouton parte avec le formulaire.
    setTimeout(() => { button.disabled = true; }, 0);
};

document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (form.method === 'dialog' || form.dataset.submitting === 'true') {
        if (form.dataset.submitting === 'true') {
            event.preventDefault();
        }
        return;
    }

    if (form.dataset.confirm && form.dataset.confirmed !== 'true') {
        event.preventDefault();
        if (await askConfirmation(form)) {
            form.dataset.confirmed = 'true';
            form.requestSubmit(event.submitter || undefined);
        }
        return;
    }

    form.dataset.submitting = 'true';
    markBusy(form, event.submitter);
});

// Retour arrière depuis le cache du navigateur : les boutons redeviennent utilisables.
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) {
        return;
    }
    document.querySelectorAll('form[data-submitting]').forEach((form) => {
        delete form.dataset.submitting;
        delete form.dataset.confirmed;
        form.querySelectorAll('button[aria-busy]').forEach((button) => {
            button.removeAttribute('aria-busy');
            button.disabled = false;
            if (button.dataset.idleLabel) {
                button.textContent = button.dataset.idleLabel;
            }
        });
    });
});

// Cloche : un clic ouvre l'aperçu des dernières notifications sous l'icône, qu'il y en ait de
// nouvelles ou non (dans le menu replié sur petit écran). Sans JavaScript, la cloche mène à la page.
// L'aperçu est demandé dès que le pointeur ou le clavier atteint la cloche, pour s'afficher sans attente.
document.querySelectorAll('[data-notif]').forEach((root) => {
    const toggle = root.querySelector('[data-notif-toggle]');
    const panel = root.querySelector('[data-notif-panel]');
    let pending = null;
    let loadedAt = 0;

    // Un aperçu demandé il y a moins de 15 secondes est réutilisé ; au-delà, il est redemandé
    // pour refléter les notifications arrivées entre-temps.
    const load = () => {
        if (!pending || Date.now() - loadedAt > 15000) {
            loadedAt = Date.now();
            pending = fetch(root.dataset.src, { headers: { Accept: 'text/html' }, cache: 'no-store' })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }
                    return response.text();
                });
            pending.catch(() => { pending = null; });
        }
        return pending;
    };

    const close = () => {
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    };

    const open = async () => {
        panel.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        panel.setAttribute('aria-busy', 'true');
        panel.innerHTML = '<p class="notif-empty">Chargement…</p>';
        try {
            const html = await load();
            if (panel.hidden) {
                return;
            }
            panel.innerHTML = html;
        } catch {
            // L'aperçu est un confort : en cas d'échec, on ouvre la page complète.
            window.location.href = toggle.href;
            return;
        } finally {
            panel.removeAttribute('aria-busy');
        }
        panel.querySelector('a')?.focus();
    };

    toggle.addEventListener('pointerenter', load);
    toggle.addEventListener('focus', load);
    toggle.addEventListener('click', (event) => {
        event.preventDefault();
        if (panel.hidden) {
            open();
        } else {
            close();
        }
    });
    document.addEventListener('click', (event) => {
        if (!panel.hidden && !root.contains(event.target)) {
            close();
        }
    });
    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) {
            close();
            toggle.focus();
        }
    });
});
