<?php

/**
 * Unit tests for JournalPostingGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\JournalPostingGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for JournalPostingGuard.
 *
 * Covers:
 * - requireBalanced: balanced vs unbalanced journals (integer-cent arithmetic)
 * - requirePostable: balance + approval gate (REQ-JE-007, REQ-JE-008)
 * - materializeGLTransaction: T1 deferral when GLTransaction register absent,
 *   unbalanced refusal, and the happy path creating header + lines + back-ref
 */
class JournalPostingGuardTest extends TestCase
{

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock IAppConfig.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * The guard under test.
     *
     * @var JournalPostingGuard
     */
    private JournalPostingGuard $guard;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->appConfig->method('getValueString')->willReturn('shillinq');

        $this->guard = new JournalPostingGuard(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * A 2-line journal whose debits equal credits is balanced.
     *
     * @return void
     */
    public function testRequireBalancedAcceptsBalancedJournal(): void
    {
        $journal = [
            'journalNumber' => 'M-2026-0001',
            'lines'         => [
                ['accountNumber' => '4500', 'side' => 'debit', 'amountCents' => 2500],
                ['accountNumber' => '1000', 'side' => 'credit', 'amountCents' => 2500],
            ],
        ];

        self::assertTrue($this->guard->requireBalanced($journal));

    }//end testRequireBalancedAcceptsBalancedJournal()

    /**
     * An unbalanced journal is rejected by requireBalanced.
     *
     * @return void
     */
    public function testRequireBalancedRejectsUnbalancedJournal(): void
    {
        $journal = [
            'journalNumber' => 'M-2026-0002',
            'lines'         => [
                ['accountNumber' => '4500', 'side' => 'debit', 'amountCents' => 2500],
                ['accountNumber' => '1000', 'side' => 'credit', 'amountCents' => 2400],
            ],
        ];

        self::assertFalse($this->guard->requireBalanced($journal));

    }//end testRequireBalancedRejectsUnbalancedJournal()

    /**
     * An empty journal is not a balanced posting.
     *
     * @return void
     */
    public function testRequireBalancedRejectsEmptyJournal(): void
    {
        self::assertFalse($this->guard->requireBalanced(['lines' => []]));

    }//end testRequireBalancedRejectsEmptyJournal()

    /**
     * requirePostable denies a balanced journal that is still pending approval.
     *
     * @return void
     */
    public function testRequirePostableDeniesPendingApproval(): void
    {
        $journal = [
            'journalNumber' => 'M-2026-0003',
            'approvalState' => 'pending',
            'lines'         => [
                ['accountNumber' => '4500', 'side' => 'debit', 'amountCents' => 1000000],
                ['accountNumber' => '1000', 'side' => 'credit', 'amountCents' => 1000000],
            ],
        ];

        self::assertFalse(
            $this->guard->requirePostable($journal),
            'A pending-approval journal must not be postable (REQ-JE-008)'
        );

    }//end testRequirePostableDeniesPendingApproval()

    /**
     * requirePostable permits a balanced, not-required-approval journal.
     *
     * @return void
     */
    public function testRequirePostablePermitsBalancedNotRequired(): void
    {
        $journal = [
            'journalNumber' => 'M-2026-0004',
            'approvalState' => 'not-required',
            'lines'         => [
                ['accountNumber' => '4500', 'side' => 'debit', 'amountCents' => 10000],
                ['accountNumber' => '1000', 'side' => 'credit', 'amountCents' => 10000],
            ],
        ];

        self::assertTrue($this->guard->requirePostable($journal));

    }//end testRequirePostablePermitsBalancedNotRequired()

    /**
     * requirePostable permits a balanced, approved journal.
     *
     * @return void
     */
    public function testRequirePostablePermitsApproved(): void
    {
        $journal = [
            'journalNumber' => 'M-2026-0005',
            'approvalState' => 'approved',
            'lines'         => [
                ['accountNumber' => '4500', 'side' => 'debit', 'amountCents' => 10000],
                ['accountNumber' => '1000', 'side' => 'credit', 'amountCents' => 10000],
            ],
        ];

        self::assertTrue($this->guard->requirePostable($journal));

    }//end testRequirePostablePermitsApproved()

    /**
     * materializeGLTransaction refuses to materialise an unbalanced journal
     * (atomic-failure scenario — no GLTransaction created).
     *
     * @return void
     */
    public function testMaterializeRefusesUnbalancedJournal(): void
    {
        // Container should never be touched for an unbalanced journal.
        $this->container->expects($this->never())->method('get');

        $journal = [
            'journalNumber' => 'M-2026-0006',
            'lines'         => [
                ['accountNumber' => '4500', 'side' => 'debit', 'amountCents' => 2500],
            ],
        ];

        self::assertFalse($this->guard->materializeGLTransaction($journal));

    }//end testMaterializeRefusesUnbalancedJournal()

    /**
     * materializeGLTransaction returns false when the GLTransaction register is
     * absent (sibling general-ledger change not yet shipped). No partial post.
     *
     * @return void
     */
    public function testMaterializeDefersWhenGLTransactionRegisterAbsent(): void
    {
        // Every findAll throws → schema-not-found probe returns false.
        $objectService = new class {
            public function setRegister(string $register): static
            {
                return $this;
            }

            public function setSchema(string $schema): static
            {
                return $this;
            }

            /**
             * @param array<string,mixed> $params
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                throw new \RuntimeException('schema not found');
            }
        };
        $this->container->method('get')->willReturn($objectService);

        $journal = [
            'journalNumber' => 'M-2026-0007',
            'lines'         => [
                ['accountNumber' => '4500', 'side' => 'debit', 'amountCents' => 2500],
                ['accountNumber' => '1000', 'side' => 'credit', 'amountCents' => 2500],
            ],
        ];

        self::assertFalse(
            $this->guard->materializeGLTransaction($journal),
            'Materialisation must abort (false) when GLTransaction register is absent'
        );

    }//end testMaterializeDefersWhenGLTransactionRegisterAbsent()

    /**
     * materializeGLTransaction happy path: creates a GLTransaction header, N
     * GLLine rows, and writes back glTransactionId — returns true.
     *
     * @return void
     */
    public function testMaterializeHappyPathCreatesTransactionAndLines(): void
    {
        $objectService = new class {
            /**
             * @var array<int,array{schema:string,data:array<string,mixed>}>
             */
            public array $saved = [];
            private string $currentSchema = '';

            public function setRegister(string $register): static
            {
                return $this;
            }

            public function setSchema(string $schema): static
            {
                $this->currentSchema = $schema;
                return $this;
            }

            /**
             * @param array<string,mixed> $params
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                // GLTransaction schema exists (availability probe succeeds).
                return [];
            }

            /**
             * @param array<string,mixed> $data
             * @return array<string,mixed>
             */
            public function saveObject(array $data): array
            {
                $this->saved[] = ['schema' => $this->currentSchema, 'data' => $data];
                if ($this->currentSchema === 'GLTransaction') {
                    return ['id' => 'txn-001'] + $data;
                }
                return ($data + ['id' => 'gen-id']);
            }
        };
        $this->container->method('get')->willReturn($objectService);

        $journal = [
            'id'               => 'je-001',
            'journalNumber'    => 'M-2026-0008',
            'entryDate'        => '2026-03-01',
            'description'      => 'Bankkosten',
            'administrationId' => 'adm-1',
            'approvalState'    => 'not-required',
            'lines'            => [
                ['accountNumber' => '4500', 'side' => 'debit', 'amountCents' => 2500],
                ['accountNumber' => '1000', 'side' => 'credit', 'amountCents' => 2500],
            ],
        ];

        $result = $this->guard->materializeGLTransaction($journal);

        self::assertTrue($result, 'Balanced journal must materialise successfully');

        // 1 GLTransaction + 2 GLLine + 1 JournalEntry back-reference write = 4 saves.
        $schemas = array_column($objectService->saved, 'schema');
        self::assertSame(1, count(array_filter($schemas, static fn($s) => $s === 'GLTransaction')));
        self::assertSame(2, count(array_filter($schemas, static fn($s) => $s === 'GLLine')));

        // The journal write-back must carry the new glTransactionId.
        $journalSaves = array_values(array_filter($objectService->saved, static fn($s) => $s['schema'] === 'JournalEntry'));
        self::assertNotEmpty($journalSaves);
        self::assertSame('txn-001', $journalSaves[0]['data']['glTransactionId']);

    }//end testMaterializeHappyPathCreatesTransactionAndLines()
}//end class
