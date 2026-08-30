<?php

/**
 * CBS Submission Controller.
 *
 * RESTful CBS submission API per REQ-CBS-009 / ADR-002 / ADR-003. The
 * controller is intentionally thin (≤10 lines of logic per method per
 * ADR-003): delegates CRUD to OpenRegister's ObjectService and the
 * IV3-export pipeline to `CBSExportService`. Endpoints:
 *
 *   GET    /api/cbs-submissions              — list with filters
 *   GET    /api/cbs-submissions/{id}         — retrieve single + lines
 *   POST   /api/cbs-submissions              — create new submission
 *   PUT    /api/cbs-submissions/{id}         — update submission / transition
 *   DELETE /api/cbs-submissions/{id}         — delete draft submission
 *   POST   /api/cbs-submissions/{id}/generate — trigger generate pipeline
 *
 * Every endpoint requires an authenticated user (`#[NoAdminRequired]`); the
 * administration scope is validated via the userSession-derived helper and
 * reads/writes are delegated to OpenRegister's ObjectService, which enforces
 * multitenancy + RBAC. Error responses include a `message` field per ADR-002.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-cbs-bestanden-extended/specs.md
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\CBSExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * CBS Submission CRUD + lifecycle controller.
 *
 * @spec openspec/changes/bookkeeping-cbs-bestanden-extended/specs.md
 */
class CBSSubmissionController extends Controller {
	/**
	 * Identifier validation pattern (short slugs only).
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request The request object.
	 * @param CBSExportService $exportService The CBS export pipeline.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param IUserSession $userSession The session for the acting user id (auth body-guard).
	 * @param LoggerInterface $logger Logger for diagnostics (no stack traces to client).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param AdministrationContextService $administrationContext Per-administration IDOR guard (ADR-005 Rule 3).
	 * @param IGroupManager $groupManager Nextcloud admin bypass for the administration guard.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly CBSExportService $exportService,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly AdministrationContextService $administrationContext,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Authorization guard — every endpoint requires an authenticated
	 * Nextcloud user (REQ-CBS-001 / ADR-005). This is only the
	 * authentication half of the guard; per-object administration
	 * membership is separately enforced by
	 * {@see requireAdministrationAccess()} on every method that reads or
	 * mutates a specific CBSSubmission, per ADR-005 Rule 3 / OWASP A01:2021
	 * (security-endpoint-guards, REQ-001).
	 *
	 * @return JSONResponse|null A 401 response when unauthenticated, null when ok.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	private function requireUser(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				['error' => 'authentication required', 'message' => 'Authentication required'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		return null;
	}//end requireUser()

	/**
	 * Per-object administration-membership guard (REQ-001 / ADR-005 Rule 3).
	 *
	 * A `CBSSubmission` belongs to exactly one administration
	 * (`administrationId`); the caller must have a valid
	 * `AdministrationMembership` for that administration before reading
	 * beyond existence-check or mutating the record. Unlike the read-side
	 * masking convention elsewhere in this app, CBS filings return an
	 * explicit 403 on denial per this change's spec scenario ("A user
	 * cannot delete another organization's CBS submission" — REQ-001) since
	 * the endpoint already discloses the submission's existence via its
	 * own id in the URL.
	 *
	 * A Nextcloud admin bypasses the per-administration check, matching the
	 * established pattern in `BookingNotificationController::authorizeBookingAccess()`
	 * — back-office admins legitimately manage every administration's
	 * filings, and requiring an `AdministrationMembership` for them as well
	 * would make the admin surface unusable.
	 *
	 * @param string $administrationId The administration id the target object belongs to.
	 *
	 * @return JSONResponse|null A 403 response when the caller is not a member, null when ok.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	private function requireAdministrationAccess(string $administrationId): ?JSONResponse {
		$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($uid !== '' && $this->groupManager->isAdmin($uid) === true) {
			return null;
		}

		if ($this->administrationContext->canAccess($administrationId) === false) {
			return new JSONResponse(
				['error' => 'cbs-submission-forbidden', 'message' => 'Not authorized to access this CBS submission'],
				Http::STATUS_FORBIDDEN,
			);
		}

		return null;
	}//end requireAdministrationAccess()

	/**
	 * GET /api/cbs-submissions — list CBS submissions, optionally filtered.
	 *
	 * Query params: `status`, `administrationId`, `periodStart`, `periodEnd`.
	 *
	 * Found during this change's per-method code read (beyond the audit's
	 * originally-named create/update/destroy/generate): this endpoint had
	 * no per-object scoping either — `requireUser()` only checks
	 * authentication, so any authenticated caller could list every
	 * administration's submissions. Fixed here alongside the four named
	 * findings since REQ-001 applies uniformly to every
	 * `#[NoAdminRequired]` method, not only the ones the audit enumerated.
	 *
	 * @return JSONResponse 200 with `{ submissions: [...] }`; 403 when an
	 *                      explicit `administrationId` filter names an
	 *                      administration the caller is not a member of.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$auth = $this->requireUser();
		if ($auth !== null) {
			return $auth;
		}

		$filters = $this->buildIndexFilters();

		$reqAdministrationId = (string)($filters['administrationId'] ?? '');
		if ($reqAdministrationId !== '') {
			$accessError = $this->requireAdministrationAccess(administrationId: $reqAdministrationId);
			if ($accessError !== null) {
				return $accessError;
			}
		}

		return $this->run(
			action: 'list CBS submissions',
			compute: function () use ($filters, $reqAdministrationId): array {
				$submissions = array_map(
					fn (mixed $row): array => $this->toArray(row: $row),
					$this->objectService()
						->setRegister($this->register())
						->setSchema('CBSSubmission')
						->findAll(['filters' => $filters])
				);

				$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
				$isAdmin = ($uid !== '' && $this->groupManager->isAdmin($uid) === true);
				if ($reqAdministrationId === '' && $isAdmin === false) {
					// No explicit filter, non-admin caller: scope the list to
					// the caller's own accessible administrations rather than
					// returning every tenant's submissions. An admin caller
					// (or an explicit, already-vetted administrationId
					// filter) sees the unfiltered set — matching
					// requireAdministrationAccess()'s admin bypass above.
					$submissions = array_values(
						array_filter(
							$submissions,
							fn (array $row): bool => $this->administrationContext->canAccess(
								(string)($row['administrationId'] ?? '')
							)
						)
					);
				}

				return ['submissions' => $submissions];
			},
			context: $filters,
		);

	}//end index()

	/**
	 * GET /api/cbs-submissions/{id} — retrieve a single submission + its lines.
	 *
	 * Found during this change's per-method code read (beyond the audit's
	 * originally-named create/update/destroy/generate): `show()` had the
	 * same missing-guard shape — any authenticated caller could read
	 * another organization's statutory filing by id. Fixed alongside the
	 * four named findings for the same reason as `index()` above.
	 *
	 * @param string $id The CBSSubmission id.
	 *
	 * @return JSONResponse 200 with `{ submission, lines }`; 400 on bad id;
	 *                      403 when the caller is not a member of the
	 *                      submission's administration; 404 when missing.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$auth = $this->requireUser();
		if ($auth !== null) {
			return $auth;
		}

		$error = $this->validateId(value: $id, label: 'id');
		if ($error !== null) {
			return $error;
		}

		try {
			$existing = $this->toArray(
				row: $this->objectService()
					->setRegister($this->register())
					->setSchema('CBSSubmission')
					->find($id)
			);
		} catch (\Throwable $e) {
			return new JSONResponse(
				['error' => 'cbs-submission-not-found', 'message' => 'CBS submission not found'],
				Http::STATUS_NOT_FOUND
			);
		}

		$accessError = $this->requireAdministrationAccess(administrationId: (string)($existing['administrationId'] ?? ''));
		if ($accessError !== null) {
			return $accessError;
		}

		return $this->run(
			action: 'retrieve CBS submission',
			compute: fn (): array => $this->buildShowPayload(id: $id),
			context: ['id' => $id],
		);

	}//end show()

	/**
	 * POST /api/cbs-submissions — create a new CBSSubmission record (draft).
	 *
	 * Body fields per REQ-CBS-001: submissionNumber (optional, auto-built by
	 * the generate pipeline), reportingPeriodStartDate, reportingPeriodEndDate,
	 * organizationLegalName, kvkNumber, taxIdentificationNumber,
	 * administrationId, description (optional).
	 *
	 * @return JSONResponse 201 on success; 400 on missing required field.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$auth = $this->requireUser();
		if ($auth !== null) {
			return $auth;
		}

		$body = $this->jsonBody();
		$error = $this->requireFields(
			body: $body,
			fields: [
				'reportingPeriodStartDate',
				'reportingPeriodEndDate',
				'organizationLegalName',
				'kvkNumber',
				'taxIdentificationNumber',
				'administrationId',
			]
		);
		if ($error !== null) {
			return $error;
		}

		$accessError = $this->requireAdministrationAccess(administrationId: (string)$body['administrationId']);
		if ($accessError !== null) {
			return $accessError;
		}

		$body['status'] = 'draft';
		$body['currency'] = ($body['currency'] ?? 'EUR');

		return $this->run(
			action: 'create CBS submission',
			compute: fn (): array => $this->toArray(
				row: $this->objectService()->saveObject(
					object: $body,
					register: $this->register(),
					schema: 'CBSSubmission',
				)
			),
			context: ['administrationId' => (string)$body['administrationId']],
			status: Http::STATUS_CREATED,
		);

	}//end create()

	/**
	 * PUT /api/cbs-submissions/{id} — update fields and/or transition state.
	 *
	 * Recognises the `status` field as a state transition request; runs
	 * `CBSExportService::validateSubmission()` on `draft → validated` and
	 * returns 422 with `errors[]` when validation fails (REQ-CBS-008).
	 *
	 * @param string $id The CBSSubmission id.
	 *
	 * @return JSONResponse 200 on update; 400 on bad id; 403 when not a
	 *                      member of the submission's administration; 422
	 *                      on validation failure.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$auth = $this->requireUser();
		if ($auth !== null) {
			return $auth;
		}

		$error = $this->validateId(value: $id, label: 'id');
		if ($error !== null) {
			return $error;
		}

		try {
			$existing = $this->toArray(
				row: $this->objectService()
					->setRegister($this->register())
					->setSchema('CBSSubmission')
					->find($id)
			);
		} catch (\Throwable $e) {
			return new JSONResponse(
				['error' => 'cbs-submission-not-found', 'message' => 'CBS submission not found'],
				Http::STATUS_NOT_FOUND
			);
		}

		$accessError = $this->requireAdministrationAccess(administrationId: (string)($existing['administrationId'] ?? ''));
		if ($accessError !== null) {
			return $accessError;
		}

		$body = $this->jsonBody();
		$body['id'] = $id;

		if (($body['status'] ?? '') === 'validated') {
			$validation = $this->exportService->validateSubmission(submission: $body);
			// `validateSubmission()` returns `array{valid:bool,errors:...,warnings:...}`,
			// so `valid` always exists. The `?? false` this replaces did not just
			// read as defensive — it made PHPStan lose the shape inside this
			// branch, leaving `$validation['errors']` an unresolved type and the
			// whole response payload unverifiable.
			if ($validation['valid'] === false) {
				return new JSONResponse(
					[
						'error' => 'validation failed',
						'message' => 'CBS submission validation failed',
						'errors' => $validation['errors'],
					],
					Http::STATUS_UNPROCESSABLE_ENTITY,
				);
			}
		}

		return $this->run(
			action: 'update CBS submission',
			compute: fn (): array => $this->toArray(
				row: $this->objectService()->saveObject(
					object: $body,
					register: $this->register(),
					schema: 'CBSSubmission',
				)
			),
			context: ['id' => $id],
		);

	}//end update()

	/**
	 * DELETE /api/cbs-submissions/{id} — delete a draft submission.
	 *
	 * Per REQ-CBS-003 only `draft` submissions are deletable; submissions in
	 * other states are immutable (audit-trail integrity).
	 *
	 * @param string $id The CBSSubmission id.
	 *
	 * @return JSONResponse 204 on success; 403 when not a member of the
	 *                      submission's administration; 409 when not in
	 *                      draft state.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		$auth = $this->requireUser();
		if ($auth !== null) {
			return $auth;
		}

		$error = $this->validateId(value: $id, label: 'id');
		if ($error !== null) {
			return $error;
		}

		try {
			$existing = $this->toArray(
				row: $this->objectService()
					->setRegister($this->register())
					->setSchema('CBSSubmission')
					->find($id)
			);
		} catch (\Throwable $e) {
			return new JSONResponse(
				['error' => 'cbs-submission-not-found', 'message' => 'CBS submission not found'],
				Http::STATUS_NOT_FOUND
			);
		}

		$accessError = $this->requireAdministrationAccess(administrationId: (string)($existing['administrationId'] ?? ''));
		if ($accessError !== null) {
			return $accessError;
		}

		$status = (string)($existing['status'] ?? '');
		if ($status !== '' && $status !== 'draft') {
			return new JSONResponse(
				[
					'error' => 'conflict',
					'message' => 'Only draft submissions may be deleted (current status: ' . $status . ')',
				],
				Http::STATUS_CONFLICT,
			);
		}

		return $this->run(
			action: 'delete CBS submission',
			compute: function () use ($id): array {
				$this->objectService()
					->setRegister($this->register())
					->setSchema('CBSSubmission')
					->deleteObject($id);
				return ['id' => $id, 'deleted' => true];
			},
			context: ['id' => $id],
		);

	}//end destroy()

	/**
	 * POST /api/cbs-submissions/{id}/generate — trigger the export pipeline.
	 *
	 * Reads the existing submission (must be in `draft` state), runs
	 * `CBSExportService::generateSubmission()` with the submission's
	 * reporting period and organization metadata, returns the regenerated
	 * submission + new CBSLine records (REQ-CBS-004).
	 *
	 * @param string $id The CBSSubmission id.
	 *
	 * @return JSONResponse 200 with the regenerated submission; 400/403/404/409.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	#[NoAdminRequired]
	public function generate(string $id): JSONResponse {
		$auth = $this->requireUser();
		if ($auth !== null) {
			return $auth;
		}

		$error = $this->validateId(value: $id, label: 'id');
		if ($error !== null) {
			return $error;
		}

		try {
			$existing = $this->toArray(
				row: $this->objectService()
					->setRegister($this->register())
					->setSchema('CBSSubmission')
					->find($id)
			);
		} catch (\Throwable $e) {
			return new JSONResponse(
				['error' => 'cbs-submission-not-found', 'message' => 'CBS submission not found'],
				Http::STATUS_NOT_FOUND
			);
		}

		$accessError = $this->requireAdministrationAccess(administrationId: (string)($existing['administrationId'] ?? ''));
		if ($accessError !== null) {
			return $accessError;
		}

		if ((string)($existing['status'] ?? 'draft') !== 'draft') {
			return new JSONResponse(
				[
					'error' => 'conflict',
					'message' => 'Only draft submissions may be regenerated',
				],
				Http::STATUS_CONFLICT,
			);
		}

		$start = new DateTimeImmutable((string)$existing['reportingPeriodStartDate']);
		$end = new DateTimeImmutable((string)$existing['reportingPeriodEndDate']);

		return $this->run(
			action: 'generate CBS submission',
			compute: fn (): array => $this->exportService->generateSubmission(
				administrationId: (string)$existing['administrationId'],
				periodStart: $start,
				periodEnd: $end,
				organization: [
					'legalName' => (string)($existing['organizationLegalName'] ?? ''),
					'kvkNumber' => (string)($existing['kvkNumber'] ?? ''),
					'taxIdentificationNumber' => (string)($existing['taxIdentificationNumber'] ?? ''),
				],
			),
			context: ['id' => $id, 'administrationId' => (string)$existing['administrationId']],
		);

	}//end generate()

	/**
	 * Build the filters array for the list endpoint from request params.
	 *
	 * @return array<string,mixed> The filter map.
	 */
	private function buildIndexFilters(): array {
		$filters = [];
		$status = trim((string)$this->request->getParam('status', ''));
		if ($status !== '') {
			$filters['status'] = $status;
		}

		$admin = trim((string)$this->request->getParam('administrationId', ''));
		if ($admin !== '') {
			$filters['administrationId'] = $admin;
		}

		return $filters;
	}//end buildIndexFilters()

	/**
	 * Build the show endpoint payload — submission + lines.
	 *
	 * @param string $id The CBSSubmission id.
	 *
	 * @return array<string,mixed>
	 */
	private function buildShowPayload(string $id): array {
		$submission = $this->toArray(
			row: $this->objectService()
				->setRegister($this->register())
				->setSchema('CBSSubmission')
				->find($id)
		);

		$lines = array_map(
			fn (mixed $row): array => $this->toArray(row: $row),
			$this->objectService()
				->setRegister($this->register())
				->setSchema('CBSLine')
				->findAll(['filters' => ['cbsSubmissionId' => $id]])
		);

		return [
			'submission' => $submission,
			'lines' => $lines,
		];

	}//end buildShowPayload()

	/**
	 * Normalise an OpenRegister ObjectService row (ObjectEntityInterface or
	 * array) to a plain array<string,mixed>. Every method in this
	 * controller does array-bracket access on find()/findAll() results
	 * (`$existing['administrationId']` etc.); the real ADR-084
	 * `ObjectServiceInterface::find()` contract returns
	 * `?ObjectEntityInterface` (an object, per
	 * `OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub` /
	 * `DuckObjectServiceAdapter`), so a raw object must be unwrapped via
	 * `jsonSerialize()`/`getObject()` before array access is safe — mirrors
	 * the same normalisation `CalendarController::toArray()` and
	 * `BankRuleController::toArray()` already perform.
	 *
	 * @param mixed $row Raw row from ObjectService::find()/findAll()/saveObject().
	 *
	 * @return array<string,mixed> The row as a plain array (empty array when unusable/null).
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$serialized = $row->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$inner = $row->getObject();
			if (is_array($inner) === true) {
				return $inner;
			}
		}

		return [];
	}//end toArray()

	/**
	 * Decode the JSON request body into an associative array.
	 *
	 * @return array<string,mixed> The decoded body, or an empty array on failure.
	 */
	private function jsonBody(): array {
		$raw = $this->request->getParams();
		if (is_array($raw) === true && $raw !== []) {
			// The NC AppFramework already merges JSON bodies into params; strip
			// the routing scaffolding fields the framework injects.
			unset($raw['_route']);
			return $raw;
		}

		return [];
	}//end jsonBody()

	/**
	 * Require a set of fields in the request body.
	 *
	 * @param array<string,mixed> $body The body.
	 * @param array<int,string> $fields The required field names.
	 *
	 * @return JSONResponse|null A 400 response when missing, null when ok.
	 */
	private function requireFields(array $body, array $fields): ?JSONResponse {
		foreach ($fields as $field) {
			if (isset($body[$field]) === false || $body[$field] === '') {
				return new JSONResponse(
					[
						'error' => 'missing field',
						'message' => $field . ' is required',
					],
					Http::STATUS_BAD_REQUEST,
				);
			}
		}

		return null;
	}//end requireFields()

	/**
	 * Validate a short identifier (slug pattern).
	 *
	 * @param string $value The id value.
	 * @param string $label The parameter label for the error message.
	 *
	 * @return JSONResponse|null A 400 response when invalid, null when ok.
	 */
	private function validateId(string $value, string $label): ?JSONResponse {
		if ($value === '') {
			return new JSONResponse(
				['error' => 'missing field', 'message' => $label . ' is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (preg_match(self::ID_PATTERN, $value) !== 1) {
			return new JSONResponse(
				['error' => 'invalid id', 'message' => $label . ' must be a valid identifier'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end validateId()

	/**
	 * Execute a service/data call, mapping any failure to a generic 500.
	 *
	 * @param string $action Human action label for the error log.
	 * @param callable():array $compute The service/data call.
	 * @param array<string,mixed> $context Log context.
	 * @param int $status HTTP status on success (default 200).
	 *
	 * @return JSONResponse The mapped response.
	 */
	private function run(string $action, callable $compute, array $context, int $status = Http::STATUS_OK): JSONResponse {
		try {
			$result = $compute();
		} catch (\Throwable $e) {
			$this->logger->error(
				'CBSSubmissionController: failed to ' . $action,
				($context + ['exception' => $e->getMessage()])
			);
			return new JSONResponse(
				[
					'error' => 'internal',
					'message' => 'Failed to ' . $action,
				],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		return new JSONResponse($result, $status);
	}//end run()

	/**
	 * Lazy DI of OpenRegister's ObjectService.
	 *
	 * The injected property is already `ObjectServiceInterface`; this accessor
	 * used to widen it back to a bare `object`, which threw away every method
	 * signature and left the JSONResponse payloads built from its results
	 * unresolvable to static analysis.
	 *
	 * @return ObjectServiceInterface The ObjectService instance.
	 */
	private function objectService(): ObjectServiceInterface {
		return $this->objectService;
	}//end objectService()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
