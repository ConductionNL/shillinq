/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Minimal @nextcloud/vue stub for the offline Vitest suite. The W8
 * External-Adapters SFCs import a handful of presentational wrappers
 * (NcAppContent / NcButton / NcLoadingIcon). The unit tests exercise the
 * component's `methods` / `computed` logic, never its template, so the stubs
 * only need to be valid (empty) Vue component option objects.
 */

export const NcAppContent = { name: 'NcAppContent', render: () => null }
export const NcButton = { name: 'NcButton', render: () => null }
export const NcLoadingIcon = { name: 'NcLoadingIcon', render: () => null }
// BbvLinkerFilterBar (#866/#862) renders one NcSelect per declared facet.
export const NcSelect = { name: 'NcSelect', render: () => null }
