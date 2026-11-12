define([], function () {
    const level = (view, permission, legacy = 'no') => {
        if (view.getUser().isAdmin()) {
            return 'all';
        }
        const value = view.getAcl().getPermissionLevel(permission);
        return value === 'not-set' ? legacy : value;
    };
    const canAccessOnboarding = view => {
        const user = view.getUser();
        if (user.isAdmin()) return true;
        if (user.get('type') !== 'regular') return false;
        const permission = level(view, 'smAccountOnboarding', 'not-set');
        if (permission !== 'not-set') return ['yes', 'all'].includes(permission);
        if (user.get('smAccountType') === 'Recruiter') return true;
        const acl = view.getAcl();
        return level(view, 'smStaffAccess') === 'all' &&
            ['yes', 'all'].includes(level(view, 'smInvitations')) &&
            acl.getLevel('User', 'read') === 'all' && acl.getLevel('User', 'edit') === 'all';
    };
    return {
        level,
        canAccessOnboarding,
        canOperate(view) {
            const legacy = ['Operator', 'ProducerAdmin'].includes(view.getUser().get('smAccountType')) ? 'own' : 'no';
            const value = level(view, 'smShiftOperate', legacy);
            return value === 'all' || (value === 'own' && view.model.get('operatorId') === view.getUser().id);
        },
        canPrepare(view) {
            const value = level(view, 'smShiftPrepare', 'own');
            return value === 'all' || (value === 'own' &&
                [view.model.get('operatorId'), view.model.get('producerId')].includes(view.getUser().id));
        },
        canReview(view) {
            const legacy = view.getUser().get('smAccountType') === 'ProducerAdmin' ? 'team' : 'no';
            const value = level(view, 'smShiftReview', legacy);
            return value === 'all' || (value === 'team' && view.model.get('producerId') === view.getUser().id);
        },
        canRevise(view) {
            const value = level(view, 'smShiftRevise');
            return value === 'all' || (value === 'team' && view.model.get('producerId') === view.getUser().id);
        },
        canViewStaff(view, scope) {
            const legacy = view.getUser().get('smAccountType') === 'ProducerAdmin' ? 'team' : 'no';
            return level(view, 'smStaffAccess', legacy) !== 'no' &&
                view.getAcl().checkScope(scope) && view.getAcl().checkScope('User', 'read');
        },
        canApproveAccounts(view) {
            if (view.getUser().isAdmin()) return true;
            return canAccessOnboarding(view) && view.getUser().get('smAccountType') !== 'Recruiter';
        },
    };
});
