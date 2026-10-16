# administration-import-migration Specification (delta)

## Purpose

An administration moves over from another package through one guided run.
From shillinq matrix row `plt-import`.

## ADDED Requirements

### Requirement: The import lifecycle runs the pipeline (REQ-AIW-001)

Each step of an import batch SHALL run its part of the import pipeline and
store its result on the batch: parsing stages the data, validation writes
findings and blocks on an error, the dry run writes the would-be result,
posting writes the opening entry, open items and relations, and reversing
undoes a posted import while its period is open.

#### Scenario: An error finding blocks posting

- GIVEN a staged batch whose auditfile has an unbalanced opening entry
- WHEN the administrator validates it
- THEN the batch shows state validation failed with the finding naming the entry
- AND posting is not offered

#### Scenario: The same import is not posted twice

- GIVEN a batch that was posted
- WHEN a second batch with the same files and mappings is posted
- THEN the app refuses and names the first batch

### Requirement: A wizard guides the import (REQ-AIW-002)

The import wizard SHALL take the administrator from choosing files in
Nextcloud Files and the source package, through mapping review, validation
findings and the dry run, to posting, on one page, and SHALL refuse a file
the administrator cannot read.

#### Scenario: An administrator moves over from Snelstart

- GIVEN an XAF auditfile exported from Snelstart in the administrator's Files
- WHEN they open the import wizard, pick the file, choose Snelstart, confirm the suggested mappings, pass validation and look at the dry run
- THEN the dry run shows the opening balance and the open items
- AND choosing post shows the batch posted with the opening entry

### Requirement: A posted import is reversible while its period is open (REQ-AIW-003)

The app SHALL reverse a posted import, entries, open items and relations it
created, while the period of its migration date is open, and SHALL refuse
once that period is closed.

#### Scenario: An administrator reverses a wrong import

- GIVEN a posted import whose period is open
- WHEN the administrator chooses reverse on the batch
- THEN the batch shows reversed and its opening entry is reversed
