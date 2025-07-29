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
 * Task for refreshing predictions.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_studentperformancepredictor\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Task to refresh predictions.
 */
class refresh_predictions extends \core\task\adhoc_task {
    /**
     * Execute the task.
     */
    public function execute() {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

        mtrace("Starting prediction refresh task");

        // Get the task data
        $data = $this->get_custom_data();
        if (!isset($data->courseid)) {
            mtrace("Missing courseid parameter for prediction refresh task");
            return;
        }

        $courseid = $data->courseid;
        mtrace("Refreshing predictions for course ID: $courseid");

        // Enable debug mode if set in config
        $debug = get_config('block_studentperformancepredictor', 'enabledebug');

        try {
            $context = \context_course::instance($courseid);

            // Verify that we have an active model (either course-specific or global)
            $model = $DB->get_record('block_spp_models', ['courseid' => $courseid, 'active' => 1, 'trainstatus' => 'complete']);
            if (!$model && get_config('block_studentperformancepredictor', 'enableglobalmodel')) {
                $model = $DB->get_record('block_spp_models', ['courseid' => 0, 'active' => 1, 'trainstatus' => 'complete']);
            }

            if (!$model) {
                mtrace("No active model found for course ID: $courseid");
                return;
            }

            // Test connection to the backend
            if ($debug) {
                mtrace("Testing connection to ML backend");
                $health_check = block_studentperformancepredictor_call_backend_api('health', []);
                if (!$health_check) {
                    mtrace("Warning: ML backend connection test failed. Will attempt predictions anyway.");
                } else {
                    mtrace("ML backend connection test successful.");
                }
            }

            // Get all students enrolled in the course
            $students = get_enrolled_users($context, 'moodle/course:isincompletionreports');
            mtrace("Found " . count($students) . " students enrolled in the course");

            $success = 0;
            $errors = 0;
            $skipped = 0;
            $batch_size = 10; // Process students in batches to avoid timeouts
            $student_batches = array_chunk($students, $batch_size, true);

            foreach ($student_batches as $batch_index => $batch) {
                mtrace("Processing batch " . ($batch_index + 1) . " of " . count($student_batches));

                foreach ($batch as $student) {
                    mtrace("Generating prediction for student ID: {$student->id}");

                    try {
                        // Use database transaction for each student prediction
                        $transaction = $DB->start_delegated_transaction();

                        $predictionid = block_studentperformancepredictor_generate_prediction($courseid, $student->id);

                        if ($predictionid) {
                            $success++;
                            $transaction->allow_commit();
                            mtrace("Prediction generated successfully for student ID: {$student->id}");
                        } else {
                        $errors++;
                        $transaction->rollback($e);
                        mtrace("Error generating prediction for student ID: {$student->id}");
                        }
                    } catch (\Exception $e) {
                        if (isset($transaction)) {
                            $transaction->rollback();
                        }
                        $errors++;
                        mtrace("Exception generating prediction for student ID: {$student->id} - " . $e->getMessage());
                        if ($debug) {
                            mtrace("Stacktrace: " . $e->getTraceAsString());
                        }
                    }

                    // Small delay to prevent overloading the backend
                    usleep(100000); // 100ms
                }

                // Add a pause between batches to prevent overloading
                sleep(2);
            }

            // Update the last refresh time
            set_config('lastrefresh_' . $courseid, time(), 'block_studentperformancepredictor');

            mtrace("Prediction refresh completed: $success successful, $errors errors, $skipped skipped");

            // Notify user who requested refresh if specified
            if (isset($data->userid)) {
                $this->send_completion_notification($data->userid, $courseid, count($students), $success, $errors);
            }
        } catch (\Exception $e) {
            mtrace("Error in prediction refresh task: " . $e->getMessage());
            if ($debug) {
                mtrace("Stacktrace: " . $e->getTraceAsString());
            }
        }
    }

    /**
     * Send a notification about the completion of the task.
     *
     * @param int $userid User to notify
     * @param int $courseid Course ID
     * @param int $total Total number of students
     * @param int $success Number of successful predictions
     * @param int $errors Number of errors
     */
    protected function send_completion_notification($userid, $courseid, $total, $success, $errors) {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            return;
        }

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return;
        }

        $subject = get_string('prediction_refresh_complete_subject', 'block_studentperformancepredictor');

        $messagedata = new \stdClass();
        $messagedata->coursename = format_string($course->fullname);
        $messagedata->total = $total;
        $messagedata->success = $success;
        $messagedata->errors = $errors;

        $message = get_string('prediction_refresh_complete_message', 'block_studentperformancepredictor', $messagedata);

        $eventdata = new \core\message\message();
        $eventdata->courseid = $courseid;
        $eventdata->component = 'block_studentperformancepredictor';
        $eventdata->name = 'prediction_refresh_complete';
        $eventdata->userfrom = \core_user::get_noreply_user();
        $eventdata->userto = $user;
        $eventdata->subject = $subject;
        $eventdata->fullmessage = $message;
        $eventdata->fullmessageformat = FORMAT_HTML;
        $eventdata->fullmessagehtml = $message;
        $eventdata->smallmessage = get_string('prediction_refresh_complete_small', 'block_studentperformancepredictor');
        $eventdata->notification = 1;

        message_send($eventdata);
    }
}
