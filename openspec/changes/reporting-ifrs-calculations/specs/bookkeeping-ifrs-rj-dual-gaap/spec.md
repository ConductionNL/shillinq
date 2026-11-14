# Delta: bookkeeping-ifrs-rj-dual-gaap

## ADDED Requirements

### Requirement: Posting under one framework derives the other and the reconciling difference (REQ-DGAAP-011)

For an administration that reports under both IFRS and Dutch GAAP, posting a transaction marked as divergent SHALL derive the entry under the other framework from the configured adjustment rule and SHALL record the difference on the reconciliation bridge of the period.

#### Scenario: An expected credit loss differs between the frameworks

- **GIVEN** an administration under both frameworks and a receivables provision of EUR 4,000 under Dutch GAAP with an IFRS 9 rule giving EUR 5,200
- **WHEN** the provision is posted
- **THEN** the IFRS entry of EUR 5,200 is derived and the bridge shows a difference of EUR 1,200
