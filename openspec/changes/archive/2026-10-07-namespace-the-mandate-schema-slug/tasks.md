# Tasks

- [x] 1.1 Move the key, slug, title and schema references in the register fragment
      **files**: lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json, lib/Settings/register.d/000-register-declaration.json, lib/Settings/shillinq_mock_register.json
- [x] 1.2 Follow the slug in the manifest fragment
      **files**: src/manifest.d/bookkeeping-verplichtingenadministratie.json
- [x] 2.1 Follow the slug in the enforcer and the settings service
      **files**: lib/Lifecycle/MandateEnforcer.php, lib/Service/SettingsService.php
- [x] 3.1 Map both source spellings onto the namespaced slug
      **files**: lib/Repair/RenameCommitmentSchemas.php
- [x] 4.1 Update the slug-map assertion for the second source
      **files**: tests/Unit/Repair/RenameCommitmentSchemasTest.php
- [x] 4.2 Follow the rename in the kind-enum test, and add the control that stops its
      seeded-mandate loop passing vacuously
      **files**: tests/Unit/Repair/RenameDutchValuesTest.php
