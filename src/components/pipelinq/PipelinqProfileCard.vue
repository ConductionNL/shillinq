<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Pipelinq Profile Card — slice 06 of bookings-pipelinq-customer-bridge.

  Renders a read-only customer profile sourced from the slice-05
  BookingDetailController response. The card auto-switches between four
  states (`ok` / `notfound` / `error` / `unlinked`); the operator edits
  customer data in pipelinq, never inline here (ADR-005 read-only).

  Field list is computed by `buildProfileFields()` so the template stays
  declarative and missing optional fields disappear entirely (no empty
  labels). The card honours nldesign theming via Nextcloud CSS variables
  (no hardcoded colors per ADR-010).

  @spec openspec/changes/bookings-pipelinq-customer-bridge-06-profile-card-ui/tasks.md
-->
<template>
	<section
		class="pipelinq-profile-card"
		data-testid="pipelinq-profile-card"
		:data-state="state">
		<header class="pipelinq-profile-card__header">
			<h3 class="pipelinq-profile-card__title">
				{{ label('Customer') }}
			</h3>
			<span
				v-if="state === 'ok'"
				class="pipelinq-profile-card__kind"
				data-testid="pipelinq-profile-kind">
				{{
					kind === 'organization'
						? label('Organization')
						: label('Individual')
				}}
			</span>
		</header>

		<!-- OK state — read-only profile, optional pipelinq link. -->
		<div
			v-if="state === 'ok'"
			class="pipelinq-profile-card__body"
			data-testid="pipelinq-profile-body">
			<dl class="pipelinq-profile-card__fields">
				<!-- Vue 3 requires the v-for key on the <template> itself. -->
				<template v-for="field in fields" :key="field.key">
					<dt class="pipelinq-profile-card__label">
						{{ label(field.label) }}
					</dt>
					<dd
						class="pipelinq-profile-card__value"
						:class="{
							'pipelinq-profile-card__value--emphasis':
								field.emphasis === true,
						}"
						:data-testid="`pipelinq-profile-${field.key}`">
						<a
							v-if="field.href"
							:href="field.href"
							class="pipelinq-profile-card__link">
							{{ field.value }}
						</a>
						<span v-else>{{ field.value }}</span>
					</dd>
				</template>
			</dl>

			<a
				v-if="pipelinqLink"
				class="pipelinq-profile-card__external"
				:href="pipelinqLink"
				target="_blank"
				rel="noopener noreferrer"
				data-testid="pipelinq-profile-external-link">
				{{ label('Open in pipelinq') }}
			</a>

			<p class="pipelinq-profile-card__readonly-hint">
				{{ label('Edit customer details in pipelinq') }}
			</p>
		</div>

		<!-- Not-found state — show the looked-up id so the operator can
		     reconcile with pipelinq. -->
		<div
			v-else-if="state === 'notfound'"
			class="pipelinq-profile-card__fallback"
			data-testid="pipelinq-profile-notfound">
			<p>{{ label('Customer not found') }}</p>
			<p v-if="pipelinqContactId" class="pipelinq-profile-card__notfound-id">
				{{ label('Looked up id') }}: <code>{{ pipelinqContactId }}</code>
			</p>
		</div>

		<!-- Error state — sanitised message from the controller. -->
		<div
			v-else-if="state === 'error'"
			class="pipelinq-profile-card__fallback pipelinq-profile-card__fallback--error"
			data-testid="pipelinq-profile-error">
			<p>{{ contactError || label('Unable to load customer data') }}</p>
		</div>

		<!-- Unlinked state — booking has no pipelinqContactId. -->
		<div
			v-else
			class="pipelinq-profile-card__fallback"
			data-testid="pipelinq-profile-unlinked">
			<p>{{ label('Customer not linked to pipelinq') }}</p>
		</div>
	</section>
</template>

<script>
import {
	buildPipelinqLink,
	buildProfileFields,
	classifyContact,
	selectProfileState,
} from '../../composables/usePipelinqProfile.js'

export default {
	name: 'PipelinqProfileCard',
	props: {
		/**
		 * Full slice-05 BookingDetailController response. The card pulls
		 * `contact`, `contactError`, and `notLinkedToPipelinq` out of it.
		 */
		payload: {
			type: Object,
			required: true,
		},

		/**
		 * Booking row (slice-05 `booking` field). Used only for the
		 * "looked up id" hint in the not-found fallback.
		 */
		booking: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * Optional pipelinq base URL — when set, the card renders a
		 * "Open in pipelinq" link (opens in a new tab). Sourced from
		 * the SettingsService config in the parent view.
		 */
		pipelinqBaseUrl: {
			type: String,
			default: '',
		},
	},

	computed: {
		state() {
			return selectProfileState(this.payload)
		},

		kind() {
			return classifyContact(this.payload?.contact)
		},

		fields() {
			return buildProfileFields(this.payload?.contact)
		},

		contactError() {
			return this.payload?.contactError || ''
		},

		pipelinqContactId() {
			return String(this.booking?.pipelinqContactId || '').trim()
		},

		pipelinqLink() {
			return buildPipelinqLink(
				this.pipelinqBaseUrl,
				this.payload?.contact?.externalId,
			)
		},
	},

	methods: {
		label(key) {
			if (typeof t === 'function') {
				return t('shillinq', key)
			}
			return key
		},
	},
}
</script>

<style scoped>
.pipelinq-profile-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border, #d5d5d5);
	border-radius: var(--border-radius-large, 8px);
	padding: 16px;
	margin-bottom: 16px;
}

.pipelinq-profile-card__header {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 12px;
}

.pipelinq-profile-card__title {
	margin: 0;
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.pipelinq-profile-card__kind {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast, #555);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.pipelinq-profile-card__fields {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin: 0;
}

.pipelinq-profile-card__label {
	font-weight: 500;
	color: var(--color-text-maxcontrast, #555);
}

.pipelinq-profile-card__value {
	margin: 0;
	color: var(--color-main-text);
	overflow-wrap: break-word;
}

.pipelinq-profile-card__value--emphasis {
	font-weight: 700;
}

.pipelinq-profile-card__link {
	color: var(--color-primary-element, #0082c9);
	text-decoration: none;
}

.pipelinq-profile-card__link:hover,
.pipelinq-profile-card__link:focus {
	text-decoration: underline;
}

.pipelinq-profile-card__external {
	display: inline-block;
	margin-top: 12px;
	color: var(--color-primary-element, #0082c9);
	text-decoration: none;
}

.pipelinq-profile-card__external::after {
	content: ' \2197';
}

.pipelinq-profile-card__external:hover,
.pipelinq-profile-card__external:focus {
	text-decoration: underline;
}

.pipelinq-profile-card__readonly-hint {
	margin: 12px 0 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast, #777);
	font-style: italic;
}

.pipelinq-profile-card__fallback {
	color: var(--color-text-maxcontrast, #555);
}

.pipelinq-profile-card__fallback--error {
	color: var(--color-error, #b00);
}

.pipelinq-profile-card__notfound-id {
	margin: 4px 0 0;
	font-size: 0.85rem;
}

.pipelinq-profile-card__notfound-id code {
	background: var(--color-background-dark, #f0f0f0);
	padding: 1px 4px;
	border-radius: 3px;
}
</style>
