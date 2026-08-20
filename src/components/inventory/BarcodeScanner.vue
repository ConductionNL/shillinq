<!--
  Barcode Scanner

  Reusable scanner component used by all four warehouse operations. Wraps
  the BarcodeDetector helpers in useBarcodeScanner.js and surfaces a manual
  text-entry fallback per REQ-BARCODE-002.

  Props:
    - formats: list of barcode formats (defaults to qr + 1d).
    - fallbackToManual: when true the manual entry box renders even before
      the camera fails (used by the count operation).

  Events:
    - @scan="(value)": emitted when a barcode is decoded or manually entered.
    - @cancel: emitted when the operator dismisses the scanner.

  @spec openspec/changes/inventory-mobile-scanner/tasks.md#T2.6
-->
<template>
	<div class="barcode-scanner" aria-live="polite">
		<div v-if="cameraError" class="barcode-scanner__error" role="status">
			{{ cameraError }}
		</div>

		<div v-if="cameraReady" class="barcode-scanner__viewport">
			<video
				ref="video"
				autoplay
				playsinline
				muted
				:aria-label="
					t('shillinq', 'Live camera preview for barcode scanning')
				" />
			<p class="barcode-scanner__hint">
				{{ t('shillinq', 'Point the camera at the barcode') }}
			</p>
		</div>

		<form class="barcode-scanner__manual" @submit.prevent="handleManualSubmit">
			<label>
				<span>{{ t('shillinq', 'Enter barcode or SKU manually') }}</span>
				<input
					v-model="manualValue"
					type="text"
					:placeholder="t('shillinq', 'Enter barcode or SKU')"
					autocomplete="off"
					inputmode="search"
					:aria-label="t('shillinq', 'Manual barcode or SKU entry')" />
			</label>
			<button type="submit" :disabled="!manualValue">
				{{ t('shillinq', 'Use this code') }}
			</button>
		</form>

		<div class="barcode-scanner__actions">
			<button type="button" @click="$emit('cancel')">
				{{ t('shillinq', 'Cancel') }}
			</button>
		</div>
	</div>
</template>

<script>
import {
	nativeDetectorAvailable,
	releaseStream,
	requestCameraStream,
	startScanLoop,
} from '../../composables/useBarcodeScanner.js'

export default {
	name: 'BarcodeScanner',
	props: {
		formats: {
			type: Array,
			default: () => ['qr_code', 'ean_13', 'code_128', 'code_39', 'code_93'],
		},

		fallbackToManual: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['scan', 'cancel'],
	data() {
		return {
			cameraReady: false,
			cameraError: null,
			manualValue: '',
			stream: null,
			loop: null,
		}
	},

	async mounted() {
		const available = await nativeDetectorAvailable(this.formats)
		if (!available) {
			this.cameraError = this.t(
				'shillinq',
				'No barcode decoder available; use manual entry.',
			)
			return
		}
		try {
			this.stream = await requestCameraStream()
			await this.$nextTick()
			const video = this.$refs.video
			if (video) {
				video.srcObject = this.stream
				this.cameraReady = true
				const handle = startScanLoop(video, { formats: this.formats })
				this.loop = handle
				const result = await handle.promise
				if (result && result.rawValue) {
					this.$emit('scan', result.rawValue)
				}
			}
		} catch (e) {
			this.cameraError = this.t(
				'shillinq',
				'Camera unavailable; use manual entry.',
			)
		}
	},

	beforeUnmount() {
		if (this.loop && typeof this.loop.stop === 'function') {
			this.loop.stop()
		}
		releaseStream(this.stream)
		this.stream = null
	},

	methods: {
		handleManualSubmit() {
			const value = (this.manualValue || '').trim()
			if (!value) {
				return
			}
			this.$emit('scan', value)
		},
	},
}
</script>

<style scoped>
.barcode-scanner {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	padding: var(--default-grid-baseline, 4px);
}

.barcode-scanner__viewport {
	display: flex;
	flex-direction: column;
	align-items: center;
}

.barcode-scanner__viewport video {
	max-width: 100%;
	border-radius: var(--border-radius, 4px);
	background: var(--color-background-dark, #000);
}

.barcode-scanner__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.barcode-scanner__manual {
	display: flex;
	gap: var(--default-grid-baseline, 4px);
	align-items: flex-end;
	flex-wrap: wrap;
}

.barcode-scanner__manual label {
	display: flex;
	flex-direction: column;
	flex: 1 1 200px;
}

.barcode-scanner__error {
	color: var(--color-error);
}

.barcode-scanner__actions {
	display: flex;
	gap: var(--default-grid-baseline, 4px);
	justify-content: flex-end;
}
</style>
