import I from './TransportIcon';
import './BrandWelcome.css';

function RouteIllustration() {
    return <div className="puj-route" aria-hidden="true">
        <svg viewBox="0 0 320 90" fill="none"><path d="M12 62C60 62 43 18 106 31S185 84 220 46 264 24 303 25" stroke="#0866ff" strokeWidth="1.5" strokeDasharray="4 5"/><circle cx="12" cy="62" r="6" fill="white" stroke="#0866ff" strokeWidth="3"/><path d="M303 8a12 12 0 0 0-12 12c0 9 12 21 12 21s12-12 12-21a12 12 0 0 0-12-12Z" fill="#0866ff"/><circle cx="303" cy="20" r="4" fill="white"/></svg>
        <span className="puj-route-jeep"><I n="airport_shuttle"/></span>
        <div><span>YOUR ROUTE</span><span>YOUR JOURNEY</span></div>
    </div>;
}

function MapIllustration() {
    return <div className="puj-map-illustration" aria-hidden="true">
        <svg viewBox="0 0 300 180" fill="none"><rect width="300" height="180" rx="20" fill="#e4f1ff88"/><g fill="#c5dce98c"><path d="M14 15h65v35H14zM98 12h58v49H98zM180 13h98v31h-98zM20 83h50v70H20zM97 85h76v28H97zM205 71h70v34h-70zM188 133h92v30h-92z"/></g><g stroke="white" strokeWidth="10"><path d="M0 67h300M85 0v180M185 0v180M0 124h300M240 0v180"/></g><path d="M43 133h42V67h100" stroke="#0866ff" strokeWidth="5" strokeLinecap="round" strokeLinejoin="round"/><path d="M185 67h55v55" stroke="#edb934" strokeWidth="5" strokeLinecap="round" strokeLinejoin="round"/>{[[43,126],[240,115]].map(([x,y])=><g key={x} transform={`translate(${x} ${y})`}><path d="M0 0S-11-12-11-19a11 11 0 1 1 22 0C11-12 0 0 0 0Z" fill="#0866ff"/><circle cy="-19" r="4" fill="white"/></g>)}</svg>
        <div className="puj-live-pill"><span className="material-symbols-outlined">sensors</span><div><strong>Live Routes</strong><span>Real-time tracking</span></div></div>
        <small>Route illustration</small>
    </div>;
}

export default function BrandWelcome({ status, accountHref = '/login', accountRole = 'passenger', onNavigate, homeHref = '/passenger/home', showMobile = false }) {
    const navigate = (event, href) => {
        if (!onNavigate || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        event.preventDefault();
        if (href === '/login' && window.location.pathname === '/login') {
            document.querySelector('.mobile-app input')?.focus({ preventScroll: true });
            document.querySelector('.mobile-app')?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'start' });
            return;
        }
        onNavigate(href);
    };
    return <aside className={'desktop-rail puj-welcome' + (showMobile ? ' puj-welcome-mobile' : '')} aria-label="Welcome to PUJ Transportation Analytics">
        <div className="puj-welcome-backdrop" aria-hidden="true"><img src="/images/puj-welcome-city-road.png" alt="" width="1271" height="1238"/></div>
        <a className="puj-brand" href={homeHref} onClick={event => navigate(event, homeHref)}><img src="/images/jeepney-tracker-logo.png" alt="" width="54" height="54"/><span><strong>PUJ</strong><small>TRANSPORTATION ANALYTICS</small></span></a>
        <div className="puj-welcome-body">
            <div className="puj-welcome-copy"><div className="puj-eyebrow">A BETTER WAY TO MOVE</div><h1>Every route.<br/>Every ride.<br/><em>Connected.</em></h1><p>Making everyday journeys around your community a little simpler.</p><RouteIllustration/></div>
            <div className="puj-welcome-visual"><img className="puj-mobile-backdrop" src="/images/puj-welcome-city-road.png" alt="" aria-hidden="true" width="1271" height="1238"/><MapIllustration/><img className="puj-hero-jeepney" src="/images/puj-welcome-jeepney.png" alt="Blue Philippine jeepney with chrome details and red and yellow accents" width="1536" height="1024"/></div>
        </div>
        <div className="puj-welcome-footer"><div className="puj-data-status"><i/>{status}</div><a className="puj-account" href={accountHref} onClick={event => navigate(event, accountHref)}><span className="puj-account-icon"><I n="person"/></span><span className="puj-account-copy"><strong>Your {accountRole} account</strong><span>Manage your rides, routes and preferences.</span></span><span className="puj-account-arrow"><I n="arrow_forward"/></span></a></div>
    </aside>;
}
