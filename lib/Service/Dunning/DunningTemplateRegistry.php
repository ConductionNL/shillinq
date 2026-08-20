<?php

/**
 * Dunning template registry.
 *
 * REQ-CCD-011 / task-28 (shillinq-side seed). The actual docudesk template
 * library (stage 1–5 PDF / e-mail templates with merge-fields) lives in the
 * docudesk project. This registry holds the canonical default `templateId`
 * mapping per stage so the seeded DunningLadder can reference them without
 * the templates being instantiated yet. The mapping is overridable via app
 * config (`dunning.template.stage_N`) so a deployment can swap a template id
 * in production without a code change.
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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-28
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Canonical default `templateId` per dunning stage, overridable via app config.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-28
 */
final class DunningTemplateRegistry {
	/**
	 * Default templateId map per stage, mirroring the docudesk template-library
	 * naming convention (`tpl-stage{N}-{tone}-{lang}`).
	 *
	 * @var array<int,string>
	 */
	private const DEFAULT_TEMPLATE_IDS = [
		1 => 'tpl-stage1-vriendelijk-nl',
		2 => 'tpl-stage2-herinnering-nl',
		3 => 'tpl-stage3-aanmaning-14d-nl',
		4 => 'tpl-stage4-ingebrekestelling-nl',
		5 => 'tpl-stage5-overdracht-incasso-nl',
	];

	/**
	 * Canonical merge-fields callers can interpolate inside the rendered body.
	 *
	 * @var array<int,string>
	 */
	private const MERGE_FIELDS = [
		'klantNaam',
		'factuurNummer',
		'invoiceDate',
		'outstandingAmount',
		'expiryDate',
		'iban',
		'betalingstermijn',
		'incassokosten',
		'rente',
	];

	/**
	 * Per-stage tone label surfaced in the UI ("Toon: vriendelijk → juridisch").
	 *
	 * @var array<int,string>
	 */
	private const TONE_GRADIENT = [
		1 => 'vriendelijk',
		2 => 'zakelijk',
		3 => 'formeel',
		4 => 'juridisch',
		5 => 'juridisch',
	];

	/**
	 * Construct the registry with the app-config the override-lookup uses.
	 *
	 * @param IAppConfig $appConfig App config for per-stage overrides.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Resolve the templateId for a stage, honouring an app-config override.
	 *
	 * @param int $stageNr Stage number (1..5).
	 *
	 * @return string The templateId.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-28
	 */
	public function templateIdForStage(int $stageNr): string {
		$default = (self::DEFAULT_TEMPLATE_IDS[$stageNr] ?? '');
		return $this->appConfig->getValueString(
			Application::APP_ID,
			'dunning.template.stage_' . $stageNr,
			$default
		);

	}//end templateIdForStage()

	/**
	 * Resolve the tone label for a stage.
	 *
	 * @param int $stageNr Stage number.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-28
	 */
	public function toneForStage(int $stageNr): string {
		return (self::TONE_GRADIENT[$stageNr] ?? 'formeel');
	}//end toneForStage()

	/**
	 * Canonical merge-fields the docudesk templates MUST interpolate.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-28
	 */
	public function mergeFields(): array {
		return self::MERGE_FIELDS;
	}//end mergeFields()

	/**
	 * Full registry shape for UI consumption (manifest dashboard widget,
	 * dunning-ladder edit view).
	 *
	 * @return array<int,array{stageNr:int,templateId:string,tone:string}>
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-28
	 */
	public function listAll(): array {
		$rows = [];
		foreach (array_keys(self::DEFAULT_TEMPLATE_IDS) as $stageNr) {
			$rows[] = [
				'stageNr' => $stageNr,
				'templateId' => $this->templateIdForStage(stageNr: $stageNr),
				'tone' => $this->toneForStage(stageNr: $stageNr),
			];
		}

		return $rows;
	}//end listAll()
}//end class
