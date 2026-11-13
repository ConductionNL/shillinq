/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Shillinq Mobile: what the three mobile pages share. The queue and its
 * transport are opened once per page load; the payload builders turn a
 * page's form into the record the queue sends; issuing a synced draft runs
 * ARInvoice's normal issue transition and is only offered online.
 *
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md
 */

import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	memoryQueueStore,
	nextcloudTransport,
	openQueueStore,
	QUEUE_KINDS,
	QUEUE_STATES,
} from '../../composables/useOfflineQueue.js'
import { buildQuickDraftRequest } from '../../modals/invoiceQuickDraft.js'

/** The receipt categories offered, as ReceiptCapture's examples name them. */
export const RECEIPT_CATEGORIES = Object.freeze(['travel', 'meals', 'supplies', 'accommodation', 'other'])

/** The VAT rates offered on a mobile invoice line, in percent. */
export const VAT_RATES = Object.freeze([21, 9, 0])

let queue = null

/**
 * The queue and transport for this page load. Without IndexedDB the queue
 * lives in memory and `persistent` is false: the app then works online only.
 *
 * @param {object} [deps] Overrides for tests.
 * @param {Function} [deps.open] Opens the IndexedDB queue.
 * @param {Function} [deps.user] The signed-in user.
 * @return {Promise<{store: object, transport: object, persistent: boolean}>} The queue.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
 */
export async function mobileQueue({ open = openQueueStore, user = getCurrentUser } = {}) {
	if (queue !== null) {
		return queue
	}

	let store
	let persistent = true
	try {
		store = await open()
	} catch {
		store = memoryQueueStore()
		persistent = false
	}

	queue = { store, transport: nextcloudTransport({ userId: user()?.uid ?? '' }), persistent }
	return queue
}

/**
 * Forget the queue of this page load (tests).
 *
 * @return {void}
 */
export function resetMobileQueue() {
	queue = null
}

/**
 * Today as YYYY-MM-DD in the phone's own time zone.
 *
 * @param {Date} [now] The moment.
 * @return {string} The date.
 */
export function today(now = new Date()) {
	const pad = (n) => String(n).padStart(2, '0')
	return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

/**
 * The receipt record for a receipt form: the amount in euro, which is also
 * the base currency, and a receipt number the phone can make offline.
 *
 * @param {object} form { amount, receiptDate, category, description }.
 * @param {string} suffix A short random suffix for the number.
 * @return {object} The payload.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-a-receipt-is-captured-with-the-camera-req-pmp-002
 */
export function buildReceiptPayload(form, suffix) {
	const amount = Math.round(Number(form.amount) * 100) / 100
	return {
		receiptNumber: `REC-M-${String(form.receiptDate).replaceAll('-', '')}-${suffix}`,
		amount,
		currency: 'EUR',
		amountInBaseCurrency: amount,
		receiptDate: form.receiptDate,
		category: form.category,
		...(String(form.description || '').trim() ? { description: String(form.description).trim() } : {}),
	}
}

/**
 * Whether a receipt form can be saved: a photo, a non-negative amount, a date and a category.
 *
 * @param {object} form The form.
 * @param {Blob|null} photo The photo.
 * @return {boolean} True when complete.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-a-receipt-is-captured-with-the-camera-req-pmp-002
 */
export function receiptComplete(form, photo) {
	const amount = Number(form.amount)
	return Boolean(photo)
		&& String(form.amount ?? '').trim() !== ''
		&& Number.isFinite(amount)
		&& amount >= 0
		&& /^\d{4}-\d{2}-\d{2}$/.test(String(form.receiptDate))
		&& RECEIPT_CATEGORIES.includes(form.category)
}

/**
 * The quick-draft request for an invoice form, as the desktop quick draft sends it.
 *
 * @param {object} form { customerId, invoiceDate, reference, lines }.
 * @return {object} The payload.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
 */
export function buildInvoicePayload(form) {
	return buildQuickDraftRequest({
		customerId: form.customerId,
		invoiceDate: form.invoiceDate,
		dueDate: '',
		reference: form.reference,
		glAccount: '',
		lines: form.lines,
	})
}

/**
 * Whether an invoice form can be saved: a customer, a date and a priced line.
 *
 * @param {object} form The form.
 * @return {boolean} True when complete.
 */
export function invoiceComplete(form) {
	return Boolean(form.customerId)
		&& /^\d{4}-\d{2}-\d{2}$/.test(String(form.invoiceDate))
		&& buildInvoicePayload(form).lines.some((line) => line.unitPrice > 0)
}

/**
 * Whether a queue item is a synced draft that can be issued now.
 *
 * @param {object} item The queue item.
 * @param {boolean} online Whether the phone is online.
 * @return {boolean} True when Issue is offered.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-an-invoice-is-issued-from-the-phone-only-online-req-pmp-004
 */
export function canIssue(item, online) {
	return online === true
		&& item.kind === QUEUE_KINDS.INVOICE_DRAFT
		&& item.state === QUEUE_STATES.SYNCED
		&& Boolean(item.recordId)
		&& !item.invoiceNumber
}

/**
 * Issue a synced draft through ARInvoice's issue transition and read back
 * the number it was given.
 *
 * @param {string} recordId The ARInvoice id.
 * @param {object} [http] The HTTP client.
 * @return {Promise<{invoiceNumber: string, lifecycleState: string}>} The issued invoice.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-an-invoice-is-issued-from-the-phone-only-online-req-pmp-004
 */
export async function issueInvoice(recordId, http = axios) {
	const id = encodeURIComponent(recordId)
	await http.post(generateUrl(`/apps/openregister/api/objects/${id}/transition`), { action: 'issue', data: {} })
	const response = await http.get(generateUrl(`/apps/openregister/api/objects/shillinq/ARInvoice/${id}`))
	return {
		invoiceNumber: String(response.data?.invoiceNumber ?? ''),
		lifecycleState: String(response.data?.lifecycleState ?? ''),
	}
}

/** Where the customer list is kept for drafting offline. */
export const CUSTOMER_CACHE_KEY = 'shillinq-mobile-customers'

/**
 * The customers to draft an invoice for, as { value, label } options. Online
 * they are read from the register and remembered on the phone; offline the
 * remembered list is used, so a draft can still name its customer.
 *
 * @param {object} [deps] Overrides for tests.
 * @param {object} [deps.http] The HTTP client.
 * @param {Storage} [deps.storage] Where the list is remembered.
 * @param {boolean} [deps.online] Whether the phone is online.
 * @return {Promise<Array<{value: string, label: string}>>} The options.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
 */
export async function loadCustomers({ http = axios, storage = globalThis.localStorage, online = true } = {}) {
	const remembered = () => {
		try {
			const rows = JSON.parse(storage?.getItem(CUSTOMER_CACHE_KEY) ?? '[]')
			return Array.isArray(rows) ? rows : []
		} catch {
			return []
		}
	}

	if (!online) {
		return remembered()
	}

	try {
		const response = await http.get(generateUrl('/apps/openregister/api/objects/shillinq/CustomerMaster'), { params: { _limit: 500 } })
		const rows = response.data?.results ?? []
		const options = (Array.isArray(rows) ? rows : []).map((c) => ({
			value: String(c.customerId ?? c.id ?? ''),
			label: String(c.legalName || c.tradeName || c.customerId || c.id || ''),
		})).filter((option) => option.value !== '')
		try {
			storage?.setItem(CUSTOMER_CACHE_KEY, JSON.stringify(options))
		} catch {
			// A full or blocked storage only costs the offline list.
		}
		return options
	} catch {
		return remembered()
	}
}
