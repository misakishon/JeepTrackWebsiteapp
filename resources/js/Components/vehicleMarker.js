// Position belongs to Mapbox; scale and artwork rotation belong to separate children.
const states = new WeakMap();
export const markerScale = zoom => Math.max(0.45, Math.min(1.3, 0.45 + (zoom - 8) * 0.10625));
export const shortestTurn = (from, to) => ((to - from) % 360 + 540) % 360 - 180;
export function travelHeading(from, to) {
    const rad = Math.PI / 180;
    const delta = (to[0] - from[0]) * rad;
    const a = from[1] * rad, b = to[1] * rad;
    return (Math.atan2(Math.sin(delta) * Math.cos(b), Math.cos(a) * Math.sin(b) - Math.sin(a) * Math.cos(b) * Math.cos(delta)) / rad + 360) % 360;
}

export function createVehicleElement(simulation = false) {
    const element = document.createElement('button');
    element.type = 'button';
    element.className = 'mb-vehicle' + (simulation ? ' simulation' : '');
    // Google Material Icons airport_shuttle (Apache-2.0), artwork faces east.
    // Source: https://github.com/google/material-design-icons/tree/master/src/places/airport_shuttle
    // The fixed -90 degree offset makes the travel heading start at north.
    element.innerHTML = '<span class="mb-vehicle-scale"><span class="mb-vehicle-art" aria-hidden="true"><svg class="mb-vehicle-image" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M17 5H3c-1.1 0-2 .89-2 2v9h2c0 1.65 1.34 3 3 3s3-1.35 3-3h5.5c0 1.65 1.34 3 3 3s3-1.35 3-3H23v-5l-6-6zM3 11V7h4v4H3zm3 6.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm7-6.5H9V7h4v4zm4.5 6.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM15 11V7h1l4 4h-5z"/></svg></span>' + (simulation ? '<span class="mb-sim-badge">SIM</span>' : '') + '</span>';
    states.set(element, { heading: 0, displayed: 0, initialized: false, point: null, time: null });
    return element;
}

export function updateVehicleHeading(element, point, suppliedHeading, stopped = false) {
    const state = states.get(element);
    if (!state) return;
    const previous = state.point;
    // Ignore sub-metre GPS jitter, but retain the previous fix until movement accumulates.
    const moved = previous && Math.hypot((point[0] - previous[0]) * Math.cos(point[1] * Math.PI / 180), point[1] - previous[1]) * 111320 >= 1;
    const valid = suppliedHeading !== null && suppliedHeading !== undefined && suppliedHeading !== '' && Number.isFinite(Number(suppliedHeading)) && Number(suppliedHeading) >= 0 && Number(suppliedHeading) < 360;
    if ((!stopped || !state.initialized) && (!previous || moved || !state.initialized) && (valid || moved)) {
        state.heading = valid ? Number(suppliedHeading) : travelHeading(previous, point);
        if (!state.initialized) state.displayed = state.heading;
        state.initialized = true;
    }
    if (!previous || moved) state.point = [...point];
}

export function renderVehicleMarkers(map, now = performance.now()) {
    const scale = markerScale(map.getZoom());
    map.getContainer().querySelectorAll('.mb-vehicle').forEach(element => {
        const state = states.get(element);
        if (!state) return;
        const dt = state.time === null ? 0 : Math.min(0.1, (now - state.time) / 1000);
        state.time = now;
        const delta = shortestTurn(state.displayed, state.heading);
        state.displayed += delta * (1 - Math.exp(-dt / 0.12));
        if (Math.abs(delta) > 0.05) map.triggerRepaint();
        else state.displayed = state.heading;
        element.querySelector('.mb-vehicle-scale').style.transform = `scale(${scale})`;
        element.querySelector('.mb-vehicle-art').style.transform = `rotate(${state.displayed - map.getBearing()}deg)`;
    });
}
