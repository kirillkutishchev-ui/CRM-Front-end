const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../../..');
const env = Object.fromEntries(fs.readFileSync(path.join(root, '.env.mq-local'), 'utf8')
    .split(/\r?\n/).filter(line => line && !line.startsWith('#')).map(line => {
        const index = line.indexOf('='); return [line.slice(0, index), line.slice(index + 1)];
    }));
const base = new URL('/api/v1/', env.CRM_SITE_URL);
assert(['127.0.0.1', 'localhost'].includes(base.hostname), 'Local CRM only.');
const auth = Buffer.from(`${env.CRM_ADMIN_USERNAME}:${env.CRM_ADMIN_PASSWORD}`).toString('base64');
const headers = {Authorization: `Basic ${auth}`, 'Espo-Authorization': auth, 'Espo-Authorization-By-Token': 'false'};
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
async function get(route) {
    const response = await fetch(new URL(route, base), {headers});
    const text = await response.text();
    let data; try { data = JSON.parse(text); } catch { data = text; }
    return {response, data};
}
(async () => {
    let taskId = process.argv.find(arg => arg.startsWith('--task='))?.slice(7);
    if (!taskId) {
        const queued = await get('StudioManagement/analytics?from=2026-01-01&to=2026-01-07');
        assert.equal(queued.response.status, 202, 'HTTP analytics must return Accepted.');
        assert.equal(queued.data.async, true);
        assert.equal(typeof queued.data.taskId, 'string');
        taskId = queued.data.taskId;
    }
    if (process.argv.includes('--expect-queued')) {
        await sleep(1500);
        const status = await get(`StudioManagement/mq-tasks/${taskId}`);
        assert.equal(status.response.status, 200);
        assert.equal(status.data.status, 'Queued', 'Broker outage must preserve the task in outbox.');
        console.log(`OUTBOX_TASK_ID=${taskId}`);
        return;
    }
    for (let attempt = 0; attempt < 120; attempt++) {
        const status = await get(`StudioManagement/mq-tasks/${taskId}`);
        assert.equal(status.response.status, 200, 'Owner must be able to poll task.');
        if (status.data.status === 'Failed') throw new Error(status.data.error);
        if (status.data.status === 'Completed') {
            assert.equal(status.data.result.from, '2026-01-01');
            assert.equal(status.data.result.to, '2026-01-07');
            assert.equal('_mqAuthorization' in status.data.result, false, 'Internal authorization data must not leak.');
            const raw = await get(`SmMqTask/${taskId}`);
            assert([403, 404].includes(raw.response.status), 'Internal tasks must not have a generic record API.');
            console.log(`RabbitMQ HTTP: Accepted -> Completed; task=${taskId}; generic API blocked.`);
            return;
        }
        await sleep(500);
    }
    throw new Error('HTTP MQ task did not complete within 60 seconds.');
})().catch(error => {console.error(error.message); process.exitCode = 1;});
