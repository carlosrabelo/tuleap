/*
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

import { shallowMount } from "@vue/test-utils";
import TimeTrackingOverviewWritingTrackers from "../../../components/writing-mode/TimeTrackingOverviewWritingTrackers.vue";
import TimeTrackingOverviewTrackersOptions from "../../../components/writing-mode/TimeTrackingOverviewTrackersOptions.vue";
import { createStoreMock } from "../../helpers/store-wrapper.spec-helper";
import localVue from "../../helpers/local-vue.js";

describe("Given a timetracking overview widget on writing mode", () => {
    let component_options, store_options, store;
    beforeEach(() => {
        store_options = {
            state: {
                projects: ["leprojet"],
                trackers: ["letracker"],
                is_loading_tracker: false
            },
            getters: {
                has_success_message: false
            }
        };
        store = createStoreMock(store_options);

        component_options = {
            localVue,
            mocks: { $store: store }
        };
    });

    it("When trackers and projects are available, then it's possible to click on add button", () => {
        const wrapper = shallowMount(TimeTrackingOverviewWritingTrackers, component_options);
        expect(wrapper.contains("[data-test=icon-spinner]")).toBeFalsy();
        expect(wrapper.contains("[data-test=icon-plus]")).toBeTruthy();
        expect(wrapper.contains("[data-test=icon-ban]")).toBeFalsy();
    });

    it("When trackers and projects are available, then click on add button", () => {
        const wrapper = shallowMount(TimeTrackingOverviewWritingTrackers, component_options);

        wrapper.find(TimeTrackingOverviewTrackersOptions).vm.$emit("input", "letracker");
        wrapper.find("[data-test=add-tracker-button]").trigger("click");
        expect(store.commit).toHaveBeenCalledWith("addSelectedTrackers", "letracker");
    });

    it("When trackers are not available, then ban icon is displayed", () => {
        store.state.trackers = [];

        const wrapper = shallowMount(TimeTrackingOverviewWritingTrackers, component_options);
        expect(wrapper.contains("[data-test=icon-spinner]")).toBeFalsy();
        expect(wrapper.contains("[data-test=icon-plus]")).toBeFalsy();
        expect(wrapper.contains("[data-test=icon-ban]")).toBeTruthy();
    });

    it("When projects are not available, then spinner icon is displayed", () => {
        store.state.projects = [];
        store.state.is_loading_tracker = true;

        const wrapper = shallowMount(TimeTrackingOverviewWritingTrackers, component_options);
        expect(wrapper.contains("[data-test=icon-spinner]")).toBeTruthy();
        expect(wrapper.contains("[data-test=icon-plus]")).toBeFalsy();
        expect(wrapper.contains("[data-test=icon-ban]")).toBeFalsy();
    });
});
