const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
function load(relative, Base, Espo = {}, context = {}) {
    let Result;
    vm.runInNewContext(fs.readFileSync(path.join(root, relative), 'utf8'), {
        define: (dependencies, factory) => {
            const args = dependencies.map((name, i) => name === 'studio-management:role-policy'
                ? load('client/custom/modules/studio-management/src/role-policy.js') : i === 0 ? Base : undefined);
            Result = factory(...args);
        }, Espo, ...context,
    });
    return Result;
}
const prefix = 'client/custom/modules/studio-management/src/views/';
let checks = 0;

(async () => {
    class EditPage {
        optionsToPass = ['returnUrl'];
        setup() {
            assert(this.optionsToPass.includes('params'));
        }
    }
    const Page = load(prefix + 'sm-shift/edit.js', EditPage);
    new Page().setup();
    checks++;

    const calls = [];
    const notifications = [];
    let postError;
    class Record {
        setup() {}
        listenTo() {}
        once() {}
        setFieldReadOnly() {}
        hideField() {}
        showField() {}
        fetch() { return {}; }
        translate(value) { return value; }
        setIsNotChanged() {}
        enableActionItems() { this.actionsEnabled = true; }
        confirm() { return Promise.resolve(); }
        getUser() { return {isAdmin: () => true}; }
        getRouter() { return {navigate: url => calls.push(['navigate', url])}; }
    }
    const Edit = load(prefix + 'sm-shift/record/edit.js', Record, {
        Ajax: {postRequest: async (url, data) => {
            calls.push([url, data]);
            if (postError) {
                throw postError;
            }
            return {};
        }},
        Ui: {success() {}, notify: value => notifications.push(value)},
    });
    const create = (status, params) => {
        const view = new Edit();
        view.options = {params};
        view.buttonList = [{name: 'save'}];
        view.model = {
            id: 'test-shift', isNew: () => false,
            get: name => name === 'status' ? status : [], setMultiple() {}, set() {},
        };
        view.setup();
        return view;
    };
    const start = create('Draft', {start: true});
    assert.equal(start.buttonList[0].label, 'Start Shift');
    checks++;
    start.save = () => Promise.reject('notModified');
    await start.actionSave();
    assert(calls.some(([url]) => url === 'SmShift/test-shift/open'));
    assert(calls.some(([url, target]) => url === 'navigate' && target === '#SmShift/view/test-shift'));
    checks += 2;

    calls.length = 0;
    const invalid = create('Draft', {start: true});
    invalid.save = () => Promise.reject('invalid');
    await assert.rejects(invalid.actionSave(), error => error === 'invalid');
    assert.equal(calls.length, 0);
    assert.equal(invalid.actionsEnabled, true);
    assert.equal(notifications.at(-1), false);
    checks++;

    const count = create('Counting', {count: true});
    assert.equal(count.buttonList[0].label, 'Complete Calculation');
    await count.actionSave();
    assert(calls.some(([url]) => url === 'SmShift/test-shift/close'));
    checks += 2;

    class Field {
        getAcl() { return {getPermissionLevel: () => 'not-set', checkScope: () => true, checkModel: () => true}; }
        data() { return {url: '#User/view/model', nameValue: 'Model'}; }
    }
    const Participant = load(prefix + 'sm-shift/fields/participant.js', Field);
    const field = new Participant();
    field.getUser = () => ({isAdmin: () => false, get: () => 'Operator'});
    assert.equal(field.data().profileLinkAllowed, false);
    field.getUser = () => ({isAdmin: () => false, get: () => 'ProducerAdmin'});
    assert.equal(field.data().profileLinkAllowed, true);
    checks += 2;

    class LinkField {
        setup() { this.linkSetupCalled = true; }
        afterRender() { this.linkAfterRenderCalled = true; }
        actionSelect() { this.modalOpened = true; }
        isEditMode() { return true; }
    }
    const TargetRole = load(prefix + 'sm-registration-link/fields/target-role.js', LinkField);
    const targetRole = new TargetRole();
    targetRole.setup();
    assert.equal(targetRole.autocompleteOnEmpty, true);
    assert.equal(targetRole.linkSetupCalled, true);

    assert.equal(targetRole.selectRecordsView, 'studio-management:views/sm-registration-link/select-roles');
    assert.equal(targetRole.createDisabled, true);
    targetRole.actionSelect();
    assert.equal(targetRole.modalOpened, true);
    checks += 3;

    const Footer = load(prefix + 'sm-shift/fields/workflow-footer.js', Field);
    const footer = new Footer();
    let saved = 0;
    const record = {actionSave: async () => { saved++; }};
    footer.getParentView = () => ({getParentView: () => record});
    const button = {disabled: false};
    await footer.runWorkflow({preventDefault() {}, stopPropagation() {}, currentTarget: button}, 'actionSave');
    assert.equal(saved, 1);
    assert.equal(button.disabled, false);
    checks += 2;

    // Both entry points must recover after validation/save failures and failed /open requests.
    for (const failure of ['invalid', {status: 400}, {status: 500}]) {
        const failed = create('Draft', {start: true});
        failed.save = async () => {
            failed.actionsEnabled = false; // Espo disables the toolbar before save.
            if (failure === 'invalid') {
                throw failure;
            }
        };
        postError = failure === 'invalid' ? undefined : failure;
        calls.length = 0;
        notifications.length = 0;
        await assert.rejects(failed.actionSave(), error => error === failure);
        assert.equal(failed.actionsEnabled, true);
        assert.equal(notifications.at(-1), false);
        assert(!calls.some(([url]) => url === 'navigate'));
        assert.equal(calls.filter(([url]) => url.endsWith('/open')).length, failure === 'invalid' ? 0 : 1);
        checks++;

        footer.getParentView = () => failed;
        await footer.runWorkflow({preventDefault() {}, stopPropagation() {}, currentTarget: button}, 'actionSave');
        assert.equal(button.disabled, false);
        assert.equal(failed.actionsEnabled, true);
        checks++;

        // A corrected form can start without refreshing the page.
        postError = undefined;
        failed.save = () => Promise.reject('notModified');
        calls.length = 0;
        await failed.actionSave();
        assert(calls.some(([url]) => url === 'SmShift/test-shift/open'));
        assert(calls.some(([url, target]) => url === 'navigate' && target === '#SmShift/view/test-shift'));
        checks++;
    }

    class View {}
    const stored = new Map();
    const cleanUrls = [];
    const window = {
        location: {
            hash: '#' + 'Ab_9'.repeat(8),
            search: '?entryPoint=studioRegister',
            pathname: '/',
        },
        sessionStorage: {
            setItem: (key, value) => stored.set(key, value),
            getItem: key => stored.get(key) || null,
        },
        history: {
            replaceState: (_state, _title, url) => cleanUrls.push(url),
        },
    };
    const Registration = load(prefix + 'registration.js', View, {}, {
        window,
        document: {title: 'CRM'},
        URLSearchParams,
        TextEncoder,
    });
    const registration = new Registration();
    assert.equal(registration.consumeTokenFromLocation(), 'Ab_9'.repeat(8));
    assert.equal(stored.get('studioAccessToken'), 'Ab_9'.repeat(8));
    assert.equal(cleanUrls.at(-1), '/?entryPoint=studioRegister');
    checks += 3;

    // Previously issued 64-character links remain valid.
    window.location.hash = '#access=' + 'a'.repeat(64);
    assert.equal(registration.consumeTokenFromLocation(), 'a'.repeat(64));
    checks++;

    registration.options = {invitation: {mode: 'pending', displayName: 'Pending User'}};
    assert.equal(registration.data().isPending, true);
    assert.equal(registration.data().isRegistration, false);
    assert.equal(registration.data().isLogin, false);
    const registrationTemplate = fs.readFileSync(path.join(root,
        'client/custom/modules/studio-management/res/templates/registration.tpl'), 'utf8');
    assert.match(registrationTemplate, /registrationPending/);
    checks += 4;

    // A personal-link login must leave the no-router entry point before F5.
    const events = [];
    const redirects = [];
    let session = null;
    let sessionResponse = {user: {id: 'session-user'}};
    let sessionError;
    let requestCount = 0;
    const loginHandlers = {};
    const entryWindow = {
        location: {pathname: '/crm/', search: '?entryPoint=studioRegister', hash: '',
            replace: url => { events.push('redirect'); redirects.push(url); }},
        sessionStorage: {getItem: key => stored.get(key) || null},
    };
    class Controller {
        getStorage() { return {get: () => session}; }
        entire(_viewName, _options, callback) {
            events.push('view');
            callback({
                once: (event, handler) => { loginHandlers[event] = handler; },
                render: () => events.push('render'),
            });
        }
        baseController = {
            normalizeLoginData: (userName, data) => ({userName, data}),
            trigger: (event, data) => { events.push(event); session = data; },
        };
    }
    const RegistrationController = load('client/custom/modules/studio-management/src/controllers/registration.js',
        Controller, {Ajax: {getRequest: async url => {
            assert.equal(url, 'App/user');
            requestCount++;
            if (sessionError) { throw sessionError; }
            return sessionResponse;
        }}}, {window: entryWindow, URLSearchParams});
    const entry = new RegistrationController();
    stored.clear();
    await entry.actionRegister();
    assert.equal(requestCount, 0);
    assert(events.includes('render'));
    loginHandlers.login('user@example.invalid', {token: 'test-session'});
    assert.deepEqual(events.slice(-2), ['login', 'redirect']);
    assert.equal(redirects.at(-1), '/crm/');
    assert(session);
    checks += 3;

    events.length = 0;
    redirects.length = 0;
    await entry.actionRegister();
    assert.equal(requestCount, 1);
    assert.equal(redirects.at(-1), '/crm/');
    assert(!events.includes('view'));
    checks++;

    // An existing session must never swallow another (including invalid) explicit personal link.
    for (const location of [
        {hash: '#' + 'Cd-7'.repeat(8), search: '?entryPoint=studioRegister'},
        {hash: '#access=' + 'b'.repeat(64), search: '?entryPoint=studioRegister'},
        {hash: '#access=invalid', search: '?entryPoint=studioRegister'},
        {hash: '', search: '?entryPoint=studioRegister&token=legacy-token'},
    ]) {
        Object.assign(entryWindow.location, location);
        events.length = 0;
        redirects.length = 0;
        await entry.actionRegister();
        assert(events.includes('view'));
        assert.equal(redirects.length, 0);
        assert.equal(requestCount, 1);
        checks++;
    }
    entryWindow.location.hash = '';
    entryWindow.location.search = '?entryPoint=studioRegister';
    stored.set('studioAccessToken', 'c'.repeat(64));
    events.length = 0;
    await entry.actionRegister();
    assert(events.includes('view'));
    assert.equal(requestCount, 1);
    checks++;
    stored.clear();
    sessionError = {xhr: {status: 401}};
    redirects.length = 0;
    events.length = 0;
    await entry.actionRegister();
    assert.equal(redirects.length, 0);
    assert(events.includes('view'));
    assert.equal(sessionError.xhr.errorIsHandled, true);
    checks++;
    sessionError = null;
    sessionResponse = {};
    redirects.length = 0;
    await entry.actionRegister();
    assert.equal(redirects.length, 0);
    checks++;

    let response = {operatorId: 'operator', modelId: 'model', producerId: 'producer',
        siteData: [{site: 'CB', login: 'model', password: 'fixture'}]};
    const TeamEdit = load(prefix + 'sm-shift/record/edit.js', Record, {
        Ajax: {getRequest: async () => response}, Ui: {error() {}},
    });
    const teamView = new TeamEdit();
    const values = {teamId: 'group', status: 'Draft', modelId: 'old-model'};
    teamView.model = {get: name => values[name], set: data => Object.assign(values, data)};
    await teamView.loadTeamDefaults();
    assert.equal(values.operatorId, 'operator');
    assert.equal(values.producerId, 'producer');
    assert.equal(values.siteData[0].login, 'model');
    checks++;
    values.teamId = null;
    await teamView.loadTeamDefaults();
    assert.equal(values.modelId, null);
    assert.equal(values.producerId, null);
    assert.equal(values.siteData.length, 0);
    checks++;
    values.teamId = 'group-a';
    let resolveDefaults;
    response = new Promise(resolve => { resolveDefaults = resolve; });
    const pending = teamView.loadTeamDefaults();
    values.teamId = 'group-b';
    resolveDefaults({modelId: 'stale-model'});
    await pending;
    assert.notEqual(values.modelId, 'stale-model');
    checks++;

    const publicLinkScope = JSON.parse(fs.readFileSync(path.join(root,
        'custom/Espo/Modules/StudioManagement/Resources/metadata/scopes/SmRegistrationLink.json'), 'utf8'));
    assert.equal(publicLinkScope.languageIsGlobal, true);
    assert.equal(publicLinkScope.languageAclDisabled, true);
    checks++;

    console.log(`Workflow client: ${checks} checks passed.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
