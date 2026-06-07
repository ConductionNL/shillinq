/**
 * npm entry point for the Shillinq self-service booking widget (REQ-WSW-004).
 *
 * Re-exports the framework-native Vue component and the imperative embed API
 * from the app's widget source. Consumers in React/Vue apps import the named
 * exports; the package ships only this thin re-export so the single source of
 * truth stays in `src/widget/`.
 *
 *   import { BookingWidget, SelfServiceWidget, mount } from '@shillinq/booking-widget'
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

export {
	BookingWidget,
	SelfServiceWidget,
	NextcloudBookingWidgetElement,
	mount,
	default,
} from '../src/widget/embed.js'
