<?php

/**
 * Iv3ReportGenerator reads Account.taskField (Q-shillinq-2).
 *
 * A ledger line without its own task field books to the task field of its
 * account; a line whose account has none falls to 0.0.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting\Generator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md#req-sopr-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\Generator;

use OCA\Shillinq\Reporting\Generator\Iv3ReportGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Pins the IV3 generator's read of the account task field.
 *
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md#req-sopr-011
 */
final class Iv3ReportGeneratorTaskFieldTest extends TestCase {

	/**
	 * A line without a task field lands on its account's task field.
	 *
	 * @return void
	 */
	public function testALineWithoutATaskFieldUsesItsAccountsTaskField(): void {
		$rows = [
			'Account' => [
				['accountNumber' => '8300', 'taskField' => '8.3'],
				['accountNumber' => '4000'],
			],
			'GLLine' => [
				['accountNumber' => '8300', 'side' => 'credit', 'amount' => 245.0],
				['accountNumber' => '4000', 'side' => 'debit', 'amount' => 100.0],
			],
		];
		$objectService = new class($rows) {
			/**
			 * The schema in use.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Construct.
			 *
			 * @param array<string,list<array<string,mixed>>> $rows Rows per schema.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * Pick the register.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * Pick the schema.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * Every row of the schema.
			 *
			 * @param array<string,mixed> $params Ignored.
			 *
			 * @return list<array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				return ($this->rows[$this->schema] ?? []);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$file = (new Iv3ReportGenerator($container, new NullLogger()))->generate(['administrationId' => 'adm-1'], 'csv');

		$lines = array_values(array_filter(explode("\n", $file->content)));
		self::assertContains('8.3,0.00,245.00,245.00', $lines);
		self::assertContains('0.0,100.00,0.00,-100.00', $lines);
	}//end testALineWithoutATaskFieldUsesItsAccountsTaskField()
}//end class
