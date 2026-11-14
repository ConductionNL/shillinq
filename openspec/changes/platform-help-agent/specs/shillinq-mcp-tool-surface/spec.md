# shillinq-mcp-tool-surface Specification (delta)

## Purpose

The in-app assistant answers how-to questions from shillinq's own user
guide. From shillinq matrix row `plt-help-assistant`.

## ADDED Requirements

### Requirement: The user guide is held as help articles (REQ-PHA-001)

Shillinq SHALL keep a `HelpArticle` per user guide page, seeded from the
app's `docs/` on install and upgrade, with its documentation link and the
manifest page ids it explains, and SHALL leave unchanged articles untouched.

#### Scenario: An upgrade refreshes a changed article

- GIVEN the article "Periode afsluiten" and a new app version whose markdown for it changed
- WHEN the upgrade runs
- THEN the article's body is the new text and other articles keep their version

### Requirement: A shillinq helper agent is offered to hermiq (REQ-PHA-002)

When hermiq is installed, shillinq SHALL offer a "Shillinq helper" agent
template that searches only help articles, uses shillinq's read tools, and
answers with the documentation link of the article it used.

#### Scenario: An administrator adds the helper

- GIVEN hermiq installed next to shillinq
- WHEN an administrator opens hermiq's agent templates
- THEN "Shillinq helper" is listed and can be instantiated

### Requirement: The helper answers for the page the user is on (REQ-PHA-003)

The helper SHALL prefer articles whose page ids include the page the user
asks from.

#### Scenario: A bookkeeper asks on the period close page

- GIVEN a bookkeeper on the period close detail page with the shillinq helper chosen in the companion
- WHEN they ask "hoe sluit ik september af?"
- THEN the answer gives the steps from "Periode afsluiten" and links to its documentation page
