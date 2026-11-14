/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the rules each finance leaf panel is styled with,
 * whether written in the component or in a stylesheet it names with src.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/leaves-library-card/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, it } from 'vitest'
import { golden } from './goldenHarness.js'

const check = golden('leafPanelStyles')
const FOLDER = path.join(__dirname, '../../src/integrations')

/**
 * The CSS rules of a component, split at each closing brace.
 *
 * @param {string} file The component's file name.
 * @return {Array<string>} The rules, whitespace normalised, sorted.
 */
function rulesOf(file) {
	const source = fs.readFileSync(path.join(FOLDER, file), 'utf8')
	const css = []
	for (const [, attributes, body] of source.matchAll(
		/<style([^>]*?)(?:\/>|>([\s\S]*?)<\/style>)/g,
	)) {
		const linked = attributes.match(/src="\.\/([^"]+)"/)
		css.push(
			linked ? fs.readFileSync(path.join(FOLDER, linked[1]), 'utf8') : body,
		)
	}
	return css
		.join('\n')
		.split('}')
		.map((rule) =>
			rule
				.replace(/\/\*[\s\S]*?\*\//g, '')
				.replace(/\s+/g, ' ')
				.trim(),
		)
		.filter(Boolean)
		.sort()
}

describe('the leaf panels keep their rules', () => {
	it.each([
		'ShillinqContractPanel.vue',
		'ShillinqFinancialDocumentsPanel.vue',
		'ShillinqPaymentRequestsPanel.vue',
	])('%s', (file) => {
		check(file, rulesOf(file))
	})
})
