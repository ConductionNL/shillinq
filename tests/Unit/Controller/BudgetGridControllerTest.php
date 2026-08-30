<?php

/**
 * Unit tests for BudgetGridController.
 *
 * Covers `budget-grid-view` REQ-BGV-001/002/003: every `index()` guard rail
 * (anonymous rejection, missing/malformed `administrationId`, the
 * canAccess() 404-masking IDOR guard, invalid `granularity`, malformed
 * `startPeriod`/`endPeriod`, and the reader-throws 500 path), plus a full
 * happy-path envelope build that drives `buildEnvelope()` /
 * `buildLedgerGroupRow()` / `buildAccountLeafRows()` end-to-end over a
 * 2-level LedgerGroup tree (a leaf root and a root-with-child-leaf), proving
 * the JSON response nests rows correctly, appends the synthetic TOTAAL
 * column, and evaluates the REQ-BGV-008 computed-rows waterfall (a fully
 * resolvable `bruto-marge = omzet - kostprijs-van-de-omzet` term pair).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BudgetGridController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BudgetGridCalculator;
use OCA\Shillinq\Service\BudgetGridReader;
use OCA\Shillinq\Service\BudgetVsActualsCalculator;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests the begroting grid endpoint's guard rails and envelope build.
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
 */
final class BudgetGridControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock AdministrationContextService.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $administrationContext;

	/**
	 * Mock BudgetGridReader — the controller's only I/O boundary; the
	 * calculator stays REAL (pure arithmetic, already unit-tested by
	 * {@see \OCA\Shillinq\Tests\Unit\Service\BudgetGridCalculatorTest}) so
	 * this test proves the controller's own row/envelope assembly, not a
	 * re-derived copy of the calculator's sign convention.
	 *
	 * @var BudgetGridReader&MockObject
	 */
	private BudgetGridReader&MockObject $reader;

	/**
	 * Request params for the current test, read by the IRequest mock.
	 *
	 * @var array<string,mixed>
	 */
	private array $params;

	/**
	 * Set up shared fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->params = [
			'administrationId' => 'adm-1',
			'granularity' => 'month',
			'startPeriod' => '2027-01',
			'endPeriod' => '2027-02',
		];

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) {
				return ($this->params[$key] ?? $default);
			}
		);

		$this->userSession = $this->createMock(IUserSession::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->administrationContext->method('canAccess')->willReturn(true);

		$this->reader = $this->createMock(BudgetGridReader::class);

	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return BudgetGridController
	 */
	private function controller(): BudgetGridController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new BudgetGridController(
			$this->request,
			$l10n,
			new NullLogger(),
			$this->reader,
			new BudgetGridCalculator(new BudgetVsActualsCalculator()),
			$this->administrationContext,
			$this->userSession,
		);

	}//end controller()

	/**
	 * Put a session user in place.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function actAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end actAs()

	/**
	 * The full `loadGrid()` bundle: a leaf root ("omzet"), a second leaf
	 * root ("kostprijs-van-de-omzet" — together with "omzet" a fully
	 * resolvable `bruto-marge` formula pair), and a root-with-child
	 * ("personeel" -> "lonen") exercising the recursive
	 * `buildLedgerGroupRow()` children branch. One past column
	 * (2027-01, budgeted + actualised) and one future column (2027-02,
	 * budgeted only) exercise both `isPast` branches.
	 *
	 * @return array<string,mixed>
	 */
	private function gridBundle(): array {
		$rowTree = [
			'entries' => [
				0 => ['id' => 'lg-omzet', 'slug' => 'omzet', 'code' => 'omzet', 'name' => 'Omzet'],
				1 => [
					'id' => 'lg-kostprijs',
					'slug' => 'kostprijs-van-de-omzet',
					'code' => 'kostprijs-van-de-omzet',
					'name' => 'Kostprijs van de omzet',
				],
				2 => ['id' => 'lg-personeel', 'slug' => 'personeel', 'code' => 'personeel', 'name' => 'Personeel'],
				3 => ['id' => 'lg-lonen', 'slug' => 'lonen', 'code' => 'lonen', 'name' => 'Lonen en salarissen'],
			],
			'childrenByIndex' => [2 => [3]],
			'rootIndexes' => [0, 1, 2],
		];

		$columns = [
			[
				'key' => '2027-01',
				'label' => 'jan 2027',
				'granularity' => 'month',
				'isPast' => true,
				'monthKeys' => ['2027-01'],
				'fiscalYears' => [2027],
			],
			[
				'key' => '2027-02',
				'label' => 'feb 2027',
				'granularity' => 'month',
				'isPast' => false,
				'monthKeys' => ['2027-02'],
				'fiscalYears' => [2027],
			],
		];

		$bvaContext = [
			'actualsByAccountMonth' => [
				'8000' => ['2027-01' => 6000000],
				'7000' => ['2027-01' => 2000000],
				'4000' => ['2027-01' => 900000],
			],
			'ledgerGroupEntries' => [
				0 => ['id' => 'lg-omzet', 'slug' => 'omzet', 'memberAccountNumbers' => ['8000']],
				// '9999' has no accountByNumber entry — proves the
				// "unresolved member -> skipped, not fabricated" branch in
				// buildAccountLeafRows().
				1 => ['id' => 'lg-kostprijs', 'slug' => 'kostprijs-van-de-omzet', 'memberAccountNumbers' => ['7000', '9999']],
				2 => ['id' => 'lg-personeel', 'slug' => 'personeel', 'memberAccountNumbers' => []],
				3 => ['id' => 'lg-lonen', 'slug' => 'lonen', 'memberAccountNumbers' => ['4000']],
			],
			'ledgerGroupKeyToIndex' => ['lg-omzet' => 0, 'lg-kostprijs' => 1, 'lg-personeel' => 2, 'lg-lonen' => 3],
			'ledgerGroupChildrenByIndex' => [2 => [3]],
		];

		$budgetLinesByFiscalYear = [
			2027 => [
				['ledgerGroupId' => 'lg-omzet', 'source' => 'manual', 'month01Amount' => 5000000],
				['ledgerGroupId' => 'lg-kostprijs', 'source' => 'manual', 'month01Amount' => 1500000],
				['ledgerGroupId' => 'lg-lonen', 'source' => 'manual', 'month01Amount' => 800000],
			],
		];

		$accountTypeByNumber = ['8000' => 'revenue', '7000' => 'expenses', '4000' => 'expenses'];

		$accountByNumber = [
			'8000' => ['id' => 'acc-8000', 'name' => 'Omzet uit verkoop'],
			'7000' => ['id' => 'acc-7000', 'name' => 'Kostprijs omzet'],
			'4000' => ['id' => 'acc-4000', 'name' => 'Salarissen'],
		];

		return [
			'rowTree' => $rowTree,
			'columns' => $columns,
			'bvaContext' => $bvaContext,
			'budgetLinesByFiscalYear' => $budgetLinesByFiscalYear,
			'accountTypeByNumber' => $accountTypeByNumber,
			'accountByNumber' => $accountByNumber,
		];

	}//end gridBundle()

	/**
	 * NEGATIVE CONTROL: index() rejects an anonymous caller with 401 before
	 * any scope resolution or reader call.
	 *
	 * @return void
	 */
	public function testIndexRejectsAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->reader->expects(self::never())->method('loadGrid');

		$response = $this->controller()->index();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testIndexRejectsAnonymous()

	/**
	 * A missing `administrationId` is rejected with 400.
	 *
	 * @return void
	 */
	public function testIndexRejectsMissingAdministrationId(): void {
		$this->actAs('alice');
		$this->params['administrationId'] = '';

		$response = $this->controller()->index();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testIndexRejectsMissingAdministrationId()

	/**
	 * An `administrationId` that fails the ADR-005 identifier pattern
	 * (e.g. embedded whitespace) is rejected with 400, never reaching
	 * `canAccess()`.
	 *
	 * @return void
	 */
	public function testIndexRejectsMalformedAdministrationId(): void {
		$this->actAs('alice');
		$this->params['administrationId'] = 'adm 1 not-valid!';
		$this->administrationContext->expects(self::never())->method('canAccess');

		$response = $this->controller()->index();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testIndexRejectsMalformedAdministrationId()

	/**
	 * NEGATIVE CONTROL (REQ-MA-001 IDOR guard): a well-formed
	 * `administrationId` the caller cannot access is masked as 404, never
	 * 403.
	 *
	 * @return void
	 */
	public function testIndexMasksInaccessibleAdministrationAs404(): void {
		$this->actAs('alice');
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->administrationContext->method('canAccess')->willReturn(false);

		$response = $this->controller()->index();
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testIndexMasksInaccessibleAdministrationAs404()

	/**
	 * An out-of-vocabulary `granularity` is rejected with 400.
	 *
	 * @return void
	 */
	public function testIndexRejectsInvalidGranularity(): void {
		$this->actAs('alice');
		$this->params['granularity'] = 'fortnight';

		$response = $this->controller()->index();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testIndexRejectsInvalidGranularity()

	/**
	 * A `startPeriod`/`endPeriod` not matching `YYYY-MM` is rejected with
	 * 400.
	 *
	 * @return void
	 */
	public function testIndexRejectsMalformedPeriods(): void {
		$this->actAs('alice');
		$this->params['startPeriod'] = '2027/01';

		$response = $this->controller()->index();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testIndexRejectsMalformedPeriods()

	/**
	 * When the reader throws, index() logs the exception (never the raw
	 * message to the caller) and answers a generic 500.
	 *
	 * @return void
	 */
	public function testIndexReturns500WhenReaderThrows(): void {
		$this->actAs('alice');
		$this->reader->method('loadGrid')->willThrowException(new RuntimeException('boom'));

		$response = $this->controller()->index();
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertArrayNotHasKey('boom', $response->getData());

	}//end testIndexReturns500WhenReaderThrows()

	/**
	 * POSITIVE CONTROL / happy path: a 2-level tree with a leaf root, a
	 * skipped unresolved member account, and a root-with-child builds the
	 * full envelope — synthetic TOTAAL column, nested row nested tree,
	 * account leaf rows with a route, and a fully-resolved computed-row
	 * (`bruto-marge`) deviation.
	 *
	 * @return void
	 */
	public function testIndexBuildsFullEnvelope(): void {
		$this->actAs('alice');
		$this->reader->method('loadGrid')->with(
			'adm-1',
			'2027-01',
			'2027-02',
			'month'
		)->willReturn($this->gridBundle());

		$response = $this->controller()->index();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();

		// Columns: the 2 generated columns plus the synthetic TOTAAL one.
		self::assertCount(3, $data['columns']);
		$totaalColumn = $data['columns'][2];
		self::assertSame('TOTAAL', $totaalColumn['key']);
		self::assertTrue($totaalColumn['isTotal']);
		self::assertFalse($data['columns'][0]['isTotal']);

		// Rows: omzet (leaf, resolves 1 account), kostprijs-van-de-omzet
		// (leaf, resolves 1 of 2 members), personeel (has 1 LedgerGroup
		// child).
		self::assertCount(3, $data['rows']);
		[$omzetRow, $kostprijsRow, $personeelRow] = $data['rows'];

		self::assertSame('ledgerGroup', $omzetRow['kind']);
		self::assertSame('omzet', $omzetRow['code']);
		self::assertTrue($omzetRow['hasChildren']);
		self::assertCount(1, $omzetRow['children']);
		$omzetAccountRow = $omzetRow['children'][0];
		self::assertSame('account', $omzetAccountRow['kind']);
		self::assertSame('acc-8000', $omzetAccountRow['id']);
		self::assertSame('8000', $omzetAccountRow['accountNumber']);
		self::assertSame('/chart-of-accounts/acc-8000', $omzetAccountRow['route']);
		self::assertSame(6000000, $omzetAccountRow['cells']['2027-01']['actual']);
		self::assertNull($omzetAccountRow['cells']['2027-02']['actual']);
		self::assertSame(6000000, $omzetAccountRow['cells']['TOTAAL']['actual']);

		// Kostprijs-van-de-omzet: the unresolved '9999' member is skipped,
		// never fabricated as a row.
		self::assertCount(1, $kostprijsRow['children']);
		self::assertSame('7000', $kostprijsRow['children'][0]['accountNumber']);

		// Personeel: a LedgerGroup child (lonen), not an account leaf row.
		self::assertTrue($personeelRow['hasChildren']);
		self::assertCount(1, $personeelRow['children']);
		$lonenRow = $personeelRow['children'][0];
		self::assertSame('ledgerGroup', $lonenRow['kind']);
		self::assertSame('lonen', $lonenRow['code']);
		self::assertCount(1, $lonenRow['children']);
		self::assertSame('account', $lonenRow['children'][0]['kind']);
		self::assertSame('4000', $lonenRow['children'][0]['accountNumber']);

		// The row's OWN LedgerGroup-level cell (evaluateColumn()/cumulative(),
		// not the computed-rows waterfall): omzet is a revenue account over
		// budget in the past column, so its raw deviation is favorable.
		self::assertSame(5000000, $omzetRow['cells']['2027-01']['budget']);
		self::assertSame(6000000, $omzetRow['cells']['2027-01']['actual']);
		self::assertTrue($omzetRow['cells']['2027-01']['favorable']);
		// The future column carries a budget (a BudgetLine exists for the
		// fiscal year even without a month02Amount) but no actual.
		self::assertSame(0, $omzetRow['cells']['2027-02']['budget']);
		self::assertNull($omzetRow['cells']['2027-02']['actual']);

		// Computed rows: bruto-marge = omzet - kostprijs-van-de-omzet is
		// fully resolvable (both terms present); kosten needs 6 terms this
		// fixture never supplies, so it stays null throughout — proving
		// the "unresolved formula operand -> null, never fabricated" path.
		$computedByCode = [];
		foreach ($data['computedRows'] as $row) {
			$computedByCode[$row['code']] = $row;
		}

		$brutoMargeJan = $computedByCode['bruto-marge']['cells']['2027-01'];
		self::assertSame(3500000, $brutoMargeJan['budget']);
		self::assertSame(4000000, $brutoMargeJan['actual']);
		self::assertSame(500000, $brutoMargeJan['deviation']);
		self::assertTrue($brutoMargeJan['favorable']);

		// Future column: budget resolves (0 - 0), actual does not (both
		// terms null) — deviation/favorable MUST stay null, never a
		// fabricated zero.
		$brutoMargeFeb = $computedByCode['bruto-marge']['cells']['2027-02'];
		self::assertNull($brutoMargeFeb['actual']);
		self::assertNull($brutoMargeFeb['deviation']);
		self::assertNull($brutoMargeFeb['favorable']);

		$kosten = $computedByCode['kosten']['cells']['2027-01'];
		self::assertNull($kosten['budget']);
		self::assertNull($kosten['actual']);
		self::assertNull($kosten['deviation']);

		// Every declared computed row is present, TOTAAL included.
		self::assertCount(7, $data['computedRows']);
		self::assertArrayHasKey('TOTAAL', $computedByCode['bruto-marge']['cells']);

	}//end testIndexBuildsFullEnvelope()
}//end class
