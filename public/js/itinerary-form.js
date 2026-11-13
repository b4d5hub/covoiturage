// Formulaire de publication : ajout, retrait et réordonnancement des étapes, numérotation,
// champs sans objet désactivés (pas d'arrivée au départ, ni de départ ni de distance à l'arrivée)
// et récapitulatif avant publication. Le serveur refait tous les contrôles.
const form = document.querySelector('[data-publish-form]');
const collection = form?.querySelector('[data-collection]');

if (form && collection) {
    const list = collection.querySelector('[data-collection-list]');
    const items = () => [...list.querySelectorAll('[data-collection-item]')];
    const field = (item, name) => item.querySelector(`[name$="[${name}]"]`);
    const MIN_STOPS = 2;

    // Les noms et identifiants suivent l'ordre affiché : publish_trip[itinerary][0], [1]…
    const renumber = () => {
        items().forEach((item, index) => {
            item.querySelectorAll('[name], [id], [for], [aria-describedby]').forEach((element) => {
                ['name', 'id', 'for', 'aria-describedby'].forEach((attribute) => {
                    const value = element.getAttribute(attribute);
                    if (value) {
                        element.setAttribute(attribute, value
                            .replace(/(\[itinerary\]\[)[^\]]+(\])/g, `$1${index}$2`)
                            .replace(/(_itinerary_)([^_]+)(_)/g, `$1${index}$3`));
                    }
                });
            });
        });
    };

    const toggle = (item, name, visible) => {
        const wrapper = item.querySelector(`[data-stop-field="${name}"]`);
        if (!wrapper) {
            return;
        }
        wrapper.hidden = !visible;
        // Un champ désactivé n'est pas envoyé : sa valeur est conservée si l'étape change de place.
        wrapper.querySelectorAll('input').forEach((input) => { input.disabled = !visible; });
    };

    const refresh = () => {
        renumber();
        const all = items();
        all.forEach((item, index) => {
            const first = index === 0;
            const last = index === all.length - 1;
            const role = first ? 'départ' : (last ? 'arrivée' : 'étape intermédiaire');
            item.dataset.role = first ? 'first' : (last ? 'last' : 'middle');
            item.querySelector('[data-stop-index]').textContent = String(index + 1);
            item.querySelector('[data-stop-title]').textContent = `Étape ${index + 1} – ${role}`;
            toggle(item, 'arrival', !first);
            toggle(item, 'departure', !last);
            toggle(item, 'distance', !last);
            item.querySelector('[data-move="-1"]').disabled = first;
            item.querySelector('[data-move="1"]').disabled = last;
            const remove = item.querySelector('[data-collection-remove]');
            remove.disabled = all.length <= MIN_STOPS;
            remove.title = remove.disabled ? 'Un trajet compte au moins deux étapes' : 'Retirer';
        });
        updateHints();
        updateRecap();
    };

    // Distances : rappel en kilomètres sous le champ en mètres.
    const updateHints = () => {
        items().forEach((item) => {
            const hint = item.querySelector('[data-km-hint]');
            const meters = parseInt(field(item, 'distanceToNextMeters')?.value, 10);
            if (hint) {
                hint.textContent = meters > 0 ? `soit ${(meters / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} km` : '';
            }
        });
    };

    // Ville trouvée sur la carte par l'assistant, sinon celle choisie dans la liste.
    const cityName = (item) => {
        if (item.dataset.city) {
            return item.dataset.city;
        }
        const select = field(item, 'city');
        return select && select.value ? select.options[select.selectedIndex].text : '…';
    };

    const formatMoment = (value) => {
        if (!value) {
            return 'à préciser';
        }
        const [date, time] = value.split('T');
        const [year, month, day] = date.split('-');
        return `${day}/${month}/${year} à ${time}`;
    };

    const recap = form.querySelector('[data-recap]');
    const recapField = (name) => recap.querySelector(`[data-recap-field="${name}"]`);

    const updateRecap = () => {
        if (!recap) {
            return;
        }
        const all = items();
        const first = all[0];
        const last = all[all.length - 1];
        const meters = all.slice(0, -1).map((item) => parseInt(field(item, 'distanceToNextMeters')?.value, 10));
        const price = form.querySelector('[name$="[fullPriceCents]"]').value.trim();
        const seats = form.querySelector('[name$="[seatCapacity]"]').value;

        recapField('route').textContent = all.map(cityName).join(' → ');
        recapField('departure').textContent = formatMoment(field(first, 'departureAt')?.value);
        recapField('arrival').textContent = formatMoment(field(last, 'arrivalAt')?.value);
        recapField('distance').textContent = meters.every((value) => value > 0)
            ? `${Math.round(meters.reduce((sum, value) => sum + value, 0) / 1000).toLocaleString('fr-FR')} km`
            : 'à compléter';
        recapField('seats').textContent = seats ? seats : 'à préciser';
        recapField('price').textContent = price ? `${price} MAD par place` : 'à préciser';
        recap.hidden = false;
    };

    // Nouvelle étape tirée du prototype ; « append » la place en dernier (assistant),
    // sinon elle s'insère avant l'arrivée.
    let created = 0;
    const addStop = (append = false) => {
        created += 1;
        const wrapper = document.createElement('div');
        wrapper.innerHTML = collection.dataset.prototype.replace(/__name__/g, 'new').replace(/__number__/g, `new-${created}`);
        const item = wrapper.firstElementChild;
        const all = items();
        list.insertBefore(item, append ? null : (all[all.length - 1] ?? null));
        refresh();
        return item;
    };

    collection.querySelector('[data-collection-add]').addEventListener('click', () => {
        field(addStop(), 'meetingAddress')?.focus();
    });
    form.addEventListener('itinerary:append', (event) => { event.detail.item = addStop(true); });
    form.addEventListener('itinerary:refresh', refresh);

    collection.addEventListener('click', (event) => {
        const button = event.target.closest('[data-move], [data-collection-remove]');
        if (!button || button.disabled) {
            return;
        }
        const item = button.closest('[data-collection-item]');

        if (button.matches('[data-collection-remove]')) {
            const next = item.nextElementSibling ?? item.previousElementSibling;
            item.remove();
            refresh();
            next?.querySelector('[data-collection-remove]')?.focus();
            return;
        }

        if (button.dataset.move === '-1' && item.previousElementSibling) {
            list.insertBefore(item, item.previousElementSibling);
        } else if (button.dataset.move === '1' && item.nextElementSibling) {
            list.insertBefore(item.nextElementSibling, item);
        }
        refresh();
        // Le focus suit l'étape déplacée ; s'il ne peut plus bouger dans ce sens, il passe à l'autre bouton.
        const same = item.querySelector(`[data-move="${button.dataset.move}"]`);
        (same.disabled ? item.querySelector(`[data-move="${-Number(button.dataset.move)}"]`) : same).focus();
    });

    // Places proposées : limitées à celles du véhicule choisi.
    const vehicle = form.querySelector('[name$="[vehicle]"]');
    const capacity = form.querySelector('[name$="[seatCapacity]"]');
    const syncCapacity = () => {
        const seats = vehicle?.selectedOptions[0]?.dataset.seats;
        if (seats && capacity) {
            capacity.max = seats;
            if (!capacity.value) {
                capacity.value = seats;
            }
        }
    };
    vehicle?.addEventListener('change', syncCapacity);
    syncCapacity();

    form.addEventListener('input', () => { updateHints(); updateRecap(); });
    form.addEventListener('change', updateRecap);
    // Les distances remplies par le calcul d'itinéraire déclenchent aussi la mise à jour.
    form.addEventListener('itinerary:updated', () => { updateHints(); updateRecap(); });
    refresh();
}
