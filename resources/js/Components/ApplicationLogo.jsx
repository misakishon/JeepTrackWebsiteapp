export default function ApplicationLogo(props) {
    return <img {...props} src="/images/jeepney-tracker-logo.png" alt="Jeepney Tracker" style={{objectFit:'contain',...props.style}} />;
}
