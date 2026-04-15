import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const reverbKey = String(import.meta.env.VITE_REVERB_APP_KEY || '').trim();
const reverbHost = String(import.meta.env.VITE_REVERB_HOST || window.location.hostname || '127.0.0.1').trim();
const reverbPort = Number(import.meta.env.VITE_REVERB_PORT || 8080);
const reverbScheme = String(import.meta.env.VITE_REVERB_SCHEME || window.location.protocol.replace(':', '') || 'http').trim();
const shouldUseTls = reverbScheme === 'https';

window.Echo = reverbKey
    ? new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: reverbHost,
        wsPort: reverbPort,
        wssPort: reverbPort,
        forceTLS: shouldUseTls,
        enabledTransports: ['ws', 'wss'],
    })
    : null;
