/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A POLLING visibility probe, for use as a `test.skip()` condition.
 *
 * ⚠️ WHY THIS FILE EXISTS — `locator.isVisible()` DOES NOT WAIT.
 * ------------------------------------------------------------------
 * `Locator.isVisible()` is an *immediate* predicate: it answers "is this
 * element visible on this tick". Its `timeout` option is **ignored** — passing
 * `{ timeout: 10_000 }` buys nothing at all, which is why Playwright
 * deprecated the option. Only `expect(...).toBeVisible()` and
 * `locator.waitFor()` poll.
 *
 * So this shape asks the question before the SPA has issued a single XHR:
 *
 *     await page.goto(url)
 *     const has = await thing.isVisible().catch(() => false)
 *     test.skip(!has, 'not present in this fixture')
 *
 * It answers "no" essentially always, and the test skips **with a reason that
 * is false**. This app has already paid for it twice:
 *
 *   - `receipt-extraction-consume.spec.ts` skipped three tests with *"Import
 *     bill action not visible in this fixture"* while `import-bill` was a
 *     declared, working `type:"open-modal"` header action (fixed in #867);
 *   - `bank-statement-wizard.spec.ts` reported all ELEVEN of its tests as
 *     *"Import bank action not available for this administration"*, blaming the
 *     instance for a selector that had simply moved.
 *
 * 🔑 A SKIP WHOSE STATED REASON IS UNTRUE IS AN INVISIBLE PASS — worse than a
 * stub assertion, because it renders as "not applicable" rather than as a gap,
 * the reason looks investigated, and it inflates the skip count, which is the
 * number that separates a flake from a regression.
 *
 * `waitFor` polls. **The skip that survives it is a real one.**
 *
 * The `test.skip()` calls are deliberately KEPT: the fix is not to unskip, it
 * is to make the gate tell the truth.
 *
 * ℹ️ Written as `.js` with JSDoc types on purpose — this suite has both `.js`
 * and `.ts` specs, and both import it.
 */

/**
 * Wait up to `timeout` for a locator to become visible; return whether it did.
 *
 * @param {import('@playwright/test').Locator} locator The locator to poll.
 *        `.first()` is applied so a strict-mode violation on a multi-match
 *        selector cannot masquerade as an absence.
 * @param {number} [timeout] Milliseconds to poll for. Default 10s — enough for
 *        a Nextcloud SPA route to mount and fetch.
 * @return {Promise<boolean>} `true` when the element became visible within
 *         `timeout`, else `false`. Never throws.
 */
export async function becomesVisible(locator, timeout = 10_000) {
	return await locator
		.first()
		.waitFor({ state: 'visible', timeout })
		.then(() => true)
		.catch(() => false)
}
