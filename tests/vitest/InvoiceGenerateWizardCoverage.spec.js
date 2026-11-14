/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * InvoiceGenerateWizard rendered with recording stand-ins for the wizard
 * dialog and @nextcloud/vue (tests/vitest/ssrCapture.js): the fields the
 * Source step offers per billing model, the line groups, checkboxes and
 * count of Choose lines, the draft summary of Review, and the fields,
 * selects, checkboxes and close pressed through the handlers the template
 * bound.
 *
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */

import { describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString, ssrRenderSlot } from 'vue/server-renderer'
import InvoiceGenerateWizard from '../../src/modals/InvoiceGenerateWizard.vue'
import { session } from './ssrCapture.js'

vi.mock('@nextcloud/l10n', () => {
	const fill = (text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key]))
	return {
		translate: (app, text, vars) => fill(text, vars),
		translatePlural: (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count)),
	}
})
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnWizardDialog: {} }))
vi.mock('@nextcloud/vue', () => ({ NcCheckboxRadioSwitch: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

/**
 * Render the wizard with part of its data replaced. Every step slot is
 * rendered, both streamed (the HTML) and as vnodes (for their handlers).
 *
 * @param {object} [state] Data to lay over the wizard's own.
 * @param {object} [props] Root props, such as an onClose listener.
 * @return {Promise<{html: string, view: object, vm: object}>}
 */
async function render(state = {}, props = {}) {
	const view = session()
	let vm = null
	const wizard = {
		name: 'CnWizardDialog',
		inheritAttrs: false,
		ssrRender(ctx, push, parent) {
			view.stubs.push({ name: 'CnWizardDialog', attrs: ctx.$attrs, text: '' })
			push('<div data-stub="CnWizardDialog">')
			for (const slot of Object.keys(ctx.$slots)) {
				view.slots.push(ctx.$slots[slot]())
				push(`<x-slot data-slot="${slot}">`)
				ssrRenderSlot(ctx.$slots, slot, {}, null, push, parent)
				push('</x-slot>')
			}
			push('</div>')
		},
	}
	const app = createSSRApp(
		{
			...InvoiceGenerateWizard,
			components: {
				CnWizardDialog: wizard,
				NcSelect: view.stub('NcSelect'),
				NcTextField: view.stub('NcTextField'),
				NcCheckboxRadioSwitch: view.stub('NcCheckboxRadioSwitch'),
			},
			mixins: [{ created() { vm = this; Object.assign(this.$data, state) } }],
		},
		props,
	)
	app.config.warnHandler = () => {}
	const html = (await renderToString(app)).replace(/<!--[[\]]-->/g, '').replace(/ data-v-[0-9a-f]+/g, '')
	return { html, view, vm }
}

/**
 * The HTML of one step slot.
 *
 * @param {string} html The rendered wizard.
 * @param {string} step The step id.
 * @return {string}
 */
function step(html, step) {
	return html.match(new RegExp(`<x-slot data-slot="step-${step}">([\\s\\S]*?)</x-slot>`))[1]
}

const testids = (view, name) => view.attrsOf(name).map((attrs) => attrs['data-testid'])
const source = (over) => ({ billingModel: 't_and_m', projectId: '', fromDate: '2026-01-01', toDate: '2026-01-31', fixedFee: '', milestoneId: '', ...over })
const money = (value) => Number(value).toLocaleString(undefined, { style: 'currency', currency: 'EUR' })

const CANDIDATES = {
	timeEntries: [{ id: 't1', description: 'Advies', personId: 'p1', date: '2026-01-05', hours: 2 }, { id: 't2', hours: 0 }],
	expenses: [],
	readings: [{ id: 'r1', meterId: 'M-1', periodStart: '2026-01-01', periodEnd: '2026-01-31', quantity: 120, unit: 'kWh' }],
}

describe('InvoiceGenerateWizard source step', () => {
	it('hands the wizard its labels, steps and validate, and offers the customers and models', async () => {
		const { view } = await render({ customers: [{ id: 'c1', legalName: 'Acme BV' }, { id: 'c2', customerId: 'K-2' }] })
		const [attrs] = view.attrsOf('CnWizardDialog')

		expect(attrs).toMatchObject({ dialogTitle: 'Generate invoices', eyebrowContext: 'Sales invoices', submitLabel: 'Post to sales invoices', nextLabel: 'Next', look: 'board' })
		expect(attrs.steps.map((s) => s.id)).toEqual(['source', 'lines', 'review'])
		expect(typeof attrs.validate).toBe('function')
		const [customer, model] = view.attrsOf('NcSelect')
		expect(customer).toMatchObject({ inputLabel: 'Customer', loading: false, modelValue: null })
		expect(customer.options).toEqual([{ value: 'c1', label: 'Acme BV' }, { value: 'c2', label: 'K-2' }])
		expect(model.modelValue).toEqual({ value: 't_and_m', label: 'Time & Materials' })
		expect(model.options.map((o) => o.label)).toEqual(['Time & Materials', 'Fixed fee', 'Milestone', 'Retainer', 'Mixed', 'Usage'])
	})

	it('asks for what each billing model needs', async () => {
		const shown = {}
		for (const billingModel of ['t_and_m', 'fixed_fee', 'milestone', 'retainer', 'mixed', 'usage']) {
			const { view } = await render({ source: source({ billingModel }) })
			shown[billingModel] = [...testids(view, 'NcSelect'), ...testids(view, 'NcTextField')]
				.filter((id) => !['igw-customer', 'igw-model', 'igw-project', 'igw-from', 'igw-to'].includes(id))
		}
		expect(shown).toEqual({
			t_and_m: ['igw-rate-card'],
			fixed_fee: ['igw-fixed-fee'],
			milestone: ['igw-milestone'],
			retainer: ['igw-retainer'],
			mixed: ['igw-rate-card', 'igw-retainer', 'igw-fixed-fee'],
			usage: [],
		})
	})

	it('labels the rate cards and retainers it offers', async () => {
		const { view } = await render({
			source: source({ billingModel: 'mixed' }),
			rateCards: [{ id: 'rc1', level: 'Senior', hourlyRate: 120 }, { id: 'rc2', level: 'Junior' }],
			retainers: [{ id: 'rs1', label: 'Support' }, { id: 'rs2', scheduleId: 'RS-2' }],
		})
		const select = (id) => view.attrsOf('NcSelect').find((a) => a['data-testid'] === id)

		expect(select('igw-rate-card').options).toEqual([{ value: 'rc1', label: `Senior, ${money(120)}` }, { value: 'rc2', label: 'Junior' }])
		expect(select('igw-retainer').options).toEqual([{ value: 'rs1', label: 'Support' }, { value: 'rs2', label: 'RS-2' }])
	})

	it('writes the fields and selects back to the wizard', async () => {
		const { view, vm } = await render({ source: source({ billingModel: 'mixed' }), customers: [{ id: 'c1', legalName: 'Acme' }] })
		const field = (id) => view.attrsOf('NcTextField').find((a) => a['data-testid'] === id)
		const select = (id) => view.attrsOf('NcSelect').find((a) => a['data-testid'] === id)

		select('igw-customer')['onUpdate:modelValue']({ value: 'c1', label: 'Acme' })
		select('igw-rate-card')['onUpdate:modelValue']({ value: 'rc1' })
		select('igw-retainer')['onUpdate:modelValue']({ value: 'rs1' })
		field('igw-project')['onUpdate:modelValue']('P-7')
		field('igw-from')['onUpdate:modelValue']('2026-02-01')
		field('igw-to')['onUpdate:modelValue']('2026-02-28')
		field('igw-fixed-fee')['onUpdate:modelValue']('1500')
		select('igw-model')['onUpdate:modelValue']({ value: 'milestone' })

		expect([vm.customer, vm.rateCard, vm.retainer]).toEqual([{ value: 'c1', label: 'Acme' }, { value: 'rc1' }, { value: 'rs1' }])
		expect(vm.source).toMatchObject({ projectId: 'P-7', fromDate: '2026-02-01', toDate: '2026-02-28', fixedFee: '1500', billingModel: 'milestone' })

		const milestone = await render({ source: source({ billingModel: 'milestone' }) })
		milestone.view.attrsOf('NcTextField').find((a) => a['data-testid'] === 'igw-milestone')['onUpdate:modelValue']('MS-3')
		expect(milestone.vm.source.milestoneId).toBe('MS-3')
	})

	it('writes back through the vnodes the slot builds too', async () => {
		const { view, vm } = await render({ source: source({ billingModel: 'mixed' }) })
		const byId = (id) => view.vnodes((vnode) => vnode.props?.['data-testid'] === id)[0].props['onUpdate:modelValue']

		byId('igw-customer')({ value: 'c9' })
		byId('igw-project')('P-1')
		byId('igw-from')('2026-03-01')
		byId('igw-to')('2026-03-31')
		byId('igw-rate-card')({ value: 'rc9' })
		byId('igw-retainer')({ value: 'rs9' })
		byId('igw-fixed-fee')('99')
		byId('igw-model')({ value: 'usage' })

		expect([vm.customer, vm.rateCard, vm.retainer]).toEqual([{ value: 'c9' }, { value: 'rc9' }, { value: 'rs9' }])
		expect(vm.source).toMatchObject({ projectId: 'P-1', fromDate: '2026-03-01', toDate: '2026-03-31', fixedFee: '99', billingModel: 'usage' })

		const milestone = await render({ source: source({ billingModel: 'milestone' }) })
		milestone.view.vnodes((vnode) => vnode.props?.['data-testid'] === 'igw-milestone')[0].props['onUpdate:modelValue']('MS-9')
		expect(milestone.vm.source.milestoneId).toBe('MS-9')
	})

	it('shows the error on the source and lines steps', async () => {
		const { html } = await render({ error: 'The period ends before it starts.' })
		for (const id of ['source', 'lines']) {
			expect(step(html, id)).toMatch(/role="alert">\s*The period ends before it starts\.\s*</)
		}
		const clean = await render()
		expect(clean.html).not.toContain('role="alert"')
	})

	it('closes through the wizard close', async () => {
		const onClose = vi.fn()
		const { view } = await render({}, { onClose })
		view.attrsOf('CnWizardDialog')[0].onClose()
		expect(onClose).toHaveBeenCalledTimes(1)
	})
})

describe('InvoiceGenerateWizard lines step', () => {
	it('shows the source chips, the groups with their rows and the chosen count', async () => {
		const { html, view } = await render({
			customer: { value: 'c1', label: 'Acme BV' },
			source: source({ projectId: 'P-7' }),
			candidates: CANDIDATES,
			chosen: { timeEntryIds: ['t1'], expenseIds: [], meterReadingIds: [] },
		})
		const lines = step(html, 'lines')

		expect(lines).toMatch(/<span class="igw__chip">Acme BV<\/span><span class="igw__chip">Project P-7<\/span><span class="igw__chip">Period 2026-01-01 to 2026-01-31<\/span>/)
		expect(lines).toMatch(/<h3 class="igw__group-title">\s*Hours\s*<\/h3>/)
		expect(lines).toMatch(/<span class="igw__title">Advies<\/span><span class="igw__meta">p1 · 2026-01-05<\/span>/)
		expect(lines).toMatch(/<h3 class="igw__group-title">\s*Costs passed on\s*<\/h3><p class="igw__muted">\s*No costs passed on to this customer in this period\.\s*<\/p>/)
		expect(lines).not.toContain('Meter readings')
		expect(lines).toMatch(/1 line chosen, becomes one draft invoice/)
		expect(view.attrsOf('NcCheckboxRadioSwitch').map((a) => [a['aria-label'], a.modelValue])).toEqual([['Advies', true], ['', false]])
	})

	it('adds the readings group for a usage customer, without a project chip', async () => {
		const { html } = await render({
			customer: null,
			source: source({ billingModel: 'usage' }),
			candidates: CANDIDATES,
			chosen: { timeEntryIds: ['t1', 't2'], expenseIds: [], meterReadingIds: ['r1'] },
		})
		const lines = step(html, 'lines')

		expect(lines).toMatch(/<span class="igw__chip"><\/span>(<!---->)?<span class="igw__chip">Period/)
		expect(lines).not.toContain('Project ')
		expect(lines).toMatch(/Meter readings[\s\S]*<span class="igw__title">M-1<\/span><span class="igw__meta">2026-01-01 to 2026-01-31<\/span>[\s\S]*120 kWh/)
		expect(lines).toMatch(/3 lines chosen, become one draft invoice/)
	})

	it('says it is loading instead of the groups', async () => {
		const { html } = await render({ linesLoading: true, candidates: CANDIDATES })
		const lines = step(html, 'lines')
		expect(lines).toMatch(/Loading the lines\s…/)
		expect(lines).not.toContain('igw__group')
	})

	it('toggles a line through its checkbox, streamed and as a vnode', async () => {
		const { view, vm } = await render({ candidates: CANDIDATES, chosen: { timeEntryIds: ['t1', 't2'], expenseIds: [], meterReadingIds: [] } })

		view.attrsOf('NcCheckboxRadioSwitch')[0]['onUpdate:modelValue'](false)
		expect(vm.chosen.timeEntryIds).toEqual(['t2'])
		const boxes = view.vnodes((vnode) => vnode.type?.name === 'NcCheckboxRadioSwitch')
		boxes[0].props['onUpdate:modelValue'](true)
		expect(vm.chosen.timeEntryIds).toEqual(['t2', 't1'])
	})
})

describe('InvoiceGenerateWizard review step', () => {
	it('summarises the draft', async () => {
		const { html } = await render({
			draft: { invoiceNumber: 'INV-9', dueDate: '2026-02-28', summary: { netAmount: 100, vatAmount: 21, grossAmount: 121 } },
		})
		const review = step(html, 'review')

		expect(review).toContain('<dd>INV-9</dd>')
		expect(review).toContain(`<dd>${money(100)}</dd>`)
		expect(review).toContain(`<dd>${money(21)}</dd>`)
		expect(review).toMatch(new RegExp(`<dd class="igw__strong">\\s*${money(121).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*</dd>`))
		expect(review).toContain('<dd>2026-02-28</dd>')
		expect(review).toContain('The draft is saved.')
	})

	it('numbers on posting and dashes missing amounts', async () => {
		const { html } = await render({ draft: { dueDate: '' } })
		const review = step(html, 'review')
		expect(review).toContain('<dd>On posting</dd>')
		expect(review.match(/—/g)).toHaveLength(3)
	})

	it('shows no summary before there is a draft', async () => {
		const { html } = await render()
		expect(step(html, 'review')).not.toContain('<dl')
	})
})
