import { useEffect, useState } from 'react';
import axios from 'axios';
import I from './TransportIcon';

export default function DriverRoutePlanner({ routes, vehicles, trip = null, active, hasDriver, onSaved, onDirty, onTracking }) {
    const [routeId, setRouteId] = useState(String(trip?.route_id || routes[0]?.route_id || ''));
    const [vehicleId, setVehicleId] = useState(String(vehicles[0]?.vehicle_id || ''));
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});
    useEffect(() => { setRouteId(String(trip?.route_id || routes[0]?.route_id || '')); setErrors({}); }, [trip?.trip_id, trip?.route_id]);
    const selected = routes.find(route => String(route.route_id) === routeId);
    if (active) return <section className="card"><div className="section-heading"><h2>Your route is active</h2><I n="route"/></div><p>{active.route?.route_name}. End this trip before setting another route.</p><button type="button" className="button secondary" onClick={onTracking}>View active trip</button></section>;

    async function save(event) {
        event.preventDefault();
        if (saving) return;
        setSaving(true); setErrors({});
        try {
            const response = trip
                ? await axios.patch(`/api/transport/trips/${trip.trip_id}/route`, { route_id: Number(routeId) })
                : await axios.post('/api/transport/trips', { route_id: Number(routeId), vehicle_id: Number(vehicleId) });
            onDirty(false);
            onSaved(response.data);
        } catch (error) {
            setErrors(error.response?.data?.errors || { form: [error.response?.data?.message || 'Could not save your route. Please retry.'] });
        } finally { setSaving(false); }
    }
    return <section className="card driver-route-planner">
        <div className="section-heading"><div><h2>Set your route</h2><p>{trip ? 'Change the route before starting this trip.' : 'Choose your route and the jeepney you will drive.'}</p></div><I n="route"/></div>
        {!hasDriver ? <p>Your account needs a driver profile. Contact your administrator.</p> : <form onSubmit={save}>
            {errors.form && <div className="feedback-message" role="alert">{errors.form[0]}</div>}
            <label className="field">Route<select aria-label="Trip route" value={routeId} onChange={event => { setRouteId(event.target.value); onDirty(true); }} disabled={saving || !routes.length} required aria-invalid={!!errors.route_id} aria-describedby={errors.route_id ? 'route-selection-error' : undefined}><option value="" disabled>Select a route</option>{routes.map(route => <option key={route.route_id} value={route.route_id}>{route.route_name}</option>)}</select>{errors.route_id && <small id="route-selection-error" role="alert">{errors.route_id[0]}</small>}</label>
            {selected && <p>{selected.origin} → {selected.destination}</p>}
            {trip ? <label className="field">Assigned jeepney<input value={trip.vehicle?.plate_number || 'Vehicle unavailable'} readOnly/></label> : <label className="field">Jeepney<select aria-label="Trip jeepney" value={vehicleId} onChange={event => { setVehicleId(event.target.value); onDirty(true); }} disabled={saving || !vehicles.length} required aria-invalid={!!errors.vehicle_id} aria-describedby={errors.vehicle_id ? 'vehicle-selection-error' : undefined}><option value="" disabled>Select your jeepney</option>{vehicles.map(vehicle => <option key={vehicle.vehicle_id} value={vehicle.vehicle_id}>{vehicle.plate_number} · {vehicle.type}</option>)}</select>{errors.vehicle_id && <small id="vehicle-selection-error" role="alert">{errors.vehicle_id[0]}</small>}</label>}
            {!routes.length && <p>No routes are configured. Contact your administrator.</p>}
            {!trip && !vehicles.length && <p>No jeepneys are available. Active trips and other drivers' scheduled assignments reserve their vehicles.</p>}
            <p>Saving creates or updates a scheduled trip. Start the trip with location permission when you are ready to share GPS with passengers.</p>
            <button className="button" type="submit" disabled={saving || !routeId || (!trip && !vehicleId) || (!trip && !vehicles.length)}>{saving ? 'Saving route…' : 'Save route'}</button>
        </form>}
    </section>;
}
