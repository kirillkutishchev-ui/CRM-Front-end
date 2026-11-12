define(['view', 'studio-management:analytics-periods', 'studio-management:mq-task-wakeup'], function (View, Periods, TaskWakeup) {
    return class extends View {
        template = 'studio-management:sm-analytics/index'

        setup() {
            this.activeTab = 'overview';
            this.requestNumber = 0;
            this.addHandler('click', '[data-analytics-tab]', (event, target) => {
                event.preventDefault();
                this.selectTab(target.dataset.analyticsTab);
            });
            this.addHandler('click', '[data-period]', (event, target) => {
                this.setPeriod(target.dataset.period);
                this.load();
            });
            this.addHandler('click', '[data-action="apply"]', () => this.load());
        }

        afterRender() {
            this.setPeriod('month');
            this.load();
        }

        setPeriod(period) {
            const range = Periods.range(period, new Date(), this.getConfig().get('timeZone') || 'UTC');
            this.$el.find('[name="from"]').val(range.from);
            this.$el.find('[name="to"]').val(range.to);
            this.$el.find('[data-period]').removeClass('active');
            this.$el.find(`[data-period="${period}"]`).addClass('active');
        }

        selectTab(tab) {
            this.activeTab = tab;
            this.$el.find('[data-analytics-tab]').attr('aria-selected', 'false').parent().removeClass('active');
            this.$el.find(`[data-analytics-tab="${tab}"]`).attr('aria-selected', 'true').parent().addClass('active');
            this.$el.find('[data-analytics-panel]').hide();
            this.$el.find(`[data-analytics-panel="${tab}"]`).show();
        }

        formatMoney(value, currency) {
            const number = Number(value || 0);

            try {
                return new Intl.NumberFormat(undefined, {style: 'currency', currency}).format(number);
            } catch (e) {
                return `${number.toFixed(2)} ${currency}`;
            }
        }

        async load() {
            const requestNumber = ++this.requestNumber;
            const from = this.$el.find('[name="from"]').val();
            const to = this.$el.find('[name="to"]').val();
            const $status = this.$el.find('.analytics-status').text(this.translate('Loading...'));

            try {
                let data = await Espo.Ajax.getRequest('StudioManagement/analytics', {from, to});
                if (data.async === true && data.taskId) {
                    data = await this.waitForTask(data.taskId, requestNumber);
                }
                if (requestNumber !== this.requestNumber || this.isRemoved()) return;
                this.personalOnly = data.personalOnly === true;
                this.renderPersonalTotals(data.personalTotals || []);
                this.renderTotals(data.totals || []);
                this.renderRows(data.rows || []);
                this.renderMetrics(data.personalMetrics || {});
                $status.text('');
            } catch (e) {
                if (requestNumber !== this.requestNumber || this.isRemoved()) return;
                $status.text(
                    e?.xhr?.getResponseHeader('X-Status-Reason') ||
                    this.translate('analyticsFailed', 'messages', 'SmAnalytics')
                );
            }
        }

        async waitForTask(taskId, requestNumber) {
            const wakeup = TaskWakeup(this, taskId);
            try {
            for (let attempt = 0; attempt < 120; attempt++) {
                if (requestNumber !== this.requestNumber || this.isRemoved()) return {};

                const task = await Espo.Ajax.getRequest(
                    `StudioManagement/mq-tasks/${encodeURIComponent(taskId)}`
                );

                if (task.status === 'Completed') return task.result || {};
                if (task.status === 'Failed') throw new Error(task.error || 'Analytics task failed.');

                await wakeup.wait();
            }

            throw new Error('Analytics task timed out.');
            } finally {
                wakeup.dispose();
            }
        }

        renderMetrics(metrics) {
            const tabs = metrics.availableTabs || [];
            this.$el.find('.analytics-heading').text(this.personalOnly
                ? this.translate('Personal Analytics', 'labels', 'SmAnalytics')
                : this.translate('SmAnalytics', 'scopeNames'));
            this.$el.find('[data-analytics-tab]').each((index, element) => {
                const tab = element.dataset.analyticsTab;
                $(element).parent().toggle((tab === 'overview' && !this.personalOnly) || tabs.includes(tab));
            });
            if (this.personalOnly && this.activeTab === 'overview') this.activeTab = tabs[0] || 'earnings';
            if (this.activeTab !== 'overview' && !tabs.includes(this.activeTab)) this.activeTab = 'overview';
            const week = metrics.week || {};
            this.$el.find('.analytics-week-range').text(
                `${week.from || ''} — ${week.to || ''} (${week.timeZone || 'UTC'})`
            );
            this.renderAmounts('.analytics-average', metrics.averageChecks || [], 'noShifts');
            this.renderAmounts('.analytics-weekly-earnings', metrics.weeklyEarnings || [], 'noEarnings');
            const minutes = Math.floor((metrics.workingSeconds || 0) / 60);
            this.$el.find('.analytics-hours').text(
                `${Math.floor(minutes / 60)} ${this.translate('Hours Unit', 'labels', 'SmAnalytics')} ` +
                `${minutes % 60} ${this.translate('Minutes Unit', 'labels', 'SmAnalytics')}`
            );
            this.$el.find('.analytics-hours-scope').text(this.translate(
                metrics.hoursScope === 'production' ? 'productionHours' : 'personalHours', 'messages', 'SmAnalytics'
            ));
            const last = metrics.lastShift;
            this.$el.find('.analytics-last-date').text(last?.endedAt
                ? this.getDateTime().toDisplay(last.endedAt)
                : this.translate('noShifts', 'messages', 'SmAnalytics'));
            this.$el.find('.analytics-last-check').text(last
                ? this.formatMoney(last.amount, last.currency)
                : this.translate('noShifts', 'messages', 'SmAnalytics'));
            this.selectTab(this.activeTab);
        }

        renderAmounts(selector, amounts, emptyMessage) {
            const $container = this.$el.find(selector).empty();
            if (!amounts.length) {
                $('<p>').text(this.translate(emptyMessage, 'messages', 'SmAnalytics')).appendTo($container);
            }
            amounts.forEach(amount => {
                $('<p>').text(this.formatMoney(amount.amount, amount.currency)).appendTo($container);
            });
        }

        renderTotals(totals) {
            const $container = this.$el.find('.analytics-totals').empty();

            if (!totals.length) {
                $('<p>').addClass('text-muted').text(this.translate('noData', 'messages', 'SmAnalytics')).appendTo($container);
                return;
            }

            totals.forEach(total => {
                const $panel = $('<div>').addClass('panel panel-default').css({display: 'inline-block', marginRight: '12px', minWidth: '260px'});
                const $body = $('<div>').addClass('panel-body').appendTo($panel);
                $('<strong>').text(total.currency).appendTo($body);
                $('<div>').text(`${this.translate('Gross', 'labels', 'SmAnalytics')}: ${this.formatMoney(total.totalGross, total.currency)}`).appendTo($body);
                $('<div>').text(`${this.translate('Studio', 'labels', 'SmAnalytics')}: ${this.formatMoney(total.studioShare, total.currency)}`).appendTo($body);
                $('<div>').text(`${this.translate('Producers', 'labels', 'SmAnalytics')}: ${this.formatMoney(total.producerShare, total.currency)}`).appendTo($body);
                $('<div>').text(`${this.translate('Participants', 'labels', 'SmAnalytics')}: ${this.formatMoney(total.participantShare, total.currency)}`).appendTo($body);
                $('<div>').text(`${this.translate('Technical Directors', 'labels', 'SmAnalytics')}: ${this.formatMoney(total.technicalDirectorShare, total.currency)}`).appendTo($body);
                $('<div>').text(`${this.translate('Recruiters', 'labels', 'SmAnalytics')}: ${this.formatMoney(total.recruiterShare, total.currency)}`).appendTo($body);
                $('<div>').text(`${this.translate('Expenses', 'labels', 'SmAnalytics')}: ${this.formatMoney(total.expensesTotal, total.currency)}`).appendTo($body);
                $('<div>').addClass('text-muted').text(`${this.translate('Shifts', 'labels', 'SmAnalytics')}: ${total.shiftCount}`).appendTo($body);
                $panel.appendTo($container);
            });
        }

        renderPersonalTotals(totals) {
            const $container = this.$el.find('.analytics-personal').empty();

            if (!totals.length) {
                $container.hide();
                return;
            }

            $container.show();

            $('<strong>')
                .text(`${this.translate('Personal Earnings', 'labels', 'SmAnalytics')}: `)
                .appendTo($container);

            totals.forEach((total, index) => {
                if (index) {
                    $container.append(document.createTextNode(' · '));
                }

                $('<span>').text(this.formatMoney(total.amount, total.currency)).appendTo($container);
            });
        }

        renderRows(rows) {
            const $body = this.$el.find('.analytics-rows').empty();

            rows.forEach(row => {
                const $tr = $('<tr>');
                $('<td>').text(row.producerName).appendTo($tr);
                $('<td>').text(row.teamName).appendTo($tr);
                $('<td>').text(row.shiftCount).appendTo($tr);
                $('<td>').text(this.formatMoney(row.totalGross, row.currency)).appendTo($tr);
                $('<td>').text(this.formatMoney(row.studioShare, row.currency)).appendTo($tr);
                $('<td>').text(this.formatMoney(row.producerShare, row.currency)).appendTo($tr);
                $('<td>').text(this.formatMoney(row.participantShare, row.currency)).appendTo($tr);
                $('<td>').text(this.formatMoney(row.technicalDirectorShare, row.currency)).appendTo($tr);
                $('<td>').text(this.formatMoney(row.recruiterShare, row.currency)).appendTo($tr);
                $('<td>').text(this.formatMoney(row.expensesTotal, row.currency)).appendTo($tr);
                $tr.appendTo($body);
            });
        }
    };
});
