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

use OCA\Shillinq\AppInfo\AppIdentity;
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
use Psr\Log\LoggerInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

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
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
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
		private readonly ObjectServiceInterface $objectService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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
		$refusal = self::ruleRefusal(rule: $rule);
		if ($refusal !== null) {
			return $refusal;
		}

		$anchorDate = $this->anchorDate();
		try {
			$result = $this->previewService->previewRule(rule: $rule, candidateLines: $this->unmatchedLines(), anchorDate: $anchorDate);
		} catch (\Throwable $e) {
			$this->logger->error('BankRuleController.preview failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'preview failed; see server log'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end preview()

	/**
	 * The 400 for a rule that is not an object, or carries no predicates.
	 *
	 * @param mixed $rule The `rule` parameter.
	 *
	 * @return JSONResponse|null
	 */
	private static function ruleRefusal(mixed $rule): ?JSONResponse {
		if (is_array($rule) === false) {
			return new JSONResponse(['error' => 'rule (object with predicates) is required'], Http::STATUS_BAD_REQUEST);
		}

		$predicates = ($rule['predicates'] ?? null);
		if (is_array($predicates) === false || $predicates === []) {
			return new JSONResponse(['error' => 'rule.predicates must be a non-empty array'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end ruleRefusal()

	/**
	 * The `anchorDate` parameter, or null when it is not a non-empty string.
	 *
	 * @return string|null
	 */
	private function anchorDate(): ?string {
		$anchorDate = $this->request->getParam('anchorDate');
		if (is_string($anchorDate) === false || $anchorDate === '') {
			return null;
		}

		return $anchorDate;
	}//end anchorDate()

	/**
	 * The administration's unmatched bank lines.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function unmatchedLines(): array {
		$admin = $this->resolveAdministrationId();

		return $this->readObjects(schema: 'BankStatementLine', filters: ['administrationId' => $admin, 'matchState' => 'unmatched']);
	}//end unmatchedLines()

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
			return $this->suggestionFor(lineId: $lineId);
		} catch (\Throwable $e) {
			$this->logger->error('BankRuleController.suggestAccount failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'suggest-account failed; see server log'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end suggestAccount()

	/**
	 * Suggest a counter-account for one of the administration's own bank lines.
	 *
	 * @param string $lineId The bank line id.
	 *
	 * @return JSONResponse 200 with the suggestion; 404 for a missing or foreign line.
	 */
	private function suggestionFor(string $lineId): JSONResponse {
		$admin = $this->resolveAdministrationId();
		$line = $this->ownLine(lineId: $lineId, admin: $admin);
		if ($line === null) {
			return new JSONResponse(['error' => 'bank line ' . $lineId . ' not found'], Http::STATUS_NOT_FOUND);
		}

		$rules = $this->readObjects(schema: 'MatchingRule', filters: ['administrationId' => $admin, 'lifecycleState' => 'active']);
		$suggestion = $this->previewService->suggestForLine(line: $line, activeRules: $rules);

		return new JSONResponse(['suggestion' => $suggestion], Http::STATUS_OK);
	}//end suggestionFor()

	/**
	 * The bank line, only when it belongs to the resolved administration (IDOR guard).
	 * A missing line and another administration's line both answer null.
	 *
	 * @param string $lineId The bank line id.
	 * @param string $admin  The resolved administration.
	 *
	 * @return array<string, mixed>|null
	 */
	private function ownLine(string $lineId, string $admin): ?array {
		$line = $this->readOne(schema: 'BankStatementLine', id: $lineId);
		if ($line === null || (string)($line['administrationId'] ?? '') !== $admin) {
			return null;
		}

		return $line;
	}//end ownLine()

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

		$k = $this->threshold();
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
	 * The `k` parameter; below 1 means the default threshold.
	 *
	 * @return int
	 */
	private function threshold(): int {
		$k = (int)$this->request->getParam('k', (string)BankRuleSuggestionService::DEFAULT_THRESHOLD);
		if ($k < 1) {
			return BankRuleSuggestionService::DEFAULT_THRESHOLD;
		}

		return $k;
	}//end threshold()

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

		$input = $this->acceptInput();
		if ($input['ruleName'] === '' || is_array($input['predicates']) === false || $input['predicates'] === []) {
			return new JSONResponse(['error' => 'ruleName and a non-empty predicates array are required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$created = $this->savedRule(payload: $this->rulePayload(input: $input, admin: $this->resolveAdministrationId()));
		} catch (\Throwable $e) {
			$this->logger->error('BankRuleController.acceptSuggestion failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'accept failed; see server log'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($created, Http::STATUS_CREATED);
	}//end acceptSuggestion()

	/**
	 * The accept parameters, trimmed, in the order they are read.
	 *
	 * @return array{ruleName: string, predicates: mixed, targetType: string, targetGlAccount: string}
	 */
	private function acceptInput(): array {
		$ruleName = trim((string)$this->request->getParam('ruleName', ''));
		$predicates = $this->request->getParam('predicates');
		$targetType = trim((string)$this->request->getParam('targetType', 'gl-transaction'));
		$targetGl = trim((string)$this->request->getParam('targetGlAccount', ''));

		return ['ruleName' => $ruleName, 'predicates' => $predicates, 'targetType' => $targetType, 'targetGlAccount' => $targetGl];
	}//end acceptInput()

	/**
	 * The MatchingRule to create: active, never auto-confirming, scoped to the administration.
	 *
	 * @param array{ruleName: string, predicates: array<mixed>, targetType: string, targetGlAccount: string} $input The accept parameters.
	 * @param string                                                                                         $admin The resolved administration.
	 *
	 * @return array<string, mixed>
	 */
	private function rulePayload(array $input, string $admin): array {
		$payload = [
			'ruleName' => $input['ruleName'],
			'priority' => (int)$this->request->getParam('priority', '100'),
			'targetType' => $input['targetType'],
			'predicates' => array_values($input['predicates']),
			'autoConfirm' => false,
			'administrationId' => $admin,
			'lifecycleState' => 'active',
		];
		if ($input['targetGlAccount'] !== '') {
			$payload['targetGlAccount'] = $input['targetGlAccount'];
		}

		return $payload;
	}//end rulePayload()

	/**
	 * Save the rule and answer the stored record.
	 *
	 * @param array<string, mixed> $payload The rule.
	 *
	 * @return array<string, mixed>
	 */
	private function savedRule(array $payload): array {
		$created = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema('MatchingRule')
			->saveObject($payload);

		return ($this->toArray(result: $created) ?? []);
	}//end savedRule()

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
			$entry = $this->historyEntry(match: $match);
			if ($entry !== null) {
				$history[] = $entry;
			}
		}

		return $history;
	}//end assembleHistory()

	/**
	 * One history entry: the matched line's counterparty and the GL account it was booked on.
	 *
	 * @param array<string, mixed> $match A confirmed ReconciliationMatch.
	 *
	 * @return array<string, string>|null Null without a GL target, a bank line, or a readable line.
	 */
	private function historyEntry(array $match): ?array {
		$refs = self::matchRefs(match: $match);
		if ($refs === null) {
			return null;
		}

		$line = $this->readOne(schema: 'BankStatementLine', id: $refs['bankLineId']);
		if ($line === null) {
			return null;
		}

		return [
			'counterpartyName' => (string)($line['counterpartyName'] ?? ''),
			'counterpartyIban' => (string)($line['counterpartyIban'] ?? ''),
			'targetType' => 'gl-transaction',
			'targetGlAccount' => $refs['gl'],
		];
	}//end historyEntry()

	/**
	 * The first GL target and first bank line of a match, when both are present.
	 *
	 * @param array<string, mixed> $match A confirmed ReconciliationMatch.
	 *
	 * @return array{gl: string, bankLineId: string}|null
	 */
	private static function matchRefs(array $match): ?array {
		$gl = self::firstRef(refs: ($match['targetRefs'] ?? []));
		$bankLineId = self::firstRef(refs: ($match['bankLineRefs'] ?? []));
		if ($gl === '' || $bankLineId === '') {
			return null;
		}

		return ['gl' => $gl, 'bankLineId' => $bankLineId];
	}//end matchRefs()

	/**
	 * The first reference of a list, or '' for anything else.
	 *
	 * @param mixed $refs The reference list.
	 *
	 * @return string
	 */
	private static function firstRef(mixed $refs): string {
		if (is_array($refs) === true && $refs !== []) {
			return (string)($refs[0] ?? '');
		}

		return '';
	}//end firstRef()

	/**
	 * Read a bounded list of objects for a schema + filters via OR ObjectService.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function readObjects(string $schema, array $filters): array {
		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema($schema)
			->findAll(['filters' => $filters, 'limit' => self::READ_CAP]);

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
			$row = ObjectIdentifier::findScoped(
				scoped: $this->objectService->setRegister($this->registerSlug())->setSchema($schema),
				id: $id
			);

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
			return self::serialized(entity: $result);
		}

		return null;
	}//end toArray()

	/**
	 * An entity's jsonSerialize() payload when it is an array.
	 *
	 * @param object $entity An OR entity.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function serialized(object $entity): ?array {
		$serialized = $entity->jsonSerialize();
		if (is_array($serialized) === true) {
			return $serialized;
		}

		return null;
	}//end serialized()
}//end class
