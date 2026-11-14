/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 */

/**
 * Refuse a second submit within five seconds, otherwise note the time
 * (REQ-SYNC-002). Used as a component method of the inventory operations,
 * so `this` is the component.
 *
 * @return {boolean} Whether this submit may go ahead.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */
export function claimSubmitSlot() {
	const now = Date.now()
	if (now - this.lastSubmittedAt < 5000) {
		this.error = this.t('shillinq', 'Already submitted; waiting for server ACK.')
		return false
	}
	this.lastSubmittedAt = now
	return true
}
