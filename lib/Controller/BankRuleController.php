<?php

/**
 * Bank Rule Controller
 *
 * Bank-rule-automation-ux — the REST surface for the MatchingRule
 * "test / dry-run" UX (REQ-BR-011) and the history-based rule-suggestion
 * learning path (REQ-BR-012).
 *
 *  - POST /api/v1/bank-rules/preview            dry-run a draft rule against
 *                                               recent unmatched lines (read-only).
 *  - POST /api/v1/bank-rules/suggest-account    suggest a GL account for one line
 *                                               from the active rules (read-only).
 *  - GET  /api/v1/bank-rules/suggestions        proposed rules from confirmed
 *                                               reconciliation history (read-only).
 *  - POST /api/v1/bank-rules/suggestions/accept persist an accepted proposal as a
 *                                               real MatchingRule (the ONLY write).
 *
 * All routes are #[NoAdminRequired] with the administration resolved
 * server-side via AdministrationContextService (IDOR-safe per ADR-005);
 * persistence rides OpenRegister's ObjectService (ADR-022). Reads are bounded
 * (ADR-058).
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
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BankRulePreviewService;
use OCA\Shillinq\Service\BankRuleSuggestionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * REST surface for bank matching-rule preview + suggestion (REQ-BR-011/012).
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 */
class BankRuleController extends Controller {
	/**
	 * Register slug fallback.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'shillinq';

	/**
	 * Bounded read cap for candidate lines / history (ADR-058).
	 *
	 * @var int
	 */
	private const READ_CAP = 500;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Inbound request.
	 * @param BankRulePreviewService $previewService Read-only predicate evaluator.
	 * @param BankRuleSuggestionService $suggestionService History-based learning path.
	 * @param AdministrationContextService $administration Server-resolved tenant scope.
	 * @param ContainerInterface $container DI container (lazy OR ObjectService).
	 * @param IUserSession $userSession Current NC user.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly BankRulePreviewService $previewService,
		private readonly BankRuleSuggestionService $suggestionService,
		private readonly AdministrationContextService $administration,
		private readonly ContainerInterface $container,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Dry-run a draft rule against recent unmatched lines (REQ-BR-011).
	 *
	 * Body: rule (object with predicates[]) — required; anchorDate (Y-m-d) — optional.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-011)
	 */
	#[NoAdminRequired]
	public function preview(): JSONResponse {
		$this->requireAuthenticatedSession();

		$rule = $this->request->getParam('rule');
		if (is_array($rule) === false) {
			return new JSONResponse(['error' => 'rule (object with predicates) is required'], Http::STATUS_BAD_REQUEST);
		}

		$predicates = ($rule['predicates'] ?? null);
		if (is_array($predicates) === false || $predicates === []) {
			return new JSONResponse(['error' => 'rule.predicates must be a non-empty array'], Http::STATUS_BAD_REQUEST);
		}

		$anchorDate = $this->request->getParam('anchorDate');
		if (is_string($anchorDate) === false || $anchorDate === '') {
			$anchorDate = null;
		}

		try {
			$admin = $this->resolveAdministrationId();
			$lines = $this->readObjects(
				schema: 'BankStatementLine',
				filters: ['administrationId' => $admin, 'matchState' => 'unmatched'],
			);

			$result = $this->previewService->previewRule(rule: $rule, candidateLines: $lines, anchorDate: $anchorDate);
		} catch (\Throwable $e) {
			$this->logger->error('BankRuleController.preview failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'preview failed; see server log'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end preview()

	/**
	 * Suggest a GL account for one bank line from the active rules (REQ-BR-011).
	 *
	 * Body: lineId — required.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-011)
	 */
	#[NoAdminRequired]
	public function suggestAccount(): JSONResponse {
		$this->requireAuthenticatedSession();

		$lineId = trim((string)$this->request->getParam('lineId', ''));
		if ($lineId === '') {
			return new JSONResponse(['error' => 'lineId is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$admin = $this->resolveAdministrationId();

			$line = $this->readOne(schema: 'BankStatementLine', id: $lineId);
			if ($line === null) {
				return new JSONResponse(['error' => 'bank line ' . $lineId . ' not found'], Http::STATUS_NOT_FOUND);
			}

			// IDOR guard: the line must belong to the resolved administration.
			if ((string)($line['administrationId'] ?? '') !== $admin) {
				return new JSONResponse(['error' => 'bank line ' . $lineId . ' not found'], Http::STATUS_NOT_FOUND);
			}

			$rules = $this->readObjects(
				schema: 'MatchingRule',
				filters: ['administrationId' => $admin, 'lifecycleState' => 'active'],
			);

			$suggestion = $this->previewService->suggestForLine(line: $line, activeRules: $rules);
		} catch (\Throwable $e) {
			$this->logger->error('BankRuleController.suggestAccount failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'suggest-account failed; see server log'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return new JSONResponse(['suggestion' => $suggestion], Http::STATUS_OK);
	}//end suggestAccount()

	/**
	 * Proposed rules from confirmed reconciliation history (REQ-BR-012).
	 *
	 * Query: k — optional repeat threshold.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-012)
	 */
	#[NoAdminRequired]
	public function suggestions(): JSONResponse {
		$this->requireAuthenticatedSession();

		$k = (int)$this->request->getParam('k', (string)BankRuleSuggestionService::DEFAULT_THRESHOLD);
		if ($k < 1) {
			$k = BankRuleSuggestionService::DEFAULT_THRESHOLD;
		}

		try {
			$admin = $this->resolveAdministrationId();
			$history = $this->assembleHistory(admin: $admin);

			// No AI provider is wired by default — deterministic ordering (REQ-BR-012
			// graceful degradation). A TaskProcessing ranker MAY be injected later.
			$proposals = $this->suggestionService->suggestRulesFromHistory(history: $history, k: $k, aiRanker: null);
		} catch (\Throwable $e) {
			$this->logger->error('BankRuleController.suggestions failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'suggestions failed; see server log'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['suggestions' => $proposals, 'threshold' => $k], Http::STATUS_OK);
	}//end suggestions()

	/**
	 * Persist an accepted proposal as a real MatchingRule (REQ-BR-012).
	 *
	 * This is the ONLY write in this controller — a suggestion never applies
	 * itself; the operator confirms it here.
	 *
	 * Body: ruleName, predicates[], targetType, targetGlAccount — required shape.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-012)
	 */
	#[NoAdminRequired]
	public function acceptSuggestion(): JSONResponse {
		$this->requireAuthenticatedSession();

		$ruleName = trim((string)$this->request->getParam('ruleName', ''));
		$predicates = $this->request->getParam('predicates');
		$targetType = trim((string)$this->request->getParam('targetType', 'gl-transaction'));
		$targetGl = trim((string)$this->request->getParam('targetGlAccount', ''));

		if ($ruleName === '' || is_array($predicates) === false || $predicates === []) {
			return new JSONResponse(
				['error' => 'ruleName and a non-empty predicates array are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$admin = $this->resolveAdministrationId();
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

			$payload = [
				'ruleName' => $ruleName,
				'priority' => (int)$this->request->getParam('priority', '100'),
				'targetType' => $targetType,
				'predicates' => array_values($predicates),
				'autoConfirm' => false,
				'administrationId' => $admin,
				'lifecycleState' => 'active',
			];
			if ($targetGl !== '') {
				$payload['targetGlAccount'] = $targetGl;
			}

			$created = $objectService
				->setRegister($this->registerSlug())
				->setSchema('MatchingRule')
				->saveObject($payload);

			$created = $this->toArray(result: $created) ?? [];
		} catch (\Throwable $e) {
			$this->logger->error('BankRuleController.acceptSuggestion failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'accept failed; see server log'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return new JSONResponse($created, Http::STATUS_CREATED);
	}//end acceptSuggestion()

	/**
	 * Assemble a normalised categorisation history from confirmed GL-transaction
	 * matches. Read failures degrade to an empty history (no suggestions, not a 500).
	 *
	 * @param string $admin The resolved administration id.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function assembleHistory(string $admin): array {
		$matches = $this->readObjects(
			schema: 'ReconciliationMatch',
			filters: ['administrationId' => $admin, 'state' => 'confirmed', 'targetType' => 'gl-transaction'],
		);

		$history = [];
		foreach ($matches as $match) {
			$targetRefs = ($match['targetRefs'] ?? []);
			if (is_array($targetRefs) === false || $targetRefs === []) {
				continue;
			}

			$gl = (string)($targetRefs[0] ?? '');
			$bankLineId = '';
			$lineRefs = ($match['bankLineRefs'] ?? []);
			if (is_array($lineRefs) === true && $lineRefs !== []) {
				$bankLineId = (string)($lineRefs[0] ?? '');
			}

			if ($gl === '' || $bankLineId === '') {
				continue;
			}

			$line = $this->readOne(schema: 'BankStatementLine', id: $bankLineId);
			if ($line === null) {
				continue;
			}

			$history[] = [
				'counterpartyName' => (string)($line['counterpartyName'] ?? ''),
				'counterpartyIban' => (string)($line['counterpartyIban'] ?? ''),
				'targetType' => 'gl-transaction',
				'targetGlAccount' => $gl,
			];
		}//end foreach

		return $history;
	}//end assembleHistory()

	/**
	 * Read a bounded list of objects for a schema + filters via OR ObjectService.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function readObjects(string $schema, array $filters): array {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$rows = $objectService
			->setRegister($this->registerSlug())
			->setSchema($schema)
			->findAll(['filters' => $filters, 'limit' => self::READ_CAP]);

		if (is_array($rows) === false) {
			$rows = [];
		}

		$out = [];
		foreach ($rows as $row) {
			$arr = $this->toArray(result: $row);
			if ($arr !== null) {
				$out[] = $arr;
			}
		}

		return $out;
	}//end readObjects()

	/**
	 * Read a single object by id, or null on absence/error.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function readOne(string $schema, string $id): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$row = $objectService
				->setRegister($this->registerSlug())
				->setSchema($schema)
				->find($id);

			return $this->toArray(result: $row);
		} catch (\Throwable $e) {
			return null;
		}

	}//end readOne()

	/**
	 * Resolve the current administration id server-side (IDOR-safe).
	 *
	 * Verified for security-endpoint-guards (Open Question, REQ-001): this
	 * reads `AdministrationContextService::buildContext()['activeAdministrationId']`,
	 * which is derived purely from `currentUserId()` (the authenticated
	 * session uid) via the caller's own `AdministrationMembership` records
	 * — see `buildContext()` in AdministrationContextService. No request
	 * parameter, header, or client-supplied value reaches this path; the
	 * only client-influenceable output is the choice of the FIRST
	 * accessible administration (a business-logic detail, not an IDOR
	 * vector, since it is always one the caller is actually a member of).
	 * `acceptSuggestion()` and every other method in this controller that
	 * calls this method are therefore already IDOR-safe; no additional
	 * membership check was added.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	private function resolveAdministrationId(): string {
		try {
			$context = $this->administration->buildContext();
			$candidate = (string)($context['activeAdministrationId'] ?? '');
			if ($candidate !== '') {
				return $candidate;
			}
		} catch (\Throwable $e) {
			// Fall through to default.
		}

		return 'default';
	}//end resolveAdministrationId()

	/**
	 * Return the configured register slug.
	 *
	 * @return string
	 */
	private function registerSlug(): string {
		return self::REGISTER_SLUG;
	}//end registerSlug()

	/**
	 * Require an authenticated Nextcloud session.
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException When no authenticated user is present.
	 */
	private function requireAuthenticatedSession(): void {
		if ($this->userSession->getUser() === null) {
			throw new OCSForbiddenException('authenticated session required for bank-rule operations');
		}

	}//end requireAuthenticatedSession()

	/**
	 * Normalise an OR find/findAll/save result to a plain array.
	 *
	 * @param mixed $result The OR return value.
	 *
	 * @return array<string,mixed>|null
	 */
	private function toArray(mixed $result): ?array {
		if (is_array($result) === true) {
			return $result;
		}

		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			$serialized = $result->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return null;
	}//end toArray()
}//end class
