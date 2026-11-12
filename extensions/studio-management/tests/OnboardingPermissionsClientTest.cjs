const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const root = path.resolve(__dirname, '../../../client/custom/modules/studio-management/src');
function load(file, deps = {}) {
    let value;
    vm.runInNewContext(fs.readFileSync(path.join(root, file), 'utf8'), {
        define: (names, factory) => { value = factory(...names.map(name => deps[name])); },
    });
    return value;
}
const policy = load('role-policy.js');
const Controller = load('controllers/sm-account-onboarding.js', {controller: class {}, 'studio-management:role-policy': policy});
const Navbar = load('views/site/navbar.js', {'views/site/navbar': class {getTabList() {return ['SmShift'];}},
    'studio-management:role-policy': policy});
let checks = 0;
const check = (value, expected) => { assert.equal(value, expected); checks++; };
for (const type of ['Model', 'Operator', 'ProducerAdmin', 'TechnicalDirector', 'Recruiter']) {
    const permissions = {smStaffAccess: 'all', smInvitations: 'yes'};
    const levels = {read: 'all', edit: 'all'};
    const actor = {getUser: () => ({isAdmin: () => false, get: key => key === 'type' ? 'regular' : type}),
        getAcl: () => ({getPermissionLevel: key => permissions[key] || 'not-set',
            getLevel: (scope, action) => levels[action], check: () => true}),
        translate: value => value};
    const controller = Object.assign(new Controller(), actor);
    const navbar = Object.assign(new Navbar(), actor);
    check(policy.canApproveAccounts(actor), type !== 'Recruiter');
    check(controller.checkAccess(), true);
    check(navbar.getTabList().some(item => item.url === '#SmAccountOnboarding'), true);
    if (type !== 'Recruiter') {
    for (const missing of ['smStaffAccess', 'smInvitations']) {
        const previous = permissions[missing]; permissions[missing] = 'no';
        check(controller.checkAccess(), false);
        check(navbar.getTabList().some(item => item.url === '#SmAccountOnboarding'), false);
        permissions[missing] = previous;
    }
    levels.edit = 'own'; check(controller.checkAccess(), false); levels.edit = 'all';
    }
    permissions.smAccountOnboarding = 'yes';
    permissions.smStaffAccess = permissions.smInvitations = 'no';
    levels.read = levels.edit = 'no';
    check(controller.checkAccess(), true);
    check(navbar.getTabList().some(item => item.url === '#SmAccountOnboarding'), true);
    permissions.smAccountOnboarding = 'no';
    permissions.smStaffAccess = 'all'; permissions.smInvitations = 'yes';
    levels.read = levels.edit = 'all';
    check(controller.checkAccess(), false);
    check(navbar.getTabList().some(item => item.url === '#SmAccountOnboarding'), false);
}
check(policy.canApproveAccounts({getUser: () => ({isAdmin: () => true})}), true);
console.log(`Onboarding permissions client: ${checks} checks passed.`);
