# Spec: bookings-create-appointment

**Status:** proposed
**Scope:** nextcloud-bookings
**Tier:** T1 (core feature)
**Depends on:** bookings-resource-calendar, bookings-service-catalog

## ADDED Requirements

### Requirement: REQ-BCA-001: The system SHALL store appointments as an OpenRegister-managed `Appointment` register

The appointment data MUST be declared as a register in `lib/Settings/bookings_register.json` per ADR-024, with the `Appointment` schema as the canonical entity. No custom PHP model, no custom database table. The register is exposed through OpenRegister's generic CRUD HTTP surface at `GET/POST /ocs/v2.php/apps/openregister/api/objects/bookings/Appointment`.

#### Scenario: Admin retrieves existing appointments via the OpenRegister API

- **GIVEN** the bookings app is installed and appointment records exist
- **WHEN** an authenticated admin calls `GET /index.php/ocs/v2.php/apps/openregister/api/objects/bookings/Appointment?limit=20`
- **THEN** the response MUST list existing appointment records, paginated per OR's standard contract, with no bookings-side controller in the call path

#### Scenario: Third-party system confirms Appointment schema is in the OR catalog

- **GIVEN** the bookings app
- **WHEN** the OR discovery endpoint is queried for registered schemas
- **THEN** the `Appointment` schema from the bookings register MUST be enumerated

### Requirement: REQ-BCA-002: The `Appointment` schema SHALL declare a minimum field set with typed relations

The `Appointment` schema MUST declare the following fields. Additional fields MAY be added in future tiers (additive only).

| Field | Type | Required | Description |
|---|---|---|---|
| `appointmentId` | string (UUID) | Yes | Unique appointment identifier |
| `startTime` | datetime (ISO 8601 UTC) | Yes | Appointment start time (e.g., "2026-05-21T14:30:00Z") |
| `endTime` | datetime (ISO 8601 UTC) | Yes | Appointment end time (e.g., "2026-05-21T15:00:00Z") |
| `serviceId` | string | Yes | FK to the `Service` register (from `bookings-service-catalog`) |
| `resourceId` | string | Yes | FK to the `Resource` register (from `bookings-resource-calendar`) |
| `customerId` | string | Yes | FK to the `Customer` register |
| `status` | enum | Yes | One of: `pending_confirmation`, `confirmed`, `completed`, `cancelled` |
| `notes` | string | No | Customer-provided notes or special requests |
| `createdAt` | datetime | Yes (auto) | Appointment creation timestamp |
| `createdBy` | string | Yes (auto) | User ID who created the appointment |
| `updatedAt` | datetime | Yes (auto) | Last update timestamp |
| `cancelledAt` | datetime | No | Timestamp when appointment was cancelled |
| `cancelledReason` | string | No | Reason for cancellation |

OpenRegister's built-in fields (`id`, `uuid`, `version`, `owner`, `auditTrail`, `relations`) are not redeclared per `adr-000-data-model.md`'s convention.

#### Scenario: A minimal appointment object is created successfully

- **GIVEN** a valid Service (serviceId: "svc-001", duration: 30 min) and Resource (resourceId: "res-001")
- **WHEN** a POST request creates `{appointmentId: "apt-001", startTime: "2026-05-22T10:00:00Z", endTime: "2026-05-22T10:30:00Z", serviceId: "svc-001", resourceId: "res-001", customerId: "cust-001", status: "pending_confirmation"}`
- **THEN** the appointment MUST be persisted with all required fields

#### Scenario: Validator rejects an invalid status enum

- **GIVEN** the Appointment schema
- **WHEN** an object with `status: "booked"` (invalid enum) is validated
- **THEN** validation MUST fail with an enum-violation error

### Requirement: REQ-BCA-003: Appointment duration MUST match the related Service duration

At create time, the appointment duration (endTime - startTime, in minutes) MUST exactly match the duration defined in the related Service record (with a ±5-minute tolerance for scheduling flexibility).

#### Scenario: Create appointment with duration matching the service

- **GIVEN** Service "svc-001" with `duration: 30` minutes
- **AND** Resource "res-001" with availability from 09:00 to 17:00
- **WHEN** creating appointment with startTime: "2026-05-22T10:00:00Z", endTime: "2026-05-22T10:30:00Z", serviceId: "svc-001"
- **THEN** the appointment MUST be created successfully

#### Scenario: Reject appointment with duration mismatch

- **GIVEN** Service "svc-001" with `duration: 30` minutes
- **WHEN** attempting to create appointment with startTime: "2026-05-22T10:00:00Z", endTime: "2026-05-22T11:00:00Z" (60 min)
- **THEN** the creation MUST fail with error "Duration mismatch: requested 60 min, service requires 30 min"

### Requirement: REQ-BCA-004: Appointment creation MUST validate resource availability and prevent double-booking

Before persisting an appointment, the system MUST verify that:
1. The resource is available (no overlapping confirmed appointments)
2. The resource's calendar rules allow bookings at this time
3. The start and end times are within the resource's operational hours

#### Scenario: Create appointment in an available slot

- **GIVEN** Resource "res-001" with availability 09:00–17:00 (UTC)
- **AND** no existing appointments in the 10:00–10:30 slot on 2026-05-22
- **WHEN** creating appointment with startTime: "2026-05-22T10:00:00Z", endTime: "2026-05-22T10:30:00Z"
- **THEN** the appointment MUST be created successfully

#### Scenario: Reject appointment conflicting with existing confirmed appointment

- **GIVEN** existing confirmed appointment: startTime: "2026-05-22T10:00:00Z", endTime: "2026-05-22T10:30:00Z"
- **WHEN** attempting to create new appointment at the same time
- **THEN** the creation MUST fail with error "Resource not available: slot 10:00–10:30 is already booked"

#### Scenario: Reject appointment outside resource operational hours

- **GIVEN** Resource "res-001" with availability 09:00–17:00
- **WHEN** attempting to create appointment at startTime: "2026-05-22T07:00:00Z", endTime: "2026-05-22T07:30:00Z" (before 09:00)
- **THEN** the creation MUST fail with error "Outside operational hours"

### Requirement: REQ-BCA-005: Appointment creation SHALL support two pathways with different initial statuses

Appointments can be created in two states, depending on the caller and business rules:

1. **Admin pathway** — create with status `confirmed` (admin schedules directly; no confirmation needed)
2. **Customer self-service pathway** — create with status `pending_confirmation` (customer books; awaits admin approval or auto-confirms per business rule)

The choice is determined by the create endpoint caller's role.

#### Scenario: Admin creates confirmed appointment

- **GIVEN** an authenticated admin user
- **WHEN** calling `POST /ocs/v2.php/apps/bookings/api/v1/appointments` with role=admin
- **THEN** the appointment MUST be created with `status: "confirmed"` (no approval step)

#### Scenario: Customer creates pending appointment via self-service portal

- **GIVEN** an authenticated customer
- **WHEN** calling `POST /ocs/v2.php/apps/bookings/api/v1/appointments` from the portal with role=customer
- **THEN** the appointment MUST be created with `status: "pending_confirmation"` and awaits admin review

### Requirement: REQ-BCA-006: Appointment creation MUST validate customer eligibility and access rules

Before creating an appointment, the system MUST verify that the customer is eligible to book the service:
1. Customer's account is active (not suspended/banned)
2. Customer has not exceeded their booking quota (if applicable)
3. Service is not restricted to specific customer tiers (if applicable)

#### Scenario: Customer with active account books successfully

- **GIVEN** Customer "cust-001" with active account status
- **WHEN** creating appointment for an unrestricted service
- **THEN** appointment creation MUST succeed

#### Scenario: Suspended customer cannot book

- **GIVEN** Customer "cust-001" with status="suspended"
- **WHEN** attempting to create appointment
- **THEN** the creation MUST fail with error "Customer account is suspended"

### Requirement: REQ-BCA-007: Appointment creation MUST persist audit trail information

Every appointment creation MUST record the following in the audit trail:
1. Timestamp of creation
2. User ID of the creator
3. IP address or API client identifier (if available)
4. Complete appointment object (before and after, though "before" is null for creates)

OpenRegister's audit-trail-immutable abstraction MUST handle this automatically; no app-local audit logging.

#### Scenario: Audit log entry is created on appointment creation

- **GIVEN** an authenticated admin creating an appointment
- **WHEN** the appointment is saved
- **THEN** OR's audit trail MUST record a "created" event with `actor`, `timestamp`, `appointmentObject`, and `hash` chain

### Requirement: REQ-BCA-008: Appointment creation via REST API MUST validate request payload

The `POST /ocs/v2.php/apps/bookings/api/v1/appointments` endpoint MUST:
1. Accept a JSON payload with required fields (startTime, endTime, serviceId, resourceId, customerId)
2. Return 400 Bad Request for missing or invalid fields
3. Return 409 Conflict if the slot is unavailable
4. Return 201 Created with the full appointment object on success

#### Scenario: Valid REST request creates appointment

- **GIVEN** a valid JSON payload with all required fields
- **WHEN** calling `POST /ocs/v2.php/apps/bookings/api/v1/appointments` with Content-Type: application/json
- **THEN** the response MUST be 201 Created with the appointment object

#### Scenario: Invalid payload returns 400

- **GIVEN** a JSON payload missing `endTime`
- **WHEN** calling the POST endpoint
- **THEN** the response MUST be 400 Bad Request with an error message naming the missing field

### Requirement: REQ-BCA-009: Admin appointment creation UI MUST provide confirmation feedback

The admin appointment create modal MUST:
1. Display confirmation of the booked appointment (date, time, customer, service)
2. Show a "Booking Confirmed" message with the appointment ID
3. Provide an option to book another appointment immediately (reset form)
4. Provide an option to close the modal and return to calendar view

#### Scenario: Admin books and sees confirmation

- **GIVEN** admin has filled in appointment details in the create form
- **WHEN** clicking "Confirm Booking"
- **THEN** the modal MUST display "Appointment Confirmed" with appointment ID and details, plus options to book another or close

### Requirement: REQ-BCA-010: Customer self-service portal MUST enforce access control

The self-service portal at `/ocs/v2.php/apps/bookings/portal/book` MUST:
1. Only be accessible to authenticated customers
2. Show only services that the customer is eligible to book
3. Show only available time slots for the selected service
4. Redirect unauthenticated users to login

#### Scenario: Authenticated customer accesses portal

- **GIVEN** a customer is logged in
- **WHEN** navigating to the portal
- **THEN** the portal MUST load and display available services

#### Scenario: Unauthenticated user is redirected

- **GIVEN** no active session
- **WHEN** attempting to access the portal
- **THEN** the system MUST redirect to the login page

## MODIFIED Requirements

_None._

## DEPRECATED Requirements

_None._
