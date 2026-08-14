/* eslint-disable no-undef */
/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Webpack runtime bootstrap — MUST be the first import of every entry point.
 *
 * `@nextcloud/webpack-vue-config` hardcodes `output.publicPath` to
 * `/apps/shillinq/js/`. Shillinq is installed under `custom_apps/`, whose
 * webroot is `/custom_apps/shillinq/js/`, and the wrong path does NOT 404 —
 * Nextcloud answers 200 with `text/html`, so a lazy chunk fetched from it
 * fails with a MIME refusal / `ChunkLoadError` rather than a missing-file
 * error.
 *
 * Vue 2 never surfaced this because the old dependency set emitted no async
 * chunks. The Vue 3 set splits `@nextcloud/dialogs@7`, `@nextcloud/files` and
 * the manifest fragments into 40+ of them, and main.js lazy-loads a fragment
 * on every route change — so every deep navigation depends on this being right.
 *
 * `generateFilePath` resolves the real webroot at runtime. It has to run
 * BEFORE any other module evaluates (ES imports are hoisted and evaluate
 * before the entry body), which is why this lives in its own module that is
 * imported first rather than as a statement at the top of the entry.
 *
 * `__webpack_nonce__` is set for the same reason: Nextcloud's CSP requires a
 * nonce on any dynamically injected <script>, which is exactly what a lazy
 * chunk is.
 */
import { generateFilePath } from '@nextcloud/router'

__webpack_nonce__ = btoa(OC.requestToken)
__webpack_public_path__ = generateFilePath('shillinq', '', 'js/')
