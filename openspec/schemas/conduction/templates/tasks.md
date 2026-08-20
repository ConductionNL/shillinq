# Tasks: <change-name>

<!-- The apply phase parses this format to track progress: use `- [ ]` checkboxes for the Implement + Test lines.
     Order tasks by dependency. If migration.md exists, the migration class implementation is Task 1.
     Each task MUST be small enough for one session and verifiable — you know when it's done. -->

## Implementation Tasks

### Task 1: <title>

- **spec_ref**: `openspec/specs/<capability>/spec.md#requirement-<anchor>`
- **files**: `<file paths this task touches>`
- **acceptance_criteria**:
  - GIVEN <precondition> WHEN <action> THEN <outcome>
- [ ] Implement
- [ ] Test

## Verification

- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

<!-- Required for all changes. Mark N/A with justification if not applicable. -->
- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- [ ] Newman/Postman tests for new/changed API endpoints
- [ ] Browser tests (Playwright MCP) for UI changes
- [ ] All tests pass (`composer test`, `newman run`)

## Documentation (company-wide ADR-010)

<!-- See .claude/docs/writing-docs.md for documentation principles. Required for user-facing features. Mark N/A with justification if not applicable. -->
- [ ] Feature documentation updated in `docs/`
- [ ] Screenshot captured and committed to `docs/images/`

## i18n (company-wide ADR-005)

<!-- Required when adding user-facing strings. Mark N/A if no new strings. -->
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added
