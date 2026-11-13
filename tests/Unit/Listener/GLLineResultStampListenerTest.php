<?php

/**
 * Unit tests for GLLineResultStampListener and StampGlLineResults.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCA\Shillinq\Listener\GLLineResultStampListener;
use OCA\Shillinq\Repair\StampGlLineResults;
use OCA\Shillinq\Service\Ledger\GlLineResultStamps;
use OCA\Shillinq\Service\Ledger\OpenItemClearingService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Ledger\GlLineResultStampsTest as Seed;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Posting, reversing and booking a source document stamp the lines; the repair step stamps history.
 */
class GLLineResultStampListenerTest extends TestCase {

	/**
	 * Every write the store received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The transitions the engine ran, as [id, action].
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $transitionsRun = [];

	/**
	 * Build the store over the seed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved = [];
		$this->store = new InMemoryObjectServiceStub(Seed::records(), $this->saved, true);

	}//end setUp()

	/**
	 * The stamps service over the store.
	 *
	 * @return GlLineResultStamps
	 */
	private function stamps(): GlLineResultStamps {
		$settings = $this->createStub(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new GlLineResultStamps($this->store, $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']));

	}//end stamps()

	/**
	 * The clearing service over the store; its engine applies the reopen transition, or is missing.
	 *
	 * @param bool $engine Whether OpenRegister's transition engine is available.
	 *
	 * @return OpenItemClearingService
	 */
	private function clearing(bool $engine=true): OpenItemClearingService {
		$store = $this->store;
		$runs = &$this->transitionsRun;
		$transitionEngine = new class ($store, $runs) {
			/**
			 * Constructor.
			 *
			 * @param InMemoryObjectServiceStub      $store The store.
			 * @param array<int, array<int, string>> $runs  Where the runs are recorded.
			 */
			public function __construct(private InMemoryObjectServiceStub $store, private array &$runs) {
			}

			/**
			 * Apply a transition to the store.
			 *
			 * @param string               $id     The object.
			 * @param string               $action The transition.
			 * @param string               $app    The app running it.
			 * @param array<string, mixed> $data   Inputs.
			 *
			 * @return mixed
			 */
			public function transitionAsSystem(string $id, string $action, string $app, array $data=[]): mixed {
				$this->runs[] = [$id, $action];

				return $this->store->patchObject(objectId: $id, data: ['state' => 'reopened'], schema: 'ClearingGroup');
			}
		};
		$container = $this->createStub(ContainerInterface::class);
		$container->method('has')->willReturn($engine);
		$container->method('get')->willReturn($transitionEngine);

		return new OpenItemClearingService(
			$this->store,
			new RegisterLocation($this->createConfiguredStub(IAppConfig::class, ['getValueString' => 'shillinq']), $this->createStub(IAppManager::class)),
			new ObjectTransitionRunner($container)
		);

	}//end clearing()

	/**
	 * The listener, with a resolver that reads the entity's schema as its slug.
	 *
	 * @param LoggerInterface|null $logger The logger, a stub by default.
	 * @param bool                 $engine Whether the transition engine is available.
	 *
	 * @return GLLineResultStampListener
	 */
	private function listener(?LoggerInterface $logger=null, bool $engine=true): GLLineResultStampListener {
		$resolver = $this->createStub(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturnCallback(static fn (?object $entity): string => (string)$entity?->getSchema());

		return new GLLineResultStampListener($this->stamps(), $resolver, ($logger ?? $this->createStub(LoggerInterface::class)), $this->clearing(engine: $engine));

	}//end listener()

	/**
	 * An entity as OpenRegister hands it to a listener.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $data   The payload with its id.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid((string)$data['id']);
		$entity->setSchema($schema);
		$entity->setObject($data);

		return $entity;

	}//end entity()

	/**
	 * A line as stored now.
	 *
	 * @param string $id The line id.
	 *
	 * @return array<string, mixed>
	 */
	private function line(string $id): array {
		return $this->store->find(id: $id, schema: 'GLLine')->getObject();

	}//end line()

	/**
	 * The post transition stamps the transaction's lines.
	 *
	 * @return void
	 */
	public function testThePostTransitionStampsTheLines(): void {
		$transaction = $this->entity('GLTransaction', ['id' => Seed::TX_SEP, 'state' => 'posted']);

		$this->listener()->handle(new ObjectTransitionedEvent(object: $transaction, move: new TransitionMove(action: 'post', from: 'draft', to: 'posted'), scope: new RegisterSchemaRef(register: 'shillinq', schema: 'GLTransaction'), actor: new TransitionActor(userId: 'controller')));

		$this->assertSame(['pnl', true], [$this->line('l2')['accountClass'], $this->line('l2')['countsInResult']]);
		$this->assertSame('balance', $this->line('l5')['accountClass']);

	}//end testThePostTransitionStampsTheLines()

	/**
	 * The reverse transition takes the transaction's lines out.
	 *
	 * @return void
	 */
	public function testTheReverseTransitionTakesTheLinesOut(): void {
		$this->stamps()->stampTransaction(Seed::TX_SEP);
		$this->store->saveObject(object: ['id' => Seed::TX_SEP, 'state' => 'reversed', 'administrationId' => Seed::ADMIN], schema: 'GLTransaction');
		$transaction = $this->entity('GLTransaction', ['id' => Seed::TX_SEP, 'state' => 'reversed']);

		$this->listener()->handle(new ObjectTransitionedEvent(object: $transaction, move: new TransitionMove(action: 'reverse', from: 'posted', to: 'reversed'), scope: new RegisterSchemaRef(register: 'shillinq', schema: 'GLTransaction'), actor: new TransitionActor(userId: 'controller')));

		$this->assertFalse($this->line('l1')['countsInResult']);

	}//end testTheReverseTransitionTakesTheLinesOut()

	/**
	 * Reversing a transaction reopens the clearing group of its line, and still takes its lines out.
	 *
	 * @return void
	 */
	public function testTheReverseTransitionReopensTheClearingGroupOfItsLine(): void {
		$this->store->saveObject(object: ['id' => 'grp-1', 'accountNumber' => '1950', 'lineIds' => ['l1', 'x'], 'debitCents' => 1, 'creditCents' => 1, 'state' => 'cleared'], schema: 'ClearingGroup');
		$this->store->patchObject(objectId: 'l1', data: ['clearingGroupId' => 'grp-1'], schema: 'GLLine');
		$this->stamps()->stampTransaction(Seed::TX_SEP);
		$this->store->saveObject(object: ['id' => Seed::TX_SEP, 'state' => 'reversed', 'administrationId' => Seed::ADMIN], schema: 'GLTransaction');
		$transaction = $this->entity('GLTransaction', ['id' => Seed::TX_SEP, 'state' => 'reversed']);

		$this->listener()->handle(new ObjectTransitionedEvent(object: $transaction, move: new TransitionMove(action: 'reverse', from: 'posted', to: 'reversed'), scope: new RegisterSchemaRef(register: 'shillinq', schema: 'GLTransaction'), actor: new TransitionActor(userId: 'controller')));

		$this->assertSame([['grp-1', 'reopen']], $this->transitionsRun);
		$this->assertSame('reopened', $this->store->find(id: 'grp-1', schema: 'ClearingGroup')->getObject()['state']);
		$this->assertFalse($this->line('l1')['countsInResult']);

	}//end testTheReverseTransitionReopensTheClearingGroupOfItsLine()

	/**
	 * A reopen that fails is logged and the lines are still taken out of the result.
	 *
	 * @return void
	 */
	public function testAFailedReopenIsLoggedAndDoesNotStopTheStamps(): void {
		$this->store->saveObject(object: ['id' => 'grp-1', 'accountNumber' => '1950', 'lineIds' => ['l1', 'x'], 'state' => 'cleared'], schema: 'ClearingGroup');
		$this->store->patchObject(objectId: 'l1', data: ['clearingGroupId' => 'grp-1'], schema: 'GLLine');
		$this->stamps()->stampTransaction(Seed::TX_SEP);
		$this->store->saveObject(object: ['id' => Seed::TX_SEP, 'state' => 'reversed', 'administrationId' => Seed::ADMIN], schema: 'GLTransaction');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'GLLineResultStampListener: clearing groups not reopened',
			['transactionId' => Seed::TX_SEP, 'exception' => 'OpenRegister TransitionEngine is not available']
		);
		$transaction = $this->entity('GLTransaction', ['id' => Seed::TX_SEP, 'state' => 'reversed']);

		$this->listener($logger, false)->handle(new ObjectTransitionedEvent(object: $transaction, move: new TransitionMove(action: 'reverse', from: 'posted', to: 'reversed'), scope: new RegisterSchemaRef(register: 'shillinq', schema: 'GLTransaction'), actor: new TransitionActor(userId: 'controller')));

		$this->assertSame('cleared', $this->store->find(id: 'grp-1', schema: 'ClearingGroup')->getObject()['state']);
		$this->assertFalse($this->line('l1')['countsInResult']);

	}//end testAFailedReopenIsLoggedAndDoesNotStopTheStamps()

	/**
	 * A line written under an already posted transaction (a booked source document) is stamped on creation.
	 *
	 * @return void
	 */
	public function testALineBookedUnderAPostedTransactionIsStamped(): void {
		$this->listener()->handle(new ObjectCreatedEvent($this->entity('GLLine', $this->line('l3'))));

		$this->assertSame(['pnl', true], [$this->line('l3')['accountClass'], $this->line('l3')['countsInResult']]);
		$this->assertArrayNotHasKey('accountClass', $this->line('l1'), 'only the created line');

	}//end testALineBookedUnderAPostedTransactionIsStamped()

	/**
	 * Another schema's transition and a draft's line change nothing.
	 *
	 * @return void
	 */
	public function testOtherObjectsAreIgnored(): void {
		$invoice = $this->entity('ARInvoice', ['id' => Seed::TX_SEP]);
		$this->listener()->handle(new ObjectTransitionedEvent(object: $invoice, move: new TransitionMove(action: 'issue', from: 'draft', to: 'posted'), scope: new RegisterSchemaRef(register: 'shillinq', schema: 'ARInvoice'), actor: new TransitionActor(userId: 'controller')));
		$this->listener()->handle(new ObjectCreatedEvent($this->entity('GLLine', $this->line('d1'))));

		$this->assertSame([], $this->saved);

	}//end testOtherObjectsAreIgnored()

	/**
	 * The repair step stamps the lines of transactions posted before the listener existed.
	 *
	 * @return void
	 */
	public function testTheRepairStepStampsHistory(): void {
		$settings = $this->createStub(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Shillinq: 5 ledger line(s) stamped over 1 posted or reversed transaction(s).');

		(new StampGlLineResults($this->stamps(), $this->store, $settings, $this->createStub(LoggerInterface::class)))->run($output);

		$this->assertTrue($this->line('l4')['countsInResult']);
		$this->assertArrayNotHasKey('countsInResult', $this->line('d1'));

	}//end testTheRepairStepStampsHistory()

	/**
	 * The app registers the listener for GLTransaction transitions and GLLine creation, and the repair step (the wiring, from the caller).
	 *
	 * @return void
	 */
	public function testTheAppWiresTheListenerAndTheRepairStep(): void {
		$root = __DIR__ . '/../../..';
		$application = (string)file_get_contents($root . '/lib/AppInfo/Application.php');
		$registration = (string)file_get_contents($root . '/lib/AppInfo/ReportingRegistration.php');
		$info = (string)file_get_contents($root . '/appinfo/info.xml');

		$this->assertStringContainsString('(new ReportingRegistration())->register(context: $context);', $application);
		$this->assertMatchesRegularExpression('/ObjectTransitionedEvent::class,\s*listener: GLLineResultStampListener::class/', $registration);
		$this->assertMatchesRegularExpression('/ObjectCreatedEvent::class,\s*listener: GLLineResultStampListener::class/', $registration);
		$this->assertStringContainsString('<step>OCA\Shillinq\Repair\StampGlLineResults</step>', $info);

	}//end testTheAppWiresTheListenerAndTheRepairStep()
}//end class
