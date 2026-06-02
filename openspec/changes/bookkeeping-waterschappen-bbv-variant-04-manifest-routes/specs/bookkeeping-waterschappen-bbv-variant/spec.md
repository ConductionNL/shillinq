# Spec Delta: bookkeeping-waterschappen-bbv-variant (member 04 — manifest + routes)

## ADDED Requirements

### Requirement: The system SHALL expose BBV navigation entries via the manifest

The system SHALL declare two navigation entries in `src/manifest.json`:
"BBV Compliance Dashboard" and "Budget Mapping" (with its index and
detail pages), ordered after the main dashboard.

#### Scenario: BBV navigation entries are present

- **GIVEN** the Shillinq app is loaded
- **WHEN** the navigation renders
- **THEN** a "BBV Compliance Dashboard" entry SHALL be shown
- **AND** a "Budget Mapping" entry SHALL be shown.

### Requirement: The system SHALL register BBV page routes with explicit auth

The system SHALL register `GET /bbv-dashboard`,
`GET /budget-mappings`, and `GET /budget-mappings/:id` in
`appinfo/routes.php`, each declaring an explicit Nextcloud auth
attribute. The dashboard route SHALL return the widget-data envelope
via a thin `DashboardController::index()`.

#### Scenario: Dashboard route is reachable

- **GIVEN** a logged-in finance officer
- **WHEN** the officer requests `GET /bbv-dashboard`
- **THEN** the route SHALL respond 200 OK with the widget-data envelope.

#### Scenario: Mapping routes are reachable

- **GIVEN** a logged-in admin
- **WHEN** the admin requests `GET /budget-mappings` or
  `GET /budget-mappings/:id`
- **THEN** the route SHALL respond 200 OK
- **AND** the route SHALL be declared only in `appinfo/routes.php`.
