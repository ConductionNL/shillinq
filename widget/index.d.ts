/**
 * TypeScript definitions for the Shillinq self-service booking widget.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import type { DefineComponent } from 'vue'

/** Embed configuration shared by all mount methods. */
export interface BookingWidgetConfig {
	/** Public business identifier (== administrationId). */
	businessId: string
	/** Per-business API key (Bearer token). */
	apiKey: string
	/** Absolute base URL of the widget API host. */
	apiBase?: string
	/** UI language, e.g. 'en' or 'nl'. */
	lang?: string
	/** Dark mode toggle. */
	dark?: boolean
	/** Override the primary brand colour. */
	primaryColor?: string
	/** Override the font family. */
	fontFamily?: string
	/** Override the border radius. */
	borderRadius?: string
	/** Target container element id (script-tag mode only). */
	containerId?: string
}

/** The framework-native Vue 3 component. */
export const SelfServiceWidget: DefineComponent<{
	businessId: string
	apiKey: string
	apiBase?: string
	lang?: string
	dark?: boolean
}>

/** Imperative script-tag API. */
export const BookingWidget: {
	init(config: BookingWidgetConfig): unknown | null
}

/** Custom element `<nextcloud-booking-widget>`. */
export class NextcloudBookingWidgetElement extends HTMLElement {}

/** Mount the widget into an element. */
export function mount(el: HTMLElement, config: BookingWidgetConfig): unknown | null

export default BookingWidget
