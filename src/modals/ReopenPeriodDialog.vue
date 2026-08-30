<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ReopenPeriodDialog — modal isolation of the reopen-period confirmation
 flow (REQ-PC-006, Task 10 of bookkeeping-period-close). Triggered by the
 PeriodCloseDetail "Reopen" button when the FiscalPeriod is in state
 `closed`. Captures a mandatory audit-trailed `closeReason` text field
 before the parent posts to /api/period-close/{id}/reopen.

 Modal isolation per hydra gate-13: the dialog lives in its own .vue file
 under src/modals/ and is imported by PeriodCloseDetail.vue. It must
 never be inlined into the parent component.

 The parent owns the network call; this component is presentation +
 input capture only. `cancel` and `confirm(closeReason)` events bubble
 the user intent up so the parent can drive the lifecycle service.

 @spec openspec/changes/bookkeeping-period-close/tasks.md#task-10
-->

<template>
	<div
		v-if="open"
		class="reopen-period-dialog"
		role="dialog"
		aria-modal="true"
		aria-labelledby="reopen-period-dialog-title"
		data-testid="reopen-period-dialog">
		<div class="reopen-period-dialog__panel">
			<header class="reopen-period-dialog__header">
				<h2 id="reopen-period-dialog-title">
					{{ t('shillinq', 'Reopen period') }}
				</h2>
			</header>
			<section class="reopen-period-dialog__body">
				<p v-if="periodName">
					{{ t('shillinq', 'Reopening period:') }}
					<strong>{{ periodName }}</strong>
				</p>
				<p class="reopen-period-dialog__hint">
					{{
						t(
							'shillinq',
							'Provide a close reason — the original close timestamp and actor are preserved in the audit history.',
						)
					}}
				</p>
				<label
					class="reopen-period-dialog__label"
					for="reopen-period-dialog-reason">
					{{ t('shillinq', 'Close reason') }}
					<span class="reopen-period-dialog__required">*</span>
				</label>
				<textarea
					id="reopen-period-dialog-reason"
					ref="reasonInput"
					v-model="closeReason"
					class="reopen-period-dialog__textarea"
					rows="3"
					:disabled="submitting"
					data-testid="reopen-period-dialog-reason"
					:placeholder="
						t(
							'shillinq',
							'e.g. Adjusting journal for tax authority correction',
						)
					" />
				<p
					v-if="error"
					class="reopen-period-dialog__error"
					data-testid="reopen-period-dialog-error">
					{{ error }}
				</p>
			</section>
			<footer class="reopen-period-dialog__footer">
				<button
					type="button"
					class="reopen-period-dialog__btn"
					:disabled="submitting"
					data-testid="reopen-period-dialog-cancel"
					@click="onCancel">
					{{ t('shillinq', 'Cancel') }}
				</button>
				<button
					type="button"
					class="reopen-period-dialog__btn reopen-period-dialog__btn--primary"
					:disabled="!canConfirm"
					data-testid="reopen-period-dialog-confirm"
					@click="onConfirm">
					{{
						submitting
							? t('shillinq', 'Working…')
							: t('shillinq', 'Reopen')
					}}
				</button>
			</footer>
		</div>
	</div>
</template>

<script>
export default {
	name: 'ReopenPeriodDialog',
	props: {
		open: {
			type: Boolean,
			default: false,
		},

		periodName: {
			type: String,
			default: '',
		},

		submitting: {
			type: Boolean,
			default: false,
		},

		error: {
			type: String,
			default: '',
		},
	},

	emits: ['cancel', 'confirm'],
	data() {
		return {
			closeReason: '',
		}
	},

	computed: {
		canConfirm() {
			return !this.submitting && this.closeReason.trim().length > 0
		},
	},

	watch: {
		open(next) {
			if (next === true) {
				this.closeReason = ''
				this.$nextTick(() => {
					if (
						this.$refs.reasonInput
						&& typeof this.$refs.reasonInput.focus === 'function'
					) {
						this.$refs.reasonInput.focus()
					}
				})
			}
		},
	},

	methods: {
		onCancel() {
			if (this.submitting) {
				return
			}
			this.$emit('cancel')
		},

		onConfirm() {
			if (!this.canConfirm) {
				return
			}
			this.$emit('confirm', this.closeReason.trim())
		},
	},
}
</script>

<style scoped>
.reopen-period-dialog {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.4);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 9999;
}

.reopen-period-dialog__panel {
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: var(--border-radius-large);
	padding: 16px 20px;
	width: min(480px, 90vw);
	box-shadow: 0 6px 30px rgba(0, 0, 0, 0.25);
}

.reopen-period-dialog__header h2 {
	margin: 0 0 12px;
}

.reopen-period-dialog__body {
	margin: 0 0 16px;
}

.reopen-period-dialog__hint {
	margin: 4px 0 8px;
	color: var(--color-text-maxcontrast);
}

.reopen-period-dialog__label {
	display: block;
	margin: 8px 0 4px;
	font-weight: 600;
}

.reopen-period-dialog__required {
	color: var(--color-error);
}

.reopen-period-dialog__textarea {
	width: 100%;
	box-sizing: border-box;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 6px 8px;
	font-family: inherit;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.reopen-period-dialog__error {
	margin-top: 8px;
	color: var(--color-error);
}

.reopen-period-dialog__footer {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}

.reopen-period-dialog__btn {
	padding: 6px 14px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	background: var(--color-background-darker);
	color: var(--color-main-text);
	cursor: pointer;
}

.reopen-period-dialog__btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.reopen-period-dialog__btn--primary {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border-color: var(--color-primary);
}
</style>
