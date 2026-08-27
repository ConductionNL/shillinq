<!-- Example output — clean-env skill for the Conduction development environment -->

# Expected Output: clean-env

Captured from a real run on 2026-08-27, not written from memory. The previous
version of this file showed a script that does not exist writing "✓ All apps
installed" against five app names, at a URL (`http://nextcloud.local`) the
instance does not serve — an example nobody could ever have produced.

## Successful run

```
$ bash .github/dev-up.sh

==> Starting stack (project=openregister)
==> Waiting for the DB
==> Waiting for all 36 app mounts inside the container
  only 34/36 app dirs visible
    collectives: EMPTY CHECKOUT at openregister/custom_apps/collectives -- clone it or drop its mount; restarting cannot help
    zaakafhandelapp: EMPTY CHECKOUT at zaakafhandelapp -- clone it or drop its mount; restarting cannot help
  every missing dir is an empty checkout, not a mount failure -- not restarting
==> Ensuring un-busted assets are not cached for 6 months
  ok
==> Ensuring custom_apps is writable by www-data
  ok
==> Clearing maintenance mode
==> Reconciling pending app upgrades (only if needed)
  no upgrade needed
==> Healing PHP dependencies (vendor/)
  stackiq: no vendor/autoload.php -- installing
    ok
==> Ensuring Conduction apps are enabled
  enabling keepiq (disabled)
    ok
  enabling integriq (disabled)
    ok
  enabling stackiq (disabled)
    ok
  SKIP zaakafhandelapp -- empty checkout at ../zaakafhandelapp (nothing to enable)
==> Re-reconciling upgrades (enabling an app can register a migration)
  no upgrade needed
==> Checking frontend bundles match the app id
  ok
==> Done. Status:
      - installed: true
      - version: 34.0.0.12
      - maintenance: false
      - needsDbUpgrade: false
    apps visible:  34/36
    apps enabled:  71
    UI: http://localhost:8080   (admin/admin)
```

## What a problem looks like

The script does not hide these; read to the end of the output.

An app that is enabled and would still render a blank page — `occ` calls this
app perfectly healthy, so this section is the only thing that reports it:

```
==> Checking frontend bundles match the app id
  integriq: js/integriq-main.js missing (found openconnector-main.js -- stale, pre-rename)
    fix: (cd ../openconnector && npm ci && npm run build)
...
    ⚠ 5 app(s) need a frontend rebuild before their page renders
```

An app that refused to enable, with the reason rather than a shrug:

```
  enabling keepiq (disabled)
    FAILED: An unhandled exception has been thrown: Error: Class "Ramsey\Uuid\Uuid" not found in …/keepiq/lib/Repair/SeedSecretTypes.php:115
  1 app(s) could not be enabled -- see the errors above
```

The stack failing to start at all — note that a **public** image answers
`denied: denied` when the local ghcr.io credential has expired, so the script
logs out and retries once before believing it:

```
==> Starting stack (project=openregister)
    Error response from daemon: Head "https://ghcr.io/v2/conductionnl/n8n-nextcloud/manifests/latest": denied: denied
  ghcr.io denied a pull -- logging out (credentials may be stale) and retrying once
```
