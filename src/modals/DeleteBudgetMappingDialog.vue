<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  DeleteBudgetMappingDialog — confirm-gated delete for the BudgetBBVMapping
  detail page (slice 07 of bookkeeping-waterschappen-bbv-variant,
  REQ-BBVW-004). Lives in src/modals/ per hydra-gate-modal-isolation; the
  parent BudgetBBVMappingDetail.vue imports it and binds open / mapping /
  deleting + listens to confirm / cancel.

  Server-authoritative delete: the actual DELETE call is fired by the
  parent against the OpenRegister object endpoint — this dialog only
  collects the operator confirmation. Deletes are not soft, but the
  programme is still recoverable from the OR audit trail (slice 09).

  @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-07-mapping-detail/tasks.md
-->

<template>
	<div
		v-if="open"
		class="bbv-delete-dialog"
		role="dialog"
		aria-modal="true"
		aria-labelledby="bbv-delete-dialog-title"
		data-testid="bbv-delete-dialog">
		<div class="bbv-delete-dialog__panel">
			<header class="bbv-delete-dialog__header">
				<h2 id="bbv-delete-dialog-title">
					{{ label('Delete budget mapping?') }}
				</h2>
			</header>
			<section class="bbv-delete-dialog__body">
				<p>
					{{
						label(
							'This permanently removes the GL → programme allocation. Deletion is logged in the audit trail.',
						)
					}}
				</p>
				<dl
					v-if="mapping"
					class="bbv-delete-dialog__summary"
					data-testid="bbv-delete-dialog-summary">
					<dt>{{ label('GL account') }}</dt>
					<dd>{{ mapping.glAccountNumber || '—' }}</dd>
					<dt>{{ label('Programme') }}</dt>
					<dd>{{ mapping.programmeCode || '—' }}</dd>
					<dt>{{ label('Allocation') }}</dt>
					<dd>{{ formatPercentage(mapping.allocationPercentage) }}</dd>
					<dt>{{ label('Effective') }}</dt>
					<dd>
						{{ mapping.effectiveFrom || '—' }}
						<template v-if="mapping.effectiveTo">
							→ {{ mapping.effectiveTo }}
						</template>
					</dd>
				</dl>
			</section>
			<footer class="bbv-delete-dialog__footer">
				<button
					type="button"
					class="bbv-delete-dialog__btn"
					data-testid="bbv-delete-dialog-cancel"
					:disabled="deleting"
					@click="$emit('cancel')">
					{{ label('Cancel') }}
				</button>
				<button
					type="button"
					class="bbv-delete-dialog__btn bbv-delete-dialog__btn--danger"
					data-testid="bbv-delete-dialog-confirm"
					:disabled="deleting"
					@click="$emit('confirm')">
					{{ deleting ? label('Deleting…') : label('Delete') }}
				</button>
			</footer>
		</div>
	</div>
</template>

<script>
/**
 * Delete confirmation dialog for BudgetBBVMapping (REQ-BBVW-004).
 *
 * Props:
 *   - open: boolean — render the modal.
 *   - mapping: object|null — the mapping being deleted; null while loading.
 *   - deleting: boolean — disable buttons while the DELETE call is in
 *     flight (the parent passes this through from its own state).
 *
 * Emits:
 *   - confirm: user confirmed the delete.
 *   - cancel: user dismissed the dialog.
 */
export default {
	name: 'DeleteBudgetMappingDialog',
	props: {
		open: { type: Boolean, default: false },
		mapping: { type: Object, default: null },
		deleting: { type: Boolean, default: false },
	},

	emits: ['confirm', 'cancel'],
	methods: {
		label(key) {
			if (typeof t === 'function') {
				return t('shillinq', key)
			}
			return key
		},

		formatPercentage(value) {
			const num = Number(value)
			if (!Number.isFinite(num)) {
				return '—'
			}
			return `${num.toFixed(2)} %`
		},
	},
}
</script>

<style scoped>
.bbv-delete-dialog {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.bbv-delete-dialog__panel {
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: var(--border-radius-large);
	padding: 1.5rem;
	min-width: 24rem;
	max-width: 32rem;
	box-shadow: 0 10px 40px rgba(0, 0, 0, 0.25);
}

.bbv-delete-dialog__header h2 {
	margin: 0 0 0.5rem;
}

.bbv-delete-dialog__body p {
	margin: 0 0 1rem;
}

.bbv-delete-dialog__summary {
	display: grid;
	grid-template-columns: max-content 1fr;
	row-gap: 0.25rem;
	column-gap: 0.75rem;
	margin: 0;
	font-size: 0.875rem;
}

.bbv-delete-dialog__summary dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.bbv-delete-dialog__summary dd {
	margin: 0;
}

.bbv-delete-dialog__footer {
	display: flex;
	justify-content: flex-end;
	gap: 0.5rem;
	margin-top: 1.5rem;
}

.bbv-delete-dialog__btn {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 0.5rem 1rem;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.bbv-delete-dialog__btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.bbv-delete-dialog__btn:hover:not(:disabled) {
	background: var(--color-background-hover);
}

.bbv-delete-dialog__btn--danger {
	border-color: var(--color-error);
	background: var(--color-error);
	color: var(--color-primary-text);
}

.bbv-delete-dialog__btn--danger:hover:not(:disabled) {
	background: var(--color-error);
	opacity: 0.9;
}
</style>
