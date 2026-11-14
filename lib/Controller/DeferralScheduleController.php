<?php

/**
 * Deferral Schedule Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Spread over periods" on an AP or AR invoice line (ledger-period-end
 * REQ-LPE-004): preview the releases, then create the schedule, which posts
 * the entry moving the total to the balance account.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Exception\DeferralRefusedException;
use OCA\Shillinq\Service\Deferral\DeferralService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * REST surface for creating deferral schedules.
 *
 * Authenticated (#[NoAdminRequired]) and authorised per administration here,
 * through AdministrationContextService::canAccess(): a foreign administration
 * is answered 404, the same as an unknown one (ADR-005).
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
 */
class DeferralScheduleController extends Controller {

	/**
	 * The request fields a schedule is made from.
	 */
	private const FIELDS = [
		'administrationId',
		'sourceReference',
		'description',
		'kind',
		'resultAccount',
		'balanceAccount',
		'totalCents',
		'periodFrom',
		'periodTo',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request     The request.
	 * @param DeferralService                       $deferrals   Creates the schedule.
	 * @param AdministrationContextServiceInterface $context     Membership guard (REQ-MA-001).
	 * @param IUserSession                          $userSession The signed-in user.
	 * @param IL10N                                 $l10n        Translations for refusals.
	 * @param LoggerInterface                       $logger      Logs unexpected failures.
	 */
	public function __construct(
		IRequest $request,
		private readonly DeferralService $deferrals,
		private readonly AdministrationContextServiceInterface $context,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * POST the releases a request would create, without writing anything.
	 *
	 * @return JSONResponse 200 with the schedule; 401, 404 or 422 with a message.
	 *
	 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
	 */
	#[NoAdminRequired]
	public function preview(): JSONResponse {
		return $this->handle(action: fn (array $input): array => $this->deferrals->preview(input: $input), created: false);

	}//end preview()

	/**
	 * POST create the schedule and post its opening entry.
	 *
	 * @return JSONResponse 201 with the schedule; 401, 404, 409 or 422 with a message.
	 *
	 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		return $this->handle(action: fn (array $input): array => $this->deferrals->spread(input: $input), created: true);

	}//end create()

	/**
	 * Check the caller, then run the action on the request's fields.
	 *
	 * @param callable $action  fn(array $input): array.
	 * @param bool     $created Whether success is a 201.
	 *
	 * @return JSONResponse
	 */
	private function handle(callable $action, bool $created): JSONResponse {
		if ($this->requireUser() === false) {
			return $this->message(text: 'Not logged in', status: Http::STATUS_UNAUTHORIZED);
		}

		$input = $this->input();
		if ($this->context->canAccess(administrationId: trim((string)$input['administrationId'])) === false) {
			return $this->message(text: 'Administration not found', status: Http::STATUS_NOT_FOUND);
		}

		try {
			$status = Http::STATUS_OK;
			if ($created === true) {
				$status = Http::STATUS_CREATED;
			}

			return new JSONResponse(data: ['data' => $action($input)], statusCode: $status);
		} catch (DeferralRefusedException $e) {
			return $this->message(text: $e->getMessage(), status: $e->getStatus());
		} catch (Throwable $e) {
			$this->logger->error('DeferralScheduleController: spreading failed', ['exception' => $e->getMessage()]);
			return $this->message(text: 'The invoice line could not be spread.', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end handle()

	/**
	 * The schedule fields of the request; non-scalar values become empty.
	 *
	 * @return array<string,mixed>
	 */
	private function input(): array {
		$input = [];
		foreach (self::FIELDS as $field) {
			$value = $this->request->getParam($field, '');
			if (is_scalar($value) === false) {
				$value = '';
			}

			$input[$field] = $value;
		}

		return $input;

	}//end input()

	/**
	 * Authorization body-guard: the in-body counterpart to #[NoAdminRequired].
	 *
	 * @return bool True when a user is signed in.
	 */
	private function requireUser(): bool {
		return $this->userSession->getUser() !== null;

	}//end requireUser()

	/**
	 * A translated message response.
	 *
	 * @param string $text   The English source string.
	 * @param int    $status The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private function message(string $text, int $status): JSONResponse {
		return new JSONResponse(data: ['message' => $this->l10n->t($text)], statusCode: $status);

	}//end message()
}//end class
