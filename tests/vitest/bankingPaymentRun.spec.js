/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * banking-payment-run: Propose payment run, Block payment and Release
 * payment reach shillinq. The manifest, the handler map in main.js, the
 * registry, the helpers' URLs and appinfo/routes.php are held to each other,
 * and the request the dialog sends is the one PaymentRunController reads.
 *
 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/specs/payment-run-sepa-export/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'src/manifest.d/bookkeeping-accounts-payable-core.json'),
		'utf8',
	),
)
const mainJs = fs.readFileSync(path.join(ROOT, 'src/main.js'), 'utf8')
const registryJs = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')
const controller = fs.readFileSync(
	path.join(ROOT, 'lib/Controller/PaymentRunController.php'),
	'utf8',
)
const checker = fs.readFileSync(
	path.join(ROOT, 'lib/PaymentRun/PaymentBlockChecker.php'),
	'utf8',
)
const proposal = fs.readFileSync(
	path.join(ROOT, 'lib/PaymentRun/PaymentRunProposalService.php'),
	'utf8',
)

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (m, k) => (k in vars ? vars[k] : m)),
}))
const axiosMock = { get: vi.fn(), post: vi.fn(), patch: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

const page = (id) => fragment.pages.find((p) => p.id === id)

describe('Propose payment run', () => {
	beforeEach(() => {
		axiosMock.post.mockReset()
	})

	it('is a header action on Payment runs whose handler main.js provides', () => {
		const action = page('PaymentRuns').config.headerActions.find(
			(a) => a.id === 'propose-payment-run',
		)
		expect(action.label).toBe('Propose payment run')
		expect(action.handler).toBe('openProposePaymentRun')
		expect(mainJs).toContain('openProposePaymentRun,')
	})

	it('posts to a route the controller serves, with the parameters it reads', async () => {
		const { buildProposalRequest, proposePaymentRun } =
			await import('../../src/utils/paymentRunApi.js')
		expect(routes).toContain(
			"'paymentRun#propose', 'url' => '/api/v1/payment-runs/propose', 'verb' => 'POST'",
		)
		const body = buildProposalRequest({
			administrationId: 'adm-1',
			dueOnOrBefore: '2026-10-01',
			debtorAccountIban: 'nl91 abna 0417 1643 00',
			executionDate: '2026-09-30',
			payOnDueDate: true,
		})
		expect(body.debtorAccountIban).toBe('NL91ABNA0417164300')
		for (const key of Object.keys(body)) {
			expect(controller).toContain(`getParam('${key}'`)
		}
		axiosMock.post.mockResolvedValue({
			data: { paymentRun: { id: 'pr-1' }, skipped: [] },
		})
		await proposePaymentRun(body)
		expect(axiosMock.post.mock.calls[0][0]).toBe(
			'/index.php/apps/shillinq/api/v1/payment-runs/propose',
		)
	})

	it('reads a 422 as a result with nothing proposed', async () => {
		const { proposePaymentRun } =
			await import('../../src/utils/paymentRunApi.js')
		axiosMock.post.mockRejectedValue({
			response: {
				status: 422,
				data: { paymentRun: null, skipped: [{ reason: 'no-iban' }] },
			},
		})
		const result = await proposePaymentRun({})
		expect(result.paymentRun).toBeNull()
	})

	it('has a sentence for every reason the server can give', async () => {
		const { skipReasonText } = await import('../../src/utils/paymentRunApi.js')
		const reasons = [
			...(checker + proposal).matchAll(/REASON_\w+ = '([a-z-]+)'/g),
		].map((m) => m[1])
		expect(reasons.length).toBeGreaterThanOrEqual(7)
		for (const reason of reasons) {
			const text = skipReasonText({ reason })
			expect(reason === 'not-found' || text !== 'Not found').toBe(true)
		}
	})
})

describe('Block payment and Release payment', () => {
	beforeEach(() => {
		axiosMock.patch.mockReset()
	})

	it.each(['APTransactionDetail', 'PayeeDetail'])(
		'%s has both actions on a registered modal, and shows the block',
		(id) => {
			const config = page(id).config
			const block = config.headerActions.find((a) => a.id === 'block-payment')
			const release = config.headerActions.find(
				(a) => a.id === 'release-payment',
			)
			expect(block.target).toBe('PaymentBlockModal')
			expect(release.props.mode).toBe('release')
			expect(block.visibleWhen).toEqual({
				field: 'paymentBlocked',
				op: 'neq',
				value: true,
			})
			expect(release.visibleWhen).toEqual({
				field: 'paymentBlocked',
				op: 'eq',
				value: true,
			})
			expect(registryJs).toContain(
				"PaymentBlockModal: { kind: 'modal', component: PaymentBlockModal }",
			)
			expect(config.fields.map((f) => f.key)).toEqual(
				expect.arrayContaining(['paymentBlocked', 'paymentBlockReason']),
			)
		},
	)

	it('refuses a block without a reason and patches both fields through OpenRegister', async () => {
		const { blockPayload, releasePayload, setPaymentBlock } =
			await import('../../src/utils/paymentRunApi.js')
		expect(() => blockPayload('  ')).toThrow(
			'Give a reason for the payment block.',
		)
		expect(blockPayload(' Wacht op creditnota ')).toEqual({
			paymentBlocked: true,
			paymentBlockReason: 'Wacht op creditnota',
		})
		axiosMock.patch.mockResolvedValue({ data: {} })
		await setPaymentBlock('APTransaction', 'ap-1', releasePayload())
		expect(axiosMock.patch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/shillinq/APTransaction/ap-1',
			{ paymentBlocked: false, paymentBlockReason: null },
		)
		await expect(
			setPaymentBlock('ARInvoice', 'x', releasePayload()),
		).rejects.toThrow()
	})
})
