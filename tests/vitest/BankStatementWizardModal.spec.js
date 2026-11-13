/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The bank statement wizard dialog: what each of its three steps renders
 * (server-side, no DOM), and what its steps, import, review and close do —
 * which request the import sends, when the IBAN memory skips the mapping
 * step, and which widgets are refreshed on the way out.
 *
 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
 */

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => text.replace(/\{(\w+)\}/g, (match, key) => (vars && key in vars ? vars[key] : match)),
}))
vi.mock('@nextcloud/vue', () => {
	const slotted = (name, tag = 'div') => ({
		name,
		inheritAttrs: false,
		props: ['name', 'disabled', 'inputLabel'],
		render() {
			return h(tag, { 'data-stub': name, 'data-name': this.name, 'data-testid': this.$attrs['data-testid'], disabled: this.disabled || undefined }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	})
	return { NcDialog: slotted('NcDialog'), NcButton: slotted('NcButton', 'button'), NcSelect: slotted('NcSelect') }
})
vi.mock('../../src/components/BudgetBBVMapping/GlAccountPicker.vue', () => ({
	default: { name: 'GlAccountPicker', render: () => h('div', { 'data-stub': 'GlAccountPicker' }) },
}))
vi.mock('../../src/modals/bankStatementWizard.js', async (importOriginal) => ({
	...(await importOriginal()),
	loadIbanMapping: vi.fn(),
	saveIbanMapping: vi.fn(),
	setReturnBreadcrumb: vi.fn(),
}))

const { default: BankStatementWizard } = await import('../../src/modals/BankStatementWizard.vue')
const { loadIbanMapping, saveIbanMapping, setReturnBreadcrumb } = await import('../../src/modals/bankStatementWizard.js')

const IMPORT_URL = '/index.php/apps/shillinq/api/v1/bank-statements/import'

/**
 * Render the wizard, open, with its data replaced.
 *
 * @param {object} state The data to lay over the wizard's own.
 * @return {Promise<string>} The HTML, without fragment comments.
 */
async function render(state = {}) {
	const app = createSSRApp({ ...BankStatementWizard, data: () => ({ ...BankStatementWizard.data(), ...state }) }, { open: true })
	app.config.warnHandler = () => {}
	return (await renderToString(app)).replace(/<!--[[\]]-->/g, '')
}

/**
 * A plain instance of the wizard: data, bound methods, readable computed values, a recorded $emit.
 *
 * @param {object} over Data to set first.
 * @return {object} The instance.
 */
function wizard(over = {}) {
	const self = { $emit: vi.fn(), $router: { push: vi.fn() } }
	Object.assign(self, BankStatementWizard.data.call(self), over)
	for (const [name, method] of Object.entries(BankStatementWizard.methods)) {
		self[name] = typeof method === 'function' ? method.bind(self) : method
	}
	for (const [name, getter] of Object.entries(BankStatementWizard.computed)) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	return self
}

/**
 * A FileReader stand-in that reads the text it was given.
 */
class FakeReader {
	readAsText(file) {
		this.result = file.text
		this.onload()
	}
}

beforeEach(() => {
	axios.post.mockReset()
	showError.mockReset()
	emit.mockReset()
	loadIbanMapping.mockReset()
	saveIbanMapping.mockReset()
	setReturnBreadcrumb.mockReset()
	vi.stubGlobal('FileReader', FakeReader)
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('BankStatementWizard renders', () => {
	it('nothing while closed', async () => {
		const html = await renderToString(createSSRApp(BankStatementWizard, { open: false }))
		expect(html).not.toContain('bank-statement-wizard')
	})

	it('step 1 without a format: no hint, no file field, Next disabled', async () => {
		const html = await render()
		expect(html).toContain('data-testid="bsw-step-1"')
		expect(html).toContain('data-name="Import bank statement"')
		expect(html).not.toContain('bsw-format-hint')
		expect(html).not.toContain('bsw-file')
		expect(html).toMatch(/data-testid="bsw-next-1" disabled/)
		expect(html).toContain('Connect via PSD2')
	})

	it('step 1 with a format: its instructions and the file field accepting its extensions', async () => {
		const html = await render({ form: { format: 'mt940', contents: ':20:', fileName: 'a.sta', glAccountId: '' } })
		expect(html).toContain('Older SWIFT format')
		expect(html).toMatch(/id="bsw-file" type="file" accept="[^"]*\.sta/)
		expect(html).not.toMatch(/data-testid="bsw-next-1" disabled/)
	})

	it('step 2 with the statement IBAN, or Unknown without one', async () => {
		expect(await render({ step: 2, statementIban: 'NL91ABNA0417164300', statementName: 'Lopende rekening' })).toMatch(/data-testid="bsw-iban"[^>]*>\s*NL91ABNA0417164300/)
		const unknown = await render({ step: 2 })
		expect(unknown).toMatch(/data-testid="bsw-iban"[^>]*>\s*Unknown/)
		expect(unknown).toContain('data-stub="GlAccountPicker"')
		expect(unknown).toMatch(/data-testid="bsw-next-2" disabled/)
	})

	it('step 3 while importing, with the summary, and with an error', async () => {
		expect(await render({ step: 3, importing: true })).toMatch(/Importing \u2026 transactions/)
		const done = await render({ step: 3, result: { transactionCount: 24, matchedCount: 22, unmatchedCount: 2 } })
		expect(done).toContain('Importing 24 transactions')
		expect(done).toMatch(/data-testid="bsw-matched"[^>]*>\s*22 automatically matched/)
		expect(done).toMatch(/data-testid="bsw-unmatched"[^>]*>\s*2 unmatched/)
		expect(done).toContain('data-testid="bsw-review"')
		const failed = await render({ step: 3, error: 'Bad file' })
		expect(failed).toMatch(/data-testid="bsw-error"[^>]*>\s*Bad file/)
		expect(failed).not.toContain('bsw-review')
	})
})

describe('BankStatementWizard step 1', () => {
	it('offers the three formats and resolves the selected one', () => {
		const vm = wizard()
		expect(vm.formatSelectOptions.map((o) => o.value)).toEqual(['camt053', 'mt940', 'csv'])
		expect(vm.selectedFormatOption).toBeNull()
		vm.onFormatSelected({ value: 'csv' })
		expect(vm.form.format).toBe('csv')
		expect(vm.selectedFormatOption.display).toBe('CSV')
		expect(vm.formatInstructions).toContain('header row')
		vm.onFormatSelected(null)
		expect(vm.form.format).toBe('')
		expect(vm.formatInstructions).toBe('')
		expect(vm.acceptFor('nope')).toBe('')
	})

	it('reads the chosen file and takes the first IBAN in it', () => {
		const vm = wizard()
		vm.onFileChosen({ target: { files: [{ name: 'st.xml', text: '<Acct><IBAN>NL91ABNA0417164300</IBAN></Acct>' }] } })
		expect(vm.form.fileName).toBe('st.xml')
		expect(vm.form.contents).toContain('NL91ABNA0417164300')
		expect(vm.statementIban).toBe('NL91ABNA0417164300')
		vm.form.contents = 'no account here'
		vm.extractStatementMeta()
		expect(vm.statementIban).toBe('')
	})

	it('clears the file when none was chosen', () => {
		const vm = wizard({ form: { format: 'csv', contents: 'x', fileName: 'x.csv', glAccountId: '' } })
		vm.onFileChosen({ target: { files: [] } })
		expect(vm.form.contents).toBe('')
		expect(vm.form.fileName).toBe('')
	})

	it('does not leave step 1 without a format and a file', () => {
		const vm = wizard({ form: { format: 'csv', contents: '', fileName: '', glAccountId: '' } })
		vm.advanceFromStep1()
		expect(vm.step).toBe(1)
		expect(loadIbanMapping).not.toHaveBeenCalled()
	})

	it('goes to the mapping step for an IBAN it does not remember', () => {
		loadIbanMapping.mockReturnValue(null)
		const vm = wizard({ statementIban: 'NL91ABNA0417164300', form: { format: 'csv', contents: 'x', fileName: 'x.csv', glAccountId: '' } })
		vm.advanceFromStep1()
		expect(loadIbanMapping).toHaveBeenCalledWith('NL91ABNA0417164300')
		expect(vm.step).toBe(2)
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('skips the mapping step and imports for a remembered IBAN', async () => {
		loadIbanMapping.mockReturnValue('gl-1100')
		axios.post.mockResolvedValue({ data: { statementId: 's-1', transactionCount: 3 } })
		const vm = wizard({ statementIban: 'NL91ABNA0417164300', form: { format: 'camt053', contents: '<x/>', fileName: 'a.xml', glAccountId: '' } })
		vm.advanceFromStep1()
		await Promise.resolve()
		expect(vm.step).toBe(3)
		expect(axios.post).toHaveBeenCalledWith(IMPORT_URL, { format: 'camt053', contents: '<x/>', glAccountId: 'gl-1100', encoding: 'utf8' })
	})

	it('sends the PSD2 link to the admin settings and closes', () => {
		const vm = wizard()
		vi.stubGlobal('window', { location: { href: '' } })
		vm.goToBankConnections()
		expect(window.location.href).toBe('/index.php/settings/admin/shillinq')
		expect(vm.$emit).toHaveBeenCalledWith('close')
	})
})

describe('BankStatementWizard import', () => {
	it('does not leave step 2 without an account', () => {
		const vm = wizard({ step: 2 })
		vm.advanceFromStep2()
		expect(vm.step).toBe(2)
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('imports, remembers the IBAN mapping and reports the result', async () => {
		const vm = wizard({ step: 2, statementIban: 'NL91ABNA0417164300', error: 'old', form: { format: 'csv', contents: 'a,b', fileName: 'x.csv', glAccountId: 'gl-1100' } })
		axios.post.mockImplementationOnce(async () => {
			expect(vm.importing).toBe(true)
			return { data: { statementId: 's-1', transactionCount: 24 } }
		})
		vm.advanceFromStep2()
		await vi.waitFor(() => expect(vm.importing).toBe(false))
		expect(vm.step).toBe(3)
		expect(vm.error).toBe('')
		expect(vm.result).toEqual({ statementId: 's-1', transactionCount: 24 })
		expect(saveIbanMapping).toHaveBeenCalledWith('NL91ABNA0417164300', 'gl-1100')
		expect(vm.$emit).toHaveBeenCalledWith('imported', { statementId: 's-1', transactionCount: 24 })
	})

	it('without a statement IBAN remembers nothing, and an empty answer is an empty result', async () => {
		axios.post.mockResolvedValue({})
		const vm = wizard({ form: { format: 'csv', contents: 'a', fileName: 'x.csv', glAccountId: 'gl-1' } })
		await vm.runImport()
		expect(vm.result).toEqual({})
		expect(saveIbanMapping).not.toHaveBeenCalled()
	})

	it('shows the server error, else the error message, else a fallback', async () => {
		for (const [failure, message] of [
			[{ response: { data: { error: 'Unknown format' } } }, 'Unknown format'],
			[new Error('Network down'), 'Network down'],
			[{}, 'Failed to import the bank statement.'],
		]) {
			axios.post.mockRejectedValueOnce(failure)
			const vm = wizard({ form: { format: 'csv', contents: 'a', fileName: 'x.csv', glAccountId: 'gl-1' } })
			await vm.runImport()
			expect(vm.error).toBe(message)
			expect(showError).toHaveBeenLastCalledWith(message)
			expect(vm.importing).toBe(false)
			expect(vm.$emit).not.toHaveBeenCalled()
		}
	})
})

describe('BankStatementWizard leaving', () => {
	it('review sets the breadcrumb, refreshes both widgets, closes and opens reconciliation', () => {
		const vm = wizard({ result: { statementId: 's-9' } })
		vm.reviewMatches()
		expect(setReturnBreadcrumb).toHaveBeenCalledWith('s-9')
		expect(emit.mock.calls).toEqual([
			['cn:widget:refresh', { widget: 'widget-open-debtors' }],
			['cn:widget:refresh', { widget: 'widget-open-creditors' }],
		])
		expect(vm.$emit).toHaveBeenCalledWith('close')
		expect(vm.$router.push).toHaveBeenCalledWith({ name: 'BankReconciliation' })
	})

	it('review does nothing without a result', () => {
		const vm = wizard()
		vm.reviewMatches()
		expect(setReturnBreadcrumb).not.toHaveBeenCalled()
		expect(vm.$router.push).not.toHaveBeenCalled()
	})

	it('cannot be closed while importing', () => {
		const vm = wizard({ importing: true })
		vm.onClose()
		expect(vm.$emit).not.toHaveBeenCalled()
	})

	it('closing after an import still refreshes the widgets; before one it only closes', () => {
		const imported = wizard({ result: { statementId: 's-1' } })
		imported.onClose()
		expect(emit).toHaveBeenCalledTimes(2)
		expect(imported.$emit).toHaveBeenCalledWith('close')

		emit.mockReset()
		const fresh = wizard()
		fresh.onClose()
		expect(emit).not.toHaveBeenCalled()
		expect(fresh.$emit).toHaveBeenCalledWith('close')
	})

	it('reopening resets every step and field', () => {
		const vm = wizard({ step: 3, importing: true, error: 'x', result: {}, statementIban: 'NL', statementName: 'n', form: { format: 'csv', contents: 'c', fileName: 'f', glAccountId: 'g' } })
		BankStatementWizard.watch.open.call(vm, true)
		expect([vm.step, vm.importing, vm.error, vm.result, vm.statementIban, vm.statementName]).toEqual([1, false, '', null, '', ''])
		expect(vm.form).toEqual({ format: '', contents: '', fileName: '', glAccountId: '' })
		vm.step = 2
		BankStatementWizard.watch.open.call(vm, false)
		expect(vm.step).toBe(2)
	})
})
