/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of scripts/check-schema-l10n.js, the ratchet on untranslated
 * schema strings. Each scenario writes a small repository (lib/Settings
 * schema JSON, l10n/en.json, the baseline) to a scratch directory, runs a
 * copy of the script there, and compares exit code, stdout, stderr and the
 * baseline file afterwards with
 * tests/vitest/fixtures/checkSchemaL10n.golden.json.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 */

import { spawnSync } from 'child_process'
import fs from 'fs'
import os from 'os'
import path from 'path'
import { afterAll, describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const SCRIPT = path.join(ROOT, 'scripts/check-schema-l10n.js')
const GOLDEN = path.join(__dirname, 'fixtures/checkSchemaL10n.golden.json')
const RECORD = process.env.SHILLINQ_GOLDEN_RECORD === '1'
const BASELINE = 'l10n/.schema-l10n-baseline.json'
const recorded = {}

const REGISTER = {
	components: {
		schemas: {
			invoice: {
				title: 'Invoice',
				properties: {
					number: { title: 'Number', description: 'The invoice number' },
					status: {
						title: 'Status',
						enum: ['open', 'paid'],
						'x-enum-labels': { open: 'Open', paid: 'Paid' },
					},
					tags: {
						type: 'array',
						title: 'Tags',
						items: { 'x-enum-labels': { a: 'Tag A', b: '  ' } },
					},
					blank: { title: '   ', description: 7 },
					nothing: null,
					scalar: 'text',
					labelsNull: { title: 'Labels null', 'x-enum-labels': null },
					itemsNull: { title: 'Number', items: null },
					nested: {
						title: 'Nested',
						properties: { inner: { title: 'Inner title' } },
					},
				},
			},
			listed: [{ title: 'Ignored', properties: { x: { title: 'In array' } } }],
			arrayProps: { title: 'Array props', properties: [{ title: 'No' }] },
			nullProps: { title: 'Null props', properties: null },
			'x-notes': { title: 'Note title', properties: { n: { title: 'Note' } } },
		},
	},
}

const SECOND = {
	title: 'Payment',
	properties: {
		amount: { title: 'Amount', description: 'Amount paid' },
		state: { title: 'State', items: { 'x-enum-labels': ['Queued', 'Sent'] } },
	},
}

const FILES = {
	'lib/Settings/shillinq_register.json': JSON.stringify(REGISTER),
	'lib/Settings/register.d/payment.json': JSON.stringify(SECOND),
	'lib/Settings/register.d/deeper/broken.json': '{ not json',
	'lib/Settings/register.d/deeper/readme.txt': JSON.stringify(SECOND),
	'l10n/en.json': JSON.stringify({
		translations: { Invoice: 'Invoice', Number: 'Number', Paid: 'Paid' },
	}),
}

/**
 * The files of a scenario: the shared repository with overrides, where a
 * null value removes the file.
 *
 * @param {object} overrides Path to content, or null.
 * @return {object} Path to content.
 */
function repoFiles(overrides) {
	const files = { ...FILES, ...overrides }
	return Object.fromEntries(
		Object.entries(files).filter(([, content]) => content !== null),
	)
}

/**
 * The baseline file content for a count.
 *
 * @param {number} uncovered The allowed uncovered count.
 * @return {string} The JSON.
 */
function baseline(uncovered) {
	return JSON.stringify({ uncovered }, null, 2) + '\n'
}

const SCENARIOS = {
	'at the baseline': { files: { [BASELINE]: baseline(18) }, args: [] },
	'below the baseline': { files: { [BASELINE]: baseline(20) }, args: [] },
	'above the baseline': { files: { [BASELINE]: baseline(3) }, args: [] },
	'one above the baseline': { files: { [BASELINE]: baseline(17) }, args: [] },
	'no baseline': { files: {}, args: [] },
	'update writes the baseline': {
		files: { [BASELINE]: baseline(3) },
		args: ['--update'],
	},
	'update without a baseline': { files: {}, args: ['--update'] },
	'list and check': { files: { [BASELINE]: baseline(18) }, args: ['--list'] },
	'list and update': { files: {}, args: ['--list', '--update'] },
	'no catalogue': {
		files: { 'l10n/en.json': null, [BASELINE]: baseline(18) },
		args: [],
	},
	'catalogue without translations': {
		files: { 'l10n/en.json': '{"plurals": ""}', [BASELINE]: baseline(18) },
		args: [],
	},
	'catalogue that is not JSON': {
		files: { 'l10n/en.json': '{', [BASELINE]: baseline(18) },
		args: ['--list'],
	},
	'no lib/Settings': {
		files: Object.fromEntries(
			Object.keys(FILES)
				.filter((f) => f.startsWith('lib/'))
				.map((f) => [f, null]),
		),
		args: ['--update'],
	},
	'everything covered': {
		files: {
			'lib/Settings/register.d/payment.json': null,
			'lib/Settings/shillinq_register.json': JSON.stringify({
				title: 'Invoice',
				properties: { n: { title: 'Number' } },
			}),
			[BASELINE]: baseline(0),
		},
		args: ['--list'],
	},
}

/**
 * Run the script in a scratch repository for one scenario.
 *
 * @param {object} scenario Files and arguments.
 * @return {object} Exit code, output and the baseline afterwards.
 */
function run(scenario) {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'schema-l10n-'))
	try {
		for (const [file, content] of Object.entries(repoFiles(scenario.files))) {
			fs.mkdirSync(path.dirname(path.join(dir, file)), { recursive: true })
			fs.writeFileSync(path.join(dir, file), content)
		}
		fs.mkdirSync(path.join(dir, 'scripts'), { recursive: true })
		fs.copyFileSync(SCRIPT, path.join(dir, 'scripts/check-schema-l10n.js'))
		const result = spawnSync(
			process.execPath,
			['scripts/check-schema-l10n.js', ...scenario.args],
			{ cwd: dir, encoding: 'utf8', timeout: 20000 },
		)
		const baselineFile = path.join(dir, BASELINE)
		return {
			status: result.status,
			stdout: result.stdout.split('\n'),
			stderr: result.stderr.split('\n'),
			baseline: fs.existsSync(baselineFile)
				? fs.readFileSync(baselineFile, 'utf8')
				: '(none)',
		}
	} finally {
		fs.rmSync(dir, { recursive: true, force: true })
	}
}

const golden = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

describe('check-schema-l10n', () => {
	it.each(Object.keys(SCENARIOS))('%s', (title) => {
		const result = run(SCENARIOS[title])
		if (RECORD) {
			recorded[title] = result
			return
		}
		expect(result).toEqual(golden[title])
	})

	it('has no recorded scenario the spec no longer runs', () => {
		if (!RECORD) {
			expect(Object.keys(golden).sort()).toEqual(Object.keys(SCENARIOS).sort())
		}
	})
})

afterAll(() => {
	if (RECORD) {
		fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
		fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
})
