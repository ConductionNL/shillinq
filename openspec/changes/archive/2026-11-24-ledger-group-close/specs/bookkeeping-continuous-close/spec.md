# Delta: bookkeeping-continuous-close

## ADDED Requirements

### Requirement: The nightly soft close flags the swings per account (REQ-CLS-011)

After the nightly soft close the system SHALL run the swing check for the closed period and store a run that lists each account whose movement passes its materiality threshold. The flux analysis page SHALL show the latest run.

#### Scenario: A large swing on one account is flagged

- **GIVEN** account 4000 moved EUR 30,000 against EUR 8,000 a month earlier and the threshold is 25 percent
- **WHEN** the nightly soft close finishes
- **THEN** the flux analysis page lists account 4000 as a swing to explain
