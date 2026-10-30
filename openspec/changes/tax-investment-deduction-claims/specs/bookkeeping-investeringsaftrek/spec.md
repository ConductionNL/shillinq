# bookkeeping-investeringsaftrek Specification (delta)

## Purpose

The investment deduction parts that shipped in June are wired to fixed
assets and a yearly overview. From shillinq matrix row
`tax-investment-deduction`.

## ADDED Requirements

### Requirement: An activated fixed asset gets its deduction eligibility (REQ-IDC-001)

When a fixed asset becomes active, the app SHALL record for it whether it
qualifies for KIA, EIA, MIA and Vamil, with the reason for each, and SHALL
keep an override a bookkeeper has set.

#### Scenario: A bookkeeper activates a delivery van

- GIVEN a bookkeeper at Bakkerij De Korenaar with a fixed asset "Bestelbus" of EUR 32,000 acquired 2026-03-01
- WHEN they activate the asset
- THEN the asset's investment deduction tab shows KIA eligible with its reason
- AND EIA, MIA and Vamil not eligible with the reason that no list code matched

#### Scenario: An override survives a later change

- GIVEN an asset whose KIA flag a bookkeeper overrode to not eligible with reason "privé gebruik"
- WHEN the asset is updated again
- THEN the KIA flag stays not eligible with that reason

### Requirement: A year's deduction is calculated per scheme (REQ-IDC-002)

The app SHALL calculate the investment deduction for a year per scheme from
the eligible assets acquired in that year and the tables of that year,
SHALL write them as draft claims without changing a claim already used in a
tax return, and SHALL refuse a year for which no tables are loaded.

#### Scenario: A bookkeeper calculates 2026

- GIVEN KIA-eligible assets acquired in 2026 totalling EUR 64,000 and the 2026 KIA tiers
- WHEN the bookkeeper chooses recalculate on the investment deductions page for 2026
- THEN a draft KIA claim shows the deduction the 2026 tiers give for EUR 64,000
- AND the heat pump has an EIA claim at its list percentage

#### Scenario: A year without tables

- GIVEN no KIA tiers are loaded for 2027
- WHEN the bookkeeper recalculates 2027
- THEN the app refuses and names the missing KIA tiers for 2027

### Requirement: The deductions are visible per year (REQ-IDC-003)

The app SHALL show a page listing the claims of a year with the total per
scheme, reachable from the tax menu, and the notification deadline for an
EIA or MIA asset.

#### Scenario: A bookkeeper prepares the tax return

- GIVEN calculated claims for 2026
- WHEN the bookkeeper opens investment deductions from the tax menu
- THEN the page lists the claims with totals for KIA and EIA
- AND the heat pump row shows its notification deadline
