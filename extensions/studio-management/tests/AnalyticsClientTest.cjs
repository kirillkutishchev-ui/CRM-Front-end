const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../../..');
let Periods;
vm.runInNewContext(fs.readFileSync(path.join(root,
    'client/custom/modules/studio-management/src/analytics-periods.js'), 'utf8'), {
    define: (deps, factory) => { Periods = factory(); },
});
let checks = 0;
const range = (period, now, zone, from, to) => {
    const actual = Periods.range(period, new Date(now), zone);
    assert.equal(actual.from, from); assert.equal(actual.to, to); checks += 2;
};
range('week', '2026-10-31T12:00:00Z', 'Europe/Berlin', '2026-10-26', '2026-11-01');
range('week', '2026-11-01T12:00:00Z', 'Europe/Berlin', '2026-10-26', '2026-11-01');
range('week', '2026-11-01T23:15:00Z', 'Europe/Berlin', '2026-11-02', '2026-11-08');
range('week', '2026-01-01T12:00:00Z', 'UTC', '2025-12-29', '2026-01-04');
range('month', '2026-10-31T12:00:00Z', 'Europe/Berlin', '2026-10-01', '2026-10-31');
range('month', '2028-02-15T12:00:00Z', 'UTC', '2028-02-01', '2028-02-29');
range('month', '2026-12-31T23:10:00Z', 'Europe/Berlin', '2027-01-01', '2027-01-31');
range('today', '2026-10-30T23:30:00Z', 'Europe/Berlin', '2026-10-31', '2026-10-31');
range('today', '2026-10-31T01:30:00Z', 'America/New_York', '2026-10-30', '2026-10-30');
range('week', '2026-03-29T12:00:00Z', 'Europe/Berlin', '2026-03-23', '2026-03-29');
// Espo passes the matched delegated node as the handler's second argument.
// event.currentTarget refers to the record root, which has no period/tab dataset.
let View;
vm.runInNewContext(fs.readFileSync(path.join(root,
    'client/custom/modules/studio-management/src/views/sm-analytics/index.js'), 'utf8'), {
    define: (deps, factory) => { View = factory(class {}, Periods); },
});
const handlers = new Map();
const view = new View();
view.addHandler = (event, selector, handler) => handlers.set(selector, handler);
view.setup();
let chosenPeriod, chosenTab, loaded = 0;
view.setPeriod = period => { chosenPeriod = period; };
view.selectTab = tab => { chosenTab = tab; };
view.load = () => { loaded++; };
handlers.get('[data-period]')({currentTarget: {dataset: {}}}, {dataset: {period: 'week'}});
assert.equal(chosenPeriod, 'week'); assert.equal(loaded, 1); checks += 2;
handlers.get('[data-analytics-tab]')({preventDefault() {}, currentTarget: {dataset: {}}},
    {dataset: {analyticsTab: 'hours'}});
assert.equal(chosenTab, 'hours'); checks++;
let Controller, Navbar;
vm.runInNewContext(fs.readFileSync(path.join(root,
    'client/custom/modules/studio-management/src/controllers/sm-analytics.js'), 'utf8'), {
    define: (deps, factory) => { Controller = factory(class {}); },
});
vm.runInNewContext(fs.readFileSync(path.join(root,
    'client/custom/modules/studio-management/src/views/site/navbar.js'), 'utf8'), {
    define: (deps, factory) => { Navbar = factory(class {getTabList() {return ['SmShift', 'SmAnalytics'];}},
        {canApproveAccounts: () => false, canAccessOnboarding: () => true}); },
});
for (const [type, allowed] of [['Operator',true],['Model',true],['Recruiter',false]]) {
    const actor = {getUser:()=>({get:()=>type,isAdmin:()=>false}),getAcl:()=>({check:()=>false,getLevel:()=> 'own'}),translate:()=> 'Personal Analytics'};
    const controller = Object.assign(new Controller(), actor);
    assert.equal(controller.checkAccess(), allowed); checks++;
    const list = Object.assign(new Navbar(), actor).getTabList();
    assert.equal(list[1]?.url === '#SmAnalytics', allowed); checks++;
}
for (const language of ['ru_RU', 'en_US']) {
    const locale = JSON.parse(fs.readFileSync(path.join(root,
        `custom/Espo/Modules/StudioManagement/Resources/i18n/${language}/SmAnalytics.json`), 'utf8'));
    const template = fs.readFileSync(path.join(root,
        'client/custom/modules/studio-management/res/templates/sm-analytics/index.tpl'), 'utf8');
    for (const match of template.matchAll(/translate '([^']+)'(?: category='(\w+)')? scope='SmAnalytics'/g)) {
        assert(locale[match[2] || 'labels']?.[match[1]], `Missing ${language}: ${match[1]}`); checks++;
    }
}
console.log(`Analytics client: ${checks} checks passed.`);
