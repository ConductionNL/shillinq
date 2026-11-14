<?php

/**
 * ImportPosting on its own: what a post refuses or warns about before it
 * writes, how it unwinds a refused post, how it links a customer that is
 * already there, and how a refused reversal is reported.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Import;

use OCA\Shillinq\Service\Import\ImportPosting;
use OCA\Shillinq\Service\Import\ImportPostingPayloads;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The refusals, warnings and unwinding of ImportPosting.
 *
 * @covers \OCA\Shillinq\Service\Import\ImportPosting
 * @uses   \OCA\Shillinq\Service\Import\ImportPostingPayloads
 * @uses   \OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 * @uses   \OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub
 * @uses   \OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub
 */
final class ImportPostingTest extends TestCase {

	/**
	 * The register.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The transitions the engine was asked to run, as "id:action".
	 *
	 * @var list<string>
	 */
	private array $ran = [];

	/**
	 * Whether the engine refuses every transition.
	 *
	 * @var bool
	 */
	private bool $engineRefuses = false;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * An empty register and an engine that records or refuses.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store  = new InMemoryObjectServiceStub(['JournalEntry' => [], 'CustomerMaster' => []]);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The posting under test, over the given register.
	 *
	 * @param InMemoryObjectServiceStub|null $store The register, or the default one.
	 *
	 * @return ImportPosting
	 */
	private function posting(?InMemoryObjectServiceStub $store = null): ImportPosting {
		$test   = $this;
		$engine = new class($test) {
			/**
			 * Constructor.
			 *
			 * @param ImportPostingTest $test The test that records the transitions.
			 */
			public function __construct(private readonly ImportPostingTest $test) {
			}

			/**
			 * Run or refuse one transition.
			 *
			 * @param string $objectId The object.
			 * @param string $action   The transition.
			 *
			 * @return void
			 */
			public function transition(string $objectId, string $action): void {
				$this->test->recordTransition(objectId: $objectId, action: $action);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($engine);
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new ImportPosting(
			objectService: ($store ?? $this->store),
			transitions: new ObjectTransitionRunner(container: $container),
			payloads: new ImportPostingPayloads(),
			registerLocation: $location,
			logger: $this->logger
		);

	}//end posting()

	/**
	 * Called by the engine double for each transition.
	 *
	 * @param string $objectId The object.
	 * @param string $action   The transition.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the engine refuses.
	 */
	public function recordTransition(string $objectId, string $action): void {
		if ($this->engineRefuses === true) {
			throw new RuntimeException('the period is closed');
		}

		$this->ran[] = $objectId . ':' . $action;

	}//end recordTransition()

	/**
	 * A batch with a balanced two-line opening journal and the given relations.
	 *
	 * @param list<array<string,mixed>> $relations The relations.
	 *
	 * @return array{0: array<string,mixed>, 1: array<string,mixed>} The batch and its dry run report.
	 */
	private function batch(array $relations = []): array {
		$batch  = ['id' => 'batch-1', 'administrationId' => 'adm-1', 'migrationDate' => '2026-01-01', 'stagingPayload' => ['relations' => $relations]];
		$report = [
			'openingJournal' => [
				'lines' => [
					['sourceAccount' => '1100', 'targetAccount' => '1100', 'debit' => 500.0, 'credit' => 0.0],
					['sourceAccount' => '0500', 'targetAccount' => '0500', 'debit' => 0.0, 'credit' => 500.0],
				],
			],
		];

		return [$batch, $report];

	}//end batch()

	/**
	 * The codes of a list of findings.
	 *
	 * @param list<array<string,mixed>> $findings The findings.
	 *
	 * @return list<string>
	 */
	private static function codes(array $findings): array {
		return array_column($findings, 'code');

	}//end codes()

	/**
	 * A line without a mapped account refuses the post before anything is written.
	 *
	 * @return void
	 */
	public function testAnUnmappedAccountRefusesThePostBeforeItWrites(): void {
		[$batch, $report] = $this->batch();
		$report['openingJournal']['lines'][1]['targetAccount'] = ' ';

		$result = $this->posting()->post($batch, $report);

		$this->assertTrue($result['failed']);
		$this->assertNull($result['postingRefs']);
		$this->assertSame(['posting-refused'], self::codes($result['findings']));
		$this->assertSame('The account 0500 has no mapping.', $result['findings'][0]['message']);
		$this->assertSame([], $this->store->setSchema('JournalEntry')->findAll());
		$this->assertSame([], $this->ran);

	}//end testAnUnmappedAccountRefusesThePostBeforeItWrites()

	/**
	 * A batch whose opening journal books nothing posts, and warns there was no opening balance.
	 *
	 * @return void
	 */
	public function testAnEmptyOpeningBalanceIsAWarningNotARefusal(): void {
		[$batch] = $this->batch();

		$result = $this->posting()->post($batch, ['openingJournal' => ['lines' => []]]);

		$this->assertFalse($result['failed']);
		$this->assertSame(['no-opening-balance'], self::codes($result['findings']));
		$this->assertSame('warning', $result['findings'][0]['severity']);
		$this->assertNull($result['postingRefs']['openingJournalId']);
		$this->assertSame([], $this->ran);

	}//end testAnEmptyOpeningBalanceIsAWarningNotARefusal()

	/**
	 * A customer without an email is left out with a warning; a supplier is counted out.
	 *
	 * @return void
	 */
	public function testACustomerWithoutAnEmailIsLeftOutWithAWarning(): void {
		[$batch, $report] = $this->batch(
			[
				['type' => 'C', 'name' => 'No Mail BV', 'email' => ''],
				['type' => 'S', 'name' => 'Supplier BV', 'email' => 'buy@example.org'],
				['type' => 'C', 'name' => 'Mail BV', 'email' => 'info@example.org', 'code' => 'C-1'],
			]
		);

		$result = $this->posting()->post($batch, $report);

		$this->assertFalse($result['failed']);
		$this->assertSame(['customer-without-email', 'supplier-not-imported'], self::codes($result['findings']));
		$this->assertSame('The customer No Mail BV has no email address and was not imported.', $result['findings'][0]['message']);
		$customers = $this->store->setSchema('CustomerMaster')->findAll();
		$this->assertCount(1, $customers);
		$this->assertCount(1, $result['postingRefs']['masterIds']);
		$this->assertSame([$result['postingRefs']['openingJournalId'] . ':postDirect'], $this->ran);

	}//end testACustomerWithoutAnEmailIsLeftOutWithAWarning()

	/**
	 * A customer already in the register by email is linked, not written again.
	 *
	 * @return void
	 */
	public function testACustomerAlreadyThereByEmailIsLinked(): void {
		$this->store = new InMemoryObjectServiceStub(
			[
				'JournalEntry'   => [],
				'CustomerMaster' => [['id' => 'cm-9', 'administrationId' => 'adm-1', 'email' => 'info@example.org', 'legalName' => 'Mail BV']],
			]
		);
		[$batch, $report] = $this->batch([['type' => 'C', 'name' => 'Mail BV', 'email' => 'info@example.org']]);

		$result = $this->posting()->post($batch, $report);

		$this->assertFalse($result['failed']);
		$this->assertSame(['cm-9'], $result['postingRefs']['linkedMasterIds']);
		$this->assertSame([], $result['postingRefs']['masterIds']);
		$this->assertCount(1, $this->store->setSchema('CustomerMaster')->findAll());

	}//end testACustomerAlreadyThereByEmailIsLinked()

	/**
	 * A post the engine refuses removes the journal entry and the customers it wrote.
	 *
	 * @return void
	 */
	public function testARefusedPostRemovesWhatItWrote(): void {
		$this->engineRefuses = true;
		[$batch, $report] = $this->batch([['type' => 'C', 'name' => 'Mail BV', 'email' => 'info@example.org']]);
		$this->logger->expects($this->once())->method('warning')->with('ImportPosting: post refused, removing what it wrote');

		$result = $this->posting()->post($batch, $report);

		$this->assertTrue($result['failed']);
		$this->assertSame('The import was not posted: the period is closed', $result['findings'][0]['message']);
		$this->assertSame([], $this->store->setSchema('JournalEntry')->findAll());
		$this->assertSame([], $this->store->setSchema('CustomerMaster')->findAll());

	}//end testARefusedPostRemovesWhatItWrote()

	/**
	 * A record that cannot be removed while unwinding is logged; the refusal still stands.
	 *
	 * @return void
	 */
	public function testARemovalThatFailsIsLoggedAndTheRefusalStands(): void {
		$this->engineRefuses = true;
		$store = new class(['JournalEntry' => [], 'CustomerMaster' => []]) extends InMemoryObjectServiceStub {
			/**
			 * Refuse every delete.
			 *
			 * @param string          $uuid            The object.
			 * @param string|int|null $register        The register.
			 * @param string|int|null $schema          The schema.
			 * @param bool            $_rbac           RBAC.
			 * @param bool            $_multitenancy   Multitenancy.
			 * @param bool            $_retentionSweep Retention sweep.
			 * @param IUser|null      $currentUser     The user.
			 * @param bool            $permanent       Permanent.
			 *
			 * @return bool
			 *
			 * @throws RuntimeException Always.
			 */
			public function deleteObject(
				string $uuid,
				string|int|null $register = null,
				string|int|null $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $_retentionSweep = false,
				?IUser $currentUser = null,
				bool $permanent = false
			): bool {
				throw new RuntimeException('locked');
			}
		};
		[$batch, $report] = $this->batch();
		$this->logger->expects($this->once())->method('error')->with(
			'ImportPosting: could not remove a record of a refused post',
			$this->callback(static fn (array $context): bool => $context['schema'] === 'JournalEntry' && $context['exception'] === 'locked')
		);

		$result = $this->posting(store: $store)->post($batch, $report);

		$this->assertTrue($result['failed']);
		$this->assertSame(['posting-refused'], self::codes($result['findings']));

	}//end testARemovalThatFailsIsLoggedAndTheRefusalStands()

	/**
	 * A reversal whose reversing entry the engine refuses removes that entry and says why.
	 *
	 * @return void
	 */
	public function testARefusedReversalRemovesItsEntryAndSaysWhy(): void {
		[$batch, $report] = $this->batch();
		$posted = $this->posting()->post($batch, $report);
		$this->assertFalse($posted['failed']);
		$batch['postingRefs'] = $posted['postingRefs'];
		$this->engineRefuses  = true;

		$result = $this->posting()->reverse($batch);

		$this->assertNull($result['reversalRefs']['reversingJournalId']);
		$this->assertSame(['reversal-refused'], self::codes($result['findings']));
		$this->assertSame('The import was not reversed: the period is closed', $result['findings'][0]['message']);
		$this->assertCount(1, $this->store->setSchema('JournalEntry')->findAll(), 'only the opening entry remains');

	}//end testARefusedReversalRemovesItsEntryAndSaysWhy()

	/**
	 * A reversal whose opening entry is gone writes nothing and names the missing entry.
	 *
	 * @return void
	 */
	public function testAReversalWithoutItsOpeningEntryNamesIt(): void {
		[$batch] = $this->batch();
		$batch['postingRefs'] = ['openingJournalId' => 'je-gone', 'masterIds' => []];

		$result = $this->posting()->reverse($batch);

		$this->assertSame(['reversal-refused'], self::codes($result['findings']));
		$this->assertSame('The import was not reversed: its opening entry je-gone cannot be found.', $result['findings'][0]['message']);
		$this->assertSame([], $this->store->setSchema('JournalEntry')->findAll());
		$this->assertSame([], $this->ran);

	}//end testAReversalWithoutItsOpeningEntryNamesIt()

	/**
	 * An opening entry the register saves without an id refuses the post,
	 * naming the entry, and runs no transition.
	 *
	 * @return void
	 */
	public function testAnEntrySavedWithoutAnIdRefusesThePost(): void {
		$store = new class(['JournalEntry' => [], 'CustomerMaster' => []]) extends InMemoryObjectServiceStub {
			/**
			 * Saves nothing and answers an object without an id.
			 *
			 * @param array $object The object.
			 * @param mixed ...$rest The other arguments.
			 *
			 * @return ObjectEntityInterface
			 */
			public function saveObject(array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true, bool $silent = false, bool $_validation = true, ?array $uploadedFiles = null, ?IUser $currentUser = null, bool $failIfExists = false): ObjectEntityInterface {
				return new ObjectEntityStub(payload: ['journalNumber' => (string)($object['journalNumber'] ?? '')]);
			}
		};
		[$batch, $report] = $this->batch();

		$result = $this->posting(store: $store)->post($batch, $report);

		$this->assertTrue($result['failed']);
		$this->assertSame(['posting-refused'], self::codes($result['findings']));
		$this->assertMatchesRegularExpression('/^The import was not posted: the JournalEntry \S+ was not saved\.$/', $result['findings'][0]['message']);
		$this->assertSame([], $this->ran);

	}//end testAnEntrySavedWithoutAnIdRefusesThePost()
}//end class
