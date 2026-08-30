<?php

/**
 * Unit tests for BbvProgrammeBudgetController — the route half of #866/#862.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
}//end class
