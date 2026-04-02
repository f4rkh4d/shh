// shh — client-side crypto + form handling.
// aes-gcm 256, 12-byte iv, key lives only in the url fragment after #k=.

(function () {
    'use strict';

    const enc = new TextEncoder();
    const dec = new TextDecoder();

    function b64urlFromBytes(bytes) {
        let bin = '';
        for (let i = 0; i < bytes.byteLength; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function bytesFromB64url(s) {
        s = s.replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) s += '=';
        const bin = atob(s);
        const out = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
        return out;
    }

    function b64FromBytes(bytes) {
        let bin = '';
        for (let i = 0; i < bytes.byteLength; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin);
    }

    function bytesFromB64(s) {
        const bin = atob(s);
        const out = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
        return out;
    }

    async function generateKey() {
        return crypto.subtle.generateKey(
            { name: 'AES-GCM', length: 256 },
            true,
            ['encrypt', 'decrypt']
        );
    }

    async function exportKey(key) {
        const raw = await crypto.subtle.exportKey('raw', key);
        return new Uint8Array(raw);
    }

    async function importKey(rawBytes) {
        return crypto.subtle.importKey(
            'raw',
            rawBytes,
            { name: 'AES-GCM', length: 256 },
            false,
            ['decrypt']
        );
    }

    async function encryptSecret(plaintext) {
        const key = await generateKey();
        const iv = crypto.getRandomValues(new Uint8Array(12));
        const ct = await crypto.subtle.encrypt(
            { name: 'AES-GCM', iv },
            key,
            enc.encode(plaintext)
        );
        const rawKey = await exportKey(key);
        return {
            ciphertext: b64FromBytes(new Uint8Array(ct)),
            iv: b64FromBytes(iv),
            keyFragment: b64urlFromBytes(rawKey),
        };
    }

    async function decryptSecret(ciphertextB64, ivB64, keyB64url) {
        const keyBytes = bytesFromB64url(keyB64url);
        if (keyBytes.length !== 32) throw new Error('bad key length');
        const key = await importKey(keyBytes);
        const iv = bytesFromB64(ivB64);
        const ct = bytesFromB64(ciphertextB64);
        const pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ct);
        return dec.decode(pt);
    }

    function ttlLabel(seconds) {
        if (seconds >= 86400) {
            const d = Math.round(seconds / 86400);
            return d + (d === 1 ? ' day' : ' days');
        }
        const h = Math.round(seconds / 3600);
        return h + (h === 1 ? ' hour' : ' hours');
    }

    // compose page
    function initCompose() {
        const secret = document.getElementById('secret');
        const chars = document.getElementById('chars');
        const ttl = document.getElementById('ttl');
        const go = document.getElementById('go');
        const err = document.getElementById('err');
        const result = document.getElementById('result');
        const urlEl = document.getElementById('url');
        const copyBtn = document.getElementById('copy');
        const againBtn = document.getElementById('again');
        const expiry = document.getElementById('expiry');
        const compose = document.getElementById('compose');

        if (!secret) return;

        secret.addEventListener('input', () => {
            chars.textContent = String(secret.value.length);
        });

        function showError(msg) {
            err.textContent = msg;
            err.hidden = false;
        }

        go.addEventListener('click', async () => {
            err.hidden = true;
            const text = secret.value;
            if (!text.trim()) {
                showError('need something to hide.');
                return;
            }
            if (text.length > 4096) {
                showError('too long. keep it under 4096 chars.');
                return;
            }
            go.disabled = true;
            go.textContent = 'encrypting...';
            try {
                const ttlSeconds = parseInt(ttl.value, 10) || 86400;
                const { ciphertext, iv, keyFragment } = await encryptSecret(text);
                const res = await fetch('/api/new', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ciphertext, iv, ttl_seconds: ttlSeconds }),
                });
                if (!res.ok) {
                    const j = await res.json().catch(() => ({}));
                    throw new Error(j.error || ('server said ' + res.status));
                }
                const { token } = await res.json();
                const url = window.location.origin + '/x/' + token + '#k=' + keyFragment;
                urlEl.textContent = url;
                expiry.textContent = 'this link expires in ' + ttlLabel(ttlSeconds) + ' or on first open, whichever comes first.';
                compose.hidden = true;
                result.hidden = false;
                secret.value = '';
            } catch (e) {
                showError(e.message || 'something broke.');
            } finally {
                go.disabled = false;
                go.textContent = 'burn after read';
            }
        });

        copyBtn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(urlEl.textContent);
                copyBtn.textContent = 'copied';
                setTimeout(() => (copyBtn.textContent = 'copy'), 1500);
            } catch (_) {
                copyBtn.textContent = 'copy failed';
            }
        });

        againBtn.addEventListener('click', () => {
            result.hidden = true;
            compose.hidden = false;
        });
    }

    // reveal page
    function initReveal() {
        const gate = document.getElementById('gate');
        const reveal = document.getElementById('reveal');
        const shown = document.getElementById('shown');
        const plain = document.getElementById('plain');
        const err = document.getElementById('err');
        const copyBtn = document.getElementById('copy');

        if (!reveal) return;

        const token = window.location.pathname.split('/').pop();
        const hash = window.location.hash || '';
        const m = hash.match(/^#k=([A-Za-z0-9_-]+)$/);

        function showError(msg) {
            err.textContent = msg;
            err.hidden = false;
        }

        if (!m) {
            showError('no decryption key in the url. the fragment after # is missing.');
            reveal.disabled = true;
            return;
        }
        const keyFrag = m[1];

        reveal.addEventListener('click', async () => {
            reveal.disabled = true;
            reveal.textContent = 'burning...';
            try {
                const res = await fetch('/api/burn/' + encodeURIComponent(token), { method: 'POST' });
                if (res.status === 404) {
                    showError('gone. either already opened, or never existed.');
                    return;
                }
                if (!res.ok) {
                    const j = await res.json().catch(() => ({}));
                    throw new Error(j.error || ('server said ' + res.status));
                }
                const { ciphertext, iv } = await res.json();
                const text = await decryptSecret(ciphertext, iv, keyFrag);
                plain.textContent = text;
                gate.hidden = true;
                shown.hidden = false;
                // scrub fragment from history so a back-button doesn't re-expose the key
                history.replaceState(null, '', window.location.pathname);
            } catch (e) {
                showError(e.message || 'decrypt failed. key probably wrong.');
                reveal.disabled = false;
                reveal.textContent = 'reveal';
            }
        });

        copyBtn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(plain.textContent);
                copyBtn.textContent = 'copied';
                setTimeout(() => (copyBtn.textContent = 'copy'), 1500);
            } catch (_) {
                copyBtn.textContent = 'copy failed';
            }
        });
    }

    initCompose();
    initReveal();
})();
