<?php

/**
 * Report Schedule Controller
 *
 * POST /api/report-schedules and PUT /api/report-schedules/{id}: the only
 * write path of a report schedule. The caller becomes the owner; only the
 * owner changes a schedule.
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
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Reporting\Schedule\ReportScheduleService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates and changes report schedules.
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 */
class ReportScheduleController extends Controller {

	/**
	 * The request fields a schedule is made of.
	 *
	 * @var list<string>
	 */
	private const FIELDS = [
		'administrationId',
		'reportType',
		'name',
		'format',
		'frequency',
		'runDay',
		'periodRule',
		'recipients',
		'folderPath',
		'status',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request   The request.
	 * @param AdministrationContextService $context   The caller.
	 * @param ReportScheduleService        $schedules The write path.
	 * @param LoggerInterface              $logger    The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextService $context,
		private readonly ReportScheduleService $schedules,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * POST /api/report-schedules
	 *
	 * @return JSONResponse 201 with the schedule, 401, a masked 404, 422 or 500.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		return $this->write(
			write: fn (string $userId, array $input): array => $this->schedules->create(
				ownerId: $userId,
				input: $input,
				now: new DateTimeImmutable()
			),
			status: Http::STATUS_CREATED
		);

	}//end create()

	/**
	 * PUT /api/report-schedules/{id}
	 *
	 * @param string $id The schedule.
	 *
	 * @return JSONResponse 200 with the schedule, 401, a masked 404, 422 or 500.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		return $this->write(
			write: fn (string $userId, array $input): array => $this->schedules->update(
				ownerId: $userId,
				scheduleId: $id,
				input: $input,
				now: new DateTimeImmutable()
			),
			status: Http::STATUS_OK
		);

	}//end update()

	/**
	 * Run a write for the signed-in caller and map its outcome.
	 *
	 * @param callable(string, array<string, mixed>): array<string, mixed> $write  The write.
	 * @param int                                                          $status The success status.
	 *
	 * @return JSONResponse The response.
	 */
	private function write(callable $write, int $status): JSONResponse {
		$userId = $this->context->currentUserId();
		if ($userId === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$input = [];
		foreach (self::FIELDS as $field) {
			$value = $this->request->getParam($field);
			if ($value !== null) {
				$input[$field] = $value;
			}
		}

		try {
			return new JSONResponse($write($userId, $input), $status);
		} catch (OutOfBoundsException) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('ReportScheduleController: write failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The schedule could not be saved'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end write()
}//end class
