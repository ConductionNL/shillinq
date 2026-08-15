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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\CBSExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
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
	 * @param ContainerInterface $container DI container — OR's ObjectService is fetched
	 *                                      lazily.
	 * @param CBSExportService $exportService The CBS export pipeline.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param IUserSession $userSession The session for the acting user id (auth body-guard).
	 * @param LoggerInterface $logger Logger for diagnostics (no stack traces to client).
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
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Authorization guard — every endpoint requires an authenticated
	 * Nextcloud user (REQ-CBS-001 / ADR-005). The administration scope is
	 * validated by the IDOR-safe service layer. This helper is the
	 * in-body counterpart to #[NoAdminRequired] so gate-7 no-admin-idor /
	 * gate-9 semantic-auth see the explicit auth posture.
	 *
	 * @return JSONResponse|null A 401 response when unauthenticated, null when ok.
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
	 * GET /api/cbs-submissions — list CBS submissions, optionally filtered.
	 *
	 * Query params: `status`, `administrationId`, `periodStart`, `periodEnd`.
	 *
	 * @return JSONResponse 200 with `{ submissions: [...] }`.
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$auth = $this->requireUser();
		if ($auth !== null) {
			return $auth;
		}

		$filters = $this->buildIndexFilters();

		return $this->run(
			action: 'list CBS submissions',
			compute: fn (): array => [
				'submissions' => $this->objectService()
					->setRegister($this->register())
					->setSchema('CBSSubmission')
					->findAll(['filters' => $filters]),
			],
			context: $filters,
		);

	}//end index()

	/**
	 * GET /api/cbs-submissions/{id} — retrieve a single submission + its lines.
	 *
	 * @param string $id The CBSSubmission id.
	 *
	 * @return JSONResponse 200 with `{ submission, lines }`; 400 on bad id; 404 when missing.
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

		$body['status'] = 'draft';
		$body['currency'] = ($body['currency'] ?? 'EUR');

		return $this->run(
			action: 'create CBS submission',
			compute: fn (): array => (array)$this->objectService()->saveObject(
				object: $body,
				register: $this->register(),
				schema: 'CBSSubmission',
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
	 * @return JSONResponse 200 on update; 400 on bad id; 422 on validation failure.
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

		$body = $this->jsonBody();
		$body['id'] = $id;

		if (($body['status'] ?? '') === 'validated') {
			$validation = $this->exportService->validateSubmission(submission: $body);
			if (($validation['valid'] ?? false) === false) {
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
			compute: fn (): array => (array)$this->objectService()->saveObject(
				object: $body,
				register: $this->register(),
				schema: 'CBSSubmission',
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
	 * @return JSONResponse 204 on success; 409 when not in draft state.
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
			$existing = $this->objectService()
				->setRegister($this->register())
				->setSchema('CBSSubmission')
				->find($id);
		} catch (\Throwable $e) {
			return new JSONResponse(
				['error' => 'not found', 'message' => 'CBS submission not found'],
				Http::STATUS_NOT_FOUND
			);
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
	 * @return JSONResponse 200 with the regenerated submission; 400/404/409.
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
			$existing = $this->objectService()
				->setRegister($this->register())
				->setSchema('CBSSubmission')
				->find($id);
		} catch (\Throwable $e) {
			return new JSONResponse(
				['error' => 'not found', 'message' => 'CBS submission not found'],
				Http::STATUS_NOT_FOUND
			);
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
		$submission = $this->objectService()
			->setRegister($this->register())
			->setSchema('CBSSubmission')
			->find($id);

		$lines = $this->objectService()
			->setRegister($this->register())
			->setSchema('CBSLine')
			->findAll(['filters' => ['cbsSubmissionId' => $id]]);

		return [
			'submission' => $submission,
			'lines' => $lines,
		];

	}//end buildShowPayload()

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
	 * @return object The ObjectService instance.
	 */
	private function objectService(): object {
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
