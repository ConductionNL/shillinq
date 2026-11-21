---
description: Run the shillinq quality suite (PHP strict gate, ESLint, Stylelint, l10n, manifest, registers)
---

Run the quality suite from the repository root and report each failure with its file and line.

```bash
composer check:strict      # lint, phpcs, phpmd, psalm, phpstan, PHPUnit
npm run lint               # ESLint
npm run stylelint          # Stylelint
npm run format             # Prettier check
npm run test:l10n          # every t()/n() string has a catalogue entry
npm run check:manifest     # validate the manifest
npm run check:registers    # validate the register JSON in lib/Settings/
```

`composer check:strict` is the PHP gate CI enforces. Fix what the change touched; do not add baseline entries or suppressions to get green. Auto-fixers: `composer cs:fix`, `composer phpcs:fix`, `npm run lint-fix`.
