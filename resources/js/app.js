import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const key = document.querySelector('meta[name="reverb-key"]')?.content;
const useTls = window.location.protocol === 'https:';

if (key) {
    window.Pusher = Pusher;
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: window.location.hostname,
        wsPort: Number(window.location.port || 80),
        wssPort: Number(window.location.port || 443),
        forceTLS: useTls,
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
    });
}
