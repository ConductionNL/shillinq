<?php

/**
 * Golden test for the annual accounts report layout.
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

use OCA\Shillinq\Reporting\Generator\AnnualAccountsReportGenerator;
use OCA\Shillinq\Reporting\ReportSection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * Pins every block build() lays out for a set of FinancialStatements.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AnnualAccountsLayoutGoldenTest extends TestCase {

	/**
	 * Every layout matches the golden fixture.
	 *
	 * @return void
	 */
	public function testLayoutsMatchGolden(): void {
		$full = [
			'statementType' => 'enkelvoudig',
			'jurisdiction' => 'NL',
			'framework' => 'BW2-Titel9',
			'language' => 'nl',
			'sizeClass' => 'klein',
			'currency' => 'EUR',
			'reportingPeriodStart' => '2025-01-01',
			'reportingPeriodEnd' => '2025-12-31',
			'balanceTotal' => 150000,
			'turnover' => 480000.5,
			'balanceSheet' => [
				'fixedAssets' => 100000,
				'currentAssets' => '50000.25',
				'equity' => 80000,
				'provisions' => 10000,
				'liabilities' => 60000.25,
			],
			'profitAndLoss' => ['netTurnover' => 480000.5, 'operatingExpenses' => 400000, 'financialResult' => -2500, 'result' => 0],
			'plPresentationModel' => 'functional',
		];
		$statements = [
			'full' => $full,
			'mismatchUsd' => array_merge(
				$full,
				['currency' => 'USD', 'balanceSheet' => ['fixedAssets' => 10, 'equity' => 9.99], 'plPresentationModel' => 'categorial']
			),
			'balanced' => array_merge($full, ['balanceSheet' => ['fixedAssets' => 1, 'equity' => 1.004], 'profitAndLoss' => ['result' => 12]]),
			'bare' => ['balanceSheet' => 'x', 'profitAndLoss' => 'x'],
		];
		$result = [];
		foreach ($statements as $name => $statement) {
			$section = new ReportSection();
			$build = new ReflectionMethod(AnnualAccountsReportGenerator::class, 'build');
			$build->invoke(new AnnualAccountsReportGenerator($this->container(statement: $statement)), $section, ['administrationId' => 'adm-1']);
			$blocks = (string) json_encode($section->toArray());
			$result[$name] = json_decode((string) preg_replace('/Gegenereerd: [0-9: -]+ UTC/', 'Gegenereerd: NOW', $blocks), true);
		}

		$file = __DIR__.'/fixtures/annual-accounts-layout.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if (getenv('ANNUAL_LAYOUT_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testLayoutsMatchGolden()

	/**
	 * A container serving a store that holds one FinancialStatement.
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
