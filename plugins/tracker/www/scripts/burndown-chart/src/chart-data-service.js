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

import { getFormattedDates } from "charts-builders/chart-dates-service.js";

export { getLastDayData, getDisplayableData };

function getLastDayData(dataset) {
    if (!dataset.length) {
        return {};
    }

    return dataset[dataset.length - 1];
}

function getDisplayableData(dataset) {
    const filtered_data = dataset.filter(({ remaining_effort }) => remaining_effort !== null);

    return getFormattedDates(filtered_data);
}
