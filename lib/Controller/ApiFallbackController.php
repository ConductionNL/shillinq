<?php

/**
 * Shillinq API fallback controller.
 *
 * Answers any `GET /apps/shillinq/api/...` that matched no declared API route
 * with a real 404, instead of letting it fall through to the SPA catch-all.
 *
 * WHY THIS EXISTS.
 *
 * `appinfo/routes.php` returns `\OCA\OpenRegister\AppHost\Routes::standard()`,
 * whose LAST entry is a `/{path}` catch-all (`dashboard#catchAll`) serving the
 * Vue SPA so browser deep links work. That catch-all does not distinguish a
 * deep link from an API call, so an unmatched `/api/...` request was answered
 * with **HTTP 200 and ~39 KB of HTML**.
 *
 * For a browser that is fine. For `axios.get()` it is a disaster: the promise
 * RESOLVES, `data.results` is `undefined`, the caller renders zero rows, and
 * the component shows its empty state. No exception, no console warning, no
 * log line. Measured on a live instance, a real schema, a retired schema, a
 * nonsense schema and a nonsense path were **indistinguishable** — every one
 * returned 200 + the same HTML shell.
 *
 * That is how eleven components came to fetch
 * `/apps/shillinq/api/openregister/objects/...` — a route this app has never
 * declared — and silently render nothing for as long as they have existed
 * (issue #1209). The bug is not that the calls were wrong; it is that being
 * wrong was indistinguishable from being empty.
 *
 * This route makes that class of mistake LOUD. It is deliberately registered
 * as the LAST entry of the `$extra` array: `Routes::standard()` does
 * `array_merge($canonical, $extra)` and only then appends the catch-all, so
 * every genuine shillinq API route is matched first and only a true miss
 * reaches this.
 *
 * It is a net, not a policy — it adds no behaviour of its own. Deleting it
 * restores the old silent-200, which is why it should not be deleted.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Turns an unmatched shillinq API path into a 404 instead of the SPA shell.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
class ApiFallbackController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string   $appName The app id.
	 * @param IRequest $request The current request.
	 *
	 * @return void
	 */
	public function __construct(string $appName, IRequest $request) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Answer an unmatched `/api/...` path with 404.
	 *
	 * `#[NoAdminRequired]` because this must answer for ordinary users too — a
	 * fallback that 403'd for non-admins would replace one misleading answer
	 * with another.
	 *
	 * The body names the path so the caller sees WHICH url was wrong. That is
	 * the whole value: the previous behaviour told them nothing at all.
	 *
	 * @param string $path The unmatched path below `/api/`.
	 *
	 * @return JSONResponse A 404 naming the unmatched route.
	 *
	 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
	 */
	#[NoAdminRequired]
	public function notFound(string $path = ''): JSONResponse {
		return new JSONResponse(
			[
				'message' => 'No such shillinq API route: api/' . $path,
				'hint' => 'This path matched no declared route in appinfo/routes.php. '
					. 'It is answered with 404 rather than the SPA page so a mistyped or '
					. 'retired API url fails visibly instead of returning an empty list.',
			],
			Http::STATUS_NOT_FOUND
		);

	}//end notFound()
}//end class
