<?php

/**
 * Unit tests for BbvProgrammeBudgetController — the route half of #866/#862.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The interesting behaviour here is the REQ-BBC-002 filter parsing, and one
 * distinction in it is load-bearing: an "All" selection means NO filter
 * (`null`), a genuinely empty selection means SHOW NOTHING (`[]`), and a value
 * outside the closed vocabulary is a 400 rather than either. Collapsing any
 * two of the three produces a dashboard that renders a plausible number for
 * the wrong question — an unrecognised programme silently showing every
 * programme, or an "All" selection silently showing none.
 *
 * The service double is HAND-WRITTEN. A `createMock()` of the service would
 * answer `[]` for every call while recording arguments it cannot actually
 * observe (a PHPUnit mock resolves a call against its own signature and
 * invokes the return callback positionally, so it never sees a named
 * argument), which is precisely the trap these tests exist to avoid.
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
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BbvProgrammeBudgetController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BbvProgrammeBudgetService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Hand-written stand-in for the budget service.
 *
 * Records the arguments it was really called with — which a PHPUnit mock
 * cannot do for this app's named-argument call style — and can be told to
 * throw so the 500 path is exercised.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RecordingBbvProgrammeBudgetService extends BbvProgrammeBudgetService {

	/**
	 * The fiscal year the last call received.
	 *
	 * @var integer|null
	 */
	public ?int $seenFiscalYear = null;

	/**
	 * The programme filter the last call received.
	 *
	 * @var array<int,string>|null
	 */
	public ?array $seenProgrammes = null;

	/**
	 * The status filter the last call received.
	 *
	 * @var array<int,string>|null
	 */
	public ?array $seenStatuses = null;

	/**
	 * Whether `programmeBudgetVsActuals()` was reached at all.
	 *
	 * @var boolean
	 */
	public bool $wasCalled = false;

	/**
	 * When true, both methods throw.
	 *
	 * @var boolean
	 */
	public bool $explode = false;

	/**
	 * Construct without the real collaborators.
	 */
	public function __construct() {
		// Deliberately does NOT call parent::__construct(): the point of this
		// double is to have no OpenRegister, no membership guard and no
		// fiscal-year resolver behind it.
	}//end __construct()

	/**
	 * Record the call and answer a recognisable envelope.
	 *
	 * @param integer|null $fiscalYear Fiscal-year filter.
	 * @param array<int,string>|null $programmes Programme filter.
	 * @param array<int,string>|null $statuses Budget-status filter.
	 *
	 * @return array<string,mixed>
	 */
	public function programmeBudgetVsActuals(
		?int $fiscalYear = null,
		?array $programmes = null,
		?array $statuses = null,
	): array {
		if ($this->explode === true) {
			throw new RuntimeException('OpenRegister is down');
		}

		$this->wasCalled = true;
		$this->seenFiscalYear = $fiscalYear;
		$this->seenProgrammes = $programmes;
		$this->seenStatuses = $statuses;

		return ['totals' => ['totalBudget' => 42.0]];
	}//end programmeBudgetVsActuals()

	/**
	 * Answer a recognisable facet envelope.
	 *
	 * @return array<string,mixed>
	 */
	public function glLineFacets(): array {
		if ($this->explode === true) {
			throw new RuntimeException('OpenRegister is down');
		}

		return ['accountTypes' => [['value' => 'expenses']], 'programmes' => [], 'assignmentStatuses' => []];
	}//end glLineFacets()
}//end class

/**
 * Covers the two provincies-BBV dashboard endpoints.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BbvProgrammeBudgetControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Hand-written service fake.
	 *
	 * @var RecordingBbvProgrammeBudgetService
	 */
	private RecordingBbvProgrammeBudgetService $budgets;

	/**
	 * Mock administration context (the authentication gate).
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $adminContext;

	/**
	 * Query parameters the request answers with.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = [];

	/**
	 * Set up shared fixtures — authenticated, no query parameters.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default = null): mixed {
				return ($this->params[$key] ?? $default);
			}
		);

		$this->budgets = new RecordingBbvProgrammeBudgetService();
		$this->adminContext = $this->createMock(AdministrationContextService::class);
		$this->adminContext->method('currentUserId')->willReturn('alice');
	}//end setUp()

	/**
	 * Build the controller over the current doubles.
	 *
	 * @return BbvProgrammeBudgetController
	 */
	private function controller(): BbvProgrammeBudgetController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new BbvProgrammeBudgetController(
			$this->request,
			$l10n,
			new NullLogger(),
			$this->budgets,
			$this->adminContext,
		);
	}//end controller()

	/**
	 * With no query parameters the endpoint answers 200 and passes NO filters
	 * through — the server-side defaults do the scoping.
	 *
	 * @return void
	 */
	public function testUnfilteredRequestAnswersTheEnvelopeWithNoFilters(): void {
		$response = $this->controller()->programmeBudgetVsActuals();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['totals' => ['totalBudget' => 42.0]], $response->getData());
		$this->assertNull($this->budgets->seenFiscalYear);
		$this->assertNull($this->budgets->seenProgrammes);
		$this->assertNull($this->budgets->seenStatuses);
	}//end testUnfilteredRequestAnswersTheEnvelopeWithNoFilters()

	/**
	 * The three REQ-BBC-002 filters reach the service as parsed values.
	 *
	 * @return void
	 */
	public function testDeclaredFiltersReachTheService(): void {
		$this->params = [
			'fiscalYear' => '2025',
			'programme' => 'water',
			'status' => 'approved,amended',
		];

		$this->controller()->programmeBudgetVsActuals();

		$this->assertSame(2025, $this->budgets->seenFiscalYear);
		$this->assertSame(['water'], $this->budgets->seenProgrammes);
		$this->assertSame(['approved', 'amended'], $this->budgets->seenStatuses);
	}//end testDeclaredFiltersReachTheService()

	/**
	 * A repeated query parameter (`?programme[]=a&programme[]=b`) parses as a
	 * list, exactly like the comma-separated form.
	 *
	 * @return void
	 */
	public function testRepeatedParameterFormParsesAsAList(): void {
		$this->params = ['programme' => ['water', 'milieu']];

		$this->controller()->programmeBudgetVsActuals();

		$this->assertSame(['water', 'milieu'], $this->budgets->seenProgrammes);
	}//end testRepeatedParameterFormParsesAsAList()

	/**
	 * The manifest's "All …" option sends the literal `all`, and that must be
	 * the ABSENCE of a filter — not a value the service tries to match, which
	 * would render an empty dashboard on the default selection.
	 *
	 * @return void
	 */
	public function testAllMeansNoFilterRatherThanAValue(): void {
		$this->params = ['programme' => 'all', 'status' => 'all', 'fiscalYear' => 'all'];

		$this->controller()->programmeBudgetVsActuals();

		$this->assertTrue($this->budgets->wasCalled);
		$this->assertNull($this->budgets->seenProgrammes);
		$this->assertNull($this->budgets->seenStatuses);
		$this->assertNull($this->budgets->seenFiscalYear);
	}//end testAllMeansNoFilterRatherThanAValue()

	/**
	 * A programme outside the closed vocabulary is refused with 400 and the
	 * service is NEVER reached — the negative half matters, because returning
	 * an empty list instead would render "no data" and look like a deliberate
	 * empty selection.
	 *
	 * @return void
	 */
	public function testUnknownProgrammeIsRefusedAndNeverReachesTheService(): void {
		$this->params = ['programme' => 'onderwijs'];

		$response = $this->controller()->programmeBudgetVsActuals();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($this->budgets->wasCalled);
	}//end testUnknownProgrammeIsRefusedAndNeverReachesTheService()

	/**
	 * A budget status outside the closed vocabulary is refused the same way.
	 *
	 * @return void
	 */
	public function testUnknownBudgetStatusIsRefused(): void {
		$this->params = ['status' => 'draft'];

		$response = $this->controller()->programmeBudgetVsActuals();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($this->budgets->wasCalled);
	}//end testUnknownBudgetStatusIsRefused()

	/**
	 * A malformed fiscal year is a 400 rather than being coerced to 0, which
	 * would silently report a year with no data as though it were empty.
	 *
	 * @return void
	 */
	public function testMalformedFiscalYearIsRefusedRatherThanCoerced(): void {
		$this->params = ['fiscalYear' => 'twenty-twentysix'];

		$response = $this->controller()->programmeBudgetVsActuals();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($this->budgets->wasCalled);
	}//end testMalformedFiscalYearIsRefusedRatherThanCoerced()

	/**
	 * An anonymous caller is refused before any read happens.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsRefused(): void {
		$adminContext = $this->createMock(AdministrationContextService::class);
		$adminContext->method('currentUserId')->willReturn(null);
		$this->adminContext = $adminContext;

		$response = $this->controller()->programmeBudgetVsActuals();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse($this->budgets->wasCalled);
	}//end testAnonymousCallerIsRefused()

	/**
	 * A failure inside the service is a 500 without a stack trace in the body.
	 *
	 * @return void
	 */
	public function testServiceFailureIsA500WithoutAStackTrace(): void {
		$this->budgets->explode = true;

		$response = $this->controller()->programmeBudgetVsActuals();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayHasKey('error', $data);
		$this->assertStringNotContainsString('OpenRegister is down', (string)$data['error']);
	}//end testServiceFailureIsA500WithoutAStackTrace()

	/**
	 * The facet endpoint answers 200 with the service's envelope.
	 *
	 * @return void
	 */
	public function testFacetEndpointAnswersTheFacets(): void {
		$response = $this->controller()->glLineFacets();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayHasKey('accountTypes', $response->getData());
	}//end testFacetEndpointAnswersTheFacets()

	/**
	 * The facet endpoint refuses an anonymous caller too.
	 *
	 * @return void
	 */
	public function testFacetEndpointRefusesAnonymousCallers(): void {
		$adminContext = $this->createMock(AdministrationContextService::class);
		$adminContext->method('currentUserId')->willReturn(null);
		$this->adminContext = $adminContext;

		$response = $this->controller()->glLineFacets();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testFacetEndpointRefusesAnonymousCallers()
	/**
	 * Every body is translated, and both failures log their own line.
	 *
	 * @return void
	 */
	public function testTranslatedBodiesAndLogLines(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:' . $text);
		$logged = [];
		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		$controller = new BbvProgrammeBudgetController($this->request, $l10n, $logger, $this->budgets, $this->adminContext);

		$this->params = ['fiscalYear' => '26'];
		self::assertSame(['error' => 'nl:fiscalYear must be a four-digit year'], $controller->programmeBudgetVsActuals()->getData());
		$this->params = ['programme' => 'nope'];
		self::assertSame(['error' => 'nl:programme must be one of the declared BBV programmes'], $controller->programmeBudgetVsActuals()->getData());
		$this->params = ['status' => 'nope'];
		self::assertSame(['error' => 'nl:status must be one of the declared budget statuses'], $controller->programmeBudgetVsActuals()->getData());

		$this->params = ['fiscalYear' => 'all', 'programme' => ' all '];
		$this->budgets->explode = true;
		$failed = $controller->programmeBudgetVsActuals();
		self::assertSame([500, ['error' => 'nl:Could not compute the BBV compliance dashboard']], [$failed->getStatus(), $failed->getData()]);
		self::assertNull($this->budgets->seenFiscalYear);
		self::assertNull($this->budgets->seenProgrammes);
		$facets = $controller->glLineFacets();
		self::assertSame([500, ['error' => 'nl:Could not load the Budget Links filters']], [$facets->getStatus(), $facets->getData()]);
		self::assertSame(
			[
				'BbvProgrammeBudgetController: failed to compute programme budget-vs-actuals',
				'BbvProgrammeBudgetController: failed to build GL-line facets',
			],
			array_column($logged, 0)
		);
		self::assertSame(['exception'], array_keys($logged[0][1]));

	}//end testTranslatedBodiesAndLogLines()
}//end class
