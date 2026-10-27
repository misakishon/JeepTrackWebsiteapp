import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Button, DataTable, Empty, Icon, Panel } from './components';
import './DriverAccounts.css';

export default function DriverAccounts({ onCreated }) {
    const [drivers, setDrivers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState('');
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [search, setSearch] = useState('');
    const nameInput = useRef(null);
    const addButton = useRef(null);
    const wasOpen = useRef(false);

    async function load(signal) {
        setLoading(true); setLoadError('');
        try {
            const response = await axios.get('/api/admin/drivers', { signal });
            setDrivers(response.data.drivers);
        } catch (error) {
            if (!axios.isCancel(error)) setLoadError(error.response?.status === 401 ? 'Your session expired. Sign in again.' : 'Could not load driver accounts. Please retry.');
        } finally { if (!signal?.aborted) setLoading(false); }
    }
    useEffect(() => { const controller = new AbortController(); load(controller.signal); return () => controller.abort(); }, []);
    useEffect(() => {
        if (open) nameInput.current?.focus();
        else if (wasOpen.current) addButton.current?.focus();
        wasOpen.current = open;
    }, [open]);

    async function create(event) {
        event.preventDefault();
        if (busy) return;
        const form = event.currentTarget;
        const input = Object.fromEntries(new FormData(form));
        setBusy(true); setErrors({}); setMessage('');
        try {
            const response = await axios.post('/api/admin/drivers', input);
            setDrivers(previous => [...previous, response.data.driver]);
            setSearch(''); form.reset(); setOpen(false);
            setMessage(`${response.data.driver.fullname}'s driver account was created. They can now sign in using ${response.data.driver.email}. Share the password directly with the driver.`);
            onCreated?.();
        } catch (error) {
            const validation = error.response?.data?.errors;
            setErrors(validation || { form: [error.response?.status === 401 ? 'Your session expired. Sign in again.' : error.response?.data?.message || 'The account could not be created. Please retry.'] });
            requestAnimationFrame(() => form.querySelector('[aria-invalid="true"]')?.focus());
        } finally { setBusy(false); }
    }
    function field(label, name, type = 'text', extra = {}) {
        return <label className="ad-driver-field" key={name}>{label}<input name={name} type={type} required aria-invalid={!!errors[name]} aria-describedby={errors[name] ? `driver-error-${name}` : undefined} {...extra}/>{errors[name] && <span className="ad-driver-error" id={`driver-error-${name}`}>{errors[name][0]}</span>}</label>;
    }
    return <div className="ad-driver-accounts">
        <Panel title="Driver accounts" subtitle="Create a driver login and its license record together." action={<button className="ad-button" type="button" ref={addButton} onClick={() => { setOpen(true); setErrors({}); }} disabled={open} aria-expanded={open} aria-controls="driver-create-form"><Icon name="person"/>Add driver</button>}/>
        {message && <div className="ad-alert" role="status"><Icon name="check"/><span>{message}</span></div>}
        {open && <Panel title="Add a driver" subtitle="Only administrators can create driver accounts.">
            <form id="driver-create-form" className="ad-driver-form" onSubmit={create} aria-busy={busy}>
                {errors.form && <p role="alert" className="ad-driver-error">{errors.form[0]}</p>}
                <div className="ad-driver-fields">
                    {field('Full name', 'fullname', 'text', { maxLength: 100, autoComplete: 'name', ref: nameInput })}
                    {field('Email address', 'email', 'email', { maxLength: 100, autoComplete: 'off' })}
                    {field('License number', 'license_number', 'text', { maxLength: 50, autoComplete: 'off' })}
                    {field('Password', 'password', 'password', { minLength: 8, autoComplete: 'new-password' })}
                    {field('Confirm password', 'password_confirmation', 'password', { minLength: 8, autoComplete: 'new-password' })}
                </div>
                <p>Use at least 8 characters. This creates sign-in access; it does not assign a vehicle or trip. The driver can change their password in Profile.</p>
                <div className="ad-driver-actions"><Button type="submit" disabled={busy}>{busy ? 'Creating account…' : 'Create driver account'}</Button><Button type="button" variant="secondary" disabled={busy} onClick={() => { setOpen(false); setErrors({}); addButton.current?.focus(); }}>Cancel</Button></div>
            </form>
        </Panel>}
        {loadError ? <Panel title="Driver accounts unavailable"><Empty title="Connection unavailable" text={loadError}/><div className="ad-driver-actions"><Button onClick={() => load()}>Retry</Button></div></Panel> : loading ? <p role="status">Loading driver accounts…</p> : <DataTable title="Registered drivers" subtitle="All driver accounts, including drivers without assigned trips." dataLabel="Driver account records" columns={[{ key: 'fullname', label: 'Driver' }, { key: 'email', label: 'Sign-in email' }, { key: 'license_number', label: 'License number' }]} rows={drivers.map(driver => ({ ...driver, id: driver.driver_id }))} query={search} onQuery={setSearch}/>}
    </div>;
}
