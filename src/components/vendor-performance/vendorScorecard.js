// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Formatting shared by the vendor-performance index and detail views: a
// basis-point rate as a percentage, the score band of a rate, and the label
// of a score trend.

import { translate as t } from '@nextcloud/l10n'

/**
 * A basis-point rate as a two-decimal percentage, or an em dash when absent.
 *
 * @spec openspec/specs/bookkeeping-vendor-performance/spec.md
 * @param {number|string|null|undefined} bp Rate in basis points.
 * @return {string}
 */
export function formatBp(bp) {
	if (bp === null || bp === undefined) {
		return '—'
	}
	const value = Number(bp)
	if (!Number.isFinite(value)) {
		return '—'
	}
	return `${(value / 100).toFixed(2)} %`
}

/**
 * The score band of a rate: high from 96 %, mid from 85 %, else low.
 *
 * @spec openspec/specs/bookkeeping-vendor-performance/spec.md
 * @param {number|string|null|undefined} bp Rate in basis points.
 * @return {'high'|'mid'|'low'}
 */
export function scoreBand(bp) {
	const value = Number(bp || 0)
	if (value >= 9600) {
		return 'high'
	}
	if (value >= 8500) {
		return 'mid'
	}
	return 'low'
}

/**
 * The label of a score trend; an unknown trend reads as stable.
 *
 * @spec openspec/specs/bookkeeping-vendor-performance/spec.md
 * @param {string} trend 'improving', 'stable' or 'declining'.
 * @return {string}
 */
export function trendLabel(trend) {
	const labels = {
		improving: t('shillinq', 'Improving'),
		stable: t('shillinq', 'Stable'),
		declining: t('shillinq', 'Declining'),
	}
	return labels[trend] || t('shillinq', 'Stable')
}
