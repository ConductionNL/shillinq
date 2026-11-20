/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The BBV mapping detail form through its own template: Delete only on an
 * existing mapping, the record loaded when the page opens on one, the
 * picker hints, the allocation feedback and the save error shown only when
 * there is something to show, and every form control writing its field.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { findVnodes, renderSsr } from './helpers/ssrStandIns.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDetailPage: {} }))
vi.mock('../../src/modals/DeleteBudgetMappingDialog.vue', () => ({ default: {} }))
vi.mock('../../src/components/BudgetBBVMapping/GlAccountPicker.vue', () => ({ default: {} }))
vi.mock('../../src/components/BudgetBBVMapping/BBVProgrammePicker.vue', () => ({ default: {} }))

const { default: BudgetBBVMappingDetail } = await import('../../src/components/BudgetBBVMapping/BudgetBBVMappingDetail.vue')

const STAND_INS = ['CnDetailPage', 'GlAccountPicker', 'BBVProgrammePicker', 'DeleteBudgetMappingDialog']

/**
 * Render the detail page.
 *
 * @param {object} options props, data and the slot mode.
 * @return {Promise<object>} The markup, the instance and the recorded stand-ins.
 */
function render({ props = {}, data = {}, slotMode = 'ssr' } = {}) {
	return renderSsr(BudgetBBVMappingDetail, { props, data, standIns: STAND_INS, slotMode })
}

beforeEach(() => {
	vi.clearAllMocks()
	// An existing record stays loading: the template under test is the form, not the fetch.
	axios.get.mockReturnValue(new Promise(() => {}))
})

describe('BudgetBBVMappingDetail actions', () => {
	it.each(['ssr', 'vnode'])('offers Delete only on an existing mapping (%s slot)', async (slotMode) => {
		const created = await render({ slotMode })
		const existing = await render({ props: { id: 'm-1' }, slotMode })

		expect(created.html).not.toContain('bbv-mapping-detail-delete')
		expect(existing.html).toContain('data-testid="bbv-mapping-detail-delete"')
	})

	it('loads the record when the page opens on an existing mapping, and not for a new one', async () => {
		await render()
		expect(axios.get).not.toHaveBeenCalled()

		const { vm } = await render({ props: { id: 'm-1' } })

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/BudgetBBVMapping/m-1')
		expect(vm.loading).toBe(true)
	})
})

describe('BudgetBBVMappingDetail feedback', () => {
	it.each(['ssr', 'vnode'])('shows the picker hints, the allocation feedback and the save error only when set (%s slot)', async (slotMode) => {
		const empty = await render({ slotMode })
		const full = await render({
			slotMode,
			data: {
				selectedAccount: { accountName: 'Salarissen' },
				selectedProgramme: { programmeCode: 'P-01', programmeName: 'Veiligheid' },
				allocationFeedback: { message: 'Over 100 %', severity: 'error' },
				saveError: 'Could not save',
			},
		})

		for (const testid of ['gl-hint', 'programme-hint', 'alloc-feedback', 'save-error']) {
			expect(empty.html).not.toContain(`bbv-mapping-detail-${testid}`)
			expect(full.html).toContain(`data-testid="bbv-mapping-detail-${testid}"`)
		}
		expect(full.html).toContain('Salarissen')
		expect(full.html).toContain('P-01 · Veiligheid')
		expect(full.html).toContain('bbv-mapping-detail__alloc--error')
		expect(full.html).toContain('Could not save')
	})
})

describe('BudgetBBVMappingDetail controls', () => {
	it('write the allocation, the dates and the status into the form', async () => {
		const { vm, seen } = await render({ slotMode: 'vnode' })
		const control = (testid) => findVnodes(seen['CnDetailPage:vnodes'], (v) => v.props?.['data-testid'] === `bbv-mapping-detail-${testid}`)[0]

		control('allocation').props['onUpdate:modelValue'](40)
		control('effective-from').props['onUpdate:modelValue']('2027-01-01')
		control('effective-to').props['onUpdate:modelValue']('2027-12-31')
		control('status').props['onUpdate:modelValue']('archived')

		expect(vm.form).toMatchObject({
			allocationPercentage: 40,
			effectiveFrom: '2027-01-01',
			effectiveTo: '2027-12-31',
			status: 'archived',
		})
	})
})
