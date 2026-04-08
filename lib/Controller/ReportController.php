<?php

/**
 * Shillinq Report Controller
 *
 * OCS controller for generating access rights reports.
 *
 * @category  Controller
 * @package   OCA\Shillinq\Controller
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.6
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for generating user access rights reports in CSV or JSON.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.6
 */
class ReportController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request   The request object
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Generate the access rights report.
     *
     * @return JSONResponse|DataDownloadResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.6
     */
    public function accessRights(): JSONResponse|DataDownloadResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );

            $format = ($this->request->getParam('format') ?? 'html');
            $users  = $objectService->findObjects(
                register: Application::APP_ID,
                schema: 'user',
                filters: ['isActive' => true],
            );

            $rows = [];
            foreach ($users as $user) {
                $delegations = $objectService->findObjects(
                    register: Application::APP_ID,
                    schema: 'accessRight',
                    filters: [
                        'userId'   => ($user['id'] ?? ''),
                        'isActive' => true,
                    ],
                );

                $rows[] = [
                    'username'          => ($user['username'] ?? ''),
                    'displayName'       => ($user['displayName'] ?? ''),
                    'roles'             => '',
                    'teams'             => '',
                    'lastLogin'         => ($user['lastLogin'] ?? ''),
                    'branch'            => ($user['branch'] ?? ''),
                    'delegationsActive' => count($delegations),
                ];
            }

            if ($format === 'csv') {
                return $this->buildCsvResponse(rows: $rows);
            }

            return new JSONResponse(data: ['results' => $rows]);
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500,
            );
        }//end try
    }//end accessRights()

    /**
     * Build a CSV download response from the report rows.
     *
     * @param array $rows The report rows
     *
     * @return DataDownloadResponse
     */
    private function buildCsvResponse(array $rows): DataDownloadResponse
    {
        $headers = [
            'username',
            'displayName',
            'roles',
            'teams',
            'lastLogin',
            'branch',
            'delegationsActive',
        ];

        $csv = implode(',', $headers)."\n";
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $value  = (string) ($row[$header] ?? '');
                $line[] = '"'.str_replace('"', '""', $value).'"';
            }

            $csv .= implode(',', $line)."\n";
        }

        return new DataDownloadResponse(
            data: $csv,
            filename: 'access-rights-report.csv',
            contentType: 'text/csv',
        );
    }//end buildCsvResponse()
}//end class
