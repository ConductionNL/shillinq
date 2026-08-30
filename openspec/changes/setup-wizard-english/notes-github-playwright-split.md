# Cross-repo follow-up: run the isolated wizard project in CI

## Why this note exists

`tests/e2e/setup-wizard-english.spec.ts` must reset shillinq's setup app-config
**server-side** (`SetupController::status()` gates on server state, not browser
state). Playwright parallelises spec FILES across 4 workers against ONE shared
Nextcloud, so for the whole window between that reset and the `afterAll`
restore, every other worker's shillinq renders the blocking "Set up this app"
dialog instead of its page. Measured consequence on `development`
(run 32329757674): 5 failed / 261 passed, and **4 of the 5 failures were in
other spec files** (`provincies-bbv-variant`, `belastingen`) that this branch
never touches. Victim sets varied run to run on identical code — the signature
of a race window, not a deterministic bug.

The spec is therefore collected only when `RUN_SETUP_WIZARD_SPEC=1`. It is a
fully working, fully fixed spec — **not** a skipped or abandoned test. This gate
is a scheduling workaround, and it is temporary.

## Why Playwright config alone cannot fix it

Playwright's only cross-project sequencing primitive is `dependencies`, and it
hard-couples ordering to pass/fail. From the installed runtime
(`node_modules/playwright/lib/runner/index.js`, `createRunTestsTask()`):

```js
const hasFailedDeps = project.deps.some((p) => !successfulProjects.has(p));
if (!hasFailedDeps) phaseTestGroups.push(...testGroups);
```

So wiring `dependencies` would trade one hazard for a worse, silent one: if
`chromium` depended on the isolated project, a single wizard failure would blank
out ~50 files' results; the reverse would silently stop running the wizard spec
whenever anything else failed. Projects with no `dependencies` relationship land
in the same phase and interleave in the shared worker pool — which is exactly
the contamination we are avoiding. There is no "sequence but don't gate"
primitive.

## The actual fix (ConductionNL/.github)

The reusable workflow `.github/workflows/quality.yml`'s `playwright` job runs a
single `npx playwright test --config="$CONFIG"` with **no** `--project`. Split
that into one invocation per project, each contributing independently to the
job's pass/fail, neither gating the other's execution.

**The crux — do not hardcode project names.** 17 other fleet apps share this
workflow and most have exactly one project; `--project=setup-wizard-isolated`
would fail immediately for them. Derive the list from the app's own config:

```bash
PROJECTS=$(npx playwright test --config="$CONFIG" --list --reporter=json \
  | jq -r '[.suites[].specs[].tests[].projectName] | unique | .[]')
for p in $PROJECTS; do
  npx playwright test --config="$CONFIG" --project="$p" || FAILED=1
done
exit "${FAILED:-0}"
```

An app with one project runs exactly as it does today (one invocation, same
name); an app with two runs them back-to-back without co-scheduling. The env
var that enables the isolated project would be set in that workflow, so the
spec returns to CI coverage the moment this lands.

## Until then

Run it explicitly:

```bash
RUN_SETUP_WIZARD_SPEC=1 npx playwright test --project=setup-wizard-isolated
```
