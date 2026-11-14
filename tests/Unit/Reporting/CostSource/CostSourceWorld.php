<?php

/**
 * A fixture for the case cost source tests: hours in humaniq, cases and case
 * types in the source app, rates per person, and which apps are installed.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting\CostSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\CostSource;

use OCA\Shillinq\Reporting\CostSource\ClassifiedSubjectCostSource;
use OCA\Shillinq\Reporting\CostSource\SubjectCostReport;
use OCA\Shillinq\Reporting\CostSource\TaskFieldRollup;
use OCA\Shillinq\Service\HrmqCostRateAdapter;
use OCA\Shillinq\Service\SubjectCostAggregator;
use OCA\Shillinq\Service\SubjectCostService;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Builds the cost source over in-memory objects.
 */
final class CostSourceWorld {

	/**
	 * Installed apps.
	 *
	 * @var array<int, string>
	 */
	public array $installed = ['dossiq', 'humaniq'];

	/**
	 * Hour rows as humaniq stores them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $hours = [];

	/**
	 * Objects per "register/schema" and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $objects = [];

	/**
	 * Cents per hour per person.
	 *
	 * @var array<string, int>
	 */
	public array $rates = [];

	/**
	 * The periods the rates were asked for.
	 *
	 * @var array<int, string>
	 */
	public array $ratePeriods = [];

	/**
	 * Constructor.
	 *
	 * @param TestCase $test The test, which makes the doubles.
	 */
	public function __construct(
		private readonly TestCase $test,
	) {
	}//end __construct()

	/**
	 * Add a case type.
	 *
	 * @param string $id The type id.
	 * @param string $title Its title.
	 * @param string|null $taskField Its taakveld, or null for none.
	 *
	 * @return void
	 */
	public function caseType(string $id, string $title, ?string $taskField): void {
		$this->objects['dossiq/caseType'][$id] = array_filter(['id' => $id, 'title' => $title, 'iv3TaskField' => $taskField], static fn ($value): bool => $value !== null);
	}//end caseType()

	/**
	 * Add a case of a type, with hours booked on it.
	 *
	 * @param string $id The case id.
	 * @param string $typeId Its type.
	 * @param array<int, array{0: string, 1: float, 2: string}> $hours [person, hours, date] per row.
	 *
	 * @return void
	 */
	public function caseWithHours(string $id, string $typeId, array $hours): void {
		$this->objects['dossiq/case'][$id] = ['id' => $id, 'caseType' => $typeId];
		foreach ($hours as [$person, $amount, $date]) {
			$this->hours[] = [
				'employeeId' => $person,
				'hours' => $amount,
				'date' => $date,
				'administrationId' => 'adm-1',
				'domainObjectRef' => $id,
				'domainObjectType' => 'dossiq:case',
			];
		}
	}//end caseWithHours()

	/**
	 * The source over this world.
	 *
	 * @return ClassifiedSubjectCostSource The source.
	 */
	public function source(): ClassifiedSubjectCostSource {
		$world = $this;
		$objectService = new class($world) {
			private string $register = '';

			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param CostSourceWorld $world The world.
			 */
			public function __construct(
				private readonly CostSourceWorld $world,
			) {
			}

			public function setRegister(string $register): self {
				$this->register = $register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = []): array {
				if ($this->register === 'humaniq' && $this->schema === 'TimeEntry') {
					return $this->world->hours;
				}

				return [];
			}

			/**
			 * @param string $id The id.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id): array {
				$found = ($this->world->objects[$this->register . '/' . $this->schema][$id] ?? null);
				if ($found === null) {
					throw new RuntimeException('not found');
				}

				return $found;
			}
		};

		$container = $this->test->getMockBuilder(ContainerInterface::class)->getMock();
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService): object {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objectService;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);

		$apps = $this->test->getMockBuilder(IAppManager::class)->getMock();
		$apps->method('isInstalled')->willReturnCallback(fn (string $app): bool => in_array($app, $this->installed, true));

		$rates = $this->test->getMockBuilder(HrmqCostRateAdapter::class)->disableOriginalConstructor()->getMock();
		$rates->method('registerSlug')->willReturn('humaniq');
		$rates->method('ratesFor')->willReturnCallback(
			function (array $personIds, string $period = ''): array {
				$this->ratePeriods[] = $period;
				return array_intersect_key($this->rates, array_flip($personIds));
			}
		);

		$logger = new NullLogger();

		return new ClassifiedSubjectCostSource(
			$container,
			$apps,
			new SubjectCostService($container, $rates, new SubjectCostAggregator($logger), $logger),
			$rates,
			$logger
		);
	}//end source()

	/**
	 * The report over this world, translating as English.
	 *
	 * @return SubjectCostReport The report.
	 */
	public function report(): SubjectCostReport {
		$l10n = $this->test->getMockBuilder(IL10N::class)->getMock();
		$l10n->method('t')->willReturnCallback(static fn (string $text, mixed $parameters = []): string => $text);

		return new SubjectCostReport($this->source(), new TaskFieldRollup(), $l10n);
	}//end report()
}//end class
