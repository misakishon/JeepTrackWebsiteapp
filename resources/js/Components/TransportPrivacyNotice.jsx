export default function TransportPrivacyNotice({ connected = true }) {
    if (!connected) return <>
        <h3>UI demonstration</h3>
        <p>This preview uses sample records and stores sample interactions in this browser. It does not establish that a real operator, GPS device or payment service is connected.</p>
        <p>Connected accounts use the privacy information shown below. No email, push notification or automatic deletion is promised by this preview.</p>
    </>;
    return <>
        <h3>What is recorded</h3>
        <p>Accounts include a name, email and sign-in credentials. Driver profiles also include a license number. Passenger boarding, destination, arrival and feedback are saved against the selected trip.</p>
        <h3>Driver location sharing</h3>
        <p>Starting or resuming a driver trip requests browser location permission. While sharing is active and the browser is visible, reported coordinates, accuracy, speed when available, and capture times are uploaded against the trip, vehicle and driver. Ending the trip stops sharing. Locking the phone, hiding the page or losing connectivity can pause updates.</p>
        <p>Passengers can see the latest recorded position of active vehicles. The backend does not continuously track passenger device locations. A map location control can request permission to center the map; that action does not create a passenger GPS history in this system.</p>
        <h3>Who can access records</h3>
        <p>Drivers can access their own trips and associated GPS. Passengers can access their own journeys and feedback, plus active-vehicle tracking information. Administrators can access operational reports and manage supported account and feedback records. Private administrator feedback notes are not sent to passengers.</p>
        <h3>Location limits and map services</h3>
        <p>Browser-reported location is not independently verified. A fresh or live GPS label describes the age of a fix, not proof that the device is physically at that position. Mapbox supplies the map and may receive network requests and map-view information. Demo simulation positions are labeled separately.</p>
        <h3>Retention and your controls</h3>
        <p>No automatic 30-day deletion or anonymization is currently configured. Ending a trip or logging out does not erase saved trip, GPS, journey or feedback records. The operating organization and retention schedule must be confirmed before operational use.</p>
        <p>You can edit your profile, change your password and revoke browser location permission. Contact your transport administrator about access, correction or deletion requests; a self-service deletion workflow is not implemented. A formal privacy contact has not yet been configured.</p>
        <p>These are prototype disclosures, not a claim of complete compliance with the Philippine Data Privacy Act.</p>
    </>;
}
