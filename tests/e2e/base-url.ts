/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Single authoritative source for the Nextcloud base URL the e2e suite drives.
 *
 * WHY THIS EXISTS
 * ---------------
 * `playwright.config.ts` and `tests/e2e/global-setup.ts` each computed their
 * own base URL, and BOTH ended in `?? 'http://localhost:8080'` — the SHARED
 * dev container. Any run started without the right environment variable
 * therefore drove a real developer instance: creating fixtures in it, and (via
 * globalSetup) firing repeated logins at it, which is how another repo in this
 * programme triggered brute-force lockouts on somebody else's box.
 *
 * There is no localhost:8080 literal here and there must never be one. An
 * unset environment is a HARD failure, because a wrong-but-plausible default
 * is worse than a stopped run.
 *
 * ⚠️ CI's name is `BASE_URL`.
 * The shared `ConductionNL/.github` quality workflow exports the target as
 * `BASE_URL` — not `PLAYWRIGHT_BASE_URL`, not `NEXTCLOUD_URL`. A resolver that
 * only honours `PLAYWRIGHT_BASE_URL` hard-fails every CI run (that is exactly
 * what happened to openconnector during its own Vue 3 migration). All three
 * names are accepted; only the absence of all three is an error.
 */

export const BASE_URL_ENV_NAMES = ['PLAYWRIGHT_BASE_URL', 'BASE_URL', 'NEXTCLOUD_URL'] as const

/**
 * Resolve the base URL, or throw.
 */
export function resolveBaseURL(): string {
	for (const name of BASE_URL_ENV_NAMES) {
		const value = process.env[name]
		if (value && value.trim() !== '') {
			return value.trim().replace(/\/+$/, '')
		}
	}
	throw new Error(
		`No Nextcloud base URL configured. Set one of ${BASE_URL_ENV_NAMES.join(' / ')} `
		+ '— e.g. PLAYWRIGHT_BASE_URL=http://localhost:8087. '
		+ 'There is deliberately no default: the old fallback was the SHARED dev '
		+ 'container on :8080, so an unset environment silently drove somebody '
		+ "else's instance.",
	)
}
