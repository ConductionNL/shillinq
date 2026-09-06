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
 * what happened to openconnector during its own Vue 3 migration). All four
 * names below are accepted; only the absence of all four is an error.
 * (`NC_BASE_URL` is exported by the same workflow steps and is accepted for the
 * same reason: the cost of honouring a name CI might use is nil, the cost of
 * omitting one is a job that fails before the first spec.)
 *
 * THE ONE EXCEPTION IS CI.
 * The "no default" rule exists because `http://localhost:8080` is the SHARED
 * development container on the team's box. On a GitHub runner there is no such
 * thing — the workflow starts a throwaway Nextcloud on the runner's OWN
 * localhost:8080 — so falling back there harms nobody, and it keeps the suite
 * running if a future workflow revision renames its variable again.
 */

export const BASE_URL_ENV_NAMES = [
	'PLAYWRIGHT_BASE_URL',
	'BASE_URL',
	'NEXTCLOUD_URL',
	'NC_BASE_URL',
] as const

/**
 * The runner-local Nextcloud the shared workflow starts with `php -S`.
 * Only ever used when `CI` / `GITHUB_ACTIONS` is set — see the header.
 */
const CI_DEFAULT_BASE_URL = 'http://localhost:8080'

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

	if (process.env.GITHUB_ACTIONS === 'true' || process.env.CI) {
		console.warn(
			`[shillinq e2e] none of ${BASE_URL_ENV_NAMES.join(' / ')} is set; `
				+ `falling back to the CI-local ${CI_DEFAULT_BASE_URL}.`,
		)
		return CI_DEFAULT_BASE_URL
	}

	throw new Error(
		`No Nextcloud base URL configured. Set one of ${BASE_URL_ENV_NAMES.join(' / ')} `
			+ '— e.g. PLAYWRIGHT_BASE_URL=http://localhost:8087. '
			+ 'There is deliberately no default outside CI: the old fallback was the '
			+ 'SHARED dev container on :8080, so an unset environment silently drove '
			+ "somebody else's instance.",
	)
}
