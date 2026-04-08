// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createObjectStore } from '@conduction/nextcloud-vue'

export const useDashboardStore = createObjectStore('dashboard', {
	register: 'shillinq',
	schema: 'dashboard',
})
