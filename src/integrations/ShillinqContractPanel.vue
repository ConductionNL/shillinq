<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ShillinqContractPanel — the render surface of the `shillinq-contracts-panel`
 leaf (REQ-FPCR-006).

 A case is handled under an agreement, and the handler needs its term, its
 counterparty and what is left of its value. Reading those here means no case
 ever carries a stale copy of the contract: the numbers come from shillinq's
 contract register through OpenRegister's generic per-object integration
 endpoint, every time the panel opens.

 Remaining value can be absent, and absent is not zero. A contract whose
 roll-up has not run yet has no remaining value, and showing 0,00 there would
 stop work that is in fact funded (REQ-FPCR-005).
-->
<template>
	<div class="shillinq-contract-panel" data-testid="shillinq-contract-panel">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="error" class="shillinq-leaf__error" role="alert">
			{{ error }}
		</div>

		<p
			v-else-if="contracts.length === 0"
			class="shillinq-leaf__empty"
			data-testid="shillinq-contract-empty">
			{{ emptyLabel }}
		</p>

		<ul v-else class="shillinq-leaf__list">
			<li
				v-for="contract in contracts"
				:key="contract.id"
				class="shillinq-leaf__row"
				:data-testid="`shillinq-contract-${contract.id}`">
				<div class="shillinq-leaf__row-head">
					<span>{{ titleOf(contract) }}</span>
					<span
						class="shillinq-leaf__state"
						:class="{
							'shillinq-leaf__state--attention':
								contract.needsAttention,
						}">
						{{ contract.status }}
					</span>
				</div>

				<p
					v-if="contract.counterpartyReference"
					class="shillinq-leaf__description">
					{{ counterpartyLine(contract) }}
				</p>

				<p v-if="termLine(contract)" class="shillinq-leaf__due">
					{{ termLine(contract) }}
				</p>

				<p class="shillinq-leaf__description">
					{{ remainingLine(contract) }}
				</p>

				<p
					v-if="contract.incurredCostComplete === false"
					class="shillinq-leaf__notice"
					role="status">
					{{ incompleteLabel }}
				</p>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'
import {
	CONTRACTS_DATA_LEAF,
	formatAmount,
	hostIdentity,
	isResolvable,
	readLeaf,
} from './leafApi.js'

/**
 * The contract panel mounted by the `shillinq-contracts-panel` leaf. See the
 * file-level comment for why it reads rather than copies.
 */
export default {
	name: 'ShillinqContractPanel',

	components: {
		NcLoadingIcon,
	},

	props: {
		/** OpenRegister register id of the host object. */
		register: { type: String, default: '' },
		/** OpenRegister schema id of the host object. */
		schema: { type: String, default: '' },
		/** Id of the host object the contract is linked to. */
		objectId: { type: [String, Number], default: '' },
		/** Whole registry context, the fallback when the discrete props are absent. */
		integrationContext: { type: Object, default: () => ({}) },
	},

	data() {
		return {
			contracts: [],
			loading: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-006) */
		emptyLabel() {
			return t('shillinq', 'This record is not handled under a contract.')
		},

		/** @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-005) */
		incompleteLabel() {
			return t(
				'shillinq',
				'Some linked costs could not be read, so the remaining value is a floor, not a total.',
			)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the contracts this object is handled under.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-006)
		 */
		async load() {
			const identity = hostIdentity(this.$props)
			if (isResolvable(identity) === false) {
				this.error = t('shillinq', 'This panel needs a record to stand on.')
				return
			}

			this.loading = true
			this.error = ''
			try {
				const envelope = await readLeaf(identity, CONTRACTS_DATA_LEAF)
				this.contracts = Array.isArray(envelope.items) ? envelope.items : []
			} catch {
				this.error = t('shillinq', 'The contract could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The contract heading: its title, or its number when it has no title.
		 *
		 * @param {object} contract One contract.
		 * @return {string} The heading.
		 *
		 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-006)
		 */
		titleOf(contract) {
			return (
				contract.title
				|| contract.contractNumber
				|| t('shillinq', 'Contract')
			)
		},

		/**
		 * Who the agreement is with.
		 *
		 * @param {object} contract One contract.
		 * @return {string} The line.
		 *
		 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-006)
		 */
		counterpartyLine(contract) {
			return t('shillinq', 'With {party}', {
				party: contract.counterpartyReference,
			})
		},

		/**
		 * How long the agreement runs.
		 *
		 * @param {object} contract One contract.
		 * @return {string} The line, or an empty string when neither date is set.
		 *
		 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-006)
		 */
		termLine(contract) {
			const from = String(contract.startDate || '').slice(0, 10)
			const until = String(contract.endDate || '').slice(0, 10)
			if (from === '' && until === '') {
				return ''
			}
			if (until === '') {
				return t('shillinq', 'Runs from {from}', { from })
			}
			if (from === '') {
				return t('shillinq', 'Runs until {until}', { until })
			}
			return t('shillinq', 'Runs from {from} until {until}', { from, until })
		},

		/**
		 * What is left of the contract value. Absent is said in words, because
		 * rendering it as 0,00 would read as spent (REQ-FPCR-005).
		 *
		 * @param {object} contract One contract.
		 * @return {string} The line.
		 *
		 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-005)
		 */
		remainingLine(contract) {
			if (
				contract.remainingValue === null
				|| contract.remainingValue === undefined
			) {
				return t(
					'shillinq',
					'The remaining value has not been rolled up yet.',
				)
			}
			const amount = formatAmount(contract.remainingValue, contract.currency)
			return t('shillinq', 'Remaining value {amount}', { amount })
		},
	},
}
</script>

<style scoped>
.shillinq-contract-panel {
	padding: 8px;
}

.shillinq-leaf__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.shillinq-leaf__row {
	border-bottom: 1px solid var(--color-border);
	padding: 8px 0;
}

.shillinq-leaf__row-head {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	font-weight: bold;
}

.shillinq-leaf__state {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.shillinq-leaf__state--attention {
	color: var(--color-warning-text, var(--color-warning));
}

.shillinq-leaf__description,
.shillinq-leaf__due,
.shillinq-leaf__empty {
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}

.shillinq-leaf__error {
	color: var(--color-error);
}

.shillinq-leaf__notice {
	margin-top: 8px;
}
</style>
