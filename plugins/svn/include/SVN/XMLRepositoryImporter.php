<?php
/**
 * Copyright (c) Enalean SAS, 2016 - 2018. All Rights Reserved.
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

namespace Tuleap\SVN;

use Backend;
use Event;
use EventManager;
use ForgeConfig;
use Logger;
use Project;
use SimpleXMLElement;
use SVN_AccessFile_Writer;
use System_Command_CommandException;
use Tuleap\Project\XML\Import\ImportConfig;
use Tuleap\SVN\AccessControl\AccessFileHistoryCreator;
use Tuleap\SVN\Admin\MailNotification;
use Tuleap\SVN\Admin\MailNotificationManager;
use Tuleap\SVN\Migration\RepositoryCopier;
use Tuleap\SVN\Notifications\NotificationsEmailsBuilder;
use Tuleap\SVN\Repository\Exception\CannotCreateRepositoryException;
use Tuleap\SVN\Repository\Exception\RepositoryNameIsInvalidException;
use Tuleap\SVN\Repository\Repository;
use Tuleap\SVN\Repository\RepositoryCreator;
use Tuleap\SVN\Repository\RepositoryManager;
use Tuleap\SVN\Repository\RuleName;

class XMLRepositoryImporter
{
    const SERVICE_NAME = 'svn';
    /**
     * @var \BackendSVN
     */
    private $backend_svn;
    /**
     * @var \Backend
     */
    private $backend_system;

    /** @var string */
    private $dump_file_path;

    /** @var string */
    private $name;

    /** @var string */
    private $access_file_contents;

    /** @var array(array(path => (string), emails => (string)), ...) */
    private $subscriptions;

    /** @var SimpleXMLElement */
    private $references;
    /**
     * @var RepositoryCreator
     */
    private $repository_creator;
    /**
     * @var AccessFileHistoryCreator
     */
    private $access_file_history_creator;
    /**
     * @var RepositoryManager
     */
    private $repository_manager;
    /**
     * @var \UserManager
     */
    private $user_manager;
    /**
     * @var NotificationsEmailsBuilder
     */
    private $notifications_emails_builder;
    /**
     * @var RepositoryCopier
     */
    private $repository_copier;

    public function __construct(
        SimpleXMLElement $xml_repo,
        $extraction_path,
        RepositoryCreator $repository_creator,
        Backend $backend_svn,
        Backend $backend_system,
        AccessFileHistoryCreator $access_file_history_creator,
        RepositoryManager $repository_manager,
        \UserManager $user_manager,
        NotificationsEmailsBuilder $notifications_emails_builder,
        RepositoryCopier $repository_copier
    ) {
        $attrs      = $xml_repo->attributes();
        $this->name = $attrs['name'];
        if (isset($attrs['dump-file'])) {
            $this->dump_file_path = $extraction_path . '/' . $attrs['dump-file'];
        }

        $this->access_file_contents = (string)$xml_repo->{"access-file"};

        $this->subscriptions = array();
        foreach ($xml_repo->notification as $notif) {
            $a                     = $notif->attributes();
            $this->subscriptions[] = array(
                'path' => $a['path'],
                'emails' => $a['emails']
            );
        }

        $this->references                   = $xml_repo->references;
        $this->repository_creator           = $repository_creator;
        $this->backend_svn                  = $backend_svn;
        $this->backend_system               = $backend_system;
        $this->access_file_history_creator  = $access_file_history_creator;
        $this->repository_manager           = $repository_manager;
        $this->user_manager                 = $user_manager;
        $this->notifications_emails_builder = $notifications_emails_builder;
        $this->repository_copier            = $repository_copier;
    }

    public function import(
        ImportConfig $configuration,
        Logger $logger,
        Project $project,
        AccessFileHistoryCreator $accessfile_history_creator,
        MailNotificationManager $mail_notification_manager,
        RuleName $rule_name,
        \PFUser $committer
    ) {
        if (!$rule_name->isValid($this->name)) {
            throw new XMLImporterException("Repository name '{$this->name}' is invalid: " . $rule_name->getErrorMessage());
        }

        $repo = new Repository("", $this->name, '', '', $project);

        try {
            $copy_from_core = false;
            $sysevent       = $this->repository_creator->createWithoutUserAdminCheck(
                $repo,
                $committer,
                $copy_from_core
            );
        } catch (CannotCreateRepositoryException $e) {
            throw new XMLImporterException("Unable to create the repository");
        } catch (RepositoryNameIsInvalidException $e) {
            throw new XMLImporterException($e->getMessage());
        }

        if (!$sysevent) {
            throw new XMLImporterException("Could not create system event");
        }

        $logger->info("[svn] Creating SVN repository {$this->name}");
        $sysevent->injectDependencies(
            $this->access_file_history_creator,
            $this->repository_manager,
            $this->user_manager,
            $this->backend_svn,
            $this->backend_system,
            $this->repository_copier
        );
        $sysevent->process();
        if ($sysevent->getStatus() != \SystemEvent::STATUS_DONE) {
            $logger->error($sysevent->getLog());
            throw new XMLImporterException("Event processing failed: status " . $sysevent->getStatus());
        } else {
            $logger->debug($sysevent->getLog());
        }

        $logger->info("[svn] Importing SVN repository {$this->name}");

        if (!empty($this->dump_file_path)) {
            $this->importCommits($logger, $repo);
        }

        if (!empty($this->access_file_contents)) {
            $this->importAccessFile($logger, $repo, $accessfile_history_creator);
        }

        if (!empty($this->subscriptions)) {
            $this->importSubscriptions($logger, $repo, $mail_notification_manager);
        }

        if (!empty($this->references)) {
            $this->importReferences($configuration, $logger, $repo);
        }

        if (!$this->currentUserIsHTTPUser()) {
            $this->backend_svn->setUserAndGroup($project, $repo->getSystemPath());
        }
    }

    private function importCommits(Logger $logger, Repository $repo)
    {
        $rootpath_arg = escapeshellarg($repo->getSystemPath());
        $dumpfile_arg = escapeshellarg($this->dump_file_path);
        $commandline  = "/usr/share/tuleap/plugins/svn/bin/import_repository.sh $rootpath_arg $dumpfile_arg";

        $logger->info("[svn {$this->name}] Import revisions: $commandline");

        try {
            $cmd            = new \System_Command();
            $command_output = $cmd->exec($commandline);
            foreach ($command_output as $line) {
                $logger->debug("[svn {$this->name}] svnadmin: $line");
            }
            $logger->debug("[svn {$this->name}] svnadmin returned with status 0");
        } catch (System_Command_CommandException $e) {
            foreach ($e->output as $line) {
                $logger->error("[svn {$this->name}] svnadmin: $line");
            }
            $logger->error("[svn {$this->name}] svnadmin returned with status {$e->return_value}");
            throw new XMLImporterException(
                "failed to svnadmin load $dumpfile_arg in $rootpath_arg: exited with status {$e->return_value}"
            );
        }
    }

    private function currentUserIsHTTPUser()
    {
        $http_user = posix_getpwnam(ForgeConfig::get('sys_http_user'));
        return ($http_user['uid'] === posix_getuid());
    }

    private function importAccessFile(
        Logger $logger,
        Repository $repo,
        AccessFileHistoryCreator $accessfile_history_creator
    ) {
        // Add entry to history
        $access_file = $accessfile_history_creator->create(
            $repo,
            $this->access_file_contents,
            time()
        );

        // Write .SVNAccessFile
        $writer = new SVN_AccessFile_Writer($repo->getSystemPath());
        $logger->info("[svn {$this->name}] Save Access File version #" . $access_file->getVersionNumber() . " to " . $writer->filename() . ": " . $access_file->getContent());
        if (! $writer->write_with_defaults($access_file->getContent())) {
            throw new XMLImporterException("Could not write to " . $writer->filename());
        }
    }

    private function importSubscriptions(
        Logger $logger,
        Repository $repo,
        MailNotificationManager $mail_notification_manager
    ) {
        foreach ($this->subscriptions as $subscription) {
            $logger->info("[svn {$this->name}] Add subscription to {$subscription['path']}: {$subscription['emails']}");
            $notif = new MailNotification(
                0,
                $repo,
                $subscription['path'],
                $this->notifications_emails_builder->transformNotificationEmailsStringAsArray($subscription['emails']),
                array(),
                array()
            );
            $mail_notification_manager->create($notif);
        }
    }

    private function importReferences(ImportConfig $configuration, Logger $logger, Repository $repo)
    {
        EventManager::instance()->processEvent(
            Event::IMPORT_COMPAT_REF_XML,
            array(
                'logger' => $logger,
                'created_refs' => array(
                    'repository' => $repo,
                ),
                'service_name' => self::SERVICE_NAME,
                'xml_content' => $this->references,
                'project' => $repo->getProject(),
                'configuration' => $configuration,
            )
        );
    }
}