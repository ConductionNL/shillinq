/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for scripts/generate-manifest-shell.js — the build-time
 * projection that keeps `src/manifest.d.shell.json` in sync with
 * `src/manifest.d/*.json` (shillinq-manifest-boot-payload-reduction
 * REQ-MBP-001).
 *
 * @spec openspec/changes/shillinq-manifest-boot-payload-reduction/specs/manifest-boot-performance/spec.md#req-mbp-001
 */

import { describe, it, expect } from 'vitest'
import fs from 'fs'
import os from 'os'
import path from 'path'
// eslint-disable-next-line n/no-unpublished-require
const {
	generateShellDocument,
	buildShellFragment,
	slimPages,
} = require('../../scripts/generate-manifest-shell.js')

describe('slimPages', () => {
	it('keeps only id/route/type/title and stamps _fragment', () => {
		const pages = [
			{
				id: 'Resources',
				route: '/bookings/resources',
				type: 'index',
				title: 'Resources',
				config: { columns: [{ key: 'name' }] },
			},
		]

		const slim = slimPages(pages, '10-bookings-resource-calendar')

		expect(slim).toEqual([
			{
				_fragment: '10-bookings-resource-calendar',
				id: 'Resources',
				route: '/bookings/resources',
				type: 'index',
				title: 'Resources',
			},
		])
		expect(slim[0].config).toBeUndefined()
	})

	it('returns an empty array for a missing/non-array pages input', () => {
		expect(slimPages(undefined, 'frag')).toEqual([])
		expect(slimPages(null, 'frag')).toEqual([])
	})

	it('omits keys the source page does not declare (no undefined padding)', () => {
		const slim = slimPages([{ id: 'A', route: '/a' }], 'frag')
		expect(Object.keys(slim[0]).sort()).toEqual(['_fragment', 'id', 'route'])
	})
})

describe('buildShellFragment', () => {
	it('copies menu through unchanged and slims pages', () => {
		const fragment = {
			menu: [{ id: 'Bookings', label: 'Bookings', children: [] }],
			pages: [
				{
					id: 'A',
					route: '/a',
					type: 'index',
					title: 'A',
					config: { big: 'payload' },
				},
			],
		}

		const shell = buildShellFragment(fragment, 'frag')

		expect(shell.menu).toEqual(fragment.menu)
		expect(shell.pages).toHaveLength(1)
		expect(shell.pages[0].config).toBeUndefined()
	})

	it('forwards pageTemplates/pageInstances/sets when present (entity-scaffold metadata is not bulky)', () => {
		const fragment = {
			menu: [],
			pages: [],
			pageTemplates: [{ id: 'tpl-1' }],
			pageInstances: [{ templateId: 'tpl-1', id: 'inst-1' }],
			sets: { foo: 'bar' },
		}

		const shell = buildShellFragment(fragment, 'frag')

		expect(shell.pageTemplates).toEqual(fragment.pageTemplates)
		expect(shell.pageInstances).toEqual(fragment.pageInstances)
		expect(shell.sets).toEqual({ foo: 'bar' })
	})

	it('omits pageTemplates/pageInstances/sets when the fragment does not declare them', () => {
		const shell = buildShellFragment({ menu: [], pages: [] }, 'frag')
		expect(shell).not.toHaveProperty('pageTemplates')
		expect(shell).not.toHaveProperty('pageInstances')
		expect(shell).not.toHaveProperty('sets')
	})

	it('defaults menu to an empty array when the fragment has none', () => {
		const shell = buildShellFragment({ pages: [] }, 'frag')
		expect(shell.menu).toEqual([])
	})
})

describe('generateShellDocument', () => {
	it('reads every *.json fragment in sorted order and produces one shell entry each', () => {
		const dir = fs.mkdtempSync(
			path.join(os.tmpdir(), 'shillinq-manifest-shell-'),
		)
		try {
			fs.writeFileSync(
				path.join(dir, 'b-frag.json'),
				JSON.stringify({ menu: [], pages: [{ id: 'B', route: '/b' }] }),
			)
			fs.writeFileSync(
				path.join(dir, 'a-frag.json'),
				JSON.stringify({ menu: [], pages: [{ id: 'A', route: '/a' }] }),
			)
			fs.writeFileSync(path.join(dir, 'notes.txt'), 'ignored — not .json')

			const shell = generateShellDocument(dir)

			expect(shell.fragments).toHaveLength(2)
			// Sorted filename order: a-frag.json before b-frag.json.
			expect(shell.fragments[0].pages[0]._fragment).toBe('a-frag')
			expect(shell.fragments[1].pages[0]._fragment).toBe('b-frag')
		} finally {
			fs.rmSync(dir, { recursive: true, force: true })
		}
	})
})
