# Tasks — spec-anchor-repair (shillinq)

- [x] task-1: Measure broken `@spec` anchors repo-wide with gate-46 resolution logic (2869 on base).
- [x] task-2: Categorise broken anchors by cause (archived→canonical-recoverable vs genuinely-dangling).
- [x] task-3: Apply the deterministic comment-only repointer (`tool/repoint.py`) — 1231 repointed across 325 files (0 anchor-level, 1231 file-level).
- [x] task-4: Comment-only proof — 0 non-`@spec` changed lines; 0 files with asymmetric insertions/deletions.
- [x] task-5: Gate-46 re-verify — broken 2869 → 1638 (all repointed anchors resolve).
- [x] task-6: File the 1638 residual-dangling anchors for human triage (`residual-dangling.md` + umbrella issue).
- [ ] task-7: STALE-BASE GUARD before push — `git diff --numstat origin/development` is `@spec`-lines-only.
- [ ] task-8: PR to `development`, admin-merge, archive change.
