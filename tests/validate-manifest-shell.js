#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-manifest-shell.js — the committed `src/manifest.d.shell.json` MUST
// be byte-identical to what `scripts/generate-manifest-shell.js` produces from
// the `src/manifest.d/*.json` fragments in the same tree.
//
// WHY THIS GATE EXISTS. `src/manifest.d.shell.json` is a GENERATED file that
// is nevertheless COMMITTED. Every local consumer regenerates it first —
// `prebuild`, `predev`, `prewatch` and `pretest:unit` all run the generator
// before webpack or vitest ever reads it — so the committed copy can drift
// arbitrarily far from the fragments it summarises WITHOUT ANY CHECK GOING
// RED. The regeneration that makes the build correct is exactly what makes
// the drift invisible.
//
// Observed twice on 2026-08-20, on two different branches:
//   - `feat/budget-known-costs` shipped a shell containing ZERO occurrences of
//     `BudgetLineDerivations` and `AnnualBudgets`, with both fragments present
//     on the branch;
//   - `development` itself was stale by +77 lines — an entire `Budgets` menu
//     group (3 children) plus 6 `budget-core-schema` pages simply absent.
//
// WHY IT MATTERS DESPITE THE REGENERATION. `src/main.js` feeds
// `manifestShell.fragments` to `buildManifest()`, which builds BOTH the
// vue-router route table AND the sidebar navigation tree. The lazy
// `require.context('./manifest.d/', false, /\.json$/, 'lazy')` that runs later
// only backfills each page's `config` — it never adds a page and never adds a
// menu entry. So a page missing from the shell has no route and no nav entry
// at runtime, no matter how complete its fragment is. Anything that reads the
// committed shell without regenerating first — a reviewer reading the diff, a
// deployment that copies `src/` without running a `pre*` hook, a tool that
// parses the shell as the app's page inventory — is reading a booby trap.
//
// HOW IT CHECKS. The real CLI entrypoint is run, not a re-implementation of
// its projection rules: `scripts/generate-manifest-shell.js` and every
// fragment are copied into a throwaway sandbox directory, and the generator is
// executed there with `node`, exactly as `npm run prebuild` executes it. The
// generator resolves its own output path from `__dirname`, so inside the
// sandbox it writes the sandbox's `src/manifest.d.shell.json` and CANNOT touch
// the working tree. This check is side-effect free: it writes nothing outside
// its sandbox and deletes the sandbox on the way out, including on failure.
//
// A CHECK THAT STOPPED SEEING ITS SUBJECT MUST NOT REPORT SUCCESS. Before any
// comparison is trusted, the regenerated document must be non-empty, parse as
// JSON, carry at least one fragment and at least one page, and carry EXACTLY
// as many fragments as there are `src/manifest.d/*.json` files on disk. A
// generator that crashed, wrote nothing, or emitted `{"fragments":[]}` would
// otherwise compare "equal" against a hypothetical equally-empty committed
// file, or simply produce a comparison that proves nothing. Each of those
// conditions is a FAIL, never a PASS.
//
// FIX WHEN THIS FAILS: run `node scripts/generate-manifest-shell.js` and
// commit the result. Never hand-edit `src/manifest.d.shell.json` — it is
// output, and the next `npm run build` will overwrite the edit.
//
// Usage:
//   node tests/validate-manifest-shell.js
//
// Exit codes:
//   0 — the committed shell is byte-identical to a freshly generated one
//   1 — drift, OR the generator/committed file could not be read, OR the
//       regenerated document was empty/implausible (i.e. nothing was compared)

'use strict'

const { spawnSync } = require('child_process')
const fs = require('fs')
const os = require('os')
const path = require('path')

const LOG = '[validate-manifest-shell]'

const REPO_ROOT = path.resolve(__dirname, '..')
const GENERATOR_PATH = path.join(REPO_ROOT, 'scripts', 'generate-manifest-shell.js')
const MANIFEST_D_DIR = path.join(REPO_ROOT, 'src', 'manifest.d')
const COMMITTED_SHELL_PATH = path.join(REPO_ROOT, 'src', 'manifest.d.shell.json')

// Cap on how many drifted ids are enumerated before the list is truncated —
// a wholesale regeneration can move hundreds, and a screen of ids is enough
// to identify WHAT drifted. The totals above the list are never truncated.
const MAX_LISTED_IDS = 40

/**
 * Print one or more error lines with the shared prefix and exit non-zero.
 * Every failure path in this file goes through here, so the FAIL wording and
 * the exit code can never disagree.
 *
 * @param {Array<string>} lines Message lines, printed in order to stderr.
 * @return {void}
 */
function fail(lines) {
	for (const line of lines) {
		console.error(`${LOG} ${line}`)
	}
	process.exit(1)
}

/**
 * Count the `*.json` fragment files the generator will read, using the same
 * `.endsWith('.json')` filter it and `check-manifest-budget.js` use —
 * `src/manifest.d/README.md` exists and is NOT a fragment.
 *
 * @return {number} Number of fragment files in `src/manifest.d/`.
 */
function countFragmentFiles() {
	return fs.readdirSync(MANIFEST_D_DIR).filter((name) => name.endsWith('.json'))
		.length
}

/**
 * Copy the generator and every fragment into a throwaway sandbox and run the
 * REAL CLI entrypoint there with `node`, exactly as the `prebuild` hook does.
 * The generator derives its output path from its own `__dirname`, so the
 * sandbox copy writes `<sandbox>/src/manifest.d.shell.json` and the working
 * tree's copy is never opened for writing.
 *
 * @param {string} sandboxDir An existing, empty directory to work in.
 * @return {{outputPath: string, status: number, stdout: string, stderr: string}}
 *   The path the generator was asked to write and the child process result.
 */
function regenerateInSandbox(sandboxDir) {
	const sandboxScripts = path.join(sandboxDir, 'scripts')
	const sandboxSrc = path.join(sandboxDir, 'src')
	fs.mkdirSync(sandboxScripts, { recursive: true })
	fs.mkdirSync(sandboxSrc, { recursive: true })
	fs.copyFileSync(
		GENERATOR_PATH,
		path.join(sandboxScripts, path.basename(GENERATOR_PATH)),
	)
	fs.cpSync(MANIFEST_D_DIR, path.join(sandboxSrc, 'manifest.d'), {
		recursive: true,
	})

	const result = spawnSync(
		process.execPath,
		[path.join(sandboxScripts, path.basename(GENERATOR_PATH))],
		{ cwd: sandboxDir, encoding: 'utf8' },
	)

	return {
		outputPath: path.join(sandboxSrc, 'manifest.d.shell.json'),
		status: result.status,
		stdout: (result.stdout || '').trim(),
		stderr: (result.stderr || '').trim(),
	}
}

/**
 * Collect every menu node id in a fragment's `menu[]`, at any depth, so a
 * nav entry that appears or disappears can be named in the failure output.
 *
 * @param {Array<object>} nodes A `menu[]` array (or anything, defensively).
 * @param {Set<string>}   out   Accumulator the ids are added to.
 * @return {void}
 */
function collectMenuIds(nodes, out) {
	if (!Array.isArray(nodes)) {
		return
	}
	for (const node of nodes) {
		if (!node || typeof node !== 'object') {
			continue
		}
		if (typeof node.id === 'string') {
			out.add(node.id)
		}
		collectMenuIds(node.children, out)
	}
}

/**
 * Summarise a shell document into the identifier sets the failure report
 * diffs. Page entries are keyed `"<_fragment>:<id>"` because a page id is
 * only unique once its owning fragment is named, and `_fragment` is the very
 * field the runtime router guard uses to pick the lazy chunk.
 *
 * @param {object} doc A parsed shell document (`{fragments: [...]}`).
 * @return {{fragmentCount: number, pageCount: number, pageKeys: Set<string>, menuIds: Set<string>}}
 */
function describeShell(doc) {
	const fragments = Array.isArray(doc && doc.fragments) ? doc.fragments : []
	const pageKeys = new Set()
	const menuIds = new Set()
	let pageCount = 0

	for (const fragment of fragments) {
		const pages = Array.isArray(fragment && fragment.pages) ? fragment.pages : []
		pageCount += pages.length
		for (const page of pages) {
			if (!page || typeof page !== 'object') {
				continue
			}
			pageKeys.add(`${page._fragment || '?'}:${page.id || '?'}`)
		}
		collectMenuIds(fragment && fragment.menu, menuIds)
	}

	return { fragmentCount: fragments.length, pageCount, pageKeys, menuIds }
}

/**
 * Members of `a` that are absent from `b`, sorted — the failure report calls
 * this twice per identifier kind to get both directions of the drift.
 *
 * @param {Set<string>} a The set to take members from.
 * @param {Set<string>} b The set to test membership against.
 * @return {Array<string>} Sorted members of `a` not in `b`.
 */
function missingFrom(a, b) {
	return [...a].filter((value) => !b.has(value)).sort()
}

/**
 * Locate the first line at which two texts differ, so drift that changes no
 * page or menu id (a retitled page, a changed route, a reordered fragment)
 * is still reported with something concrete to look at.
 *
 * @param {string} committed The committed file's text.
 * @param {string} generated The freshly generated text.
 * @return {{line: number, committed: string, generated: string}|null} The
 *   1-based line number and both sides, or null if the texts are equal.
 */
function firstDifferingLine(committed, generated) {
	const a = committed.split('\n')
	const b = generated.split('\n')
	const limit = Math.max(a.length, b.length)
	for (let i = 0; i < limit; i++) {
		if (a[i] !== b[i]) {
			return {
				line: i + 1,
				committed: a[i] === undefined ? '<end of file>' : a[i],
				generated: b[i] === undefined ? '<end of file>' : b[i],
			}
		}
	}
	return null
}

/**
 * Print a labelled, truncated list of drifted identifiers to stderr.
 *
 * @param {string}        label Human-readable heading for the list.
 * @param {Array<string>} ids   The identifiers to print.
 * @return {void}
 */
function reportIds(label, ids) {
	if (ids.length === 0) {
		return
	}

	console.error(`${LOG} ${label} (${ids.length}):`)
	for (const id of ids.slice(0, MAX_LISTED_IDS)) {
		console.error(`${LOG}     ${id}`)
	}
	if (ids.length > MAX_LISTED_IDS) {
		console.error(
			`${LOG}     ... and ${ids.length - MAX_LISTED_IDS} more not listed`,
		)
	}
}

/**
 * Read and sanity-check the regenerated shell. Returns the text only if it is
 * a plausible product of the generator; every implausible outcome FAILS here
 * rather than being compared, so a generator that stopped producing its
 * subject can never be read as agreement.
 *
 * @param {{outputPath: string, status: number, stdout: string, stderr: string}} run
 *   The result of regenerateInSandbox().
 * @param {number} expectedFragments Fragment files counted on disk.
 * @return {string} The regenerated shell's text.
 */
function readGeneratedOrFail(run, expectedFragments) {
	if (run.status !== 0) {
		fail([
			`FAIL: the generator exited ${run.status} — nothing was compared.`,
			`Command: ${process.execPath} scripts/generate-manifest-shell.js`,
			run.stderr ? `stderr: ${run.stderr}` : 'stderr: <empty>',
			'A generator that cannot run tells you nothing about the committed',
			'shell. Fix the generator before trusting this gate again.',
		])
	}

	if (!fs.existsSync(run.outputPath)) {
		fail([
			'FAIL: the generator exited 0 but wrote no output file — nothing was',
			`compared. Expected: ${run.outputPath}`,
			run.stdout
				? `generator stdout: ${run.stdout}`
				: 'generator stdout: <empty>',
		])
	}

	const text = fs.readFileSync(run.outputPath, 'utf8')
	if (text.trim() === '') {
		fail([
			'FAIL: the generator produced an EMPTY shell document — nothing was',
			'compared. An empty comparison is not a passing comparison.',
		])
	}

	let doc
	try {
		doc = JSON.parse(text)
	} catch (err) {
		fail([
			`FAIL: the regenerated shell is not valid JSON: ${err.message}`,
			'Nothing was compared.',
		])
	}

	const summary = describeShell(doc)
	if (summary.fragmentCount === 0 || summary.pageCount === 0) {
		fail([
			`FAIL: the regenerated shell holds ${summary.fragmentCount} fragment(s) and`,
			`${summary.pageCount} page(s) — nothing meaningful was compared. The`,
			'generator ran but stopped seeing its subject; a green result here would',
			'say nothing about the committed shell.',
		])
	}

	if (summary.fragmentCount !== expectedFragments) {
		fail([
			`FAIL: the regenerated shell holds ${summary.fragmentCount} fragment(s) but`,
			`src/manifest.d/ contains ${expectedFragments} *.json fragment file(s).`,
			'The regeneration did not see every fragment, so its agreement or',
			'disagreement with the committed shell proves nothing.',
		])
	}

	return text
}

function main() {
	if (!fs.existsSync(GENERATOR_PATH)) {
		fail([
			`FAIL: generator not found at ${GENERATOR_PATH}.`,
			'Without it this check cannot regenerate anything, so it cannot pass.',
		])
	}
	if (!fs.existsSync(MANIFEST_D_DIR)) {
		fail([
			`FAIL: fragment directory not found at ${MANIFEST_D_DIR}.`,
			'Nothing to regenerate from, so nothing can be compared.',
		])
	}

	const expectedFragments = countFragmentFiles()
	if (expectedFragments === 0) {
		fail([
			`FAIL: ${MANIFEST_D_DIR} holds no *.json fragments.`,
			'A scan with no subject must not report success.',
		])
	}

	const sandboxDir = fs.mkdtempSync(
		path.join(os.tmpdir(), 'validate-manifest-shell-'),
	)
	// Cleanup is registered on `exit`, NOT wrapped in try/finally: every
	// failure path here calls process.exit(), which does NOT run finally
	// blocks, and a gate that litters /tmp on every red run is a gate people
	// start ignoring. `rmSync` with `force` is idempotent, so the explicit
	// call below on the happy path is harmless.
	process.on('exit', () => {
		fs.rmSync(sandboxDir, { recursive: true, force: true })
	})

	const run = regenerateInSandbox(sandboxDir)
	const generated = readGeneratedOrFail(run, expectedFragments)
	fs.rmSync(sandboxDir, { recursive: true, force: true })

	if (!fs.existsSync(COMMITTED_SHELL_PATH)) {
		fail([
			`FAIL: ${COMMITTED_SHELL_PATH} is missing.`,
			'src/main.js imports it at boot, so its absence breaks every route and',
			'every sidebar entry. Run `node scripts/generate-manifest-shell.js` and',
			'commit the result.',
		])
	}

	const committed = fs.readFileSync(COMMITTED_SHELL_PATH, 'utf8')
	if (committed.trim() === '') {
		fail([
			`FAIL: ${COMMITTED_SHELL_PATH} is empty.`,
			'Run `node scripts/generate-manifest-shell.js` and commit the result.',
		])
	}

	const generatedSummary = describeShell(JSON.parse(generated))

	console.log(
		`${LOG} regenerated from ${expectedFragments} fragment file(s): `
			+ `${generatedSummary.fragmentCount} fragments, `
			+ `${generatedSummary.pageCount} pages, `
			+ `${generatedSummary.menuIds.size} menu ids, `
			+ `${Buffer.byteLength(generated)} bytes`,
	)

	console.log(
		`${LOG} committed src/manifest.d.shell.json: `
			+ `${Buffer.byteLength(committed)} bytes`,
	)

	if (committed === generated) {
		console.log(
			`${LOG} PASS — the committed shell is byte-identical to a freshly `
				+ `generated one (${generatedSummary.pageCount} pages compared).`,
		)
		process.exit(0)
	}

	console.error(
		`${LOG} FAIL — src/manifest.d.shell.json has DRIFTED from src/manifest.d/*.json.`,
	)

	let committedSummary = null
	try {
		committedSummary = describeShell(JSON.parse(committed))
	} catch (err) {
		console.error(
			`${LOG} the committed shell is not valid JSON (${err.message}) — it cannot `
				+ 'be diffed structurally, only replaced.',
		)
	}

	if (committedSummary !== null) {
		console.error(
			`${LOG} fragments ${committedSummary.fragmentCount} -> `
				+ `${generatedSummary.fragmentCount}, pages `
				+ `${committedSummary.pageCount} -> ${generatedSummary.pageCount}, `
				+ `menu ids ${committedSummary.menuIds.size} -> `
				+ `${generatedSummary.menuIds.size} (committed -> regenerated)`,
		)
		reportIds(
			'pages MISSING from the committed shell (no route, no nav entry at boot)',
			missingFrom(generatedSummary.pageKeys, committedSummary.pageKeys),
		)
		reportIds(
			'pages STALE in the committed shell (no longer in any fragment)',
			missingFrom(committedSummary.pageKeys, generatedSummary.pageKeys),
		)
		reportIds(
			'menu ids MISSING from the committed shell',
			missingFrom(generatedSummary.menuIds, committedSummary.menuIds),
		)
		reportIds(
			'menu ids STALE in the committed shell',
			missingFrom(committedSummary.menuIds, generatedSummary.menuIds),
		)
	}

	const diffLine = firstDifferingLine(committed, generated)
	if (diffLine !== null) {
		console.error(`${LOG} first differing line ${diffLine.line}:`)

		console.error(`${LOG}     committed:   ${diffLine.committed}`)

		console.error(`${LOG}     regenerated: ${diffLine.generated}`)
	}

	fail([
		'FIX: run `node scripts/generate-manifest-shell.js` and commit the result.',
		'Do NOT hand-edit src/manifest.d.shell.json — it is generated output, and',
		'the next `npm run build` overwrites the edit. The drift is invisible',
		'locally because prebuild/predev/prewatch/pretest:unit all regenerate the',
		'file before anything reads it; only the COMMITTED copy is stale, and',
		'src/main.js builds the router table and the sidebar from it.',
	])
}

module.exports = {
	collectMenuIds,
	countFragmentFiles,
	describeShell,
	firstDifferingLine,
	missingFrom,
	regenerateInSandbox,
}

if (require.main === module) {
	main()
}
