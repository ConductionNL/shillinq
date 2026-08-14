/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * TypeScript declarations for the @conduction/bookings-widget npm package
 * (REQ-WSW-004 method 3). Partners using TypeScript get autocomplete and
 * type-checking for the init() config.
 */

export interface BookingWidgetConfig {
	/** Public partner business id used in widget embeds (e.g. "salon-001"). */
	businessId: string
	/** Shillinq API base URL, e.g. https://shillinq.example.com/index.php/apps/shillinq */
	apiBase: string
	/** Bearer API key (bk_live_...). Shown to the partner once at creation. */
	apiKey: string
	/** DOM id of the mount container (mutually exclusive with `element`). */
	containerId?: string
	/** DOM element to mount into (mutually exclusive with `containerId`). */
	element?: HTMLElement
	/** Logical resource id to book (defaults to "res-001"). */
	resourceId?: string
	/** BCP-47 language code (defaults to "en"). */
	lang?: string
	/** Hex/CSS colour applied to --wsw-primary-color. */
	primaryColor?: string
	/** When true, applies the wsw-widget--dark surface palette. */
	darkMode?: boolean
	/** Override the bundled translation table. */
	translations?: Record<string, string>
}

export interface BookingWidgetInstance {
	$destroy(): void
}

export interface BookingWidgetApi {
	init(_config: BookingWidgetConfig): BookingWidgetInstance | null
	iframeUrl(_config: BookingWidgetConfig): string
}

export const BookingWidget: BookingWidgetApi
export default BookingWidget
