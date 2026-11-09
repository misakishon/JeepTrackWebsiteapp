import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

export function isMobileAppEnvironment() {
    return Boolean(window.__JEEPTRACK_MOBILE__ || window.JeepTrackAndroid || window.location.protocol === 'file:' || window.location.hostname === 'appassets.androidplatform.net');
}

export function getMobileServerUrl() {
    const saved = localStorage.getItem('puj-mobile-server-url');
    if (saved) return saved.replace(/\/+$/, '');
    if (window.__JEEPTRACK_DEFAULT_SERVER__) return String(window.__JEEPTRACK_DEFAULT_SERVER__).replace(/\/+$/, '');
    return 'http://192.168.100.13:8000';
}

export function setMobileServerUrl(url) {
    const clean = String(url || '').trim().replace(/\/+$/, '');
    if (clean) {
        localStorage.setItem('puj-mobile-server-url', clean);
    }
    return clean;
}

export function getMobileToken() {
    return localStorage.getItem('puj-mobile-token') || '';
}

export function setMobileToken(token) {
    if (token) {
        localStorage.setItem('puj-mobile-token', token);
    } else {
        localStorage.removeItem('puj-mobile-token');
    }
}

if (typeof window !== 'undefined' && isMobileAppEnvironment() && !localStorage.getItem('puj-mobile-server-url')) {
    const candidates = [
        window.__JEEPTRACK_DEFAULT_SERVER__ || 'http://192.168.100.13:8000',
        'http://10.0.2.2:8000',
        'http://127.0.0.1:8000',
    ];
    for (const candidate of candidates) {
        fetch(`${candidate.replace(/\/+$/, '')}/api/mobile/ping`, {
            method: 'GET',
            headers: { 'X-JeepTrack-Mobile': 'android', 'Accept': 'application/json' },
        }).then((res) => {
            if (res.ok && !localStorage.getItem('puj-mobile-server-url')) {
                setMobileServerUrl(candidate);
            }
        }).catch(() => {});
    }
}

window.axios.interceptors.request.use((config) => {
    if (isMobileAppEnvironment()) {
        config.headers = config.headers || {};
        config.headers['X-JeepTrack-Mobile'] = 'android';
        const token = getMobileToken();
        if (token) {
            config.headers['Authorization'] = `Bearer ${token}`;
        }
        if (config.url && config.url.startsWith('/')) {
            config.baseURL = getMobileServerUrl();
        }
    }
    return config;
});
