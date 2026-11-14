<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Security;

use OCA\Shillinq\Lifecycle\ExpenseClaimGuard;
use OCA\Shillinq\Lifecycle\RuleComplianceGuard;
use OCA\Shillinq\Service\ConsolidationGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * OpenRegister's find() searches every schema without a schema argument and
 * retries a uuid missed in the schema without it. So lib/ reads a stored object
 * through ObjectIdentifier, which checks the schema of the answer.
 *
 * @covers \OCA\Shillinq\Service\ConsolidationGuard
 * @covers \OCA\Shillinq\Lifecycle\ExpenseClaimGuard
 * @covers \OCA\Shillinq\Lifecycle\RuleComplianceGuard
 * @covers \OCA\Shillinq\Util\ObjectIdentifier
 */
final class StoredLookupsStayInTheirSchemaTest extends TestCase {

	private const OTHER = 'c0ffee00-0000-4000-8000-0000000000aa';

	/**
	 * Files allowed to call find() directly: the helper itself, the pay guard
	 * (which checks the register and schema of the answer through
	 * ListenerSchemaResolver) and the mapper lookups (ids of the mapper's own table).
	 */
	private const ALLOWED = ['Util/ObjectIdentifier.php', 'Lifecycle/SupplierInvoicePayGuard.php', 'Service/ListenerSchemaResolver.php', 'Service/Platform/SchemaDefinitions.php'];

	private const DIRECT_FIND = '/(?:->setSchema\([^;]*?\)\s*->find\(|\$(?:this->)?objectService(?:\(\))?\s*->find\(|\$this->objects\(\)\s*->find\(|\$scoped\s*->find\(|\$this->scoped\([^;]*?\)\s*->find\(|\$service\s*->find\(|ObjectService\'\)\s*->find\()/';

	private function appConfig(): IAppConfig {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		return $appConfig;
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function directFinds(): array {
		return [
			'chain' => ['$this->objectService->setRegister($r)->setSchema(\'X\')->find($id);'],
			'multi-line chain' => ["\$objectService\n\t->setRegister(\$r)\n\t->setSchema(\$s)\n\t->find(\$id);"],
			'explicit scope' => ['$this->objectService->find(id: $id, register: $r, schema: \'X\');'],
			'service method' => ['$this->objectService()->setRegister($r)->setSchema(\'X\')->find($id);'],
			'scoped variable' => ['$scoped->find($id);'],
			'scoped method' => ['$this->scoped(schema: \'X\')->find($id);'],
			'container' => ['$this->container->get(\'OCA\OpenRegister\Service\ObjectService\')->find($id);'],
		];
	}

	/**
	 * @dataProvider directFinds
	 */
	public function testTheScanRecognisesADirectFind(string $code): void {
		self::assertSame(1, preg_match(self::DIRECT_FIND, $code));
	}

	public function testNoFileReadsAStoredObjectWithADirectFind(): void {
		$lib = dirname(__DIR__, 3) . '/lib/';
		$found = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
			$relative = substr((string)$file, strlen($lib));
			if (str_ends_with($relative, '.php') === false || in_array($relative, self::ALLOWED, true) === true) {
				continue;
			}

			if (preg_match(self::DIRECT_FIND, (string)file_get_contents((string)$file)) === 1) {
				$found[] = $relative;
			}
		}

		self::assertGreaterThan(100, iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS))), 'the scan walked lib/');
		self::assertSame([], $found);
	}

	public function testFindScopedOrThrowCountsAnotherSchemaAsAMiss(): void {
		$store = new InMemoryObjectServiceStub(['Invoice' => [['id' => self::OTHER]], 'FiscalYear' => [['id' => 'fy-1', 'isClosed' => true]]]);

		self::assertNotNull(ObjectIdentifier::findScopedOrThrow(scoped: $store->setRegister('shillinq')->setSchema('FiscalYear'), id: 'fy-1'));
		self::assertNull(ObjectIdentifier::findScopedOrThrow(scoped: $store->setRegister('shillinq')->setSchema('FiscalYear'), id: 'fy-2'), 'a plain null answer stays null');
		$this->expectException(DoesNotExistException::class);
		ObjectIdentifier::findScopedOrThrow(scoped: $store->setRegister('shillinq')->setSchema('FiscalYear'), id: self::OTHER);
	}

	public function testAFiscalYearOfAnotherSchemaDoesNotPermitAFinalise(): void {
		$store = new InMemoryObjectServiceStub(['FiscalYear' => [['id' => 'fy-1', 'isClosed' => true]], 'Invoice' => [['id' => self::OTHER, 'isClosed' => true]]]);
		$guard = new ConsolidationGuard($this->appConfig(), new NullLogger(), $store);

		self::assertTrue($guard->requireFiscalPeriodClosed(['fiscalYearId' => 'fy-1']));
		self::assertFalse($guard->requireFiscalPeriodClosed(['fiscalYearId' => self::OTHER]));
	}

	public function testAGroupOfAnotherSchemaDoesNotPermitAConsolidation(): void {
		$store = new InMemoryObjectServiceStub(['ConsolidationGroup' => [], 'Invoice' => [['id' => self::OTHER, 'administrationIds' => []]]]);
		$guard = new ConsolidationGuard($this->appConfig(), new NullLogger(), $store);

		self::assertFalse($guard->requireAllMembersFinalised(['consolidationGroupId' => self::OTHER, 'fiscalYearId' => 'fy-1']));
	}

	public function testACostCentreOfAnotherSchemaDoesNotCount(): void {
		$store = new InMemoryObjectServiceStub(['Invoice' => [['id' => self::OTHER, 'costCentreCode' => 'CC-1']]]);
		$guard = new ExpenseClaimGuard($this->appConfig(), new NullLogger(), $store);

		self::assertFalse($guard->requireOpenPeriodAndCostCentres(['id' => 'claim-1', 'receiptIds' => [self::OTHER]]));
	}

	public function testAnObjectOfAnotherSchemaIsNotTheInvoiceToValidate(): void {
		$store = new InMemoryObjectServiceStub(['Invoice' => [['id' => self::OTHER, 'lines' => []]]]);
		$guard = new RuleComplianceGuard(
			$this->appConfig(),
			new NullLogger(),
			$this->createStub(\OCA\Shillinq\Lifecycle\BalanceGuard::class),
			$store,
			$this->createStub(\OCA\Shillinq\Lifecycle\PostingRestrictionGuard::class)
		);

		self::assertFalse($guard->validateInvoice(self::OTHER));
	}
}
