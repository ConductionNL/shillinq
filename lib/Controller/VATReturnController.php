<?php

/**
 * VAT Return Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tier-3 BTW-aangifte HTTP API for the bookkeeping-vat-btw-filing
 * change (issue #127). Exposes the seven endpoints declared in
 * tasks.md:
 *
 *   GET    /api/vat-returns                       — list (paginated, filterable)
 *   GET    /api/vat-returns/{returnId}             — detail
 *   POST   /api/vat-returns                       — create + derive from GL
 *   PUT    /api/vat-returns/{returnId}             — update notes
 *   POST   /api/vat-returns/{returnId}/submit      — submit
 *   POST   /api/vat-returns/{returnId}/rebase      — re-open + re-derive
 *   DELETE /api/vat-returns/{returnId}             — delete (draft only)
 *
 * The endpoints are authenticated (#[NoAdminRequired]) AND authorised
 * per administration in this controller, via
 * AdministrationContextService::canAccess() (ADR-005, REQ-MA-001).
 *
 * ⚠️ The previous version of this paragraph claimed authorisation was
 * "delegated to OpenRegister's ObjectService which enforces
 * administration-scoped multitenancy". That was false in both halves:
 * none of these endpoints passes an administration term into
 * OpenRegister at all, and a schema that declares no `authorization`
 * block — as all ~871 of this app's schemas do — grants every action to
 * every authenticated user (openregister PermissionHandler::
 * hasGroupPermission(), `enforce_default_closed` defaults false). The
 * controller guard is not the outer of two layers; it is the only layer.
 *
 * Future returns are rejected at the controller (REQ-VAT-001);
 * lifecycle status checks live in VATReturnService.
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
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\VATReturnServiceInterface;
use OCA\Shillinq\Service\Vat\VatFiscalUnityMemberException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP API for VAT returns (REQ-VAT-001 .. REQ-VAT-012).
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The total is the sum of small private steps; each method stays under the method thresholds.
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 */
class VATReturnController extends Controller {
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	private const PERIOD_VALUES = ['quarter', 'month', 'year'];

	private const REGIME_VALUES = ['standard', 'kor', 'reverse-charge'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param VATReturnServiceInterface $service The VAT return service.
	 * @param ContainerInterface $container DI container for OR's ObjectService.
	 * @param IUserSession $session User session (for actor identity).
	 * @param AdministrationContextServiceInterface $context RBAC guard — resolves the user's administration memberships.
	 * @param LoggerInterface $logger Logger.
	 * @param IL10N $l10n Localized strings for client-facing error messages (ADR-050).
	 */
	public function __construct(
		IRequest $request,
		private readonly VATReturnServiceInterface $service,
		private readonly ContainerInterface $container,
		private readonly IUserSession $session,
		private readonly AdministrationContextServiceInterface $context,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List VAT returns (paginated, filterable by period / regime / status).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$filters = $this->buildListFilters();
		$page    = max(1, (int)$this->request->getParam('_page', 1));
		$limit   = max(1, min(200, (int)$this->request->getParam('_limit', 25)));

		$scope = $this->listScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		$all = $this->returnsIn(scope: $scope, filters: $filters);
		if ($all === null) {
			return new JSONResponse(['error' => 'Failed to list VAT returns'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->pageResponse(all: $all, page: $page, limit: $limit);

	}//end index()

	/**
	 * ADR-005 / REQ-MA-001. The administrationId filter used to be OPTIONAL,
	 * so omitting it listed every tenant's statutory VAT returns. The scope
	 * is now always the caller's memberships; an explicit administrationId
	 * may only NARROW it, never widen it.
	 *
	 * @return array<int, string>|JSONResponse The administrations to list, or a 404 for a foreign one.
	 */
	private function listScope(): array|JSONResponse {
		$requested = trim((string)$this->request->getParam('administrationId', ''));
		$scope     = $this->context->accessibleAdministrationIds();
		if ($requested !== '') {
			if ($this->context->canAccess(administrationId: $requested) === false) {
				return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
			}

			$scope = [$requested];
		}

		return $scope;
	}//end listScope()

	/**
	 * The VAT returns of every administration in scope, or null when the lookup fails.
	 *
	 * @param array<int, string>   $scope   The administrations to list.
	 * @param array<string, mixed> $filters The list filters.
	 *
	 * @return array<int, mixed>|null
	 */
	private function returnsIn(array $scope, array $filters): array|null {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$all = [];
			foreach ($scope as $administrationId) {
				$all = array_merge(
					$all,
					$objectService
						->setRegister(register: 'shillinq')
						->setSchema(schema: 'BtwAangifte')
						->findAll(['filters' => ($filters + ['administrationId' => $administrationId])])
				);
			}
		} catch (\Throwable $e) {
			$this->logger->error('VATReturnController: failed to list VAT returns', ['exception' => $e->getMessage()]);
			return null;
		}

		return $all;
	}//end returnsIn()

	/**
	 * One page of the list.
	 *
	 * @param array<int, mixed> $all   Every listed VAT return.
	 * @param int               $page  The page, from 1.
	 * @param int               $limit The page size.
	 *
	 * @return JSONResponse
	 */
	private function pageResponse(array $all, int $page, int $limit): JSONResponse {
		$total  = count($all);
		$pages  = max(1, (int)ceil($total / $limit));
		$offset = (($page - 1) * $limit);
		$slice  = array_slice($all, $offset, $limit);

		return new JSONResponse(['data' => $slice, 'total' => $total, 'page' => $page, 'pages' => $pages, 'limit' => $limit], Http::STATUS_OK);
	}//end pageResponse()

	/**
	 * Return one VAT return by id (with declarations + lines).
	 *
	 * @param string $returnId The VATReturn id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	#[NoAdminRequired]
	public function show(string $returnId): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->validId(id: $returnId) === false) {
			return new JSONResponse(['error' => 'returnId is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			// OpenRegister's ObjectService::find() returns `?ObjectEntity`, never
			// an array. The previous `is_array()` test was therefore false for
			// EVERY row that was actually found, so this endpoint answered 404
			// for every VAT return that exists — including the one POST
			// /api/vat-returns had just created and returned an id for.
			// VATReturnService::findReturn() performs the entity → array
			// normalisation and returns null only when the row is genuinely absent.
			$vatReturn = $this->service->findReturn(returnId: $returnId);
			if ($this->mayAccessReturn(vatReturn: $vatReturn) === false) {
				return new JSONResponse(['error' => 'VAT return not found'], Http::STATUS_NOT_FOUND);
			}

			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$declarations = $objectService
				->setRegister(register: 'shillinq')
				->setSchema(schema: 'VATDeclaration')
				->findAll(['filters' => ['returnId' => $returnId]]);
			$lines = $objectService
				->setRegister(register: 'shillinq')
				->setSchema(schema: 'VATLine')
				->findAll(['filters' => ['returnId' => $returnId]]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'VATReturnController: failed to load VAT return',
				['returnId' => $returnId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'Failed to load VAT return'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return new JSONResponse(
			[
				'data' => $vatReturn,
				'declarations' => $declarations,
				'lines' => $lines,
			],
			Http::STATUS_OK
		);

	}//end show()

	/**
	 * Create a new VAT return (REQ-VAT-001).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		$period = trim((string)$this->request->getParam('period', 'quarter'));
		$periodYear = (int)$this->request->getParam('periodYear', 0);
		$periodNumber = (int)$this->request->getParam('periodNumber', 0);
		$regime = trim((string)$this->request->getParam('regime', 'standard'));

		// Authorisation first, then shape. A caller who may not touch this
		// administration is told nothing about which periods it would accept.
		$refusal = $this->requireAccessibleAdministration(administrationId: $administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		$refusal = $this->validateCreatePeriod(
			period: $period,
			periodYear: $periodYear,
			periodNumber: $periodNumber,
			regime: $regime
		);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$created = $this->service->createReturn(
				administrationId: $administrationId,
				period: $period,
				periodYear: $periodYear,
				periodNumber: $periodNumber,
				regime: $regime
			);
		} catch (VatFiscalUnityMemberException $e) {
			// REQ-TVRB-003: the representative files for the unity; the refusal names it.
			return new JSONResponse(
				[
					'error' => 'vat-fiscal-unity-member',
					'message' => $this->l10n->t(
						'This administration is a member of the VAT fiscal unity %s in this period; the representative files the return for the unity.',
						[$e->unityName]
					),
				],
				Http::STATUS_CONFLICT
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'VATReturnController: failed to create VAT return',
				['exception' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'Failed to create VAT return'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['data' => $created], Http::STATUS_CREATED);
	}//end create()

	/**
	 * Update notes on a draft VAT return.
	 *
	 * @param string $returnId The VATReturn id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	#[NoAdminRequired]
	public function update(string $returnId): JSONResponse {
		$refusal = $this->refuseReturnRequest(returnId: $returnId);
		if ($refusal !== null) {
			return $refusal;
		}

		$notes = (string)$this->request->getParam('notes', '');

		try {
			return $this->updateAccessibleDraft(returnId: $returnId, notes: $notes);
		} catch (\Throwable $e) {
			return $this->returnActionFailed(action: 'update', returnId: $returnId, e: $e);
		}
	}//end update()

	/**
	 * Save the notes on an accessible draft return.
	 *
	 * @param string $returnId The VATReturn id.
	 * @param string $notes    The new notes.
	 *
	 * @return JSONResponse
	 */
	private function updateAccessibleDraft(string $returnId, string $notes): JSONResponse {
		// See show(): find() yields `?ObjectEntity`, so the previous
		// `is_array()` test rejected every existing record as "not found".
		$vatReturn = $this->service->findReturn(returnId: $returnId);
		if ($this->mayAccessReturn(vatReturn: $vatReturn) === false) {
			return new JSONResponse(['error' => 'VAT return not found'], Http::STATUS_NOT_FOUND);
		}

		if ((string)($vatReturn['statusCode'] ?? '') !== 'draft') {
			return new JSONResponse(['error' => 'Only draft returns can be edited; rebase first'], Http::STATUS_CONFLICT);
		}

		$vatReturn['notes'] = $notes;
		$objectService      = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$saved = $objectService->setRegister(register: 'shillinq')->setSchema(schema: 'BtwAangifte')->saveObject($vatReturn);

		return new JSONResponse(['data' => $saved], Http::STATUS_OK);
	}//end updateAccessibleDraft()

	/**
	 * Refuse a request on one return when nobody is logged in or the id is malformed.
	 *
	 * @param string $returnId The VATReturn id.
	 *
	 * @return JSONResponse|null The 401/400 refusal, or null when the request may proceed.
	 */
	private function refuseReturnRequest(string $returnId): JSONResponse|null {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->validId(id: $returnId) === false) {
			return new JSONResponse(['error' => 'returnId is required'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end refuseReturnRequest()

	/**
	 * Log an unexpected failure of an action on one return and answer 500.
	 *
	 * @param string     $action   The action verb, as in "failed to <action> VAT return".
	 * @param string     $returnId The VATReturn id.
	 * @param \Throwable $e        The failure.
	 *
	 * @return JSONResponse
	 */
	private function returnActionFailed(string $action, string $returnId, \Throwable $e): JSONResponse {
		$this->logger->error(
			'VATReturnController: failed to ' . $action . ' VAT return',
			['returnId' => $returnId, 'exception' => $e->getMessage()]
		);

		return new JSONResponse(['error' => 'Failed to ' . $action . ' VAT return'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end returnActionFailed()

	/**
	 * Log a refused state change of one return and answer 409 with its translated message.
	 *
	 * @param string            $action   The action, as in "VATReturnController.<action> failed".
	 * @param string            $returnId The VATReturn id.
	 * @param \RuntimeException $e        The refusal.
	 * @param string            $message  The translated message.
	 *
	 * @return JSONResponse
	 */
	private function returnActionConflict(string $action, string $returnId, \RuntimeException $e, string $message): JSONResponse {
		$this->logger->error(
			'VATReturnController.' . $action . ' failed',
			['returnId' => $returnId, 'exception' => $e]
		);

		return new JSONResponse(['message' => $message, 'error' => 'vat-return-' . $action . '-failed'], Http::STATUS_CONFLICT);
	}//end returnActionConflict()

	/**
	 * Whether the caller may act on the return, as found by id.
	 *
	 * @param string $returnId The VATReturn id.
	 *
	 * @return bool
	 */
	private function mayAccessReturnId(string $returnId): bool {
		return $this->mayAccessReturn(vatReturn: $this->service->findReturn(returnId: $returnId));
	}//end mayAccessReturnId()

	/**
	 * Submit a VAT return (REQ-VAT-005).
	 *
	 * @param string $returnId The VATReturn id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function submit(string $returnId): JSONResponse {
		$refusal = $this->refuseReturnRequest(returnId: $returnId);
		if ($refusal !== null) {
			return $refusal;
		}

		$userId = $this->resolveUserId();

		try {
			// ADR-005 / REQ-MA-001 — filing another tenant's statutory return.
			// $userId below is stamped as the actor but was never compared to
			// anything, so it authorised nothing on its own.
			if ($this->mayAccessReturnId(returnId: $returnId) === false) {
				return new JSONResponse(['error' => 'VAT return not found'], Http::STATUS_NOT_FOUND);
			}

			$vatReturn = $this->service->submitReturn(returnId: $returnId, userId: $userId);
		} catch (\RuntimeException $e) {
			$message = $this->l10n->t('Unable to submit VAT return');
			return $this->returnActionConflict(action: 'submit', returnId: $returnId, e: $e, message: $message);
		} catch (\Throwable $e) {
			return $this->returnActionFailed(action: 'submit', returnId: $returnId, e: $e);
		}

		return new JSONResponse(['data' => $vatReturn], Http::STATUS_OK);
	}//end submit()

	/**
	 * Rebase a submitted VAT return back to draft (REQ-VAT-008).
	 *
	 * @param string $returnId The VATReturn id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function rebase(string $returnId): JSONResponse {
		$refusal = $this->refuseReturnRequest(returnId: $returnId);
		if ($refusal !== null) {
			return $refusal;
		}

		$userId = $this->resolveUserId();

		try {
			// ADR-005 / REQ-MA-001 — reverting another tenant's SUBMITTED
			// statutory filing back to draft.
			if ($this->mayAccessReturnId(returnId: $returnId) === false) {
				return new JSONResponse(['error' => 'VAT return not found'], Http::STATUS_NOT_FOUND);
			}

			$vatReturn = $this->service->rebaseReturn(returnId: $returnId, userId: $userId);
		} catch (\RuntimeException $e) {
			$message = $this->l10n->t('Unable to rebase VAT return');
			return $this->returnActionConflict(action: 'rebase', returnId: $returnId, e: $e, message: $message);
		} catch (\Throwable $e) {
			return $this->returnActionFailed(action: 'rebase', returnId: $returnId, e: $e);
		}

		return new JSONResponse(['data' => $vatReturn], Http::STATUS_OK);
	}//end rebase()

	/**
	 * Delete a VAT return — draft only.
	 *
	 * @param string $returnId The VATReturn id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	#[NoAdminRequired]
	public function destroy(string $returnId): JSONResponse {
		$refusal = $this->refuseReturnRequest(returnId: $returnId);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->deleteAccessibleDraft(returnId: $returnId);
		} catch (\Throwable $e) {
			return $this->returnActionFailed(action: 'delete', returnId: $returnId, e: $e);
		}
	}//end destroy()

	/**
	 * Delete an accessible draft return.
	 *
	 * @param string $returnId The VATReturn id.
	 *
	 * @return JSONResponse
	 */
	private function deleteAccessibleDraft(string $returnId): JSONResponse {
		// See show(): find() yields `?ObjectEntity`, so the previous
		// `is_array()` test masked every existing record as 404 — which
		// meant a SUBMITTED return answered 404 instead of the 409 the
		// "only draft returns can be deleted" rule is supposed to give.
		$vatReturn = $this->service->findReturn(returnId: $returnId);
		if ($this->mayAccessReturn(vatReturn: $vatReturn) === false) {
			return new JSONResponse(['error' => 'VAT return not found'], Http::STATUS_NOT_FOUND);
		}

		if ((string)($vatReturn['statusCode'] ?? '') !== 'draft') {
			return new JSONResponse(['error' => 'Only draft returns can be deleted'], Http::STATUS_CONFLICT);
		}

		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$objectService->setRegister(register: 'shillinq')->setSchema(schema: 'BtwAangifte')->deleteObject($returnId);

		return new JSONResponse(['data' => ['id' => $returnId, 'deleted' => true]], Http::STATUS_OK);
	}//end deleteAccessibleDraft()

	/**
	 * Require an administrationId that is well-formed AND one of the caller's (ADR-005).
	 *
	 * VATReturnService::createReturn() documents its $administrationId as
	 * "Server-resolved administration scope". This controller reads it off the
	 * wire, so the membership check that makes that contract true has to happen
	 * here. Without it any authenticated user could inject a statutory Dutch VAT
	 * filing into any administration (REQ-MA-001).
	 *
	 * @param string $administrationId The administration id read from the request.
	 *
	 * @return JSONResponse|null A 400/404 response when refused, null when allowed.
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	private function requireAccessibleAdministration(string $administrationId): ?JSONResponse {
		if ($this->validId(id: $administrationId) === false) {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end requireAccessibleAdministration()

	/**
	 * Validate the period/regime shape of a create request (REQ-VAT-001).
	 *
	 * Split out of create() so the method keeps a readable cyclomatic
	 * complexity now that the ADR-005 membership check lives there too.
	 *
	 * @param string $period quarter | month | year.
	 * @param int $periodYear Fiscal year.
	 * @param int $periodNumber Period within the year.
	 * @param string $regime standard | kor | reverse-charge.
	 *
	 * @return JSONResponse|null A 400 response when invalid, null when acceptable.
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	private function validateCreatePeriod(
		string $period,
		int $periodYear,
		int $periodNumber,
		string $regime,
	): ?JSONResponse {
		if (in_array($period, self::PERIOD_VALUES, true) === false) {
			return new JSONResponse(['error' => 'period must be one of quarter | month | year'], Http::STATUS_BAD_REQUEST);
		}

		if (in_array($regime, self::REGIME_VALUES, true) === false) {
			return new JSONResponse(['error' => 'regime must be one of standard | kor | reverse-charge'], Http::STATUS_BAD_REQUEST);
		}

		return $this->validatePeriodNumber(period: $period, periodYear: $periodYear, periodNumber: $periodNumber);
	}//end validateCreatePeriod()

	/**
	 * Validate the period's year and number, and refuse future periods.
	 *
	 * @param string $period       quarter | month | year.
	 * @param int    $periodYear   Fiscal year.
	 * @param int    $periodNumber Period within the year.
	 *
	 * @return JSONResponse|null A 400 response when invalid, null when acceptable.
	 */
	private function validatePeriodNumber(string $period, int $periodYear, int $periodNumber): JSONResponse|null {
		$error = self::periodNumberError(period: $period, periodYear: $periodYear, periodNumber: $periodNumber);
		if ($error !== '') {
			return new JSONResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
		}

		if ($this->isPeriodInFuture(periodYear: $periodYear, periodNumber: $periodNumber, period: $period) === true) {
			return new JSONResponse(['error' => 'Cannot create returns for future periods'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end validatePeriodNumber()

	/**
	 * The error for an out-of-range year or period number, or '' when they are valid.
	 *
	 * @param string $period       quarter | month | year.
	 * @param int    $periodYear   Fiscal year.
	 * @param int    $periodNumber Period within the year.
	 *
	 * @return string
	 */
	private static function periodNumberError(string $period, int $periodYear, int $periodNumber): string {
		if ($periodYear < 2020 || $periodYear > 2099 || $periodNumber < 1) {
			return 'periodYear / periodNumber must be valid';
		}

		return self::periodRangeError(period: $period, periodNumber: $periodNumber);
	}//end periodNumberError()

	/**
	 * The error for a period number beyond the quarters or months of a year, or '' when it fits.
	 *
	 * @param string $period       quarter | month | year.
	 * @param int    $periodNumber Period within the year.
	 *
	 * @return string
	 */
	private static function periodRangeError(string $period, int $periodNumber): string {
		if ($period === 'quarter' && $periodNumber > 4) {
			return 'periodNumber must be 1..4 for quarter';
		}

		if ($period === 'month' && $periodNumber > 12) {
			return 'periodNumber must be 1..12 for month';
		}

		return '';
	}//end periodRangeError()

	/**
	 * Whether the caller may act on a loaded VAT return (ADR-005 IDOR guard).
	 *
	 * A missing row and a row belonging to an administration the caller has no
	 * membership for are deliberately indistinguishable — canAccess()'s contract
	 * is that a refusal is masked as a 404 and never confirms the record exists.
	 *
	 * @param array<string,mixed>|null $vatReturn The loaded VAT return, or null.
	 *
	 * @return bool True when the row exists AND the caller is a member of its administration.
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	private function mayAccessReturn(?array $vatReturn): bool {
		if ($vatReturn === null) {
			return false;
		}

		return $this->context->canAccess(
			administrationId: (string)($vatReturn['administrationId'] ?? '')
		);

	}//end mayAccessReturn()

	/**
	 * Build the list-filter map from query params; only allow whitelisted keys.
	 *
	 * @return array<string,mixed>
	 */
	private function buildListFilters(): array {
		$filters = [];

		$period = (string)$this->request->getParam('period', '');
		if (in_array($period, self::PERIOD_VALUES, true) === true) {
			$filters['period'] = $period;
		}

		$regime = (string)$this->request->getParam('regime', '');
		if (in_array($regime, self::REGIME_VALUES, true) === true) {
			$filters['regime'] = $regime;
		}

		$status = (string)$this->request->getParam('status', '');
		if (in_array($status, ['draft', 'submitted', 'verified', 'filed'], true) === true) {
			$filters['statusCode'] = $status;
		}

		// The administrationId param is deliberately NOT read here: index()
		// resolves the administration scope from the caller's memberships and
		// only ever narrows it (ADR-005). Reading it as a plain filter here
		// would let an unguarded value back in.
		return $filters;
	}//end buildListFilters()

	/**
	 * Decide whether the requested period is in the future (REQ-VAT-001 validation).
	 *
	 * @param int $periodYear Fiscal year.
	 * @param int $periodNumber Period within year.
	 * @param string $period quarter | month | year.
	 *
	 * @return bool True when the requested period starts after today.
	 */
	private function isPeriodInFuture(int $periodYear, int $periodNumber, string $period): bool {
		$today = gmdate(format: 'Y-m-d');
		$currentYear = (int)substr($today, 0, 4);
		$currentMonth = (int)substr($today, 5, 2);

		if ($periodYear > $currentYear) {
			return true;
		}

		if ($periodYear < $currentYear) {
			return false;
		}

		// Same year.
		if ($period === 'quarter') {
			$startMonth = (1 + (($periodNumber - 1) * 3));
			return $startMonth > $currentMonth;
		}

		if ($period === 'month') {
			return $periodNumber > $currentMonth;
		}

		// Year period this year is in-progress; allow.
		return false;
	}//end isPeriodInFuture()

	/**
	 * Validate an opaque identifier (administration / return / declaration / line).
	 *
	 * @param string $id Candidate identifier.
	 *
	 * @return bool True when the identifier is non-empty and well-formed.
	 */
	private function validId(string $id): bool {
		if ($id === '') {
			return false;
		}

		return (preg_match(pattern: self::ID_PATTERN, subject: $id) === 1);
	}//end validId()

	/**
	 * Resolve the actor's user identifier; falls back to 'system'.
	 *
	 * @return string The user identifier.
	 */
	private function resolveUserId(): string {
		$user = $this->session->getUser();
		if ($user !== null) {
			return $user->getUID();
		}

		return 'system';
	}//end resolveUserId()
}//end class
