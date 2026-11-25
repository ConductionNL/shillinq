# Spec: subject-cost-aggregation (delta from hours-to-humaniq)

## MODIFIED Requirements

### Requirement: A subject cost is reachable over HTTP

`GET /api/subject-cost` SHALL take `subjectApp` and `subjectId`, both
required (400 when either is missing), match them against humaniq
`TimeEntry.domainObjectRef` and the `<app>:` prefix of `domainObjectType`
(a row without a type is kept), and return that object's aggregate to any authenticated member of an
administration; a Nextcloud admin reads every administration. Where humaniq
does not answer it SHALL answer 200 with `hours` and `costCents` null and
`missingApp` `Humaniq`, never zero hours (REQ-H2H-002).

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
