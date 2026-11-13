<?php

/**
 * Shillinq InstantiateCloseChecklistAction
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Handler for the action declared on the FiscalPeriod `startClose` transition
 * (`open` → `closing`) in `lib/Settings/register.d/bookkeeping-period-close.json`.
 * When the period's close checklist is still empty, it copies the tasks of the
 * administration's close checklist template into `taskChecklistItems`, each
 * unresolved (ledger-period-end REQ-LPE-001).
 *
 * The template is the one for the administration's legal form: a
 * `CloseChecklistTemplate` names the administration type it is for
 * (`administrationTypeId`: BV, NV, eenmanszaak), and an `Administration`
 * carries that type as `legalForm`. When several templates are written for one
 * type, the first by name is used, so the choice does not depend on the order
 * the store returns them in.
 *
 * The items are a copy: a later edit of the template does not change a period
 * that already started closing. A period whose list already holds items keeps
 * them, so running the action again changes nothing. A period without an
 * administration, or whose type has no template, starts closing with the list
 * it had; the mandatory checks of the close itself are unchanged.
 *
 * @category  Lifecycle
 * @package   OCA\Shillinq\Lifecycle\Action
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-period-close/spec.md#requirement-a-period-that-starts-closing-copies-the-checklist-template-req-lpe-001
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Copies the administration's close checklist template onto a period that starts closing.
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-period-close/spec.md#requirement-a-period-that-starts-closing-copies-the-checklist-template-req-lpe-001
 */
class InstantiateCloseChecklistAction implements LifecycleActionInterface {

	/**
	 * The field on FiscalPeriod that holds the checklist.
	 */
	public const ITEMS = 'taskChecklistItems';

	/**
	 * The categories a checklist item may carry; a template task naming none
	 * of these is filed under `other`.
	 */
	public const CATEGORIES = ['ap', 'ar', 'bank', 'expense-claims', 'vat', 'accruals', 'other'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the administration and the templates.
	 * @param RegisterLocation       $settings      The register the objects live in.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $settings,
	) {
	}//end __construct()

	/**
	 * Fill an empty checklist from the administration's template.
	 *
	 * @param array<string, mixed> $objectData   The period as it will be stored.
	 * @param array<string, mixed> $previousData The period before the transition.
	 * @param array<string, mixed> $parameters   The declared parameters (none are read).
	 * @param string               $actionName   The action's declared name.
	 *
	 * @return array<string, mixed> The period, its checklist filled when it was empty.
	 *
	 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-period-close/spec.md#requirement-a-period-that-starts-closing-copies-the-checklist-template-req-lpe-001
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$existing = ($objectData[self::ITEMS] ?? []);
		if (is_array($existing) === true && $existing !== []) {
			return $objectData;
		}

		$template = $this->templateFor(administrationId: trim((string)($objectData['administrationId'] ?? '')));
		if ($template === null) {
			return $objectData;
		}

		$objectData[self::ITEMS] = self::itemsFrom(tasks: (array)($template['tasks'] ?? []));

		return $objectData;
	}//end execute()

	/**
	 * The close checklist template for an administration's legal form, or null.
	 *
	 * @param string $administrationId The administration's id or code.
	 *
	 * @return array<string, mixed>|null The template.
	 *
	 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-period-close/spec.md#requirement-a-period-that-starts-closing-copies-the-checklist-template-req-lpe-001
	 */
	private function templateFor(string $administrationId): ?array {
		if ($administrationId === '') {
			return null;
		}

		$register       = $this->settings->getRegisterSlug();
		$administration = ObjectIdentifier::findOne(
			scoped: $this->objectService->setRegister($register)->setSchema('Administration'),
			id: $administrationId,
			fallbackProperty: 'administrationCode'
		);
		$legalForm      = trim((string)($administration['legalForm'] ?? ''));
		if ($legalForm === '') {
			return null;
		}

		$rows = $this->objectService->setRegister($register)->setSchema('CloseChecklistTemplate')
			->findAll(['filters' => ['administrationTypeId' => $legalForm]]);

		$templates = [];
		foreach ($rows as $row) {
			$template = ObjectIdentifier::recordWithId(candidate: $row);
			if ($template !== null && (string)($template['administrationTypeId'] ?? '') === $legalForm) {
				$templates[] = $template;
			}
		}

		usort($templates, static fn (array $a, array $b): int => strcmp((string)($a['templateName'] ?? ''), (string)($b['templateName'] ?? '')));

		return ($templates[0] ?? null);
	}//end templateFor()

	/**
	 * The checklist items for a template's tasks: one open item per named task.
	 *
	 * @param array<int, mixed> $tasks The template's tasks.
	 *
	 * @return list<array<string, mixed>> The items.
	 *
	 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-period-close/spec.md#requirement-a-period-that-starts-closing-copies-the-checklist-template-req-lpe-001
	 */
	public static function itemsFrom(array $tasks): array {
		$items = [];
		foreach ($tasks as $index => $task) {
			if (is_array($task) === false) {
				continue;
			}

			$description = trim((string)($task['taskName'] ?? ''));
			if ($description === '') {
				continue;
			}

			$id = trim((string)($task['taskId'] ?? ''));
			if ($id === '') {
				$id = 'task-' . ((int)$index + 1);
			}

			$items[] = [
				'id' => $id,
				'category' => self::categoryOf(task: $task),
				'description' => $description,
				'resolved' => false,
				'resolvedAt' => null,
				'resolvedBy' => null,
			];
		}

		return $items;
	}//end itemsFrom()

	/**
	 * A task's category when it names a known one, else `other`.
	 *
	 * @param array<string, mixed> $task The template task.
	 *
	 * @return string The category.
	 */
	private static function categoryOf(array $task): string {
		$category = trim((string)($task['category'] ?? ''));
		if (in_array($category, self::CATEGORIES, true) === true) {
			return $category;
		}

		return 'other';
	}//end categoryOf()
}//end class
