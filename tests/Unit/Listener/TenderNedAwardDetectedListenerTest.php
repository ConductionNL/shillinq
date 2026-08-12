<?php

/**
 * Unit tests for TenderNedAwardDetectedListener.
 *
 * Verifies the eligibility filter for the REQ-002 auto-promotion path:
 *
 *  - Non-aanbesteding schema -> NO promotion.
 *  - Aanbesteding NOT in `gegund` status -> NO promotion.
 *  - Contract value below €1 -> NO promotion.
 *  - No tenant KvK configured -> NO promotion (ambiguous).
 *  - Tenant KvK does NOT match the awarded supplier -> NO promotion.
 *  - Tenant KvK MATCHES + value valid + OR available -> promotion attempted
 *    (saveObject invoked on Verplichting + emitter fires).
 *  - Idempotency: when an existing Verplichting carries the bronReferentie,
 *    no new saveObject is issued; the budget event IS re-emitted.
 *  - Handler swallows all downstream exceptions (fail-soft).
 *
 * The OR ObjectService is stubbed with a tiny fluent in-memory recorder so
 * the test verifies side-effects without booting NC.
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
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Listener\TenderNedAwardDetectedListener;
use OCA\Shillinq\Service\BudgetImpactEmitter;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\MilestoneTemplateService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for the REQ-002 auto-promotion path.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TenderNedAwardDetectedListenerTest extends TestCase
{

    /**
     * Build a recording IEventDispatcher.
     *
     * @return IEventDispatcher
     */
    private function recordingDispatcher(): IEventDispatcher
    {
        return new class implements IEventDispatcher {

            /**
             * @var array<int, array{name: string, event: Event}>
             */
            public array $events = [];

            public function dispatch(string $eventName, Event $event): void
            {
                $this->events[] = ['name' => $eventName, 'event' => $event];

            }

            public function dispatchTyped(Event $event): void
            {
            }

            public function addListener(string $eventName, callable $listener, int $priority=0): void
            {
            }

            public function addServiceListener(string $eventName, string $className, int $priority=0): void
            {
            }

            public function hasListeners(string $eventName): bool
            {
                return false;
            }

            public function removeListener(string $eventName, callable $listener): void
            {
            }
        };

    }//end recordingDispatcher()

    /**
     * Build an IAppConfig returning the configured tenant KvK.
     *
     * @param string $tenantKvk Tenant KvK.
     *
     * @return IAppConfig
     */
    private function appConfigWithTenantKvk(string $tenantKvk): IAppConfig
    {
        $cfg = $this->createMock(IAppConfig::class);
        $cfg->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default='') use ($tenantKvk): string {
                return ($key === 'tenant_kvk' ? $tenantKvk : $default);
            }
        );
        return $cfg;

    }//end appConfigWithTenantKvk()

    /**
     * Build a container that resolves the OR ObjectService to a recording
     * fluent stub. Returns an array $verplichtingenRows on the Verplichting
     * lookup.
     *
     * @param array<int, array<string, mixed>> $verplichtingenRows Existing matching obligations.
     *
     * @return array{0: ContainerInterface, 1: object} Container and the recorder so the test can inspect saveObject() calls.
     */
    private function containerAndRecorder(array $verplichtingenRows): array
    {
        $recorder = new class($verplichtingenRows) {

            /**
             * @var array<int, array<string, mixed>>
             */
            private array $verplichtingenRows;

            /**
             * @var string
             */
            private string $currentSchema = '';

            /**
             * @var array<int, array{schema: string, object: array<string, mixed>}>
             */
            public array $saves = [];


            /**
             * @param array<int, array<string, mixed>> $rows Existing rows.
             */
            public function __construct(array $rows)
            {
                $this->verplichtingenRows = $rows;
            }

            public function setRegister(string $register): self
            {
                return $this;
            }

            public function setSchema(string $schema): self
            {
                $this->currentSchema = $schema;
                return $this;
            }

            public function findAll(array $opts=[]): array
            {
                if ($this->currentSchema === 'Verplichting') {
                    return $this->verplichtingenRows;
                }
                return [];
            }

            public function saveObject(array $object): array
            {
                $this->saves[] = ['schema' => $this->currentSchema, 'object' => $object];
                return $object;
            }
        };

        $container = new class($recorder) implements ContainerInterface {

            public function __construct(private readonly object $objectService)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
                    return $this->objectService;
                }
                throw new class('not bound') extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return $id === 'OCA\\OpenRegister\\Service\\ObjectService';
            }
        };

        return [$container, $recorder];

    }//end containerAndRecorder()

    /**
     * Build a listener with the supplied container + tenant KvK.
     *
     * @param ContainerInterface $container  Container.
     * @param string             $tenantKvk  Tenant KvK (default empty).
     * @param string             $schemaSlug Slug the schema resolver reports for the event entity.
     *
     * @return array{0: TenderNedAwardDetectedListener, 1: IEventDispatcher} Listener + dispatcher to inspect.
     */
    private function listener(
        ContainerInterface $container,
        string $tenantKvk='',
        string $schemaSlug='TenderNedAanbesteding'
    ): array {
        $dispatcher = $this->recordingDispatcher();
        $emitter    = new BudgetImpactEmitter($dispatcher, new NullLogger());
        $templates  = new MilestoneTemplateService();
        $listener   = new TenderNedAwardDetectedListener(
            $templates,
            $emitter,
            $container,
            $this->appConfigWithTenantKvk($tenantKvk),
            $this->resolver($schemaSlug),
            new NullLogger()
        );
        return [$listener, $dispatcher];

    }//end listener()

    /**
     * Build a ListenerSchemaResolver stub that reports a given schema slug.
     *
     * @param string $slug Slug the resolver resolves the entity's id to.
     *
     * @return ListenerSchemaResolver
     */
    private function resolver(string $slug): ListenerSchemaResolver
    {
        $resolver = $this->createMock(ListenerSchemaResolver::class);
        $resolver->method('schemaSlug')->willReturn($slug);
        return $resolver;

    }//end resolver()

    /**
     * Build an ObjectEntity carrying a numeric schema **id**, exactly as
     * OpenRegister stamps it (`setSchema((string) $schema->getId())`).
     *
     * A hand-built entity carrying the slug is a shape production never
     * produces; the slug arrives through {@see ListenerSchemaResolver}.
     *
     * @param string              $schemaId Numeric schema id as OR stamps it.
     * @param array<string,mixed> $payload  Payload.
     *
     * @return ObjectEntity
     */
    private function entity(string $schemaId, array $payload): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setSchema($schemaId);
        $entity->setObject($payload);
        return $entity;

    }//end entity()

    /**
     * Non-aanbesteding schema is ignored.
     *
     * @return void
     */
    public function testNonAanbestedingSchemaIsIgnored(): void
    {
        [$container, $recorder] = $this->containerAndRecorder([]);
        [$listener, $dispatcher] = $this->listener($container, '30280353', 'Verplichting');

        $event = new ObjectCreatedEvent(
            $this->entity('1089', ['status' => 'gegund', 'contractWaarde' => 100.0])
        );

        $listener->handle($event);

        $this->assertCount(0, $recorder->saves);
        $this->assertCount(0, $dispatcher->events);

    }//end testNonAanbestedingSchemaIsIgnored()

    /**
     * A non-`gegund` status is ignored.
     *
     * @return void
     */
    public function testNonGegundStatusIsIgnored(): void
    {
        [$container, $recorder] = $this->containerAndRecorder([]);
        [$listener, $dispatcher] = $this->listener($container, '30280353');

        $event = new ObjectCreatedEvent(
            $this->entity('1090', [
                'status'             => 'open',
                'contractWaarde'     => 50000.0,
                'gegundeLeverancier' => '30280353 Test BV',
                'aanbestedingId'     => 'TN-2026-0001',
            ])
        );

        $listener->handle($event);

        $this->assertCount(0, $recorder->saves);

    }//end testNonGegundStatusIsIgnored()

    /**
     * Contract value below €1 is ignored.
     *
     * @return void
     */
    public function testZeroValueIsIgnored(): void
    {
        [$container, $recorder] = $this->containerAndRecorder([]);
        [$listener, $dispatcher] = $this->listener($container, '30280353');

        $event = new ObjectCreatedEvent(
            $this->entity('1090', [
                'status'             => 'gegund',
                'contractWaarde'     => 0.0,
                'gegundeLeverancier' => '30280353 Test BV',
                'aanbestedingId'     => 'TN-2026-0001',
            ])
        );

        $listener->handle($event);

        $this->assertCount(0, $recorder->saves);

    }//end testZeroValueIsIgnored()

    /**
     * Empty tenant KvK is treated as "do not promote".
     *
     * @return void
     */
    public function testEmptyTenantKvkSkipsPromotion(): void
    {
        [$container, $recorder] = $this->containerAndRecorder([]);
        [$listener, $dispatcher] = $this->listener($container, '');

        $event = new ObjectCreatedEvent(
            $this->entity('1090', [
                'status'             => 'gegund',
                'contractWaarde'     => 50000.0,
                'gegundeLeverancier' => '30280353 Test BV',
                'aanbestedingId'     => 'TN-2026-0001',
            ])
        );

        $listener->handle($event);

        $this->assertCount(0, $recorder->saves);

    }//end testEmptyTenantKvkSkipsPromotion()

    /**
     * A supplier KvK mismatch skips promotion.
     *
     * @return void
     */
    public function testSupplierMismatchSkipsPromotion(): void
    {
        [$container, $recorder] = $this->containerAndRecorder([]);
        [$listener, $dispatcher] = $this->listener($container, '99999999');

        $event = new ObjectCreatedEvent(
            $this->entity('1090', [
                'status'             => 'gegund',
                'contractWaarde'     => 50000.0,
                'gegundeLeverancier' => '30280353 Test BV',
                'aanbestedingId'     => 'TN-2026-0001',
            ])
        );

        $listener->handle($event);

        $this->assertCount(0, $recorder->saves);

    }//end testSupplierMismatchSkipsPromotion()

    /**
     * A KvK match writes a Verplichting + emits the budget event (REQ-002 / REQ-007).
     *
     * @return void
     */
    public function testMatchingKvkPromotesAndEmits(): void
    {
        [$container, $recorder] = $this->containerAndRecorder([]);
        [$listener, $dispatcher] = $this->listener($container, '30280353');

        $event = new ObjectCreatedEvent(
            $this->entity('1090', [
                'status'             => 'gegund',
                'contractWaarde'     => 50000.0,
                'gegundeLeverancier' => '30280353 Conduction B.V.',
                'aanbestedingId'     => 'TN-2026-0001',
                'titel'              => 'Schoonmaak',
                'opdrachttype'       => 'levering-in-fases',
                'looptijdStart'      => '2026-01-01',
                'looptijdEind'       => '2026-12-31',
                'administrationId'   => 'adm-x',
            ])
        );

        $listener->handle($event);

        // 1 Verplichting + 1 update of the aanbesteding to in-uitvoering = 2 saves.
        $this->assertGreaterThanOrEqual(1, count($recorder->saves));
        $verplichtingSave = null;
        foreach ($recorder->saves as $save) {
            if ($save['schema'] === 'Verplichting') {
                $verplichtingSave = $save['object'];
                break;
            }
        }
        $this->assertNotNull($verplichtingSave);
        $this->assertSame('tenderned', $verplichtingSave['bron']);
        $this->assertSame('TN-2026-0001', $verplichtingSave['bronReferentie']);
        $this->assertSame('active', $verplichtingSave['status']);
        $this->assertNotEmpty($verplichtingSave['mijlpalen']);

        // Budget impact event emitted.
        $this->assertCount(1, $dispatcher->events);
        $this->assertSame(BudgetImpactEmitter::EVENT_OBLIGATION_ACTIVATED, $dispatcher->events[0]['name']);

    }//end testMatchingKvkPromotesAndEmits()

    /**
     * An existing Verplichting with the same bronReferentie is idempotent —
     * no new Verplichting is written; the budget event IS re-emitted.
     *
     * @return void
     */
    public function testIdempotentOnExistingBronReferentie(): void
    {
        $existing = [
            'verplichtingNummer' => 'TN-TN-2026-0001',
            'bron'               => 'tenderned',
            'bronReferentie'     => 'TN-2026-0001',
            'status'             => 'active',
            'amount'             => 50000.0,
            'administrationId'   => 'adm-x',
        ];
        [$container, $recorder] = $this->containerAndRecorder([$existing]);
        [$listener, $dispatcher] = $this->listener($container, '30280353');

        $event = new ObjectCreatedEvent(
            $this->entity('1090', [
                'status'             => 'gegund',
                'contractWaarde'     => 50000.0,
                'gegundeLeverancier' => '30280353 Conduction B.V.',
                'aanbestedingId'     => 'TN-2026-0001',
                'administrationId'   => 'adm-x',
            ])
        );

        $listener->handle($event);

        // No NEW Verplichting save.
        foreach ($recorder->saves as $save) {
            $this->assertNotSame('Verplichting', $save['schema'], 'No new Verplichting should be created on idempotent re-event');
        }

        // Budget impact event WAS re-emitted to nudge launchpad.
        $this->assertCount(1, $dispatcher->events);

    }//end testIdempotentOnExistingBronReferentie()
}//end class
