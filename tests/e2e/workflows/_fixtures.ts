/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Seeded-fixture helper for the DEEP, DATA-DEPENDENT financial-correctness
 * test layer.
 *
 * The shillinq calculation endpoints (IFRS 16 lease amortization, OSS/BTW
 * rate resolution, trial balance, payroll) compute their numbers from
 * OpenRegister objects (LeaseContract, EuVatRate, GLLine, …) scoped to a
 * shillinq register. These helpers create / read / delete those objects
 * through OpenRegister's generic object REST API — the SAME HTTP surface
 * the shillinq manifest SPA uses — so the calculations are exercised
 * end-to-end through Nextcloud's full middleware + auth + OR storage stack.
 *
 * Design rules followed here:
 *   - Every seeded object carries a unique per-run prefix (UNIQUE_PREFIX)
 *     so a failed run never collides with a later one and cleanup can be
 *     scoped precisely.
 *   - afterAll cleanup deletes every object this run created (tracked in
 *     `created`), even when an assertion failed mid-test.
 *   - The real register/schema slugs are discovered from the live OR
 *     instance, not hard-coded, so the helper survives a register re-import
 *     that changes numeric ids.
 *
 * IMPORTANT ENVIRONMENT NOTE (2026-06-10): on the instance these specs were
 * authored against, OpenRegister's configuration ImportHandler throws a
 * TypeError while importing the shillinq register fragments
 * (`SchemaMapper::find(): Argument #1 ($id) must be of type string|int,
 * null given` — ImportHandler.php:1277), so the shillinq register +
 * LeaseContract / EuVatRate / Administration schemas are never created.
 * `ensureRegister()` detects this and lets the calc specs `test.fixme`
 * themselves with a precise, actionable skip reason instead of producing a
 * misleading green. The exact expected numbers are still asserted, so the
 * specs run for real the moment the register imports.
 */

import { APIRequestContext, expect } from '@playwright/test'

/** OpenRegister generic object API base. */
const OR = '/index.php/apps/openregister/api'

/** The shillinq register slug (per lib/Settings/register.d/*.json `@self`). */
export const REGISTER_SLUG = 'shillinq'

/** A unique, filesystem/slug-safe prefix for every object this run seeds. */
export const UNIQUE_PREFIX = `e2efin-${Date.now().toString(36)}-${Math.floor(Math.random() * 1e4)}`

/**
 * One created object, tracked for afterAll cleanup.
 */
export interface CreatedRef {
	registerSlug: string
	schemaSlug: string
	id: string
}

/**
 * A small client around the OpenRegister object API bound to one Playwright
 * request context (which carries the authenticated storage state).
 */
export class OrFixtures {
	private created: CreatedRef[] = []

	constructor(private readonly api: APIRequestContext) {}

	/**
	 * The Nextcloud CSRF requesttoken read from an authenticated app page.
	 * OR's write routes require it in addition to the session cookie.
	 */
	private requestToken: string | null = null

	private async token(): Promise<string> {
		if (this.requestToken) {
			return this.requestToken
		}
		const res = await this.api.get('/index.php/apps/shillinq/')
		const html = await res.text()
		const m = html.match(/data-requesttoken="([^"]+)"/)
		this.requestToken = m ? m[1] : ''
		return this.requestToken
	}

	private async headers(): Promise<Record<string, string>> {
		return {
			requesttoken: await this.token(),
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
		}
	}

	/**
	 * Resolve whether the shillinq register exists live and exposes a schema.
	 * Returns the missing schema name (or null) so callers can skip precisely.
	 *
	 * @param schemaSlugs schema slugs the test needs (e.g. ['LeaseContract']).
	 * @return null when all present; otherwise the first missing schema slug,
	 *         or the sentinel 'shillinq-register' when the register itself is
	 *         absent (the OR ImportHandler blocker).
	 */
	async missingSchema(schemaSlugs: string[]): Promise<string | null> {
		const regs = await this.api.get(`${OR}/registers`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		if (!regs.ok()) {
			return 'shillinq-register'
		}
		const body = await regs.json().catch(() => ({}))
		const list: Array<{ slug?: string }> = body.results ?? body ?? []
		const hasRegister = list.some((r) => r.slug === REGISTER_SLUG)
		if (!hasRegister) {
			return 'shillinq-register'
		}
		const schemas = await this.api.get(`${OR}/schemas?_limit=1000`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		const sBody = await schemas.json().catch(() => ({}))
		const sList: Array<{ slug?: string }> = sBody.results ?? sBody ?? []
		const present = new Set(sList.map((s) => s.slug))
		for (const want of schemaSlugs) {
			if (!present.has(want)) {
				return want
			}
		}
		return null
	}

	/**
	 * Create one object in (register, schema). Tracks it for cleanup and
	 * returns its OpenRegister id.
	 * @param schemaSlug
	 * @param data
	 */
	async create(
		schemaSlug: string,
		data: Record<string, unknown>,
	): Promise<{ id: string; self: Record<string, unknown> }> {
		const res = await this.api.post(
			`${OR}/objects/${REGISTER_SLUG}/${schemaSlug}`,
			{
				headers: await this.headers(),
				data,
			},
		)
		expect(
			res.ok(),
			`create ${schemaSlug} failed: HTTP ${res.status()} ${await res.text()}`,
		).toBeTruthy()
		const body = await res.json()
		// OR returns either the object directly or { '@self': {...}, ... }.
		const self = (body['@self'] ?? body) as Record<string, unknown>
		const id = String(self.id ?? body.id ?? '')
		expect(id, `no id in create response for ${schemaSlug}`).not.toEqual('')
		this.created.push({ registerSlug: REGISTER_SLUG, schemaSlug, id })
		return { id, self }
	}

	/**
	 * Read one object by id.
	 * @param schemaSlug
	 * @param id
	 */
	async get(schemaSlug: string, id: string): Promise<Record<string, unknown>> {
		const res = await this.api.get(
			`${OR}/objects/${REGISTER_SLUG}/${schemaSlug}/${id}`,
			{
				headers: { 'OCS-APIRequest': 'true' },
			},
		)
		expect(
			res.ok(),
			`get ${schemaSlug}/${id} failed: HTTP ${res.status()}`,
		).toBeTruthy()
		const body = await res.json()
		return (body['@self'] ? body : body) as Record<string, unknown>
	}

	/**
	 * Update one object by id (PUT).
	 * @param schemaSlug
	 * @param id
	 * @param data
	 */
	async update(
		schemaSlug: string,
		id: string,
		data: Record<string, unknown>,
	): Promise<Record<string, unknown>> {
		const res = await this.api.put(
			`${OR}/objects/${REGISTER_SLUG}/${schemaSlug}/${id}`,
			{
				headers: await this.headers(),
				data,
			},
		)
		expect(
			res.ok(),
			`update ${schemaSlug}/${id} failed: HTTP ${res.status()} ${await res.text()}`,
		).toBeTruthy()
		return res.json()
	}

	/**
	 * Fire an OpenRegister lifecycle transition on an object.
	 *
	 * POSTs to /api/objects/{id}/transition {action}. Returns the raw response
	 * so callers can assert on both success (200) and refusal (422/4xx) — the
	 * latter is what proves an orderType-gated transition is rejected.
	 *
	 * @param id     The object id (uuid).
	 * @param action The lifecycle action name (e.g. 'verleen', 'approve').
	 */
	async transition(
		id: string,
		action: string,
	): Promise<import('@playwright/test').APIResponse> {
		return this.api.post(`${OR}/objects/${id}/transition`, {
			headers: await this.headers(),
			data: { action },
		})
	}

	/**
	 * Delete one object by id and stop tracking it.
	 * @param schemaSlug
	 * @param id
	 */
	async remove(schemaSlug: string, id: string): Promise<void> {
		await this.api
			.delete(`${OR}/objects/${REGISTER_SLUG}/${schemaSlug}/${id}`, {
				headers: await this.headers(),
			})
			.catch(() => undefined)
		this.created = this.created.filter(
			(c) => !(c.schemaSlug === schemaSlug && c.id === id),
		)
	}

	/** Delete every object created this run (afterAll). Best-effort. */
	async cleanup(): Promise<void> {
		const headers = await this.headers()
		for (const ref of [...this.created].reverse()) {
			await this.api
				.delete(
					`${OR}/objects/${ref.registerSlug}/${ref.schemaSlug}/${ref.id}`,
					{ headers },
				)
				.catch(() => undefined)
		}
		this.created = []
	}
}

/**
 * Round a money number to 2 decimals for tolerant float comparison.
 * @param n
 */
export function money(n: number): number {
	return Math.round(n * 100) / 100
}
