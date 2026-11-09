<div class="container content">
    <div class="block-center">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h4 class="panel-title">
                    {{#if isPending}}
                        {{translate 'Pending Approval' scope='SmRegistrationLink'}}
                    {{else}}
                    {{#if isLogin}}
                        {{translate 'Log in'}}
                    {{else}}
                        {{translate 'Registration' scope='SmRegistrationLink'}}
                    {{/if}}
                    {{/if}}
                </h4>
            </div>
            <div class="panel-body">
                {{#if notFound}}
                    <p class="text-danger">{{translate 'linkUnavailable' category='messages' scope='SmRegistrationLink'}}</p>
                {{else}}
                    {{#if isPending}}
                    <p class="text-warning registration-pending-message" role="status">
                        {{translate 'registrationPending' category='messages' scope='SmRegistrationLink'}}
                    </p>
                    {{else}}
                    {{#if isRegistration}}
                    <p class="text-muted">
                        {{translate 'Account Type' scope='SmRegistrationLink'}}: <strong>{{accountType}}</strong><br>
                        {{translate 'Target Role' scope='SmRegistrationLink'}}: <strong>{{roleName}}</strong><br>
                        {{translate 'Expires At' scope='SmRegistrationLink'}}: <strong>{{expiresAt}}</strong>
                    </p>
                    <div class="registration-form">
                        <div class="form-group">
                            <label>{{translate 'Full Name' scope='SmRegistrationLink'}}</label>
                            <input class="form-control" type="text" name="fullName" maxlength="150" autocomplete="name">
                        </div>
                        <div class="form-group">
                            <label>{{translate 'Email'}}</label>
                            <input class="form-control" type="email" name="email" maxlength="254" autocomplete="email">
                        </div>
                        <div class="form-group">
                            <label for="registration-password">{{translate 'Password'}}</label>
                            <input id="registration-password" class="form-control" type="password" name="password" minlength="10" maxlength="255" autocomplete="new-password" aria-describedby="registration-password-requirements">
                            <p id="registration-password-requirements" class="help-block">{{translate 'passwordRequirements' category='messages' scope='SmRegistrationLink'}}</p>
                        </div>
                        <div class="form-group">
                            <label>{{translate 'Confirm Password' scope='SmRegistrationLink'}}</label>
                            <input class="form-control" type="password" name="passwordConfirm" maxlength="255" autocomplete="new-password">
                        </div>
                        <button type="button" class="btn btn-primary" data-action="register">
                            {{translate 'Register' scope='SmRegistrationLink'}}
                        </button>
                    </div>
                    <p class="registration-message" role="alert" style="margin-top: 15px"></p>
                    {{else}}
                        {{#if isLogin}}
                    <p class="text-muted">
                        {{translate 'Personal Login Description' scope='SmRegistrationLink'}}
                    </p>
                    <div class="login-form">
                        <div class="form-group">
                            <label>{{translate 'Username'}}</label>
                            <input class="form-control" type="text" name="loginUserName" value="{{userName}}" readonly autocomplete="username">
                        </div>
                        <div class="form-group">
                            <label>{{translate 'Password'}}</label>
                            <input class="form-control" type="password" name="loginPassword" maxlength="255" autocomplete="current-password">
                        </div>
                        <button type="button" class="btn btn-primary" data-action="login">
                            {{translate 'Log in'}}
                        </button>
                    </div>
                    {{#if justRegistered}}
                        <p class="text-success" style="margin-top: 15px">
                            {{translate 'registrationComplete' category='messages' scope='SmRegistrationLink'}}
                        </p>
                    {{/if}}
                    <p class="login-message" style="margin-top: 15px"></p>
                        {{/if}}
                    {{/if}}
                    {{/if}}
                {{/if}}
            </div>
        </div>
    </div>
</div>
