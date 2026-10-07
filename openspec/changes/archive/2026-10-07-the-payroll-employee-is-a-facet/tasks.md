# Tasks

- [x] 1.1 Move the key, slug, `$ref` and schema references in the register fragment
      **files**: lib/Settings/register.d/bookkeeping-detachering-payroll-administratie.json, lib/Settings/register.d/000-register-declaration.json, lib/Settings/shillinq_mock_register.json
- [x] 1.2 Add the `employee` uuid pointing at humaniq's owner, and say so in the description
      **files**: lib/Settings/register.d/bookkeeping-detachering-payroll-administratie.json
- [x] 2.1 Follow the slug in the payroll guard, leaving the hrmq cost-rate lookup alone
      **files**: lib/Lifecycle/PayrollGuard.php
- [x] 3.1 Rename the row in place before the import, scoped to this app
      **files**: lib/Repair/RenameCollidingSchemaSlugs.php, appinfo/info.xml
- [x] 3.2 Pin the write, the application scoping, the two refusals, and the owner pointer
      **files**: tests/Unit/Repair/RenameCollidingSchemaSlugsTest.php
- [x] 4.1 Follow the rename in the guard and fragment tests
      **files**: tests/Unit/Lifecycle/PayrollGuardTest.php, tests/Unit/Service/PayrollDetacheringFragmentTest.php
