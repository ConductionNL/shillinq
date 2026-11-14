<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Map Expense Categories
 *
 * Repair step of expenses-category-mapping (REQ-ECM-001, REQ-ECM-002). It
 * does three things, each idempotent:
 *
 *  1. For every administration on the RGS template, seeds the expense
 *     categories of lib/Settings/seeds/expense-categories-rgs.json whose
 *     account is an expense account of that administration's chart, and a
 *     category the administration already has is left alone.
 *  2. Sets the administration's mileage, per diem and expense payable account
 *     where it has none and its chart has the seeded account.
 *  3. Maps the free text in `Receipt.category` onto a category code of the
 *     receipt's administration: an exact code, then a Dutch or English
 *     category name, then a known alias (reiskosten, lunch, parkeren ...).
 *     A text that matches nothing becomes `uncategorised` and is listed with
 *     its original text for a bookkeeper, in the output and the log.
 *
 * "On the RGS template" is measured, not assumed: an administration is seeded
 * under the id its accounts carry (its uuid or its administrationCode), and
 * one without a chart gets nothing.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\ExpenseAccountResolver;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Seeds expense categories and settings, and maps receipt category text.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Three small idempotent passes over one seed.
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.2
 */
final class MapExpenseCategories implements IRepairStep {
	/**
	 * The seed of categories and settings.
	 *
	 * @var string
	 */
	public const SEED_FILE = __DIR__ . '/../Settings/seeds/expense-categories-rgs.json';

	/**
	 * Rows read per findAll() page.
	 *
	 * @var integer
	 */
	private const PAGE = 200;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService    OpenRegister's object service.
	 * @param RegisterLocation       $registerLocation The register this app's objects live in.
	 * @param LoggerInterface        $logger           Logger for the unmatched list.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The repair step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.2
	 */
	public function getName(): string {
		return 'Shillinq: seed expense categories and map receipt categories to them';
	}//end getName()

	/**
	 * Seed, set and map; fail-soft so an upgrade never stops on it.
	 *
	 * @param IOutput $output Progress and the unmatched list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.2
	 */
	public function run(IOutput $output): void {
		if ($this->registerLocation->isOpenRegisterAvailable() === false) {
			$output->info('Shillinq: OpenRegister is not available; expense categories were not mapped.');
			return;
		}

		try {
			$seed = $this->seed();
			$this->objectService->runAsSystem(
				function () use ($seed, $output): void {
					$this->runInner(seed: $seed, output: $output);
				}
			);
		} catch (\Throwable $e) {
			$output->warning('Shillinq: mapping expense categories failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: mapping expense categories failed', ['exception' => $e->getMessage()]);
		}
	}//end run()

	/**
	 * The three passes.
	 *
	 * @param array<string, mixed> $seed   The seed.
	 * @param IOutput              $output Progress and the unmatched list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.2
	 */
	private function runInner(array $seed, IOutput $output): void {
		$seeded = 0;
		$settings = 0;
		foreach ($this->rows(schema: 'Administration') as $administration) {
			$chartId = $this->chartId(administration: $administration);
			if ($chartId === '') {
				continue;
			}

			$chart = $this->chart(administrationId: $chartId);
			$seeded += $this->seedCategories(administrationId: $chartId, chart: $chart, categories: (array)($seed['categories'] ?? []));
			$settings += $this->seedSettings(administration: $administration, chart: $chart, defaults: (array)($seed['settings'] ?? []));
		}

		$byAdministration = [];
		foreach ($this->rows(schema: 'ExpenseCategory') as $category) {
			$byAdministration[(string)($category['administrationId'] ?? '')][] = $category;
		}

		$aliases = $this->aliases(categories: (array)($seed['categories'] ?? []));
		$mapped = 0;
		$unmatched = [];
		foreach ($this->rows(schema: 'Receipt') as $receipt) {
			$text = trim((string)($receipt['category'] ?? ''));
			$administrationId = (string)($receipt['administrationId'] ?? '');
			if ($text === '' || $text === ExpenseAccountResolver::UNCATEGORISED || isset($byAdministration[$administrationId]) === false) {
				continue;
			}

			$code = $this->codeFor(text: $text, categories: $byAdministration[$administrationId], aliases: $aliases);
			if ($code === $text) {
				continue;
			}

			if ($code === null) {
				$code = ExpenseAccountResolver::UNCATEGORISED;
				$unmatched[] = (string)($receipt['receiptNumber'] ?? ($receipt['id'] ?? '')) . ": '{$text}'";
			}

			$receipt['category'] = $code;
			$this->save(object: $receipt, schema: 'Receipt');
			$mapped++;
		}//end foreach

		$output->info(
			"Shillinq: expense categories — {$seeded} categories seeded, {$settings} administrations given expense accounts, "
			. "{$mapped} receipts mapped, " . count($unmatched) . ' left uncategorised.'
		);
		if ($unmatched !== []) {
			$output->warning('Shillinq: receipts whose category matched no expense category, now uncategorised: ' . implode('; ', $unmatched));
			$this->logger->warning('Shillinq: receipts left uncategorised by the expense category mapping', ['receipts' => $unmatched]);
		}
	}//end runInner()

	/**
	 * The category code a free text maps onto among the administration's
	 * categories: an exact code, a Dutch or English name, a known alias; null
	 * when nothing matches.
	 *
	 * @param string                               $text       The receipt's category text.
	 * @param list<array<string, mixed>>           $categories The administration's categories.
	 * @param array<string, string>                $aliases    Lower-case alias => code.
	 *
	 * @return string|null The code.
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.2
	 */
	public function codeFor(string $text, array $categories, array $aliases): ?string {
		$needle = mb_strtolower(trim($text));
		$codes = [];
		foreach ($categories as $category) {
			$code = (string)($category['code'] ?? '');
			$codes[$code] = true;
			$names = (array)($category['name'] ?? []);
			$candidates = [mb_strtolower($code), mb_strtolower((string)($names['nl'] ?? '')), mb_strtolower((string)($names['en'] ?? ''))];
			if ($code !== '' && in_array($needle, $candidates, true) === true) {
				return $code;
			}
		}

		$alias = ($aliases[$needle] ?? null);
		if ($alias !== null && isset($codes[$alias]) === true) {
			return $alias;
		}

		return null;
	}//end codeFor()

	/**
	 * Seed the categories whose account the chart has as an expense account.
	 *
	 * @param string                               $administrationId The id the chart carries.
	 * @param array<string, array<string, mixed>>  $chart            Accounts by number.
	 * @param array<int, mixed>                    $categories       The seeded categories.
	 *
	 * @return int How many were created.
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-1.1
	 */
	private function seedCategories(string $administrationId, array $chart, array $categories): int {
		$existing = [];
		foreach ($this->rows(schema: 'ExpenseCategory', filters: ['administrationId' => $administrationId]) as $category) {
			$existing[(string)($category['code'] ?? '')] = true;
		}

		$created = 0;
		foreach ($categories as $category) {
			$code = (string)($category['code'] ?? '');
			$account = (string)($category['expenseAccount'] ?? '');
			if ($code === '' || isset($existing[$code]) === true || (string)($chart[$account]['accountType'] ?? '') !== 'expenses') {
				continue;
			}

			$this->save(
				object: [
					'code' => $code,
					'name' => (array)($category['name'] ?? []),
					'expenseAccount' => $account,
					'vatDeductionRule' => (string)($category['vatDeductionRule'] ?? 'full'),
					'active' => true,
					'administrationId' => $administrationId,
				],
				schema: 'ExpenseCategory'
			);
			$created++;
		}

		return $created;
	}//end seedCategories()

	/**
	 * Set the administration's expense accounts it has none of, from the seed,
	 * when its chart has the account.
	 *
	 * @param array<string, mixed>                $administration The administration.
	 * @param array<string, array<string, mixed>> $chart          Accounts by number.
	 * @param array<string, mixed>                $defaults       Setting => account number.
	 *
	 * @return int 1 when the administration was changed, else 0.
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-1.1
	 */
	private function seedSettings(array $administration, array $chart, array $defaults): int {
		$changed = false;
		foreach ($defaults as $setting => $account) {
			if (trim((string)($administration[$setting] ?? '')) === '' && isset($chart[(string)$account]) === true) {
				$administration[$setting] = (string)$account;
				$changed = true;
			}
		}

		if ($changed === false) {
			return 0;
		}

		$this->save(object: $administration, schema: 'Administration');
		return 1;
	}//end seedSettings()

	/**
	 * The id the administration's accounts carry: its uuid, else its code;
	 * '' when it has no chart.
	 *
	 * @param array<string, mixed> $administration The administration.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-1.1
	 */
	private function chartId(array $administration): string {
		foreach ([(string)($administration['id'] ?? ''), (string)($administration['administrationCode'] ?? '')] as $candidate) {
			if ($candidate !== '' && $this->scoped(schema: 'Account')->findAll(['filters' => ['administrationId' => $candidate], 'limit' => 1]) !== []) {
				return $candidate;
			}
		}

		return '';
	}//end chartId()

	/**
	 * The administration's accounts by number.
	 *
	 * @param string $administrationId The id the chart carries.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-1.1
	 */
	private function chart(string $administrationId): array {
		$chart = [];
		foreach ($this->rows(schema: 'Account', filters: ['administrationId' => $administrationId]) as $account) {
			$chart[(string)($account['accountNumber'] ?? '')] = $account;
		}

		return $chart;
	}//end chart()

	/**
	 * Lower-case alias => code, from the seed.
	 *
	 * @param array<int, mixed> $categories The seeded categories.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.2
	 */
	private function aliases(array $categories): array {
		$aliases = [];
		foreach ($categories as $category) {
			foreach ((array)($category['aliases'] ?? []) as $alias) {
				$aliases[mb_strtolower((string)$alias)] = (string)($category['code'] ?? '');
			}
		}

		return $aliases;
	}//end aliases()

	/**
	 * The seed file.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When it cannot be read.
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-1.1
	 */
	private function seed(): array {
		$content = file_get_contents(self::SEED_FILE);
		$seed = json_decode((string)$content, true);
		if (is_array($seed) === false) {
			throw new \RuntimeException('the expense category seed cannot be read.');
		}

		return $seed;
	}//end seed()

	/**
	 * Every record of a schema, page by page.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Equality filters.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.2
	 */
	private function rows(string $schema, array $filters = []): array {
		$records = [];
		$offset = 0;
		do {
			$page = $this->scoped(schema: $schema)->findAll(['filters' => $filters, 'limit' => self::PAGE, 'offset' => $offset]);
			foreach ($page as $row) {
				$record = ObjectIdentifier::recordWithId(candidate: $row);
				if ($record !== null) {
					$records[] = $record;
				}
			}

			$offset += self::PAGE;
		} while (count($page) === self::PAGE);

		return $records;
	}//end rows()

	/**
	 * Save one object.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param string               $schema The schema slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.2
	 */
	private function save(array $object, string $schema): void {
		$uuid = (string)($object['id'] ?? '');
		if ($uuid === '') {
			$uuid = null;
		}

		$this->objectService->saveObject(
			object: $object,
			register: $this->registerLocation->getRegisterSlug(),
			schema: $schema,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);
	}//end save()

	/**
	 * The object service scoped to this app's register and one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.2
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema);
	}//end scoped()
}//end class
