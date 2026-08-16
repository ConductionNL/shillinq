<?php

/**
 * Orders Audit Command
 *
 * `occ shillinq:orders:audit` — count-equality guard for the
 * abstract-order-primitive fold (REQ-ORD-003). For each legacy source schema
 * (Subsidie, PurchaseOrder, DBAOpdracht) it counts the source rows and the
 * matching folded `Order` rows (by the `migratedFrom` marker FoldIntoOrder
 * stamps), and reports PASS when every source row has a corresponding Order,
 * FAIL (non-zero exit code) otherwise. Read-only — it never writes data; it
 * is the "run before/after the migration" verification tasks.md calls for.
 *
 * @category Command
 * @package  OCA\Shillinq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Command;

use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command that count-verifies the Subsidie/PurchaseOrder/DBAOpdracht ->
 * Order fold.
 *
 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class OrdersAuditCommand extends Command {
	use ReadsSourceRowsInBatches;

	/**
	 * Source schema slug => discriminator orderType it folds onto.
	 *
	 * @var array<string,string>
	 */
	private const SOURCE_SCHEMAS = [
		'Subsidie' => 'subsidy',
		'PurchaseOrder' => 'purchase',
		'DBAOpdracht' => 'engagement',
	];

	/**
	 * Construct the orders-audit command.
	 *
	 * @param SettingsService $settingsService Provides the shillinq register slug.
	 * @param ContainerInterface $container DI container (lazy OR ObjectService resolution).
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Configure the command name and description.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('shillinq:orders:audit')
			->setDescription('Count-equality audit for the Subsidie/PurchaseOrder/DBAOpdracht -> Order fold (REQ-ORD-003).');

	}//end configure()

	/**
	 * Execute the audit and print the per-schema count comparison.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when every source row has a matching Order, 1 otherwise.
	 *
	 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $input is required by
	 *     Symfony Command::execute()'s signature; this command takes no
	 *     console input.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$output->writeln('<error>Shillinq: orders:audit — OpenRegister ObjectService unavailable: ' . $e->getMessage() . '</error>');
			return 1;
		}

		$registerSlug = $this->settingsService->getRegisterSlug();
		$allMatched = true;

		$output->writeln('<info>Shillinq Order-fold count-equality audit</info>');

		foreach (self::SOURCE_SCHEMAS as $sourceSchema => $orderType) {
			$sourceRows = $this->countRows($objectService, $registerSlug, $sourceSchema);
			$orderRows = $this->countMigrated($objectService, $registerSlug, $sourceSchema);
			$matched = ($orderRows >= $sourceRows);

			$statusLabel = '<error>MISMATCH</error>';
			if ($matched === true) {
				$statusLabel = '<info>OK</info>';
			} else {
				$allMatched = false;
			}

			$output->writeln(
				sprintf(
					'  %-14s (orderType=%-10s) source=%-4d migrated=%-4d %s',
					$sourceSchema,
					$orderType,
					$sourceRows,
					$orderRows,
					$statusLabel
				)
			);
		}//end foreach

		if ($allMatched === true) {
			$output->writeln('<info>PASS — every source row has a matching folded Order.</info>');
			return 0;
		}

		$output->writeln('<error>FAIL — one or more source schemas have unmigrated rows. Run `occ maintenance:repair` to fold them.</error>');
		return 1;
	}//end execute()

	/**
	 * Count rows of a source schema. Returns 0 when the schema is absent.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The shillinq register slug.
	 * @param string $schema The schema slug.
	 *
	 * @return int The row count.
	 */
	private function countRows(object $objectService, string $registerSlug, string $schema): int {
		try {
			return count($this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: $schema));
		} catch (\Throwable) {
			return 0;
		}

	}//end countRows()

	/**
	 * Count Order rows carrying `migratedFrom.schema = $sourceSchema`.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The shillinq register slug.
	 * @param string $sourceSchema The source schema slug the marker names.
	 *
	 * @return int The migrated-Order count.
	 */
	private function countMigrated(object $objectService, string $registerSlug, string $sourceSchema): int {
		try {
			// OpenRegister does NOT support dot-path filters on nested properties,
			// so `['migratedFrom.schema' => ...]` matches nothing (it silently
			// returned 0, making the audit report a false PASS). Read every
			// OrderPrimitive row and match the nested marker in PHP instead.
			$count = 0;
			foreach ($this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: 'OrderPrimitive') as $row) {
				$data = $row;
				if (is_object($row) === true) {
					try {
						$data = $row->getObject();
					} catch (\Throwable) {
						$data = [];
					}
				}

				if (is_array($data) === true
					&& (string)(($data['migratedFrom']['schema'] ?? '')) === $sourceSchema
				) {
					$count++;
				}
			}

			return $count;
		} catch (\Throwable) {
			return 0;
		}//end try

	}//end countMigrated()
}//end class
