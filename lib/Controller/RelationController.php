<?php

/**
 * Relation Controller
 *
 * The relations that are both customer and supplier
 * (reporting-relation-both-sides): suggestions and links, both sides of one
 * relation for the customer and supplier pages, and the report of all of
 * them. Every endpoint checks the caller's membership of the administration
 * first and masks another administration as absent (ADR-005). Which side a
 * caller sees follows their role in the administration (REQ-RRBS-004).
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
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use DomainException;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Relation\RelationBothSidesService;
use OCA\Shillinq\Service\Relation\RelationLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Serves relation links and both-sides views.
 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
 */
class RelationController extends Controller {

	/**
	 * Roles that may read the sales side: everyone but the payables and payroll roles.
	 */
	public const SENT_ROLES = ['eigenaar', 'controller', 'boekhouder', 'inkijker', 'accountant_extern', 'debiteurenadmin'];

	/**
	 * Roles that may read the purchase side: everyone but the receivables and payroll roles.
	 */
	public const RECEIVED_ROLES = ['eigenaar', 'controller', 'boekhouder', 'inkijker', 'accountant_extern', 'crediteurenadmin'];

	/**
	 * Roles that may link and dismiss: the roles that keep customer or supplier records.
	 */
	public const LINK_ROLES = ['eigenaar', 'controller', 'boekhouder', 'debiteurenadmin', 'crediteurenadmin'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request   The request.
	 * @param AdministrationContextService $context   The caller's administrations.
	 * @param RelationLinkService          $links     Suggestions and links.
	 * @param RelationBothSidesService     $bothSides Both sides of a relation.
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextService $context,
		private readonly RelationLinkService $links,
		private readonly RelationBothSidesService $bothSides,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * GET /api/relations/{customerId}/both-sides?from=&to=: both sides of one customer's relation.
	 *
	 * @param string $customerId The customer.
	 *
	 * @return JSONResponse The relation, 400, 401 or a masked 404.
	 *
	 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	#[NoAdminRequired]
	public function bothSides(string $customerId): JSONResponse {
		$scope = $this->authorizeScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		try {
			return new JSONResponse(
				$this->bothSides->forCustomer(
					administrationId: $scope['administrationId'],
					customerId: $customerId,
					from: $scope['from'],
					to: $scope['to'],
					sides: $scope['sides']
				)
			);
		} catch (DomainException $e) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

	}//end bothSides()

	/**
	 * GET /api/relations/payee/{payeeId}/both-sides: the same view from the supplier's page.
	 *
	 * @param string $payeeId The supplier.
	 *
	 * @return JSONResponse The relation, `{linked: false}` for an unlinked supplier, or an error.
	 *
	 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	#[NoAdminRequired]
	public function payeeBothSides(string $payeeId): JSONResponse {
		$scope = $this->authorizeScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		$customerId = $this->bothSides->customerOfPayee(administrationId: $scope['administrationId'], payeeId: $payeeId);
		if ($customerId === null) {
			return new JSONResponse(['linked' => false]);
		}

		return $this->bothSides($customerId);

	}//end payeeBothSides()

	/**
	 * GET /api/relations/both-sides?from=&to=&format=csv: every linked relation with its totals.
	 *
	 * @return Response JSON, or the CSV download when format=csv.
	 *
	 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	#[NoAdminRequired]
	public function report(): Response {
		$scope = $this->authorizeScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		$report = $this->bothSides->forAdministration(
			administrationId: $scope['administrationId'],
			from: $scope['from'],
			to: $scope['to'],
			sides: $scope['sides']
		);
		if ($this->request->getParam('format', '') === 'csv') {
			return new DataDownloadResponse(
				$this->bothSides->toCsv(relations: $report['relations']),
				'relations-both-ways-' . $scope['from'] . '-' . $scope['to'] . '.csv',
				'text/csv'
			);
		}

		return new JSONResponse($report);

	}//end report()

	/**
	 * GET /api/relations/suggestions: unlinked pairs with an equal KvK or VAT number.
	 *
	 * @return JSONResponse `{suggestions: [...]}` or an error.
	 *
	 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	#[NoAdminRequired]
	public function suggestions(): JSONResponse {
		$scope = $this->authorizeScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		return new JSONResponse(['suggestions' => $this->links->suggestions(administrationId: $scope['administrationId'])]);

	}//end suggestions()

	/**
	 * POST /api/relations/links {customerId, payeeId, matchedOn}: confirm a suggestion or link by hand.
	 *
	 * @return JSONResponse The link, 403 for a role that may not link, 404 or 409.
	 *
	 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	#[NoAdminRequired]
	public function link(): JSONResponse {
		$scope = $this->authorizeScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		if ($scope['mayLink'] === false) {
			return new JSONResponse(['error' => 'Your role may not link relations'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse(
				$this->links->link(
					administrationId: $scope['administrationId'],
					customerId: (string)$this->request->getParam('customerId', ''),
					payeeId: (string)$this->request->getParam('payeeId', ''),
					userId: $scope['userId'],
					matchedOn: (string)$this->request->getParam('matchedOn', 'manual')
				)
			);
		} catch (DomainException $e) {
			return $this->refusal(exception: $e);
		}

	}//end link()

	/**
	 * POST /api/relations/suggestions/dismiss {customerId, payeeId}: stop suggesting a pair.
	 *
	 * @return JSONResponse `{dismissed: true}`, 403 or 404.
	 *
	 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	#[NoAdminRequired]
	public function dismiss(): JSONResponse {
		$scope = $this->authorizeScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		if ($scope['mayLink'] === false) {
			return new JSONResponse(['error' => 'Your role may not link relations'], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->links->dismiss(
				administrationId: $scope['administrationId'],
				customerId: (string)$this->request->getParam('customerId', ''),
				payeeId: (string)$this->request->getParam('payeeId', '')
			);
		} catch (DomainException $e) {
			return $this->refusal(exception: $e);
		}

		return new JSONResponse(['dismissed' => true]);

	}//end dismiss()

	/**
	 * DELETE /api/relations/links/{customerId}: remove a customer's link.
	 *
	 * @param string $customerId The customer.
	 *
	 * @return JSONResponse `{unlinked: true}`, 403 or 404.
	 *
	 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	#[NoAdminRequired]
	public function unlink(string $customerId): JSONResponse {
		$scope = $this->authorizeScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		if ($scope['mayLink'] === false) {
			return new JSONResponse(['error' => 'Your role may not link relations'], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->links->unlink(administrationId: $scope['administrationId'], customerId: $customerId);
		} catch (DomainException $e) {
			return $this->refusal(exception: $e);
		}

		return new JSONResponse(['unlinked' => true]);

	}//end unlink()

	/**
	 * The caller, their administration, the period and what their role may do; or the error response.
	 *
	 * @return array<string, mixed>|JSONResponse The scope, or 401, 400 or a masked 404.
	 */
	private function authorizeScope(): array|JSONResponse {
		$userId = $this->context->currentUserId();
		if ($userId === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$context          = $this->context->buildContext();
		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		if ($administrationId === '') {
			$administrationId = trim((string)($context['activeAdministrationId'] ?? ''));
		}

		if ($administrationId === '' || $this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$year = (new DateTimeImmutable())->format('Y');
		// A dashboard's date range sends ISO moments; the day is what counts.
		$from = substr((string)$this->request->getParam('from', $year . '-01-01'), 0, 10);
		$to   = substr((string)$this->request->getParam('to', $year . '-12-31'), 0, 10);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1 || $to < $from) {
			return new JSONResponse(['error' => 'Dates must read YYYY-MM-DD, the first before the last'], Http::STATUS_BAD_REQUEST);
		}

		$role = '';
		foreach (($context['administrations'] ?? []) as $administration) {
			if ((string)($administration['administrationId'] ?? '') === $administrationId) {
				$role = (string)($administration['role'] ?? '');
			}
		}

		return [
			'userId'           => $userId,
			'administrationId' => $administrationId,
			'from'             => $from,
			'to'               => $to,
			'sides'            => [
				'sent'     => in_array($role, self::SENT_ROLES, true),
				'received' => in_array($role, self::RECEIVED_ROLES, true),
			],
			'mayLink'          => in_array($role, self::LINK_ROLES, true),
		];

	}//end authorizeScope()

	/**
	 * A refused link as a response: 404 for a record not found, 409 otherwise.
	 *
	 * @param DomainException $exception The refusal.
	 *
	 * @return JSONResponse The response.
	 */
	private function refusal(DomainException $exception): JSONResponse {
		if ($exception->getMessage() === 'Not found') {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_CONFLICT);

	}//end refusal()
}//end class
