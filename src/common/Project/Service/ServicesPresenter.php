<?php
/**
 * Copyright (c) Enalean, 2017. All Rights Reserved.
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

namespace Tuleap\Project\Service;

use CSRFSynchronizerToken;
use Project;

class ServicesPresenter
{
    public $services;
    public $csrf;
    public $project_id;
    public $is_default_template;
    public $minimal_rank;

    public function __construct(Project $project, CSRFSynchronizerToken $csrf, array $services)
    {
        $this->services            = $services;
        $this->csrf                = $csrf;
        $this->project_id          = $project->getID();
        $this->is_default_template = (int)$project->getID() === Project::ADMIN_PROJECT_ID;
        $this->minimal_rank        = $project->getMinimalRank() + 1;
    }
}
