// Carte d'accueil : tracé du prochain départ sur un fond OpenStreetMap atténué.
// Elle sert de décor : pas d'interaction, la carte complète reste sur la page du trajet.
const element = document.getElementById('home-map');

if (element && window.L) {
    const data = JSON.parse(element.dataset.map);
    const map = L.map(element, {
        zoomControl: false,
        dragging: false,
        scrollWheelZoom: false,
        doubleClickZoom: false,
        boxZoom: false,
        keyboard: false,
        touchZoom: false,
        attributionControl: false,
    });

    map.attributionControl?.setPrefix(false);
    L.tileLayer(element.dataset.tiles, { maxZoom: 19 }).addTo(map);

    // Trait clair sous le tracé pour qu'il se détache du fond de carte.
    L.polyline(data.path, { color: '#ffffff', weight: 9, opacity: 0.9 }).addTo(map);
    const routeColor = getComputedStyle(element).getPropertyValue('--escale-action').trim();
    const line = L.polyline(data.path, { color: routeColor, weight: 5, dashArray: data.approximate ? '6 8' : null }).addTo(map);

    data.stops.forEach((stop, index) => {
        const end = index === 0 || index === data.stops.length - 1;
        L.marker([stop.latitude, stop.longitude], {
            icon: L.divIcon({ className: 'escale-map-stop', iconSize: end ? [15, 15] : [12, 12] }),
            keyboard: false,
            interactive: false,
        }).addTo(map);
    });

    const fit = () => {
        map.invalidateSize();
        map.fitBounds(line.getBounds(), { paddingTopLeft: [28, 20], paddingBottomRight: [28, 42] });
    };
    fit();
    window.addEventListener('resize', fit);
}
