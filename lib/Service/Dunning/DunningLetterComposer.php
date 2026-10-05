<?php

/**
 * Dunning letter composer.
 *
 * Composes what a dunning run sends and records: the voluntary contribution's
 * own letter (D28), the template a stage falls back to (DunningTemplateRegistry)
 * and the DunningRun record itself. Taken out of DunningRunService, which sat
 * exactly at phpmd's 1300-line class limit, so the orchestration keeps the
 * persistence and the guards and this class keeps the composition.
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
 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/bookkeeping-credit-control-dunning/spec.md (REQ-CCD-017)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use DateTimeImmutable;
use OCP\IAppConfig;

/**
 * Prepares a run's letter and composes its DunningRun record.
 *
 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/bookkeeping-credit-control-dunning/spec.md (REQ-CCD-017)
 */
final class DunningLetterComposer {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config, for the registry's per-stage override.
	 * @param VoluntaryContributionPolicy $voluntary The one-reminder cap on a voluntary contribution.
	 * @param VoluntaryReminderTemplate $reminder The voluntary contribution's own reminder letter.
	 * @param DunningTemplateRegistry|null $templates The default template per stage; built over $appConfig when absent.
	 * @param DunningStageRenderer|null $renderer The stage's subject and body; built over the registry when absent.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly VoluntaryContributionPolicy $voluntary = new VoluntaryContributionPolicy(),
		private readonly VoluntaryReminderTemplate $reminder = new VoluntaryReminderTemplate(),
		private readonly ?DunningTemplateRegistry $templates = null,
		private readonly ?DunningStageRenderer $renderer = null,
	) {
	}//end __construct()

	/**
	 * Address the stage to the customer and render its text.
	 *
	 * The recipient is the customer's email address and legal name unless the
	 * caller named one. The subject and body are the stage's (the one the tick
	 * chose, else the named ladder's), in the customer's language with the
	 * merge fields filled, unless the caller already rendered them (the
	 * voluntary contribution's own letter does).
	 *
	 * @param array<string,mixed> $params  The prepared params.
	 * @param array<string,mixed> $invoice The invoice, or [] when it was not found.
	 * @param callable(string, string, ?string): (array<string,mixed>|null) $find Looks up one record by schema, id and fallback property.
	 *
	 * @return array<string,mixed> The params with recipient and rendered text.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.3
	 */
	public function address(array $params, array $invoice, callable $find): array {
		$customerId = (string)($invoice['customerReference'] ?? ($invoice['customerId'] ?? ''));
		$customer   = [];
		if ($customerId !== '') {
			$customer = ($find('CustomerMaster', $customerId, null) ?? []);
		}

		$params['recipientEmail'] = ($params['recipientEmail'] ?? ($customer['email'] ?? null));
		$params['recipientName']  = ($params['recipientName'] ?? ($customer['legalName'] ?? ($customer['tradeName'] ?? null)));
		if ((string)($params['renderedSubject'] ?? '') !== '' && (string)($params['renderedBody'] ?? '') !== '') {
			return $params;
		}

		$stage    = $this->stageOf(params: $params, find: $find);
		$renderer = ($this->renderer ?? new DunningStageRenderer(registry: ($this->templates ?? new DunningTemplateRegistry(appConfig: $this->appConfig))));
		$text     = $renderer->render(
			stage: $stage,
			invoice: $invoice,
			customer: $customer,
			values: ['incassokosten' => ($params['collectionCostAmount'] ?? null), 'rente' => ($params['interestAmount'] ?? null)]
		);
		$params['renderedSubject'] = $text['subject'];
		$params['renderedBody']    = $text['body'];

		return $params;
	}//end address()

	/**
	 * The ladder stage a run sends: the one the tick chose (an override's
	 * stage included), else the stage of the named ladder, else only its
	 * number, so the renderer falls back to the default text.
	 *
	 * @param array<string,mixed> $params The run's params.
	 * @param callable(string, string, ?string): (array<string,mixed>|null) $find Looks up one record by schema, id and fallback property.
	 *
	 * @return array<string,mixed> The stage.
	 */
	private function stageOf(array $params, callable $find): array {
		$stageNr = (int)($params['stageNr'] ?? 1);
		$stages  = [];
		if (is_array($params['stage'] ?? null) === true) {
			$stages = [$params['stage']];
		} else if ((string)($params['ladderId'] ?? '') !== '') {
			$stages = (array)(($find('DunningLadder', (string)$params['ladderId'], 'slug') ?? [])['stages'] ?? []);
		}

		foreach ($stages as $stage) {
			if (is_array($stage) === true && (int)($stage['nr'] ?? 0) === $stageNr) {
				return $stage;
			}
		}

		return ['nr' => $stageNr];
	}//end stageOf()

	/**
	 * Prepare the run's params for the invoice's letter.
	 *
	 * A declined contribution is refused, a voluntary one runs once, without
	 * costs, in its own letter (REQ-SCON-008, REQ-SCON-012, REQ-SCON-014). An
	 * ordinary invoice passes through, and the run count is only read when the
	 * cap needs it.
	 *
	 * @param array<string,mixed>|null $invoice The invoice, or null when it was not found.
	 * @param array<string,mixed> $params The run's params.
	 * @param callable(): int $runsSoFar How many runs the invoice already had.
	 *
	 * @return array<string,mixed> The prepared params.
	 *
	 * @throws \RuntimeException When the contribution was declined or its one reminder was sent.
	 *
	 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/bookkeeping-credit-control-dunning/spec.md (REQ-CCD-017)
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-012)
	 */
	public function prepare(?array $invoice, array $params, callable $runsSoFar): array {
		if ($this->voluntary->isVoluntary(invoice: $invoice) === false && $this->voluntary->isDeclined(invoice: $invoice) === false) {
			return $params;
		}

		return $this->voluntary->prepareRun(invoice: $invoice, params: $params, runsSoFar: (int)$runsSoFar(), reminder: $this->reminder);
	}//end prepare()

	/**
	 * Compose the executed DunningRun record from the prepared params.
	 *
	 * The template is the one the caller, the ladder stage or the voluntary
	 * letter named, else the registry's default for the stage (REQ-CCD-016).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $invoiceId Invoice FK.
	 * @param array<string,mixed> $params The prepared params.
	 * @param DateTimeImmutable $now The execution moment.
	 *
	 * @return array<string,mixed> The DunningRun record, not yet dispatched or saved.
	 *
	 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/bookkeeping-credit-control-dunning/spec.md (REQ-CCD-017)
	 * @spec openspec/changes/billing-inherited-defects/specs/bookkeeping-credit-control-dunning/spec.md (REQ-CCD-016)
	 */
	public function compose(string $administrationId, string $invoiceId, array $params, DateTimeImmutable $now): array {
		$templates = ($this->templates ?? new DunningTemplateRegistry(appConfig: $this->appConfig));

		return [
			'invoiceId' => $invoiceId,
			'ladderId' => (string)($params['ladderId'] ?? ''),
			'stageNr' => (int)($params['stageNr'] ?? 1),
			'executedOn' => $now->format(DATE_ATOM),
			'channel' => (string)($params['channel'] ?? 'EMAIL'),
			'recipientEmail' => ($params['recipientEmail'] ?? null),
			'recipientName' => ($params['recipientName'] ?? null),
			'recipientAddress' => ($params['recipientAddress'] ?? null),
			'templateId' => $templates->resolve(params: $params),
			'renderedSubject' => ($params['renderedSubject'] ?? null),
			'renderedBody' => ($params['renderedBody'] ?? null),
			'renderedPdfHash' => ($params['renderedPdfHash'] ?? null),
			'deliveryStatus' => 'PENDING',
			'openTracking' => ($params['openTracking'] ?? null),
			'postageStatus' => ($params['postageStatus'] ?? null),
			'digitalSignature' => ($params['digitalSignature'] ?? null),
			'invoiceAmount' => (float)($params['invoiceAmount'] ?? 0.0),
			'collectionCostAmount' => ($params['collectionCostAmount'] ?? null),
			'interestAmount' => ($params['interestAmount'] ?? null),
			'administrationId' => $administrationId,
			'lifecycleState' => 'executed',
		];
	}//end compose()
}//end class
