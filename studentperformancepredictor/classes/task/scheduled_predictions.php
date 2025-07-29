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
 * Scheduled task for refreshing predictions.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_studentperformancepredictor\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Task to automatically refresh predictions at scheduled times.
 */
class scheduled_predictions extends \core\task\scheduled_task {
    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_scheduled_predictions', 'block_studentperformancepredictor');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

        mtrace('Starting scheduled prediction task');

        // Get refresh interval (hours)
        $refresh_interval = get_config('block_studentperformancepredictor', 'refreshinterval');
        if (empty($refresh_interval) || !is_numeric($refresh_interval)) {
            $refresh_interval = 24; // Default to 24 hours
        }
        $refresh_interval_seconds = $refresh_interval * 3600;

        // Find courses with active models
        $sql = "SELECT DISTINCT c.id, c.fullname
                FROM {course} c
                JOIN {block_spp_models} m ON (m.courseid = c.id OR m.courseid = 0)
                WHERE m.active = 1 AND m.trainstatus = 'complete'";

        $courses = $DB->get_records_sql($sql);
        mtrace('Found ' . count($courses) . ' courses with active models');

        foreach ($courses as $course) {
            // Check when this course was last refreshed
            $last_refresh = get_config('block_studentperformancepredictor', 'lastrefresh_' . $course->id);

            // If no last refresh or refresh interval has passed
            if (empty($last_refresh) || (time() - $last_refresh) > $refresh_interval_seconds) {
                mtrace("Scheduling prediction refresh for course: {$course->fullname} (ID: {$course->id})");

                // Trigger refresh for this course
                try {
                    block_studentperformancepredictor_trigger_prediction_refresh($course->id);
                    mtrace("Prediction refresh triggered for course ID: {$course->id}");
                } catch (\Exception $e) {
                    mtrace("Error triggering prediction refresh for course ID: {$course->id} - " . $e->getMessage());
                }
            } else {
                $time_since_refresh = time() - $last_refresh;
                $hours_since_refresh = round($time_since_refresh / 3600, 1);
                mtrace("Skipping course ID: {$course->id} - last refreshed {$hours_since_refresh} hours ago (interval is {$refresh_interval} hours)");
            }
        }

        mtrace('Completed scheduled prediction task');
    }
}
