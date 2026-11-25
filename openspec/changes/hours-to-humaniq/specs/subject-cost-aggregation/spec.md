# Spec: subject-cost-aggregation (delta from hours-to-humaniq)

## MODIFIED Requirements

### Requirement: A subject cost is reachable over HTTP

The capability SHALL be exposed as `GET /api/subject-cost`, taking
`subjectApp` and `subjectId` and returning the aggregate for that domain
object. Both are required; a request missing either SHALL be refused with 400.

`subjectId` SHALL be matched against `TimeEntry.domainObjectRef`, and
`subjectApp` against the `<app>:` prefix of `domainObjectType`. Matching the
uuid alone would be an accidental cross-app match: almost always the same
answer, and occasionally, silently, not. A row carrying no `domainObjectType`
SHALL be kept, because the uuid matched and the writer may legitimately have
left the type unset.

Where humaniq is absent the endpoint SHALL answer 200 rather than an error,
with `hours` and `costCents` null, `complete` false and `missingApp`
`Humaniq`, so a surface names the app instead of showing zero hours
(REQ-H2H-002). shillinq does not declare humaniq as a dependency. Where
humaniq answers, `missingApp` SHALL be null and a subject without hours
SHALL report 0 hours.

This requirement exists because the capability was built without it. The
aggregator and the wage-rate adapter were implemented, spec-tagged and
unit-tested, and nothing could call either one. No route reached them, no
service composed them, and no code read an hour set for a subject. A
capability with no door is not delivered, however green its tests are.

The endpoint SHALL be available to any authenticated user. It SHALL refuse a
caller who belongs to no administration.

A Nextcloud admin SHALL read every administration, as it already does in
`CBSSubmissionController` and `BookingNotificationController`. Back-office
admins hold no `AdministrationMembership` of their own, so without the bypass
the endpoint refuses its own operator.

#### Scenario: The endpoint answers for a subject with booked hours
- **GIVEN** hours booked against a domain object, in an administration the
  caller belongs to
- **WHEN** the caller requests `GET /api/subject-cost` for that subject
- **THEN** the response is 200 and reports the subject's total hours

#### Scenario: A request without a subject is refused
- **GIVEN** an authenticated caller
- **WHEN** `subjectApp` or `subjectId` is missing
- **THEN** the response is 400 and no aggregate is returned

#### Scenario: Humaniq is absent
- **GIVEN** an install where humaniq's register does not answer
- **WHEN** the caller requests `GET /api/subject-cost` for a subject
- **THEN** the response is 200 with `hours` null, `costCents` null and
  `missingApp` `Humaniq`
- @e2e exclude a server answer for a disabled app; covered by PHPUnit
  `SubjectCostServiceTest::testAbsentHumaniqIsNamedNotZero`
