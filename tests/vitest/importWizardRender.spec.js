/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * platform-administration-import: the import wizard as it renders (server
 * side, so no DOM is needed) on each of its six steps, and how it resolves
 * the administration it imports into.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

const contextMock = vi.hoisted(() => vi.fn())
vi.mock('../../src/api/administrationApi.js', () => ({ fetchAdministrationContext: contextMock }))
vi.mock('@nextcloud/dialogs', () => ({ getFilePickerBuilder: vi.fn() }))
vi.mock('@nextcloud/vue', () => {
	const slotted = (name, tag = 'div') => ({
		name,
		props: ['type', 'variant', 'disabled', 'modelValue', 'options', 'inputLabel'],
		render() {
			const attrs = { 'data-stub': name }
			if (this.type) {
				attrs['data-type'] = this.type
			}
			if (this.disabled) {
				attrs.disabled = true
			}
			if (name === 'NcSelect') {
				attrs['data-value'] = this.modelValue?.id || ''
				attrs['data-options'] = (this.options || []).map((option) => option.label).join('|')
			}
			return h(tag, attrs, this.$slots.default?.())
		},
	})
	return {
		NcButton: slotted('NcButton', 'button'),
		NcCheckboxRadioSwitch: slotted('NcCheckboxRadioSwitch', 'label'),
		NcLoadingIcon: slotted('NcLoadingIcon', 'span'),
		NcNoteCard: slotted('NcNoteCard'),
		NcSelect: slotted('NcSelect'),
	}
})

const { default: ImportWizard } = await import('../../src/views/import/ImportWizard.vue')

/**
 * The wizard's own starting data, with `state` laid over it.
 *
 * @param {object} state The data to render with.
 * @return {object} The data.
 */
function stateOf(state) {
	return { ...ImportWizard.data.call({}), ...state }
}

/**
 * Render the wizard with its data replaced by `state`.
 *
 * @param {object} state The data to render with.
 * @return {Promise<string>} The HTML.
 */
async function render(state) {
	const app = createSSRApp({ ...ImportWizard, data: () => stateOf(state) })
	return renderToString(app)
}

describe('the import wizard renders each step', () => {
	it('shows only the loading panel while it opens a batch, and no step list', async () => {
		const html = await render({ opening: true })

		expect(html).toContain('data-testid="import-wizard-opening"')
		expect(html).toContain('Opening the import batch')
		expect(html).not.toContain('import-wizard__steps')
		expect(html).not.toContain('data-testid="import-step-upload"')
	})

	it('marks the current step and the done ones, and shows the error above the panel', async () => {
		const html = await render({ step: 'mapping', error: 'The batch could not be read.' })

		expect(html).toMatch(/class="import-wizard__step--done"[^>]*>Choose file<\/li><li class="import-wizard__step--done"[^>]*>Source package</)
		expect(html).toMatch(/class="import-wizard__step--current" aria-current="step"[^>]*>Mapping review</)
		expect(html).toMatch(/<li class=""[^>]*>Validation</)
		expect(html).toContain('data-testid="import-wizard-error"')
		expect(html).toContain('The batch could not be read.')
	})

	it('holds Next back on the upload step until a file is picked', async () => {
		const empty = await render({ step: 'upload' })
		expect(empty).toContain('data-testid="import-step-upload"')
		expect(empty).not.toContain('data-testid="import-picked-file"')
		expect(empty).toMatch(/<button[^>]*disabled[^>]*>\s*Next/)

		const picked = await render({ step: 'upload', form: { ...stateOf({}).form, path: '/Audit/2025.xaf' } })
		expect(picked).toContain('/Audit/2025.xaf')
		expect(picked).not.toMatch(/<button[^>]*disabled[^>]*>\s*Next/)
	})

	it('offers the source packages with the chosen one selected on the profile step', async () => {
		const html = await render({ step: 'profile', form: { ...stateOf({}).form, sourceSystem: 'snelstart' } })

		expect(html).toContain('data-testid="import-step-profile"')
		expect(html).toContain('data-value="snelstart"')
		expect(html).toContain('XAF auditfile (any package)|SnelStart|Exact Online')
		expect(html).toContain('Import the opening balance')
		// Without a migration date and an administration the read cannot start.
		expect(html).toMatch(/<button[^>]*disabled[^>]*data-testid="import-start"|data-testid="import-start"[^>]*disabled/)
	})

	it('lists the mapping rows and says what is still open', async () => {
		const html = await render({
			step: 'mapping',
			mappings: [
				{ id: 'm1', sourceCode: '0500', sourceName: 'Bank', targetAccount: '1100', confirmed: true },
				{ id: 'm2', sourceCode: '8000', sourceName: 'Revenue', targetAccount: '', confirmed: false },
			],
		})

		expect(html.match(/data-testid="import-mapping-row"/g)).toHaveLength(2)
		expect(html).toContain('0500 Bank')
		expect(html).toContain('Not mapped')
		expect(html).toContain('Accounts to review: 1')
		expect(html).toContain('Map the remaining accounts in the Mapping list, then come back to this batch.')
		expect(html).toMatch(/<button[^>]*disabled[^>]*data-testid="import-validate"|data-testid="import-validate"[^>]*disabled/)
	})

	it('says a failed validation cannot be posted, and lists its findings', async () => {
		const html = await render({
			step: 'validation',
			batch: { status: 'validation_failed', validationReport: { findings: [{ severity: 'error', message: 'Trial balance is off by 0.01' }] } },
		})

		expect(html).toContain('data-testid="import-validation-failed"')
		expect(html).toContain('Trial balance is off by 0.01')
		expect(html).toMatch(/<button[^>]*disabled[^>]*data-testid="import-dry-run"|data-testid="import-dry-run"[^>]*disabled/)
	})

	it('shows the dry run totals in euros and counts the open items and relations', async () => {
		const html = await render({
			step: 'dry-run',
			batch: {
				status: 'dry_run_completed',
				dryRunReport: {
					openingJournal: { lines: [{ debit: 100.1, credit: 0 }, { debit: 0, credit: 100.1 }] },
					arOpenItems: [{}, {}],
					apOpenItems: [{}],
					contacts: [{}, {}, {}, {}],
				},
			},
		})

		const amount = new Intl.NumberFormat(undefined, { style: 'currency', currency: 'EUR' }).format(100.1)
		expect(html).toMatch(new RegExp(`data-testid="import-dry-run-debit"[^>]*>\\s*${amount.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`))
		expect(html).toMatch(new RegExp(`data-testid="import-dry-run-credit"[^>]*>\\s*${amount.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`))
		expect(html).toMatch(/>Open items<\/dt><dd[^>]*>3<\/dd>/)
		expect(html).toMatch(/>Relations<\/dt><dd[^>]*>4<\/dd>/)
	})

	it.each([
		['posted', 'import-posted', 'The import is posted with its opening entry.'],
		['reversed', null, 'The import is reversed.'],
		['posting_failed', 'import-posting-failed', 'The import was not posted.'],
	])('says on the last step that a %s import is what it is', async (status, testid, sentence) => {
		const html = await render({ step: 'post', batch: { status, postingReport: { findings: [] } } })

		expect(html).toContain('data-testid="import-step-post"')
		expect(html).toContain(sentence)
		if (testid !== null) {
			expect(html).toContain(`data-testid="${testid}"`)
		}
		expect(html).toContain('No findings.')
	})
})

describe('the import wizard resolves its administration', () => {
	beforeEach(() => {
		contextMock.mockReset()
	})

	/**
	 * A wizard instance whose methods are bound to its own data.
	 *
	 * @return {object}
	 */
	function instance() {
		const vm = stateOf({})
		for (const [name, method] of Object.entries(ImportWizard.methods)) {
			vm[name] = method.bind(vm)
		}
		return vm
	}

	it('imports into the active administration', async () => {
		contextMock.mockResolvedValue({ activeAdministrationId: 'adm-7' })
		const vm = instance()

		await vm.resolveAdministration()

		expect(vm.form.administrationId).toBe('adm-7')
	})

	it('names no administration when the context cannot be read', async () => {
		contextMock.mockRejectedValue(new Error('offline'))
		const vm = instance()
		vm.form.administrationId = 'stale'

		await vm.resolveAdministration()

		expect(vm.form.administrationId).toBe('')
	})

	it('names no administration when none is active', async () => {
		contextMock.mockResolvedValue({})
		const vm = instance()

		await vm.resolveAdministration()

		expect(vm.form.administrationId).toBe('')
	})
})
