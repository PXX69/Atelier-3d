// Vérifie des clés Liagos 3D Pro avec WebCrypto (comme le fait l'application) : node verify_key.mjs <clé publique base64url> <clé>...
const [pubB64, ...keys] = process.argv.slice(2);
const b64u = s => Uint8Array.from(Buffer.from(s.replace(/-/g, '+').replace(/_/g, '/'), 'base64'));
const pub = await crypto.subtle.importKey('raw', b64u(pubB64), { name: 'ECDSA', namedCurve: 'P-256' }, false, ['verify']);
const ctx = new TextEncoder().encode('Liagos3D-Pro-v1\0');
const out = [];
for (let k of keys) {
  k = k.replace(/\s+/g, '');
  try {
    if (!k.startsWith('L3D1-')) { out.push(null); continue; }
    const raw = b64u(k.slice(5));
    if (raw.length !== 76) { out.push(null); continue; }
    const data = new Uint8Array(ctx.length + 12); data.set(ctx); data.set(raw.slice(0, 12), ctx.length);
    const ok = await crypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, pub, raw.slice(12), data);
    out.push(ok ? { edition: raw[1], days: (raw[2] << 8) | raw[3] } : null);
  } catch (e) { out.push(null); }
}
console.log(JSON.stringify(out));
