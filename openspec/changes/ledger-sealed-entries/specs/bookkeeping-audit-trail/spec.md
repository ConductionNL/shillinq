# bookkeeping-audit-trail Specification (delta)

## Purpose

Each posted ledger transaction is sealed into a chain per administration,
and a controller can prove the chain is intact. From shillinq matrix row
`led-tamper-proof`.

## ADDED Requirements

### Requirement: A posted transaction is sealed into its administration's chain (REQ-LSE-001)

When a `GLTransaction` reaches `posted`, shillinq SHALL write
`sealSequence`, `previousSealHash`, `sealHash` and `sealedAt`, where
`sealHash` is the SHA-256 of the previous seal and the canonical posted
content. Two posts in one administration MUST NOT chain onto the same
predecessor, and a post whose seal cannot be written MUST fail.

#### Scenario: A bookkeeper posts and the entry is sealed

- GIVEN Gemeente Voorbeeld with two sealed transactions
- WHEN a bookkeeper posts memorial entry 2026-0904 from the general ledger detail page
- THEN the transaction shows seal sequence 3 and a seal hash
- AND its previous seal hash equals the seal hash of sequence 2

### Requirement: A controller checks the seal chain (REQ-LSE-002)

The general ledger page SHALL offer Check ledger seal, and `occ
shillinq:ledger:verify-seal` SHALL do the same, reporting either that the
chain is intact with the number of entries, or the first transaction whose
content or link no longer matches.

#### Scenario: An intact ledger

- GIVEN three sealed transactions nobody changed
- WHEN a controller presses Check ledger seal on the general ledger page
- THEN the page reports the chain intact over 3 entries

#### Scenario: An amount changed in storage is caught

- GIVEN the amount of a line of transaction 2026-0902 was changed directly in storage after posting
- WHEN the controller runs the check
- THEN it reports transaction 2026-0902 as the first entry that no longer matches its seal

### Requirement: A reversal does not break the chain (REQ-LSE-003)

The sealed content SHALL exclude `state`, so reversing a posted transaction
MUST NOT make the check fail.

#### Scenario: A reversed purchase posting still checks out

- GIVEN transaction 2026-0902 reversed by 2026-0903
- WHEN the controller runs the check
- THEN the chain is reported intact
