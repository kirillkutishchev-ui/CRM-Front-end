// Run only against a disposable localhost CRM. --role-id must be saved from the real role editor.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../../..');
const env = Object.fromEntries(fs.readFileSync(path.join(root, '.env'), 'utf8').split(/\r?\n/)
    .filter(line => line && !line.startsWith('#')).map(line => {
        const i = line.indexOf('='); return [line.slice(0, i), line.slice(i + 1)];
    }));
const base = env.CRM_SITE_URL + '/api/v1/';
assert(['localhost', '127.0.0.1'].includes(new URL(base).hostname), 'Disposable localhost only');
const roleId = process.argv[process.argv.indexOf('--role-id') + 1];
assert(/^[a-f0-9]{17}$/.test(roleId), 'Pass the real UI-saved role ID');
const registry = path.join(root, 'data/tmp/handoff-access-fixtures.json');
const previous = fs.existsSync(registry) ? JSON.parse(fs.readFileSync(registry, 'utf8')) : null;
assert(!previous || /^HandoffTest [a-f0-9]{10}$/.test(previous.prefix), 'Valid previous fixture registry');
const prefix = previous?.prefix || 'HandoffTest ' + crypto.randomBytes(5).toString('hex');
const adminBasic = Buffer.from(env.CRM_ADMIN_USERNAME + ':' + env.CRM_ADMIN_PASSWORD).toString('base64');
const admin = {'Content-Type': 'application/json', Authorization: 'Basic ' + adminBasic,
    'Espo-Authorization': adminBasic, 'Espo-Authorization-By-Token': 'false'};
const fixtures = previous?.fixtures || [];
let checks = 0;
const check = (value, label) => { assert(value, label); checks++; };
async function req(url, method = 'GET', body, headers = admin) {
    const res = await fetch(base + url, {method, headers, body: body === undefined ? undefined : JSON.stringify(body)});
    const text = await res.text();
    let data; try { data = JSON.parse(text); } catch { data = text; }
    return {status: res.status, data};
}
async function ok(url, method, body, headers) {
    const res = await req(url, method, body, headers);
    assert.equal(res.status, 200, url + ': ' + JSON.stringify(res.data)); return res.data;
}
function track(type, data) {
    fixtures.push([type, data.id]);
    fs.mkdirSync(path.join(root, 'data/tmp'), {recursive: true});
    fs.writeFileSync(path.join(root, 'data/tmp/handoff-access-fixtures.json'), JSON.stringify({prefix, fixtures}));
    return data;
}
async function create(type, data, headers) { return track(type, await ok(type, 'POST', data, headers)); }
(async () => {
    const adminSession = await ok('App/user');
    check(adminSession.user.type === 'admin', 'Actual native administrator, not merely ProducerAdmin');
    check(adminSession.acl.table.Role?.read === 'all' && adminSession.acl.table.Role?.create === 'yes',
        'Native admin Role grant is explicit and independent of delegated rights');
    check(Array.isArray((await ok('Role?maxSize=1')).list), 'Native admin opens Role list');
    const role = await ok('Role/' + roleId);
    check(role.name === 'Handoff unrestricted UI test', 'Role was saved from the actual UI button');
    for (const scope of ['Team', 'User', 'SmShift', 'SmRegistrationLink']) {
        check(role.data[scope].create === 'yes', scope + ': UI button saved create permission');
        check(role.data[scope].read === 'all', scope + ': UI button saved all read permission');
        check(role.data[scope].edit === 'all', scope + ': UI button saved all edit permission');
    }
    check(role.data.SmRoleAdministration === true, 'UI button really saved Role administration');
    check(Object.values(role.fieldData).every(fields => Object.keys(fields).length === 0),
        'UI button removed all field restrictions (native save retains empty scope maps)');
    for (const field of ['smShiftPreparePermission', 'smShiftOperatePermission', 'smShiftReviewPermission', 'smShiftRevisePermission',
        'smStaffAccessPermission', 'smAnalyticsAccessPermission']) check(role[field] === 'all', field);
    const link = await create('SmRegistrationLink', {name: prefix + ' invitation', accountType: 'Operator',
        targetRoleId: roleId, expiresAt: new Date(Date.now() + 86400000).toISOString().slice(0, 19).replace('T', ' ')});
    const email = 'handoff-' + crypto.randomBytes(6).toString('hex') + '@example.invalid';
    const password = 'Disposable-Aa9' + crypto.randomBytes(12).toString('hex');
    const registration = await ok('StudioManagement/register', 'POST',
        {token: link.token, email, fullName: prefix + ' Operator', password});
    track('User', {id: registration.userId});
    const basic = Buffer.from(email + ':' + password).toString('base64');
    check(registration.mode === 'pending', 'Registration waits for profile completion');
    const blocked = await request('App/user', 'GET', undefined, {...admin,
        Authorization: 'Basic ' + basic, 'Espo-Authorization': basic, 'X-Studio-Access-Token': link.token});
    check(blocked.status === 502, 'Pending account login returns HTTP 502');
    await ok('StudioManagement/onboarding/' + registration.userId + '/complete', 'POST', {});
    const session = await ok('App/user', 'GET', undefined, {...admin,
        Authorization: 'Basic ' + basic, 'Espo-Authorization': basic, 'X-Studio-Access-Token': link.token});
    check(session.user.type === 'regular', 'Test account is not a native admin');
    check(session.user.smAccountType === 'Operator', 'Business type remains Operator');
    check(session.acl.table.SmRoleAdministration === true, 'Effective Role administration grant');
    check(session.acl.table.Team.create === 'yes', 'Effective Team create grant');
    const tokenBasic = Buffer.from(email + ':' + session.token).toString('base64');
    const actor = {...admin, Authorization: 'Basic ' + tokenBasic, 'Espo-Authorization': tokenBasic,
        'Espo-Authorization-By-Token': 'true'};
    fs.writeFileSync(path.join(root, 'data/tmp/handoff-access-login.json'),
        JSON.stringify({email, password, accessToken: link.token, roleId, userId: registration.userId}));
    const roleSearch = await ok('Role?maxSize=5&q=' + encodeURIComponent(role.name), 'GET', undefined, actor);
    check(roleSearch.list.some(item => item.id === role.id), 'Unrestricted account actually sees UI-saved role in list');
    const child = await create('Role', {name: prefix + ' created by unrestricted account'}, actor);
    check(Boolean(child.id), 'Unrestricted account creates roles');
    check((await ok('Role/' + child.id, 'PUT', {name: prefix + ' edited role'}, actor)).name.endsWith('edited role'), 'Edits roles');
    const person = async type => {
        const userName = 'handoff-' + crypto.randomBytes(7).toString('hex');
        return create('User', {firstName: prefix, lastName: type,
        userName, smDelegatedUserName: userName, type: 'regular', isActive: true,
        smAccountType: type, ...(type === 'Model' ? {smSiteData: [{site:'CB', login:'fixture', password:'fixture'}]} : {})}, actor);
    };
    const producer = await person('ProducerAdmin'), model = await person('Model');
    check(Boolean(producer.id && model.id), 'Creates staff accounts');
    const team = await create('Team', {name: prefix + ' complete group', smProducerId: producer.id,
        smOperatorId: registration.userId, smModelId: model.id}, actor);
    check(team.smProducerId === producer.id && team.smOperatorId === registration.userId && team.smModelId === model.id,
        'Creates complete producer/operator/model group');
    const defaults = await ok('StudioManagement/teams/' + team.id + '/shiftDefaults', 'GET', undefined, actor);
    check(defaults.modelId === model.id && defaults.operatorId === registration.userId, 'Loads all group slots');
    const shift = await create('SmShift', {name: prefix + ' shift', teamId: team.id, currency:'USD'}, actor);
    check(shift.operatorId === registration.userId && shift.modelId === model.id, 'Creates shift from group');
    const open = await ok('SmShift/' + shift.id + '/open', 'POST', {}, actor);
    check(open.status === 'Open' && Boolean(open.startTime), 'Opens shift');
    check((await ok('SmShift/' + shift.id + '/finish', 'POST', {}, actor)).status === 'Counting', 'Finishes active shift');
    const siteData = defaults.siteData.map(row => ({...row, earning:'100.00', offlineBonus:'10.00'}));
    const submitted = await ok('SmShift/' + shift.id + '/close', 'POST', {siteData, screenshotsIds:[]}, actor);
    check(submitted.status === 'Review', 'Submission never bypasses review, even with unrestricted rights');
    const closed = await ok('SmShift/' + shift.id + '/review', 'POST', {siteData, screenshotsIds:[], confirmClosure:true}, actor);
    check(closed.status === 'Closed' && Number(closed.totalGross) === 110, 'Closes shift with persisted total');
    check((await ok('SmShift/' + shift.id + '/revise', 'POST', {siteData, screenshotsIds:[]}, actor)).status === 'Closed',
        'Can revise closed shift');
    const fresh = await ok('App/user', 'GET', undefined, actor);
    check(fresh.acl.table.SmRoleAdministration === true, 'Permission survives session reload');
    console.log('Handoff actual-button role: ' + checks + ' checks passed.');
})().catch(error => { console.error(error.message); process.exitCode = 1; });
