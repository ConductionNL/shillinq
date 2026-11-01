/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import type { DefineComponent } from 'vue'
import type { BookingWidgetConfig } from './index.js'

/** The init() config without its mount target: the component is the mount. */
export type BookingWidgetProps = Omit<BookingWidgetConfig, 'containerId' | 'element'>

declare const BookingWidget: DefineComponent<BookingWidgetProps>
export { BookingWidget }
export default BookingWidget
