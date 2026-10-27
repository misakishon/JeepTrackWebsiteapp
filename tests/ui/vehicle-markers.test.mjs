import test from 'node:test';
import assert from 'node:assert/strict';
import { markerScale, shortestTurn, travelHeading, createVehicleElement, updateVehicleHeading, renderVehicleMarkers } from '../../resources/js/Components/vehicleMarker.js';

test('zoom sizing is continuous, monotonic, and bounded', () => {
    assert.equal(markerScale(0), 0.45);
    assert.equal(markerScale(24), 1.3);
    assert.ok(markerScale(12) < markerScale(14));
    assert.ok(Math.abs(markerScale(12.001) - markerScale(12)) < 0.001);
});

test('GPS bearings and wraparound follow the shortest turn', () => {
    const origin = [120, 18];
    assert.equal(travelHeading(origin, [120, 19]), 0);
    assert.ok(Math.abs(travelHeading(origin, [121, 18]) - 90) < 1);
    assert.equal(travelHeading(origin, [120, 17]), 180);
    assert.ok(Math.abs(travelHeading(origin, [119, 18]) - 270) < 1);
    assert.equal(shortestTurn(359, 0), 1);
    assert.equal(shortestTurn(0, 359), -1);
});

test('rotation compensates camera bearing, preserves anchors and stopped heading', () => {
    const scale = { style: {} }, art = { style: {} };
    globalThis.document = { createElement: () => ({ style: { transform: 'translate(123px, 456px)' }, querySelector: selector => selector === '.mb-vehicle-scale' ? scale : art }) };
    try {
        const element = createVehicleElement(true);
        let bearing = 0, zoom = 12;
        const map = { getZoom: () => zoom, getBearing: () => bearing, getContainer: () => ({ querySelectorAll: () => [element] }), triggerRepaint() {} };
        updateVehicleHeading(element, [120, 18], 359);
        renderVehicleMarkers(map, 0);
        assert.equal(art.style.transform, 'rotate(359deg)');
        updateVehicleHeading(element, [120, 18.001], 0);
        renderVehicleMarkers(map, 16);
        const angle = () => Number(art.style.transform.match(/rotate\((.*)deg\)/)[1]);
        assert.ok(angle() > 359 && angle() < 360);
        for (let time = 32; time <= 2000; time += 16) renderVehicleMarkers(map, time);
        updateVehicleHeading(element, [120, 18.001], 180);
        bearing = 90; zoom = 8;
        renderVehicleMarkers(map, 2016);
        assert.equal((angle() + 360) % 360, 270);
        assert.equal(scale.style.transform, 'scale(0.45)');
        assert.equal(element.style.transform, 'translate(123px, 456px)');
        updateVehicleHeading(element, [120, 18.002], 180, true);
        renderVehicleMarkers(map, 2032);
        assert.equal((angle() + 360) % 360, 270);
        // Missing heading falls back to consecutive coordinates when movement resumes.
        updateVehicleHeading(element, [120, 18.003], null);
        for (let time = 2048; time <= 4000; time += 16) renderVehicleMarkers(map, time);
        assert.equal((angle() + 360) % 360, 270);
    } finally { delete globalThis.document; }
});

