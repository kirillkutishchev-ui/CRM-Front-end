define(['views/record/edit', 'studio-management:role-policy', 'studio-management:mq-task-wakeup'], function (EditRecordView, RolePolicy, TaskWakeup) {
    return class extends EditRecordView {
        saveAndContinueEditingAction = false
        saveAndNewAction = false

        setup() {
            super.setup();

            const params = this.options.params || {};
            this.model.studioWorkflowMode = params.start ? 'start' : params.count ? 'count' : params.review ? 'review' : params.revise ? 'revise' : '';
            const status = this.model.get('status');
            const saveButton = this.buttonList.find(item => item.name === 'save');
            this.model.set('confirmClosure', false);
            this.hideField('confirmClosure');
            if ((status === 'Review' && !RolePolicy.canReview(this)) ||
                (status === 'Closed' && !RolePolicy.canRevise(this))) {
                ['siteData', 'screenshots', 'description'].forEach(field => this.setFieldReadOnly(field, true));
                this.buttonList = this.buttonList.filter(item => item.name !== 'save');
            }

            this.listenTo(this.model, 'change:modelId', () => this.loadModelDefaults());
            this.listenTo(this.model, 'change:teamId', () => this.loadTeamDefaults());
            ['operator', 'model', 'producer', 'assignedUser', 'teams'].forEach(field => this.setFieldReadOnly(field, true));
            if (this.model.isNew() && this.model.get('teamId')) {
                this.once('after:render', () => this.loadTeamDefaults());
            }

            if (!this.model.isNew() && status === 'Draft' && !this.model.get('siteData')?.length) {
                this.once('after:render', () => this.loadModelDefaults());
            }

            if (status && status !== 'Draft') {
                [
                    'name',
                    'operator',
                    'model',
                    'producer',
                    'modelBirthDate',
                    'team',
                    'modelImages',
                    'startTime',
                    'currency',
                ].forEach(field => this.setFieldReadOnly(field, true));

                if (!(status === 'Counting' && params.count) && !(status === 'Review' && RolePolicy.canReview(this))) {
                    this.setFieldReadOnly('description', true);
                }
            }

            if (!saveButton || this.model.isNew()) {
                return;
            }

            if (status === 'Draft' && params.start) {
                saveButton.label = 'Start Shift';
                saveButton.style = 'success';
            } else if (status === 'Counting' && params.count) {
                saveButton.label = 'Complete Calculation';
                saveButton.style = 'success';
            } else if (status === 'Closed' && params.revise) {
                saveButton.label = 'Save Changes';
                saveButton.style = 'primary';
            } else if (status === 'Review' && RolePolicy.canReview(this)) {
                saveButton.label = 'Save Review';
                saveButton.style = 'success';
            }
        }

        async loadTeamDefaults() {
            const id = this.model.get('teamId');
            if (this.model.get('status') && this.model.get('status') !== 'Draft') {
                return;
            }
            // Immediately discard the previous team's users, including when the request fails.
            this.model.set({
                operatorId: null, operatorName: null, modelId: null, modelName: null,
                producerId: null, producerName: null, modelBirthDate: null, siteData: [],
            });
            if (!id) {
                return;
            }
            this.teamDefaultsLoading = true;
            try {
                const values = await Espo.Ajax.getRequest(`StudioManagement/teams/${id}/shiftDefaults`);
                if (this.model.get('teamId') === id) {
                    this.applyingTeamDefaults = true;
                    this.model.set(values);
                    this.applyingTeamDefaults = false;
                }
            } catch (error) {
                Espo.Ui.error(this.translate('teamDefaultsFailed', 'messages', 'SmShift'));
            } finally {
                this.teamDefaultsLoading = false;
            }
        }

        async loadModelDefaults() {
            if (this.applyingTeamDefaults) {
                return;
            }
            const id = this.model.get('modelId');
            if (!id || this.model.get('status') !== 'Draft') {
                return;
            }
            const query = this.model.id ? {shiftId: this.model.id} : {};
            try {
                const values = await Espo.Ajax.getRequest(`StudioManagement/models/${id}/shiftDefaults`, query);
                if (this.model.get('modelId') === id) {
                    this.model.set(values);
                }
            } catch (error) {
                Espo.Ui.error(this.translate('modelDefaultsFailed', 'messages', 'SmShift'));
            }
        }

        async actionSave(data) {
            if (this.teamDefaultsLoading) {
                return;
            }
            const params = this.options.params || {};
            const status = this.model.get('status');

            if (this.model.isNew() || (status === 'Draft' && !params.start)) {
                return super.actionSave(data);
            }

            this.model.setMultiple(this.fetch());
            if (this.getFieldView?.('screenshots')?.validate()) {
                this.enableActionItems();
                Espo.Ui.notify(false);
                return;
            }

            if (status === 'Draft' && params.start) {
                try {
                    try {
                        await this.save({...data?.options, skipNotModifiedWarning: true});
                    } catch (reason) {
                        // Starting an already-prepared draft does not require editing a field.
                        if (reason !== 'notModified') {
                            throw reason;
                        }
                    }
                    const response = await Espo.Ajax.postRequest(`SmShift/${this.model.id}/open`);
                    this.model.set(response);
                    Espo.Ui.success(this.translate('shiftOpened', 'messages', 'SmShift'));
                    this.leaveToDetail();
                } catch (error) {
                    // /open bypasses Espo's save error handler; restore the standard toolbar too.
                    this.enableActionItems();
                    Espo.Ui.notify(false);
                    throw error;
                }

                return;
            }

            try {
                if (status === 'Counting' && params.count) {
                    await this.confirm(this.translate('closeShiftConfirmation', 'messages', 'SmShift'));

                    const response = await Espo.Ajax.postRequest(`SmShift/${this.model.id}/close`, {
                        siteData: this.model.get('siteData') || [],
                        screenshotsIds: this.model.get('screenshotsIds') || [],
                        comment: this.model.get('description') || '',
                    });
                    this.model.set(response);
                    Espo.Ui.success(this.translate('shiftClosed', 'messages', 'SmShift'));
                    this.leaveToDetail();

                    return;
                }

                if (status === 'Review' && RolePolicy.canReview(this)) {
                    const checkbox = this.$el?.find('[name="confirmClosureReview"]');
                    const confirmClosure = checkbox?.length ? checkbox.prop('checked') === true : this.model.get('confirmClosure') === true;
                    if (confirmClosure) {
                        await this.confirm(this.translate('approveShiftConfirmation', 'messages', 'SmShift'));
                    }
                    let response = await Espo.Ajax.postRequest(`SmShift/${this.model.id}/review`, {
                        siteData: this.model.get('siteData') || [],
                        screenshotsIds: this.model.get('screenshotsIds') || [],
                        comment: this.model.get('description') || '',
                        confirmClosure,
                    });
                    if (response.async === true && response.taskId) {
                        response = await this.waitForMqTask(response.taskId);
                    }
                    this.model.set(response);
                    Espo.Ui.success(this.translate(confirmClosure ? 'shiftApproved' : 'shiftReviewSaved', 'messages', 'SmShift'));
                    this.leaveToDetail();
                    return;
                }

                if (status === 'Closed' && params.revise && this.isManager()) {
                    await this.confirm(this.translate('reviseShiftConfirmation', 'messages', 'SmShift'));

                    const response = await Espo.Ajax.postRequest(`SmShift/${this.model.id}/revise`, {
                        siteData: this.model.get('siteData') || [],
                        screenshotsIds: this.model.get('screenshotsIds') || [],
                    });
                    this.model.set(response);
                    Espo.Ui.success(this.translate('shiftRevised', 'messages', 'SmShift'));
                    this.leaveToDetail();

                    return;
                }

                return super.actionSave(data);
            } catch (error) {
                this.enableActionItems();
                Espo.Ui.notify(false);
                throw error;
            }
        }

        isManager() {
            return RolePolicy.canRevise(this);
        }

        async waitForMqTask(taskId) {
            const wakeup = TaskWakeup(this, taskId);
            try {
            for (let attempt = 0; attempt < 240; attempt++) {
                if (this.isRemoved?.()) throw new Error('Shift view was closed.');
                const task = await Espo.Ajax.getRequest(
                    `StudioManagement/mq-tasks/${encodeURIComponent(taskId)}`
                );
                if (task.status === 'Completed') return task.result.shift || task.result;
                if (task.status === 'Failed') throw new Error(task.error || 'Shift processing failed.');
                await wakeup.wait();
            }
            throw new Error('Shift processing is still running. Refresh the shift to see its status.');
            } finally {
                wakeup.dispose();
            }
        }

        leaveToDetail() {
            this.setIsNotChanged();
            this.getRouter().navigate(`#SmShift/view/${this.model.id}`, {trigger: true});
        }
    };
});
