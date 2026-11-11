<?php

/**
 * Golden test for the BBV jaarstukken report layout.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\Generator\BbvJaarstukkenReportGenerator;
use OCA\Shillinq\Reporting\ReportSection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * Pins every block build() lays out for a set of BbvStatements.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BbvJaarstukkenLayoutGoldenTest extends TestCase {

	/**
	 * Every layout matches the golden fixture.
	 *
	 * @return void
	 */
	public function testLayoutsMatchGolden(): void {
		$full = [
			'documentType' => 'jaarstukken',
			'entityType' => 'gemeente',
			'jurisdiction' => 'NL',
			'fiscalYear' => 2025,
			'currency' => 'EUR',
			'parts' => ['programmaverantwoording', 'jaarrekening'],
			'programmePlan' => [
				'programmes' => [['code' => 'P1', 'name' => 'Veiligheid'], 'skip', ['code' => 'P2', 'name' => 'Onderwijs']],
				'generalFundingSources' => 1250000.5,
				'overhead' => 300000,
				'vpbCharge' => '12.34',
				'unforeseen' => 0,
			],
			'paragraphs' => ['lokaleHeffingen', 'Financing', 'GRONDBELEID'],
			'taskFields' => [
				['code' => '0.1', 'name' => 'Bestuur', 'estimatedRevenue' => 10.5, 'estimatedExpense' => 2000],
				'skip',
				['code' => '1.1', 'name' => 'Crisisbeheersing', 'estimatedRevenue' => '0', 'estimatedExpense' => 99.99],
			],
			'annualAccounts' => ['overviewRevenueExpenses' => true, 'balance' => false, 'auditorsStatement' => 'yes'],
			'fixedAssets' => [
				['name' => 'Raadhuis', 'kind' => 'tangible', 'capitalised' => true, 'acquisitionCost' => 5000000],
				['name' => 'Software', 'kind' => 'intangible', 'capitalised' => 1, 'acquisitionCost' => 12000.5],
				'skip',
			],
		];
		$statements = [
			'full' => $full,
			'begrotingUsd' => array_merge($full, ['documentType' => 'begroting', 'currency' => 'USD', 'taskFields' => [], 'fixedAssets' => 'x']),
			'bare' => ['documentType' => 'jaarstukken', 'programmePlan' => 'x', 'annualAccounts' => 'x', 'paragraphs' => []],
			'noProgrammes' => ['documentType' => 'jaarstukken', 'programmePlan' => ['programmes' => []], 'taskFields' => 'x'],
		];
		$result = [];
		foreach ($statements as $name => $statement) {
			$section = new ReportSection();
			$build = new ReflectionMethod(BbvJaarstukkenReportGenerator::class, 'build');
			$build->invoke(new BbvJaarstukkenReportGenerator($this->container(statement: $statement)), $section, ['administrationId' => 'adm-1']);
			$blocks = (string) json_encode($section->toArray());
			$result[$name] = json_decode((string) preg_replace('/Gegenereerd: [0-9: -]+ UTC/', 'Gegenereerd: NOW', $blocks), true);
		}

		$file = __DIR__.'/fixtures/bbv-jaarstukken-layout.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if (getenv('BBV_LAYOUT_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testLayoutsMatchGolden()

	/**
	 * A container serving a store that holds one BbvStatement.
	 *
	 * @param array $statement The statement.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $statement): ContainerInterface {
		$store = new class($statement) {

			/**
			 * Construct.
			 *
			 * @param array $statement The statement.
			 */
			public function __construct(private array $statement) {
			}//end __construct()

			/**
			 * Set register.
			 *
			 * @param string $register Register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Set schema.
			 *
			 * @param string $schema Schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Find.
			 *
			 * @param array $config Config.
			 *
			 * @return array
			 */
			public function findAll(array $config=[]): array {
				return [$this->statement];
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($store): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $store;
				}

				throw new \RuntimeException('no '.$id);
			}
		);
		return $container;

	}//end container()
}//end class
