import React from 'react';
import I from './TransportIcon';
import { isMobileAppEnvironment } from '../bootstrap';

export default function ApkDownloadCard({ className = '' }) {
    if (isMobileAppEnvironment()) return null;

    return (
        <section className={'apk-download-card ' + className} aria-label="Download JeepTrack Android App">
            <div className="apk-info">
                <span className="apk-icon" aria-hidden="true">
                    <I n="android" />
                </span>
                <div>
                    <h3>JeepTrack Mobile App (.apk)</h3>
                    <p>Passenger &amp; Driver Android app · Database-connected</p>
                </div>
            </div>
            <a className="apk-download-btn" href="/download/apk" download="JeepTrack.apk">
                <I n="download" />
                <span>Download APK</span>
            </a>
        </section>
    );
}
