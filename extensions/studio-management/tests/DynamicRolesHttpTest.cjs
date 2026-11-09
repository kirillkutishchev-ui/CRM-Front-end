// Disposable localhost only. Run the paired CleanupDynamicRoles.php after this test.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../../..');
const env = Object.fromEntries(fs.readFileSync(path.join(root, '.env'), 'utf8').split(/\r?\n/)
    .filter(l => l && !l.startsWith('#')).map(l => { const i = l.indexOf('='); return [l.slice(0, i), l.slice(i + 1)]; }));
const base = env.CRM_SITE_URL + '/api/v1/';
if (!['localhost', '127.0.0.1'].includes(new URL(base).hostname)) throw Error('Local disposable CRM only.');
let prefix = 'DynamicRoles ' + crypto.randomBytes(5).toString('hex');
const fixtures = [];
const registry = path.join(root, 'data/tmp/dynamic-roles-fixtures.json');
fs.mkdirSync(path.dirname(registry), {recursive: true});
const track = (type, data) => {
    fixtures.push([type, data.id]);
    fs.writeFileSync(registry, JSON.stringify({prefix, fixtures}));
    return data;
};
const credentials = Buffer.from(env.CRM_ADMIN_USERNAME + ':' + env.CRM_ADMIN_PASSWORD).toString('base64');
const headers = {'Content-Type': 'application/json', Authorization: 'Basic ' + credentials,
    'Espo-Authorization': credentials, 'Espo-Authorization-By-Token': 'false'};
let checks = 0;
const check = (value, message) => { assert(value, message); checks++; };
async function request(url, method = 'GET', body, actor = headers) {
    const res = await fetch(base + url, {method, headers: actor, body: body === undefined ? undefined : JSON.stringify(body)});
    const text = await res.text();
    let data; try { data = JSON.parse(text); } catch { data = text; }
    return {status: res.status, data};
}
async function ok(url, method, body, actor) {
    const result = await request(url, method, body, actor);
    assert.equal(result.status, 200, url + ': ' + JSON.stringify(result.data));
    return result.data;
}
async function create(type, data, actor) {
    if (type === 'SmRegistrationLink') {
        data = {expiresAt: new Date(Date.now() + 86400000).toISOString().slice(0, 19).replace('T', ' '), ...data};
    }
    return track(type, await ok(type, 'POST', data, actor));
}
async function login(type, role) {
    const link = await create('SmRegistrationLink', {accountType: type, targetRoleId: role.id});
    const email = 'dr-' + type.toLowerCase().slice(0, 8) + '-' + prefix.split(' ')[1] + '@example.invalid';
    const password = 'OnlyTest-Aa7' + crypto.randomBytes(12).toString('hex');
    const registration = await ok('StudioManagement/register', 'POST',
        {token: link.token, email, fullName: prefix + ' ' + type, password});
    track('User', {id: registration.userId});
    const basic = Buffer.from(email + ':' + password).toString('base64');
    check(registration.mode === 'pending', type + ': registration enters approval');
    const pendingLink = await request('StudioManagement/invitation', 'GET', undefined,
        {'X-Studio-Access-Token': link.token});
    check(pendingLink.status === 200 && pendingLink.data.mode === 'pending',
        type + ': the personal link shows pending approval');
    const blocked = await request('App/user', 'GET', undefined, {...headers,
        Authorization: 'Basic ' + basic, 'Espo-Authorization': basic, 'X-Studio-Access-Token': link.token});
    check(blocked.status === 502, type + ': login is blocked before recruiter completion');
    await ok('StudioManagement/onboarding/' + registration.userId + '/complete', 'POST', {});
    const session = await ok('App/user', 'GET', undefined, {...headers,
        Authorization: 'Basic ' + basic, 'Espo-Authorization': basic, 'X-Studio-Access-Token': link.token});
    check(session.user.id === registration.userId, type + ': the same password and link work after activation');
    const token = Buffer.from(email + ':' + session.token).toString('base64');
    return {id: registration.userId, headers: {...headers, Authorization: 'Basic ' + token,
        'Espo-Authorization': token, 'Espo-Authorization-By-Token': 'true'}};
}
(async () => {
    const started = Date.now();
    await ok('App/user'); // Warm the native Slim route cache after a Docker rebuild.
    // Native Role CRUD has no role-count/type whitelist. Exercise actual HTTP creation of 400 roles.
    const roles = [];
    if (process.argv.includes('--resume')) {
        const saved = JSON.parse(fs.readFileSync(registry, 'utf8'));
        assert(/^DynamicRoles [a-f0-9]{10}$/.test(saved.prefix));
        assert(saved.fixtures.length === 400 && saved.fixtures.every(([type]) => type === 'Role'));
        prefix = saved.prefix;
        fixtures.push(...saved.fixtures);
        for (let offset = 0; offset < 400; offset += 100) {
            const page = await ok('StudioManagement/registrationRoles?maxSize=100&offset=' + offset +
                '&q=' + encodeURIComponent(prefix + ' catalog'));
            roles.push(...page.list);
        }
    } else {
        for (let offset = 0; offset < 400; offset += 8) {
            const batch = await Promise.allSettled(Array.from({length: 8}, (_, index) =>
                create('Role', {name: prefix + ' catalog ' + String(offset + index).padStart(3, '0')})));
            for (const result of batch) {
                if (result.status === 'rejected') throw result.reason;
                roles.push(result.value);
            }
        }
    }
    check(roles.length === 400, 'Create 400 roles');
    const query = encodeURIComponent(prefix + ' catalog');
    const native = await ok('Role?maxSize=20&offset=380&orderBy=name&order=asc' +
        '&whereGroup[0][type]=textFilter&whereGroup[0][value]=' + query);
    check(native.list.length === 20 && native.list[0].name.endsWith('380') && native.list[19].name.endsWith('399'),
        'Native list supports page 20/20 (core may intentionally omit total count)');
    const seen = new Set();
    for (let offset = 0; offset < 400; offset += 100) {
        const page = await ok('StudioManagement/registrationRoles?maxSize=100&offset=' + offset + '&q=' + query);
        check(page.total === 400 && page.list.length === 100, 'Role catalog page ' + offset);
        page.list.forEach(role => { seen.add(role.id); check(Object.keys(role).sort().join() === 'id,name', 'Catalog only exposes id/name'); });
    }
    check(seen.size === 400, 'All 400 roles reachable without duplicates');
    for (let offset = 0; offset < 32; offset += 8) {
        await Promise.all(roles.slice(offset, offset + 8).map(role =>
            ok('Role/' + role.id, 'PUT', {smShiftOperatePermission: 'all'})));
        check(true, 'Concurrent role saves keep state.php readable');
    }
    const last = roles.find(role => role.name.endsWith('399'));
    const search = await ok('StudioManagement/registrationRoles?where=' + encodeURIComponent(JSON.stringify([
        {type: 'textFilter', value: last.name}])));
    check(search.total === 1 && search.list[0].id === last.id, 'Modal search finds a role after first 20 records');
    const browserSearch = await ok('StudioManagement/registrationRoles?whereGroup[0][type]=textFilter&whereGroup[0][value]=' +
        encodeURIComponent(last.name));
    check(browserSearch.total === 1 && browserSearch.list[0].id === last.id, 'Native browser whereGroup search');
    const edited = await ok('Role/' + last.id, 'PUT', {name: last.name + ' edited',
        smAccountType: 'Model', smShiftOperatePermission: 'all', smSharePercent: 3});
    check(edited.name.endsWith('edited') && edited.smShiftOperatePermission === 'all', 'Existing arbitrary role is editable');
    const allAcl = {
        SmShift: {read: 'all', edit: 'all', create: 'yes', delete: 'all'},
        User: {create: 'yes', read: 'all', edit: 'all', delete: 'all', stream: 'all'},
        Team: {create: 'yes', read: 'all', edit: 'all', delete: 'all', stream: 'all'},
        SmModels: true, SmOperators: true, SmAnalytics: true,
        SmShiftFinancialSnapshot: {read: 'all'},
        SmRegistrationLink: {read: 'all', edit: 'all', create: 'yes', delete: 'all'},
        SmRoleAdministration: true,
    };
    const actorRoles = [];
    const actors = [];
    for (const type of ['Model', 'Operator', 'ProducerAdmin', 'TechnicalDirector', 'Recruiter']) {
        const role = await create('Role', {name: prefix + ' grants ' + type, smAccountType: 'Model',
            userPermission: 'all', assignmentPermission: 'all',
            smShiftPreparePermission: 'all', smShiftOperatePermission: 'all', smShiftReviewPermission: 'all', smShiftRevisePermission: 'all',
            smStaffAccessPermission: 'all', smAnalyticsAccessPermission: 'all', smInvitationsPermission: 'yes',
            data: allAcl});
        actorRoles.push(role);
        const actor = await login(type, role);
        actors.push(actor);
        const session = await ok('App/user', 'GET', undefined, actor.headers);
        check(session.user.smAccountType === type, type + ': role type never changes explicit account type');
        check(session.acl.smShiftOperatePermission === 'all', type + ': native ACL map includes grant');
        check(session.acl.table.Team.create === 'yes', type + ': Team create grant reaches active session');
        if (type === 'Recruiter') {
            check((await request('StudioManagement/onboarding', 'GET', undefined, actor.headers)).status === 200,
                'Recruiter can open the account completion queue');
            for (const resource of ['Team', 'User', 'Role', 'SmShift', 'SmRegistrationLink', 'StudioManagement/analytics']) {
                check((await request(resource, 'GET', undefined, actor.headers)).status === 403,
                    'Recruiter access stays limited despite a role grant: ' + resource);
            }
            continue;
        }
        const managedTeam = await create('Team', {name: prefix + ' managed team ' + type}, actor.headers);
        check(Boolean(managedTeam.id), type + ': delegated Team create permission works on backend');
        const managedTeamEdit = await ok('Team/' + managedTeam.id, 'PUT',
            {name: prefix + ' updated team ' + type}, actor.headers);
        check(managedTeamEdit.name === prefix + ' updated team ' + type,
            type + ': delegated Team edit permission works on backend');
        const managedTeamStream = await ok('Team/' + managedTeam.id + '/stream?maxSize=5',
            'GET', undefined, actor.headers);
        check(Array.isArray(managedTeamStream.list),
            type + ': delegated Team stream permission works on backend');
        check((await request('Team/' + managedTeam.id, 'DELETE', undefined, actor.headers)).status === 200,
            type + ': delegated Team delete permission works on backend');
        const managedUser = await create('User', {
            userName: 'managed-' + type.toLowerCase().slice(0, 8) + '-' + prefix.split(' ')[1] + '@example.invalid',
            smDelegatedUserName: 'managed-' + type.toLowerCase().slice(0, 8) + '-' + prefix.split(' ')[1] + '@example.invalid',
            firstName: 'Managed', lastName: type, type: 'regular', isActive: true,
        }, actor.headers);
        check(Boolean(managedUser.id), type + ': delegated User create permission works on backend');
        const managedEdit = await ok('User/' + managedUser.id, 'PUT', {firstName: 'Updated'}, actor.headers);
        check(managedEdit.firstName === 'Updated', type + ': delegated User edit permission works on backend');
        check((await request('User/' + managedUser.id, 'DELETE', undefined, actor.headers)).status === 200,
            type + ': delegated User delete permission works on backend');
        const delegatedRole = await create('Role', {name: prefix + ' delegated ' + type,
            smAccountType: 'ProducerAdmin', data: {SmModels: true}, smShiftRevisePermission: 'all'}, actor.headers);
        check(delegatedRole.smShiftRevisePermission === 'all', type + ': can create any role with any grant');
        const delegatedEdit = await ok('Role/' + delegatedRole.id, 'PUT',
            {smAccountType: 'Recruiter', smShiftOperatePermission: 'all'}, actor.headers);
        check(delegatedEdit.smAccountType === 'Recruiter', type + ': delegated role update');
        const catalog = await ok('StudioManagement/registrationRoles?maxSize=1&q=' + query, 'GET', undefined, actor.headers);
        check(catalog.total === 400, type + ': delegated invitation role catalog');
        const invite = await create('SmRegistrationLink', {accountType: 'Model', targetRoleId: delegatedRole.id}, actor.headers);
        check(Boolean(invite.token), type + ': can invite with unrelated role type');
        check((await request('StudioManagement/staff/model', 'GET', undefined, actor.headers)).status === 200,
            type + ': staff grant overrides hardcoded type');
        check((await request('StudioManagement/analytics?from=2026-01-01&to=2026-12-31', 'GET', undefined, actor.headers)).status === 200,
            type + ': analytics grant');
    }
    const user = async type => create('User', {name: prefix + ' ' + type, firstName: prefix, lastName: type,
        userName: 'dynrole-' + crypto.randomBytes(6).toString('hex'), type: 'regular', isActive: true, smAccountType: type,
        ...(type === 'Model' ? {smSiteData: [{site: 'CB', login: 'test-only', password: 'test-only'}]} : {})});
    const producer = await user('ProducerAdmin'), operator = await user('Operator'), model = await user('Model');
    const team = await create('Team', {name: prefix + ' team', smProducerId: producer.id,
        smOperatorId: operator.id, smModelId: model.id});
    const actor = actors[0]; // A model with explicit grants operates somebody else's shift.
    const defaults = await ok('StudioManagement/teams/' + team.id + '/shiftDefaults', 'GET', undefined, actor.headers);
    check(defaults.modelId === model.id && defaults.operatorId === operator.id, 'Granted model loads unrelated team defaults');
    const shift = await create('SmShift', {name: prefix + ' shift', teamId: team.id, currency: 'USD'}, actor.headers);
    check(shift.operatorId === operator.id, 'Granted model creates shift without occupying operator slot');
    for (const action of ['open', 'finish']) {
        const value = await ok('SmShift/' + shift.id + '/' + action, 'POST', {}, actor.headers);
        check(value.status === (action === 'open' ? 'Open' : 'Counting'), 'Granted model: ' + action);
    }
    const siteData = defaults.siteData.map(row => ({...row, earning: '12.00', offlineBonus: '2.00'}));
    const submitted = await ok('SmShift/' + shift.id + '/close', 'POST', {siteData, screenshotsIds: []}, actor.headers);
    check(submitted.status === 'Review', 'Granted user still submits to review');
    const closed = await ok('SmShift/' + shift.id + '/review', 'POST', {siteData, screenshotsIds: [], confirmClosure: true}, actor.headers);
    check(closed.status === 'Closed' && Number(closed.totalGross) === 14, 'Granted model closes shift');
    const revised = await ok('SmShift/' + shift.id + '/revise', 'POST', {siteData, screenshotsIds: []}, actor.headers);
    check(revised.status === 'Closed', 'Granted model revises closed shift');
    const analytics = await ok('StudioManagement/analytics?from=2026-01-01&to=2026-12-31', 'GET', undefined, actor.headers);
    check(analytics.rows.some(row => row.teamId === team.id), 'All-analytics grant includes unrelated production');
    const denied = {smShiftPreparePermission: 'no', smShiftOperatePermission: 'no', smShiftReviewPermission: 'no', smShiftRevisePermission: 'no',
        smStaffAccessPermission: 'no', smAnalyticsAccessPermission: 'no', smInvitationsPermission: 'no'};
    for (let index = 0; index < actors.length; index++) {
        const viewer = actors[index];
        await ok('Role/' + actorRoles[index].id, 'PUT', {...denied, data: {...allAcl, SmRoleAdministration: false}});
        const session = await ok('App/user', 'GET', undefined, viewer.headers);
        check(session.acl.smShiftOperatePermission === 'no', 'Active session receives revoked rights');
        check((await request('Role', 'POST', {name: prefix + ' forbidden'}, viewer.headers)).status === 403, 'Role management revoked');
        check((await request('SmRegistrationLink', 'POST', {accountType: 'Model', targetRoleId: last.id}, viewer.headers)).status === 403, 'Invitation grant revoked');
        check((await request('StudioManagement/registrationRoles', 'GET', undefined, viewer.headers)).status === 403, 'Catalog grant revoked');
        check((await request('StudioManagement/staff/model', 'GET', undefined, viewer.headers)).status === 403, 'Staff grant revoked, including producer');
        check((await request('StudioManagement/teams/' + team.id + '/shiftDefaults', 'GET', undefined, viewer.headers)).status === 403, 'Credentials grant revoked');
        check((await request('SmShift/' + shift.id + '/revise', 'POST', {siteData, screenshotsIds: []}, viewer.headers)).status === 403, 'Revision grant revoked');
        for (const action of ['open', 'finish', 'close']) {
            check((await request('SmShift/' + shift.id + '/' + action, 'POST',
                {siteData, screenshotsIds: []}, viewer.headers)).status === 403, action + ' grant revoked');
        }
        check((await request('SmShift', 'POST', {teamId: team.id, currency: 'USD'}, viewer.headers)).status === 403,
            'Preparation grant revoked');
        const hidden = await request('StudioManagement/analytics?from=2026-01-01&to=2026-12-31', 'GET', undefined, viewer.headers);
        if (['Model', 'Operator'].includes(session.user.smAccountType)) {
            check(hidden.status === 200 && hidden.data.personalOnly === true && !hidden.data.rows && !hidden.data.totals,
                'Revoked studio grant leaves only built-in personal metrics');
        } else {
            check(hidden.status === 403, 'Studio analytics revoked, including technical director');
        }
    }
    // Invitation catalog permission must not imply permission to manage Role records.
    await ok('Role/' + actorRoles[0].id, 'PUT', {smInvitationsPermission: 'yes'});
    check((await request('StudioManagement/registrationRoles', 'GET', undefined, actor.headers)).status === 200, 'Invite-only role can select roles');
    check((await request('Role', 'GET', undefined, actor.headers)).status === 403, 'Invite-only role cannot read Role ACL matrices');
    check((await request('Role', 'POST', {name: prefix + ' forbidden'}, actor.headers)).status === 403, 'Invite-only role cannot create Role records');
    // Studio grants supplement native ACL, never replace it.
    await ok('Role/' + actorRoles[0].id, 'PUT', {smShiftPreparePermission: 'all', smShiftOperatePermission: 'all',
        smShiftRevisePermission: 'all', smStaffAccessPermission: 'all', smAnalyticsAccessPermission: 'all',
        data: {...allAcl, SmRoleAdministration: false, SmShift: {read: 'all', edit: 'no', create: 'no'},
            SmModels: false, SmAnalytics: false}});
    check((await request('SmShift/' + shift.id + '/revise', 'POST', {siteData, screenshotsIds: []}, actor.headers)).status === 403,
        'Native shift ACL still denies an explicitly granted action');
    check((await request('StudioManagement/teams/' + team.id + '/shiftDefaults', 'GET', undefined, actor.headers)).status === 403,
        'Native create ACL still protects credentials');
    check((await request('StudioManagement/staff/model', 'GET', undefined, actor.headers)).status === 403,
        'Native staff tab ACL is authoritative');
    const personalOnly = await request('StudioManagement/analytics?from=2026-01-01&to=2026-12-31', 'GET', undefined, actor.headers);
    check(personalOnly.status === 200 && personalOnly.data.personalOnly === true && !personalOnly.data.rows && !personalOnly.data.totals,
        'Native analytics denial still protects studio finances; model retains personal metrics only');
    console.log('Dynamic roles HTTP: ' + checks + ' checks passed; 400 roles; ' + ((Date.now() - started) / 1000).toFixed(1) + 's. Run fixture cleanup.');
})().catch(error => { console.error(error.message); process.exitCode = 1; });
