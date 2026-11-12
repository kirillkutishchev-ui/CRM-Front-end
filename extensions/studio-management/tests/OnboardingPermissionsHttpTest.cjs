// Run only against the isolated RabbitMQ local CRM; removes only its own fixtures.
const fs = require('node:fs');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const env = Object.fromEntries(fs.readFileSync('.env.mq-local', 'utf8').split(/\r?\n/)
    .filter(line => line && !line.startsWith('#')).map(line => {
        const i = line.indexOf('='); return [line.slice(0, i), line.slice(i + 1)];
    }));
const base = new URL('/api/v1/', env.CRM_SITE_URL);
assert(['localhost', '127.0.0.1'].includes(base.hostname));
const basic = Buffer.from(`${env.CRM_ADMIN_USERNAME}:${env.CRM_ADMIN_PASSWORD}`).toString('base64');
const admin = {'Content-Type': 'application/json', 'Espo-Authorization': basic, 'Espo-Authorization-By-Token': 'false'};
const suffix = crypto.randomBytes(6).toString('hex');
const fixtures = [];
let checks = 0;
async function request(route, method = 'GET', body, headers = admin) {
    const response = await fetch(new URL(route, base), {method, headers, signal: AbortSignal.timeout(15000),
        body: body === undefined ? undefined : JSON.stringify(body)});
    const text = await response.text();
    let data; try { data = JSON.parse(text); } catch { data = text; }
    return {status: response.status, data};
}
async function ok(route, method, body, headers) {
    const result = await request(route, method, body, headers);
    assert.equal(result.status, 200, `${method || 'GET'} ${route}: ${JSON.stringify(result.data)}`); checks++;
    return result.data;
}
async function create(type, body) {
    const entity = await ok(type, 'POST', body); fixtures.push([type, entity.id]); return entity;
}
async function register(type, role, recruiterId) {
    const link = await create('SmRegistrationLink', {name: `Approval ${suffix} ${type}`, accountType: type,
        targetRoleId: role.id, recruiterId, neverExpires: true, expiresAt: '2200-01-01 00:00:00'});
    const email = `approval-${crypto.randomBytes(4).toString('hex')}@example.invalid`;
    const password = `Approval-Test-Aa7-${suffix}`;
    const result = await ok('StudioManagement/register', 'POST', {token: link.token, email,
        fullName: `Approval ${suffix} ${type}`, password});
    fixtures.push(['User', result.userId]);
    assert.equal(result.mode, 'pending'); checks++;
    const encoded = Buffer.from(`${email}:${password}`).toString('base64');
    return {id: result.userId, link, headers: {...admin, 'Espo-Authorization': encoded, 'X-Studio-Access-Token': link.token}};
}
(async () => {
    try {
        const full = await create('Role', {name: `Approval full ${suffix}`, userPermission: 'all',
            smStaffAccessPermission: 'all', smInvitationsPermission: 'yes',
            data: {User: {read: 'all', edit: 'all', create: 'yes', delete: 'all'}}, fieldData: {User: {}}});
        const limited = await create('Role', {name: `Approval limited ${suffix}`, smStaffAccessPermission: 'all',
            smInvitationsPermission: 'no', data: {User: {read: 'all', edit: 'all'}}});
        const approvalOnly = await create('Role', {name: `Approval only ${suffix}`,
            smAccountOnboardingPermission: 'yes', smStaffAccessPermission: 'no', smInvitationsPermission: 'no', data: {}});
        const recruiter = await register('Recruiter', limited);
        await ok(`StudioManagement/onboarding/${recruiter.id}/complete`, 'POST', {});
        const managers = [];
        for (const type of ['Operator', 'Model']) {
            const actor = await register(type, full);
            await ok(`StudioManagement/onboarding/${actor.id}/complete`, 'POST', {});
            const session = await ok('App/user', 'GET', undefined, actor.headers);
            assert.equal(session.user.type, 'regular'); checks++;
            managers.push(actor);
        }
        const denied = await register('Operator', limited);
        await ok(`StudioManagement/onboarding/${denied.id}/complete`, 'POST', {});
        const target = await register('Operator', limited, recruiter.id);
        const target2 = await register('Model', limited, recruiter.id);
        const delegated = await register('Operator', approvalOnly);
        await ok(`StudioManagement/onboarding/${delegated.id}/complete`, 'POST', {});
        const target3 = await register('Model', limited, recruiter.id);
        for (const [label, actor, targetUser] of [
            ['legacy Operator', managers[0], target],
            ['legacy Model', managers[1], target2],
            ['approval-only Operator', delegated, target3],
        ]) {
            const queueResult = await request('StudioManagement/onboarding', 'GET', undefined, actor.headers);
            assert.equal(queueResult.status, 200, `${label} queue: ${JSON.stringify(queueResult.data)}`); checks++;
            const queue = queueResult.data;
            assert(queue.list.some(user => user.id === targetUser.id)); checks++;
            await ok(`StudioManagement/onboarding/${targetUser.id}`, 'PUT', {smContact: 'Test contact'}, actor.headers);
            await ok(`StudioManagement/onboarding/${targetUser.id}/complete`, 'POST', {}, actor.headers);
            const invitation = await ok('StudioManagement/invitation', 'GET', undefined,
                {'X-Studio-Access-Token': targetUser.link.token});
            assert.equal(invitation.mode, 'login'); checks++;
            const login = await ok('App/user', 'GET', undefined, targetUser.headers);
            assert.equal(login.user.id, targetUser.id); checks++;
        }
        assert.equal((await request('StudioManagement/onboarding', 'GET', undefined, denied.headers)).status, 403); checks++;
        await ok('Role/' + full.id, 'PUT', {smAccountOnboardingPermission: 'no'});
        assert.equal((await request('StudioManagement/onboarding', 'GET', undefined, managers[0].headers)).status, 403); checks++;
        await ok('Role/' + approvalOnly.id, 'PUT', {smAccountOnboardingPermission: 'no'});
        assert.equal((await request('StudioManagement/onboarding', 'GET', undefined, delegated.headers)).status, 403); checks++;
        console.log(`Onboarding permissions HTTP: ${checks} checks passed; non-admin Operator/Model approve accounts, limited roles denied.`);
    } finally {
        for (const [type, id] of fixtures.reverse()) {
            const result = await request(`${type}/${id}`, 'DELETE');
            if (![200, 204, 404].includes(result.status)) throw new Error(`Fixture cleanup failed: ${type}/${id}`);
        }
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
