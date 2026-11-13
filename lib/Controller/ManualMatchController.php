<?php

/**
 * Matches a bank line by hand.
 *
 * POST /api/v1/bank-lines/{lineId}/match with either `targets` (invoice ids)
 * or `ledgerAccount` (`accountNumber`, optional `vatRate` and
 * `vatAccountNumber`, `description`). A line outside the caller's
 * administrations answers 404, the same as a line that does not exist.
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
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Bank\ManualMatchRefusedException;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Manual bank line matching endpoint.
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 */
class ManualMatchController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request     The request.
	 * @param ManualMatchService           $service     The matching service.
	 * @param AdministrationContextService $context     Administration access check.
	 * @param IUserSession                 $userSession The signed-in user.
	 * @param IL10N                        $l10n        Translations for refusals.
	 * @param LoggerInterface              $logger      Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ManualMatchService $service,
		private readonly AdministrationContextService $context,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Match one bank line to invoices or to a ledger account.
	 *
	 * @param string $lineId The line's uuid or `lineId`.
	 *
	 * @return JSONResponse 200 with the confirmed match, 404, or 422 with a message.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
	 */
	#[NoAdminRequired]
	public function match(string $lineId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$line = $this->accessibleLine(lineId: $lineId);
		if ($line === null) {
			return $this->notFound();
		}

		try {
			return $this->bookedOrMatched(line: $line, user: $user);
		} catch (ManualMatchRefusedException $e) {
			return $this->refused(e: $e);
		} catch (Throwable $e) {
			return $this->matchFailed(lineId: $lineId, e: $e);
		}
	}//end match()

	/**
	 * The line, when it exists and the caller may access its administration.
	 * ADR-005: the line's administration decides; a foreign line is masked as absent.
	 *
	 * @param string $lineId The line's uuid or `lineId`.
	 *
	 * @return array<string,mixed>|null Null for an unknown or foreign line.
	 */
	private function accessibleLine(string $lineId): ?array {
		try {
			$line = $this->service->findLine(lineId: $lineId);
		} catch (OutOfBoundsException $e) {
			return null;
		}

		if ($this->context->canAccess(administrationId: (string)($line['administrationId'] ?? '')) === false) {
			return null;
		}

		return $line;
	}//end accessibleLine()

	/**
	 * Book the line to a ledger account when one is given, else match it to the selected invoices.
	 *
	 * @param array<string,mixed> $line The accessible line.
	 * @param IUser               $user The signed-in user (the actor).
	 *
	 * @return JSONResponse
	 */
	private function bookedOrMatched(array $line, IUser $user): JSONResponse {
		$targets = $this->request->getParam('targets');
		$ledger = $this->request->getParam('ledgerAccount');
		if (is_array($ledger) === true && $ledger !== []) {
			return new JSONResponse(data: $this->service->bookToLedger(line: $line, ledger: $ledger, actor: $user->getUID()));
		}

		if (is_array($targets) === false) {
			return new JSONResponse(data: ['message' => $this->l10n->t('Select at least one invoice.')], statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse(data: $this->service->matchInvoices(line: $line, targetIds: $targets, actor: $user->getUID()));
	}//end bookedOrMatched()

	/**
	 * The 422 for a match the service refused, in the caller's language.
	 *
	 * @param ManualMatchRefusedException $e The refusal.
	 *
	 * @return JSONResponse
	 */
	private function refused(ManualMatchRefusedException $e): JSONResponse {
		return new JSONResponse(
			data: ['message' => $this->l10n->t($e->getTemplate(), $e->getParameters())],
			statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
		);
	}//end refused()

	/**
	 * Log an unexpected failure and answer 500 without detail.
	 *
	 * @param string    $lineId The line's uuid or `lineId`.
	 * @param Throwable $e      The failure.
	 *
	 * @return JSONResponse
	 */
	private function matchFailed(string $lineId, Throwable $e): JSONResponse {
		$this->logger->error('ManualMatchController: match failed', ['lineId' => $lineId, 'exception' => $e->getMessage()]);

		return new JSONResponse(
			data: ['message' => $this->l10n->t('The bank line could not be matched.')],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end matchFailed()

	/**
	 * The masked 404.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(data: ['message' => $this->l10n->t('Bank line not found')], statusCode: Http::STATUS_NOT_FOUND);

	}//end notFound()
}//end class
