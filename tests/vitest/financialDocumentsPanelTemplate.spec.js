/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The financial documents leaf panel rendered from its real template: the
 * loading and error states, the top acts the caller holds, the issue and
 * payout forms, each document row with its title, state, due line, link and
 * the row acts its kind, state and grant allow, the settle form and the
 * notices. A carded or summarised placement leaves the parts the host draws.
 */

import { describe, expect, it, vi } from 'vitest'
import { renderTemplate, standIn } from './helpers/renderTemplate.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

globalThis.t = (_app, value, vars = {}) => value.replace(/{(\w+)}/g, (m, key) => (key in vars ? String(vars[key]) : m))

const { default: Panel } = await import('../../src/integrations/ShillinqFinancialDocumentsPanel.vue')

const components = { NcButton: standIn('NcButton'), NcLoadingIcon: standIn('NcLoadingIcon') }

const ALL_GRANTS = { issueInvoice: true, requestPayment: true, administerPayments: true, createQuote: true, creditInvoice: true, raisePayout: true }

/**
 * The text of the HTML, tags removed and whitespace collapsed.
 *
 * @param {string} html The HTML.
 * @return {string} The text.
 */
const text = (html) => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()

/**
 * Euros as the panel writes them.
 *
 * @param {number} amount The amount.
 * @return {string} The text.
 */
const eur = (amount) => text(new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(amount))

/**
 * The labels of the buttons in a piece of HTML.
 *
 * @param {string} html The HTML.
 * @return {Array<string>} The labels.
 */
const buttons = (html) => [...html.matchAll(/<cn-stub data-name="NcButton"[^>]*>([\s\S]*?)<\/cn-stub>/g)].map((m) => text(m[1]))

/**
 * The HTML of one document row.
 *
 * @param {string} html The panel HTML.
 * @param {string} id The row id.
 * @return {string} The row HTML.
 */
function row (html, id) {
	const start = html.indexOf(`data-testid="shillinq-financial-document-${id}"`)
	return '<li ' + html.slice(start, html.indexOf('</li>', start))
}

/**
 * Render the panel for a state.
 *
 * @param {object} data Preset data.
 * @param {object} props Props.
 * @return {Promise<string>} The HTML.
 */
const render = (data, props = {}) => renderTemplate(Panel, { props, data, components })

const ROWS = [
	{ id: 'i1', kind: 'invoice', number: 'F-2026-001', amount: 125, paymentState: 'outstanding', dueOn: '2026-12-01', path: '/apps/shillinq/invoices/i1' },
	{ id: 'p1', kind: 'payment-request', amount: 40, currency: 'EUR', paymentState: 'outstanding' },
	{ id: 'c1', kind: 'credit-note', number: 'CN-1', amount: null, paymentState: 'waived' },
	{ id: 'q1', kind: 'quote', number: 'Q-9', amount: 10, restricted: true, dueOn: '2026-12-31' },
	{ id: 'x1', kind: 'payout', amount: 'n/a', paymentState: 'unknown' },
]

describe('ShillinqFinancialDocumentsPanel template', () => {
	it('shows a loading icon, then the read failure', async () => {
		expect(await render({ loading: true })).toContain('data-name="NcLoadingIcon"')
		expect(text(await render({ error: 'The financial documents could not be read.' }))).toBe('The financial documents could not be read.')
	})

	it('offers the top acts the caller holds and the placement allows, and says when nothing is linked', async () => {
		const html = await render({ acts: { ...ALL_GRANTS, createQuote: false } }, { actions: ['issue-invoice', 'new-quote', 'request-payment'] })

		expect(buttons(html)).toEqual(['Issue invoice'])
		expect(text(html)).toContain('No quotes, invoices or payments are linked to this record.')
		expect(buttons(await render({ acts: ALL_GRANTS }))).toEqual(['Issue invoice', 'New quote', 'Raise payout'])
		expect(buttons(await render({}))).toEqual([])
	})

	it('lists each document with its title, state, due line, link and the acts it allows', async () => {
		const html = await render({ rows: ROWS, acts: ALL_GRANTS, busyId: 'p1', notices: { i1: 'The payment request is raised.' } })

		const invoice = row(html, 'i1')
		expect(text(invoice)).toMatch(new RegExp(`^Invoice F-2026-001 ${eur(125).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')} Outstanding Due 2026-12-01`))
		expect(invoice).toContain('href="/index.php/apps/shillinq/invoices/i1"')
		expect(buttons(invoice)).toEqual(['Open in Shillinq', 'Request payment', 'Credit invoice'])
		expect(text(invoice)).toContain('The payment request is raised.')

		const request = row(html, 'p1')
		expect(buttons(request)).toEqual(['Send payment link', 'Mark paid by other means'])
		expect(request.match(/data-disabled="true"/g)).toHaveLength(2)

		expect(text(row(html, 'c1'))).toBe('Credit note CN-1 Waived')
		expect(text(row(html, 'q1'))).toBe(`Quote Q-9 ${eur(10)} You cannot open this document; the finance administration holds it.`)
		expect(text(row(html, 'x1'))).toBe('Payout')
	})

	it('keeps rows of kinds the placement leaves out off the list', async () => {
		const html = await render({ rows: ROWS, acts: ALL_GRANTS }, { kinds: ['quote'] })
		expect(html).toContain('shillinq-financial-document-q1')
		expect(html).not.toContain('shillinq-financial-document-i1')
	})

	it('shows the invoice form with debtor fields, the payout form with an IBAN, and the form notice', async () => {
		const invoice = await render({ acts: ALL_GRANTS, formFor: 'issue-invoice', notices: { form: 'The invoice is issued.' } })
		expect(text(invoice)).toContain('Amount What it is for Who pays Their email address Issue the invoice Cancel')
		expect(invoice).not.toContain('shillinq-form-iban')
		expect(text(invoice)).toContain('The invoice is issued.')

		const payout = await render({ acts: ALL_GRANTS, formFor: 'raise-payout', busyId: 'form' })
		expect(text(payout)).toContain('Amount What it is for Who is paid IBAN Raise the payout Cancel')
		expect(payout).not.toContain('shillinq-form-email')
		const confirm = [...payout.matchAll(/<cn-stub data-name="NcButton"([^>]*)>([\s\S]*?)<\/cn-stub>/g)].find((m) => text(m[2]) === 'Raise the payout')
		expect(confirm[1]).toContain('data-disabled="true"')
		expect(text(await render({ formFor: 'new-quote' }))).toContain('Start the quote')
		expect(Panel.methods.confirmLabelFor.call({ confirmIssueLabel: 'x' }, 'other')).toBe('')
	})

	it('shows the settle form under its row with every way money can arrive', async () => {
		const html = await render({ rows: ROWS, acts: ALL_GRANTS, settleFor: 'p1' })
		const request = row(html, 'p1')

		expect(text(request)).toContain('How the money arrived Pin at the counter Cash at the counter Bank transfer Waived Another way Reference Reason, which a waiver needs Record the payment Cancel')
		expect(row(html, 'i1')).not.toContain('How the money arrived')
	})

	it('leaves the top acts and the empty line to a carded host, and renders nothing for a summary', async () => {
		const carded = await render({ acts: ALL_GRANTS }, { onHeader: () => {} })
		expect(buttons(carded)).toEqual([])
		expect(carded).not.toContain('shillinq-financial-documents-empty')
		expect(text(await render({ rows: ROWS }, { onSummary: () => {} }))).toBe('')
	})
})
