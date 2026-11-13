// Assistant de publication d'un trajet, par-dessus le formulaire complet :
//   1. Trajet : d'où, où, date et heure de départ ;
//   2. Arrêts : villes proposées le long de la route, autre arrêt, horaires calculés et modifiables ;
//   3. Véhicule et places ;  4. Prix conseillé, ajusté par − / + ;  5. Récapitulatif.
// L'assistant ne fait que remplir les champs du formulaire complet, seul envoyé au serveur, qui refait tous les contrôles.
// Les services ne sont appelés que sur action du conducteur (« Rechercher », clic sur la carte, passage à l'étape suivante).
// Sans JavaScript, ou si la carte devient indisponible, le formulaire complet reste utilisable.
// Bloc propre : les scripts classiques de la page partagent la même portée globale.
{
const form = document.querySelector('[data-publish-form]');
const wizard = form?.querySelector('[data-wizard]');
const root = form?.querySelector('[data-geo-assist]');

if (form && wizard && root && window.fetch) {
    const PANELS = ['route', 'stops', 'vehicle', 'price', 'recap'];
    const STOP_MINUTES = 10;
    const panels = Object.fromEntries(PANELS.map((name) => [name, wizard.querySelector(`[data-panel="${name}"]`)]));
    const classic = form.querySelector('[data-classic-form]');
    const progress = document.querySelector('[data-wizard-progress]');
    const list = root.querySelector('[data-collection-list]');
    const items = () => [...list.querySelectorAll('[data-collection-item]')];
    const field = (item, name) => item.querySelector(`[name$="[${name}]"]`);
    const priceInput = form.querySelector('[name$="[fullPriceCents]"]');
    const seatsInput = form.querySelector('[name$="[seatCapacity]"]');
    const dateInput = wizard.querySelector('[data-wizard-date]');
    const timeInput = wizard.querySelector('[data-wizard-time]');
    const legStatus = wizard.querySelector('[data-leg-status]');

    let panel = 'route';
    let route = null;
    let routeKey = '';
    let suggestions = [];
    let fallen = false;

    /* ---------------------------------------------------------------- Outils */

    const getJson = async (url) => {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        let data = {};
        try {
            data = await response.json();
        } catch {
            data = { error: 'Réponse inattendue du serveur.' };
        }
        return { ok: response.ok, status: response.status, data };
    };

    const setStatus = (element, text, state) => {
        if (!element) {
            return;
        }
        element.hidden = !text;
        element.textContent = text || '';
        element.classList.toggle('is-ok', state === 'ok');
        element.classList.toggle('is-error', state === 'error');
    };

    const busy = (button, on) => {
        button.disabled = on;
        button.toggleAttribute('aria-busy', on);
    };

    const panelError = (text) => setStatus(panels[panel].querySelector('[data-panel-error]'), text, 'error');
    const refreshForm = () => form.dispatchEvent(new CustomEvent('itinerary:refresh'));
    const placed = (item) => !!item && field(item, 'latitude').value !== '' && field(item, 'longitude').value !== '';
    const point = (item) => [parseFloat(field(item, 'latitude').value), parseFloat(field(item, 'longitude').value)];
    const cityOf = (item) => item.dataset.city || '';
    const nameOf = (item) => field(item, 'meetingAddress').value.trim() || cityOf(item) || 'Lieu à choisir';
    const km = (meters) => `${Math.round(meters / 1000).toLocaleString('fr-FR')} km`;

    // Montants en centimes entiers : « 199,50 » ↔ 19950, sans calcul en virgule flottante.
    const parseCents = (text) => {
        const match = String(text).trim().match(/^(\d{1,7})(?:[.,](\d{1,2}))?$/);
        return match ? Number(match[1]) * 100 + Number((match[2] || '0').padEnd(2, '0')) : null;
    };
    const formatCents = (cents) => `${Math.floor(cents / 100)},${String(cents % 100).padStart(2, '0')}`;
    const mad = (cents) => `${Math.floor(cents / 100).toLocaleString('fr-FR')},${String(cents % 100).padStart(2, '0')} MAD`;

    // Heures « murales » du Maroc, comptées en minutes sans fuseau : seul le serveur convertit en UTC.
    const toMinutes = (value) => {
        const match = value?.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
        return match ? Date.UTC(+match[1], +match[2] - 1, +match[3], +match[4], +match[5]) / 60000 : null;
    };
    const fromMinutes = (minutes) => {
        const d = new Date(minutes * 60000);
        const pad = (n) => String(n).padStart(2, '0');
        return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}T${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`;
    };
    const round5 = (minutes) => Math.max(5, Math.round(minutes / 5) * 5);
    const dayLabel = (value) => new Date(`${value.slice(0, 10)}T12:00:00Z`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' });

    /* ---------------------------------------------------------------- Étapes du formulaire */

    const setCity = (item, city) => {
        item.dataset.city = city || '';
        // La liste de secours suit la ville trouvée quand elle y figure ; le serveur la détermine de toute façon.
        const select = field(item, 'city');
        const option = [...select.options].find((candidate) => city && candidate.text.localeCompare(city, 'fr', { sensitivity: 'base' }) === 0);
        select.value = option ? option.value : '';
    };

    const fillPlace = (item, place, city) => {
        field(item, 'latitude').value = place.latitude;
        field(item, 'longitude').value = place.longitude;
        // Libellé lisible, modifiable par le conducteur : le nom du lieu et ses premières précisions.
        const label = (place.address || place.name).split(',').map((part) => part.trim()).filter(Boolean).slice(0, 2).join(', ');
        field(item, 'meetingAddress').value = (label || place.name).slice(0, 255);
        setCity(item, city);
        delete item.dataset.centre;
    };

    const newItem = () => {
        const event = new CustomEvent('itinerary:append', { detail: {} });
        form.dispatchEvent(event);
        return event.detail.item;
    };

    // Les arrêts en chemin sont rangés dans l'ordre de la route (de 0, le départ, à 1, l'arrivée).
    const orderStops = () => {
        const all = items();
        const last = all[all.length - 1];
        all.slice(1, -1)
            .sort((a, b) => Number(a.dataset.along || 0.5) - Number(b.dataset.along || 0.5))
            .forEach((item) => list.insertBefore(item, last));
        refreshForm();
    };

    // Ajout d'un arrêt en chemin : créé en dernier par le formulaire, puis replacé avant l'arrivée.
    const addStop = (along) => {
        const all = items();
        const last = all[all.length - 1];
        const item = newItem();
        list.insertBefore(item, last);
        item.dataset.along = String(along);
        return item;
    };

    const removeStop = (item) => {
        item.remove();
        refreshForm();
    };

    // Position d'un point le long du tracé courant, de 0 à 1.
    const alongOf = (latLon) => {
        const path = route?.path;
        if (!path || path.length < 2) {
            return 0.5;
        }
        const dist = (a, b) => Math.hypot(a[0] - b[0], (a[1] - b[1]) * Math.cos(a[0] * Math.PI / 180));
        let cumulative = 0;
        let best = Infinity;
        let at = 0;
        path.forEach((p, index) => {
            if (index > 0) {
                cumulative += dist(path[index - 1], p);
            }
            const d = dist(p, latLon);
            if (d < best) {
                best = d;
                at = cumulative;
            }
        });
        return cumulative > 0 ? Math.min(0.999, Math.max(0.001, at / cumulative)) : 0.5;
    };

    /* ---------------------------------------------------------------- Services */

    // Localité du point : refusé s'il n'est dans aucune localité du Maroc.
    const locate = async (latitude, longitude, describe, status) => {
        setStatus(status, 'Vérification du lieu…');
        const params = new URLSearchParams({ lat: latitude, lon: longitude });
        if (describe) {
            params.set('describe', '1');
        }
        const { ok, status: code, data } = await getJson(`${root.dataset.localityUrl}?${params}`);
        if (code === 503) {
            fallback(data.error);
            return null;
        }
        if (!ok) {
            setStatus(status, data.error, 'error');
            return null;
        }
        setStatus(status, '');
        return data;
    };

    const computeRoute = async ({ along = false } = {}) => {
        const all = items();
        if (all.length < 2 || !all.every(placed)) {
            return null;
        }
        const points = all.map((item) => `${field(item, 'latitude').value},${field(item, 'longitude').value}`).join(';');
        const key = `${points}|${along}`;
        if (key === routeKey && route) {
            return route;
        }
        setStatus(legStatus, 'Calcul de l\'itinéraire…');
        const params = new URLSearchParams({ points });
        if (along) {
            params.set('along', '1');
        }
        const { ok, data } = await getJson(`${root.dataset.routeUrl}?${params}`);
        if (!ok) {
            fallback(data.error);
            return null;
        }
        route = data;
        routeKey = key;
        if (along) {
            suggestions = data.citiesAlong || [];
        }
        data.distances.forEach((meters, index) => {
            const input = field(all[index], 'distanceToNextMeters');
            if (input) {
                input.value = meters;
            }
        });
        setStatus(legStatus, `${km(data.totalMeters)} par la route.`, 'ok');
        drawPath(data.path);
        return data;
    };

    /* ---------------------------------------------------------------- Horaires */

    // Départ choisi par le conducteur, puis heure d'arrivée à chaque étape d'après la durée de conduite,
    // et un arrêt de quelques minutes aux étapes intermédiaires. Une heure modifiée à la main est gardée.
    const computeTimes = () => {
        const all = items();
        if (!dateInput.value || !timeInput.value) {
            return;
        }
        let previous = toMinutes(`${dateInput.value}T${timeInput.value}`);
        field(all[0], 'departureAt').value = fromMinutes(previous);
        const durations = route?.durations || [];

        all.slice(1).forEach((item, offset) => {
            const index = offset + 1;
            const isLast = index === all.length - 1;
            const arrival = field(item, 'arrivalAt');
            const seconds = durations[index - 1];
            if (item.dataset.manualArrival !== '1') {
                arrival.value = seconds > 0 && previous !== null ? fromMinutes(previous + round5(seconds / 60)) : '';
            }
            const arrivedAt = toMinutes(arrival.value);
            if (!isLast) {
                const departure = field(item, 'departureAt');
                if (item.dataset.manualDeparture !== '1') {
                    departure.value = arrivedAt !== null ? fromMinutes(arrivedAt + STOP_MINUTES) : '';
                }
                previous = toMinutes(departure.value);
            }
        });
    };

    /* ---------------------------------------------------------------- Carte */

    let map = null;
    let markers = [];
    let line = null;
    const mapWrap = wizard.querySelector('[data-wizard-map]');

    const initMap = () => {
        if (!window.L || map) {
            return;
        }
        mapWrap.hidden = false;
        map = L.map(mapWrap.querySelector('[data-publish-map]'), { scrollWheelZoom: false }).setView([31.8, -7.1], 6);
        map.attributionControl.setPrefix(false);
        L.tileLayer(root.dataset.tiles, {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">contributeurs OpenStreetMap</a>',
        }).addTo(map);
        map.on('click', (event) => onMapClick(event.latlng.lat.toFixed(6), event.latlng.lng.toFixed(6)));
    };

    const placeMap = (name) => {
        const slot = panels[name]?.querySelector(`[data-map-slot="${name}"]`);
        if (!slot || !window.L) {
            mapWrap.hidden = true;
            return;
        }
        initMap();
        slot.append(mapWrap);
        mapWrap.hidden = false;
        map.invalidateSize();
        drawMarkers();
    };

    const drawMarkers = () => {
        if (!map) {
            return;
        }
        markers.forEach((marker) => marker.remove());
        markers = [];
        const all = items();
        all.forEach((item, index) => {
            if (!placed(item)) {
                return;
            }
            const end = index === 0 || index === all.length - 1;
            markers.push(L.marker(point(item), {
                keyboard: false,
                title: `${index + 1}. ${nameOf(item)}`,
                icon: L.divIcon({ className: `wizard-pin${end ? ' is-active' : ''}`, html: `<span>${index + 1}</span>`, iconSize: [30, 30], iconAnchor: [15, 15] }),
            }).addTo(map));
        });
        const bounds = [...markers.map((marker) => marker.getLatLng()), ...(line ? line.getLatLngs() : [])];
        if (bounds.length > 1) {
            map.fitBounds(bounds, { padding: [32, 32], maxZoom: 13 });
        } else if (bounds.length === 1) {
            map.setView(bounds[0], 12);
        }
    };

    const drawPath = (path) => {
        if (!map) {
            return;
        }
        line?.remove();
        const color = getComputedStyle(map.getContainer()).getPropertyValue('--escale-action').trim() || '#381845';
        line = path && path.length > 1 ? L.polyline(path, { color, weight: 4 }).addTo(map) : null;
        drawMarkers();
    };

    /* ---------------------------------------------------------------- Choix d'un lieu */

    const pickers = Object.fromEntries([...wizard.querySelectorAll('[data-picker]')].map((element) => [element.dataset.picker, element]));
    let activePicker = 'first';
    let replacing = null;

    const pickerItem = (name) => {
        const all = items();
        if (name === 'first') {
            return all[0];
        }
        if (name === 'last') {
            return all[all.length - 1];
        }
        return replacing;
    };

    const showChosen = (name) => {
        const element = pickers[name];
        const item = pickerItem(name);
        if (!element.querySelector('[data-picker-chosen]')) {
            return;
        }
        const chosen = placed(item);
        element.querySelector('[data-picker-chosen]').hidden = !chosen;
        element.querySelector('[data-picker-search]').hidden = chosen;
        if (chosen) {
            element.querySelector('[data-picker-name]').textContent = nameOf(item);
            element.querySelector('[data-picker-city]').textContent = cityOf(item) && cityOf(item) !== nameOf(item) ? cityOf(item) : '';
        }
    };

    // Lieu retenu par le conducteur : départ, arrivée, arrêt ajouté ou arrêt précisé.
    const applyPlace = async (name, place, city) => {
        if (name === 'extra') {
            const item = replacing ?? addStop(alongOf([parseFloat(place.latitude), parseFloat(place.longitude)]));
            fillPlace(item, place, city);
            if (!replacing) {
                orderStops();
            }
            closeExtra();
            await refreshStops();
            return item;
        }
        const item = pickerItem(name);
        fillPlace(item, place, city);
        // Changer de départ ou d'arrivée change la route : les arrêts proposés sont recalculés.
        routeKey = '';
        suggestions = [];
        showChosen(name);
        if (name === 'first' && !placed(pickerItem('last'))) {
            activePicker = 'last';
            pickers.last.querySelector('[data-picker-query]').focus();
        }
        drawMarkers();
        if (placed(pickerItem('first')) && placed(pickerItem('last'))) {
            await computeRoute();
        }
        return item;
    };

    const searchPlaces = async (name, button) => {
        const element = pickers[name];
        const query = element.querySelector('[data-picker-query]');
        const results = element.querySelector('[data-picker-results]');
        const status = element.querySelector('[data-picker-status]');
        const text = query.value.trim();

        if (text.length < 2) {
            setStatus(status, 'Saisissez le nom d\'un lieu ou d\'une ville.', 'error');
            query.focus();
            return;
        }

        busy(button, true);
        setStatus(status, 'Recherche…');
        try {
            const { ok, status: code, data } = await getJson(`${root.dataset.placesUrl}?${new URLSearchParams({ q: text })}`);
            if (code === 503) {
                fallback(data.error);
                return;
            }
            if (!ok) {
                setStatus(status, data.error, 'error');
                return;
            }
            results.replaceChildren(...data.places.map((place) => {
                const li = document.createElement('li');
                const choice = document.createElement('button');
                choice.type = 'button';
                choice.className = 'place-choice';
                const title = document.createElement('strong');
                title.textContent = place.name;
                const address = document.createElement('span');
                address.textContent = place.address;
                choice.append(title, address);
                choice.addEventListener('click', async () => {
                    results.hidden = true;
                    const located = await locate(place.latitude, place.longitude, false, status);
                    if (located) {
                        await applyPlace(name, place, located.city);
                    }
                });
                li.append(choice);
                return li;
            }));
            results.hidden = data.places.length === 0;
            setStatus(status, data.places.length === 0
                ? 'Aucun lieu trouvé au Maroc : essayez un autre nom, ou cliquez sur la carte.'
                : `${data.places.length} lieu${data.places.length > 1 ? 'x' : ''} trouvé${data.places.length > 1 ? 's' : ''} : choisissez le vôtre.`,
            data.places.length === 0 ? 'error' : null);
            results.querySelector('button')?.focus();
        } catch {
            fallback();
        } finally {
            busy(button, false);
        }
    };

    // Suggestions pendant la frappe : villes et points de rendez-vous déjà connus de l'application.
    // Nominatim n'est interrogé que sur demande (« Chercher sur la carte », bouton « Rechercher ») :
    // sa politique d'usage interdit l'autocomplétion.
    const chooseKnown = async (name, known) => {
        const place = known.kind === 'city'
            ? { name: `Centre-ville, ${known.name}`, address: '', latitude: known.latitude, longitude: known.longitude }
            : { name: known.name, address: '', latitude: known.latitude, longitude: known.longitude };
        const element = pickers[name];
        element.querySelector('[data-picker-results]').hidden = true;
        element.querySelector('[data-picker-query]').setAttribute('aria-expanded', 'false');
        const item = await applyPlace(name, place, known.city);
        // Une ville choisie telle quelle reste « au centre-ville », à préciser si besoin.
        if (known.kind === 'city' && item) {
            item.dataset.centre = '1';
            if (panel === 'stops') {
                renderPlan();
            }
        }
    };

    const suggest = async (name, text, request) => {
        const element = pickers[name];
        const query = element.querySelector('[data-picker-query]');
        const results = element.querySelector('[data-picker-results]');
        const status = element.querySelector('[data-picker-status]');
        let places = [];
        try {
            const { ok, data } = await getJson(`${root.dataset.knownUrl}?${new URLSearchParams({ q: text })}`);
            places = ok ? data.places : [];
        } catch {
            places = [];
        }
        if (request !== element.dataset.request) {
            return;
        }

        const rows = places.map((known) => {
            const li = document.createElement('li');
            const choice = document.createElement('button');
            choice.type = 'button';
            choice.className = `place-choice${known.kind === 'city' ? ' is-city' : ''}`;
            const title = document.createElement('strong');
            title.textContent = known.name;
            const detail = document.createElement('span');
            detail.textContent = known.kind === 'city' ? 'Ville · centre-ville' : known.city;
            choice.append(title, detail);
            choice.addEventListener('click', () => chooseKnown(name, known));
            li.append(choice);
            return li;
        });

        // Toujours proposé : chercher le texte exact dans OpenStreetMap, sur demande du conducteur.
        const li = document.createElement('li');
        const more = document.createElement('button');
        more.type = 'button';
        more.className = 'place-choice place-more';
        const title = document.createElement('strong');
        title.textContent = `Chercher « ${text} » sur la carte`;
        const detail = document.createElement('span');
        detail.textContent = 'Gares, adresses, quartiers et lieux publics';
        more.append(title, detail);
        more.addEventListener('click', () => searchPlaces(name, element.querySelector('[data-picker-go]')));
        li.append(more);

        results.replaceChildren(...rows, li);
        results.hidden = false;
        query.setAttribute('aria-expanded', 'true');
        setStatus(status, '');
    };

    const debounce = {};

    Object.entries(pickers).forEach(([name, element]) => {
        const query = element.querySelector('[data-picker-query]');
        const go = element.querySelector('[data-picker-go]');
        const results = element.querySelector('[data-picker-results]');
        go.addEventListener('click', () => searchPlaces(name, go));
        query.addEventListener('input', () => {
            clearTimeout(debounce[name]);
            const text = query.value.trim();
            element.dataset.request = `${Date.now()}`;
            if (text.length < 2) {
                results.hidden = true;
                query.setAttribute('aria-expanded', 'false');
                return;
            }
            const request = element.dataset.request;
            debounce[name] = setTimeout(() => suggest(name, text, request), 120);
        });
        query.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                // Entrée : la première suggestion si la liste est affichée, sinon une recherche complète.
                const first = results.hidden ? null : results.querySelector('.place-choice');
                if (first) {
                    first.click();
                } else {
                    searchPlaces(name, go);
                }
            } else if (event.key === 'ArrowDown' && !results.hidden) {
                event.preventDefault();
                results.querySelector('.place-choice')?.focus();
            } else if (event.key === 'Escape') {
                results.hidden = true;
                query.setAttribute('aria-expanded', 'false');
            }
        });
        // Flèches haut et bas dans la liste des suggestions.
        results.addEventListener('keydown', (event) => {
            const buttons = [...results.querySelectorAll('.place-choice')];
            const index = buttons.indexOf(document.activeElement);
            if (event.key === 'ArrowDown' && index > -1) {
                event.preventDefault();
                buttons[Math.min(index + 1, buttons.length - 1)].focus();
            } else if (event.key === 'ArrowUp' && index > -1) {
                event.preventDefault();
                (index === 0 ? query : buttons[index - 1]).focus();
            } else if (event.key === 'Escape') {
                results.hidden = true;
                query.focus();
            }
        });
        query.addEventListener('focus', () => { activePicker = name; });
        element.querySelector('[data-picker-change]')?.addEventListener('click', () => {
            activePicker = name;
            element.querySelector('[data-picker-chosen]').hidden = true;
            element.querySelector('[data-picker-search]').hidden = false;
            query.value = '';
            query.focus();
        });
    });

    // Clic sur la carte : lieu nommé par le serveur, puis attribué au champ en cours ou ajouté comme arrêt.
    const onMapClick = async (latitude, longitude) => {
        const name = panel === 'route' ? activePicker : 'extra';
        if (panel !== 'route' && panel !== 'stops') {
            return;
        }
        const status = (pickers[name] ?? pickers.first).querySelector('[data-picker-status]');
        try {
            const data = await locate(latitude, longitude, true, panel === 'stops' ? legStatus : status);
            if (!data) {
                return;
            }
            const place = data.place ?? { name: 'Point choisi sur la carte', address: '', latitude, longitude };
            await applyPlace(name, place, data.city);
        } catch {
            fallback();
        }
    };

    /* ---------------------------------------------------------------- Arrêts */

    const extraPicker = pickers.extra;
    const extraOpen = wizard.querySelector('[data-extra-open]');

    const openExtra = (item = null) => {
        replacing = item;
        extraPicker.hidden = false;
        extraOpen.hidden = true;
        extraPicker.querySelector('label').textContent = item ? `Point de rendez-vous à ${cityOf(item)}` : 'Autre arrêt';
        const query = extraPicker.querySelector('[data-picker-query]');
        query.value = item ? cityOf(item) : '';
        query.focus();
        // Préciser un arrêt : les points de rendez-vous déjà connus dans cette ville s'affichent aussitôt.
        if (item) {
            query.dispatchEvent(new Event('input'));
        }
    };

    const closeExtra = () => {
        replacing = null;
        extraPicker.hidden = true;
        extraOpen.hidden = false;
        extraPicker.querySelector('[data-picker-results]').hidden = true;
        setStatus(extraPicker.querySelector('[data-picker-status]'), '');
    };

    extraOpen.addEventListener('click', () => openExtra());
    wizard.querySelector('[data-extra-cancel]').addEventListener('click', closeExtra);

    // Villes proposées le long de la route : cochée, une ville devient un arrêt au centre-ville, à préciser.
    const renderSuggestions = () => {
        const box = wizard.querySelector('[data-suggested]');
        const target = wizard.querySelector('[data-suggested-list]');
        const chosenCities = items().slice(1, -1).map(cityOf);
        box.hidden = suggestions.length === 0;
        target.replaceChildren(...suggestions.map((city) => {
            const label = document.createElement('label');
            label.className = 'suggested-city';
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.checked = chosenCities.some((name) => name.localeCompare(city.name, 'fr', { sensitivity: 'base' }) === 0);
            box.addEventListener('change', async () => {
                if (box.checked) {
                    const item = addStop(city.along);
                    fillPlace(item, { latitude: city.latitude, longitude: city.longitude, name: `Centre-ville, ${city.name}`, address: '' }, city.name);
                    item.dataset.centre = '1';
                    orderStops();
                } else {
                    const item = items().slice(1, -1).find((candidate) => cityOf(candidate).localeCompare(city.name, 'fr', { sensitivity: 'base' }) === 0);
                    if (item) {
                        removeStop(item);
                    }
                }
                await refreshStops();
            });
            const text = document.createElement('span');
            text.textContent = city.name;
            label.append(box, text);
            return label;
        }));
    };

    const timeOf = (value) => value ? value.slice(11, 16) : '';

    // Itinéraire détaillé : lieux, horaires calculés (modifiables), retrait d'un arrêt.
    const renderPlan = () => {
        const all = items();
        const plan = wizard.querySelector('[data-plan]');
        const firstDay = field(all[0], 'departureAt').value.slice(0, 10);
        plan.replaceChildren(...all.map((item, index) => {
            const first = index === 0;
            const last = index === all.length - 1;
            const li = document.createElement('li');
            li.className = `plan-stop${first || last ? ' is-end' : ''}`;

            const head = document.createElement('div');
            head.className = 'plan-head';
            const title = document.createElement('p');
            title.className = 'plan-name';
            title.textContent = nameOf(item);
            const city = document.createElement('p');
            city.className = 'plan-city';
            city.textContent = [cityOf(item), first ? 'Départ' : (last ? 'Arrivée' : 'Arrêt')].filter(Boolean).join(' · ');
            head.append(title, city);

            if (item.dataset.centre === '1') {
                const precise = document.createElement('button');
                precise.type = 'button';
                precise.className = 'link-button';
                precise.textContent = 'Préciser le point de rendez-vous';
                precise.addEventListener('click', () => openExtra(item));
                head.append(precise);
            }
            li.append(head);

            const times = document.createElement('div');
            times.className = 'plan-times';
            const timeField = (labelText, name, flag) => {
                const wrap = document.createElement('div');
                wrap.className = 'plan-time';
                const label = document.createElement('label');
                label.textContent = labelText;
                const input = document.createElement('input');
                input.id = `plan-${index}-${name}`;
                label.htmlFor = input.id;
                input.type = 'time';
                input.step = 300;
                input.className = 'input';
                const value = field(item, name).value;
                input.value = timeOf(value);
                input.addEventListener('change', () => {
                    if (!input.value) {
                        return;
                    }
                    const day = (field(item, name).value || `${firstDay}T`).slice(0, 10);
                    field(item, name).value = `${day}T${input.value}`;
                    item.dataset[flag] = '1';
                    computeTimes();
                    renderPlan();
                });
                wrap.append(label, input);
                if (value && value.slice(0, 10) !== firstDay) {
                    const next = document.createElement('small');
                    next.textContent = `le ${dayLabel(value)}`;
                    wrap.append(next);
                }
                return wrap;
            };
            if (first) {
                const fixed = document.createElement('p');
                fixed.className = 'plan-fixed';
                fixed.textContent = `Départ à ${timeInput.value}`;
                times.append(fixed);
            } else {
                times.append(timeField('Arrivée', 'arrivalAt', 'manualArrival'));
                if (!last) {
                    times.append(timeField('Départ', 'departureAt', 'manualDeparture'));
                }
            }
            li.append(times);

            if (!first && !last) {
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'button button-ghost button-small plan-remove';
                remove.textContent = 'Retirer';
                remove.setAttribute('aria-label', `Retirer l'arrêt ${nameOf(item)}`);
                remove.addEventListener('click', async () => {
                    removeStop(item);
                    await refreshStops();
                });
                li.append(remove);
            }
            return li;
        }));
    };

    // Après tout changement d'arrêts : route, distances, horaires, puis affichage.
    const refreshStops = async () => {
        try {
            await computeRoute();
        } catch {
            fallback();
            return;
        }
        computeTimes();
        renderSuggestions();
        renderPlan();
        drawMarkers();
    };

    /* ---------------------------------------------------------------- Prix */

    const priceSlot = wizard.querySelector('[data-slot="price"]');

    const renderPrice = () => {
        const cents = parseCents(priceInput.value);
        const hint = wizard.querySelector('[data-price-hint]');
        if (route?.suggestedPriceCents) {
            hint.hidden = false;
            hint.textContent = `Prix conseillé : ${mad(route.suggestedPriceCents)} pour ${km(route.totalMeters)}.`;
        }
        const preview = wizard.querySelector('[data-price-preview]');
        const target = wizard.querySelector('[data-price-preview-list]');
        const all = items();
        const meters = all.slice(0, -1).map((item) => parseInt(field(item, 'distanceToNextMeters').value, 10) || 0);
        const total = meters.reduce((sum, value) => sum + value, 0);
        preview.hidden = !(cents > 0 && total > 0);
        if (preview.hidden) {
            return;
        }
        // Même formule que le serveur : prix proportionnel à la distance, arrondi au centime le plus proche.
        const priceFor = (distance) => Math.floor((2 * cents * distance + total) / (2 * total));
        const rows = all.length > 2
            ? all.slice(0, -1).map((item, index) => [`${cityOf(item) || nameOf(item)} → ${cityOf(all[index + 1]) || nameOf(all[index + 1])}`, priceFor(meters[index])])
            : [];
        rows.push([`${cityOf(all[0])} → ${cityOf(all[all.length - 1])} (trajet entier)`, cents]);
        target.replaceChildren(...rows.flatMap(([label, value]) => {
            const dt = document.createElement('dt');
            dt.textContent = label;
            const dd = document.createElement('dd');
            dd.textContent = mad(value);
            return [dt, dd];
        }));
    };

    wizard.querySelectorAll('[data-price-step]').forEach((button) => button.addEventListener('click', () => {
        const current = parseCents(priceInput.value) ?? route?.suggestedPriceCents ?? 0;
        const next = Math.max(100, current + Number(button.dataset.priceStep));
        priceInput.value = formatCents(next);
        renderPrice();
    }));
    priceInput.addEventListener('input', renderPrice);

    /* ---------------------------------------------------------------- Récapitulatif */

    const renderRecap = () => {
        const all = items();
        const departure = field(all[0], 'departureAt').value;
        panels.recap.querySelector('[data-recap-date]').textContent = departure ? dayLabel(departure).replace(/^./, (c) => c.toUpperCase()) : '';
        const plan = panels.recap.querySelector('[data-recap-plan]');
        plan.replaceChildren(...all.map((item, index) => {
            const li = document.createElement('li');
            li.className = `plan-stop${index === 0 || index === all.length - 1 ? ' is-end' : ''}`;
            const time = document.createElement('p');
            time.className = 'plan-clock';
            time.textContent = timeOf(field(item, index === 0 ? 'departureAt' : 'arrivalAt').value);
            const head = document.createElement('div');
            head.className = 'plan-head';
            const title = document.createElement('p');
            title.className = 'plan-name';
            title.textContent = nameOf(item);
            const city = document.createElement('p');
            city.className = 'plan-city';
            city.textContent = cityOf(item);
            head.append(title, city);
            li.append(time, head);
            return li;
        }));
        panels.recap.querySelector('[data-recap-seats]').textContent = seatsInput.value;
        const cents = parseCents(priceInput.value);
        panels.recap.querySelector('[data-recap-price]').textContent = cents ? `${mad(cents)} par place` : '';
        panels.recap.querySelector('[data-recap-distance]').textContent = route ? km(route.totalMeters) : '';
    };

    /* ---------------------------------------------------------------- Navigation */

    const show = (name, focus = true) => {
        panel = name;
        PANELS.forEach((other) => { panels[other].hidden = other !== name; });
        progress.querySelectorAll('[data-progress]').forEach((element) => {
            const position = PANELS.indexOf(element.dataset.progress);
            element.classList.toggle('is-current', element.dataset.progress === name);
            element.classList.toggle('is-done', position < PANELS.indexOf(name));
            if (element.dataset.progress === name) {
                element.setAttribute('aria-current', 'step');
            } else {
                element.removeAttribute('aria-current');
            }
        });
        panelError('');
        placeMap(name);
        if (name === 'route') {
            ['first', 'last'].forEach(showChosen);
        }
        if (name === 'stops') {
            closeExtra();
            renderSuggestions();
            renderPlan();
        }
        if (name === 'price') {
            if (!priceInput.value && route?.suggestedPriceCents) {
                priceInput.value = formatCents(route.suggestedPriceCents);
            }
            renderPrice();
        }
        if (name === 'recap') {
            renderRecap();
        }
        if (focus) {
            const heading = panels[name].querySelector('h2');
            heading.setAttribute('tabindex', '-1');
            heading.focus({ preventScroll: true });
            progress.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };

    // Contrôles légers avant de passer à la suite ; le serveur refait tous les contrôles.
    const checks = {
        route: async () => {
            if (!placed(pickerItem('first'))) {
                return 'Choisissez votre lieu de départ.';
            }
            if (!placed(pickerItem('last'))) {
                return 'Choisissez votre lieu d\'arrivée.';
            }
            if (cityOf(pickerItem('first')) && cityOf(pickerItem('first')) === cityOf(pickerItem('last'))) {
                return 'Le départ et l\'arrivée sont dans la même ville : choisissez une arrivée dans une autre ville.';
            }
            if (!dateInput.value || !timeInput.value) {
                return 'Indiquez la date et l\'heure du départ.';
            }
            if (`${dateInput.value}T${timeInput.value}` < fromMinutes(Math.floor(Date.now() / 60000) - new Date().getTimezoneOffset())) {
                return 'Choisissez un départ à venir.';
            }
            // Villes proposées le long de la route ; celles déjà choisies comme arrêts apparaissent cochées.
            if (suggestions.length === 0) {
                await computeRoute({ along: true });
            } else {
                await computeRoute();
            }
            computeTimes();
            return null;
        },
        stops: async () => {
            const all = items();
            const missing = all.findIndex((item, index) => index > 0 && !field(item, 'arrivalAt').value);
            if (missing > -1) {
                return `Indiquez l'heure d'arrivée à ${nameOf(all[missing])}.`;
            }
            const gap = all.findIndex((item, index) => index > 0 && index < all.length - 1 && !field(item, 'departureAt').value);
            if (gap > -1) {
                return `Indiquez l'heure de départ de ${nameOf(all[gap])}.`;
            }
            return null;
        },
        vehicle: async () => {
            if (!seatsInput.value || !seatsInput.checkValidity()) {
                seatsInput.reportValidity();
                return 'Indiquez un nombre de places entre 1 et le nombre de places du véhicule.';
            }
            return null;
        },
        price: async () => (parseCents(priceInput.value) > 0 ? null : 'Indiquez un prix, par exemple 150 ou 149,50.'),
    };

    wizard.querySelectorAll('[data-panel-next]').forEach((button) => button.addEventListener('click', async () => {
        busy(button, true);
        try {
            const error = await checks[panel]?.();
            if (fallen) {
                return;
            }
            if (error) {
                panelError(error);
                return;
            }
            show(PANELS[PANELS.indexOf(panel) + 1]);
        } catch {
            fallback();
        } finally {
            busy(button, false);
        }
    }));

    wizard.querySelectorAll('[data-panel-back]').forEach((button) => button.addEventListener('click', () => {
        show(PANELS[PANELS.indexOf(panel) - 1]);
    }));

    // Changer la date ou l'heure de départ décale toutes les heures calculées.
    [dateInput, timeInput].forEach((input) => input.addEventListener('change', () => {
        items().forEach((item) => {
            delete item.dataset.manualArrival;
            delete item.dataset.manualDeparture;
        });
        computeTimes();
    }));

    /* ---------------------------------------------------------------- Formulaire complet en secours */

    // Champs déplacés dans l'assistant, remis à leur place si l'on revient au formulaire complet.
    const moved = [];
    const moveInto = (slot, node) => {
        if (node) {
            moved.push([node, node.parentNode, node.nextSibling]);
            slot.append(node);
        }
    };

    const fallback = (message) => {
        if (fallen) {
            return;
        }
        fallen = true;
        moved.reverse().forEach(([node, parent, next]) => parent.insertBefore(node, next));
        wizard.hidden = true;
        progress.hidden = true;
        classic.hidden = false;
        form.noValidate = false;
        items().forEach((item) => {
            item.hidden = false;
            item.querySelector('[data-stop-advanced]').open = true;
        });
        const notice = document.createElement('div');
        notice.className = 'flash flash-error';
        notice.setAttribute('role', 'alert');
        notice.textContent = message || 'La carte est momentanément indisponible : le formulaire complet est affiché. Saisissez les lieux, horaires et distances vous-même.';
        classic.prepend(notice);
        notice.scrollIntoView({ block: 'center' });
    };

    /* ---------------------------------------------------------------- Mise en place */

    // Après un renvoi du serveur avec des erreurs, le formulaire complet les montre à côté de chaque champ.
    if (!form.querySelector('.field--invalid, .field-errors, .flash-error')) {
        moveInto(wizard.querySelector('[data-slot="vehicle"]'), form.querySelector('[name$="[vehicle]"]')?.closest('.field'));
        moveInto(wizard.querySelector('[data-slot="vehicle"]'), seatsInput?.closest('.field'));
        moveInto(priceSlot, priceInput?.closest('.field'));

        const today = new Date();
        today.setMinutes(today.getMinutes() - today.getTimezoneOffset());
        dateInput.min = today.toISOString().slice(0, 10);

        // Étapes déjà connues (trajet retour) : ville reprise de la liste, horaires du départ si présents.
        items().forEach((item) => {
            const select = field(item, 'city');
            if (placed(item) && select.value) {
                item.dataset.city = select.options[select.selectedIndex].text;
            }
        });
        const known = field(items()[0], 'departureAt').value;
        if (known) {
            dateInput.value = known.slice(0, 10);
            timeInput.value = known.slice(11, 16);
        }
        if (placed(pickerItem('first')) && !placed(pickerItem('last'))) {
            activePicker = 'last';
        }

        classic.hidden = true;
        wizard.hidden = false;
        progress.hidden = false;
        // Le serveur valide tout : la validation du navigateur bloquerait sur des champs masqués.
        form.noValidate = true;

        // Entrée dans un champ : étape suivante plutôt qu'un envoi prématuré.
        form.addEventListener('keydown', (event) => {
            if (fallen || event.key !== 'Enter' || panel === 'recap' || !event.target.matches('input:not([type=search])')) {
                return;
            }
            event.preventDefault();
            panels[panel].querySelector('[data-panel-next]')?.click();
        });

        show('route', false);
    }
}
}
