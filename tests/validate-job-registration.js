#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-job-registration.js — keeps documentation honest about which
// background jobs actually run.
//
// A BackgroundJob class that is not listed in appinfo/info.xml is never
// scheduled by Nextcloud. It exists, it has unit tests, and it does nothing.
// That is a legitimate state — enabling a job that dispatches notifications to
// real users is a product decision, not a mechanical fix — but it MUST NOT be
// documented as if it runs.
//
// Observed 2026-08-21: docs/user-guide/.../vpb-administration.md told users to
// look for `TaxDeadlineReminderJob` in `oc_background_jobs` when a reminder did
// not arrive. The job has never been registered, so that search always comes up
// empty and reads as a broken install.
//
// This gate is SYMMETRIC, which is the point:
//   - unregistered + documented as running        -> FAIL (the original defect)
//   - unregistered + carries the "does not send"  -> pass
//     warning
//   - REGISTERED + still carries the warning      -> FAIL (the warning is now
//                                                    itself a lie)
//
// The third case is why this is a gate and not a comment: whoever registers the
// job is told, at that moment, to delete the caveat.

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')
const INFO_XML = path.join(REPO_ROOT, 'appinfo', 'info.xml')

// Jobs whose documentation must state that they do not currently run, for as
// long as they are absent from info.xml. Each entry names the doc that makes a
// user-visible claim about it and the marker phrase that must be present.
const DOCUMENTED_JOBS = [
	{
		job: 'TaxDeadlineReminderJob',
		doc: 'docs/user-guide/bookkeeping/tax/vpb-administration.md',
		marker: 'Deadline reminders do not currently send',
	},
]

/**
 * Read a file, or return null when it does not exist.
 *
 * @param {string} p Absolute path.
 * @return {string|null} Contents, or null.
 */
function read(p) {
	try {
		return fs.readFileSync(p, 'utf8')
	} catch (_) {
		return null
	}
}

function main() {
	const infoXml = read(INFO_XML)
	if (infoXml === null) {
		console.error(
			'[validate-job-registration] FAIL — appinfo/info.xml not readable; cannot tell which jobs are registered.',
		)
		process.exit(1)
	}

	const problems = []
	let checked = 0

	for (const { job, doc, marker } of DOCUMENTED_JOBS) {
		const docPath = path.join(REPO_ROOT, doc)
		const docText = read(docPath)
		if (docText === null) {
			problems.push(
				`${doc} does not exist, but ${job} is listed as documented there.`,
			)
			continue
		}
		checked++

		const registered = infoXml.includes(job)
		const warned = docText.includes(marker)

		if (registered === false && warned === false) {
			problems.push(
				`${job} is NOT registered in appinfo/info.xml, so Nextcloud never schedules it — `
					+ `but ${doc} describes it as running. A user following that guidance searches `
					+ `oc_background_jobs, finds nothing, and concludes their install is broken. `
					+ `Add the "${marker}" warning, or register the job.`,
			)
		}

		if (registered === true && warned === true) {
			problems.push(
				`${job} IS now registered in appinfo/info.xml, but ${doc} still carries the `
					+ `"${marker}" warning. That caveat is now itself false — delete it.`,
			)
		}
	}

	console.log(`[validate-job-registration] documented jobs checked: ${checked}`)

	// A check that inspected nothing must not report success.
	if (checked === 0) {
		console.error(
			'[validate-job-registration] FAIL — inspected ZERO documented jobs. The list is empty or every doc path is wrong; that is a broken gate, not a clean repo.',
		)
		process.exit(1)
	}

	if (problems.length > 0) {
		console.error('[validate-job-registration] FAIL:')
		for (const p of problems) console.error(`  - ${p}`)
		process.exit(1)
	}

	console.log(
		"[validate-job-registration] PASS — every documented job's registration state matches its documentation.",
	)
	process.exit(0)
}

main()
