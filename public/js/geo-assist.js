// Aide à la saisie du formulaire de publication : coordonnées (Nominatim) et distances (OSRM),
// demandées à notre serveur uniquement sur clic. Les champs restent modifiables à la main.
const root = document.querySelector('[data-geo-assist]');

if (root) {
    const field = (item, name) => item.querySelector(`[name$="[${name}]"]`);

    const getJson = async (url) => {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        return { ok: response.ok, data: await response.json() };
    };

    root.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-geocode]');
        if (!button) {
            return;
        }

        const item = button.closest('[data-collection-item]');
        const status = item.querySelector('[data-geo-status]');
        const params = new URLSearchParams({ city: field(item, 'city').value, address: field(item, 'meetingAddress').value });

        button.disabled = true;
        status.textContent = 'Recherche en cours…';

        try {
            const { ok, data } = await getJson(`${root.dataset.geocodeUrl}?${params}`);
            if (ok) {
                field(item, 'latitude').value = data.latitude;
                field(item, 'longitude').value = data.longitude;
                status.textContent = 'Coordonnées trouvées : vérifiez-les avant de publier.';
            } else {
                status.textContent = data.error;
            }
        } catch {
            status.textContent = 'Service indisponible : saisissez les coordonnées vous-même.';
        } finally {
            button.disabled = false;
        }
    });

    const routeButton = document.querySelector('[data-route]');
    const routeStatus = document.querySelector('[data-route-status]');

    routeButton?.addEventListener('click', async () => {
        const items = [...root.querySelectorAll('[data-collection-item]')];
        const points = items.map((item) => `${field(item, 'latitude').value},${field(item, 'longitude').value}`).join(';');

        routeButton.disabled = true;
        routeStatus.textContent = 'Calcul en cours…';

        try {
            const { ok, data } = await getJson(`${root.dataset.routeUrl}?${new URLSearchParams({ points })}`);
            if (!ok) {
                routeStatus.textContent = data.error;
                return;
            }

            data.distances.forEach((meters, index) => {
                field(items[index], 'distanceToNextMeters').value = meters;
            });

            const km = Math.round(data.totalMeters / 1000);
            routeStatus.innerHTML = '';
            routeStatus.append(`Distance totale : ${km} km. Prix suggéré : ${data.suggestedPrice} MAD par place. `);

            const usePrice = document.createElement('button');
            usePrice.type = 'button';
            usePrice.className = 'button button-small';
            usePrice.textContent = 'Utiliser ce prix';
            usePrice.addEventListener('click', () => {
                document.querySelector('[name$="[fullPriceCents]"]').value = data.suggestedPrice;
            });
            routeStatus.append(usePrice);
        } catch {
            routeStatus.textContent = 'Service indisponible : saisissez les distances vous-même.';
        } finally {
            routeButton.disabled = false;
        }
    });
}
