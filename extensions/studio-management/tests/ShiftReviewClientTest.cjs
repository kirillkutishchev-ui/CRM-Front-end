const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../../..');
const prefix = 'client/custom/modules/studio-management/src/';
let checks = 0;
const eq = (a, b) => { assert.equal(a, b); checks++; };
function load(file, deps = {}, globals = {}) {
    let Result;
    vm.runInNewContext(fs.readFileSync(path.join(root, prefix, file), 'utf8'), {
        define: (names, factory) => { Result = factory(...names.map(name => deps[name])); }, ...globals,
    });
    return Result;
}
const policy = load('role-policy.js');
const values = {status: 'Review', producerId: 'producer', operatorId: 'operator'};
let id = 'operator', type = 'Operator', permissions = {};
const actor = {
    model: {id: 'shift', get: name => values[name], isNew: () => false,
        set: (name, value) => typeof name === 'string' ? values[name] = value : Object.assign(values, name),
        setMultiple: data => Object.assign(values, data)},
    getUser: () => ({id, get: () => type, isAdmin: () => false}),
    getAcl: () => ({checkModel: () => true, getPermissionLevel: key => permissions[key] ?? 'not-set'}),
};
class Field { data() { return {}; } }
const Actions = load('views/sm-shift/fields/workflow-actions.js', {'views/fields/base': Field, 'studio-management:role-policy': policy});
const actions = Object.assign(new Actions(), actor);
eq(policy.canReview(actor), false);
eq(actions.data().showEdit, false);
eq(actions.data().showCalculation, false);
id = 'producer'; type = 'ProducerAdmin';
eq(policy.canReview(actor), true);
eq(actions.data().showEdit, true);
eq(actions.data().editMode, 'review');
values.status = 'Closed';
eq(policy.canRevise(actor), false);
eq(actions.data().showEdit, false);
permissions.smShiftRevise = 'team';
eq(policy.canRevise(actor), true);
id = 'other';
eq(policy.canRevise(actor), false);
permissions.smShiftRevise = 'all';
eq(policy.canRevise(actor), true);
id = 'producer'; permissions = {smShiftReview: 'no'};
eq(policy.canReview(actor), false);

const hidden = new Set();
const readOnly = new Set();
let request, requestError;
class Record {
    setup() {} listenTo() {} once() {} setFieldReadOnly(name) { readOnly.add(name); }
    hideField(name) { hidden.add(name); } showField(name) { hidden.delete(name); }
    fetch() { return {}; } translate(name) { return name; }
    enableActionItems() { this.enabled = true; }
    confirm() { return Promise.resolve(); }
    setIsNotChanged() {} getRouter() { return {navigate() {}}; }
}
const Edit = load('views/sm-shift/record/edit.js', {'views/record/edit': Record, 'studio-management:role-policy': policy}, {
    Espo: {Ajax: {postRequest: async (url, data) => { request = {url, data}; if (requestError) throw requestError; return {}; }},
        Ui: {success() {}, notify() {}}},
});
const make = () => {
    const view = Object.assign(new Edit(), actor);
    view.options = {params: {review: true}};
    view.buttonList = [{name: 'save'}];
    view.setup();
    return view;
};
(async () => {
    values.status = 'Review'; id = 'operator'; type = 'Operator'; permissions = {};
    const operatorView = make();
    eq(hidden.has('confirmClosure'), true);
    eq(operatorView.buttonList.length, 0);
    id = 'producer'; type = 'ProducerAdmin';
    const producerView = make();
    eq(hidden.has('confirmClosure'), true); // The native utility field stays hidden; review actions own the checkbox.
    eq(readOnly.has('confirmClosure'), false);
    eq(values.confirmClosure, false);
    await producerView.actionSave();
    eq(request.url, 'SmShift/shift/review');
    eq(request.data.confirmClosure, false);
    values.confirmClosure = true;
    await producerView.actionSave();
    eq(request.data.confirmClosure, true);
    requestError = new Error('API failure');
    await assert.rejects(producerView.actionSave(), /API failure/);
    eq(producerView.enabled, true);
    values.operatorId = 'producer'; // Dual-role user still gets a separate review form with unchecked confirmation.
    make();
    eq(values.confirmClosure, false);
    values.status = 'Closed';
    const closedProducer = make();
    eq(hidden.has('confirmClosure'), true);
    eq(closedProducer.buttonList.length, 0);
    permissions.smShiftRevise = 'all';
    eq(make().buttonList.length, 1);

    const Screenshots = load('views/sm-shift/fields/screenshots.js', {'views/fields/attachment-multiple': class {afterRender() {}}},
        {URL, window: {location: {href: 'http://localhost:8094/'}}, MutationObserver: class {observe() {} disconnect() {}}});
    const link = {dataset: {}, classList: {add: name => eq(name, 'sm-screenshot-preview')},
        getAttribute: () => '/?entryPoint=download&id=image', setAttribute() {}};
    const screenshot = new Screenshots();
    screenshot.name = 'screenshots'; screenshot.translate = () => 'Preview';
    screenshot.model = {get: name => ({screenshotsIds: ['image'], screenshotsNames: {image: 'proof.png'}, screenshotsTypes: {image: 'image/png'}})[name]};
    screenshot.element = {querySelectorAll: selector => selector === 'a[href]' ? [link] : []};
    screenshot.afterRender();
    eq(link.dataset.action, 'showImagePreview');
    eq(link.dataset.id, 'image');
    const image = {src: 'http://localhost:8094/?entryPoint=image&id=image', dataset: {},
        getAttribute() { return this.src; }, closest: () => link};
    screenshot.model = {get: name => ({screenshotsIds: ['image'], screenshotOptimizations: {image: 'optimized'},
        screenshotsNames: {image: 'proof.png'}, screenshotsTypes: {image: 'image/png'}})[name]};
    screenshot.element = {querySelectorAll: selector => selector === 'a[href]' ? [link] : [image]};
    screenshot.decoratePreviews();
    eq(new URL(image.src).searchParams.get('id'), 'optimized');
    eq(image.dataset.originalAttachmentId, 'image');
    eq(link.dataset.id, 'image');
    console.log('Shift review client: ' + checks + ' checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
