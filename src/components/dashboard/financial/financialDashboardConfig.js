// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared date-range config for the Financial overview dashboard's
// range-driven KPI cards. The engine renders interactive range chips
// only for chart widgets, so the KPI cards reproduce the same
// behaviour locally: the preset list, the localStorage persist key
// and the preset→window resolver below mirror what CnDashboardPage
// uses, so a range picked from a KPI chip and one picked from a chart
// chip are identical and interchangeable (same shared range ref +
// same persisted value).
//
// Keep RANGE_PRESETS / PERSIST_KEY in sync with the Financial
// overview page in src/manifest.json (config.dateRange).
//
// @spec openspec/changes/financial-dashboard-graphs/specs/financial-dashboard-graphs/spec.md

import { translate as t } from '@nextcloud/l10n'

/** localStorage key the engine persists the dashboard range under. */
export const RANGE_PERSIST_KEY = 'shillinq-financial-range'

/**
 * Range presets, mirroring the Financial overview manifest. `days`
 * drives a calendar-aligned trailing window via resolvePresetWindow().
 *
 * @return {Array<{ id: string, label: string, days: number }>}
 */
export const RANGE_PRESETS = [
	{ id: 'quarter', label: t('shillinq', 'Last 3 months'), days: 91 },
	{ id: 'half-year', label: t('shillinq', 'Last 6 months'), days: 183 },
	{ id: 'year', label: t('shillinq', 'Last 12 months'), days: 365 },
	{ id: 'two-year', label: t('shillinq', 'Last 24 months'), days: 730 },
]

/**
 * Resolve a preset id to a `{ from, to }` ISO window — a calendar-
 * aligned trailing span ending at end-of-day-today (UTC), matching
 * the engine's resolvePresetWindow for day-granularity presets.
 *
 * @param {string} presetId Preset id (e.g. `year`).
 * @param {Array<object>} presets Preset list to look the id up in.
 * @param {Date} now Reference date.
 * @return {{ from: string, to: string }|null}
 */
export function resolvePresetWindow(presetId, presets = RANGE_PRESETS, now = new Date()) {
	if (!presetId || presetId === 'custom') return null
	const preset = presets.find((p) => p.id === presetId)
	if (!preset || typeof preset.days !== 'number') return null
	const end = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate(), 23, 59, 59, 999))
	const start = new Date(end)
	start.setUTCDate(start.getUTCDate() - (preset.days - 1))
	start.setUTCHours(0, 0, 0, 0)
	return { from: start.toISOString(), to: end.toISOString() }
}

/**
 * Compact, localised label for a `{ from, to }` window — e.g.
 * `21 jun 2025 – 20 jun 2026`. The year is shown only when a bound
 * falls outside the current year (mirrors the engine's chart chip).
 *
 * @param {{ from: string, to: string }|null} range The window.
 * @return {string} Formatted label, or '' when no range.
 */
export function formatRange(range) {
	if (!range || !range.from || !range.to) return ''
	const toDate = (v) => {
		if (typeof v !== 'string' || v.length < 10) return null
		const d = new Date(`${v.slice(0, 10)}T00:00:00`)
		return Number.isNaN(d.getTime()) ? null : d
	}
	const from = toDate(range.from)
	const to = toDate(range.to)
	if (!from && !to) return ''
	const thisYear = new Date().getFullYear()
	const needsYear = [from, to].some((d) => d && d.getFullYear() !== thisYear)
	const fmt = (d) => (d
		? d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', ...(needsYear ? { year: 'numeric' } : {}) })
		: '')
	return `${fmt(from)} – ${fmt(to)}`
}
