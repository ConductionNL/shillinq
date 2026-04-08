// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createObjectStore } from '@conduction/nextcloud-vue'

export const useDataJobStore = createObjectStore('dataJob', {
	register: 'shillinq',
	schema: 'dataJob',
})
