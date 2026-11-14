<?php

/**
 * Unit tests for DocudeskTemplateSelection: exactly one template in the
 * category is used, and none, several or one without an id refuses with a
 * message that names the report, namespace and candidates.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\Generator\DocudeskTemplateSelection;
use PHPUnit\Framework\TestCase;

/**
 * Template selection never guesses.
 *
 * @covers \OCA\Shillinq\Reporting\Generator\DocudeskTemplateSelection
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DocudeskTemplateSelectionTest extends TestCase {

	/**
	 * The selection under test.
	 *
	 * @return DocudeskTemplateSelection
	 */
	private function selection(): DocudeskTemplateSelection {
		return new DocudeskTemplateSelection(reportType: 'annual-accounts', namespace: 'shillinq');
	}//end selection()

	/**
	 * The one template in the category is chosen; other categories do not count.
	 *
	 * @return void
	 */
	public function testTheOnlyTemplateInTheCategoryIsChosen(): void {
		$rows = [
			['id' => 'tpl-other', 'category' => 'letter'],
			['id' => 'tpl-1', 'category' => 'jaarrekening'],
			['id' => 'tpl-none'],
		];

		$this->assertSame(['templateId' => 'tpl-1', 'error' => null], $this->selection()->single($rows, 'jaarrekening'));
	}//end testTheOnlyTemplateInTheCategoryIsChosen()

	/**
	 * A template without a top-level id is identified by its @self id.
	 *
	 * @return void
	 */
	public function testTheSelfIdIsUsedWhenThereIsNoId(): void {
		$rows = [['@self' => ['id' => 'uuid-7'], 'category' => 'jaarrekening']];

		$this->assertSame(['templateId' => 'uuid-7', 'error' => null], $this->selection()->single($rows, 'jaarrekening'));
	}//end testTheSelfIdIsUsedWhenThereIsNoId()

	/**
	 * No template in the category refuses, naming report, namespace and category.
	 *
	 * @return void
	 */
	public function testNoTemplateRefuses(): void {
		$this->assertSame(
			[
				'templateId' => null,
				'error' => 'No docudesk template found for "annual-accounts" in namespace "shillinq" (category "jaarrekening"); generation is refused.',
			],
			$this->selection()->single([['id' => 'tpl-other', 'category' => 'letter']], 'jaarrekening')
		);
		$this->assertNull($this->selection()->single([], 'jaarrekening')['templateId']);
	}//end testNoTemplateRefuses()

	/**
	 * Several templates in the category refuse and list every candidate, "?" for one without any id.
	 *
	 * @return void
	 */
	public function testSeveralTemplatesRefuseAndListTheCandidates(): void {
		$rows = [
			['id' => 'tpl-1', 'category' => 'jaarrekening'],
			['@self' => ['id' => 'uuid-2'], 'category' => 'jaarrekening'],
			['category' => 'jaarrekening'],
		];

		$this->assertSame(
			[
				'templateId' => null,
				'error' => 'Multiple docudesk templates (tpl-1, uuid-2, ?) for "annual-accounts" in namespace "shillinq"; generation is refused '
					. '(never guess between templates that produce official documents).',
			],
			$this->selection()->single($rows, 'jaarrekening')
		);
	}//end testSeveralTemplatesRefuseAndListTheCandidates()

	/**
	 * A single match without any id refuses.
	 *
	 * @return void
	 */
	public function testASingleMatchWithoutAnIdRefuses(): void {
		$this->assertSame(
			['templateId' => null, 'error' => 'The matched docudesk template has no id.'],
			$this->selection()->single([['category' => 'jaarrekening', 'id' => '']], 'jaarrekening')
		);
	}//end testASingleMatchWithoutAnIdRefuses()
}//end class
