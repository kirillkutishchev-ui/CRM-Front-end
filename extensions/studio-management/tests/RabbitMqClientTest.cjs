const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../../..');
let checks = 0;
const equal = (actual, expected, message) => {
    assert.equal(actual, expected, message);
    checks++;
};
const load = (file, dependencies, Espo, extra = {}) => {
    let View;
    vm.runInNewContext(fs.readFileSync(path.join(root, file), 'utf8'), {
        define: (names, factory) => { View = factory(...names.map(name => dependencies[name])); },
        Espo,
        setTimeout: callback => { callback(); return 1; },
        clearTimeout: () => {},
        ...extra,
    });
    return View;
};

(async () => {
    const TaskWakeup = load('client/custom/modules/studio-management/src/mq-task-wakeup.js', {}, {});
    let subscription;
    let unsubscribed = false;
    const Wakeup = load('client/custom/modules/studio-management/src/mq-task-wakeup.js', {}, {}, {
        setTimeout: () => 1,
    });
    const wake = Wakeup({getHelper: () => ({webSocketManager: {
        subscribe: (topic, callback) => { equal(topic, 'studioMqTaskCompleted'); subscription = callback; },
        unsubscribe: (topic, callback) => { unsubscribed = callback === subscription; },
    }})}, 'expected');
    let settled = false;
    const waiting = wake.wait().then(() => { settled = true; });
    subscription('studioMqTaskCompleted', {taskId: 'other'});
    await Promise.resolve();
    equal(settled, false, 'Other task events must not wake the pending result.');
    subscription('studioMqTaskCompleted', {taskId: 'expected'});
    await waiting;
    equal(settled, true, 'Matching real-time event must immediately wake result retrieval.');
    wake.dispose();
    equal(unsubscribed, true, 'Completed task must release only its own subscription.');
    const requests = [];
    let responses = [];
    const Analytics = load('client/custom/modules/studio-management/src/views/sm-analytics/index.js', {
        view: class {},
        'studio-management:analytics-periods': {},
        'studio-management:mq-task-wakeup': TaskWakeup,
    }, {Ajax: {getRequest: async url => { requests.push(url); return responses.shift(); }}});
    const analytics = new Analytics();
    analytics.requestNumber = 1;
    analytics.isRemoved = () => false;
    responses = [
        {status: 'Queued'}, {status: 'Published'}, {status: 'Processing'},
        {status: 'Completed', result: {totals: [{currency: 'USD', totalGross: '5.00'}]}},
    ];
    const result = await analytics.waitForTask('task/with spaces', 1);
    equal(result.totals[0].totalGross, '5.00', 'Completed analytics must return the worker result.');
    equal(requests.length, 4, 'Analytics must continue polling through every pending status.');
    equal(requests[0], 'StudioManagement/mq-tasks/task%2Fwith%20spaces', 'Task ID must be URL encoded.');

    responses = [{status: 'Failed', error: 'Dates were rejected'}];
    await assert.rejects(analytics.waitForTask('failed', 1), /Dates were rejected/);
    checks++;
    const priorRequests = requests.length;
    const stale = await analytics.waitForTask('stale', 0);
    equal(Object.keys(stale).length, 0, 'Superseded analytics request must return without rendering old data.');
    equal(requests.length, priorRequests, 'Superseded analytics must not create more HTTP requests.');
    analytics.isRemoved = () => true;
    await analytics.waitForTask('removed', 1);
    equal(requests.length, priorRequests, 'Removed analytics view must stop polling.');

    const calls = [];
    let shiftResponses = [];
    let waitForResponse;
    const navigation = [];
    const successes = [];
    class Record {
        fetch() { return {}; }
        confirm() { return Promise.resolve(); }
        translate(value) { return value; }
        setIsNotChanged() { calls.push('unchanged'); }
        enableActionItems() { calls.push('enabled'); }
        getRouter() { return {navigate: target => navigation.push(target)}; }
    }
    const Shift = load('client/custom/modules/studio-management/src/views/sm-shift/record/edit.js', {
        'views/record/edit': Record,
        'studio-management:role-policy': {canReview: () => true, canRevise: () => true},
        'studio-management:mq-task-wakeup': TaskWakeup,
    }, {
        Ajax: {
            postRequest: async (url, body) => {
                calls.push({url, body});
                return {async: true, taskId: 'approval-task'};
            },
            getRequest: async url => {
                calls.push({url});
                if (waitForResponse) return waitForResponse;
                return shiftResponses.shift();
            },
        },
        Ui: {success: message => successes.push(message), notify: value => calls.push({notify: value})},
    });
    const shift = new Shift();
    shiftResponses = [{status: 'Processing'}, {status: 'Completed', result: {shift: {id: 'shift-id', status: 'Closed'}}}];
    const approved = await shift.waitForMqTask('approval/task');
    equal(approved.status, 'Closed', 'Shift polling must extract the approved shift from the task result.');
    equal(calls[0].url, 'StudioManagement/mq-tasks/approval%2Ftask', 'Shift task ID must be URL encoded.');
    shiftResponses = [{status: 'Failed', error: 'Screenshot is invalid'}];
    await assert.rejects(shift.waitForMqTask('failed'), /Screenshot is invalid/);
    checks++;

    // Approval must not announce success or navigate away before the worker
    // returns the financial transaction's resulting Closed record.
    const values = {status: 'Review', confirmClosure: true, siteData: [], screenshotsIds: [], description: 'review'};
    const assigned = [];
    shift.options = {params: {review: true}};
    shift.model = {
        id: 'shift-id',
        isNew: () => false,
        get: name => values[name],
        setMultiple() {},
        set: data => { assigned.push(data); Object.assign(values, data); },
    };
    let resolveWorker;
    waitForResponse = new Promise(resolve => { resolveWorker = resolve; });
    const saving = shift.actionSave();
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    equal(successes.length, 0, 'Queued approval must not announce financial success.');
    equal(navigation.length, 0, 'Queued approval must keep the view until task completion.');
    equal(assigned.length, 0, 'Queue receipt must not be mistaken for a shift record.');
    resolveWorker({status: 'Completed', result: {shift: {id: 'shift-id', status: 'Closed'}}});
    await saving;
    equal(values.status, 'Closed', 'Successful async approval must update the model.');
    equal(successes[0], 'shiftApproved', 'Successful worker result must announce approval.');
    equal(navigation[0], '#SmShift/view/shift-id', 'Successful worker result must open the shift.');

    // Failed async approval preserves Review and restores the action toolbar.
    waitForResponse = null;
    shiftResponses = [{status: 'Failed', error: 'Financial validation failed'}];
    values.status = 'Review';
    const navigationBeforeFailure = navigation.length;
    await assert.rejects(shift.actionSave(), /Financial validation failed/);
    checks++;
    equal(values.status, 'Review', 'Failed approval must preserve the current shift status.');
    equal(navigation.length, navigationBeforeFailure, 'Failed approval must not leave the form.');
    equal(calls.includes('enabled'), true, 'Failed approval must restore action buttons.');

    console.log(`RabbitMQ client: ${checks} checks passed.`);
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
