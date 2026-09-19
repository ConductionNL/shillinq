// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shillinq's every-page leaf bootstrap (ADR-019 / ADR-066).
//
// Loaded on every Nextcloud page through
// `\OCP\Util::addInitScript('shillinq', 'shillinq-integration-init')`. Its job
// is to put shillinq's two finance panels on the shared OpenRegister registry
// when the page belongs to ANOTHER app: a dossiq case, an opencatalogi record,
// anything with an object detail page. Nextcloud only loads an app's own
// bundle on that app's routes, so without this entry the panels would exist
// only on shillinq's own pages, where nobody needs them.
//
// Deliberately tiny. It imports the two registrations and nothing else: no
// router, no store, no app shell.

import { registerContractLeaf } from './integrations/registerContractLeaf.js'
import { registerPaymentRequestsLeaf } from './integrations/registerPaymentRequestsLeaf.js'

// Sets __webpack_public_path__ / __webpack_nonce__ for this entry. The panels
// load as lazy chunks, and a chunk fetched from the wrong webroot does not 404
// here: Nextcloud answers the HTML shell with a 200 and the load fails as a
// MIME refusal (see src/setPublicPath.js). It sits last because the linter
// sorts side-effect imports there and because it only has to run before a
// chunk is REQUESTED, which is after every module in this entry has evaluated.
import './setPublicPath.js'

registerPaymentRequestsLeaf()
registerContractLeaf()
