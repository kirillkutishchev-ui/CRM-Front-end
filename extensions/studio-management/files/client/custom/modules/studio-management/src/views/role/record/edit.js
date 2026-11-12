define(['views/role/record/edit'], function (RoleEditView) {
    return class extends RoleEditView {
        layoutName = 'studioRole'
        tableView = 'studio-management:views/role/record/table'

        setupButtons() {
            this.addButton({
                name: 'removeAllRestrictions',
                label: 'Remove All Restrictions',
                style: 'danger',
            });

            super.setupButtons();
        }

        async actionRemoveAllRestrictions() {
            await this.confirm(
                this.translate('removeAllRestrictionsConfirmation', 'messages', 'Role')
            );

            this.getTableView().removeAllRestrictions();
            this.model.set({
                assignmentPermission: 'all',
                userPermission: 'all',
                messagePermission: 'all',
                portalPermission: 'yes',
                groupEmailAccountPermission: 'all',
                exportPermission: 'yes',
                massUpdatePermission: 'yes',
                dataPrivacyPermission: 'yes',
                followerManagementPermission: 'all',
                auditPermission: 'yes',
                mentionPermission: 'all',
                userCalendarPermission: 'all',
                lockPermission: 'yes',
                smShiftPreparePermission: 'all',
                smShiftOperatePermission: 'all',
                smShiftReviewPermission: 'all',
                smShiftRevisePermission: 'all',
                smStaffAccessPermission: 'all',
                smAnalyticsAccessPermission: 'all',
                smInvitationsPermission: 'yes',
                smAccountOnboardingPermission: 'yes',
            });

            Espo.Ui.success(
                this.translate('allRestrictionsRemoved', 'messages', 'Role')
            );
        }
    };
});
