<?php

/**
 * Unit tests for the shillinq.receipt.book and shillinq.expense_claim.approve MCP tools.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Mcp;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ExtractionPrefillServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Mcp\McpAdministrationGate;
use OCA\Shillinq\Mcp\McpArgumentValidator;
use OCA\Shillinq\Mcp\McpExpenseTools;
use OCA\Shillinq\Mcp\McpResult;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Extraction\ExtractionDraftCommitter;
use OCA\Shillinq\Service\Extraction\ExtractionPrefillService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A NotAuthorizedException the way OpenRegister's transition engine throws it:
 * an Exception, not a RuntimeException.
 */
final class NotAuthorizedException extends \Exception {
}//end class

/**
 * receipt.book commits a receipt onto a draft claim of the same administration
 * through the review screen's consume flow; expense_claim.approve runs the
 * claim's declared transition as the signed-in user.
 *
 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
 */
#[CoversClass(McpExpenseTools::class)]
#[UsesClass(McpAdministrationGate::class)]
#[UsesClass(McpResult::class)]
#[UsesClass(McpArgumentValidator::class)]
#[UsesClass(ObjectIdentifier::class)]
#[UsesClass(ExtractionPrefillService::class)]
class McpExpenseToolsTest extends TestCase {

	/**
	 * The records per schema, by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $records = [];

	/**
	 * The drafts handed to the committer.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $committed = [];

	/**
	 * The transitions run.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $transitioned = [];

	/**
	 * A receipt draft as the capture made it, and a draft claim, both in adm-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->records = [
			'Receipt' => [
				'rcp-1' => [
					'id' => 'rcp-1',
					'administrationId' => 'adm-1',
					'photoUri' => 'files/receipt.jpg',
					'amount' => 12.5,
					'currency' => 'EUR',
					'receiptDate' => '2026-11-02',
					'category' => 'travel',
					'fieldConfidence' => ['amount' => 0.9],
					'humanCorrected' => [],
					'extractionStatus' => 'pending-review',
				],
				'rcp-foreign' => ['id' => 'rcp-foreign', 'administrationId' => 'adm-2'],
			],
			'ExpenseClaimEntry' => [
				'clm-1' => ['id' => 'clm-1', 'administrationId' => 'adm-1', 'state' => 'draft'],
				'clm-sub' => ['id' => 'clm-sub', 'administrationId' => 'adm-1', 'state' => 'submitted'],
				'clm-foreign' => ['id' => 'clm-foreign', 'administrationId' => 'adm-2', 'state' => 'draft'],
			],
		];
		$this->committed = [];
		$this->transitioned = [];
	}//end setUp()

	/**
	 * A booked receipt carries the claim and the corrections, is confirmed, and is committed as a Receipt.
	 *
	 * @return void
	 */
	public function testAReceiptIsBookedOnTheClaimThroughTheConsumeFlow(): void {
		$result = $this->tools()->bookReceipt(['administrationId' => 'adm-1', 'receiptId' => 'rcp-1', 'claimId' => 'clm-1', 'amount' => 14.0, 'glAccount' => '4500', 'personId' => 'ignored']);

		$this->assertFalse(McpResult::isError($result), (string)($result['message'] ?? ''));
		$this->assertCount(1, $this->committed);
		[$schema, $draft] = $this->committed[0];
		$this->assertSame('Receipt', $schema);
		$this->assertSame('clm-1', $draft['claimId']);
		$this->assertSame(14.0, $draft['amount']);
		$this->assertSame('4500', $draft['glAccount']);
		$this->assertSame(['amount', 'glAccount', 'claimId'], $draft['humanCorrected']);
		$this->assertSame(ExtractionPrefillService::STATUS_CONFIRMED, $draft['extractionStatus']);
		$this->assertArrayNotHasKey('personId', $draft);
		$this->assertSame($draft, $result['receipt']);
	}//end testAReceiptIsBookedOnTheClaimThroughTheConsumeFlow()

	/**
	 * Every refusal answers before anything is committed.
	 *
	 * @return void
	 */
	public function testABookingIsRefusedWhereTheReviewScreenCouldNotBookIt(): void {
		$cases = [
			'not signed in' => [['receiptId' => 'rcp-1', 'claimId' => 'clm-1'], false, 'unauthorised'],
			'foreign administration' => [['administrationId' => 'adm-2', 'receiptId' => 'rcp-foreign', 'claimId' => 'clm-foreign'], true, 'not_found'],
			'unknown receipt' => [['receiptId' => 'rcp-x', 'claimId' => 'clm-1'], true, 'not_found'],
			'receipt of another administration' => [['receiptId' => 'rcp-foreign', 'claimId' => 'clm-1'], true, 'not_found'],
			'claim of another administration' => [['receiptId' => 'rcp-1', 'claimId' => 'clm-foreign'], true, 'not_found'],
			'claim no longer a draft' => [['receiptId' => 'rcp-1', 'claimId' => 'clm-sub'], true, 'conflict'],
			'negative amount' => [['receiptId' => 'rcp-1', 'claimId' => 'clm-1', 'amount' => -1], true, 'invalid_arguments'],
			'no category' => [['receiptId' => 'rcp-1', 'claimId' => 'clm-1', 'category' => ' '], true, 'invalid_arguments'],
		];

		foreach ($cases as $name => [$args, $signedIn, $expected]) {
			$result = $this->tools(signedIn: $signedIn)->bookReceipt($args + ['administrationId' => 'adm-1']);
			$this->assertSame($expected, $result['error'] ?? 'ok', $name);
		}

		$this->assertSame([], $this->committed);
	}//end testABookingIsRefusedWhereTheReviewScreenCouldNotBookIt()

	/**
	 * A receipt already on another claim is refused; one already on this claim books again.
	 *
	 * @return void
	 */
	public function testAReceiptOnAnotherClaimIsNotMoved(): void {
		$this->records['Receipt']['rcp-1']['claimId'] = 'clm-other';
		$refused = $this->tools()->bookReceipt(['administrationId' => 'adm-1', 'receiptId' => 'rcp-1', 'claimId' => 'clm-1']);
		$this->assertSame(['conflict', 'This receipt is already booked on another claim'], [$refused['error'], $refused['message']]);
		$this->assertSame([], $this->committed);

		$this->records['Receipt']['rcp-1']['claimId'] = 'clm-1';
		$this->assertFalse(McpResult::isError($this->tools()->bookReceipt(['administrationId' => 'adm-1', 'receiptId' => 'rcp-1', 'claimId' => 'clm-1'])));
		$this->assertCount(1, $this->committed);
	}//end testAReceiptOnAnotherClaimIsNotMoved()

	/**
	 * The missing fields are named; a failing save is a logged, generic failure.
	 *
	 * @return void
	 */
	public function testMissingFieldsAreNamedAndASaveFailureIsGeneric(): void {
		unset($this->records['Receipt']['rcp-1']['currency'], $this->records['Receipt']['rcp-1']['receiptDate']);
		$missing = $this->tools()->bookReceipt(['administrationId' => 'adm-1', 'receiptId' => 'rcp-1', 'claimId' => 'clm-1']);
		$this->assertSame('The receipt has no currency, receiptDate; pass them to book it', $missing['message']);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Shillinq MCP: failed to book a receipt');
		$failed = $this->tools(logger: $logger, saveFails: true)->bookReceipt(['administrationId' => 'adm-1', 'receiptId' => 'rcp-1', 'claimId' => 'clm-1', 'currency' => 'EUR', 'receiptDate' => '2026-11-02']);
		$this->assertSame(['failed', 'Could not book the receipt'], [$failed['error'], $failed['message']]);
	}//end testMissingFieldsAreNamedAndASaveFailureIsGeneric()

	/**
	 * Approving runs `approve`, rejecting runs `reject`, and the claim is read back after.
	 *
	 * @return void
	 */
	public function testADecisionRunsTheClaimsDeclaredTransition(): void {
		$approved = $this->tools(onTransition: function (): void {
			$this->records['ExpenseClaimEntry']['clm-sub']['state'] = 'approved';
		})->decideClaim(['administrationId' => 'adm-1', 'claimId' => 'clm-sub', 'decision' => 'approved']);

		$this->assertSame([['clm-sub', 'approve']], $this->transitioned);
		$this->assertSame('approved', $approved['claim']['state']);

		$this->records['ExpenseClaimEntry']['clm-sub']['state'] = 'submitted';
		$this->tools()->decideClaim(['administrationId' => 'adm-1', 'claimId' => 'clm-sub', 'decision' => 'rejected']);
		$this->assertSame([['clm-sub', 'approve'], ['clm-sub', 'reject']], $this->transitioned);
	}//end testADecisionRunsTheClaimsDeclaredTransition()

	/**
	 * A decision the caller may not take, or on a claim not submitted, never runs or is refused by the engine.
	 *
	 * @return void
	 */
	public function testADecisionIsRefusedBeforeOrByTheWorkflow(): void {
		$args = ['administrationId' => 'adm-1', 'claimId' => 'clm-sub', 'decision' => 'approved'];
		$this->assertSame('unauthorised', $this->tools(signedIn: false)->decideClaim($args)['error']);
		$this->assertSame('not_found', $this->tools()->decideClaim(['claimId' => 'clm-foreign'] + $args)['error']);
		$this->assertSame('not_found', $this->tools()->decideClaim(['administrationId' => 'adm-2', 'claimId' => 'clm-foreign'] + $args)['error']);
		$draft = $this->tools()->decideClaim(['claimId' => 'clm-1'] + $args);
		$this->assertSame(['conflict', "Only a submitted claim can be approved or rejected; this claim is 'draft'"], [$draft['error'], $draft['message']]);
		$this->assertSame([], $this->transitioned);

		$guard = $this->tools(onTransition: static function (): void {
			throw new RuntimeException('Claims above 5000 need a second approver');
		})->decideClaim($args);
		$this->assertSame(['conflict', 'The claim could not be approved: Claims above 5000 need a second approver'], [$guard['error'], $guard['message']]);

		$forbidden = $this->tools(onTransition: static function (): void {
			throw new NotAuthorizedException('no update permission');
		})->decideClaim($args);
		$this->assertSame(['forbidden', 'You may not decide this claim'], [$forbidden['error'], $forbidden['message']]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Shillinq MCP: failed to decide an expense claim');
		$failed = $this->tools(logger: $logger, onTransition: static function (): void {
			throw new \LogicException('db down');
		})->decideClaim($args);
		$this->assertSame(['failed', 'Could not decide the claim'], [$failed['error'], $failed['message']]);
	}//end testADecisionIsRefusedBeforeOrByTheWorkflow()

	/**
	 * The catalogue declares both tools with the grant axes the spec's table gives them.
	 *
	 * @return void
	 */
	public function testTheToolsAreCataloguedWithTheirGrantAxes(): void {
		$tools = [];
		foreach (json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Mcp/tool-catalogue.json'), true) as $tool) {
			$tools[$tool['id']] = $tool;
		}

		$this->assertSame(['create', 'user', false], [$tools['shillinq.receipt.book']['scope'], $tools['shillinq.receipt.book']['reach'], $tools['shillinq.receipt.book']['readOnlyHint']]);
		$this->assertSame(['update', 'instance', false], [$tools['shillinq.expense_claim.approve']['scope'], $tools['shillinq.expense_claim.approve']['reach'], $tools['shillinq.expense_claim.approve']['readOnlyHint']]);
		$this->assertSame(['approved', 'rejected'], $tools['shillinq.expense_claim.approve']['inputSchema']['properties']['decision']['enum']);
		$this->assertSame(
			['decision must be one of approved, rejected'],
			(new McpArgumentValidator())->problems(schema: $tools['shillinq.expense_claim.approve']['inputSchema'], arguments: ['administrationId' => 'adm-1', 'claimId' => 'clm-1', 'decision' => 'delegated'])
		);
		$this->assertSame(['administrationId', 'receiptId', 'claimId'], $tools['shillinq.receipt.book']['inputSchema']['required']);
	}//end testTheToolsAreCataloguedWithTheirGrantAxes()

	/**
	 * The tools over a session, the administration context and the fakes.
	 *
	 * @param bool                 $signedIn     Whether a user is signed in.
	 * @param LoggerInterface|null $logger       The logger, or a mock.
	 * @param bool                 $saveFails    Whether the commit throws.
	 * @param callable|null        $onTransition What a transition does besides being recorded.
	 *
	 * @return McpExpenseTools The tools.
	 */
	private function tools(bool $signedIn = true, ?LoggerInterface $logger = null, bool $saveFails = false, ?callable $onTransition = null): McpExpenseTools {
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'adm-1');

		$schema = '';
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			static function (string $name) use (&$schema, $objects): ObjectServiceInterface {
				$schema = $name;
				return $objects;
			}
		);
		$objects->method('find')->willReturnCallback(
			function (string $id) use (&$schema): ?ObjectEntityInterface {
				if (isset($this->records[$schema][$id]) === false) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($this->records[$schema][$id]);
				return $entity;
			}
		);

		$committer = $this->createMock(ExtractionDraftCommitter::class);
		$committer->method('commit')->willReturnCallback(
			function (string $schema, array $draft) use ($saveFails): array {
				if ($saveFails === true) {
					throw new RuntimeException('save refused');
				}

				$this->committed[] = [$schema, $draft];
				return $draft;
			}
		);

		$transitions = $this->createMock(ObjectTransitionRunnerInterface::class);
		$transitions->method('run')->willReturnCallback(
			function (string $objectId, string $action) use ($onTransition): void {
				$this->transitioned[] = [$objectId, $action];
				if ($onTransition !== null) {
					$onTransition();
				}
			}
		);

		return new McpExpenseTools(
			new McpAdministrationGate($session, $context),
			$objects,
			$this->prefill(),
			$committer,
			$transitions,
			$logger ?? $this->createMock(LoggerInterface::class)
		);
	}//end tools()

	/**
	 * The real correction recorder, without its collaborators (recordCorrection needs none).
	 *
	 * @return ExtractionPrefillServiceInterface
	 */
	private function prefill(): ExtractionPrefillServiceInterface {
		return (new \ReflectionClass(ExtractionPrefillService::class))->newInstanceWithoutConstructor();
	}//end prefill()
}//end class
