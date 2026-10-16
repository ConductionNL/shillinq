<?php

/**
 * Budget Editing Controller
 *
 * The endpoints behind typing a budget into the grid, spreading a yearly
 * amount, the multi-year page and starting next year's budget
 * (planning-budget-editing, REQ-PBE-001 to REQ-PBE-003). Every request names
 * its administration and is refused outside the administrations the user may
 * act in; amounts are EUR cents.
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
 * @spec openspec/specs/budget-grid-view/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Budget\BudgetEditingService;
use OCA\Shillinq\Budget\BudgetEditRefusedException;
use OCA\Shillinq\Service\AdministrationContextService;
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
 * Budget entry endpoints.
 *
 * @spec openspec/specs/budget-grid-view/spec.md
 */
class BudgetEditingController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request The request.
	 * @param BudgetEditingService         $budgets Budget entry.
	 * @param AdministrationContextService $context Administration access check.
	 * @param IUserSession                 $session The signed-in user.
	 * @param IL10N                        $l10n    Translations for refusals.
	 * @param LoggerInterface              $logger  Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly BudgetEditingService $budgets,
		private readonly AdministrationContextService $context,
		private readonly IUserSession $session,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * The ledger groups of an annual budget with their month amounts.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
	 */
	#[NoAdminRequired]
	public function lines(): JSONResponse {
		$refused = $this->refuse();
		if ($refused !== null) {
			return $refused;
		}

		return $this->run(
			operation: fn (): array => $this->budgets->lines(
				administrationId: $this->param(name: 'administrationId'),
				annualBudgetId: $this->param(name: 'annualBudgetId')
			)
		);

	}//end lines()

	/**
	 * Save one month of a ledger group's manual line.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
	 */
	#[NoAdminRequired]
	public function saveCell(): JSONResponse {
		$refused = $this->refuse();
		if ($refused !== null) {
			return $refused;
		}

		return $this->run(
			operation: fn (): array => $this->budgets->saveCell(
				administrationId: $this->param(name: 'administrationId'),
				annualBudgetId: $this->param(name: 'annualBudgetId'),
				ledgerGroupId: $this->param(name: 'ledgerGroupId'),
				month: (int)$this->request->getParam('month', 0),
				amount: (int)$this->request->getParam('amount', 0),
				expected: (int)$this->request->getParam('expected', 0)
			)
		);

	}//end saveCell()

	/**
	 * Spread a yearly amount over the twelve months of a row.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.2
	 */
	#[NoAdminRequired]
	public function spread(): JSONResponse {
		$refused = $this->refuse();
		if ($refused !== null) {
			return $refused;
		}

		$expected = $this->request->getParam('expected', []);
		if (is_array($expected) === false || count($expected) !== 12) {
			return $this->message(text: 'Send the twelve amounts the row showed.', status: Http::STATUS_BAD_REQUEST);
		}

		return $this->run(
			operation: fn (): array => $this->budgets->spread(
				administrationId: $this->param(name: 'administrationId'),
				annualBudgetId: $this->param(name: 'annualBudgetId'),
				ledgerGroupId: $this->param(name: 'ledgerGroupId'),
				yearly: (int)$this->request->getParam('yearly', 0),
				expected: array_map('intval', array_values($expected))
			)
		);

	}//end spread()

	/**
	 * Ledger groups against the fiscal years that have a budget.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function multiYear(): JSONResponse {
		$refused = $this->refuse();
		if ($refused !== null) {
			return $refused;
		}

		$fromYear = (int)$this->request->getParam('fromYear', (int)date('Y'));
		return $this->run(
			operation: fn (): array => $this->budgets->multiYear(
				administrationId: $this->param(name: 'administrationId'),
				fromYear: $fromYear
			)
		);

	}//end multiYear()

	/**
	 * Start next year's budget from a chosen one with a percentage.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function startNextYear(): JSONResponse {
		$refused = $this->refuse();
		if ($refused !== null) {
			return $refused;
		}

		$percentage = $this->request->getParam('percentage', '');
		if (is_numeric($percentage) === false) {
			return $this->message(text: 'Enter a percentage, for example 3.', status: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return $this->run(
			operation: fn (): array => $this->budgets->startNextYear(
				administrationId: $this->param(name: 'administrationId'),
				annualBudgetId: $this->param(name: 'annualBudgetId'),
				percentage: (float)$percentage
			),
			status: Http::STATUS_CREATED
		);

	}//end startNextYear()

	/**
	 * A refusal for an anonymous caller or an administration the user may not act in.
	 *
	 * @return JSONResponse|null Null when the request may go on.
	 */
	private function refuse(): ?JSONResponse {
		if ($this->session->getUser() === null) {
			return $this->message(text: 'Not logged in', status: Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->param(name: 'administrationId');
		if ($administrationId === '' || $this->context->canAccess(administrationId: $administrationId) === false) {
			return $this->message(text: 'Administration not found', status: Http::STATUS_NOT_FOUND);
		}

		return null;

	}//end refuse()

	/**
	 * A trimmed string parameter.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string
	 */
	private function param(string $name): string {
		return trim((string)$this->request->getParam($name, ''));

	}//end param()

	/**
	 * Run an operation, translating a refusal to 422.
	 *
	 * @param callable $operation The operation.
	 * @param int      $status    The status on success.
	 *
	 * @return JSONResponse
	 */
	private function run(callable $operation, int $status = Http::STATUS_OK): JSONResponse {
		try {
			return new JSONResponse(data: $operation(), statusCode: $status);
		} catch (BudgetEditRefusedException $e) {
			return new JSONResponse(
				data: ['message' => $this->l10n->t($e->getTemplate(), $e->getParameters())],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (Throwable $e) {
			$this->logger->error('BudgetEditingController: request failed', ['exception' => $e->getMessage()]);
			return $this->message(text: 'The budget could not be updated.', status: Http::STATUS_INTERNAL_SERVER_ERROR);
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
