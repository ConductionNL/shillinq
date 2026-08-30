/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Generates `src/manifest.d.shell.json` — a slim, boot-eager projection of
 * every `src/manifest.d/*.json` fragment, used by `src/main.js` to build the
 * vue-router route table and sidebar navigation WITHOUT bundling each
 * fragment's full page `config` (columns/widgets/forms/actions — the bulk of
 * a fragment's bytes) into the `main` webpack chunk.
 *
 * shillinq-manifest-boot-payload-reduction REQ-MBP-001: the merged manifest
 * payload delivered in the `main` chunk MUST NOT ship a feature area's
 * fragment data before the user navigates into it. Measured on this repo's
 * fragment set, a shell page entry (`id`/`route`/`type`/`title`) is ~15% of
 * its full fragment's bytes — the other ~85% (`config`) is fetched lazily by
 * `main.js`'s router guard on first navigation into that fragment's pages,
 * via `require.context('./manifest.d/', false, /\.json$/, 'lazy')`.
 *
 * Every shell page entry carries `_fragment`: the origin fragment's filename
 * stem (matching the lazy `require.context` key), so the router guard knows
 * which chunk to fetch when a route belonging to that page is entered.
 *
 * Run via `npm run build` / `npm run dev` / `npm run watch` (wired as
 * `prebuild`/`predev`/`prewatch`) and `npm run test:unit` (`pretest:unit`) so
 * the checked-in `src/manifest.d.shell.json` can never silently drift from
 * the fragments it summarises. The generated file is ALSO committed so a
 * checkout that skips the "pre" hook (e.g. a stale `npm run build` script
 * invocation) still has a working shell.
 */

const fs = require('fs')
const path = require('path')

const MANIFEST_D_DIR = path.join(__dirname, '..', 'src', 'manifest.d')
const SHELL_OUTPUT_PATH = path.join(__dirname, '..', 'src', 'manifest.d.shell.json')

/**
 * Slim page keys kept eagerly in the shell — everything needed to register a
 * vue-router route + render the current page's title/breadcrumb before its
 * full fragment loads. `config` (columns/widgets/forms/actions/relations —
 * the page-type-specific bulk) is deliberately NOT included; it is merged in
 * by `main.js`'s router guard once the owning fragment is lazily loaded.
 *
 * @type {Array<string>}
 */
const SHELL_PAGE_KEYS = ['id', 'route', 'type', 'title']

/**
 * Project one fragment's `pages[]` down to the shell keys, stamping each
 * entry with `_fragment` (the origin file's basename without extension) so
 * the runtime router guard knows which lazy chunk owns it.
 *
 * @param {Array<object>} pages     The fragment's full `pages[]`.
 * @param {string}        fragment  The fragment's filename stem.
 * @return {Array<object>} Slim page entries.
 */
function slimPages(pages, fragment) {
	if (!Array.isArray(pages)) {
		return []
	}

	return pages.map((page) => {
		const slim = { _fragment: fragment }
		for (const key of SHELL_PAGE_KEYS) {
			if (page && Object.prototype.hasOwnProperty.call(page, key)) {
				slim[key] = page[key]
			}
		}
		return slim
	})
}

/**
 * Build the shell projection of a single fragment JSON object. `menu` is
 * copied through UNCHANGED — the sidebar navigation tree is small and must
 * render correctly for every feature area at boot, lazy-loaded or not.
 * `pageTemplates`/`pageInstances`/`sets` (manifest-entity-scaffold-templating)
 * are also copied through unchanged: they are metadata, not bulky config, and
 * `buildManifest()`'s scaffold expansion needs them present at boot to
 * materialise their instantiated pages into the initial route table.
 *
 * @param {object} fragment      The full fragment JSON (`{menu, pages, ...}`).
 * @param {string} fragmentStem  The fragment's filename stem (no extension).
 * @return {object} The shell fragment: `{menu, pages, pageTemplates?, pageInstances?, sets?}`.
 */
function buildShellFragment(fragment, fragmentStem) {
	const shell = {
		menu: Array.isArray(fragment.menu) ? fragment.menu : [],
		pages: slimPages(fragment.pages, fragmentStem),
	}
	if (Array.isArray(fragment.pageTemplates)) {
		shell.pageTemplates = fragment.pageTemplates
	}
	if (Array.isArray(fragment.pageInstances)) {
		shell.pageInstances = fragment.pageInstances
	}
	if (fragment.sets && typeof fragment.sets === 'object') {
		shell.sets = fragment.sets
	}
	return shell
}

/**
 * Read every `src/manifest.d/*.json` fragment and build the combined shell
 * document: `{ fragments: [...] }`, one shell fragment per source file, in
 * the SAME sorted order `main.js`'s `require.context(...).keys().sort()`
 * already uses (case-sensitive lexical sort of the filename), so boot-time
 * menu/page merge order is identical to the pre-shell eager behaviour.
 *
 * @param {string} [dir] The `manifest.d` directory to read (override for tests).
 * @return {{fragments: Array<object>}} The shell document.
 */
function generateShellDocument(dir = MANIFEST_D_DIR) {
	const files = fs
		.readdirSync(dir)
		.filter((name) => name.endsWith('.json'))
		.sort()

	const fragments = files.map((filename) => {
		const raw = fs.readFileSync(path.join(dir, filename), 'utf8')
		const fragment = JSON.parse(raw)
		const stem = filename.replace(/\.json$/, '')
		return buildShellFragment(fragment, stem)
	})

	return { fragments }
}

/**
 * CLI entrypoint: generate the shell document and write it to
 * `src/manifest.d.shell.json`.
 *
 * @return {void}
 */
function main() {
	const shell = generateShellDocument()
	fs.writeFileSync(SHELL_OUTPUT_PATH, JSON.stringify(shell, null, '\t') + '\n')
	// eslint-disable-next-line no-console
	console.log(
		`[generate-manifest-shell] wrote ${SHELL_OUTPUT_PATH} `
			+ `(${shell.fragments.length} fragments, `
			+ `${shell.fragments.reduce((n, f) => n + f.pages.length, 0)} pages)`,
	)
}

if (require.main === module) {
	main()
}

module.exports = {
	generateShellDocument,
	buildShellFragment,
	slimPages,
	SHELL_PAGE_KEYS,
}
