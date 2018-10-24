<?php
/**
 * Copyright (c) Enalean, 2018. All Rights Reserved.
 *
 * This file is a part of Tuleap.
 *
 * Tuleap is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Tuleap is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Tuleap. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Tuleap\CrossTracker\Report\CSV;

use HTTPRequest;
use PFUser;
use Project_AccessException;
use Project_AccessProjectNotFoundException;
use ProjectManager;
use Tuleap\CrossTracker\CrossTrackerArtifactRepresentationFactory;
use Tuleap\CrossTracker\CrossTrackerReport;
use Tuleap\CrossTracker\CrossTrackerReportDao;
use Tuleap\CrossTracker\CrossTrackerReportFactory;
use Tuleap\CrossTracker\CrossTrackerReportNotFoundException;
use Tuleap\CrossTracker\Permission\CrossTrackerPermissionGate;
use Tuleap\CrossTracker\Permission\CrossTrackerUnauthorizedException;
use Tuleap\CrossTracker\Report\CrossTrackerArtifactReportFactory;
use Tuleap\Dashboard\Project\ProjectDashboardController;
use Tuleap\Dashboard\User\UserDashboardController;
use Tuleap\Layout\BaseLayout;
use Tuleap\Request\DispatchableWithRequest;
use Tuleap\Request\ForbiddenException;
use Tuleap\Request\NotFoundException;
use Tuleap\REST\Header;
use Tuleap\Tracker\Report\Query\Advanced\SearchablesAreInvalidException;
use Tuleap\Tracker\Report\Query\Advanced\SearchablesDoNotExistException;
use URLVerification;

class CSVExportController implements DispatchableWithRequest
{
    const MAX_LIMIT = 50;
    /**
     * @var CrossTrackerReportFactory
     */
    private $report_factory;
    /**
     * @var CrossTrackerArtifactReportFactory
     */
    private $artifact_report_factory;
    /**
     * @var CrossTrackerArtifactRepresentationFactory
     */
    private $representation_factory;
    /**
     * @var CrossTrackerReportDao
     */
    private $cross_tracker_dao;
    /**
     * @var ProjectManager
     */
    private $project_manager;
    /**
     * @var CrossTrackerPermissionGate
     */
    private $cross_tracker_permission_gate;

    /**
     * CSVExportController constructor.
     * @param CrossTrackerReportFactory                 $report_factory
     * @param CrossTrackerArtifactReportFactory         $artifact_report_factory
     * @param CrossTrackerArtifactRepresentationFactory $representation_factory
     * @param CrossTrackerReportDao                     $cross_tracker_dao
     * @param ProjectManager                            $project_manager
     * @param CrossTrackerPermissionGate                $cross_tracker_permission_gate
     */
    public function __construct(
        CrossTrackerReportFactory $report_factory,
        CrossTrackerArtifactReportFactory $artifact_report_factory,
        CrossTrackerArtifactRepresentationFactory $representation_factory,
        CrossTrackerReportDao $cross_tracker_dao,
        ProjectManager $project_manager,
        CrossTrackerPermissionGate $cross_tracker_permission_gate
    ) {
        $this->report_factory                = $report_factory;
        $this->artifact_report_factory       = $artifact_report_factory;
        $this->representation_factory        = $representation_factory;
        $this->cross_tracker_dao             = $cross_tracker_dao;
        $this->project_manager               = $project_manager;
        $this->cross_tracker_permission_gate = $cross_tracker_permission_gate;
    }

    /**
     * @param HTTPRequest $request
     * @param BaseLayout  $layout
     * @param array       $variables
     * @return void
     */
    public function process(HTTPRequest $request, BaseLayout $layout, array $variables)
    {
        $current_user = $request->getCurrentUser();

        $report_id = $variables['report_id'];
        try {
            list($limit, $offset) = $this->getPaginationParameters($request);
            $representations = $this->buildRepresentations($current_user, $report_id, $limit, $offset);
            Header::sendPaginationHeaders($limit, $offset, $representations->getTotalSize(), self::MAX_LIMIT);
            header('Content-Type: text/csv');
            echo $representations;
        } catch (BadRequestException $e) {
            http_response_code(400);
            header('Content-type: text/plain');
            echo $e->getMessage();
        } catch (ForbiddenException $e) {
            http_response_code(403);
            header('Content-type: text/plain');
            echo $e->getMessage();
        } catch (NotFoundException $e) {
            http_response_code(404);
            header('Content-type: text/plain');
            echo $e->getMessage();
        }
    }

    /**
     * @param PFUser $current_user
     * @param int    $report_id
     * @param int    $limit
     * @param int    $offset
     * @return PaginatedCollectionOfCSVRepresentations
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws ForbiddenException
     */
    private function buildRepresentations(PFUser $current_user, $report_id, $limit, $offset)
    {
        try {
            $report     = $this->report_factory->getById($report_id);
            $this->checkUserIsAllowedToSeeReport($current_user, $report);
            $collection = $this->artifact_report_factory->getArtifactsMatchingReport(
                $report,
                $current_user,
                $limit,
                $offset
            );
            return $this->representation_factory->buildRepresentationsForCSV($collection, $current_user);
        } catch (CrossTrackerReportNotFoundException $e) {
            throw new NotFoundException(
                sprintf(dgettext('tuleap-crosstracker', 'Report with id %d not found'), $report_id)
            );
        } catch (SearchablesAreInvalidException $e) {
            throw new BadRequestException($e->getMessage());
        } catch (SearchablesDoNotExistException $e) {
            throw new BadRequestException($e->getMessage());
        }
    }

    /**
     * @throws BadRequestException
     * @return array
     */
    private function getPaginationParameters(HTTPRequest $request)
    {
        if (! $request->existAndNonEmpty('limit') || ! $request->existAndNonEmpty('offset')) {
            throw new BadRequestException(
                dgettext(
                    'tuleap-crosstracker',
                    "'limit' and 'offset' are required parameters. Please add them in the query."
                )
            );
        }

        if (! is_numeric($request->get('limit'))) {
            throw new BadRequestException(
                dgettext('tuleap-crosstracker', "Invalid value for 'limit'. It must be an integer")
            );
        }
        if (! is_numeric($request->get('offset'))) {
            throw new BadRequestException(
                dgettext(
                    'tuleap-crosstracker',
                    "Invalid value for 'offset'. It must be an integer"
                )
            );
        }
        $limit  = (int) $request->get('limit');
        $offset = (int) $request->get('offset');

        if ($limit > self::MAX_LIMIT) {
            throw new BadRequestException(
                sprintf(dgettext('tuleap-crosstracker', "The maximum value for 'limit' is %d"), self::MAX_LIMIT)
            );
        }

        return [$limit, $offset];
    }

    /**
     * @param PFUser             $user
     * @param CrossTrackerReport $report
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    private function checkUserIsAllowedToSeeReport(PFUser $user, CrossTrackerReport $report)
    {
        $widget = $this->cross_tracker_dao->searchCrossTrackerWidgetByCrossTrackerReportId($report->getId());
        if ($widget['dashboard_type'] === UserDashboardController::DASHBOARD_TYPE
            && $widget['user_id'] !== (int) $user->getId()
        ) {
            throw new ForbiddenException();
        }

        if ($widget['dashboard_type'] === ProjectDashboardController::DASHBOARD_TYPE) {
            $project = $this->project_manager->getProject($widget['project_id']);
            try {
                $url_verification = new URLVerification();
                $url_verification->userCanAccessProject($user, $project);
            } catch (Project_AccessProjectNotFoundException $exception) {
                throw new NotFoundException(dgettext('tuleap-crosstracker', 'Project not found'));
            } catch (Project_AccessException $exception) {
                throw new ForbiddenException(dgettext('tuleap-crosstracker', "You don't have permission to access this project"));
            }
        }

        try {
            $this->cross_tracker_permission_gate->check($user, $report);
        } catch (CrossTrackerUnauthorizedException $exception) {
            throw new ForbiddenException($exception->getMessage());
        }
    }
}