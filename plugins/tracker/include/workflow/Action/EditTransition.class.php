<?php
/**
 * Copyright (c) Enalean, 2012. All Rights Reserved.
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
require_once 'Abstract.class.php';

class Tracker_Workflow_Action_EditTransition extends Tracker_Workflow_Action_Abstract {
    /** @var TransitionFactory */
    private $transition_factory;
    
    /** @var Transition_PostActionFactory */
    private $post_action_factory;
    
    public function __construct(Tracker $tracker, TransitionFactory $transition_factory, Transition_PostActionFactory $post_action_factory) {
        parent::__construct($tracker);
        $this->transition_factory  = $transition_factory;
        $this->post_action_factory = $post_action_factory;
    }

    public function process(Tracker_IDisplayTrackerLayout $layout, Codendi_Request $request, User $current_user) {
        //$workflow   = WorkflowFactory::instance()->getWorkflowByTrackerId($this->tracker->id);
        $transition = $this->transition_factory->getTransition($request->get('edit_transition'));
        $this->displayTransitionDetails($layout, $request, $current_user, $transition);
    }
    
    private function displayTransitionDetails(TrackerManager $engine, Codendi_Request $request, User $current_user, Transition $transition) {

        $hp = Codendi_HTMLPurifier::instance();
        $this->displayHeader($engine);

        $back_to_transitions_link = TRACKER_BASE_URL.'/?'. http_build_query(
            array(
                'tracker' => (int)$this->tracker->id,
                'func'    => 'admin-workflow'
            )
        );
        echo '<a href="'. $back_to_transitions_link .'">← Back to transitions</a>'; //TODO: i18n

        if($transition->getFieldValueFrom()) {
            $from_label = $transition->getFieldValueFrom()->getLabel();
        } else {
            $from_label = $GLOBALS['Language']->getText('workflow_admin','new_artifact');
        }
        $to_label = $transition->getFieldValueTo()->getLabel();

        echo '<p>';
        echo $GLOBALS['Language']->getText('workflow_admin','title_define_transition_details', array($from_label, $to_label));
        echo '</p>';

        $form_action = TRACKER_BASE_URL.'/?'. http_build_query(
            array(
                'tracker'    => (int)$this->tracker->id,
                'func'       => 'admin-workflow',
                'transition' => $transition->getTransitionId()
            )
        );
        echo '<form action="'. $form_action .'" method="POST">';
        echo '<table><tr><td>';

        $section_conditions = new Widget_Static($GLOBALS['Language']->getText('workflow_admin','under_the_following_condition'));
        $section_conditions->setContent($transition->fetchConditions());
        $section_conditions->display();

        $actions = '';
        $actions .= $transition->fetchPostActions();
        $actions .= $this->post_action_factory->fetchPostActions();
        $section_postactions = new Widget_Static($GLOBALS['Language']->getText('workflow_admin','following_action_performed'));
        $section_postactions->setContent($actions);
        $section_postactions->display();

        echo '<p><input type="submit" name="workflow_details" value="'. $GLOBALS['Language']->getText('global', 'btn_submit') .'" /></p>';
        echo '</td></tr></table>';
        echo '</form>';

        $this->displayFooter($engine);
    }
}

?>
