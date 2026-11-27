/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the Shillinq helper in hermiq answers the period close
 * question from the user guide (platform-help-agent task 3.2, REQ-PHA-001,
 * REQ-PHA-002 and REQ-PHA-003).
 *
 * The live pass on an instance with hermiq:
 *
 *   1. the user guide page on closing a period is a HelpArticle in shillinq's
 *      register (`docs/user-guide/user/10-period-close.md`, seeded by the
 *      SeedHelpArticles repair step) with its documentation URL on
 *      shillinq.conduction.nl, and the Help articles index lists it; design D1
 *      also gives it the period close pages as `pageIds`;
 *   2. an administrator instantiates "Shillinq helper" from hermiq's agent
 *      templates ("Use this template"). No route for that is named in this
 *      repo or in the nextcloud-vue chat client it ships, so the step is a
 *      test.fixme, and the next step looks the instantiated agent up instead;
 *   3. with the helper found in hermiq's agents (`GET /apps/hermiq/api/agents`,
 *      the list the companion's agent picker reads), the question "hoe sluit
 *      ik september af?" is sent through `POST /apps/hermiq/api/chat/send`, the
 *      companion's own non-streaming route, with the page context the
 *      companion sends from the period close detail page; the answer is not
 *      empty and links the article's documentation page.
 *
 * The answer and its link, which the task wants in the PR body, are recorded
 * as test annotations. The spec skips, and says why, when hermiq is not
 * installed, its chat engine does not answer, or no agent has been made from
 * the template yet. The conversation hermiq keeps is the administrator's and
 * is left; the spec writes nothing in shillinq's register.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/platform-help-agent/specs/shillinq-mcp-tool-surface/spec.md
 * @spec openspec/changes/platform-help-agent/tasks.md
 *
 * @e2e shillinq-mcp-tool-surface::an-administrator-adds-the-helper
 * @e2e shillinq-mcp-tool-surface::a-bookkeeper-asks-on-the-period-close-page
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { OrFixtures } from './workflows/_fixtures.ts'

const APP = '/index.php/apps/shillinq'
const HERMIQ = '/index.php/apps/hermiq/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }
const ARTICLE_SOURCE = 'docs/user-guide/user/10-period-close.md'
const HELPER = 'Shillinq helper'
const QUESTION = 'hoe sluit ik september af?'

/**
 * Whether an app is enabled: listed among the enabled apps, or, when the OCS
 * list cannot be read, its app route answering.
 *
 * @param api   The administrator's request context.
 * @param appId The app id.
 * @return True when the app is there.
 */
async function appInstalled(api: APIRequestContext, appId: string): Promise<boolean> {
	const apps = await api.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json', {
		headers: { ...HEADERS, Accept: 'application/json' },
	})
	if (apps.ok()) {
		return (await apps.text()).includes(`"${appId}"`)
	}
	const route = await api.get(`/index.php/apps/${appId}/`, { maxRedirects: 0 })
	return route.status() === 200
}

/**
 * Close Nextcloud's first-run wizard when it covers the page.
 *
 * @param page The page.
 */
async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/**
 * The rows of a list answer.
 *
 * @param body The answer.
 * @return Its rows.
 */
function rowsOf(body: unknown): Array<Record<string, unknown>> {
	if (Array.isArray(body)) {
		return body as Array<Record<string, unknown>>
	}
	const wrapped = (body ?? {}) as Record<string, unknown>
	return (wrapped.results ?? wrapped.items ?? wrapped.agents ?? wrapped.data ?? []) as Array<Record<string, unknown>>
}

test.describe('platform-help-agent: the Shillinq helper in hermiq (task 3.2)', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let hermiq = false

	/**
	 * The "Close a fiscal period" help article.
	 *
	 * @return The article, or undefined when it was not seeded.
	 */
	async function periodCloseArticle(): Promise<Record<string, unknown> | undefined> {
		const res = await api.get(`${OBJECTS}/HelpArticle?_limit=500`, { headers: HEADERS })
		expect(res.ok(), `HelpArticle list: HTTP ${res.status()}`).toBeTruthy()
		return rowsOf(await res.json()).find((article) => article.sourcePath === ARTICLE_SOURCE)
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
		hermiq = await appInstalled(api, 'hermiq')
	})

	test.afterAll(async () => {
		await api?.dispose()
	})

	test('the period close guide page is a help article with its documentation link @spec REQ-PHA-001', async ({ page }) => {
		const missing = await fx.missingSchema(['HelpArticle'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		const article = await periodCloseArticle()
		expect(article, `SeedHelpArticles seeded ${ARTICLE_SOURCE}`).toBeTruthy()
		expect(article?.title).toBe('Close a fiscal period')
		expect(article?.documentationUrl).toBe('https://shillinq.conduction.nl/user-guide/user/period-close')
		expect(String(article?.body ?? '')).toContain('period close')
		expect(String(article?.sourceHash ?? '')).toMatch(/^[0-9a-f]{64}$/)
		// Design D1 ties the article to the pages it explains; the helper prefers
		// it on the period close page through these ids (REQ-PHA-003).
		expect.soft(article?.pageIds, 'the period close pages carry a documentationUrl to this article').toEqual(expect.arrayContaining(['PeriodClose', 'PeriodCloseDetail']))

		await page.goto(`${APP}/documentation/help-articles`)
		await dismissOverlays(page)
		await expect(page.getByText('Close a fiscal period').first()).toBeVisible({ timeout: 20_000 })
	})

	test('an administrator instantiates the Shillinq helper from hermiq\'s agent templates @spec REQ-PHA-002', async () => {
		test.skip(!hermiq, 'hermiq is not installed: the helper is offered to hermiq\'s template catalogue')
		// OfferHelperAgentTemplate answers hermiq's CollectAgentTemplatesEvent;
		// listing that catalogue and "Use this template" are hermiq routes this
		// repo does not name, so instantiating is left to an administrator in
		// hermiq's UI. The next test finds the agent it makes.
		test.fixme(true, 'no route for hermiq\'s agent template catalogue or its "Use this template" is named in this repo or its chat client')
	})

	test('asked "hoe sluit ik september af?" on the period close page, the helper answers with the guide\'s link @spec REQ-PHA-003', async () => {
		test.skip(!hermiq, 'hermiq is not installed: the helper is a hermiq agent')
		const health = await api.get(`${HERMIQ}/chat/health`, { headers: HEADERS })
		test.skip(!health.ok(), `hermiq's chat engine does not answer its health probe (HTTP ${health.status()}): enable it and give it a model`)

		const agents = await api.get(`${HERMIQ}/agents`, { headers: HEADERS })
		expect(agents.ok(), `hermiq agents: HTTP ${agents.status()}`).toBeTruthy()
		const helper = rowsOf(await agents.json()).find((agent) => agent.name === HELPER)
		test.skip(helper === undefined, `no "${HELPER}" agent yet: an administrator instantiates it from hermiq's agent templates`)
		const agentUuid = String(helper?.uuid ?? helper?.id ?? '')
		test.info().annotations.push({ type: 'helper agent', description: agentUuid })

		const article = await periodCloseArticle()
		expect(article, `SeedHelpArticles seeded ${ARTICLE_SOURCE}`).toBeTruthy()
		const link = String(article?.documentationUrl ?? '')

		// The body the companion sends (useAiChatStream), with the context
		// CnAppRoot provides on the period close detail page.
		const html = await (await api.get(`${APP}/`)).text()
		const token = html.match(/data-requesttoken="([^"]+)"/)?.[1] ?? ''
		const sent = await api.post(`${HERMIQ}/chat/send`, {
			headers: { ...HEADERS, requesttoken: token, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
			data: {
				message: QUESTION,
				content: QUESTION,
				context: { appId: 'shillinq', pageKind: 'custom', pageId: 'PeriodCloseDetail', route: { path: '/apps/shillinq/bookkeeping/period-close/2026-09' } },
				newThread: true,
				conversation: null,
				conversationUuid: null,
				agentUuid,
			},
			timeout: 180_000,
		})
		const sentText = await sent.text()
		expect(sent.ok(), `chat/send: HTTP ${sent.status()} ${sentText.slice(0, 500)}`).toBeTruthy()
		const reply = JSON.parse(sentText) as Record<string, unknown>
		const answer = String(reply.content ?? reply.message ?? reply.reply ?? '')
		test.info().annotations.push({ type: 'helper answer', description: answer.slice(0, 2000) })
		test.info().annotations.push({ type: 'helper link', description: link })

		expect(answer.trim(), 'the helper answered').not.toBe('')
		expect(answer, 'the answer links the period close guide page').toContain(link)
	})
})
