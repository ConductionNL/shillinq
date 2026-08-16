/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import type { DefineComponent } from 'vue'

export interface BookingWidgetProps {
	businessId: string
	apiBase: string
	apiKey: string
	resourceId?: string
	lang?: string
	primaryColor?: string
	darkMode?: boolean
	translations?: Record<string, string>
}

declare const BookingWidget: DefineComponent<BookingWidgetProps>
export { BookingWidget }
export default BookingWidget
