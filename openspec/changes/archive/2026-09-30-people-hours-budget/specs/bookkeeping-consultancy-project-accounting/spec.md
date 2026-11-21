# bookkeeping-consultancy-project-accounting Specification (delta)

## Purpose

Hours logged on a project are compared with the hours budgeted, and the
project owner is warned before and when they run over. From shillinq matrix
row `ppl-hours-budget`.

## ADDED Requirements

### Requirement: An assignment knows its logged hours (REQ-PHB-001)

`ProjectAssignment` SHALL carry `loggedHours`, kept equal to the sum of the
hours booked on it within seconds of each booking, change or removal, and a
calculated `hoursUsedPercent` against `estimatedHours`.

#### Scenario: A booking updates the assignment

- GIVEN a.bakker's assignment on "Herinrichting Wmo-loket" with 90 of 120 hours logged
- WHEN 6 hours are booked on it
- THEN the project detail page shows 96 hours logged and 80 percent used

### Requirement: The project owner is warned at 80 and 100 percent (REQ-PHB-002)

The project owner SHALL receive a notification when an assignment's used
percentage reaches 80 and when it reaches 100, once per threshold until the
estimate changes.

@e2e exclude backend/notification: the warning is a declared notification on a flag the listener writes, asserted by AssignmentHoursListenerTest, not the browser

#### Scenario: The first warning

- GIVEN the assignment at 75 percent
- WHEN a booking takes it to 80 percent
- THEN j.devries receives a notification naming the project, the consultant and 96 of 120 hours
- AND a further booking at 85 percent sends no second 80 percent warning

### Requirement: Projects over budget can be found (REQ-PHB-003)

The projects list SHALL offer a filter for projects with an assignment over
100 percent, and the project page SHALL show estimated, logged and remaining
hours per assignment and in total.

#### Scenario: A manager lists projects over budget

- GIVEN one project with an assignment at 104 percent
- WHEN the manager filters the projects list on over budget
- THEN that project is listed
