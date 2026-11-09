const CIPHER_PREFIX = 'JT_ENC:';
const KEY_PARTS = [0x4a, 0x65, 0x65, 0x70, 0x54, 0x72, 0x61, 0x63, 0x6b, 0x2d, 0x49, 0x4e, 0x2d, 0x32, 0x30, 0x32, 0x36];

function keyByte(index, salt) {
    const k = KEY_PARTS[index % KEY_PARTS.length];
    return (k ^ ((index * 31 + salt * 17) & 0xff)) & 0xff;
}

export function encryptMapboxToken(rawToken, salt = 0x5a) {
    const clean = String(rawToken || '').trim();
    if (!clean || clean.startsWith(CIPHER_PREFIX)) {
        return clean;
    }
    const bytes = [salt & 0xff];
    for (let i = 0; i < clean.length; i += 1) {
        const code = clean.charCodeAt(i) & 0xff;
        const masked = (code ^ keyByte(i, salt)) & 0xff;
        const rotated = ((masked << 3) | (masked >> 5)) & 0xff;
        bytes.push(rotated);
    }
    const hex = bytes.map((b) => b.toString(16).padStart(2, '0')).join('');
    const b64 = typeof btoa === 'function'
        ? btoa(hex)
        : Buffer.from(hex, 'utf8').toString('base64');
    return CIPHER_PREFIX + b64;
}

export function resolveMapboxToken(value) {
    const raw = String(value || '').trim().replace(/^["']|["']$/g, '');
    if (!raw) {
        return '';
    }
    if (!raw.startsWith(CIPHER_PREFIX)) {
        return raw;
    }
    try {
        const b64 = raw.slice(CIPHER_PREFIX.length);
        const hex = typeof atob === 'function'
            ? atob(b64)
            : Buffer.from(b64, 'base64').toString('utf8');
        if (hex.length < 4 || hex.length % 2 !== 0) {
            return '';
        }
        const salt = parseInt(hex.slice(0, 2), 16);
        let out = '';
        let charIndex = 0;
        for (let pos = 2; pos < hex.length; pos += 2) {
            const rotated = parseInt(hex.slice(pos, pos + 2), 16);
            const masked = ((rotated >> 3) | (rotated << 5)) & 0xff;
            const code = (masked ^ keyByte(charIndex, salt)) & 0xff;
            out += String.fromCharCode(code);
            charIndex += 1;
        }
        return out;
    } catch {
        return '';
    }
}
