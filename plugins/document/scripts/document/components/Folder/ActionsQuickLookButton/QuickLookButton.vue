<!--
  - Copyright (c) Enalean, 2019. All Rights Reserved.
  -
  - This file is a part of Tuleap.
  -
  - Tuleap is free software; you can redistribute it and/or modify
  - it under the terms of the GNU General Public License as published by
  - the Free Software Foundation; either version 2 of the License, or
  - (at your option) any later version.
  -
  - Tuleap is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU General Public License for more details.
  -
  - You should have received a copy of the GNU General Public License
  - along with Tuleap. If not, see http://www.gnu.org/licenses/.
  -
  -->

<template>
    <button type="button"
            class="tlp-button-primary tlp-button-outline tlp-button-small tlp-dropdown-split-button-main"
            v-on:click="displayQuickLook()"
            data-test="quick-look-button"
    >
        <i class="fa fa-eye tlp-button-icon"></i>
        <translate>Quick look</translate>
    </button>
</template>

<script>
import { mapState } from "vuex";
export default {
    name: "QuickLookButton",
    computed: {
        ...mapState(["currently_previewed_item"])
    },
    methods: {
        displayQuickLook() {
            this.$emit("displayQuickLook", this.currently_previewed_item);
        }
    }
};
</script>
