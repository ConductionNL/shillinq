<?php

/**
 * The key-facts rows of the jaarrekening and the BBV-jaarstukken.
 *
 * Both layout goldens render one statement each, so only the enum values those
 * two fixtures happen to carry are pinned: every other `statementType`,
 * `entityType` and `documentType` arm — and the average-employees figure, which
 * is rounded and dropped when it is not a number — renders unasserted. These
 * are the rows an accountant reads first, and a wrong arm there is a wrong
 * document.
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

use OCA\Shillinq\Reporting\Generator\AnnualAccountsReportGenerator;
use OCA\Shillinq\Reporting\Generator\BbvJaarstukkenReportGenerator;
use OCA\Shillinq\Reporting\ReportSection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The key-facts rows of the two statement documents.
 *
 * @covers \OCA\Shillinq\Reporting\Generator\AnnualAccountsReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\BbvJaarstukkenReportGenerator
 * @uses   \OCA\Shillinq\Reporting\Generator\AbstractDocumentReportGenerator
 * @uses   \OCA\Shillinq\Reporting\ReportSection
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class StatementKeyFactsTest extends TestCase {

	/**
	 * Render a generator's body over one stored statement and answer its first
	 * details table (the key facts).
	 *
	 * @param class-string         $class     The generator class.
	 * @param array<string, mixed> $statement The stored statement row.
	 *
	 * @return array<string, string> The key-facts rows.
	 */
	private function keyFacts(string $class, array $statement): array {
		$store = new class ($statement) {

			/**
			 * @param array<string, mixed> $statement The statement.
			 */
			public function __construct(private readonly array $statement) {
			}

			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				return [$this->statement];
			}

			/**
			 * @param string $id The object id.
			 *
			 * @return mixed Always null.
			 */
			public function find(string $id): mixed {
				return null;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($store): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $store;
				}

				throw new RuntimeException('No service ' . $id);
			}
		);

		$section = new ReportSection();
		$build = new ReflectionMethod($class, 'build');
		$build->invoke(new $class($container), $section, []);

		foreach ($section->toArray() as $block) {
			if ($block['type'] === 'detailsTable') {
				return $block['rows'];
			}
		}

		return [];

	}//end keyFacts()

	/**
	 * Every statementType enum value has its own Dutch label; an unknown value
	 * is shown as it is stored rather than silently relabelled.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function statementTypes(): array {
		return [
			'abridged' => ['abridged', 'Verkorte jaarrekening'],
			'consolidated' => ['consolidated', 'Geconsolideerde jaarrekening'],
			'interim' => ['interim', 'Tussentijdse cijfers'],
			'annual' => ['annual', 'Volledige jaarrekening'],
			'unknown' => ['eigen-variant', 'eigen-variant'],
		];

	}//end statementTypes()

	/**
	 * The jaarrekening labels its own statement type.
	 *
	 * @param string $stored The stored statementType.
	 * @param string $label  The label it renders.
	 *
	 * @return void
	 */
	#[DataProvider('statementTypes')]
	public function testTheJaarrekeningLabelsItsStatementType(string $stored, string $label): void {
		$rows = $this->keyFacts(
			AnnualAccountsReportGenerator::class,
			['statementType' => $stored, 'jurisdiction' => 'nl']
		);

		$this->assertSame($label, $rows['Soort jaarrekening']);

	}//end testTheJaarrekeningLabelsItsStatementType()

	/**
	 * A statement without a statementType shows no such row at all — the
	 * details table drops its empty values rather than inventing a type.
	 *
	 * @return void
	 */
	public function testAStatementWithoutATypeShowsNoTypeRow(): void {
		$rows = $this->keyFacts(AnnualAccountsReportGenerator::class, ['jurisdiction' => 'nl']);

		$this->assertArrayNotHasKey('Soort jaarrekening', $rows);
		$this->assertSame('nl', $rows['Jurisdictie']);

	}//end testAStatementWithoutATypeShowsNoTypeRow()

	/**
	 * The average number of employees is rounded to a whole person, read from a
	 * stored number or a numeric string, and omitted when it is neither.
	 *
	 * @return void
	 */
	public function testTheAverageEmployeeCountIsRoundedAndOmittedWhenItIsNotANumber(): void {
		$rounded = $this->keyFacts(AnnualAccountsReportGenerator::class, ['employees' => 12.6]);
		$asText = $this->keyFacts(AnnualAccountsReportGenerator::class, ['employees' => '7']);
		$absent = $this->keyFacts(AnnualAccountsReportGenerator::class, ['jurisdiction' => 'nl']);
		$notANumber = $this->keyFacts(AnnualAccountsReportGenerator::class, ['jurisdiction' => 'nl', 'employees' => 'veel']);

		$this->assertSame('13', $rounded['Gemiddeld personeel']);
		$this->assertSame('7', $asText['Gemiddeld personeel']);
		$this->assertArrayNotHasKey('Gemiddeld personeel', $absent);
		$this->assertArrayNotHasKey('Gemiddeld personeel', $notANumber);

	}//end testTheAverageEmployeeCountIsRoundedAndOmittedWhenItIsNotANumber()

	/**
	 * Every entityType enum value has its own Dutch label; an unknown value is
	 * shown as it is stored.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function entityTypes(): array {
		return [
			'municipality' => ['municipality', 'Gemeente'],
			'province' => ['province', 'Provincie'],
			'water authority' => ['waterAuthority', 'Waterschap'],
			'unknown' => ['gemeenschappelijke-regeling', 'gemeenschappelijke-regeling'],
		];

	}//end entityTypes()

	/**
	 * The BBV-jaarstukken label the kind of public body they are for.
	 *
	 * @param string $stored The stored entityType.
	 * @param string $label  The label it renders.
	 *
	 * @return void
	 */
	#[DataProvider('entityTypes')]
	public function testTheBbvJaarstukkenLabelTheKindOfPublicBody(string $stored, string $label): void {
		$rows = $this->keyFacts(
			BbvJaarstukkenReportGenerator::class,
			['entityType' => $stored, 'documentType' => 'jaarstukken', 'fiscalYear' => '2026']
		);

		$this->assertSame($label, $rows['Soort lichaam']);
		$this->assertSame('Jaarstukken', $rows['Documenttype']);

	}//end testTheBbvJaarstukkenLabelTheKindOfPublicBody()

	/**
	 * A BBV document without a stored documentType is read as jaarstukken; a
	 * budget says so.
	 *
	 * @return void
	 */
	public function testABbvDocumentWithoutATypeIsReadAsJaarstukken(): void {
		$untyped = $this->keyFacts(BbvJaarstukkenReportGenerator::class, ['fiscalYear' => '2026']);
		$budget = $this->keyFacts(BbvJaarstukkenReportGenerator::class, ['documentType' => 'begroting', 'fiscalYear' => '2026']);

		$this->assertSame('Jaarstukken', $untyped['Documenttype']);
		$this->assertSame('Begroting', $budget['Documenttype']);

	}//end testABbvDocumentWithoutATypeIsReadAsJaarstukken()

	/**
	 * A named statement that the store answers as an entity rather than an
	 * array is still read: a JSON-serialisable one through its own
	 * serialisation, any other object through its public fields.
	 *
	 * @return void
	 */
	public function testANamedStatementIsReadFromAnEntityOrAPlainObject(): void {
		$serialisable = new class implements \JsonSerializable {

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['statementType' => 'abridged', 'jurisdiction' => 'nl'];
			}
		};
		$plain = new \stdClass();
		$plain->statementType = 'interim';
		$plain->jurisdiction = 'be';

		$fromEntity = $this->namedStatement($serialisable);
		$fromObject = $this->namedStatement($plain);

		$this->assertSame(['Verkorte jaarrekening', 'nl'], [$fromEntity['Soort jaarrekening'], $fromEntity['Jurisdictie']]);
		$this->assertSame(['Tussentijdse cijfers', 'be'], [$fromObject['Soort jaarrekening'], $fromObject['Jurisdictie']]);

	}//end testANamedStatementIsReadFromAnEntityOrAPlainObject()

	/**
	 * The key facts of the jaarrekening the store answers for a named
	 * statement id.
	 *
	 * @param object $found What find() answers.
	 *
	 * @return array<string, string> The key-facts rows.
	 */
	private function namedStatement(object $found): array {
		$store = new class ($found) {

			/**
			 * @param object $found What find() answers.
			 */
			public function __construct(private readonly object $found) {
			}

			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				return [];
			}

			/**
			 * @param string $id The object id.
			 *
			 * @return object
			 */
			public function find(string $id): object {
				return $this->found;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($store): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $store;
				}

				throw new RuntimeException('No service ' . $id);
			}
		);

		$section = new ReportSection();
		$build = new ReflectionMethod(AnnualAccountsReportGenerator::class, 'build');
		$build->invoke(new AnnualAccountsReportGenerator($container), $section, ['statementId' => 'fs-1']);

		foreach ($section->toArray() as $block) {
			if ($block['type'] === 'detailsTable') {
				return $block['rows'];
			}
		}

		return [];

	}//end namedStatement()
}//end class
