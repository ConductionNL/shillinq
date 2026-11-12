<?php

/**
 * Dunning stage choice
 *
 * The state of choosing the stage the next dunning tick sends for one invoice:
 * the administration and invoice, the base ladder, dispatch overrides and the
 * moment of the tick, and what the choice resolves on the way (days in
 * arrears, ladder, prior runs, stage).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use DateTimeImmutable;

/**
 * Progress of choosing the next dunning stage for one invoice.
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-1.1
 */
final class DunningStageChoice {
	/**
	 * The base ladder; empty resolves it.
	 *
	 * @var string
	 */
	public string $baseLadderId = '';

	/**
	 * Dispatch overrides.
	 *
	 * @var array<string,mixed>
	 */
	public array $params = [];

	/**
	 * Now.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $now = null;

	/**
	 * The invoice id.
	 *
	 * @var string
	 */
	public string $invoiceId = '';

	/**
	 * The customer.
	 *
	 * @var string
	 */
	public string $customerId = '';

	/**
	 * Days past the due date, or null when not overdue.
	 *
	 * @var int|null
	 */
	public ?int $daysInArrears = null;

	/**
	 * The resolved ladder (ladderId, stages).
	 *
	 * @var array<string,mixed>
	 */
	public array $ladder = [];

	/**
	 * The invoice's prior DunningRuns.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $runs = [];

	/**
	 * The chosen stage.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $stage = null;

	/**
	 * The stage selector the choice consults.
	 *
	 * @var DunningStageSelector|null
	 */
	public ?DunningStageSelector $selector = null;

	/**
	 * Start choosing a stage for an invoice.
	 *
	 * @param string              $administrationId Administration scope.
	 * @param array<string,mixed> $invoice          The invoice.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly array $invoice,
	) {
	}//end __construct()

	/**
	 * Read the invoice id and the days in arrears; true when the invoice has
	 * an id and is overdue.
	 *
	 * @return bool Whether the invoice is overdue.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-1.1
	 */
	public function isOverdue(): bool {
		$this->invoiceId = (string)($this->invoice['id'] ?? ($this->invoice['@self']['id'] ?? ''));
		$this->daysInArrears = $this->selector->daysOverdue(invoice: $this->invoice, now: $this->now);
		return $this->invoiceId !== '' && $this->daysInArrears !== null;
	}//end isOverdue()

	/**
	 * Choose the stage the selector finds due on the ladder after the prior
	 * runs; true when there is one.
	 *
	 * @return bool Whether a stage is due.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-1.1
	 */
	public function chooseDueStage(): bool {
		$this->stage = $this->selector->next(stages: $this->ladder['stages'], runs: $this->runs, daysInArrears: (int)$this->daysInArrears, now: $this->now);
		return $this->stage !== null;
	}//end chooseDueStage()

	/**
	 * A voluntary contribution's single reminder: refused once a run exists,
	 * otherwise the ladder's only stage with the cost-free dispatch params.
	 *
	 * @param array<string,mixed> $costFreeParams The dispatch params without costs.
	 *
	 * @return bool False when the reminder was already sent.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-1.1
	 */
	public function useOnlyStageOnce(array $costFreeParams): bool {
		if ($this->runs !== []) {
			return false;
		}

		$this->useOnlyStage();
		$this->params = $costFreeParams;
		return true;
	}//end useOnlyStageOnce()

	/**
	 * Use the ladder's only stage for a voluntary contribution (the
	 * definition when the ladder has it, the due stage otherwise), numbered
	 * as the only stage.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-1.1
	 */
	public function useOnlyStage(): void {
		$stage = ($this->selector->definition(stages: $this->ladder['stages'], stageNr: VoluntaryContributionPolicy::ONLY_STAGE) ?? $this->stage);
		$stage['nr'] = VoluntaryContributionPolicy::ONLY_STAGE;
		$this->stage = $stage;
	}//end useOnlyStage()

	/**
	 * The executeStage() params of the chosen stage, dispatch overrides last.
	 *
	 * @param int $stageNr The chosen stage number.
	 *
	 * @return array<string,mixed> The executeStage() params.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-1.1
	 */
	public function stageParams(int $stageNr): array {
		$params = $this->params;
		$stage = (array)$this->stage;
		return array_merge(
			[
				'invoiceId' => $this->invoiceId,
				'ladderId' => (string)$this->ladder['ladderId'],
				'stageNr' => $stageNr,
				'channel' => (string)($params['channel'] ?? ($stage['channel'] ?? 'EMAIL')),
				'templateId' => (string)($params['templateId'] ?? ($stage['templateId'] ?? '')),
				'invoiceAmount' => (float)($this->invoice['grossAmount'] ?? 0.0),
				'stage' => $stage,
			],
			$params
		);
	}//end stageParams()
}//end class
