## EspoCRM

> This repository contains the complete EspoCRM application with the custom
> **Studio Management** module integrated into the backend and frontend.
> Module features include shifts, teams, RBAC fields, one-time registration
> links, immutable financial snapshots, recruiter/technical-director expenses,
> and role-scoped studio analytics.
> See [the module documentation](extensions/studio-management/README.md).

[![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen)](#espocrm)

[EspoCRM](https://www.espocrm.com) is a free, open-source CRM platform designed to help organizations build and maintain strong customer relationships.
It provides a wide range of tools to store, organize, and manage leads, contacts, sales opportunities, marketing campaigns,
support cases, and more – all business information in a simple and intuitive interface.

![Screenshot](https://github.com/user-attachments/assets/d0806394-3691-43a1-83a5-16ad2e7314e2)


### Architecture

EspoCRM is a web application with a frontend designed as a single-page application and a REST API
backend written in PHP.

### Demo

You can try the CRM on an online [demo](https://www.espocrm.com/demo/).

### Requirements

* PHP 8.3 - 8.5;
* MySQL 8.0 (and later), or MariaDB 10.6 (and later);
* PostgreSQL 15 (and later).

For more information about server configuration, see [this article](https://docs.espocrm.com/administration/server-configuration/).

### Download

[Download](https://www.espocrm.com/download/) the latest release from our website or from GitHub [releases](https://github.com/espocrm/espocrm/releases).

### Release notes

Release notes are available at GitHub [releases](https://github.com/espocrm/espocrm/releases).

### Documentation

See the [documentation](https://docs.espocrm.com) for administrators, users and developers.

### Why EspoCRM?

* Open-source transparency. EspoCRM's source code is open and accessible, so anyone can inspect it and see how data is being managed within the CRM.
* Customization freedom. You can develop features, create custom entities, fields, relationships, buttons to make the system fit your specific needs. EspoCRM is more than a CRM – it's a platform for building custom business applications.
* Clean user interface. EspoCRM offers an uncluttered, minimalist, and fast user interface, which is easy to navigate and has a short learning curve.
* Straightforward REST API. It can be easily integrated with other applications using a REST API.

### Who is EspoCRM for?

* From startups, small & medium-sized businesses to larger organizations. A flexible, fully customizable solution that scales with your needs.
* Developers & tech enthusiasts. You can extend functionalities, build extensions, and create custom integrations.
* Anyone seeking a free or on-premise CRM.

### Installing stable version

See installation instructions:

* [Manual installation](https://docs.espocrm.com/administration/installation/)
* [Installation by script](https://docs.espocrm.com/administration/installation-by-script/)
* [Installation with Docker](https://docs.espocrm.com/administration/docker/installation/)
* [Installation with Traefik](https://docs.espocrm.com/administration/docker/traefik/)

### Bug reporting

Create a [GitHub issue](https://github.com/espocrm/espocrm/issues/new/choose) or post on our [forum](https://forum.espocrm.com/forum/bug-reports).

### Development

See the [developer documentation](https://docs.espocrm.com/development/).

#### Run the complete CRM with Docker

The runtime stack contains MariaDB, the EspoCRM web application, the background
daemon and the WebSocket service. The Studio Management backend and frontend
are baked into the local image and refreshed in the persistent custom volumes
each time the web container starts.

1. Copy `.env.example` to `.env`, replace all three example passwords, and set
   `CRM_ADMIN_HOSTS` plus `CRM_ADMIN_ALLOWED_IPS`.
2. Start the stack:

```bash
docker compose up -d --build
```

3. Wait until the application is healthy, then open a host allowed by
   `CRM_ADMIN_HOSTS` from an address allowed by `CRM_ADMIN_ALLOWED_IPS` and
   sign in with `CRM_ADMIN_USERNAME` and `CRM_ADMIN_PASSWORD` from `.env`.

Regular users cannot use the standard login page. A Registration Link opens
registration on first use and becomes the same user's account-bound login page
after registration. The secret is carried in a URL fragment (`#access=...`),
which is not sent in an HTTP request or Referer header, and is removed from the
address bar immediately. The user must still enter their password. One link
cannot be used to authenticate another account.

For production, copy `.env.production.example` to `.env`, replace every
`GENERATE_...` / `CHANGE_ME_...` value, create an A/AAAA record for the
administrator hostname (for example `UcfgcINdRftUIqOv.wesperr.studio`), and enforce HTTPS. An
MX record is only for mail and does not control web access. Keep real passwords
and IP addresses in the untracked `.env` file or in the deployment platform's
secret storage.

The Compose file binds CRM ports to `127.0.0.1` by default. Put a trusted HTTPS
reverse proxy on the same server in front of them. The proxy must overwrite
`X-Real-IP`, pass the original `Host`, support WebSocket upgrades, and rate-limit
at least `/api/v1/StudioManagement/invitation`,
`/api/v1/StudioManagement/register`, and `/api/v1/App/user`. Do not expose ports
8080/8081 directly when `CRM_IP_ADDRESS_SERVER_PARAM=HTTP_X_REAL_IP`, because a
direct client could otherwise spoof that header.

Before the first production start, back up the database and persistent volumes.
After deployment, verify HTTPS, the administrator host/IP restriction, a new
registration link, logout and repeat login through that link. Existing links
are automatically migrated from `?token=...` to `#access=...` when the CRM
container starts.

Useful commands:

```bash
docker compose ps
docker compose logs -f crm
docker compose down
```

`docker compose down` preserves the database and uploaded files. Removing the
named volumes also removes all CRM data, so do that only when a clean install
is intended.

#### Reproducible Linux build

The full EspoCRM build requires Node.js, PHP, Composer, PHP extensions, and
Unix utilities. A dedicated build-only Docker environment is included so the
same toolchain can be used on Windows, macOS, and Linux. It does not define or
change any runtime CRM containers.

From PowerShell run:

```powershell
.\scripts\build-linux.ps1
```

From a Unix shell run:

```bash
bash ./scripts/build-linux.sh
```

The first run downloads the toolchain and dependency caches. The resulting
EspoCRM distribution is written to the repository's `build` directory. To
clear only the build dependency caches, run
`docker compose -f compose.build.yaml down --volumes`.

We highly recommend using an IDE for development. The backend codebase adheres to SOLID principles, utilizes interfaces, static typing and generics. We recommend to start learning EspoCRM from the Dependency Injection article in the documentation.

Metadata plays an integral role in the EspoCRM application. All possible parameters are described with a JSON Schema, meaning you will have autocompletion in the IDE. You can also find the full metadata reference in the documentation.

The frontend is an SPA built on a custom framework. It utilizes nested views and service DI, with the core partially written in TypeScript. Developers primarily work with existing form and field view implementations.

### Community & Support

If you have a question regarding some features, need help or customizations, want to get in touch with other EspoCRM users, or add a feature request, please use our [community forum](https://forum.espocrm.com/). We believe that using the forum to ask for help and share experience allows everyone in the community to contribute and use this knowledge later.

### License

EspoCRM is an open-source project licensed under [GNU AGPLv3](https://raw.githubusercontent.com/espocrm/espocrm/master/LICENSE.txt).

### Contributing

Before we can merge your pull request, you need to accept our CLA [here](https://github.com/espocrm/cla). See the [contributing guidelines](https://github.com/espocrm/espocrm/blob/master/.github/CONTRIBUTING.md).

Branches:

* *fix* – upcoming maintenance release; minor fixes should be pushed to this branch;
* *master* – develop branch; new features should be pushed to this branch;
* *stable* – last stable release.

### Language

If you want to improve existing translation or add a language that is not available yet, you can contribute on our [POEditor](https://poeditor.com/join/project/gLDKZtUF4i) project. See instructions [here](https://www.espocrm.com/blog/how-to-use-poeditor-to-translate-espocrm/). It may be reasonable to let us know about your intention to join the POEditor project by posting on our forum or via the contact form on our website.

Changes on POEditor are usually merged to the GitHub repository before minor releases.
