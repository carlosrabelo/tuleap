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
 * along with Tuleap. If not, see http://www.gnu.org/licenses/.
 *
 */

declare(strict_types=1);

namespace Tuleap\Docman\REST\v1\Metadata;

use Tuleap\Docman\REST\v1\ItemRepresentation;

class HardcodedMetadataObsolescenceDateRetriever
{

    /**
     * @var HardcodedMetdataObsolescenceDateChecker
     */
    private $date_checker;

    public function __construct(HardcodedMetdataObsolescenceDateChecker $date_checker)
    {
        $this->date_checker = $date_checker;
    }

    /**
     * @return int
     * @throws InvalidDateTimeFormatException
     */
    public function getTimeStampOfDate(string $date, int $item_type)
    {
        if (!$this->date_checker->isObsolescenceMetadataUsed() || $item_type === PLUGIN_DOCMAN_ITEM_TYPE_FOLDER) {
            return (int)ItemRepresentation::OBSOLESCENCE_DATE_NONE;
        }

        $formatted_date = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$formatted_date) {
            throw new InvalidDateTimeFormatException();
        }

        return $formatted_date->getTimestamp();
    }
}
