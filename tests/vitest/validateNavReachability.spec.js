/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for tests/validate-nav-reachability.js (nav-reachability-gate
 * REQ-NAVR-005). A small, synthetic `{ base, fragments, menuLayout }` fixture
 * — NOT this repo's real 595-page manifest — where `menuLayout.removals`
 * retires the only menu entry reaching one page ("Solo"). Proves the check
 * CAN fail (a check never observed to fail is unproven, per this repo's own
 * working norms), and proves the failure is caused by the removal
 * specifically, via a sibling positive-control assertion on the SAME fixture
 * with `removals: []`.
 *
 * Also exercises the cause-attribution replay directly: a removals-caused
 * orphan must be attributed "removals", and a page absent from every
 * fragment's menu[] from the start must be attributed "no menu entry in any
 * base/fragment menu[]", not "removals".
 *
 * @spec openspec/changes/nav-reachability-gate/specs/nav-reachability/spec.md#req-navr-005
 */

import {
	applyMenuRelocations,
	applyMenuRemovals,
	applySettingsSection,
	mergeMenuItems,
} from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { describe, expect, it } from 'vitest'
import {
	attributeCause,
	computeReachable,
	diffBaseline,
	replayStages,
} from '../validate-nav-reachability.js'

const STAGE_FNS = {
	mergeMenuItems,
	applyMenuRelocations,
	applyMenuRemovals,
	applySettingsSection,
}

/**
 * Build the synthetic fixture: page `Solo` is reachable only via menu node
 * `SoloLeaf`, plus an unrelated, always-reachable `Anchor` page so the menu
 * merge has more than a single trivial entry. `Ghost` never has a menu entry
 * anywhere, in any variant — used by the cause-attribution assertions below
 * to prove a pre-existing gap is not misattributed to `removals`.
 *
 * @param {Array<string>} removals The `menuLayout.removals` list to apply.
 * @return {{base: object, fragments: Array<object>, menuLayout: object}}
 */
function buildFixture(removals) {
	const base = {
		menu: [
			{ id: 'AnchorLeaf', label: 'Anchor', route: 'Anchor' },
			{ id: 'SoloLeaf', label: 'Solo leaf', route: 'Solo' },
		],
		pages: [
			{
				id: 'Anchor',
				route: '/anchor',
				type: 'index',
				title: 'Anchor',
				config: {},
			},
			{
				id: 'Solo',
				route: '/solo',
				type: 'detail',
				title: 'Solo',
				config: {},
			},
			{
				id: 'Ghost',
				route: '/ghost',
				type: 'detail',
				title: 'Ghost',
				config: {},
			},
		],
	}
	const fragments = []
	const menuLayout = { relocations: {}, removals, settingsSection: [] }
	return { base, fragments, menuLayout }
}

describe('validate-nav-reachability — negative fixture (REQ-NAVR-005)', () => {
	it('reports Solo as a new orphan when removals retires its only menu entry', () => {
		const { base, fragments, menuLayout } = buildFixture(['SoloLeaf'])
		const { stages } = replayStages(base, fragments, menuLayout, STAGE_FNS)
		const { orphans } = stages.afterSettings
		const { newOrphans } = diffBaseline(orphans, { exceptions: {} })

		expect(newOrphans).toContain('Solo')
	})

	it('positive control: the SAME fixture with removals:[] reports zero new orphans', () => {
		const { base, fragments, menuLayout } = buildFixture([])
		const { stages } = replayStages(base, fragments, menuLayout, STAGE_FNS)
		const { orphans } = stages.afterSettings
		const { newOrphans } = diffBaseline(orphans, { exceptions: {} })

		// Ghost has no menu entry in either variant of this fixture, so it is
		// orphaned regardless of removals — proving the removals-only variant's
		// Solo finding above is caused by the removal, not a fixture defect that
		// would also orphan Solo here.
		expect(newOrphans).not.toContain('Solo')
		expect(newOrphans).toEqual(['Ghost'])
	})

	it('computeReachable finds Solo unreachable directly (removals variant)', () => {
		const { base, menuLayout } = buildFixture(['SoloLeaf'])
		const mergedMenu = []
		mergeMenuItems(mergedMenu, base.menu)
		const laidOutMenu = applySettingsSection(
			applyMenuRemovals(
				applyMenuRelocations(mergedMenu, menuLayout.relocations),
				menuLayout.removals,
			),
			menuLayout.settingsSection,
		)
		const { orphans } = computeReachable({
			pages: base.pages,
			menu: laidOutMenu,
		})

		expect(orphans).toContain('Solo')
	})

	it('computeReachable finds Solo reachable directly (no-removals variant)', () => {
		const { base, menuLayout } = buildFixture([])
		const mergedMenu = []
		mergeMenuItems(mergedMenu, base.menu)
		const laidOutMenu = applySettingsSection(
			applyMenuRemovals(
				applyMenuRelocations(mergedMenu, menuLayout.relocations),
				menuLayout.removals,
			),
			menuLayout.settingsSection,
		)
		const { orphans } = computeReachable({
			pages: base.pages,
			menu: laidOutMenu,
		})

		expect(orphans).not.toContain('Solo')
	})
})

describe('validate-nav-reachability — cause attribution (REQ-NAVR-004)', () => {
	it('attributes a removals-caused orphan to "removals"', () => {
		const { base, fragments, menuLayout } = buildFixture(['SoloLeaf'])
		const { stages } = replayStages(base, fragments, menuLayout, STAGE_FNS)

		expect(attributeCause('Solo', stages)).toBe('removals')
	})

	it('attributes a page absent from every fragment menu[] to the pre-existing-gap cause, not "removals"', () => {
		const { base, fragments, menuLayout } = buildFixture(['SoloLeaf'])
		const { stages } = replayStages(base, fragments, menuLayout, STAGE_FNS)

		expect(attributeCause('Ghost', stages)).toBe(
			'no menu entry in any base/fragment menu[]',
		)
	})

	it('does not attribute Solo to a pre-existing gap when removals is empty (it is not orphaned at all)', () => {
		const { base, fragments, menuLayout } = buildFixture([])
		const { stages } = replayStages(base, fragments, menuLayout, STAGE_FNS)

		expect(stages.afterSettings.reachable.has('Solo')).toBe(true)
	})
})

describe('validate-nav-reachability — baseline diff (REQ-NAVR-003)', () => {
	it('a baselined orphan does not count as a new orphan', () => {
		const orphans = ['LegacyWizardStep']
		const { newOrphans } = diffBaseline(orphans, {
			exceptions: { LegacyWizardStep: 'non-empty reason' },
		})

		expect(newOrphans).toEqual([])
	})

	it('an orphan absent from the baseline is reported as new', () => {
		const orphans = ['NewlyOrphaned']
		const { newOrphans } = diffBaseline(orphans, { exceptions: {} })

		expect(newOrphans).toEqual(['NewlyOrphaned'])
	})

	it('a baseline id no longer orphaned is reported as stale, not new', () => {
		const orphans = []
		const { newOrphans, staleExceptions } = diffBaseline(orphans, {
			exceptions: { NowReachable: 'was orphaned' },
		})

		expect(newOrphans).toEqual([])
		expect(staleExceptions).toEqual(['NowReachable'])
	})
})
