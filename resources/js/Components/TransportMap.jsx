import React, { useEffect, useRef, useState } from 'react';
import roadRoutes from '../roadRoutes.json';
import './TransportMap.css';
import { createVehicleElement, updateVehicleHeading, renderVehicleMarkers } from './vehicleMarker';

// Mapbox supplies the base map.
const SDK = 'https://api.mapbox.com/mapbox-gl-js/v3.15.0/mapbox-gl.js';
const CSS = 'https://api.mapbox.com/mapbox-gl-js/v3.15.0/mapbox-gl.css';
let sdkPromise;
function loadMapbox() {
    if (window.mapboxgl) return Promise.resolve(window.mapboxgl);
    if (!sdkPromise) {
        sdkPromise = new Promise((resolve, reject) => {
            if (!document.querySelector('[data-mapbox-css]')) {
                const link = document.createElement('link');
                link.rel = 'stylesheet'; link.href = CSS; link.dataset.mapboxCss = 'true';
                document.head.appendChild(link);
            }
            const script = document.createElement('script');
            script.src = SDK; script.async = true;
            script.onload = () => window.mapboxgl ? resolve(window.mapboxgl) : reject(new Error('Map SDK unavailable'));
            script.onerror = () => { script.remove(); reject(new Error('Mapbox could not be reached')); };
            document.head.appendChild(script);
        }).catch(error => { sdkPromise = undefined; throw error; });
    }
    return sdkPromise;
}

export const ROUTE_WAYPOINTS = {
    1: roadRoutes[1] || roadRoutes['1'] || [],
    2: roadRoutes[2] || roadRoutes['2'] || [],
    3: roadRoutes[3] || roadRoutes['3'] || [],
    4: roadRoutes[4] || roadRoutes['4'] || [],
    5: roadRoutes[5] || roadRoutes['5'] || [],
};

export const ROUTE_COLORS = {
    1: '#2463eb', // Batac–Laoag (Blue)
    2: '#0d9488', // Batac–Paoay (Teal)
    3: '#7c3aed', // Paoay–Laoag (Purple)
    4: '#ea580c', // Laoag–San Nicolas (Orange)
    5: '#0284c7', // San Nicolas–Batac (Sky Blue)
};

export const ALL_ROUTES_LINES = [
    { id: 1, name: 'Batac–Laoag', line: ROUTE_WAYPOINTS[1], color: ROUTE_COLORS[1] },
    { id: 2, name: 'Batac–Paoay', line: ROUTE_WAYPOINTS[2], color: ROUTE_COLORS[2] },
    { id: 3, name: 'Paoay–Laoag', line: ROUTE_WAYPOINTS[3], color: ROUTE_COLORS[3] },
    { id: 4, name: 'Laoag–San Nicolas', line: ROUTE_WAYPOINTS[4], color: ROUTE_COLORS[4] },
    { id: 5, name: 'San Nicolas–Batac', line: ROUTE_WAYPOINTS[5], color: ROUTE_COLORS[5] },
];

export const DESIGNATED_STOPS = {
    1: [
        { name: 'Batac Terminal', coord: [120.5636, 18.0553], isTerminal: true },
        { name: 'San Mateo Stop', coord: [120.5680, 18.0850] },
        { name: 'San Nicolas Plaza Stop', coord: [120.5938, 18.1729] },
        { name: 'Robinsons Laoag Stop', coord: [120.5926, 18.1788] },
        { name: 'Laoag Terminal', coord: [120.5931, 18.1975], isTerminal: true },
    ],
    2: [
        { name: 'Batac Terminal', coord: [120.5636, 18.0553], isTerminal: true },
        { name: 'Batac City Hall', coord: [120.5641, 18.0567] },
        { name: 'Quiling Stop', coord: [120.5494, 18.0568] },
        { name: 'Paoay Church / Terminal', coord: [120.5215, 18.0615], isTerminal: true },
    ],
    3: [
        { name: 'Paoay Church / Terminal', coord: [120.5215, 18.0615], isTerminal: true },
        { name: 'Suba / Lake Paoay Stop', coord: [120.5394, 18.1200] },
        { name: 'Laoag Public Market', coord: [120.5931, 18.1878] },
        { name: 'Laoag Terminal', coord: [120.5931, 18.1975], isTerminal: true },
    ],
    4: [
        { name: 'Laoag Terminal', coord: [120.5931, 18.1975], isTerminal: true },
        { name: 'Gilbert Bridge Stop', coord: [120.5908, 18.1913] },
        { name: 'Robinsons Laoag Stop', coord: [120.5926, 18.1788] },
        { name: 'San Nicolas Plaza Stop', coord: [120.5938, 18.1729], isTerminal: true },
    ],
    5: [
        { name: 'San Nicolas Plaza Stop', coord: [120.5938, 18.1729], isTerminal: true },
        { name: 'San Mateo Stop', coord: [120.5680, 18.0850] },
        { name: 'Batac City Hall', coord: [120.5641, 18.0567] },
        { name: 'Batac Terminal', coord: [120.5636, 18.0553], isTerminal: true },
    ]
};

// Initial distances in meters so vehicles appear distributed and reach stops within 15-30s
export const INITIAL_STAGGER_METERS = {
    1: 3750,  // Approaches San Mateo Stop (3,919m)
    2: 380,   // Approaches Batac City Hall (494m)
    3: 7630,  // Approaches Suba / Lake Paoay (7,799m)
    4: 650,   // Approaches Gilbert Bridge Stop (773m)
    5: 10640, // Approaches San Mateo Stop (10,808m)
};

export const DEFAULT_SPEED_KMH = 20.0; // Default simulated speed ~20 km/h

/**
 * Haversine formula to compute great-circle distance between two [lng, lat] coordinates in meters.
 */
export function distanceMeters(coordA, coordB) {
    if (!coordA || !coordB) return 0;
    const toRad = Math.PI / 180;
    const lat1 = coordA[1] * toRad;
    const lat2 = coordB[1] * toRad;
    const dLat = (coordB[1] - coordA[1]) * toRad;
    const dLng = (coordB[0] - coordA[0]) * toRad;
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(lat1) * Math.cos(lat2) *
              Math.sin(dLng / 2) * Math.sin(dLng / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return 6371000 * c;
}

/**
 * Robustly normalizes route ID from integer, route-X string, or route name.
 */
export function normalizeRouteId(rawId, name) {
    if (rawId != null) {
        const parsed = typeof rawId === 'number' ? rawId : parseInt(String(rawId).replace(/[^0-9]/g, ''), 10);
        if (Number.isFinite(parsed) && parsed >= 1 && parsed <= 5) {
            return parsed;
        }
    }
    if (!name) return 1;
    const n = String(name).toLowerCase().replace(/[\u2013\u2014\u2212-]/g, ' ');
    if (n.includes('san nicolas') && n.includes('batac')) return 5;
    if (n.includes('laoag') && n.includes('san nicolas')) return 4;
    if (n.includes('paoay') && n.includes('laoag')) return 3;
    if (n.includes('batac') && n.includes('paoay')) return 2;
    if (n.includes('batac') && n.includes('laoag')) return 1;
    return 1;
}

export function geometry(name) {
    const rId = normalizeRouteId(null, name);
    return ROUTE_WAYPOINTS[rId] || ROUTE_WAYPOINTS[1];
}

const ROUTE_METRIC_CACHE = {};

/**
 * Computes segment distances, cumulative meters, and total distance in meters.
 */
export function getRouteMetricData(routeId, waypoints) {
    const pts = waypoints && waypoints.length >= 2 ? waypoints : (ROUTE_WAYPOINTS[routeId] || ROUTE_WAYPOINTS[1]);
    const key = String(routeId || 'route') + '_' + pts.length;
    if (ROUTE_METRIC_CACHE[key]) {
        return ROUTE_METRIC_CACHE[key];
    }

    const segMeters = [];
    const cumMeters = [0];
    let totalMeters = 0;
    for (let i = 0; i < pts.length - 1; i++) {
        const d = distanceMeters(pts[i], pts[i + 1]);
        segMeters.push(d);
        totalMeters += d;
        cumMeters.push(totalMeters);
    }
    const data = { segMeters, cumMeters, totalMeters, waypoints: pts };
    ROUTE_METRIC_CACHE[key] = data;
    return data;
}

/**
 * Accurately finds the interpolated [lng, lat] coordinate and travel bearing at a given distance in meters.
 */
export function getPointAtDistance(metricData, distance) {
    const { waypoints, cumMeters, totalMeters } = metricData;
    if (!waypoints || waypoints.length === 0) {
        return { coordinate: [120.5636, 18.0553], bearing: 0 };
    }
    if (waypoints.length === 1 || totalMeters <= 0.00001) {
        return { coordinate: waypoints[0], bearing: 0 };
    }

    const clampedDist = Math.max(0, Math.min(totalMeters, distance));

    let low = 0;
    let high = cumMeters.length - 1;
    let idx = 0;
    while (low <= high) {
        const mid = (low + high) >> 1;
        if (cumMeters[mid] <= clampedDist) {
            idx = mid;
            low = mid + 1;
        } else {
            high = mid - 1;
        }
    }
    if (idx >= waypoints.length - 1) {
        idx = waypoints.length - 2;
    }

    const segStart = cumMeters[idx];
    const segEnd = cumMeters[idx + 1];
    const segSpan = segEnd - segStart;
    const fraction = segSpan > 0 ? (clampedDist - segStart) / segSpan : 0;

    const p1 = waypoints[idx];
    const p2 = waypoints[idx + 1];
    const lng = p1[0] + (p2[0] - p1[0]) * fraction;
    const lat = p1[1] + (p2[1] - p1[1]) * fraction;

    // Calculate heading / bearing
    const dLng = (p2[0] - p1[0]) * Math.cos(lat * Math.PI / 180);
    const dLat = p2[1] - p1[1];
    const bearing = (Math.atan2(dLng, dLat) * 180 / Math.PI + 360) % 360;

    const result = [lng, lat];
    result.bearing = bearing;
    return { coordinate: result, bearing, segIndex: idx };
}

/**
 * Finds intermediate designated stops along the route with their meter positions.
 */
export function getRouteStopsWithDistances(routeId, metricData, externalStops = []) {
    const { waypoints, cumMeters, totalMeters } = metricData;
    const baseStops = DESIGNATED_STOPS[routeId] || [];
    const combinedStops = [...baseStops];

    if (Array.isArray(externalStops)) {
        externalStops.forEach(s => {
            const lng = Number(s.longitude || s.lng);
            const lat = Number(s.latitude || s.lat);
            if (Number.isFinite(lng) && Number.isFinite(lat)) {
                if (!combinedStops.some(b => distanceMeters(b.coord, [lng, lat]) < 80)) {
                    combinedStops.push({ name: s.label || s.stop_name || 'Designated Stop', coord: [lng, lat] });
                }
            }
        });
    }

    const stopsWithDist = [];
    combinedStops.forEach((stop, i) => {
        let bestDist = Infinity;
        let bestCum = 0;
        for (let w = 0; w < waypoints.length; w++) {
            const d = distanceMeters(stop.coord, waypoints[w]);
            if (d < bestDist) {
                bestDist = d;
                bestCum = cumMeters[w];
            }
        }
        // Only consider as an intermediate pause stop if within 400m of route
        // and between 35m and (totalMeters - 35m) so we don't pause at start or finish terminals
        if (bestDist < 400 && bestCum > 35 && bestCum < (totalMeters - 35) && !stop.isTerminal) {
            stopsWithDist.push({
                id: `stop-${routeId}-${i}-${Math.round(bestCum)}`,
                name: stop.name,
                distance: bestCum,
                coord: stop.coord,
            });
        }
    });

    stopsWithDist.sort((a, b) => a.distance - b.distance);
    return stopsWithDist;
}

/**
 * External helper for static or initial point computation.
 */
export function computeSimulatedPoint(v) {
    const rId = normalizeRouteId(v.route_id, v.route_name);
    const waypoints = (ROUTE_WAYPOINTS[rId] && ROUTE_WAYPOINTS[rId].length > 10)
        ? ROUTE_WAYPOINTS[rId]
        : ((v.waypoints && v.waypoints.length > 10) ? v.waypoints : (ROUTE_WAYPOINTS[1] || []));

    const metricData = getRouteMetricData(rId, waypoints);
    const initDist = v.initial_distance != null
        ? v.initial_distance
        : (INITIAL_STAGGER_METERS[rId] || (v.offset_seconds ? v.offset_seconds * 30 : 0));

    const { coordinate, bearing } = getPointAtDistance(metricData, initDist);
    coordinate.bearing = bearing;
    return coordinate;
}

function buildPopupHtml(v, statusText, statusType = 'moving') {
    const dotClass = statusType === 'paused' ? 'paused' : (statusType === 'finished' ? 'finished' : '');
    return `
        <div class="sim-popup">
            <div class="sim-popup-title">${v.plate_number} (${v.vehicle_type || 'Jeepney'})</div>
            <div class="sim-popup-row"><strong>Route:</strong> <span>${v.route_name}</span></div>
            <div class="sim-popup-row"><strong>Driver:</strong> <span>${v.driver_name || 'Demo Driver'}</span></div>
            <div class="sim-popup-row sim-popup-status">
                <span class="sim-status-dot ${dotClass}"></span>
                <span>${statusText}</span>
            </div>
            <div class="sim-badge-tag">Demo simulation — not real GPS</div>
        </div>
    `;
}

export default function TransportMap({
    small = false,
    route = ['Batac–Laoag', 'Batac Terminal', 'Laoag Public Market'],
    fallback,
    mapData = null,
    simulationSpeed = DEFAULT_SPEED_KMH, // Configurable simulated speed, defaults to 20 km/h
}) {
    const container = useRef(null);
    const mapRef = useRef(null);
    const glRef = useRef(null);
    const vehicleMarker = useRef(null);
    const vehiclePopup = useRef(null);
    const fleetMarkers = useRef({});
    const simMarkersRef = useRef({});
    const simPopupsRef = useRef({});
    const simStatesRef = useRef({});
    const following = useRef(true);
    const [status, setStatus] = useState('loading');
    const [attempt, retry] = useState(0);

    const name = route[0];
    const dataKey = JSON.stringify(mapData ? {
        routeId: mapData.routeId,
        allRoutes: !!mapData.allRoutesLines,
        stopsCount: mapData.stops?.length,
        simCount: mapData.simulatedVehicles?.length
    } : null);

    const knownStops = (mapData?.stops || []).filter(p =>
        Number.isFinite(Number(p.longitude)) &&
        Number.isFinite(Number(p.latitude)) &&
        Math.abs(Number(p.longitude)) <= 180 &&
        Math.abs(Number(p.latitude)) <= 90
    );
    const knownLocation = mapData?.location;
    const simVehicles = mapData?.simulatedVehicles || [];
    const liveVehicles = mapData?.liveVehicles || [];

    const realPoints = [
        ...knownStops.map(p => [Number(p.longitude), Number(p.latitude)]),
        ...(knownLocation ? [[Number(knownLocation.longitude), Number(knownLocation.latitude)]] : []),
        ...simVehicles.map(v => [Number(v.longitude), Number(v.latitude)]),
        ...liveVehicles.map(v => [Number(v.longitude), Number(v.latitude)])
    ];

    // Expose developer / test controls
    useEffect(() => {
        window.__demoSimulation = {
            setSpeed: (kmh) => {
                window.__simSpeedKmh = Math.max(10, Math.min(30, Number(kmh)));
            },
            getSpeed: () => window.__simSpeedKmh || simulationSpeed || DEFAULT_SPEED_KMH,
            getStates: () => simStatesRef.current,
            setVehicleDistance: (id, distMeters) => {
                if (simStatesRef.current[id]) {
                    simStatesRef.current[id].distanceMeters = distMeters;
                    simStatesRef.current[id].isFinished = false;
                    simStatesRef.current[id].isPaused = false;
                    simStatesRef.current[id].pauseRemaining = 0;
                }
            }
        };
    }, [simulationSpeed]);

    useEffect(() => {
        let disposed = false;
        let instance;
        setStatus('loading');

        const timeout = setTimeout(() => {
            if (!disposed) setStatus('error');
        }, 12000);

        const token = import.meta.env.VITE_MAPBOX_ACCESS_TOKEN;
        if (!token) {
            clearTimeout(timeout);
            setStatus('error');
            return;
        }

        loadMapbox().then(gl => {
            if (disposed) return;
            if (!gl.supported()) throw new Error('WebGL unavailable');

            gl.accessToken = token;
            glRef.current = gl;
            following.current = true;

            const follow = mapData?.followVehicle && knownLocation;
            const rId = normalizeRouteId(mapData?.routeId, name);
            const activeRoadLine = mapData?.routeLine || ROUTE_WAYPOINTS[rId] || geometry(name);

            const centerPoint = follow
                ? [Number(knownLocation.longitude), Number(knownLocation.latitude)]
                : (realPoints.length > 0 ? realPoints[0] : (activeRoadLine[0] || [120.5636, 18.0553]));

            instance = new gl.Map({
                container: container.current,
                style: 'mapbox://styles/mapbox/streets-v12',
                center: centerPoint,
                zoom: follow ? 14 : 11,
                attributionControl: true
            });
            mapRef.current = instance;
            instance.on('render', () => renderVehicleMarkers(instance));
            window.__transportMap = instance;

            const boundsPoints = (realPoints && realPoints.length > 0)
                ? realPoints
                : (activeRoadLine && activeRoadLine.length > 0 ? activeRoadLine : ROUTE_WAYPOINTS[1]);

            if (boundsPoints.length && !follow) {
                const bounds = boundsPoints.reduce((b, p) => b.extend(p), new gl.LngLatBounds(boundsPoints[0], boundsPoints[0]));
                instance.fitBounds(bounds, { padding: { top: 55, bottom: 60, left: 45, right: 45 }, duration: 0, maxZoom: 14 });
            }

            instance.addControl(new gl.GeolocateControl({ positionOptions: { enableHighAccuracy: true }, trackUserLocation: false, showUserHeading: false }), 'top-right');

            const addStopMarker = (point, label, vehicle = false) => {
                const element = vehicle ? createVehicleElement() : document.createElement('button');
                element.type = 'button';
                if (!vehicle) element.className = 'mb-stop';
                element.setAttribute('aria-label', label);
                if (vehicle) {
                    const index = activeRoadLine.indexOf(point);
                    const next = activeRoadLine[index + 1];
                    if (next) {
                        updateVehicleHeading(element, point);
                        updateVehicleHeading(element, next);
                    }
                }
                const popup = new gl.Popup({ offset: 22, maxWidth: '280px' }).setText(label);
                new gl.Marker({ element, anchor: 'center' }).setLngLat(point).setPopup(popup).addTo(instance);
            };

            if (mapData) {
                knownStops.forEach(stop => addStopMarker([Number(stop.longitude), Number(stop.latitude)], stop.label || stop.stop_name || 'Route stop'));
            } else {
                const previewPoints = activeRoadLine.length ? activeRoadLine : ROUTE_WAYPOINTS[1];
                addStopMarker(previewPoints[0], route[1] + ' · demo boarding stop');
                addStopMarker(previewPoints[previewPoints.length - 1], route[2] + ' · demo destination');
                addStopMarker(previewPoints[Math.floor(previewPoints.length / 2)], 'IJ-087 · illustrative sample position, not live GPS', true);
            }

            const drawRoutes = () => {
                if (disposed) return;
                let routesToDraw = mapData?.allRoutesLines;
                if (!routesToDraw) {
                    if (mapData?.routeLine) {
                        routesToDraw = [{ id: 'main', line: mapData.routeLine, color: ROUTE_COLORS[rId] || '#2463eb' }];
                    } else if (name.includes('Ilocos Norte') || name.includes('Fleet Map') || (simVehicles.length > 1 && !mapData?.routeId && !knownLocation)) {
                        routesToDraw = ALL_ROUTES_LINES;
                    } else {
                        const targetId = normalizeRouteId(mapData?.routeId, name);
                        const rLine = ROUTE_WAYPOINTS[targetId] || geometry(name);
                        routesToDraw = [{ id: 'route-' + targetId, line: rLine, color: ROUTE_COLORS[targetId] || '#2463eb' }];
                    }
                }

                routesToDraw.forEach((r, idx) => {
                    const srcId = 'route-src-' + (r.id != null ? r.id : idx);
                    if (r.line?.length > 1 && !instance.getSource(srcId)) {
                        instance.addSource(srcId, {
                            type: 'geojson',
                            data: { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: r.line } }
                        });
                        instance.addLayer({
                            id: srcId + '-casing',
                            type: 'line',
                            source: srcId,
                            paint: { 'line-color': '#fff', 'line-width': 8 }
                        });
                        instance.addLayer({
                            id: srcId + '-line',
                            type: 'line',
                            source: srcId,
                            layout: { 'line-join': 'round', 'line-cap': 'round' },
                            paint: { 'line-color': r.color || '#2463eb', 'line-width': 4.5, 'line-opacity': 0.85 }
                        });
                    }
                });
            };

            instance.on('load', () => {
                drawRoutes();
                clearTimeout(timeout);
                setStatus('ready');
            });

            instance.on('error', () => {
                if (!disposed && !instance.loaded()) {
                    clearTimeout(timeout);
                    setStatus('error');
                }
            });

            instance.on('dragstart', () => { following.current = false; });
        }).catch(() => {
            if (!disposed) {
                clearTimeout(timeout);
                setStatus('error');
            }
        });

        return () => {
            disposed = true;
            clearTimeout(timeout);
            Object.values(simMarkersRef.current).forEach(m => m.remove());
            simMarkersRef.current = {};
            simPopupsRef.current = {};
            Object.values(fleetMarkers.current).forEach(record => record.marker.remove());
            fleetMarkers.current = {};
            if (instance) instance.remove();
            mapRef.current = null;
            vehicleMarker.current = null;
            vehiclePopup.current = null;
            window.__transportMap = null;
            window.__simMarkers = null;
        };
    }, [name, attempt, dataKey]);

    // Real GPS fix marker update (kept completely separate from simulation)
    const locationKey = JSON.stringify(knownLocation ? {
        latitude: knownLocation.latitude,
        longitude: knownLocation.longitude,
        timestamp: knownLocation.timestamp,
        is_stale: knownLocation.is_stale,
        point_id: knownLocation.point_id,
        heading: knownLocation.heading,
        bearing: knownLocation.bearing,
        speed: knownLocation.speed
    } : null);

    useEffect(() => {
        if (!mapData || status !== 'ready' || !mapRef.current || !glRef.current) return;
        if (!knownLocation) {
            vehicleMarker.current?.remove();
            vehicleMarker.current = null;
            vehiclePopup.current = null;
            return;
        }
        const gl = glRef.current;
        const map = mapRef.current;
        const point = [Number(knownLocation.longitude), Number(knownLocation.latitude)];
        const label = (mapData.vehicleLabel || 'Vehicle') + ' · ' + (knownLocation.is_stale ? 'stale GPS, last known position' : 'live GPS') + ' · ' + new Date(knownLocation.timestamp).toLocaleString('en-PH', { timeZone: 'Asia/Manila' });

        if (!vehicleMarker.current) {
            const element = createVehicleElement();
            vehiclePopup.current = new gl.Popup({ offset: 22, maxWidth: '280px' });
            vehicleMarker.current = new gl.Marker({ element, anchor: 'center' }).setPopup(vehiclePopup.current).setLngLat(point).addTo(map);
        } else {
            vehicleMarker.current.setLngLat(point);
        }
        updateVehicleHeading(vehicleMarker.current.getElement(), point, knownLocation.heading ?? knownLocation.bearing, Number(knownLocation.speed) === 0 && knownLocation.speed != null);
        map.triggerRepaint();
        vehicleMarker.current.getElement().setAttribute('aria-label', label);
        vehicleMarker.current.getElement().classList.toggle('stale', !!knownLocation.is_stale);
        vehiclePopup.current.setText(label);

        if (mapData.followVehicle && following.current) {
            map.easeTo({ center: point, zoom: Math.max(map.getZoom(), 14), duration: 800 });
        }
    }, [locationKey, status, dataKey]);

    // Fleet views show the same canonical trip fixes as individual tracking views.
    const liveKey = JSON.stringify(liveVehicles);
    useEffect(() => {
        if (status !== 'ready' || !mapRef.current || !glRef.current) return;
        const map = mapRef.current, gl = glRef.current;
        const ids = new Set(liveVehicles.map(v => String(v.trip_id)));
        Object.entries(fleetMarkers.current).forEach(([id, record]) => {
            if (!ids.has(id)) { record.marker.remove(); delete fleetMarkers.current[id]; }
        });
        liveVehicles.forEach(v => {
            const id = String(v.trip_id), point = [Number(v.longitude), Number(v.latitude)];
            if (!point.every(Number.isFinite)) return;
            const stale = v.is_stale || Date.now() - new Date(v.timestamp).getTime() > 30000;
            const label = `${v.vehicleLabel || 'Vehicle'} · ${stale ? 'stale GPS, last known position' : 'live GPS'}`;
            let record = fleetMarkers.current[id];
            if (!record) {
                const element = createVehicleElement();
                const popup = new gl.Popup({ offset: 22, maxWidth: '280px' });
                record = { marker: new gl.Marker({ element, anchor: 'center' }).setPopup(popup).setLngLat(point).addTo(map), popup };
                fleetMarkers.current[id] = record;
            }
            record.marker.setLngLat(point);
            const element = record.marker.getElement();
            element.setAttribute('aria-label', label);
            element.classList.toggle('stale', !!stale);
            updateVehicleHeading(element, point, v.heading ?? v.bearing, v.speed != null && Number(v.speed) === 0);
            record.popup.setText(label);
        });
        map.triggerRepaint();
    }, [liveKey, status, dataKey]);

    // Simulated moving vehicles update and real-time slow road animation
    const simKey = JSON.stringify(simVehicles.map(v => v.vehicle_id));
    useEffect(() => {
        if (status !== 'ready' || !mapRef.current || !glRef.current) return;
        const gl = glRef.current;
        const map = mapRef.current;

        // Clean up removed markers
        const activeIds = new Set(simVehicles.map(v => String(v.vehicle_id)));
        Object.keys(simMarkersRef.current).forEach(id => {
            if (!activeIds.has(id)) {
                simMarkersRef.current[id].remove();
                delete simMarkersRef.current[id];
                delete simPopupsRef.current[id];
                delete simStatesRef.current[id];
            }
        });

        // Initialize markers, popups, and state tracking for each simulated vehicle
        simVehicles.forEach(v => {
            const id = String(v.vehicle_id);
            const rId = normalizeRouteId(v.route_id, v.route_name);
            const waypoints = (ROUTE_WAYPOINTS[rId] && ROUTE_WAYPOINTS[rId].length > 10)
                ? ROUTE_WAYPOINTS[rId]
                : ((v.waypoints && v.waypoints.length > 10) ? v.waypoints : (ROUTE_WAYPOINTS[1] || []));
            const metricData = getRouteMetricData(rId, waypoints);
            const stops = getRouteStopsWithDistances(rId, metricData, mapData?.stops);

            if (!simStatesRef.current[id]) {
                const initDist = v.initial_distance != null
                    ? Number(v.initial_distance)
                    : (INITIAL_STAGGER_METERS[rId] != null
                        ? INITIAL_STAGGER_METERS[rId]
                        : (v.offset_seconds ? Number(v.offset_seconds) * 30 : 0));

                simStatesRef.current[id] = {
                    distanceMeters: Math.min(initDist, Math.max(0, metricData.totalMeters - 100)),
                    isPaused: false,
                    pauseRemaining: 0,
                    pausedAtStopName: null,
                    visitedStops: new Set(),
                    isFinished: false,
                    bearing: 0,
                };
            }

            if (!simMarkersRef.current[id]) {
                const element = createVehicleElement(true);
                const label = `${v.plate_number} · ${v.route_name} · Demo simulation — not real GPS`;
                element.setAttribute('aria-label', label);

                const popupContent = document.createElement('div');
                const initialSpeedKmh = Math.max(10, Math.min(30, Number(v.speed || v.sim_speed || window.__simSpeedKmh || simulationSpeed || DEFAULT_SPEED_KMH)));
                popupContent.innerHTML = buildPopupHtml(v, `In transit (~${Math.round(initialSpeedKmh)} km/h)`, 'moving');

                const popup = new gl.Popup({
                    offset: 22,
                    maxWidth: '280px',
                    closeButton: true,
                    closeOnClick: false,
                    focusAfterOpen: false
                }).setDOMContent(popupContent);

                const state = simStatesRef.current[id];
                const pt = getPointAtDistance(metricData, state.distanceMeters);

                // Anchor at center so marker directly follows the center of the road polyline
                const marker = new gl.Marker({ element, anchor: 'center' }).setPopup(popup).setLngLat(pt.coordinate).addTo(map);

                // Keep vehicle information popup fully inside the map viewport
                element.addEventListener('click', () => {
                    map.easeTo({ center: marker.getLngLat(), duration: 250 });
                });

                updateVehicleHeading(element, pt.coordinate, pt.bearing);

                simMarkersRef.current[id] = marker;
                simPopupsRef.current[id] = { popup, contentElement: popupContent, lastHtml: popupContent.innerHTML };
            }
        });
        window.__simMarkers = simMarkersRef.current;

        // High-precision distance & elapsed time simulation animation loop
        let animId;
        let lastFrameTimestamp = performance.now();

        const animate = (currentTimestamp) => {
            const rawElapsed = (currentTimestamp - lastFrameTimestamp) / 1000;
            lastFrameTimestamp = currentTimestamp;
            // Guard against large time jumps when switching browser tabs
            const dt = Math.max(0.001, Math.min(0.25, rawElapsed));

            simVehicles.forEach(v => {
                const id = String(v.vehicle_id);
                const marker = simMarkersRef.current[id];
                const state = simStatesRef.current[id];
                if (!marker || !state) return;

                const rId = normalizeRouteId(v.route_id, v.route_name);
                const waypoints = (ROUTE_WAYPOINTS[rId] && ROUTE_WAYPOINTS[rId].length > 10)
                    ? ROUTE_WAYPOINTS[rId]
                    : ((v.waypoints && v.waypoints.length > 10) ? v.waypoints : (ROUTE_WAYPOINTS[1] || []));
                const metricData = getRouteMetricData(rId, waypoints);
                const stops = getRouteStopsWithDistances(rId, metricData, mapData?.stops);

                // Configurable simulated speed: approximately 20 km/h, configurable between 10 and 30 km/h
                const speedKmh = Math.max(10, Math.min(30, Number(window.__simSpeedKmh || v.speed || v.sim_speed || simulationSpeed || DEFAULT_SPEED_KMH)));
                const speedMps = (speedKmh * 1000) / 3600; // ~5.556 m/s at 20 km/h

                let statusText = `In transit (~${Math.round(speedKmh)} km/h)`;
                let statusType = 'moving';

                if (state.isFinished) {
                    // When the trip ends, stop the marker instead of teleporting it back to the start
                    state.distanceMeters = metricData.totalMeters;
                    statusText = 'Trip completed · Arrived at destination';
                    statusType = 'finished';
                } else if (state.isPaused) {
                    // Pause for approximately 3–5 seconds at designated stops
                    state.pauseTimerRemaining -= dt;
                    statusText = `Paused at ${state.pausedAtStopName || 'stop'} (${Math.max(1, Math.ceil(state.pauseTimerRemaining))}s)`;
                    statusType = 'paused';

                    if (state.pauseTimerRemaining <= 0) {
                        state.isPaused = false;
                        state.pauseTimerRemaining = 0;
                        state.pausedAtStopName = null;
                        statusText = `In transit (~${Math.round(speedKmh)} km/h)`;
                        statusType = 'moving';
                    }
                } else {
                    // Normal road progression using distance and elapsed time
                    const moveMeters = speedMps * dt;
                    const nextStop = stops.find(s => !state.visitedStops.has(s.id) && s.distance >= state.distanceMeters);

                    if (nextStop && (state.distanceMeters + moveMeters) >= nextStop.distance) {
                        // Arrived at a designated stop: pause for 4 seconds (within 3-5 second requirement)
                        state.distanceMeters = nextStop.distance;
                        state.isPaused = true;
                        state.pauseTimerRemaining = 4.0;
                        state.pausedAtStopName = nextStop.name;
                        state.visitedStops.add(nextStop.id);
                        statusText = `Paused at ${nextStop.name} (4s)`;
                        statusType = 'paused';
                    } else {
                        state.distanceMeters += moveMeters;
                        if (state.distanceMeters >= metricData.totalMeters) {
                            state.distanceMeters = metricData.totalMeters;
                            state.isFinished = true;
                            statusText = 'Trip completed · Arrived at destination';
                            statusType = 'finished';
                        }
                    }
                }

                // Compute exact road position and bearing
                const pt = getPointAtDistance(metricData, state.distanceMeters);
                state.bearing = pt.bearing;
                marker.setLngLat(pt.coordinate);

                updateVehicleHeading(marker.getElement(), pt.coordinate, pt.bearing, state.isPaused || state.isFinished);

                // Update popup if DOM content has changed
                const popupRecord = simPopupsRef.current[id];
                if (popupRecord && popupRecord.contentElement) {
                    const nextHtml = buildPopupHtml(v, statusText, statusType);
                    if (popupRecord.lastHtml !== nextHtml) {
                        popupRecord.contentElement.innerHTML = nextHtml;
                        popupRecord.lastHtml = nextHtml;
                    }
                }
            });

            animId = requestAnimationFrame(animate);
        };

        animId = requestAnimationFrame(animate);

        return () => {
            cancelAnimationFrame(animId);
        };
    }, [simKey, status, simulationSpeed]);

    function recenter() {
        const map = mapRef.current;
        if (!map) return;
        following.current = true;
        if (mapData?.followVehicle && knownLocation) {
            map.flyTo({ center: [Number(knownLocation.longitude), Number(knownLocation.latitude)], zoom: Math.max(map.getZoom(), 14), duration: 500 });
            return;
        }
        const rId = normalizeRouteId(mapData?.routeId, name);
        const activeRoadLine = mapData?.routeLine || ROUTE_WAYPOINTS[rId] || geometry(name);
        const points = realPoints.length ? realPoints : activeRoadLine;
        if (!points.length) {
            map.flyTo({ center: [120.57, 18.12], zoom: 11 });
            return;
        }
        map.fitBounds([
            points.reduce((a, p) => [Math.min(a[0], p[0]), Math.min(a[1], p[1])], points[0]),
            points.reduce((a, p) => [Math.max(a[0], p[0]), Math.max(a[1], p[1])], points[0])
        ], { padding: 55, duration: 500 });
    }

    const hasSim = simVehicles.length > 0;
    const mapLabel = mapData
        ? (knownLocation
            ? (knownLocation.is_stale ? 'STALE GPS · LAST KNOWN POSITION' : 'LIVE VEHICLE GPS')
            : (liveVehicles.length ? `${liveVehicles.length} VEHICLES · RECORDED GPS${hasSim ? ' · DEMO SIMULATION ALSO SHOWN' : ''}` : hasSim
                ? (simVehicles.length > 1 ? `${simVehicles.length} MOVING JEEPNEYS · DEMO SIMULATION — NOT REAL GPS` : 'DEMO SIMULATION — NOT REAL GPS')
                : 'ROUTE STOPS · VEHICLE GPS UNAVAILABLE'))
        : 'DEMO ROUTE & VEHICLE · NOT LIVE GPS';

    return (
        <div className={'mapbox-wrap ' + (small ? 'small' : '')}>
            <div ref={container} className="mapbox-container" aria-label={'Mapbox map: ' + name} />
            {status === 'ready' && (
                <>
                    <span className={'map-label ' + (hasSim && !knownLocation ? 'simulation' : '')}>{mapLabel}</span>
                    <button className="mb-recenter" aria-label={mapData?.followVehicle && knownLocation ? 'Recenter vehicle' : 'Recenter route'} onClick={recenter}>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path d="M20 4 14 20 10 14 4 10Z" /></svg>
                    </button>
                </>
            )}
            {status === 'loading' && (
                <div className="mapbox-loading" role="status">
                    <span className="map-spinner" />
                    <strong>Loading Mapbox…</strong>
                    <small>{hasSim ? 'Active routes and simulated jeepneys' : (mapData ? 'Route stops and location records' : 'Demo route and vehicle markers')}</small>
                </div>
            )}
            {status === 'error' && (
                <div className="mapbox-fallback">
                    {fallback || (mapData && <StopPreview stops={knownStops} location={knownLocation} simulatedVehicles={simVehicles} liveVehicles={liveVehicles} />)}
                    <div className="mapbox-error" role="status">
                        <span>{mapData ? 'Mapbox unavailable · coordinate preview' : 'Mapbox unavailable · illustrative fallback'}</span>
                        <button onClick={() => retry(x => x + 1)}>Retry</button>
                    </div>
                </div>
            )}
        </div>
    );
}

function StopPreview({ stops, location, simulatedVehicles = [], liveVehicles = [] }) {
    const [zoom, Z] = useState(1);
    const [selected, S] = useState('');
    const points = [
        ...stops.map(s => ({ ...s, label: s.label || s.stop_name || 'Route stop' })),
        ...(location ? [{ ...location, label: 'Latest vehicle location', vehicle: true }] : []),
        ...liveVehicles.map(v => ({ ...v, label: `${v.vehicleLabel || 'Vehicle'} · Latest recorded GPS`, vehicle: true })),
        ...simulatedVehicles.map(v => ({
            ...v,
            label: `${v.plate_number} (${v.route_name}) · Demo simulation — not real GPS`,
            vehicle: true,
            is_simulation: true
        }))
    ];

    const lngs = points.map(p => Number(p.longitude));
    const lats = points.map(p => Number(p.latitude));
    const minLng = Math.min(...lngs);
    const maxLng = Math.max(...lngs);
    const minLat = Math.min(...lats);
    const maxLat = Math.max(...lats);

    const x = p => points.length === 1 ? 200 : 60 + (Number(p.longitude) - minLng) / Math.max(maxLng - minLng, .0001) * 270;
    const y = p => points.length === 1 ? 170 : 260 - (Number(p.latitude) - minLat) / Math.max(maxLat - minLat, .0001) * 170;

    return (
        <div className="stop-coordinate-preview">
            <svg viewBox="0 0 400 340" role="img" aria-label="Route stop coordinates and moving demo jeepneys; basemap offline">
                <defs>
                    <pattern id="coordinate-grid" width="45" height="45" patternUnits="userSpaceOnUse">
                        <path d="M45 0H0V45" fill="none" stroke="#dce5ef" strokeWidth="1" />
                    </pattern>
                </defs>
                <rect width="400" height="340" fill="#eef3f8" />
                <rect width="400" height="340" fill="url(#coordinate-grid)" />
                <g transform={`translate(200 170) scale(${zoom}) translate(-200 -170)`}>
                    {points.map((p, i) => (
                        <g key={i}>
                            <circle
                                cx={x(p)}
                                cy={y(p)}
                                r={p.vehicle ? 10 : 7}
                                fill={selected === p.label ? '#2463eb' : (p.is_simulation ? '#0d9488' : (p.vehicle ? '#1d4ed8' : 'white'))}
                                stroke={p.is_simulation ? '#0f766e' : '#2463eb'}
                                strokeWidth="3"
                                tabIndex="0"
                                role="button"
                                aria-label={p.label}
                                onClick={() => S(p.label)}
                                onKeyDown={e => { if (e.key === 'Enter') S(p.label); }}
                            >
                                <title>{p.label}</title>
                            </circle>
                        </g>
                    ))}
                </g>
                {!points.length && <text x="200" y="170" textAnchor="middle">No location coordinates available</text>}
            </svg>
            {points.length > 0 && (
                <label className="coordinate-stop-picker">
                    Find on map
                    <select aria-label="Find a map stop" value={selected} onChange={e => S(e.target.value)}>
                        <option value="">Select a location</option>
                        {points.map((p, i) => <option key={i} value={p.label}>{p.label}</option>)}
                    </select>
                </label>
            )}
            <div className="map-controls">
                <button aria-label="Recenter route" onClick={() => Z(1)}>↺</button>
            </div>
            <span className="map-selected">
                {selected || (simulatedVehicles.length ? 'Demo simulation — not real GPS' : (location ? 'Latest recorded vehicle point' : 'Route stops'))}
            </span>
            <small className="attribution">Stored coordinates · Basemap offline</small>
        </div>
    );
}
