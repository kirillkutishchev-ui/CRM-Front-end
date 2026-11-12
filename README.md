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

1. Copy `.env.example` to `.env`, replace every example password, and set
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
administrator hostname (for example `wesperr.studio`), and enforce HTTPS. An
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

#### RabbitMQ task processing

The runtime stack includes RabbitMQ and separate publisher/worker containers.
The CRM writes a task and the business change to MariaDB first; the publisher
then sends the durable event to the `crm.events` topic exchange. This
transactional-outbox design prevents a temporary broker outage from losing a
shift-material or analytics task.

The durable queues are `analytics_tasks`, `file_processing`, and
`websocket_notifications`. Each has a dead-letter queue bound to `crm.dlx`.
Messages use persistent delivery, publisher confirms, and explicit ACKs.
Delivery attempts commit separately before handler execution, so SIGKILL/OOM
cannot reset the retry limit. Concurrent duplicate claims are fenced by the
attempt number; financial writes and task completion still commit atomically.
Workers have a hard `MQ_TASK_TIMEOUT_SECONDS` deadline (default 120 seconds)
and a heartbeat healthcheck. Increase the deadline for legitimately longer jobs.
Docker restarts a timed-out process; there is no Supervisor.
Publication holds the outbox row lock until confirmation; workers hold that
lock while running the handler and atomically committing the result plus a
completion-notification outbox entry. Completed duplicates do not rerun the
handler. A failed attempt returns to `Queued` with a future `availableAt` in
MariaDB; the publisher sends it again after `MQ_RETRY_DELAY_MS`.

Studio analytics and final shift approval run in workers. The web API returns
HTTP 202 with a task ID, and the UI waits for the task result. Financial
snapshots, expense rows, payout flags and the Closed status still commit in
one transaction. User creation, role assignment and authentication remain
synchronous. Workers recheck the initiating user's current native and Studio
permissions rather than trusting role flags in the broker message.

Screenshot processing checks the image bytes, dimensions, MIME type, file size
and access to the shift/attachment. PNG files can receive lossless compressed
copies when smaller; originals remain intact. JPEG, WebP and GIF files are
validated without re-encoding. This stage does not reduce the initial upload
traffic or replace the source screenshot collection.
The shift stores a read-only `screenshotOptimizations` mapping, and preview
thumbnails use the smaller PNG. Full-size originals stay available. Keeping
originals plus optimized copies increases total storage; this optimization
reduces preview transfer, not initial upload or total disk usage. A killed
transaction can leave a deterministic derived file that is reused on retry.

AMQP port 5672 is available only inside the Compose network. The management UI
is bound to `127.0.0.1:15672` by default; do not bind it publicly. For remote
administration, put an authenticated HTTPS reverse proxy with an IP allow-list
in front of that loopback address, or use the optional HTTPS container:

```bash
# Set certificate/key paths, MQ_MANAGEMENT_ALLOWED_IPS (comma-separated CIDRs),
# MQ_HTTPS_BIND_ADDRESS and MQ_HTTPS_PORT in the private .env first.
docker compose -f compose.yaml -f compose.mq-management.yaml up -d mq-management-https
```

`compose.mq-management.yaml` enforces TLS 1.2/1.3 and a deny-by-default client
IP allowlist before forwarding to RabbitMQ's own authenticated UI. It uses the
actual TCP client address and ignores forwarded-IP headers. Publish it directly
on a dedicated port if external access is needed; a NAT/load balancer in front
would hide the client IP and needs a separate reviewed proxy configuration.
Use a certificate valid for the chosen management hostname in production.
The example admin IP is `158.160.144.108/32`; verify your current egress IP.
Keep the raw management port bound to loopback. Local test certificates under
`data/tmp` are ignored and must never be deployed.

The `studioMqTaskCompleted` WebSocket topic carries only task ID/status, targets
the initiating active user and wakes the view's authorized HTTP result lookup.
Transport errors are retried rather than silently marked Completed. Offline
browsers recover by polling; a successful transport submission does not imply
that every browser was connected or acknowledged receipt. A shift awaiting
queued approval rejects later review saves with HTTP 409 until processing ends.

Operational checks:

```bash
docker compose ps
docker compose exec -T crm_rabbitmq rabbitmq-diagnostics -q ping
docker compose exec -T crm_rabbitmq rabbitmqctl list_queues name messages_ready messages_unacknowledged
docker compose logs --tail=100 mq-publisher mq-worker-analytics mq-worker-files mq-worker-websocket
```

Automatic retries preserve the original payload. After
the configured attempts, the message moves to the queue's `.dlq` and the
MariaDB task becomes `Failed`. To correct its payload, copy a JSON object into
the CRM container and create a new task linked to the failed one:

```bash
docker compose cp corrected-payload.json crm:/tmp/corrected-payload.json
docker compose exec -T crm bin/command studio-mq-retry \
  --task-id=FAILED_TASK_ID \
  --payload-file=/tmp/corrected-payload.json
```

Use `--as-user-id=USER_ID` only when the original initiator no longer exists.
Completed task records are retained for `MQ_TASK_RETENTION_DAYS` and then
purged by the publisher. Failed tasks are retained for manual diagnosis.

For the isolated local RabbitMQ installation, use the private `.env.mq-local`
file together with the explicit Compose project name below on every command.
It selects image `studio-crm:mq-local`, CRM HTTP/WebSocket ports 8098/8099 and
the management UI on loopback port 15673. Its separate named volumes preserve
other local CRM installations. `.env.mq-local` contains local credentials and
is ignored by Git.

```bash
docker compose --env-file .env.mq-local -p crm-rabbitmq-local build crm
docker compose --env-file .env.mq-local -p crm-rabbitmq-local up -d
docker compose --env-file .env.mq-local -p crm-rabbitmq-local ps
```

Open `http://localhost:8098/` and use the local administrator credentials from
that file. RabbitMQ Management is at `http://127.0.0.1:15673/`; its credentials
are `MQ_USER` and `MQ_PASSWORD` from the same file. An IP-filtered production
HTTPS route for management can use the optional Compose file above.

Run the broker integration test only against this isolated installation with
its background publisher and workers stopped; the test checks that queues
are empty before creating its own fixtures:

```bash
docker compose --env-file .env.mq-local -p crm-rabbitmq-local stop mq-publisher mq-worker-analytics mq-worker-files mq-worker-websocket
docker compose --env-file .env.mq-local -p crm-rabbitmq-local cp extensions/studio-management/tests/RabbitMqIntegrationTest.php crm:/tmp/RabbitMqIntegrationTest.php
docker compose --env-file .env.mq-local -p crm-rabbitmq-local exec -T -e STUDIO_MQ_TEST_ISOLATED=1 crm php /tmp/RabbitMqIntegrationTest.php
docker compose --env-file .env.mq-local -p crm-rabbitmq-local up -d mq-publisher mq-worker-analytics mq-worker-files mq-worker-websocket
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
