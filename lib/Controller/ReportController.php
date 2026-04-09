<?php

/**
 * Shillinq Report Controller
 *
 * OCS controller for generating access rights reports.
 *
 * @category  Controller
 * @package   OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
 */

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
 * Controller for the user access rights report.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
 */
class ReportController extends Controller
{


    /**
     * Constructor for ReportController.
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
     * @RequiresRoleLevel(100)
     *
     * @return JSONResponse|DataDownloadResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function accessRights()
    {
        $format = $this->request->getParam('format', 'html');
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

        // Load all active users.
        $users = $objectService->findObjects(
            filters: ['isActive' => true],
            register: Application::APP_ID,
            schema: 'user',
        );

        // Build report rows.
        $rows = [];
        foreach ($users as $user) {
            $userId = ($user['id'] ?? '');

            // Get active access rights.
            $accessRights = $objectService->findObjects(
                filters: [
                    'userId'   => $userId,
                    'isActive' => true,
                ],
                register: Application::APP_ID,
                schema: 'accessRight',
            );

            $roleNames = [];
            foreach ($accessRights as $right) {
                $roles = $objectService->findObjects(
                    filters: ['id' => $right['roleId']],
                    register: Application::APP_ID,
                    schema: 'role',
                );
                if (empty($roles) === false) {
                    $roleNames[] = ($roles[0]['name'] ?? 'Unknown');
                }
            }

            $rows[] = [
                'username'          => ($user['username'] ?? ''),
                'displayName'       => ($user['displayName'] ?? ''),
                'roles'             => implode(', ', $roleNames),
                'teams'             => '',
                'lastLogin'         => ($user['lastLogin'] ?? ''),
                'branch'            => ($user['branch'] ?? ''),
                'delegationsActive' => count($accessRights),
            ];
        }//end foreach

        if ($format === 'csv') {
            return $this->generateCsv($rows);
        }

        return new JSONResponse($rows);

    }//end accessRights()


    /**
     * Generate a CSV download response from report rows.
     *
     * @param array $rows The report data rows
     *
     * @return DataDownloadResponse
     */
    private function generateCsv(array $rows): DataDownloadResponse
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

        $output = implode(',', $headers)."\n";

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $value = (string) ($row[$header] ?? '');
                // Escape CSV values containing commas or quotes.
                if (strpos($value, ',') !== false || strpos($value, '"') !== false) {
                    $value = '"'.str_replace('"', '""', $value).'"';
                }

                $line[] = $value;
            }

            $output .= implode(',', $line)."\n";
        }

        return new DataDownloadResponse(
            $output,
            'access-rights-report.csv',
            'text/csv'
        );

    }//end generateCsv()


}//end class
