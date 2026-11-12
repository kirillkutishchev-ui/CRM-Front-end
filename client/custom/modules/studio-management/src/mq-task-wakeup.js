define([], function () {
    // Notifications wake HTTP polling; HTTP still enforces the current ACL and
    // recovers missed events, reconnections and browsers without WebSocket.
    return function (view, taskId) {
        let wake;
        let timer;
        const manager = view.getHelper?.().webSocketManager;
        const callback = (topic, data) => {
            if (data?.taskId === taskId) wake?.();
        };
        const dispose = () => {
            manager?.unsubscribe('studioMqTaskCompleted', callback);
            view.off?.('remove', dispose);
            wake?.();
        };
        manager?.subscribe('studioMqTaskCompleted', callback);
        view.once?.('remove', dispose);
        return {
            wait: () => new Promise(resolve => {
                wake = () => { clearTimeout(timer); wake = null; resolve(); };
                timer = setTimeout(wake, 2000);
            }),
            dispose,
        };
    };
});
