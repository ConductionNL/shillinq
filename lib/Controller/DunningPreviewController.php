<?php

/**
 * Dunning Preview Controller
 *
 * The read side of automatic dunning (receivables-automatic-dunning 4.1,
 * REQ-RAD-008):
 *
 *  - GET /api/dunning/next-run              per administration the caller may
 *                                           see: whether reminders are on, the
 *                                           last run report, and per invoice
 *                                           the stage and channel the next run
 *                                           would send. Writes nothing.
 *  - GET /api/dunning/ladders/{id}/stages   the stages of a ladder with the
 *                                           subject and body a letter would
 *                                           use, in the caller's language.
 *
 * Every read is scoped to an administration the caller holds a membership
 * of (AdministrationContextService). An administration or ladder outside
 * that scope answers 404, so the response does not confirm it exists.
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
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Dunning\DunningStageRenderer;
use OCA\Shillinq\Service\Dunning\DunningTickRunner;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The Next run preview, the last run report and a ladder's stage texts.
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
 */
class DunningPreviewController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request       The request.
	 * @param AdministrationContextService $context       The caller's administrations.
	 * @param DunningTickRunner            $runner        The daily pass: its preview and its reports.
	 * @param DunningStageRenderer         $renderer      The text a stage's letter uses.
	 * @param ObjectServiceInterface       $objectService OpenRegister's object service.
	 * @param IAppConfig                   $appConfig     The register slug.
	 * @param ITimeFactory                 $time          The clock.
	 * @param IL10N                        $l10n          The caller's language and messages.
	 * @param LoggerInterface              $logger        Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextService $context,
		private readonly DunningTickRunner $runner,
		private readonly DunningStageRenderer $renderer,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The Next run preview and the last run, per administration.
	 *
	 * Query: `administrationId` (optional): one administration, by its code or
	 * record id; without it, every administration the caller holds.
	 *
	 * @return JSONResponse 200 {administrations: [{administrationId, id, name, enabled, lastRun, rows}]};
	 *                      401 without a user; 400 on a malformed id; 404 outside the caller's scope.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function nextRun(): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$requested = trim((string)$this->request->getParam('administrationId', ''));
		if ($requested !== '' && preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $requested) !== 1) {
			return new JSONResponse(['error' => 'administrationId must be a valid identifier'], Http::STATUS_BAD_REQUEST);
		}

		$ids = [$requested];
		if ($requested === '') {
			$ids = $this->context->accessibleAdministrationIds();
		}

		$now     = $this->time->now();
		$entries = [];
		$seen    = [];
		foreach ($ids as $id) {
			$administration = $this->administration(id: (string)$id);
			if ($administration === null || $this->mayRead(administration: $administration) === false) {
				if ($requested !== '') {
					return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
				}

				continue;
			}

			$recordId = (string)($administration['id'] ?? '');
			if (isset($seen[$recordId]) === true) {
				continue;
			}

			$seen[$recordId] = true;
			$entries[]       = [
				'administrationId' => (string)($administration['administrationCode'] ?? $recordId),
				'id' => $recordId,
				'name' => (string)($administration['name'] ?? ''),
				'enabled' => (($administration[DunningTickRunner::ENABLED_PROPERTY] ?? false) === true),
				'lastRun' => $this->runner->lastReportFor(administration: $administration),
				'rows' => $this->runner->previewAdministration(administration: $administration, now: $now),
			];
		}//end foreach

		return new JSONResponse(['administrations' => $entries]);
	}//end nextRun()

	/**
	 * The stages of a ladder with the subject and body a letter would use.
	 *
	 * @param string $id The DunningLadder id or slug.
	 *
	 * @return JSONResponse 200 {rows: [{nr, days, channel, channelLabel, subject, body}]};
	 *                      401 without a user; 404 when the caller cannot see the ladder.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function ladderStages(string $id): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$ladder = $this->find(schema: 'DunningLadder', id: $id, fallbackProperty: 'slug');
		if ($ladder === null || $this->context->canAccess(administrationId: (string)($ladder['administrationId'] ?? '')) === false) {
			return new JSONResponse(['error' => 'Ladder not found'], Http::STATUS_NOT_FOUND);
		}

		$language = 'nl';
		if (str_starts_with(strtolower($this->l10n->getLanguageCode()), 'nl') === false) {
			$language = 'en';
		}

		$stages = array_values(array_filter((array)($ladder['stages'] ?? []), 'is_array'));
		usort($stages, static fn (array $a, array $b): int => ((int)($a['nr'] ?? 0) <=> (int)($b['nr'] ?? 0)));

		$rows = [];
		foreach ($stages as $stage) {
			$text    = $this->renderer->textFor(stage: $stage, language: $language);
			$channel = (string)($stage['channel'] ?? 'EMAIL');
			$rows[]  = [
				'nr' => (int)($stage['nr'] ?? 0),
				'days' => (int)($stage['daysAfterExpiryDate'] ?? 0),
				'channel' => $channel,
				'channelLabel' => $this->channelLabel(channel: $channel),
				'subject' => $text['subject'],
				'body' => $text['body'],
			];
		}

		return new JSONResponse(['rows' => $rows]);
	}//end ladderStages()

	/**
	 * Whether the caller holds a membership of this administration, under
	 * any of the values records carry as `administrationId`.
	 *
	 * @param array<string,mixed> $administration The Administration record.
	 *
	 * @return bool
	 */
	private function mayRead(array $administration): bool {
		foreach (['administrationCode', 'id', 'uuid'] as $key) {
			$value = (string)($administration[$key] ?? '');
			if ($value !== '' && $this->context->canAccess(administrationId: $value) === true) {
				return true;
			}
		}

		return false;
	}//end mayRead()

	/**
	 * The Administration record by its record id or its code.
	 *
	 * @param string $id The id or code.
	 *
	 * @return array<string,mixed>|null
	 */
	private function administration(string $id): ?array {
		return $this->find(schema: 'Administration', id: $id, fallbackProperty: 'administrationCode');
	}//end administration()

	/**
	 * One record, read with the caller's own rights.
	 *
	 * @param string $schema           The schema slug.
	 * @param string $id               The id, uuid or slug.
	 * @param string $fallbackProperty The property to match when the id is not an identifier.
	 *
	 * @return array<string,mixed>|null
	 */
	private function find(string $schema, string $id, string $fallbackProperty): ?array {
		try {
			$scoped = $this->objectService->setRegister($this->register())->setSchema($schema);
		} catch (Throwable $e) {
			$this->logger->warning('Shillinq: dunning preview scope for ' . $schema . ' failed: ' . $e->getMessage());
			return null;
		}

		return ObjectIdentifier::findOne(scoped: $scoped, id: $id, fallbackProperty: $fallbackProperty);
	}//end find()

	/**
	 * The name of a dunning channel in the caller's language.
	 *
	 * @param string $channel The channel code.
	 *
	 * @return string
	 */
	private function channelLabel(string $channel): string {
		return match ($channel) {
			'EMAIL' => $this->l10n->t('Email'),
			'eMAILPostRegistration' => $this->l10n->t('Email and registered post'),
			'REGISTERED_POST' => $this->l10n->t('Registered post'),
			'COLLECTION_AGENCY_API' => $this->l10n->t('Collection agency'),
			default => $channel,
		};
	}//end channelLabel()

	/**
	 * The configured register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
