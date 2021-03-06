// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Javascript controller for the "Actions" panel at the bottom of the page.
 *
 * @package    format_flexsections
 * @author     Jean-Roch Meurisse
 * @copyright  2018 University of Namur - Cellule TICE
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/log', 'core/str'], function($, log, str) {

    "use strict";

    /**
     * Update toggles state of current course in browser storage.
     */
    var setState = function(course, toggles, storage) {
        if (storage == 'local') {
            window.localStorage.setItem('sections-toggle-' + course, JSON.stringify(toggles));
        } else if (storage == 'session') {
            window.sessionStorage.setItem('sections-toggle-' + course, JSON.stringify(toggles));
        }
    };

    /**
     * Update toggles state of current course in browser storage.
     */
    var getState = function(course, storage) {
        var toggles;
        if (storage == 'local') {
            toggles = window.localStorage.getItem('sections-toggle-' + course);
        } else if (storage == 'session') {
            toggles = window.sessionStorage.getItem('sections-toggle-' + course);
        }
        if (toggles === null) {
            return {};
        } else {
            return JSON.parse(toggles);
        }
    };

    return {
        init: function(args) {
            console.info('Format flexsections AMD module initialized');
            log.debug('Format flexsections AMD module initialized');
            $(document).ready(function($) {
                var sectiontoggles;
                var keepstateoversession = args.keepstateoversession;
                var storage;
                if (keepstateoversession == 1) {
                    storage = 'local';   // Use browser local storage.
                } else {
                    storage = 'session'; // Use browser session storage.
                }
                sectiontoggles = getState(args.course, storage);
                // ELO 2022
                setTimeout(function() {
                    // debugger;
                    Object.keys(sectiontoggles).forEach(function(sectiontoggle) {
                        var section = "#"+sectiontoggle;
                        $(section).collapse('show');
                    });
                }, 100);
                
                $('.collapse').on('show.bs.collapse', function(event) {
                    var sectionstringid = $(event.target).attr('id');
                    var sectionid = sectionstringid.substring(sectionstringid.lastIndexOf('-') + 1);

                    if (!sectiontoggles.hasOwnProperty(sectionid)) {
                        sectiontoggles[sectionid] = "true";
                        setState(args.course, sectiontoggles, storage);
                    }
                });
                $('.collapse').on('hide.bs.collapse', function(event) {
                    var sectionstringid = $(event.target).attr('id');
                    var sectionid = sectionstringid.substring(sectionstringid.lastIndexOf('-') + 1);

                    if (sectiontoggles.hasOwnProperty(sectionid)) {
                        delete sectiontoggles[sectionid];
                        setState(args.course, sectiontoggles, storage);
                    }
                });
                
            });
        }
    };
});
