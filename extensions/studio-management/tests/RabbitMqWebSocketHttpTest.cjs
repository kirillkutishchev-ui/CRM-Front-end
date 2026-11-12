// Real WAMP v1 subscription; run only against .env.mq-local with MQ workers running.
const fs = require('node:fs');
const assert = require('node:assert/strict');
const env = Object.fromEntries(fs.readFileSync('.env.mq-local', 'utf8').split(/\r?\n/)
    .filter(line => line && !line.startsWith('#')).map(line => {
        const i = line.indexOf('='); return [line.slice(0, i), line.slice(i + 1)];
    }));
const base = new URL('/api/v1/', env.CRM_SITE_URL);
assert(['localhost', '127.0.0.1'].includes(base.hostname));
const auth = Buffer.from(`${env.CRM_ADMIN_USERNAME}:${env.CRM_ADMIN_PASSWORD}`).toString('base64');
const headers = {'Espo-Authorization': auth, 'Espo-Authorization-By-Token': 'false',
    'Espo-Authorization-Create-Token': 'true'};
(async () => {
    const session = await fetch(new URL('App/user', base), {headers}).then(r => r.json());
    assert(session.token && session.user?.id, 'Session token is required.');
    const wsUrl = new URL(env.CRM_WEBSOCKET_URL);
    assert(['localhost', '127.0.0.1'].includes(wsUrl.hostname));
    wsUrl.searchParams.set('authToken', session.token);
    wsUrl.searchParams.set('userId', session.user.id);
    const socket = new WebSocket(wsUrl, 'wamp');
    let timer;
    try {
        const event = new Promise((resolve, reject) => {
            timer = setTimeout(() => reject(new Error('No real WebSocket completion within 30 seconds.')), 30000);
            socket.addEventListener('error', () => reject(new Error('WebSocket transport failed.')));
            socket.addEventListener('message', async message => {
                const packet = JSON.parse(message.data);
                if (packet[0] === 0) {
                    socket.send(JSON.stringify([5, 'studioMqTaskCompleted']));
                    // WAMP v1 has no subscribe ACK; preserve ordering and give the server a turn.
                    await new Promise(r => setTimeout(r, 250));
                    const response = await fetch(new URL('StudioManagement/analytics?from=2026-01-01&to=2026-01-07', base), {headers});
                    assert.equal(response.status, 202);
                    const task = await response.json();
                    socket.fixtureTaskId = task.taskId;
                }
                if (packet[0] === 8 && packet[1] === 'studioMqTaskCompleted' &&
                    packet[2].taskId === socket.fixtureTaskId) resolve(packet[2]);
            });
        });
        const notification = await event;
        assert.equal(notification.status, 'Completed');
        assert.equal(notification.userId, session.user.id);
        const result = await fetch(new URL(`StudioManagement/mq-tasks/${notification.taskId}`, base), {headers}).then(r => r.json());
        assert.equal(result.status, 'Completed');
        assert.equal(result.result.from, '2026-01-01');
        console.log('RabbitMQ WebSocket: real authenticated WAMP event received; HTTP result authorized.');
    } finally {
        clearTimeout(timer);
        socket.close();
        await fetch(new URL('App/destroyAuthToken', base), {method: 'POST', headers: {...headers, 'Content-Type': 'application/json'},
            body: JSON.stringify({token: session.token})});
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
