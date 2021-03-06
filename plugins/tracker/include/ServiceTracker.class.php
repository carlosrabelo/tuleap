<?php
/**
 * Copyright (c) Enalean, 2011 - 2017. All Rights Reserved.
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
 * along with Tuleap; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

class ServiceTracker extends Service {

    public const NAME = 'tracker';

    /**
     * Display header for service tracker
     *
     * @param string $title       The title
     * @param array  $breadcrumbs array of breadcrumbs (array of 'url' => string, 'title' => string)
     * @param array  $toolbar     array of toolbars (array of 'url' => string, 'title' => string)
     *
     * @return void
     */
    public function displayHeader($title, $breadcrumbs, $toolbar, $params = array()) {
        $GLOBALS['HTML']->includeCalendarScripts();

        $tracker_manager         = new TrackerManager();
        $user_has_special_access = $tracker_manager->userCanAdminAllProjectTrackers();

        $params = $params + array('user_has_special_access' => $user_has_special_access);
        $params['service_name'] = self::NAME;
        $params['project_id']   = $this->getGroupId();

        parent::displayHeader($title, $breadcrumbs, $toolbar, $params);
    }
    
    /**
     * Duplicate this service from the current project to another
     * 
     * @param int   $to_project_id  The target paroject Id
     * @param array $ugroup_mapping The ugroup mapping
     * 
     * @return void
     */
    public function duplicate($to_project_id, $ugroup_mapping) {
        $tracker_manager = $this->getTrackerManager();
        $tracker_manager->duplicate($this->project->getId(), $to_project_id, $ugroup_mapping);
    }
    
    /**
     * @return TrackerManager 
     */
    protected function getTrackerManager() {
        return new TrackerManager();
    }
    
    /**
     * Say if the service is allowed for the project
     *
     * @param Project $project
     *
     * @return bool
     */
    protected function isAllowed($project) {
        $plugin_manager = PluginManager::instance();
        $p = $plugin_manager->getPluginByName('tracker');
        if ($p && $plugin_manager->isPluginAvailable($p) && $p->isAllowed($project->getGroupId())) {
            return true;
        }
        return false;
    }
    
    /**
     * Say if the service is restricted
     *
     * @param Project $project
     *
     * @return bool
     */
    public function isRestricted() {
        $plugin_manager = PluginManager::instance();
        $p = $plugin_manager->getPluginByName('tracker');
        if ($p && $plugin_manager->isProjectPluginRestricted($p)) {
            return true;
        }
        return false;
    }
    
    /**
     * Trackers are cloned on project creation
     * 
     * @see Service::isInheritedOnDuplicate()
     * 
     * @return bool
     */
    public function isInheritedOnDuplicate() {
        return true;
    }

    public static function getDefaultServiceData($project_id)
    {
        return array(
            'label'        => 'plugin_tracker:service_lbl_key',
            'description'  => 'plugin_tracker:service_desc_key',
            'link'         => "/plugins/tracker/?group_id=$project_id",
            'short_name'   => trackerPlugin::SERVICE_SHORTNAME,
            'scope'        => 'system',
            'rank'         => 151,
            'location'     => 'master',
            'is_in_iframe' => 0,
            'server_id'    => 0,
        );
    }
}
?>
