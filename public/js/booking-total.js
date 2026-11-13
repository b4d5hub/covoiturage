// Récapitulatif de la réservation avant l'envoi : portion choisie, prix par place, total,
// places encore libres, et mise en valeur de la portion sur la ligne de route.
// Le serveur refait tous les contrôles : ce script n'est qu'une aide à la saisie.
const form = document.querySelector('[data-booking-form]');

if (form) {
    const portions = JSON.parse(form.dataset.portions);
    const boarding = form.querySelector('[name$="[boarding]"]');
    const alighting = form.querySelector('[name$="[alighting]"]');
    const seats = form.querySelector('[name$="[seats]"]');
    const submit = form.querySelector('[data-booking-submit]');
    const summary = form.querySelector('[data-booking-total]');
    const message = form.querySelector('[data-booking-message]');
    const stepButtons = [...form.querySelectorAll('[data-step]')];
    const routeLine = document.querySelector('[data-route-line]');
    const stops = routeLine ? [...routeLine.querySelectorAll('[data-stop-id]')] : [];
    const panel = document.getElementById('reserver');
    const bar = document.querySelector('[data-mobile-book-bar]');

    // Montants en centimes entiers, formatés sans calcul à virgule flottante.
    const mad = (cents) => `${Math.floor(cents / 100).toLocaleString('fr-FR')},${String(cents % 100).padStart(2, '0')} MAD`;
    const cityOf = (id) => stops.find((stop) => stop.dataset.stopId === String(id))?.dataset.city ?? '';
    const field = (name) => summary.querySelector(`[data-summary="${name}"]`);

    const setBar = (total, detail) => {
        if (!bar) return;
        bar.querySelector('[data-bar-total]').textContent = total;
        bar.querySelector('[data-bar-detail]').textContent = detail;
    };

    if (bar && panel) {
        const mobile = window.matchMedia('(max-width: 760px)');
        let panelVisible = true;
        const syncBar = () => {
            bar.hidden = panelVisible || !mobile.matches;
            document.body.classList.toggle('has-book-bar', !bar.hidden);
        };
        mobile.addEventListener('change', syncBar);
        new IntersectionObserver(([entry]) => {
            panelVisible = entry.isIntersecting;
            syncBar();
        }).observe(panel);
    }

    const highlight = (fromId, toId) => {
        if (!routeLine) {
            return;
        }
        const from = stops.findIndex((stop) => stop.dataset.stopId === String(fromId));
        const to = stops.findIndex((stop) => stop.dataset.stopId === String(toId));
        const valid = from > -1 && to > from;
        routeLine.classList.toggle('has-portion', valid);
        stops.forEach((stop, index) => {
            stop.classList.toggle('in-portion', valid && index >= from && index <= to);
            stop.classList.toggle('is-boarding', valid && index === from);
            stop.classList.toggle('is-alighting', valid && index === to);
            const role = stop.querySelector('[data-role-label]');
            if (role) {
                role.textContent = !valid ? '' : index === from ? 'Vous montez ici'
                    : index === to ? 'Vous descendez ici' : index > to ? 'Suite du trajet' : '';
            }
        });
    };

    // Prix et places affichés à chaque étape, depuis l'étape de montée choisie, avec les actions
    // « Monter ici » (avant la montée) et « Descendre ici » (après). Mêmes règles que le rendu du serveur.
    const lastId = stops.length ? stops[stops.length - 1].dataset.stopId : '';
    const canBoardAt = (id) => Object.entries(portions).some(([key, p]) => key.startsWith(`${id}-`) && p.open && p.available > 0);
    const pickButton = (label, onPick) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'rl-offer-pick';
        button.textContent = label;
        button.addEventListener('click', () => {
            onPick();
            update();
        });
        return button;
    };

    const renderOffers = (fromId, toId) => {
        const from = stops.findIndex((stop) => stop.dataset.stopId === String(fromId));
        const to = stops.findIndex((stop) => stop.dataset.stopId === String(toId));
        stops.forEach((stop, index) => {
            const box = stop.querySelector('[data-stop-offer]');
            if (!box) {
                return;
            }
            const id = stop.dataset.stopId;
            const parts = [];
            if (from > -1 && index > from) {
                const offer = portions[`${fromId}-${id}`];
                if (offer) {
                    const price = document.createElement('span');
                    price.className = 'rl-offer-price';
                    price.textContent = !offer.open ? 'Départ passé' : (offer.available > 0 ? mad(offer.seatPriceCents) : 'Complet');
                    parts.push(price);
                    if (offer.open && offer.available > 0) {
                        const free = document.createElement('span');
                        free.className = 'rl-offer-seats';
                        free.textContent = `${offer.available} place${offer.available > 1 ? 's' : ''} libre${offer.available > 1 ? 's' : ''}`;
                        parts.push(free);
                        if (index !== to) {
                            parts.push(pickButton('Descendre ici', () => { alighting.value = id; }));
                        }
                    }
                }
            } else if (index < stops.length - 1 && (from === -1 || index < from) && canBoardAt(id)) {
                parts.push(pickButton('Monter ici', () => {
                    boarding.value = id;
                    if (to <= index) {
                        alighting.value = lastId;
                    }
                }));
            }
            box.replaceChildren(...parts);
        });
    };

    const block = (text) => {
        summary.hidden = true;
        message.hidden = false;
        message.classList.add('is-error');
        message.textContent = text;
        submit.disabled = true;
        stepButtons.forEach((button) => { button.disabled = true; });
        setBar('—', 'Choisissez vos étapes');
    };

    const update = () => {
        const portion = portions[`${boarding.value}-${alighting.value}`];
        highlight(boarding.value, alighting.value);
        renderOffers(boarding.value, alighting.value);

        if (!portion) {
            block('Choisissez une ville de descente située après votre ville de montée.');
            return;
        }

        if (!portion.open) {
            block('Cette étape de montée est déjà passée. Choisissez un départ à venir.');
            return;
        }

        if (portion.available < 1) {
            block(`Plus aucune place entre ${cityOf(boarding.value)} et ${cityOf(alighting.value)}. Essayez de monter ou de descendre à une autre étape.`);
            return;
        }

        seats.max = portion.available;
        const count = Math.min(Math.max(parseInt(seats.value, 10) || 1, 1), portion.available);
        if (String(count) !== seats.value) {
            seats.value = count;
        }

        const total = portion.seatPriceCents * count;
        field('portion').textContent = `${cityOf(boarding.value)} → ${cityOf(alighting.value)}`;
        field('price').textContent = mad(portion.seatPriceCents);
        field('seats').textContent = String(count);
        field('total').textContent = mad(total);
        summary.hidden = false;

        message.hidden = false;
        message.classList.remove('is-error');
        message.textContent = portion.available === 1
            ? 'Il reste 1 place entre ces étapes.'
            : `Il reste ${portion.available} places entre ces étapes.`;

        stepButtons.forEach((button) => {
            button.hidden = false;
            const step = Number(button.dataset.step);
            button.disabled = (step < 0 && count <= 1) || (step > 0 && count >= portion.available);
        });

        submit.disabled = false;
        setBar(mad(total), 'Paiement simulé');
    };

    stepButtons.forEach((button) => button.addEventListener('click', () => {
        seats.value = (parseInt(seats.value, 10) || 1) + Number(button.dataset.step);
        update();
    }));

    // « Choisir » dans le tableau des portions : préremplit le formulaire sans recharger la page.
    document.querySelectorAll('[data-pick-portion]').forEach((link) => link.addEventListener('click', (event) => {
        event.preventDefault();
        boarding.value = link.dataset.boarding;
        alighting.value = link.dataset.alighting;
        update();
        document.getElementById('reserver')?.scrollIntoView({ block: 'start' });
        boarding.focus({ preventScroll: true });
    }));

    // Une montée choisie après la descente : la descente passe à l'arrivée du trajet plutôt que de rester impossible.
    boarding.addEventListener('input', () => {
        const order = (id) => stops.findIndex((stop) => stop.dataset.stopId === String(id));
        if (lastId && order(alighting.value) <= order(boarding.value)) {
            alighting.value = lastId;
        }
    });
    [boarding, alighting, seats].forEach((input) => input.addEventListener('input', update));
    update();
}
