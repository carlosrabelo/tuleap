<?php
/**
 * Copyright (c) Enalean, 2019 - present. All Rights Reserved.
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

declare(strict_types = 1);

namespace Tuleap\Docman\REST\v1;

use Docman_Link;
use Docman_LinkVersionFactory;
use Docman_LockFactory;
use Docman_PermissionsManager;
use Docman_SettingsBo;
use Luracast\Restler\RestException;
use Docman_VersionFactory;
use Project;
use ProjectManager;
use Tuleap\DB\DBFactory;
use Tuleap\DB\DBTransactionExecutorWithConnection;
use Tuleap\Docman\ApprovalTable\ApprovalTableRetriever;
use Tuleap\Docman\ApprovalTable\ApprovalTableUpdateActionChecker;
use Tuleap\Docman\ApprovalTable\Exceptions\ItemHasApprovalTableButNoApprovalActionException;
use Tuleap\Docman\ApprovalTable\Exceptions\ItemHasNoApprovalTableButHasApprovalActionException;
use Tuleap\Docman\Lock\LockChecker;
use Tuleap\Docman\REST\v1\Links\DocmanLinkPATCHRepresentation;
use Tuleap\Docman\REST\v1\Links\DocmanLinksValidityChecker;
use Tuleap\Docman\REST\v1\Links\DocmanLinkUpdator;
use Tuleap\Docman\REST\v1\Metadata\HardcodedMetadataObsolescenceDateRetriever;
use Tuleap\Docman\REST\v1\Metadata\HardcodedMetadataUsageChecker;
use Tuleap\Docman\REST\v1\Metadata\HardcodedMetdataObsolescenceDateChecker;
use Tuleap\Docman\REST\v1\Metadata\ItemStatusMapper;
use Tuleap\REST\AuthenticatedResource;
use Tuleap\REST\Header;
use Tuleap\REST\I18NRestException;
use Tuleap\REST\UserManager as RestUserManager;

class DocmanLinksResource extends AuthenticatedResource
{
    /**
     * @var ProjectManager
     */
    private $project_manager;
    /**
     * @var \EventManager
     */
    private $event_manager;
    /**
     * @var RestUserManager
     */
    private $rest_user_manager;
    /**
     * @var DocmanItemsRequestBuilder
     */
    private $request_builder;

    public function __construct()
    {
        $this->rest_user_manager = RestUserManager::build();
        $this->project_manager   = ProjectManager::instance();
        $this->request_builder   = new DocmanItemsRequestBuilder($this->rest_user_manager, $this->project_manager);
        $this->event_manager     = \EventManager::instance();
    }

    /**
     * @url OPTIONS {id}
     */
    public function optionsDocumentItems($id)
    {
        $this->setHeaders();
    }

    /**
     * Patch an link of document manager
     *
     * Create a new version of an existing link document
     * <pre>
     * /!\ This route is under construction and will be subject to changes
     * </pre>
     *
     * <pre>
     * approval_table_action should be provided only if item has an existing approval table.<br>
     * Possible values:<br>
     *  * copy: Creates an approval table based on the previous one<br>
     *  * reset: Reset the current approval table<br>
     *  * empty: No approbation needed for the new version of this document<br>
     * </pre>
     *
     * @url    PATCH {id}
     * @access hybrid
     *
     * @param int                           $id             Id of the item
     * @param DocmanLinkPATCHRepresentation $representation {@from body}
     *
     * @status 200
     * @throws 400
     * @throws 403
     * @throws 501
     */

    public function patch(int $id, DocmanLinkPATCHRepresentation $representation)
    {
        $this->checkAccess();
        $this->setHeaders();

        $item_request = $this->request_builder->buildFromItemId($id);

        /** @var \Docman_Link $item */
        $item = $item_request->getItem();

        $validator =  new DocumentBeforeModificationValidatorVisitor(Docman_Link::class);
        $item->accept($validator, []);

        $current_user = $this->rest_user_manager->getCurrentUser();

        $project = $item_request->getProject();

        $docman_permission_manager = Docman_PermissionsManager::instance($project->getGroupId());
        if (! $docman_permission_manager->userCanWrite($current_user, $item->getId())) {
            throw new I18NRestException(
                403,
                dgettext('tuleap-docman', 'You are not allowed to write this item.')
            );
        }

        $event_adder = $this->getDocmanItemsEventAdder();
        $event_adder->addLogEvents();
        $event_adder->addNotificationEvents($project);

        $docman_approval_table_retriever = new ApprovalTableRetriever(new \Docman_ApprovalTableFactoriesFactory(), new Docman_VersionFactory());
        $docman_item_updator             = $this->getLinkUpdator($project);

        try {
            $approval_check = new ApprovalTableUpdateActionChecker($docman_approval_table_retriever);
            $approval_check->checkApprovalTableForItem($representation->approval_table_action, $item);
            $docman_item_updator->updateLink(
                $item,
                $current_user,
                $representation,
                new \DateTimeImmutable()
            );
        } catch (ExceptionItemIsLockedByAnotherUser $exception) {
            throw new I18NRestException(
                403,
                dgettext('tuleap-docman', 'Document is locked by another user.')
            );
        } catch (ItemHasApprovalTableButNoApprovalActionException $exception) {
            throw new I18NRestException(
                400,
                $exception->getMessage()
            );
        } catch (ItemHasNoApprovalTableButHasApprovalActionException $exception) {
            throw new I18NRestException(
                400,
                $exception->getMessage()
            );
        } catch (Metadata\StatusNotFoundException $e) {
            throw new RestException(400, $e->getMessage());
        } catch (Metadata\ItemStatusUsageMismatchException $e) {
            throw new RestException(403, 'The "Status" property is not activated for this item.');
        } catch (Metadata\InvalidDateComparisonException $e) {
            throw new RestException(400, 'The obsolescence date is before the current date');
        } catch (Metadata\InvalidDateTimeFormatException $e) {
            throw new RestException(400, 'The date format is incorrect. The format should be YYYY-MM-DD');
        } catch (Metadata\ObsoloscenceDateUsageMismatchException $e) {
            throw new RestException(403, $e->getMessage());
        }
    }

    /**
     * Delete a link document in the document manager
     *
     * Delete an existing link document
     *
     * @url    DELETE {id}
     * @access hybrid
     *
     * @param int $id Id of the link
     *
     * @status 200
     * @throws RestException 401
     * @throws RestException 403
     * @throws RestException 404
     */
    public function delete(int $id) : void
    {
        $this->checkAccess();
        $this->setHeaders();

        $item_request      = $this->request_builder->buildFromItemId($id);
        $item_to_delete    = $item_request->getItem();
        $current_user      = $this->rest_user_manager->getCurrentUser();
        $project           = $item_request->getProject();
        $validator_visitor = new DocumentBeforeModificationValidatorVisitor(\Docman_Link::class);

        $docman_permission_manager = Docman_PermissionsManager::instance($project->getGroupId());
        if (! $docman_permission_manager->userCanDelete($current_user, $item_to_delete)) {
            throw new I18NRestException(
                403,
                dgettext('tuleap-docman', 'You are not allowed to delete this item.')
            );
        }

        $item_to_delete->accept($validator_visitor);

        $event_adder = $this->getDocmanItemsEventAdder();
        $event_adder->addLogEvents();
        $event_adder->addNotificationEvents($project);

        (new \Docman_ItemFactory())->delete($item_to_delete);

        $this->event_manager->processEvent('send_notifications', []);
    }

    private function getDocmanItemsEventAdder(): DocmanItemsEventAdder
    {
        return new DocmanItemsEventAdder($this->event_manager);
    }

    private function setHeaders(): void
    {
        Header::allowOptionsPatchDelete();
    }

    private function getLinkUpdator(Project $project): DocmanLinkUpdator
    {
        $builder                                      = new DocmanItemUpdatorBuilder();
        $updator                                      = $builder->build($this->event_manager);
        $version_factory                              = new \Docman_VersionFactory();
        $lock_factory                                 = new Docman_LockFactory();
        $lock_checker                                 = new LockChecker($lock_factory);
        $docman_item_factory                          = new \Docman_ItemFactory();
        $docman_setting_bo                            = new Docman_SettingsBo($project->getGroupId());
        $hardcoded_metadata_status_checker            = new HardcodedMetadataUsageChecker($docman_setting_bo);
        $hardcoded_metadata_obsolescence_date_checker = new HardcodedMetdataObsolescenceDateChecker(
            $docman_setting_bo
        );
        return new DocmanLinkUpdator(
            $version_factory,
            $updator,
            $lock_checker,
            $docman_item_factory,
            \EventManager::instance(),
            new DocmanLinksValidityChecker(),
            new Docman_LinkVersionFactory(),
            new DBTransactionExecutorWithConnection(DBFactory::getMainTuleapDBConnection()),
            new ItemStatusMapper(),
            $hardcoded_metadata_status_checker,
            $hardcoded_metadata_obsolescence_date_checker,
            new HardcodedMetadataObsolescenceDateRetriever(
                $hardcoded_metadata_obsolescence_date_checker
            )
        );
    }
}
