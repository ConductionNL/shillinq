---
description: Run the shillinq unit tests (PHPUnit and Vitest)
---

Run both unit suites from the repository root:

```bash
composer test:unit     # PHPUnit, tests/Unit and tests/unit (phpunit-unit.xml)
npm run test:unit      # Vitest (regenerates the manifest shell first)
```

To run one PHP test file: `./vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/<path>Test.php`.
To run one Vitest file: `npx vitest run <path>`.

$ARGUMENTS
