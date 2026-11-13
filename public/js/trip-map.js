// Carte d'un trajet : tuiles OpenStreetMap et tracé OSRM (ou lignes droites si le tracé est indisponible).
const element = document.getElementById('trip-map');

if (element && window.L) {
    const data = JSON.parse(element.dataset.map);
    const map = L.map(element, { scrollWheelZoom: false });

    map.attributionControl?.setPrefix(false);
    L.tileLayer(element.dataset.tiles, {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">contributeurs OpenStreetMap</a>',
    }).addTo(map);

    const theme = getComputedStyle(element);
    const line = L.polyline(data.path, { color: theme.getPropertyValue('--escale-action').trim(), weight: 4, dashArray: data.approximate ? '6 8' : null }).addTo(map);

    data.stops.forEach((stop) => {
        const label = document.createElement('span');
        label.textContent = stop.label;
        L.marker([stop.latitude, stop.longitude], {
            icon: L.divIcon({ className: 'escale-map-stop', iconSize: [14, 14] }),
            title: stop.label,
        })
            .addTo(map)
            .bindTooltip(label);
    });

    if (!data.approximate) {
        map.attributionControl.addAttribution('Itinéraire : <a href="https://project-osrm.org/">OSRM</a>');
    }

    map.fitBounds(line.getBounds(), { padding: [24, 24] });
}
