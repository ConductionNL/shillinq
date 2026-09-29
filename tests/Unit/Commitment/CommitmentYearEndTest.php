<?php

/**
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Commitment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Commitment;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\AppInfo\CommitmentGuardServices;
use OCA\Shillinq\Service\Commitment\CommitmentLedger;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Lifecycle\BudgetBlocker;
use OCA\Shillinq\Lifecycle\CommitmentGuardAdapter;
use OCA\Shillinq\Lifecycle\MandateEnforcer;
use OCA\Shillinq\Listener\InvoiceCommitmentListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\ListenerSlugContract;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Entering, invoicing, closing and carrying commitments (REQ-PCYE-001 to REQ-PCYE-004).
 */
final class CommitmentYearEndTest extends TestCase {
	use CommitmentYearEndFixture;

	/**
	 * Seed Gemeente Voorbeeld.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->seed();
	}//end setUp()

	/**
	 * The two guards over the store, wrapped as the app registers them.
	 *
	 * @param string $method `canCommit` or `requiresApproval`.
	 *
	 * @return CommitmentGuardAdapter
	 */
	private function guard(string $method): CommitmentGuardAdapter {
		$store = $this->store;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (): object => $store);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$logger = $this->createMock(LoggerInterface::class);
		$mandate = new MandateEnforcer($container, $config, $logger);
		$guard = $mandate;
		if ($method === 'canCommit') {
			$guard = new BudgetBlocker($container, $config, $logger, $mandate);
		}

		return new CommitmentGuardAdapter($guard, $method, 'Refused.', $this->ledger(), $logger);
	}//end guard()

	/**
	 * The listener with a schema resolver that knows SupplierInvoice as schema 900.
	 *
	 * @return InvoiceCommitmentListener
	 */
	private function listener(): InvoiceCommitmentListener {
		$mapper = new class {
			/**
			 * Find by id.
			 *
			 * @param mixed $id The id.
			 *
			 * @return object
			 */
			public function find(mixed $id, mixed ...$rest): object {
				$slug = ['900' => 'SupplierInvoice', '901' => 'PurchaseOrder', '7' => 'shillinq'][(string)$id] ?? '';
				return new class($slug) {
					/**
					 * Constructor.
					 *
					 * @param string $slug The slug.
					 */
					public function __construct(private string $slug) {
					}

					/**
					 * The slug.
					 *
					 * @return string
					 */
					public function getSlug(): string {
						return $this->slug;
					}
				};
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($mapper);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$contract = $this->createMock(ListenerSlugContract::class);
		$contract->method('isEnabled')->willReturn(true);
		$resolver = new ListenerSchemaResolver(container: $container, settingsService: $settings, contract: $contract, logger: $this->createMock(LoggerInterface::class));

		return new InvoiceCommitmentListener($this->invoicing(), $resolver, $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * The approval of the seed invoice as OpenRegister raises it.
	 *
	 * @param string $schemaId The schema id on the entity.
	 * @param string $to       The state reached.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function approval(string $schemaId = '900', string $to = 'approved'): ObjectTransitionedEvent {
		$entity = new ObjectEntity();
		$entity->setObject($this->get('SupplierInvoice', $this->ids['invoice']));
		$entity->setSchema($schemaId);
		$entity->setRegister('7');
		return new ObjectTransitionedEvent($entity, 'approveMatched', 'matched', $to, 'k.dewit', '7', $schemaId);
	}//end approval()

	/**
	 * Entering V-2026-0114 records EUR 20,000 once and leaves EUR 40,000 free on 0.4.
	 *
	 * @return void
	 */
	public function testEnteringACommitmentRecordsItOnce(): void {
		$this->engine->transition($this->ids['v0114'], 'aangaan');

		$movements = $this->all('CommitmentMovement');
		$this->assertCount(1, $movements);
		$this->assertSame(['committed', 2000000], [$movements[0]['kind'], $movements[0]['amount']]);
		$this->assertSame([], $this->registerErrors('CommitmentMovement', $movements[0]));
		$this->assertSame(2000000, $this->get('CommitmentLine', $this->ids['line0114'])['remaining_committed']);
		$budget = $this->get('CommitmentBudget', $this->ids['budget04']);
		$this->assertSame([2000000, 4000000], [$budget['outstanding_commitments'], $budget['free_capacity']]);
		$this->assertSame([], $this->registerErrors('CommitmentBudget', $budget));

		$this->ledger()->committed($this->get('Commitment', $this->ids['v0114']), 'm.jansen');
		$this->assertCount(1, $this->all('CommitmentMovement'), 'A second run writes nothing.');
	}//end testEnteringACommitmentRecordsItOnce()

	/**
	 * The guards resolve: within budget allowed, beyond it refused naming the shortfall.
	 *
	 * @return void
	 */
	public function testTheGuardsResolveAndNameTheShortfall(): void {
		$commitment = $this->get('Commitment', $this->ids['v0114']);
		$this->assertTrue($this->guard('canCommit')->check($commitment, 'aangaan', 'm.jansen')->isAllowed());

		$this->store->setSchema('CommitmentLine')->patchObject($this->ids['line0114'], ['amount_excl_vat' => 8000000]);
		$refused = $this->guard('canCommit')->check($commitment, 'aangaan', 'm.jansen');
		$this->assertFalse($refused->isAllowed());
		$this->assertSame('The 2026 budget for programme 0.4 is EUR 20,000.00 short.', $refused->getMessage());

		$this->assertTrue($this->guard('requiresApproval')->check($commitment, 'indienen', 'm.jansen')->isAllowed(), 'Without a mandate the commitment goes for approval.');
	}//end testTheGuardsResolveAndNameTheShortfall()

	/**
	 * An approved EUR 15,000 invoice leaves EUR 5,000 remaining, partially invoiced, once.
	 *
	 * @return void
	 */
	public function testAnApprovedInvoiceLowersTheCommitment(): void {
		$this->engine->transition($this->ids['v0114'], 'aangaan');
		$listener = $this->listener();
		$listener->handle($this->approval());
		$listener->handle($this->approval());
		$listener->handle($this->approval('901'));
		$listener->handle($this->approval('900', 'paid'));

		$line = $this->get('CommitmentLine', $this->ids['line0114']);
		$this->assertSame([1500000, 500000], [$line['invoiced_amount'], $line['remaining_committed']]);
		$this->assertSame([], $this->registerErrors('CommitmentLine', $line));
		$this->assertSame('partially_invoiced', $this->get('Commitment', $this->ids['v0114'])['status']);
		$invoiced = array_values(array_filter($this->all('CommitmentMovement'), static fn (array $m): bool => $m['kind'] === 'invoiced'));
		$this->assertCount(1, $invoiced);
		$this->assertSame([], $this->registerErrors('CommitmentMovement', $invoiced[0]));
		$budget = $this->get('CommitmentBudget', $this->ids['budget04']);
		$this->assertSame([1500000, 500000, 4000000], [$budget['realised_amount'], $budget['outstanding_commitments'], $budget['free_capacity']]);
	}//end testAnApprovedInvoiceLowersTheCommitment()

	/**
	 * Marking the invoice last closes V-2026-0114 and frees EUR 5,000 more.
	 *
	 * @return void
	 */
	public function testTheLastInvoiceReleasesTheRest(): void {
		$this->engine->transition($this->ids['v0114'], 'aangaan');
		$this->listener()->handle($this->approval());

		$this->assertSame(['invoiceNumber' => 'F-2026-7781', 'commitmentNumber' => 'V-2026-0114', 'release' => 500000], $this->invoicing()->lastInvoice('adm-voorbeeld', $this->ids['invoice']));
		$this->invoicing()->markLast('adm-voorbeeld', $this->ids['invoice']);

		$this->assertSame('closed', $this->get('Commitment', $this->ids['v0114'])['status']);
		$this->assertTrue($this->get('SupplierInvoice', $this->ids['invoice'])['isLastInvoice']);
		$this->assertSame([], $this->registerErrors('SupplierInvoice', $this->get('SupplierInvoice', $this->ids['invoice'])));
		$closed = array_values(array_filter($this->all('CommitmentMovement'), static fn (array $m): bool => $m['kind'] === 'closed'));
		$this->assertSame(-500000, $closed[0]['amount']);
		$line = $this->get('CommitmentLine', $this->ids['line0114']);
		$this->assertSame([0, true], [$line['remaining_committed'], $line['afgesloten']]);
		$this->assertSame(4500000, $this->get('CommitmentBudget', $this->ids['budget04'])['free_capacity']);

		$this->expectExceptionMessage('This invoice has no open commitment to close.');
		$this->invoicing()->markLast('adm-voorbeeld', $this->ids['invoice']);
	}//end testTheLastInvoiceReleasesTheRest()

	/**
	 * An invoice approved while already marked last closes the commitment at once.
	 *
	 * @return void
	 */
	public function testAnInvoiceApprovedAsTheLastOneCloses(): void {
		$this->engine->transition($this->ids['v0114'], 'aangaan');
		$this->store->setSchema('SupplierInvoice')->patchObject($this->ids['invoice'], ['isLastInvoice' => true]);
		$this->listener()->handle($this->approval());

		$this->assertSame('closed', $this->get('Commitment', $this->ids['v0114'])['status']);
		$this->assertSame(['factureren', 'afsluiten'], array_slice(array_column($this->engine->ran, 'action'), 1));
	}//end testAnInvoiceApprovedAsTheLastOneCloses()

	/**
	 * Road maintenance continues into 2027 with its shortfall listed; a second run changes nothing.
	 *
	 * @return void
	 */
	public function testOpenCommitmentsCarryOverOnce(): void {
		$preview = $this->carryOver()->preview('adm-voorbeeld', 2026);
		$this->assertSame(['V-2026-0120'], array_column($preview['lines'], 'commitmentNumber'), 'The draft V-2026-0114 is not carried.');
		$this->assertSame(1800000, $preview['total']);
		$this->assertSame([['programme' => '7.1', 'carried' => 1800000, 'free' => 1000000, 'shortfall' => 800000, 'commitments' => ['V-2026-0120']]], $preview['shortfalls']);

		$this->carryOver()->execute('adm-voorbeeld', 2026, 'm.jansen');

		$lines = array_values(array_filter($this->all('CommitmentLine'), static fn (array $l): bool => $l['commitment'] === 'V-2026-0120'));
		$this->assertCount(2, $lines);
		$new = $lines[1];
		$this->assertSame([2027, 1800000, $this->ids['line0120']], [$new['financialYear'], $new['amount_excl_vat'], $new['carriedFromLine']]);
		$this->assertSame([], $this->registerErrors('CommitmentLine', $new));
		$this->assertSame([0, true], [$lines[0]['remaining_committed'], $lines[0]['afgesloten']]);
		$moves = array_values(array_filter($this->all('CommitmentMovement'), static fn (array $m): bool => $m['kind'] === 'carried_forward'));
		$this->assertSame([-1800000, 1800000], array_column($moves, 'amount'));
		$this->assertSame([], $this->registerErrors('CommitmentMovement', $moves[1]));
		$this->assertSame(0, $this->get('CommitmentBudget', $this->ids['budget71'])['outstanding_commitments']);
		$this->assertSame(-800000, $this->get('CommitmentBudget', $this->ids['budget71n'])['free_capacity']);

		$this->assertSame([], $this->carryOver()->preview('adm-voorbeeld', 2026)['lines']);
		$this->carryOver()->execute('adm-voorbeeld', 2026, 'm.jansen');
		$this->assertCount(2, $this->all('CommitmentMovement'), 'Nothing more is written.');
	}//end testOpenCommitmentsCarryOverOnce()

	/**
	 * The declarations the flow depends on.
	 *
	 * @return void
	 */
	public function testTheLifecycleDeclaresTheWiring(): void {
		$lifecycle = RegisterSchema::schema('Commitment')['x-openregister-lifecycle']['transitions'];
		$this->assertSame(['committed', 'partially_delivered', 'partially_invoiced', 'partially_paid'], (array)$lifecycle['afsluiten']['from']);
		$this->assertSame('closed', $lifecycle['afsluiten']['actions'][0]['actionParameters']['kind']);
		$this->assertContains('committed', (array)$lifecycle['factureren']['from']);
		$this->assertSame('OCA\\Shillinq\\Lifecycle\\Action\\RecordCommitmentMovementAction', $lifecycle['aangaan']['actions'][0]['action']);
		$this->assertContains('carried_forward', RegisterSchema::schema('CommitmentMovement')['properties']['kind']['enum']);
	}//end testTheLifecycleDeclaresTheWiring()
	/**
	 * Both tags the Commitment lifecycle names resolve to an adapter over the real guard.
	 *
	 * @return void
	 */
	public function testTheGuardTagsAreRegistered(): void {
		$factories = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);
		(new CommitmentGuardServices())->register($context);

		$lifecycle = RegisterSchema::schema('Commitment')['x-openregister-lifecycle']['transitions'];
		$this->assertSame([$lifecycle['indienen']['requires'], $lifecycle['aangaan']['requires']], array_keys($factories));

		$store = $this->store;
		$config = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);
		$services = [
			MandateEnforcer::class => null,
			CommitmentLedger::class => $this->ledger(),
			LoggerInterface::class => $logger,
		];
		$inner = $this->createMock(ContainerInterface::class);
		$inner->method('get')->willReturn($store);
		$services[MandateEnforcer::class] = new MandateEnforcer($inner, $config, $logger);
		$services[BudgetBlocker::class] = new BudgetBlocker($inner, $config, $logger, $services[MandateEnforcer::class]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

		$commitment = $this->get('Commitment', $this->ids['v0114']);
		foreach ($factories as $factory) {
			$this->assertTrue($factory($container)->check($commitment, 'aangaan', 'm.jansen')->isAllowed());
		}
	}//end testTheGuardTagsAreRegistered()
}//end class
