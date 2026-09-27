# report-delivery Specification

## Purpose

Reports run and arrive on a schedule, and the ledger feeds an outside BI
tool. From shillinq matrix rows `rep-scheduled` and `rep-bi-feed`.

## ADDED Requirements

### Requirement: A controller schedules a report (REQ-RDD-001)

Shillinq SHALL let a user create a report schedule with a catalogue report,
format, frequency, run day, period rule, recipients and destination folder,
from a schedules page or from the report dialog, and pause or resume it.

#### Scenario: The monthly budget report is scheduled

- GIVEN a controller on the report dialog with "Budget versus realisatie" as PDF
- WHEN they choose Schedule this report, monthly on day 5, previous period, for group controllers, into /Rapportages/Maand
- THEN the schedule is listed as active with its next run on the 5th of next month

### Requirement: A due schedule produces and delivers its report (REQ-RDD-002)

A background job SHALL produce every due scheduled report, file it in the
destination folder shared with the recipients, notify them with a link, and
set the next run. A failed run SHALL record its reason and notify the
schedule's owner.

#### Scenario: The 5th of October

- GIVEN the monthly schedule and the job running on 2026-10-05
- WHEN it completes
- THEN /Rapportages/Maand holds the September report and each controller has a notification linking to it

#### Scenario: A run fails

- GIVEN docudesk is not installed
- WHEN the schedule runs
- THEN the schedule shows the reason and its owner is notified

### Requirement: The ledger is declared as a read-only feed (REQ-RDD-003)

Shillinq SHALL declare read-only feed datasets for posted ledger lines,
accounts, periods and relations, each bound to one administration, for
integriq to serve with its own credentials. A dataset without an
administration binding MUST be refused by the declaration's validation.

#### Scenario: A BI workspace pulls its own ledger

- GIVEN integriq serves the declared feed with a credential bound to Gemeente Voorbeeld
- WHEN the municipality's BI tool pulls ledger-lines
- THEN it receives only Gemeente Voorbeeld's posted lines with account, period, dimensions and signed amount
