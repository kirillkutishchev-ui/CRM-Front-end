define(['view'], function (View) {
    return class extends View {
        template = 'studio-management:registration'

        data() {
            const mode = this.options.invitation?.mode || '';

            return {
                notFound: this.options.notFound,
                isRegistration: mode === 'register',
                isPending: mode === 'pending',
                isLogin: mode === 'login',
                accountType: this.options.invitation?.accountType || '',
                roleName: this.options.invitation?.roleName || '',
                expiresAt: this.options.invitation?.expiresAt || '',
                userName: this.options.invitation?.userName || '',
                displayName: this.options.invitation?.displayName || '',
                justRegistered: Boolean(this.options.justRegistered),
            };
        }

        setup() {
            this.options.notFound = true;
            this.wait(this.loadInvitation());

            this.addHandler('click', '[data-action="register"]', () => this.submit());
            this.addHandler('click', '[data-action="login"]', () => this.login());
            this.addHandler('keydown', 'input', event => {
                if (event.key === 'Enter') {
                    if (this.options.invitation?.mode === 'login') {
                        this.login();
                    } else {
                        this.submit();
                    }
                }
            });
        }

        async loadInvitation() {
            const token = this.consumeTokenFromLocation();

            this.options.token = token;

            if (!this.isValidToken(token)) {
                return;
            }

            try {
                this.options.invitation = await Espo.Ajax.getRequest(
                    'StudioManagement/invitation',
                    null,
                    {headers: {'X-Studio-Access-Token': token}}
                );
                this.options.notFound = false;
            } catch (e) {
                this.options.invitation = null;
                this.options.notFound = true;
            }
        }

        consumeTokenFromLocation() {
            const rawFragment = window.location.hash.replace(/^#/, '');
            const fragment = new URLSearchParams(rawFragment);
            const query = new URLSearchParams(window.location.search);
            const locationToken = fragment.get('access') ||
                (this.isValidToken(rawFragment) ? rawFragment : '') ||
                query.get('token') || '';
            let token = locationToken;

            try {
                if (locationToken) {
                    window.sessionStorage.setItem('studioAccessToken', locationToken);
                } else {
                    token = window.sessionStorage.getItem('studioAccessToken') || '';
                }
            } catch (e) {
                // Session storage can be unavailable in hardened browsers.
            }

            query.delete('token');
            const search = query.toString();
            const cleanUrl = window.location.pathname + (search ? `?${search}` : '');

            window.history.replaceState(null, document.title, cleanUrl);

            return token.trim();
        }

        isValidToken(token) {
            return /^[A-Za-z0-9_-]{32}$/.test(token) || /^[a-f0-9]{64}$/.test(token);
        }

        meetsBasicPasswordRequirements(password) {
            return Array.from(password).length >= 10 && /[a-z]/.test(password) &&
                /[A-Z]/.test(password) && /[0-9]/.test(password);
        }

        async submit() {
            const fullName = this.$el.find('[name="fullName"]').val().trim();
            const email = this.$el.find('[name="email"]').val().trim();
            const password = this.$el.find('[name="password"]').val();
            const passwordConfirm = this.$el.find('[name="passwordConfirm"]').val();
            const $message = this.$el.find('.registration-message');

            $message.removeClass('text-success').addClass('text-danger').text('');

            if (!fullName || !email || !password) {
                $message.text(this.translate('allFieldsRequired', 'messages', 'SmRegistrationLink'));
                return;
            }

            if (password !== passwordConfirm) {
                $message.text(this.translate('passwordsDoNotMatch', 'messages', 'SmRegistrationLink'));
                return;
            }

            if (!this.meetsBasicPasswordRequirements(password)) {
                $message.text(this.translate('passwordRequirements', 'messages', 'SmRegistrationLink'));
                return;
            }

            const $button = this.$el.find('[data-action="register"]').prop('disabled', true);

            try {
                const response = await Espo.Ajax.postRequest('StudioManagement/register', {
                    token: this.options.token,
                    fullName,
                    email,
                    password,
                });

                this.options.invitation = {
                    mode: 'pending',
                    displayName: fullName,
                };
                this.options.justRegistered = true;

                await this.reRender();
            } catch (e) {
                $message.text(
                    e?.xhr?.getResponseHeader('X-Status-Reason') ||
                    this.translate('registrationFailed', 'messages', 'SmRegistrationLink')
                );
                $button.prop('disabled', false);
            }
        }

        async login() {
            const userName = String(this.options.invitation?.userName || '').trim();
            const password = this.$el.find('[name="loginPassword"]').val();
            const $message = this.$el.find('.login-message');
            const $button = this.$el.find('[data-action="login"]').prop('disabled', true);

            $message.removeClass('text-success').addClass('text-danger').text('');

            if (!userName || !password) {
                $message.text(this.translate('allFieldsRequired', 'messages', 'SmRegistrationLink'));
                $button.prop('disabled', false);
                return;
            }

            const authString = this.encodeBase64(`${userName}:${password}`);

            try {
                const data = await Espo.Ajax.getRequest('App/user', null, {
                    login: true,
                    headers: {
                        'Authorization': `Basic ${authString}`,
                        'Espo-Authorization': authString,
                        'Espo-Authorization-By-Token': 'false',
                        'Espo-Authorization-Create-Token-Secret': 'true',
                        'X-Studio-Access-Token': this.options.token,
                    },
                });

                try {
                    window.sessionStorage.removeItem('studioAccessToken');
                } catch (e) {
                    // Session storage can be unavailable in hardened browsers.
                }

                this.trigger('login', userName, data);
            } catch (e) {
                $message.text(this.translate('loginFailed', 'messages', 'SmRegistrationLink'));
                $button.prop('disabled', false);
            }
        }

        encodeBase64(value) {
            const bytes = new TextEncoder().encode(value);
            let binary = '';

            bytes.forEach(byte => binary += String.fromCharCode(byte));

            return window.btoa(binary);
        }
    };
});
