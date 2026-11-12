define(['controller', 'studio-management:role-policy'], function (Controller, RolePolicy) {
    return class extends Controller {
        checkAccess() {
            return RolePolicy.canAccessOnboarding(this);
        }

        actionIndex() {
            this.handleCheckAccess('');
            this.main('studio-management:views/sm-account-onboarding/index');
        }
    };
});
