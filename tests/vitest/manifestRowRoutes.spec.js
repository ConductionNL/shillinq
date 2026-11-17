/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * An index page's rows open the detail page it names. CnPageRenderer reads
 * `config.rowRoute`; without it a row opens the FIRST detail page bound to the
 * same register and schema, in manifest order (manifest.json, then the
 * fragments alphabetically). `detailRoute` alone is not read for that.
 *
 * @spec exclude manifest integrity test, not tied to one requirement
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const FRAGMENTS = path.join(ROOT, 'src/manifest.d')

const pages = [
	...JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages,
	...fs.readdirSync(FRAGMENTS)
		.filter((name) => name.endsWith('.json'))
		.sort()
		.flatMap((name) => JSON.parse(fs.readFileSync(path.join(FRAGMENTS, name), 'utf8')).pages ?? []),
]

const firstDetailFor = new Map()
for (const page of pages) {
	if (page?.type !== 'detail') {
		continue
	}
	const key = `${page.config?.register} ${page.config?.schema}`
	if (!firstDetailFor.has(key)) {
		firstDetailFor.set(key, page.id)
	}
}

describe('index page row targets', () => {
	it('opens the declared detail page, not another page on the same schema', () => {
		const misrouted = []
		let checked = 0
		for (const page of pages) {
			const config = page?.config ?? {}
			if (page?.type !== 'index' || typeof config.detailRoute !== 'string') {
				continue
			}
			checked++
			const opens = config.rowRoute ?? firstDetailFor.get(`${config.register} ${config.schema}`)
			if (opens !== config.detailRoute) {
				misrouted.push(`${page.id} names ${config.detailRoute} but opens ${opens}`)
			}
		}

		expect(checked).toBeGreaterThan(0)
		expect(misrouted).toEqual([])
	})
})
