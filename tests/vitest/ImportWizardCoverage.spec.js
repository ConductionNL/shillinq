/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The import wizard rendered at each of its six steps, with recording
 * stand-ins for @nextcloud/vue (tests/vitest/ssrCapture.js): what each step
 * shows, and what each of its buttons, selects and switches does when the
 * handler the template bound is called — which request it sends to the
 * register and where the wizard lands afterwards.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { session } from './ssrCapture.js'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn() }))
const pickMock = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/dialogs', () => ({
	getFilePickerBuilder: () => {
		const builder = {
			setMultiSelect: () => builder,
			allowDirectories: () => builder,
			addButton: () => builder,
			build: () => ({ pick: pickMock }),
		}
		return builder
	},
}))

const ImportWizard = (await import('../../src/views/import/ImportWizard.vue'))
	.default

const STUBBED = [
	'NcButton',
	'NcCheckboxRadioSwitch',
	'NcLoadingIcon',
	'NcNoteCard',
	'NcSelect',
]
const TRANSITION = (id) => `/index.php/apps/openregister/api/objects/${id}/transition`

/**
 * Render the wizard with part of its data replaced.
 *
 * @param {object} state The data to lay over the wizard's own.
 * @return {Promise<{html: string, view: object}>}
 */
async function wizard(state) {
	const view = session()
	const html = await view.render(ImportWizard, state, {}, STUBBED)
	return { html: html.replace(/<!--[[\]]-->/g, ''), view }
}

/**
 * The attributes of the button with this text or data-testid.
 *
 * @param {object} view The render session.
 * @param {string} label The text or data-testid.
 * @return {object}
 */
function button(view, label) {
	return (
		view.attrsOf('NcButton').find((attrs) => attrs['data-testid'] === label)
		|| view.labelled('NcButton', label)
	)
}

/**
 * A wizard form.
 *
 * @param {object} [over] Fields to set.
 * @return {object}
 */
function form(over = {}) {
	return {
		path: '',
		sourceSystem: 'xaf-generic',
		migrationDate: '',
		administrationId: 'adm-1',
		scope: { openingBalance: true, relations: true },
		...over,
	}
}

beforeEach(() => {
	vi.clearAllMocks()
	axiosMock.post.mockResolvedValue({ data: {} })
})

describe('the upload step', () => {
	it('shows the picked file, enables Next and moves on to the profile', async () => {
		const empty = await wizard({ form: form() })
		expect(button(empty.view, 'Next').disabled).toBe(true)
		expect(empty.html).not.toContain('data-testid="import-picked-file"')

		const { html, view } = await wizard({ form: form({ path: '/Migrations/a.xaf' }) })
		expect(html).toMatch(/data-testid="import-picked-file"[^>]*>\s*\/Migrations\/a\.xaf\s*</)
		expect(button(view, 'Next').disabled).toBe(false)
		expect(view.labelled('NcButton', 'Choose file')).toBeTruthy()

		button(view, 'Next').onClick()
		expect(view.vm.step).toBe('profile')
	})

	it('picks through the Choose file button, the first of a multi-pick', async () => {
		const { view } = await wizard({ form: form() })
		pickMock.mockResolvedValueOnce(['/Migrations/b.xaf', '/Migrations/c.xaf'])

		await button(view, 'import-pick-file').onClick()

		expect(pickMock).toHaveBeenCalledOnce()
		expect(view.vm.form.path).toBe('/Migrations/b.xaf')
	})

	it('shows the error card with the message', async () => {
		const { html, view } = await wizard({ error: 'The step could not be completed.' })
		expect(html).toMatch(/data-testid="import-wizard-error"[^>]*>\s*The step could not be completed\.\s*</)
		expect(view.attrsOf('NcNoteCard')[0].type).toBe('error')
	})
})

describe('the profile step', () => {
	it('binds the source package select, the switches and the Back button', async () => {
		const { html, view } = await wizard({ step: 'profile', form: form() })
		expect(html).toContain('data-testid="import-step-profile"')
		expect(html).toContain('aria-current="step"')
		expect(html).toContain('import-wizard__step--done')

		const select = view.attrsOf('NcSelect')[0]
		expect(select.modelValue).toEqual({ id: 'xaf-generic', label: 'XAF auditfile (any package)' })
		expect(select.inputLabel).toBe('Package the auditfile comes from')
		select['onUpdate:modelValue']({ id: 'moneybird', label: 'Moneybird' })
		expect(view.vm.form.sourceSystem).toBe('moneybird')
		select['onUpdate:modelValue'](null)
		expect(view.vm.form.sourceSystem).toBe('')
		expect(view.vm.sourceSystem).toBeNull()

		const switches = view.stubs.filter((entry) => entry.name === 'NcCheckboxRadioSwitch')
		expect(switches.map((entry) => entry.text)).toEqual(['Import the opening balance', 'Import customers'])
		switches[0].attrs['onUpdate:modelValue'](false)
		switches[1].attrs['onUpdate:modelValue'](false)
		expect(view.vm.form.scope).toEqual({ openingBalance: false, relations: false })

		button(view, 'Back').onClick()
		expect(view.vm.step).toBe('upload')
	})

	it('holds Read the auditfile back until the form is complete, then reads it', async () => {
		const incomplete = await wizard({ step: 'profile', form: form({ path: '/a.xaf' }) })
		expect(button(incomplete.view, 'import-start').disabled).toBe(true)

		axiosMock.post.mockImplementation(async (url, body) => ({ data: { ...body, id: 'batch-7' } }))
		axiosMock.get.mockImplementation(async (url) =>
			url.includes('/ImportMapping')
				? { data: { results: [] } }
				: { data: { id: 'batch-7', status: 'mapping' } },
		)
		const { view } = await wizard({
			step: 'profile',
			form: form({ path: '/a.xaf', migrationDate: '2026-01-01' }),
		})
		expect(button(view, 'import-start').disabled).toBe(false)

		await button(view, 'import-start').onClick()

		expect(axiosMock.post.mock.calls.map(([url, body]) => [url, body.action])).toEqual([
			['/index.php/apps/openregister/api/objects/shillinq/ImportBatch', undefined],
			[TRANSITION('batch-7'), 'parse'],
			[TRANSITION('batch-7'), 'startMapping'],
		])
		expect(view.vm.step).toBe('mapping')
		expect(view.vm.busy).toBe(false)
	})
})

describe('the mapping review', () => {
	const rows = [
		{ id: 'm1', sourceCode: '0500', sourceName: 'Kas', targetAccount: '1000', confirmed: true },
		{ id: 'm2', sourceCode: '1300', sourceName: 'Debiteuren', targetAccount: '1300', confirmed: false },
		{ id: 'm3', sourceCode: '9999', sourceName: 'Onbekend', targetAccount: '', confirmed: false },
	]

	it('lists each row with its target and confirmation and asks to map the rest', async () => {
		const { html, view } = await wizard({ step: 'mapping', batch: { id: 'b1', status: 'mapping' }, mappings: rows })

		expect(view.vm.unmapped).toBe(1)
		expect(html).toContain('Accounts to review: 2')
		const cells = [...html.matchAll(/<tr data-testid="import-mapping-row"[^>]*>([\s\S]*?)<\/tr>/g)]
			.map((match) => match[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim())
		expect(cells).toEqual(['0500 Kas 1000 Yes', '1300 Debiteuren 1300 No', '9999 Onbekend Not mapped No'])
		expect(html).toContain('Map the remaining accounts in the Mapping list, then come back to this batch.')
		expect(button(view, 'import-validate').disabled).toBe(true)
		expect(button(view, 'import-confirm-suggestions').disabled).toBe(false)
	})

	it('confirms the suggestions through its button and validates through the other', async () => {
		const { html, view } = await wizard({ step: 'mapping', batch: { id: 'b1', status: 'mapping' }, mappings: rows.slice(0, 2) })
		expect(html).not.toContain('Map the remaining accounts')

		axiosMock.get.mockResolvedValueOnce({ data: { results: rows.slice(0, 2).map((row) => ({ ...row, confirmed: true })) } })
		await button(view, 'import-confirm-suggestions').onClick()
		expect(axiosMock.patch).toHaveBeenCalledOnce()
		expect(axiosMock.patch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/shillinq/ImportMapping/m2',
			{ confirmed: true },
		)
		expect(view.vm.open).toEqual([])

		axiosMock.get.mockResolvedValueOnce({ data: { id: 'b1', status: 'validated' } })
		await button(view, 'import-validate').onClick()
		expect(axiosMock.post).toHaveBeenCalledWith(TRANSITION('b1'), { action: 'validate', data: {} })
		expect(view.vm.step).toBe('validation')
	})

	it('keeps the step and shows the refusal of a failed confirmation', async () => {
		const { view } = await wizard({ step: 'mapping', batch: { id: 'b1', status: 'mapping' }, mappings: rows })
		axiosMock.patch.mockRejectedValueOnce({ response: { data: { error: 'Mapping is locked.' } } })

		await button(view, 'import-confirm-suggestions').onClick()

		expect([view.vm.error, view.vm.busy, view.vm.step]).toEqual(['Mapping is locked.', false, 'mapping'])
	})
})

describe('the validation step', () => {
	it('says a failed validation cannot be posted and holds the dry run back', async () => {
		const { html, view } = await wizard({
			step: 'validation',
			batch: { id: 'b1', status: 'validation_failed', validationReport: { findings: [{ severity: 'error', code: 'x', message: 'Unbalanced.' }] } },
		})
		expect(html).toContain('data-testid="import-validation-failed"')
		expect(html).toContain('Unbalanced.')
		expect(button(view, 'import-dry-run').disabled).toBe(true)
	})

	it('runs the dry run through its button', async () => {
		const { html, view } = await wizard({
			step: 'validation',
			batch: { id: 'b1', status: 'validated', validationReport: { findings: [] } },
		})
		expect(html).not.toContain('data-testid="import-validation-failed"')
		expect(button(view, 'import-dry-run').disabled).toBe(false)

		axiosMock.get.mockResolvedValueOnce({ data: { id: 'b1', status: 'dry_run_complete' } })
		await button(view, 'import-dry-run').onClick()

		expect(axiosMock.post).toHaveBeenCalledWith(TRANSITION('b1'), { action: 'dryRun', data: {} })
		expect(view.vm.step).toBe('dry-run')
	})
})

describe('the dry run step', () => {
	it('shows the opening balance in euros, the open items and relations, and posts', async () => {
		const { html, view } = await wizard({
			step: 'dry-run',
			batch: {
				id: 'b1',
				status: 'dry_run_complete',
				dryRunReport: {
					openingJournal: { lines: [{ debit: 1250.5 }, { credit: 1250.5 }] },
					arOpenItems: [{}, {}],
					apOpenItems: [{}],
					contacts: [{ name: 'Acme' }, { name: 'Beta' }],
				},
			},
		})
		const amount = view.vm.money(1250.5)
		expect(amount).toMatch(/1.?250[.,]50/)
		expect(amount).toContain('€')
		expect(html).toMatch(new RegExp(`data-testid="import-dry-run-debit"[^>]*>\\s*${amount.replace(/[.$]/g, '\\$&')}\\s*<`))
		const values = [...html.matchAll(/<dd[^>]*>\s*([^<]*?)\s*<\/dd>/g)].map((match) => match[1])
		expect(values.slice(2)).toEqual(['3', '2'])
		expect(view.vm.openItems).toBe(3)

		axiosMock.get.mockResolvedValueOnce({ data: { id: 'b1', status: 'posted' } })
		await button(view, 'import-post').onClick()
		expect(axiosMock.post).toHaveBeenCalledWith(TRANSITION('b1'), { action: 'post', data: {} })
		expect(view.vm.step).toBe('post')
	})

	it('counts nothing and formats zero without a dry run report', async () => {
		const { html, view } = await wizard({ step: 'dry-run', batch: { id: 'b1', status: 'dry_run_complete' } })
		expect(view.vm.openItems).toBe(0)
		expect(view.vm.money(undefined)).toBe(view.vm.money(0))
		expect(html).toMatch(/<dd[^>]*>\s*0\s*<\/dd>\s*<dt[^>]*>Relations<\/dt>\s*<dd[^>]*>\s*0\s*<\/dd>/)
	})
})

describe('the post step', () => {
	it('says posted, reversed or not posted by the batch status', async () => {
		const posted = await wizard({ step: 'post', batch: { id: 'b1', status: 'posted' } })
		expect(posted.html).toContain('data-testid="import-posted"')
		expect(posted.html).toContain('The import is posted with its opening entry.')
		expect(posted.view.attrsOf('NcNoteCard')[0].type).toBe('success')

		const reversed = await wizard({ step: 'post', batch: { id: 'b1', status: 'reversed' } })
		expect(reversed.html).toContain('The import is reversed.')
		expect(reversed.view.attrsOf('NcNoteCard')[0].type).toBe('info')

		const failed = await wizard({ step: 'post', batch: { id: 'b1', status: 'posting_failed', postingReport: { findings: [{ severity: 'error', message: 'Period closed.' }] } } })
		expect(failed.html).toContain('data-testid="import-posting-failed"')
		expect(failed.html).toContain('The import was not posted.')
		expect(failed.html).toContain('Period closed.')
	})

	it('starts a new import through its button with an empty form', async () => {
		axiosMock.get.mockRejectedValueOnce(new Error('down'))
		const { view } = await wizard({ step: 'post', batch: { id: 'b1', status: 'posted' }, form: form({ path: '/old.xaf' }) })

		await button(view, 'Start a new import').onClick()

		expect(axiosMock.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/administrations/context')
		expect([view.vm.step, view.vm.batch, view.vm.form.path]).toEqual(['upload', null, ''])
		expect(view.vm.form.administrationId).toBe('')
	})

	it('labels an unknown step by its id', async () => {
		const { view } = await wizard({})
		expect(view.vm.stepLabel('elsewhere')).toBe('elsewhere')
		expect(view.vm.stepLabel('dry-run')).toBe('Dry run')
	})
})
