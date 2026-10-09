<?php

/**
 * Payment Plan Controller
 *
 * The HTTP surface of receivables-payment-plans: draw up a plan, activate it,
 * record an instalment payment by hand, cancel it, and pay it from a bank
 * line (the plans a line can pay, and the confirmation). Every route answers
 * for the administration of the plan or line it touches; a foreign one is
 * masked as absent (ADR-005).
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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\PaymentPlan\PaymentPlanBankMatcher;
use OCA\Shillinq\PaymentPlan\PaymentPlanRefusedException;
use OCA\Shillinq\PaymentPlan\PaymentPlanService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Bank\ManualMatchRefusedException;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Payment plan endpoints.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */
class PaymentPlanController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request  The request.
	 * @param PaymentPlanService           $plans    The plan flow.
	 * @param PaymentPlanBankMatcher       $matcher  Bank line candidates and booking.
	 * @param ManualMatchService           $lines    Reads bank lines.
	 * @param AdministrationContextService $context  Administration access check.
	 * @param IUserSession                 $session  The signed-in user.
	 * @param IL10N                        $l10n     Translations for refusals.
	 * @param LoggerInterface              $logger   Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly PaymentPlanService $plans,
		private readonly PaymentPlanBankMatcher $matcher,
		private readonly ManualMatchService $lines,
		private readonly AdministrationContextService $context,
		private readonly IUserSession $session,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Draw up a plan in draft. 201 with the plan and its instalments.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return $this->message(text: 'Not logged in', status: Http::STATUS_UNAUTHORIZED);
		}

		$input = $this->request->getParams();
		if ($this->context->canAccess(administrationId: (string)($input['administrationId'] ?? '')) === false) {
			return $this->message(text: 'Not allowed for this administration', status: Http::STATUS_FORBIDDEN);
		}

		$input['includesCharges'] = filter_var(($input['includesCharges'] ?? false), FILTER_VALIDATE_BOOLEAN);
		return $this->run(
			operation: fn (): array => $this->plans->draft(input: $input, actor: $user->getUID()),
			status: Http::STATUS_CREATED
		);

	}//end create()

	/**
	 * Activate a drafted plan.
	 *
	 * @param string $id The plan id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function activate(string $id): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return $this->message(text: 'Not logged in', status: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->owns(planId: $id) === false) {
			return $this->message(text: 'This payment plan does not exist.', status: Http::STATUS_NOT_FOUND);
		}

		return $this->run(operation: fn (): array => $this->plans->activate(planId: $id, actor: $user->getUID()));

	}//end activate()

	/**
	 * Record an instalment payment by hand: amount, paidDate, reference.
	 *
	 * @param string $id The plan id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.2
	 */
	#[NoAdminRequired]
	public function settle(string $id): JSONResponse {
		if ($this->session->getUser() === null) {
			return $this->message(text: 'Not logged in', status: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->owns(planId: $id) === false) {
			return $this->message(text: 'This payment plan does not exist.', status: Http::STATUS_NOT_FOUND);
		}

		$paidDate = (string)$this->request->getParam('paidDate', '');
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidDate) !== 1) {
			return $this->message(text: 'Enter the date the payment arrived.', status: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$amount = (float)$this->request->getParam('amount', 0);
		return $this->run(operation: fn (): array => $this->plans->receive(planId: $id, amount: $amount, paidDate: $paidDate, source: 'by-hand'));

	}//end settle()

	/**
	 * Cancel a plan and resume its dunning pauses.
	 *
	 * @param string $id The plan id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function cancel(string $id): JSONResponse {
		if ($this->session->getUser() === null) {
			return $this->message(text: 'Not logged in', status: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->owns(planId: $id) === false) {
			return $this->message(text: 'This payment plan does not exist.', status: Http::STATUS_NOT_FOUND);
		}

		$reason = trim((string)$this->request->getParam('reason', ''));
		return $this->run(operation: fn (): array => $this->plans->cancel(planId: $id, reason: $reason));

	}//end cancel()

	/**
	 * The plans a bank line can pay, best first.
	 *
	 * @param string $lineId The line's uuid or lineId.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.3
	 */
	#[NoAdminRequired]
	public function lineCandidates(string $lineId): JSONResponse {
		if ($this->session->getUser() === null) {
			return $this->message(text: 'Not logged in', status: Http::STATUS_UNAUTHORIZED);
		}

		$line = $this->line(lineId: $lineId);
		if ($line === null) {
			return $this->message(text: 'This bank line does not exist.', status: Http::STATUS_NOT_FOUND);
		}

		$candidates = array_map(
			static fn (array $candidate): array => [
				'planId' => (string)$candidate['plan']['id'],
				'planNumber' => (string)($candidate['plan']['planNumber'] ?? ''),
				'customerName' => (string)($candidate['plan']['customerName'] ?? ''),
				'nextDueDate' => $candidate['plan']['nextDueDate'] ?? null,
				'nextDueAmount' => $candidate['plan']['nextDueAmount'] ?? null,
				'confidence' => $candidate['confidence'],
				'reason' => $candidate['reason'],
			],
			$this->matcher->candidates(line: $line)
		);

		return new JSONResponse(data: ['candidates' => $candidates]);

	}//end lineCandidates()

	/**
	 * Pay a plan from a bank line the bookkeeper confirmed: lineId.
	 *
	 * @param string $id The plan id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.3
	 */
	#[NoAdminRequired]
	public function payFromLine(string $id): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return $this->message(text: 'Not logged in', status: Http::STATUS_UNAUTHORIZED);
		}

		$line = $this->line(lineId: (string)$this->request->getParam('lineId', ''));
		if ($line === null || $this->owns(planId: $id) === false) {
			return $this->message(text: 'This payment plan does not exist.', status: Http::STATUS_NOT_FOUND);
		}

		$reason = sprintf('Payment plan instalment confirmed by %s', $user->getUID());
		return $this->run(operation: fn (): array => $this->matcher->payFromLine(planId: $id, line: $line, actor: $user->getUID(), reason: $reason));

	}//end payFromLine()

	/**
	 * Whether the plan exists in an administration the user may act in.
	 *
	 * @param string $planId The plan id.
	 *
	 * @return bool
	 */
	private function owns(string $planId): bool {
		try {
			$plan = $this->plans->plan(planId: $planId);
		} catch (PaymentPlanRefusedException $e) {
			return false;
		}

		return $this->context->canAccess(administrationId: (string)($plan['administrationId'] ?? ''));

	}//end owns()

	/**
	 * A bank line of an administration the user may act in, or null.
	 *
	 * @param string $lineId The line's uuid or lineId.
	 *
	 * @return array<string,mixed>|null
	 */
	private function line(string $lineId): ?array {
		try {
			$line = $this->lines->findLine(lineId: $lineId);
		} catch (OutOfBoundsException $e) {
			return null;
		}

		if ($this->context->canAccess(administrationId: (string)($line['administrationId'] ?? '')) === false) {
			return null;
		}

		return $line;

	}//end line()

	/**
	 * Run an operation, translating a refusal into 422.
	 *
	 * @param callable $operation The operation, answering an array.
	 * @param int      $status    The status on success.
	 *
	 * @return JSONResponse
	 */
	private function run(callable $operation, int $status = Http::STATUS_OK): JSONResponse {
		try {
			return new JSONResponse(data: $operation(), statusCode: $status);
		} catch (PaymentPlanRefusedException | ManualMatchRefusedException $e) {
			return new JSONResponse(
				data: ['message' => $this->l10n->t($e->getTemplate(), $e->getParameters())],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (Throwable $e) {
			$this->logger->error('PaymentPlanController: request failed', ['exception' => $e->getMessage()]);
			return $this->message(text: 'The payment plan could not be updated.', status: Http::STATUS_INTERNAL_SERVER_ERROR);
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
