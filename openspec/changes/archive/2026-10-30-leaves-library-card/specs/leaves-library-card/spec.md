# leaves-library-card Delta

**Status**: proposed
**Scope**: shillinq

## ADDED Requirements

### Requirement: The finance leaves report to the library card and the linked card

The payment-requests and contract leaves SHALL declare `chrome: "card"`. Given `onHeader` they SHALL report their count
and empty state once read and SHALL NOT draw their own empty line. Given `onSummary` they SHALL draw nothing and SHALL
report `{ count, noun, rows }` once read, and nothing while loading or after a failed read.

#### Scenario: a case without fees or a contract in the Gekoppeld card

- **GIVEN** a dossiq case without payment requests or a contract
- **THEN** both leaves report count 0, with the nouns "leges en betalingen" and "contract"
