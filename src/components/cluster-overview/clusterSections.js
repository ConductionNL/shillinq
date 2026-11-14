/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 */

/**
 * Turns a cluster landing page's section table into the descriptors
 * ClusterOverview renders. Every section and card label in the table is a
 * function returning its translated text.
 *
 * @param {Array} table The sections, each with an id, a label function and cards.
 * @return {Array} New section objects with the labels translated.
 * @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
 */
export function translateSections(table) {
	return table.map((section) => ({
		...section,
		label: section.label(),
		cards: section.cards.map(translateCard),
	}))
}

/**
 * @param {object} card A card with a label function.
 * @return {object} A copy of the card with the label translated.
 * @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
 */
function translateCard(card) {
	return { ...card, label: card.label() }
}
