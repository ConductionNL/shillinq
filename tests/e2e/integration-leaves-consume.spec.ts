/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the platform leaves on shillinq's detail pages
 * (integration-leaves-consume task 5, REQ-CPA-005 to REQ-CPA-008).
 *
 * The live pass of the change, one test per leaf, each driven through the
 * OpenRegister leaf routes the nextcloud-vue widgets call and then read back
 * on the rendered page:
 *
 *   1. files, receipts: a Receipt with an uploaded image (`photoUri` set to the
 *      stored file) shows that file on ReceiptDetail (the ReceiptCapture
 *      component's Photo field) and the URL answers with the image; an
 *      ExpenseClaimEntry whose `receiptIds` name two such receipts shows both
 *      images on ExpenseClaimDetail ("Receipt evidence");
 *   2. files, bank and VAT: a BankConnection with two BankStatements whose
 *      statement files are stored on the connection shows both files on
 *      BankConnectionDetail ("Imported statements"); a submitted VatReturn
 *      with its `attachmentUri` stored shows the attachment on
 *      BtwAangifteDetail ("Filed return attachment");
 *   3. calendar: a draft Q2 BTW return and an issued ARInvoice with a future
 *      `dueDate` are published by POST /api/deadline-calendar/settings (filing
 *      and ar-due categories on; ComplianceDeadlineCalendarService is the one
 *      writer, REQ-CDC-002/-004). Each VEVENT (`btw-filing:{id}`,
 *      `ar-invoice:{id}`) exists once in the user's calendars, is listed by the
 *      calendar leaf of the VatReturn on BtwAangifteDetail and of the ARInvoice
 *      on ARInvoiceDetail, and is still there exactly once after the pages
 *      rendered;
 *   4. contacts: a CustomerMaster whose email matches an address-book card is
 *      linked to that card through the contacts leaf; a Payee whose email
 *      matches nothing gets a contact created and linked; both links survive a
 *      reload of CustomerDetail and PayeeDetail, and the Payee object itself is
 *      unchanged;
 *   5. talk: an approval conversation started on an ExpenseClaimEntry through
 *      the talk leaf is the one conversation the claim lists, before and after
 *      ExpenseClaimDetail is reopened.
 *
 * Every leg skips with the reason when the Nextcloud app behind its leaf
 * (files, calendar, contacts, spreed) is not enabled on the instance.
 *
 * ComplianceDeadlineCalendarService publishes the BTW deadline from the
 * `VatReturn` BtwAangifteDetail shows and writes the X-OPENREGISTER-*
 * properties of the return and of the ARInvoice on each VEVENT, so both
 * calendar leaves list their event without a link made by hand. The receipt
 * and statement files are stored
 * through the files leaf's upload call (`…/files`), as the widget does.
 *
 * Everything runs in an administration of its own (unique id, with a
 * membership for the suite's administrator). Every object, file link, contact
 * card, conversation and VEVENT setting written here is removed or restored
 * afterwards.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/archive/2026-11-25-integration-leaves-consume/specs/consume-platform-abstractions/spec.md
 * @spec openspec/changes/archive/2026-11-25-integration-leaves-consume/tasks.md
 *
 * @e2e consume-platform-abstractions::a-receipts-image-is-reachable-from-its-detail-page
 * @e2e consume-platform-abstractions::an-expense-claim-shows-all-of-its-receipt-evidence
 * @e2e consume-platform-abstractions::a-bank-connections-statements-are-browsable-as-files
 * @e2e consume-platform-abstractions::a-filed-vat-return-exposes-its-submitted-attachment
 * @e2e consume-platform-abstractions::a-vat-returns-filing-deadline-is-visible-on-its-detail-page
 * @e2e consume-platform-abstractions::an-ar-invoices-due-date-surfaces-as-a-calendar-entry
 * @e2e consume-platform-abstractions::a-debtor-is-linked-to-an-address-book-contact
 * @e2e consume-platform-abstractions::a-creditor-with-no-matching-contact-can-create-one
 * @e2e consume-platform-abstractions::an-expense-claim-approval-discussion-happens-on-the-claim
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const APP = '/index.php/apps/shillinq'
const OR_API = '/index.php/apps/openregister/api'
const OBJECTS = `${OR_API}/objects/shillinq`
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMINISTRATION_ID = `${UNIQUE_PREFIX}-leaves`
const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
const ADDRESS_BOOK = `/remote.php/dav/addressbooks/users/${ADMIN_USER}/contacts`

/** A row of an object's files as the files leaf answers it. */
type FileRow = Record<string, unknown>

/** One VEVENT found in the user's calendars. */
interface FoundEvent {
	calendarUri: string
	href: string
}

test.describe('integration-leaves-consume: files, calendar, contacts and talk on the detail pages', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let token = ''
	let enabled: Set<string> | null = null
	let administrationReady = false
	/** Deadline-calendar settings as they were, restored in afterAll. */
	let deadlineSettings: Record<string, unknown> | null = null
	/** Leaf links to undo in afterAll: the DELETE URL of each. */
	const unlinks: string[] = []
	/** Address-book cards written here. */
	const cards: string[] = []
	/** Talk conversations created here. */
	const rooms: string[] = []

	/**
	 * The CSRF token shillinq's own write routes need beside the session cookie.
	 *
	 * @return The request token of an authenticated app page.
	 */
	async function requestToken(): Promise<string> {
		if (token === '') {
			const html = await (await api.get(`${APP}/`)).text()
			token = html.match(/data-requesttoken="([^"]+)"/)?.[1] ?? ''
		}
		return token
	}

	/**
	 * Headers of a JSON write as the administrator.
	 *
	 * @return The headers.
	 */
	async function writeHeaders(): Promise<Record<string, string>> {
		return { requesttoken: await requestToken(), 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
	}

	/**
	 * Whether a Nextcloud app is enabled: the provisioning list first, a probe
	 * of the app's own page when that list cannot be read.
	 *
	 * @param app The app id.
	 * @return True when the app is enabled.
	 */
	async function appEnabled(app: string): Promise<boolean> {
		if (enabled === null) {
			const res = await api.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json', { headers: { 'OCS-APIRequest': 'true' } })
			if (res.ok()) {
				const body = await res.json().catch(() => ({}))
				enabled = new Set<string>((body?.ocs?.data?.apps ?? []) as string[])
			} else {
				enabled = new Set<string>()
			}
		}
		if (enabled.size > 0) {
			return enabled.has(app)
		}
		const probe = await api.get(`/index.php/apps/${app}/`, { maxRedirects: 0 })
		return probe.status() === 200
	}

	/**
	 * Skip the running test when a Nextcloud app it needs is not enabled.
	 *
	 * @param apps The app ids.
	 */
	async function needApps(apps: string[]): Promise<void> {
		for (const app of apps) {
			test.skip(!(await appEnabled(app)), `the Nextcloud app "${app}" is not enabled on this instance`)
		}
	}

	/**
	 * The administration of the test and the administrator's membership of it.
	 */
	async function administration(): Promise<void> {
		if (administrationReady) {
			return
		}
		await fx.create('Administration', {
			administrationCode: ADMINISTRATION_ID,
			name: `Leaves ${UNIQUE_PREFIX}`,
			legalForm: 'bv',
			status: 'actief',
			fiscalYearStartMonth: 1,
			fiscalYearStartDay: 1,
		})
		await fx.create('AdministrationMembership', {
			userId: ADMIN_USER,
			administrationId: ADMINISTRATION_ID,
			role: 'controller',
			mayPostJournalEntries: true,
			validFrom: '2024-01-01',
			validUntil: null,
		})
		administrationReady = true
	}

	/**
	 * Change some fields of a stored object, keeping the rest.
	 *
	 * @param schema  The schema slug.
	 * @param id      The object id.
	 * @param changes The fields to set.
	 */
	async function patch(schema: string, id: string, changes: Record<string, unknown>): Promise<void> {
		const stored = { ...(await fx.get(schema, id)) }
		delete stored['@self']
		await fx.update(schema, id, { ...stored, ...changes })
	}

	/**
	 * Store a file on an object through the files leaf, as the widget's upload does.
	 *
	 * @param schema   The schema slug.
	 * @param id       The object id.
	 * @param name     The file name.
	 * @param mime     The media type.
	 * @param base64   The content.
	 * @return The file as the leaf lists it.
	 */
	async function attach(schema: string, id: string, name: string, mime: string, base64: string): Promise<FileRow> {
		const res = await api.post(`${OBJECTS}/${schema}/${id}/files`, {
			headers: await writeHeaders(),
			data: { name, content: `data:${mime};base64,${base64}` },
		})
		expect(res.ok(), `upload ${name} to ${schema}: HTTP ${res.status()} ${await res.text()}`).toBeTruthy()
		const row = (await files(schema, id)).find((file) => fileName(file) === name)
		expect(row, `${name} is listed on the ${schema}`).toBeTruthy()
		return row as FileRow
	}

	/**
	 * The files the files leaf lists for an object.
	 *
	 * @param schema The schema slug.
	 * @param id     The object id.
	 * @return The rows.
	 */
	async function files(schema: string, id: string): Promise<FileRow[]> {
		const res = await api.get(`${OBJECTS}/${schema}/${id}/files?limit=50&_page=1`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(res.ok(), `files of ${schema}/${id}: HTTP ${res.status()}`).toBeTruthy()
		const body = await res.json()
		return (body.results ?? body.items ?? (Array.isArray(body) ? body : [])) as FileRow[]
	}

	/**
	 * A file row's name.
	 *
	 * @param file The row.
	 * @return Its name.
	 */
	function fileName(file: FileRow): string {
		return String(file.name ?? file.title ?? file.basename ?? '')
	}

	/**
	 * The URL a file row opens at.
	 *
	 * @param file The row.
	 * @return The URL, '' when the row names none.
	 */
	function fileUrl(file: FileRow): string {
		return String(file.downloadUrl ?? file.accessUrl ?? file.url ?? '')
	}

	/**
	 * Open a URL the way the page does and check it serves the stored file.
	 *
	 * @param url  The file URL.
	 * @param mime The media type expected.
	 */
	async function expectServed(url: string, mime: RegExp): Promise<void> {
		expect(url, 'the file row names a URL to open it at').not.toBe('')
		const res = await api.get(url)
		expect(res.status(), `GET ${url}`).toBe(200)
		expect(res.headers()['content-type'] ?? '').toMatch(mime)
		expect((await res.body()).length).toBeGreaterThan(0)
	}

	/**
	 * Open a sidebar tab when the page shows it (the tab may also be open already).
	 *
	 * @param page The page.
	 * @param name The tab's name.
	 */
	async function openTab(page: Page, name: RegExp): Promise<void> {
		const tab = page.getByRole('tab', { name }).first()
		if (await tab.isVisible({ timeout: 5_000 }).catch(() => false)) {
			await tab.click()
		}
	}

	/**
	 * The user's calendars, as CalDAV hrefs.
	 *
	 * @return The calendar collection hrefs.
	 */
	async function calendars(): Promise<string[]> {
		// A cookie session reaches DAV only with its request token; without it DAV answers 401.
		const res = await api.fetch(`/remote.php/dav/calendars/${ADMIN_USER}/`, {
			method: 'PROPFIND',
			headers: { Depth: '1', 'Content-Type': 'application/xml; charset=utf-8', requesttoken: await requestToken() },
			data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>',
		})
		expect(res.status(), 'PROPFIND of the calendar home').toBe(207)
		const xml = await res.text()
		const hrefs: string[] = []
		for (const [, block] of xml.matchAll(/<(?:\w+:)?response>([\s\S]*?)<\/(?:\w+:)?response>/g)) {
			const href = block.match(/<(?:\w+:)?href>([^<]+)</)?.[1] ?? ''
			if (/<(?:\w+:)?calendar\s*\/>/.test(block) && href !== '') {
				hrefs.push(href)
			}
		}
		return hrefs
	}

	/**
	 * The live (not cancelled) VEVENTs with a UID in any of the user's calendars.
	 *
	 * @param uid The VEVENT UID.
	 * @return Where each one is stored.
	 */
	async function events(uid: string): Promise<FoundEvent[]> {
		const found: FoundEvent[] = []
		for (const href of await calendars()) {
			const res = await api.fetch(href, {
				method: 'REPORT',
				headers: { Depth: '1', 'Content-Type': 'application/xml; charset=utf-8', requesttoken: await requestToken() },
				data: '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
					+ '<d:prop><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
					+ `<c:prop-filter name="UID"><c:text-match collation="i;octet" match-type="equals">${uid}</c:text-match></c:prop-filter>`
					+ '</c:comp-filter></c:comp-filter></c:filter></c:calendar-query>',
			})
			if (res.status() !== 207) {
				continue
			}
			const xml = await res.text()
			for (const [, block] of xml.matchAll(/<(?:\w+:)?response>([\s\S]*?)<\/(?:\w+:)?response>/g)) {
				const eventHref = block.match(/<(?:\w+:)?href>([^<]+)</)?.[1] ?? ''
				if (block.includes(`UID:${uid}`) && !block.includes('STATUS:CANCELLED')) {
					found.push({ calendarUri: href.replace(/\/$/, '').split('/').pop() ?? '', href: eventHref })
				}
			}
		}
		return found
	}

	/**
	 * The events the calendar leaf lists for an object.
	 *
	 * @param schema The schema slug.
	 * @param id     The object id.
	 * @return The rows.
	 */
	async function linkedEvents(schema: string, id: string): Promise<Array<Record<string, unknown>>> {
		const res = await api.get(`${OBJECTS}/${schema}/${id}/integrations/calendar`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(res.ok(), `calendar leaf of ${schema}/${id}: HTTP ${res.status()}`).toBeTruthy()
		const body = await res.json()
		return (body.results ?? body.items ?? (Array.isArray(body) ? body : [])) as Array<Record<string, unknown>>
	}

	/**
	 * The calendar leaf's rows for one VEVENT UID. The publisher links the
	 * event to its object itself, so nothing is linked here.
	 *
	 * @param schema The schema slug.
	 * @param id     The object id.
	 * @param uid    The VEVENT UID.
	 * @return The leaf's rows for that UID.
	 */
	async function leafEvents(schema: string, id: string, uid: string): Promise<Array<Record<string, unknown>>> {
		return (await linkedEvents(schema, id)).filter((row) => String(row.uid ?? row.eventUid ?? '') === uid)
	}

	/**
	 * The deadline UID ComplianceDeadlineCalendarService gives an object.
	 *
	 * @param source The deadline source (btw-filing, ar-invoice).
	 * @param schema The schema slug.
	 * @param id     The object id.
	 * @return `{source}:{slug or id}` (REQ-CDC-001).
	 */
	async function deadlineUid(source: string, schema: string, id: string): Promise<string> {
		const self = ((await fx.get(schema, id))['@self'] ?? {}) as Record<string, unknown>
		return `${source}:${String(self.slug ?? self.id ?? id)}`
	}

	/**
	 * The contacts the contacts leaf lists for an object.
	 *
	 * @param schema The schema slug.
	 * @param id     The object id.
	 * @return The rows.
	 */
	async function contacts(schema: string, id: string): Promise<Array<Record<string, unknown>>> {
		const res = await api.get(`${OBJECTS}/${schema}/${id}/contacts`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(res.ok(), `contacts of ${schema}/${id}: HTTP ${res.status()}`).toBeTruthy()
		const body = await res.json()
		return (body.results ?? body.items ?? (Array.isArray(body) ? body : [])) as Array<Record<string, unknown>>
	}

	/**
	 * The address-book cards OpenRegister's contact search finds for a term.
	 *
	 * @param term The search term.
	 * @return The rows.
	 */
	async function searchContacts(term: string): Promise<Array<Record<string, unknown>>> {
		const res = await api.get(`${OR_API}/contacts/search?q=${encodeURIComponent(term)}`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(res.ok(), `contact search: HTTP ${res.status()}`).toBeTruthy()
		const body = await res.json()
		return (body.results ?? body.items ?? (Array.isArray(body) ? body : [])) as Array<Record<string, unknown>>
	}

	/**
	 * The conversations the talk leaf lists for an object.
	 *
	 * @param schema The schema slug.
	 * @param id     The object id.
	 * @return The conversation tokens.
	 */
	async function conversations(schema: string, id: string): Promise<string[]> {
		const res = await api.get(`${OBJECTS}/${schema}/${id}/talk`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(res.ok(), `talk of ${schema}/${id}: HTTP ${res.status()}`).toBeTruthy()
		const body = await res.json()
		const rows = (body.results ?? body.items ?? (Array.isArray(body) ? body : [])) as Array<Record<string, unknown>>
		return rows.map((row) => String(row.roomToken ?? row.token ?? row.id ?? ''))
	}

	/**
	 * A receipt of the test with its image stored and named in `photoUri`.
	 *
	 * @param suffix Makes the receipt unique within the run.
	 * @return The receipt id, the image's name and its URL.
	 */
	async function receipt(suffix: string): Promise<{ id: string, name: string, url: string }> {
		const { id } = await fx.create('Receipt', {
			receiptNumber: `${UNIQUE_PREFIX}-R${suffix}`,
			photoUri: 'pending',
			amount: 12.5,
			currency: 'EUR',
			amountInBaseCurrency: 12.5,
			receiptDate: '2026-11-02',
			category: 'travel',
			vendorName: `Vendor ${suffix}`,
			administrationId: ADMINISTRATION_ID,
		})
		const name = `${UNIQUE_PREFIX}-receipt-${suffix}.png`
		const url = fileUrl(await attach('Receipt', id, name, 'image/png', PNG))
		await patch('Receipt', id, { photoUri: url })
		return { id, name, url }
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
	})

	test.afterAll(async () => {
		const headers = api ? await writeHeaders() : {}
		for (const url of unlinks) {
			await api?.delete(url, { headers }).catch(() => undefined)
		}
		for (const room of rooms) {
			await api?.delete(`/ocs/v2.php/apps/spreed/api/v4/room/${encodeURIComponent(room)}`, { headers }).catch(() => undefined)
		}
		for (const card of cards) {
			await api?.delete(card).catch(() => undefined)
		}
		await fx?.cleanup()
		if (deadlineSettings !== null) {
			// Restoring the settings re-publishes, which cancels the VEVENTs of the
			// objects removed above.
			await api?.post(`${APP}/api/deadline-calendar/settings`, { headers, data: { categories: deadlineSettings } }).catch(() => undefined)
		}
		await api?.dispose()
	})

	test('a receipt image is listed on ReceiptDetail and both receipts\' images on ExpenseClaimDetail @spec REQ-CPA-005', async ({ page }) => {
		await needApps(['openregister', 'files'])
		const missing = await fx.missingSchema(['Administration', 'AdministrationMembership', 'Receipt', 'ExpenseClaimEntry'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		await administration()

		const first = await receipt('1')
		const second = await receipt('2')

		// ReceiptDetail is the ReceiptCapture component: its Photo field names the
		// stored image, and the image opens.
		await page.goto(`${APP}/inkoop/receipts/${first.id}`)
		await expect(page.getByTestId('rc-photo-uri')).toHaveValue(first.url, { timeout: 20_000 })
		await expectServed(first.url, /^image\/png/)

		// The claim names both receipts; its page shows both images.
		const { id: claimId } = await fx.create('ExpenseClaimEntry', {
			claimNumber: `${UNIQUE_PREFIX}-EC`,
			employeeId: ADMIN_USER,
			fromDate: '2026-11-01',
			toDate: '2026-11-30',
			totalAmount: 25,
			currency: 'EUR',
			approvalState: 'pending',
			state: 'submitted',
			administrationId: ADMINISTRATION_ID,
			receiptIds: [first.id, second.id],
		})
		await page.goto(`${APP}/inkoop/expense-claims/${claimId}`)
		await expect(page.getByText(first.name).first()).toBeVisible({ timeout: 20_000 })
		await expect(page.getByText(second.name).first()).toBeVisible()
		await expectServed(second.url, /^image\/png/)
	})

	test('imported statements list on BankConnectionDetail and the filed VAT attachment on BtwAangifteDetail @spec REQ-CPA-005', async ({ page }) => {
		await needApps(['openregister', 'files'])
		const missing = await fx.missingSchema(['Administration', 'AdministrationMembership', 'BankConnection', 'BankStatement', 'VatReturn'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		await administration()

		// The connection and its two statements, each statement file stored on the connection.
		const { id: connectionId } = await fx.create('BankConnection', {
			connectionNumber: `${UNIQUE_PREFIX}-BC`,
			aggregator: 'manual',
			aggregatorSourceSlug: 'manual',
			bankBic: 'ABNANL2A',
			bankCountry: 'NL',
			bankAccountIban: 'NL91ABNA0417164300',
			consentReference: `${UNIQUE_PREFIX}-consent`,
			consentGrantedAt: '2026-11-01T00:00:00+00:00',
			consentExpiresAt: '2027-02-01T00:00:00+00:00',
			lifecycleState: 'active',
			administrationId: ADMINISTRATION_ID,
		})
		const statementNames: string[] = []
		for (const day of ['2026-11-01', '2026-11-02']) {
			const name = `${UNIQUE_PREFIX}-camt053-${day}.xml`
			const xml = Buffer.from(`<?xml version="1.0"?><Document><BkToCstmrStmt><Stmt><Id>${UNIQUE_PREFIX}-${day}</Id></Stmt></BkToCstmrStmt></Document>`).toString('base64')
			const file = await attach('BankConnection', connectionId, name, 'application/xml', xml)
			await fx.create('BankStatement', {
				bankConnectionId: connectionId,
				statementFormat: 'camt.053.001.08',
				statementDate: `${day}T00:00:00+00:00`,
				statementAttachmentUri: fileUrl(file),
				transactionCount: 0,
				administrationId: ADMINISTRATION_ID,
			})
			statementNames.push(name)
		}
		await page.goto(`${APP}/bank-connections/${connectionId}`)
		for (const name of statementNames) {
			await expect(page.getByText(name).first()).toBeVisible({ timeout: 20_000 })
		}

		// A submitted return with its filed attachment.
		const { id: returnId } = await fx.create('VatReturn', {
			administrationId: ADMINISTRATION_ID,
			periodType: 'quarter',
			periodYear: 2026,
			periodQuarter: 3,
			periodStart: '2026-07-01',
			periodEnd: '2026-09-30',
			rubrieken: { '1a': { revenue: 0, vat: 0 } },
			dueSalesTax: 0,
			inputVat: 0,
			payableOrRefund: 0,
			state: 'submitted',
			submittedAt: '2026-10-28T10:00:00+00:00',
		})
		const attachmentName = `${UNIQUE_PREFIX}-btw-2026-Q3.xml`
		const attachment = await attach('VatReturn', returnId, attachmentName, 'application/xml', Buffer.from('<?xml version="1.0"?><aangifte/>').toString('base64'))
		await patch('VatReturn', returnId, { attachmentUri: fileUrl(attachment) })
		expect(String((await fx.get('VatReturn', returnId)).state)).toBe('submitted')
		await page.goto(`${APP}/belastingen/btw-aangiften/${returnId}`)
		await expect(page.getByText(attachmentName).first()).toBeVisible({ timeout: 20_000 })
		await expectServed(fileUrl(attachment), /xml/)
	})

	test('the published Q2 BTW deadline and an AR due date show on their pages, once each @spec REQ-CPA-006', async ({ page }) => {
		await needApps(['openregister', 'calendar'])
		const missing = await fx.missingSchema(['Administration', 'AdministrationMembership', 'VatReturn', 'CustomerMaster', 'ARInvoice'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		await administration()

		// The Q2 return BtwAangifteDetail shows; the publisher reads it too.
		const { id: returnId } = await fx.create('VatReturn', {
			administrationId: ADMINISTRATION_ID,
			periodType: 'quarter',
			periodYear: 2026,
			periodQuarter: 2,
			periodStart: '2026-04-01',
			periodEnd: '2026-06-30',
			rubrieken: { '1a': { revenue: 0, vat: 0 } },
			dueSalesTax: 0,
			inputVat: 0,
			payableOrRefund: 0,
			state: 'draft',
		})

		// The AR invoice, issued, due in the future.
		const { id: customerId } = await fx.create('CustomerMaster', {
			customerId: `${UNIQUE_PREFIX}-DEB`,
			legalName: `Debiteur ${UNIQUE_PREFIX}`,
			email: `debiteur-${UNIQUE_PREFIX}@example.org`,
			administrationId: ADMINISTRATION_ID,
			lifecycleState: 'active',
		})
		const invoiceNumber = `${UNIQUE_PREFIX}-F1`
		const { id: invoiceId } = await fx.create('ARInvoice', {
			invoiceNumber,
			customerId,
			administrationId: ADMINISTRATION_ID,
			invoiceDate: '2026-11-20',
			dueDate: '2027-01-15',
			grossAmount: 121,
			netAmount: 100,
			currency: 'EUR',
			periodId: '2026-11',
			lifecycleState: 'issued',
		})
		test.skip(
			String((await fx.get('ARInvoice', invoiceId)).lifecycleState) !== 'issued',
			'the instance keeps a seeded ARInvoice in draft; only an issued invoice has a due-date deadline (REQ-CDC-004)',
		)

		// Publish with the filing and the AR due-date categories on (the REQ-CDC-004 opt-in).
		const before = await api.get(`${APP}/api/deadline-calendar/settings`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(before.status()).toBe(200)
		deadlineSettings = (await before.json()).categories as Record<string, unknown>
		const published = await api.post(`${APP}/api/deadline-calendar/settings`, {
			headers: await writeHeaders(),
			data: { categories: { filing: { enabled: true }, 'ar-due': { enabled: true } } },
		})
		expect(published.status(), await published.text()).toBe(200)
		expect((await published.json()).publication).toBe('ok')

		// The BTW deadline: one VEVENT, listed by the VatReturn's calendar leaf, still one after the page rendered.
		const btwUid = await deadlineUid('btw-filing', 'VatReturn', returnId)
		const btwEvents = await events(btwUid)
		expect(btwEvents, `one VEVENT ${btwUid}`).toHaveLength(1)
		const onReturn = await leafEvents('VatReturn', returnId, btwUid)
		expect(onReturn, 'the calendar leaf lists the deadline once').toHaveLength(1)
		expect(String(onReturn[0].summary ?? '')).toBe('BTW-aangifte 2026-Q2')
		await page.goto(`${APP}/belastingen/btw-aangiften/${returnId}`)
		await openTab(page, /meeting|calendar|agenda|afspra|vergader/i)
		await expect(page.getByText('BTW-aangifte 2026-Q2').first()).toBeVisible({ timeout: 20_000 })
		expect(await events(btwUid), 'rendering the page wrote no second VEVENT').toHaveLength(1)

		// The AR due date: the same on ARInvoiceDetail.
		const arUid = await deadlineUid('ar-invoice', 'ARInvoice', invoiceId)
		const arEvents = await events(arUid)
		expect(arEvents, `one VEVENT ${arUid}`).toHaveLength(1)
		const summary = `Factuur ${invoiceNumber} vervalt`
		const onInvoice = await leafEvents('ARInvoice', invoiceId, arUid)
		expect(onInvoice).toHaveLength(1)
		expect(String(onInvoice[0].summary ?? '')).toBe(summary)
		expect(String(onInvoice[0].dtstart ?? '')).toContain('2027')
		await page.goto(`${APP}/bookkeeping/accounts-receivable/${invoiceId}`)
		await openTab(page, /meeting|calendar|agenda|afspra|vergader/i)
		await expect(page.getByText(summary).first()).toBeVisible({ timeout: 20_000 })
		expect(await events(arUid), 'rendering the page wrote no second VEVENT').toHaveLength(1)
	})

	test('a debtor is linked to its address-book card and a creditor gets one created, both kept over a reload @spec REQ-CPA-007', async ({ page }) => {
		await needApps(['openregister', 'contacts'])
		const missing = await fx.missingSchema(['Administration', 'AdministrationMembership', 'CustomerMaster', 'Payee'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		await administration()

		// An address-book card for the debtor's email.
		const debtorEmail = `contact-${UNIQUE_PREFIX}@example.org`
		const debtorName = `Contact ${UNIQUE_PREFIX}`
		const cardUid = `${UNIQUE_PREFIX}-card`
		const card = `${ADDRESS_BOOK}/${cardUid}.vcf`
		const put = await api.put(card, {
			headers: { 'Content-Type': 'text/vcard; charset=utf-8' },
			data: ['BEGIN:VCARD', 'VERSION:3.0', `UID:${cardUid}`, `FN:${debtorName}`, `EMAIL:${debtorEmail}`, 'END:VCARD', ''].join('\r\n'),
		})
		expect([201, 204], `PUT ${card}`).toContain(put.status())
		cards.push(card)

		// The debtor: the leaf's search finds the card by email, and linking it persists.
		const { id: customerId } = await fx.create('CustomerMaster', {
			customerId: `${UNIQUE_PREFIX}-DEB-C`,
			legalName: `Debiteur ${UNIQUE_PREFIX}`,
			email: debtorEmail,
			administrationId: ADMINISTRATION_ID,
			lifecycleState: 'active',
		})
		const [match] = (await searchContacts(debtorEmail)).filter((row) => String(row.email ?? '') === debtorEmail || String(row.contactUid ?? '') === cardUid)
		expect(match, 'the leaf matches the card on the debtor\'s email').toBeTruthy()
		const linked = await api.post(`${OBJECTS}/CustomerMaster/${customerId}/contacts`, {
			headers: await writeHeaders(),
			data: {
				kind: 'contact',
				contactUid: match.contactUid,
				addressbookId: match.addressbookId,
				contactUri: match.contactUri,
				displayName: debtorName,
				email: debtorEmail,
				role: null,
			},
		})
		expect(linked.ok(), `link the card: HTTP ${linked.status()} ${await linked.text()}`).toBeTruthy()
		unlinks.push(`${OBJECTS}/CustomerMaster/${customerId}/contacts/${encodeURIComponent(String(match.contactUid))}`)
		await page.goto(`${APP}/bookkeeping/customers/${customerId}`)
		await page.reload()
		await openTab(page, /contact/i)
		await expect(page.getByText(debtorName).first()).toBeVisible({ timeout: 20_000 })
		const debtorLinks = await contacts('CustomerMaster', customerId)
		expect(debtorLinks.map((row) => String(row.contactUid ?? ''))).toEqual([String(match.contactUid)])

		// The creditor: nothing matches, so the leaf creates the card and links it.
		const creditorEmail = `crediteur-${UNIQUE_PREFIX}@example.org`
		const creditorName = `Crediteur ${UNIQUE_PREFIX}`
		const { id: payeeId } = await fx.create('Payee', {
			vendorNumber: `${UNIQUE_PREFIX}-CRED`,
			name: creditorName,
			email: creditorEmail,
			paymentTermDays: 30,
			administrationId: ADMINISTRATION_ID,
			lifecycleState: 'active',
		})
		const payeeBefore = { ...(await fx.get('Payee', payeeId)) }
		delete payeeBefore['@self']
		expect(await searchContacts(creditorEmail), 'no card carries the creditor\'s email yet').toHaveLength(0)
		const created = await api.post(`${OBJECTS}/Payee/${payeeId}/contacts/new`, {
			headers: await writeHeaders(),
			data: { displayName: creditorName, email: creditorEmail, phone: null, org: null, role: null },
		})
		expect(created.ok(), `create and link a card: HTTP ${created.status()} ${await created.text()}`).toBeTruthy()
		const creditorLinks = await contacts('Payee', payeeId)
		expect(creditorLinks, 'one card linked to the creditor').toHaveLength(1)
		const creditorCard = creditorLinks[0]
		unlinks.push(`${OBJECTS}/Payee/${payeeId}/contacts/${encodeURIComponent(String(creditorCard.contactUid ?? ''))}`)
		if (String(creditorCard.contactUri ?? '') !== '') {
			cards.push(`${ADDRESS_BOOK}/${String(creditorCard.contactUri)}`)
		}
		await page.goto(`${APP}/bookkeeping/payees/${payeeId}`)
		await page.reload()
		await openTab(page, /contact/i)
		await expect(page.getByText(creditorName).first()).toBeVisible({ timeout: 20_000 })
		expect((await contacts('Payee', payeeId)).map((row) => String(row.contactUid ?? ''))).toEqual([String(creditorCard.contactUid)])

		// The link lives beside the Payee: the object itself is as it was.
		const payeeAfter = { ...(await fx.get('Payee', payeeId)) }
		delete payeeAfter['@self']
		expect(payeeAfter).toEqual(payeeBefore)
	})

	test('an approval conversation started on an expense claim is the same one when the claim is reopened @spec REQ-CPA-008', async ({ page }) => {
		await needApps(['openregister', 'spreed'])
		const missing = await fx.missingSchema(['Administration', 'AdministrationMembership', 'ExpenseClaimEntry'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		await administration()

		const { id: claimId } = await fx.create('ExpenseClaimEntry', {
			claimNumber: `${UNIQUE_PREFIX}-EC-TALK`,
			employeeId: ADMIN_USER,
			fromDate: '2026-11-01',
			toDate: '2026-11-30',
			totalAmount: 42,
			currency: 'EUR',
			approvalState: 'pending',
			state: 'submitted',
			administrationId: ADMINISTRATION_ID,
		})
		expect(await conversations('ExpenseClaimEntry', claimId), 'no conversation yet').toHaveLength(0)

		// Start the approval conversation through the talk leaf, as its "new" form does.
		const roomName = `Approval ${UNIQUE_PREFIX}`
		const started = await api.post(`${OBJECTS}/ExpenseClaimEntry/${claimId}/talk/new`, {
			headers: await writeHeaders(),
			data: { name: roomName, description: `Approval of ${UNIQUE_PREFIX}-EC-TALK`, type: 2 },
		})
		expect(started.ok(), `start the conversation: HTTP ${started.status()} ${await started.text()}`).toBeTruthy()
		const first = await conversations('ExpenseClaimEntry', claimId)
		expect(first, 'one conversation bound to the claim').toHaveLength(1)
		rooms.push(first[0])
		unlinks.push(`${OBJECTS}/ExpenseClaimEntry/${claimId}/talk/${encodeURIComponent(first[0])}`)

		// Open the claim, then reopen it: the Chat tab shows the same conversation.
		await page.goto(`${APP}/inkoop/expense-claims/${claimId}`)
		await openTab(page, /chat|gesprek/i)
		await expect(page.getByText(roomName).first()).toBeVisible({ timeout: 20_000 })
		await page.goto(`${APP}/inkoop/expense-claims`)
		await page.goto(`${APP}/inkoop/expense-claims/${claimId}`)
		await openTab(page, /chat|gesprek/i)
		await expect(page.getByText(roomName).first()).toBeVisible({ timeout: 20_000 })
		expect(await conversations('ExpenseClaimEntry', claimId), 'reopening bound no new conversation').toEqual(first)
	})
})
