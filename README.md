<p align="center">
  <img src="img/app-store.svg" alt="Nextcloud App Template logo" width="80" height="80">
</p>

<h1 align="center">Nextcloud App Template</h1>

<p align="center">
  <strong>A template for creating new Nextcloud apps</strong>
</p>

<p align="center">
  <a href="https://github.com/ConductionNL/nextcloud-app-template/releases"><img src="https://img.shields.io/github/v/release/ConductionNL/nextcloud-app-template" alt="Latest release"></a>
  <a href="https://github.com/ConductionNL/nextcloud-app-template/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-EUPL--1.2-blue" alt="License"></a>
  <a href="https://github.com/ConductionNL/nextcloud-app-template/actions"><img src="https://img.shields.io/github/actions/workflow/status/ConductionNL/nextcloud-app-template/code-quality.yml?label=quality" alt="Code quality"></a>
</p>

---

A starting point for building Nextcloud apps following ConductionNL conventions.

> **Pre-wired for [OpenRegister](https://github.com/ConductionNL/openregister)** — all data is stored as OpenRegister objects. If your app needs OpenRegister, install it first. If not, remove the dependency from `appinfo/info.xml` and `openspec/app-config.json`.

## Screenshots

_Add screenshots here once the app has a UI._

## Features

Features are defined in [`openspec/specs/`](openspec/specs/). See the [roadmap](openspec/ROADMAP.md) for planned work.

### Core
- **Dashboard** — Personal overview page with key information at a glance
- **Admin Settings** — Configurable settings panel for administrators

### Supporting
- **OpenRegister Integration** — Pre-wired data layer using OpenRegister objects
- **Quality Pipeline** — PHPCS, PHPMD, Psalm, PHPStan, ESLint, Stylelint

## Architecture

```mermaid
graph TD
    A[Vue 2 Frontend] -->|REST API| B[OpenRegister API]
    B --> C[(PostgreSQL JSON store)]
    A --> D[Nextcloud Activity]
    A --> E[Nextcloud Search]
```

_Update this diagram during `/app-explore` sessions as the architecture evolves._

### Data Model

| Object | Description |
|--------|-------------|
| _(define your data objects here)_ | — |

_Data model is defined using OpenRegister schemas. See [`openspec/specs/`](openspec/specs/) for feature-level design decisions and [`openspec/architecture/`](openspec/architecture/) for architectural decisions._

### Directory Structure

```
app-template/
├── appinfo/                    # Nextcloud app manifest, routes, navigation
├── lib/                        # PHP backend
│   ├── AppInfo/Application.php
│   ├── Controller/             # DashboardController, SettingsController
│   ├── Service/SettingsService.php
│   ├── Listener/DeepLinkRegistrationListener.php
│   ├── Repair/InitializeSettings.php
│   └── Settings/               # AdminSettings, app_template_register.json
├── templates/                  # PHP templates (SPA shells)
├── src/                        # Vue 2 frontend
│   ├── main.js                 # App entry point
│   ├── App.vue                 # Root component
│   ├── navigation/MainMenu.vue # App navigation sidebar
│   ├── router/                 # Vue Router
│   ├── store/                  # Pinia stores
│   └── views/                  # Route-level views + UserSettings.vue
├── openspec/                   # Specifications, decisions, and roadmap
│   ├── app-config.json         # Canonical app config (id, goal, dependencies, CI)
│   ├── config.yaml             # OpenSpec CLI configuration
│   ├── specs/                  # Feature specs (input for OpenSpec changes)
│   ├── architecture/           # App-specific Architectural Decision Records
│   ├── ROADMAP.md              # Product roadmap
│   └── changes/                # OpenSpec change directories (created on first change)
├── tests/                      # Unit and integration tests
├── l10n/                       # Translations (en, nl)
├── .github/workflows/          # CI/CD pipelines
├── Makefile                    # Dev helpers (make dev-link)
└── img/                        # App icons and screenshots
```

## Requirements

| Dependency | Version |
|-----------|---------|
| Nextcloud | 28 – 33 |
| PHP | 8.1+ |
| Node.js | 20+ |
| [OpenRegister](https://github.com/ConductionNL/openregister) | latest |

## Installation

### From the Nextcloud App Store

1. Go to **Apps** in your Nextcloud instance
2. Search for **Nextcloud App Template**
3. Click **Download and enable**

> OpenRegister must be installed first. [Install OpenRegister →](https://apps.nextcloud.com/apps/openregister)

### From Source

```bash
cd /var/www/html/custom_apps
git clone https://github.com/ConductionNL/nextcloud-app-template.git app-template
cd app-template
npm install && npm run build
php occ app:enable app-template
```

## Development

### Start the environment

```bash
docker compose -f ../openregister/docker-compose.yml up -d
```

### Frontend development

```bash
npm install
npm run dev        # Watch mode
npm run build      # Production build
```

### Code quality

```bash
# PHP
composer check:strict   # All quality checks (PHPCS, PHPMD, Psalm, PHPStan, tests)
composer cs:fix         # Auto-fix PHPCS issues
composer phpmd          # Mess detection
composer phpmetrics     # HTML metrics report

# Frontend
npm run lint            # ESLint
npm run stylelint       # CSS linting
```

### Enable locally

Nextcloud requires the app directory name to match the `<id>` in `appinfo/info.xml` (`app-template`).
When this repo is cloned as `nextcloud-app-template`, create a relative symlink first.

> **Note:** The `js/` build output is not committed. You must build the frontend before enabling the app, or the UI will be blank.

```bash
make dev-link
npm install && npm run build
docker exec nextcloud php occ app:enable app-template
```

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Frontend | Vue 2.7, Pinia, @nextcloud/vue |
| Build | Webpack 5, @nextcloud/webpack-vue-config |
| Backend | PHP 8.1+, Nextcloud App Framework |
| Data | OpenRegister (PostgreSQL JSON objects) |
| UX | @conduction/nextcloud-vue |
| Quality | PHPCS, PHPMD, Psalm, PHPStan, ESLint, Stylelint |

## Branches

| Branch | Purpose |
|--------|---------|
| `main` | Stable releases — triggers release workflow |
| `beta` | Beta / pre-release builds |
| `development` | Active development — merge target for feature branches |

## Documentation

| Resource | Description |
|----------|-------------|
| [`openspec/app-config.json`](openspec/app-config.json) | App identity, goals, dependencies, and CI configuration |
| [`openspec/specs/`](openspec/specs/) | Feature specs — what the app should do |
| [`openspec/architecture/`](openspec/architecture/) | App-specific Architectural Decision Records |
| [`openspec/ROADMAP.md`](openspec/ROADMAP.md) | Product roadmap |
| [`openspec/`](openspec/) | Implementation specifications and changes |

## Standards & Compliance

- **Accessibility:** WCAG AA (Dutch government requirement)
- **Authorization:** RBAC via OpenRegister
- **Audit trail:** Full change history on all objects
- **Localization:** English and Dutch

## Related Apps

- **[OpenRegister](https://github.com/ConductionNL/openregister)** — Object storage layer (required dependency)

_Add related apps here as integrations are built._

## Troubleshooting

### App UI is blank after enabling

The `js/` build output is not committed to the repo. Run the frontend build before enabling the app:

```bash
npm install && npm run build
```

### "Could not download app app-template" when running `occ app:enable`

Nextcloud requires the app directory name to exactly match the `<id>` in `appinfo/info.xml`. When this repo is cloned as `nextcloud-app-template`, create a symlink first:

```bash
make dev-link   # creates apps-extra/app-template -> nextcloud-app-template
```

Then enable the app again:

```bash
docker exec nextcloud php occ app:enable app-template
```

## Support

For support, contact us at [support@conduction.nl](mailto:support@conduction.nl).

For a Service Level Agreement (SLA), contact [sales@conduction.nl](mailto:sales@conduction.nl).

## License

This project is licensed under the [EUPL-1.2](LICENSE).

### Dependency license policy

All dependencies (PHP and JavaScript) are automatically checked against an approved license allowlist during CI. The following SPDX license families are approved:

- **Permissive:** MIT, ISC, BSD-2-Clause, BSD-3-Clause, 0BSD, Apache-2.0, Unlicense, CC0-1.0, CC-BY-3.0, CC-BY-4.0, Zlib, BlueOak-1.0.0, Artistic-2.0, BSL-1.0
- **Copyleft (EUPL-compatible):** LGPL-2.0/2.1/3.0, GPL-2.0/3.0, AGPL-3.0, EUPL-1.1/1.2, MPL-2.0
- **Font licenses:** OFL-1.0, OFL-1.1

## Authors

Built by [Conduction](https://conduction.nl) — open-source software for Dutch government and public sector organizations.
