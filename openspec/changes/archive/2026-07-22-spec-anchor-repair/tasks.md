# Tasks — spec-anchor-repair (shillinq)

- [x] task-1: Measure broken `@spec` anchors repo-wide with gate-46 resolution logic (2869 on base).
- [x] task-2: Categorise broken anchors by cause (archived→canonical-recoverable vs genuinely-dangling).
- [x] task-3: Apply the deterministic comment-only repointer (`tool/repoint.py`) — 1231 repointed across 325 files (0 anchor-level, 1231 file-level).
- [x] task-4: Comment-only proof — 0 non-`@spec` changed lines; 0 files with asymmetric insertions/deletions.
- [x] task-5: Gate-46 re-verify — broken 2869 → 1638 (all repointed anchors resolve).
- [x] task-6: File the 1638 residual-dangling anchors for human triage (`residual-dangling.md` + umbrella issue).
- [x] task-7: STALE-BASE GUARD before push — re-verified 2026-07-22: the repoint
  commit (`a954df79`, "docs(spec): spec-anchor-repair — repoint 1,231 broken
  @spec anchors to canonical specs") already merged to `development` via PR #485
  (`991b2286`). `git diff --numstat a954df79^ a954df79 -- lib src` confirms every
  changed file has `insertions == deletions` (1:1 comment rewrite), and
  `git diff --unified=0 a954df79^ a954df79 -- lib src` confirms 0 non-`@spec`
  changed lines. Base is not stale — no further push needed for this guard.
- [x] task-8: PR to `development`, admin-merge, archive change. PR #485 merged
  2026-07-16. Archive completed 2026-07-22 with `openspec archive
  spec-anchor-repair --yes --skip-specs` (comment-only change, no requirement
  deltas — consistent with other doc/tooling-only archived changes in this repo).
  Spot-checked: `grep -rn "@spec openspec/specs/" lib/ src/` → 1323 occurrences /
  92 unique targets, all 92 target files verified to exist on disk (0 missing),
  0 anchor-level (`#requirement-...`) fragments, matching design.md's claim of
  "0 to an exact requirement heading, 1231 to the capability spec file".
