<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 VatReturnChecksPanel: the Checks tab on a BTW return
 (tax-vat-return-from-books REQ-TVRB-001, task 3.3).

 Runs the return's checks on the server and shows each one as passed, failed
 and blocking, or failed as a warning, with what to fix (the transactions,
 accounts or documents the server named). A failed blocking check is the
 reason Submit is refused. The state is written out, not only coloured.

 Rendered in VATReturnDetail's sidebar tab "Checks" (sidebar widgets get the
 loaded return as `objectData`).

 @spec openspec/changes/tax-vat-return-from-books/specs/bookkeeping-vat-btw-filing/spec.md
-->

<template>
	<section class="vrc" data-testid="vat-return-checks">
		<p v-if="loading" class="vrc__muted">
			{{ t('shillinq', 'Running the checks…') }}
		</p>
		<p
			v-else-if="error"
			class="vrc__error"
			role="alert"
			data-testid="vat-return-checks-error">
			{{ error }}
		</p>
		<ul v-else class="vrc__list">
			<li
				v-for="row in rows"
				:key="row.id"
				class="vrc__check"
				:class="['vrc__check--' + row.state]"
				:data-testid="'vat-return-check-' + row.id">
				<span class="vrc__name">{{ row.name }}</span>
				<span class="vrc__state">{{ row.stateLabel }}</span>
				<ul v-if="row.offenders.length" class="vrc__offenders">
					<li v-for="offender in row.offenders" :key="offender">
						{{ offender }}
					</li>
				</ul>
			</li>
		</ul>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { checkRows, fetchVatReturnChecks } from '../../utils/vatReturnChecks.js'

export default {
	name: 'VatReturnChecksPanel',

	props: {
		/** The loaded BtwAangifte, supplied by the sidebar's widget binding. */
		objectData: { type: Object, default: null },
	},

	data() {
		return {
			loading: false,
			error: '',
			checks: [],
		}
	},

	computed: {
		/**
		 * The rows to show.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.3
		 */
		rows() {
			return checkRows(this.checks)
		},

		/**
		 * The return's id.
		 *
		 * @return {string}
		 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.3
		 */
		returnId() {
			return String(
				this.objectData?.id || this.objectData?.['@self']?.id || '',
			)
		},
	},

	watch: {
		returnId: {
			immediate: true,
			/**
			 * Run the checks once the return is loaded.
			 *
			 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.3
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * Ask the server for the checks.
		 *
		 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.3
		 */
		async load() {
			if (this.returnId === '') {
				return
			}
			this.loading = true
			this.error = ''
			try {
				this.checks = await fetchVatReturnChecks(this.returnId)
			} catch {
				this.error = t('shillinq', 'The checks could not be run.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.vrc__list,
.vrc__offenders {
	margin: 0;
	padding: 0;
	list-style: none;
}

.vrc__check {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 12px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.vrc__name {
	flex: 1 1 auto;
	font-weight: bold;
}

.vrc__check--blocking .vrc__state {
	color: var(--color-error-text);
}

.vrc__check--warning .vrc__state {
	color: var(--color-warning-text);
}

.vrc__check--passed .vrc__state {
	color: var(--color-success-text);
}

.vrc__offenders {
	flex: 1 1 100%;
	padding-inline-start: 16px;
}

.vrc__muted {
	color: var(--color-text-maxcontrast);
}

.vrc__error {
	color: var(--color-error-text);
}
</style>
