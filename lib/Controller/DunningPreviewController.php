<?php

/**
 * Dunning Preview Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DunningStageRendererInterface;
use OCA\Shillinq\Kernel\Contracts\DunningTickRunnerInterface;
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
	 * The property each previewed schema is also looked up by when its id is not a uuid.
	 *
	 * @var array<string, string>
	 */
	private const FALLBACK_PROPERTY = [
		'Administration' => 'administrationCode',
		'DunningLadder' => 'slug',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request       The request.
	 * @param AdministrationContextServiceInterface $context       The caller's administrations.
	 * @param DunningTickRunnerInterface            $runner        The daily pass: its preview and its reports.
	 * @param DunningStageRendererInterface         $renderer      The text a stage's letter uses.
	 * @param ObjectServiceInterface       $objectService OpenRegister's object service.
	 * @param IAppConfig                   $appConfig     The register slug.
	 * @param ITimeFactory                 $time          The clock.
	 * @param IL10N                        $l10n          The caller's language and messages.
	 * @param LoggerInterface              $logger        Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextServiceInterface $context,
		private readonly DunningTickRunnerInterface $runner,
		private readonly DunningStageRendererInterface $renderer,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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

		$entries = $this->previewEntries(requested: $requested);
		if ($entries === null) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['administrations' => $entries]);
	}//end nextRun()

	/**
	 * One preview entry per readable administration in scope, each administration once.
	 *
	 * @param string $requested The asked-for administration, or '' for every membership.
	 *
	 * @return array<int, array<string, mixed>>|null Null when the asked-for administration is missing or unreadable.
	 */
	private function previewEntries(string $requested): ?array {
		if ($requested === '') {
			return $this->membershipEntries();
		}

		$now = $this->time->now();
		$administration = $this->readableAdministration(id: $requested);
		if ($administration === null) {
			return null;
		}

		return [$this->previewEntry(administration: $administration, now: $now)];
	}//end previewEntries()

	/**
	 * The preview of every readable administration the caller is a member of, each once;
	 * a missing or unreadable one is skipped.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function membershipEntries(): array {
		$ids = $this->context->accessibleAdministrationIds();
		$now = $this->time->now();
		$entries = [];
		foreach ($ids as $id) {
			$administration = $this->readableAdministration(id: (string)$id);
			if ($administration !== null) {
				$entries[(string)($administration['id'] ?? '')] ??= $this->previewEntry(administration: $administration, now: $now);
			}
		}

		return array_values($entries);
	}//end membershipEntries()

	/**
	 * The administration, when it exists and the caller may read it.
	 *
	 * @param string $id The administration id or code.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readableAdministration(string $id): ?array {
		$administration = $this->administration(id: $id);
		if ($administration === null || $this->mayRead(administration: $administration) === false) {
			return null;
		}

		return $administration;
	}//end readableAdministration()

	/**
	 * The preview of one administration: its identity, the switch, the last run and the rows it would send.
	 *
	 * @param array<string, mixed> $administration The Administration record.
	 * @param DateTimeImmutable    $now            The moment previewed.
	 *
	 * @return array<string, mixed>
	 */
	private function previewEntry(array $administration, DateTimeImmutable $now): array {
		$recordId = (string)($administration['id'] ?? '');

		return [
			'administrationId' => (string)($administration['administrationCode'] ?? $recordId),
			'id' => $recordId,
			'name' => (string)($administration['name'] ?? ''),
			'enabled' => (($administration[DunningTickRunnerInterface::ENABLED_PROPERTY] ?? false) === true),
			'lastRun' => $this->runner->lastReportFor(administration: $administration),
			'rows' => $this->runner->previewAdministration(administration: $administration, now: $now),
		];
	}//end previewEntry()

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

		$ladder = $this->find(schema: 'DunningLadder', id: $id);
		if ($ladder === null || $this->context->canAccess(administrationId: (string)($ladder['administrationId'] ?? '')) === false) {
			return new JSONResponse(['error' => 'Ladder not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['rows' => $this->stageRows(ladder: $ladder)]);
	}//end ladderStages()

	/**
	 * The ladder's stages by number, each with its channel label and letter text in the caller's language.
	 *
	 * @param array<string, mixed> $ladder The DunningLadder record.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function stageRows(array $ladder): array {
		$language = $this->letterLanguage();
		$stages = array_values(array_filter((array)($ladder['stages'] ?? []), 'is_array'));
		usort($stages, static fn (array $a, array $b): int => ((int)($a['nr'] ?? 0) <=> (int)($b['nr'] ?? 0)));

		$rows = [];
		foreach ($stages as $stage) {
			$rows[] = $this->stageRow(stage: $stage, language: $language);
		}

		return $rows;
	}//end stageRows()

	/**
	 * 'nl' for a Dutch caller, 'en' otherwise.
	 *
	 * @return string
	 */
	private function letterLanguage(): string {
		if (str_starts_with(strtolower($this->l10n->getLanguageCode()), 'nl') === false) {
			return 'en';
		}

		return 'nl';
	}//end letterLanguage()

	/**
	 * One stage row.
	 *
	 * @param array<string, mixed> $stage    A ladder stage.
	 * @param string               $language The letter language.
	 *
	 * @return array<string, mixed>
	 */
	private function stageRow(array $stage, string $language): array {
		$text = $this->renderer->textFor(stage: $stage, language: $language);
		$channel = (string)($stage['channel'] ?? 'EMAIL');

		return [
			'nr' => (int)($stage['nr'] ?? 0),
			'days' => (int)($stage['daysAfterExpiryDate'] ?? 0),
			'channel' => $channel,
			'channelLabel' => $this->channelLabel(channel: $channel),
			'subject' => $text['subject'],
			'body' => $text['body'],
		];
	}//end stageRow()

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
		return $this->find(schema: 'Administration', id: $id);
	}//end administration()

	/**
	 * One record, read with the caller's own rights.
	 *
	 * @param string $schema           The schema slug.
	 * @param string $id               The id, uuid or slug.
	 *
	 * @return array<string,mixed>|null
	 */
	private function find(string $schema, string $id): ?array {
		try {
			$scoped = $this->objectService->setRegister($this->register())->setSchema($schema);
		} catch (Throwable $e) {
			$this->logger->warning('Shillinq: dunning preview scope for ' . $schema . ' failed: ' . $e->getMessage());
			return null;
		}

		return ObjectIdentifier::findOne(scoped: $scoped, id: $id, fallbackProperty: self::FALLBACK_PROPERTY[$schema]);
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
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
