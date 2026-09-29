<?php

/**
 * Commitment Year End Controller
 *
 * The endpoints behind "Carry open commitments to next year" on the
 * commitments register and "Mark as last invoice" on a supplier invoice
 * (planning-commitment-year-end, REQ-PCYE-003, REQ-PCYE-004). The carry-over
 * is for a controller or owner of the administration; marking the last
 * invoice for anyone who may post in it.
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
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DomainException;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Commitment\CommitmentCarryOverService;
use OCA\Shillinq\Service\Commitment\CommitmentInvoicing;
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
 * Year-end carry-over and last-invoice endpoints.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
class CommitmentYearEndController extends Controller {
	/**
	 * Administration roles that may carry commitments over.
	 *
	 * @var array<int,string>
	 */
	private const CARRY_OVER_ROLES = ['controller', 'eigenaar'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request   The request.
	 * @param CommitmentCarryOverService   $carryOver The carry-over.
	 * @param CommitmentInvoicing          $invoicing The last-invoice close.
	 * @param AdministrationContextService $context   Administration access and roles.
	 * @param IUserSession                 $session   The signed-in user.
	 * @param IL10N                        $l10n      Translations.
	 * @param LoggerInterface              $logger    Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly CommitmentCarryOverService $carryOver,
		private readonly CommitmentInvoicing $invoicing,
		private readonly AdministrationContextService $context,
		private readonly IUserSession $session,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * What carrying a year's open commitments over would do.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-3.2
	 */
	#[NoAdminRequired]
	public function previewCarryOver(): JSONResponse {
		$refused = $this->refuseCarryOver();
		if ($refused !== null) {
			return $refused;
		}

		return $this->run(
			operation: fn (): array => $this->carryOver->preview(
				administrationId: $this->administrationId(),
				fromYear: (int)$this->request->getParam('fromYear', 0)
			)
		);

	}//end previewCarryOver()

	/**
	 * Carry a year's open commitments over.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-3.2
	 */
	#[NoAdminRequired]
	public function carryOver(): JSONResponse {
		$refused = $this->refuseCarryOver();
		if ($refused !== null) {
			return $refused;
		}

		$user = (string)$this->context->currentUserId();
		return $this->run(
			operation: fn (): array => $this->carryOver->execute(
				administrationId: $this->administrationId(),
				fromYear: (int)$this->request->getParam('fromYear', 0),
				user: $user
			)
		);

	}//end carryOver()

	/**
	 * What marking an invoice as the last one releases.
	 *
	 * @param string $id The supplier invoice id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-2.3
	 */
	#[NoAdminRequired]
	public function previewLastInvoice(string $id): JSONResponse {
		$refused = $this->refusePosting();
		if ($refused !== null) {
			return $refused;
		}

		return $this->run(operation: fn (): array => $this->invoicing->lastInvoice(administrationId: $this->administrationId(), invoiceId: $id));

	}//end previewLastInvoice()

	/**
	 * Mark an invoice as the last one and close its commitment.
	 *
	 * @param string $id The supplier invoice id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-2.3
	 */
	#[NoAdminRequired]
	public function markLastInvoice(string $id): JSONResponse {
		$refused = $this->refusePosting();
		if ($refused !== null) {
			return $refused;
		}

		return $this->run(operation: fn (): array => $this->invoicing->markLast(administrationId: $this->administrationId(), invoiceId: $id));

	}//end markLastInvoice()

	/**
	 * Refuse anyone but a controller or owner of the administration.
	 *
	 * @return JSONResponse|null Null when the request may go on.
	 */
	private function refuseCarryOver(): ?JSONResponse {
		$refused = $this->refuseOutsider();
		if ($refused !== null) {
			return $refused;
		}

		$role = '';
		foreach ((array)($this->context->buildContext()['administrations'] ?? []) as $administration) {
			if ((string)($administration['administrationId'] ?? '') === $this->administrationId()) {
				$role = (string)($administration['role'] ?? '');
			}
		}

		if (in_array($role, self::CARRY_OVER_ROLES, true) === false) {
			return $this->message(text: 'Only a controller can carry commitments over.', status: Http::STATUS_FORBIDDEN);
		}

		return null;

	}//end refuseCarryOver()

	/**
	 * Refuse anyone who may not post in the administration.
	 *
	 * @return JSONResponse|null Null when the request may go on.
	 */
	private function refusePosting(): ?JSONResponse {
		$refused = $this->refuseOutsider();
		if ($refused !== null) {
			return $refused;
		}

		if ($this->context->canPostJournalEntry(administrationId: $this->administrationId()) === false) {
			return $this->message(text: 'You may not change invoices in this administration.', status: Http::STATUS_FORBIDDEN);
		}

		return null;

	}//end refusePosting()

	/**
	 * Refuse an anonymous caller or an administration the user may not act in.
	 *
	 * @return JSONResponse|null Null when the request may go on.
	 */
	private function refuseOutsider(): ?JSONResponse {
		if ($this->session->getUser() === null) {
			return $this->message(text: 'Not logged in', status: Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->administrationId();
		if ($administrationId === '' || $this->context->canAccess(administrationId: $administrationId) === false) {
			return $this->message(text: 'Administration not found', status: Http::STATUS_NOT_FOUND);
		}

		return null;

	}//end refuseOutsider()

	/**
	 * The administration the request names.
	 *
	 * @return string
	 */
	private function administrationId(): string {
		return trim((string)$this->request->getParam('administrationId', ''));

	}//end administrationId()

	/**
	 * Run an operation, translating a refusal to 422.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return JSONResponse
	 */
	private function run(callable $operation): JSONResponse {
		try {
			return new JSONResponse(data: $operation());
		} catch (DomainException $e) {
			return $this->message(text: $e->getMessage(), status: Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('CommitmentYearEndController: request failed', ['exception' => $e->getMessage()]);
			return $this->message(text: 'The commitments could not be updated.', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end run()

	/**
	 * A translated message response.
	 *
	 * @param string $text   The English source string.
	 * @param int    $status The status.
	 *
	 * @return JSONResponse
	 */
	private function message(string $text, int $status): JSONResponse {
		return new JSONResponse(data: ['message' => $this->l10n->t($text)], statusCode: $status);

	}//end message()
}//end class
