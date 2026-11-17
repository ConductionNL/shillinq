/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Generates `src/administrationScopedPages.json`: the schemas whose records
 * carry `administrationId` (the union of every `lib/Settings/register.d/`
 * fragment's properties for that schema), and the manifest index pages over
 * them. `AdministrationScopedIndex.vue` scopes exactly those pages to the
 * active administration (ledger-multi-administration REQ-LMA-002).
 *
 * The list is generated from the schemas, never typed by hand, so a schema
 * that gains `administrationId` is scoped on the next run.
 * `tests/vitest/administrationScopedIndex.spec.js` fails while the committed
 * file differs from what this script writes.
 *
 * Usage: node scripts/generate-administration-scoped-pages.js
 *
 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-lists-show-the-active-administration-only-req-lma-002
 */

const fs = require('fs')
const path = require('path')

const SCOPE_FIELD = 'administrationId'

/**
 * Read every `.json` file in a directory, sorted by name.
 *
 * @param {string} dir The directory.
 * @return {Array<object>} The parsed files.
 */
function readJsonDir(dir) {
	if (!fs.existsSync(dir)) {
		return []
	}
	return fs.readdirSync(dir)
		.filter((name) => name.endsWith('.json'))
		.sort()
		.map((name) => JSON.parse(fs.readFileSync(path.join(dir, name), 'utf8')))
}

/**
 * The scoped schemas and the index pages over them.
 *
 * @param {string} root The app root.
 * @return {{schemas: Array<string>, pages: Array<string>}} Both lists, sorted.
 */
function buildScopedPages(root) {
	const properties = new Map()
	for (const fragment of readJsonDir(path.join(root, 'lib/Settings/register.d'))) {
		const schemas = (fragment.components && fragment.components.schemas) || {}
		for (const [name, schema] of Object.entries(schemas)) {
			const names = properties.get(name) || new Set()
			Object.keys((schema && schema.properties) || {}).forEach((key) => names.add(key))
			properties.set(name, names)
		}
	}
	const schemas = [...properties.entries()]
		.filter(([, names]) => names.has(SCOPE_FIELD))
		.map(([name]) => name)
		.sort()
	const scoped = new Set(schemas)

	const manifests = [JSON.parse(fs.readFileSync(path.join(root, 'src/manifest.json'), 'utf8'))]
		.concat(readJsonDir(path.join(root, 'src/manifest.d')))
	const pages = new Set()
	for (const manifest of manifests) {
		for (const page of manifest.pages || []) {
			const schema = page && page.type === 'index' && page.config ? page.config.schema : null
			if (typeof schema === 'string' && scoped.has(schema)) {
				pages.add(page.id)
			}
		}
	}

	return { schemas, pages: [...pages].sort() }
}

/**
 * The file's content as written, so the check compares exactly.
 *
 * @param {{schemas: Array<string>, pages: Array<string>}} scopedPages The lists.
 * @return {string} The JSON text.
 */
function serialize(scopedPages) {
	return JSON.stringify(scopedPages, null, '\t') + '\n'
}

module.exports = { buildScopedPages, serialize, SCOPE_FIELD }

if (require.main === module) {
	const root = path.resolve(__dirname, '..')
	const scopedPages = buildScopedPages(root)
	fs.writeFileSync(path.join(root, 'src/administrationScopedPages.json'), serialize(scopedPages))
	console.log(`[administration-scoped-pages] ${scopedPages.schemas.length} schemas, ${scopedPages.pages.length} index pages`)
}
