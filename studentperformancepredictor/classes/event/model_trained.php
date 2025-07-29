<?php
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
 * Event triggered when a model is trained.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_studentperformancepredictor\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event triggered when a model is trained.
 */
class model_trained extends \core\event\base {

    /**
     * Initialize the event.
     */
    protected function init() {
        $this->data['crud'] = 'c'; // Create
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'block_spp_models';
    }

    /**
     * Get the event name.
     *
     * @return string Event name
     */
    public static function get_name() {
        return get_string('event_model_trained', 'block_studentperformancepredictor');
    }

    /**
     * Get the event description.
     *
     * @return string Event description
     */
    public function get_description() {
        return "The user with id '{$this->userid}' trained a new prediction model with id '{$this->objectid}' for the course with id '{$this->courseid}'.";
    }

    /**
     * Get the event URL.
     *
     * @return \moodle_url Event URL
     */
    public function get_url() {
        return new \moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', [
            'courseid' => $this->courseid,
            'modelid' => $this->objectid
        ]);
    }

    /**
     * Get the legacy log data.
     *
     * @return array Legacy log data
     */
    protected function get_legacy_logdata() {
        return [
            $this->courseid, 
            'block_studentperformancepredictor', 
            'train_model',
            $this->get_url()->out_as_local_url(), 
            $this->objectid, 
            $this->contextinstanceid
        ];
    }
}