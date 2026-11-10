// Carte d'un trajet : tuiles OpenStreetMap et tracé OSRM (ou lignes droites si le tracé est indisponible).
const element = document.getElementById('trip-map');

if (element && window.L) {
    const data = JSON.parse(element.dataset.map);
    const map = L.map(element, { scrollWheelZoom: false });

    L.tileLayer(element.dataset.tiles, {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">contributeurs OpenStreetMap</a>',
    }).addTo(map);

    const line = L.polyline(data.path, { color: '#0b6e4f', weight: 4, dashArray: data.approximate ? '6 8' : null }).addTo(map);

    data.stops.forEach((stop) => {
        L.circleMarker([stop.latitude, stop.longitude], { radius: 7, color: '#08533b', fillColor: '#ffffff', fillOpacity: 1, weight: 3 })
            .addTo(map)
            .bindTooltip(stop.label);
    });

    if (!data.approximate) {
        map.attributionControl.addAttribution('Itinéraire : <a href="https://project-osrm.org/">OSRM</a>');
    }

    map.fitBounds(line.getBounds(), { padding: [24, 24] });
}
