/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Contract test for the accountant-portal wiring (accountant-portal,
 * REQ-ACP-001 / REQ-ACP-002 / REQ-ACP-004).
 *
 * WHY THIS EXISTS. Every other piece of the accountant portal shipped —
 * the manifest page (src/manifest.d/accountant-portal.json), the view
 * (src/views/AccountantPortalDashboard.vue), the API client
 * (src/api/accountantApi.js) and the controller
 * (lib/Controller/AccountantPortalController.php) — while
 * `appinfo/routes.php` contained no entry for either endpoint. The portal
 * therefore 404'd on open for every user, and nothing caught it: the
 * controller's unit tests call the methods directly, so they never touch
 * the router.
 *
 * This test asserts the two files AGREE. It reads the URLs the client
 * actually requests out of src/api/accountantApi.js and requires each one
 * to have a matching declaration in appinfo/routes.php. It deliberately
 * does NOT hardcode the URL list on one side only: a test that merely
 * restated routes.php back to itself would pass even if the client asked
 * for something else entirely.
 *
 * @spec openspec/specs/accountant-portal/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const repoRoot = path.resolve(__dirname, '..', '..')
const routesSource = fs.readFileSync(
	path.join(repoRoot, 'appinfo', 'routes.php'),
	'utf8',
)
const clientSource = fs.readFileSync(
	path.join(repoRoot, 'src', 'api', 'accountantApi.js'),
	'utf8',
)

/**
 * Extract every declared route as {name, url, verb} from appinfo/routes.php.
 *
 * Matches the two literal styles used in that file — the one-line
 * `['name' => 'x#y', 'url' => '/…', 'verb' => 'GET']` form and the
 * multi-line form — by keying off the quoted values rather than layout,
 * so reformatting the file does not silently empty this list.
 *
 * @return {Array<{name: string, url: string, verb: string}>} Declared routes.
 */
function parseDeclaredRoutes() {
	const routes = []
	const re =
		/'name'\s*=>\s*'([^']+)'\s*,\s*'url'\s*=>\s*'([^']+)'\s*,\s*'verb'\s*=>\s*'([^']+)'/g
	let m
	while ((m = re.exec(routesSource)) !== null) {
		routes.push({ name: m[1], url: m[2], verb: m[3] })
	}
	return routes
}

/**
 * Extract the shillinq API paths that accountantApi.js requests, normalising
 * any `${...}` template placeholder to the Nextcloud `{id}` route wildcard.
 *
 * @return {Array<string>} Requested API paths, e.g. `/api/accountant/dashboard`.
 */
function parseClientPaths() {
	const paths = []
	const re = /\/apps\/shillinq(\/api\/[^'"`]*)/g
	let m
	while ((m = re.exec(clientSource)) !== null) {
		paths.push(m[1].replace(/\$\{[^}]*\}/g, '{id}'))
	}
	return paths
}

describe('accountant portal routing contract', () => {
	// Guard against the whole suite passing because a regex silently matched
	// nothing. A zero-length input would make every assertion below vacuous.
	it('parses a non-empty route table and a non-empty client URL list', () => {
		expect(parseDeclaredRoutes().length).toBeGreaterThan(50)
		expect(parseClientPaths().length).toBeGreaterThan(0)
	})

	it('declares a route for every URL src/api/accountantApi.js requests', () => {
		const declaredUrls = parseDeclaredRoutes().map((r) => r.url)
		for (const requested of parseClientPaths()) {
			expect(declaredUrls, `no route declared for ${requested}`).toContain(
				requested,
			)
		}
	})

	it('routes accountantPortal#dashboard at GET /api/accountant/dashboard', () => {
		expect(parseDeclaredRoutes()).toContainEqual({
			name: 'accountantPortal#dashboard',
			url: '/api/accountant/dashboard',
			verb: 'GET',
		})
	})

	it('routes accountantPortal#handoverPack at GET the handover-pack URL', () => {
		expect(parseDeclaredRoutes()).toContainEqual({
			name: 'accountantPortal#handoverPack',
			url: '/api/accountant/administrations/{id}/handover-pack',
			verb: 'GET',
		})
	})
})
