define(['views/site/navbar', 'studio-management:role-policy'], function (Navbar, RolePolicy) {
    return class extends Navbar {
        isRecruiter() {
            return !this.getUser().isAdmin() && this.getUser().get('smAccountType') === 'Recruiter';
        }

        getItemDefs(name) {
            return this.isRecruiter() ? null : super.getItemDefs(name);
        }

        setupMenu() {
            super.setupMenu();
            if (this.isRecruiter()) {
                this.menuDataList = this.menuDataList.filter(item => item.name === 'logout');
            }
        }

        getTabList() {
            const onboarding = {
                type: 'url',
                url: '#SmAccountOnboarding',
                text: this.translate('Accounts to complete', 'labels', 'SmAccountOnboarding'),
            };
            if (this.isRecruiter()) {
                return RolePolicy.canAccessOnboarding(this) ? [onboarding] : [];
            }
            const isModel = !this.getUser().isAdmin() && this.getUser().get('smAccountType') === 'Model';
            const filter = items => items.filter(item =>
                !(isModel && ['User', 'Team'].includes(item) && this.getAcl().getLevel(item, 'read') !== 'all')
            ).map(item => item?.itemList ? {...item, itemList: filter(item.itemList)} : item);
            const list = filter(super.getTabList());
            if (RolePolicy.canApproveAccounts(this)) {
                list.push(onboarding);
            }
            if (this.getAcl().check('SmAnalytics') ||
                !['Operator', 'Model'].includes(this.getUser().get('smAccountType'))) return list;
            const personal = {type: 'url', url: '#SmAnalytics',
                text: this.translate('Personal Analytics', 'labels', 'SmAnalytics')};
            const replace = items => items.map(item => {
                if (item === 'SmAnalytics') return personal;
                if (item?.itemList) return {...item, itemList: replace(item.itemList)};
                return item;
            });
            return replace(list);
        }
    };
});
