import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const key = document.querySelector('meta[name="reverb-key"]')?.content;
const audience = document.querySelector('meta[name="realtime-audience"]')?.content;
const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
const useTls = window.location.protocol === 'https:';

const pending = new Map();
function signal(name, detail = {}) {
    // A notification or receipt is an invalidation. Livewire rereads keyed rows.
    if (!pending.has(name)) {
        pending.set(name, []);
        window.setTimeout(() => {
            const updates = pending.get(name);
            pending.delete(name);
            window.dispatchEvent(new CustomEvent('gigkampus:' + name, { detail: updates }));
        }, 75);
    }
    pending.get(name).push(detail);
}

if (key && audience) {
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
        authEndpoint: '/broadcasting/auth',
        auth: { headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } },
    });

    window.Echo.private(audience)
        .listen('.notification.changed', event => {
            signal('inbox', event);
            signal('counts');
        })
        .listen('.chat.changed', event => {
            signal('chat', event);
            signal('counts');
        })
        .subscribed(() => {
            signal('inbox');
            signal('chat');
            signal('counts');
        });
}

window.gigkampusChatSubmit = async function (form) {
    const fields = new FormData(form);
    if (!fields.get('client_message_key')) {
        const key = crypto.randomUUID();
        form.elements.client_message_key.value = key;
        fields.set('client_message_key', key);
    }

    const response = await fetch(form.action, {
        method: 'POST',
        body: fields,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
    });

    if (!response.ok) {
        const result = await response.json().catch(() => ({}));
        throw new Error(Object.values(result.errors ?? {})[0]?.[0]
            ?? result.message ?? 'Pesan belum terkirim. Coba lagi.');
    }

    form.reset();
    form.elements.client_message_key.value = '';
};

window.gigkampusConversation = function (wire) {
    return {
        syncing: false,
        repeat: false,

        async sync(initial) {
            if (this.syncing) {
                this.repeat = true;
                return;
            }

            this.syncing = true;
            const history = this.$refs.history;
            const follow = initial || (history && history.scrollHeight - history.scrollTop - history.clientHeight < 90);

            try {
                if (!initial) await wire.$refresh();
                await this.$nextTick();
                const current = this.$refs.history;
                const through = Number(current?.dataset.visibleThrough);
                if (current && follow) current.scrollTop = current.scrollHeight;
                if (through && document.visibilityState === 'visible') {
                    const read = await wire.readVisible(through);
                    if (read) signal('counts');
                }
            } finally {
                this.syncing = false;
                if (this.repeat) {
                    this.repeat = false;
                    this.sync(false);
                }
            }
        },
    };
};
