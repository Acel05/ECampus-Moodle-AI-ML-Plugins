<?php
// classes/task/adhoc_train_model.php

namespace block_studentperformancepredictor\task;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

/**
 * Ad-hoc task to train a new model on demand.
 */
class adhoc_train_model extends \core\task\adhoc_task {
    /**
     * Execute the ad-hoc task.
     */
    public function execute() {
        global $DB, $CFG;

        mtrace('Starting model training task execution...');

        // Get the task data
        $data = $this->get_custom_data();
        if (!isset($data->courseid) || !isset($data->datasetid)) {
            mtrace('Error: Missing required parameters for model training task.');
            return;
        }

        $courseid = $data->courseid;
        $datasetid = $data->datasetid;
        $algorithm = isset($data->algorithm) ? $data->algorithm : null;
        $userid = isset($data->userid) ? $data->userid : null;
        $modelid = isset($data->modelid) ? $data->modelid : null;

        mtrace("Training model for course {$courseid} using dataset {$datasetid} with algorithm {$algorithm}");

        try {
            // Update model status if we have a model ID
            if ($modelid) {
                $model = $DB->get_record('block_spp_models', ['id' => $modelid]);
                if ($model) {
                    $model->trainstatus = 'training';
                    $model->timemodified = time();
                    $DB->update_record('block_spp_models', $model);
                    mtrace("Updated model status to 'training'");
                }
            }

            // Call the backend to train the model
            $modelid = block_studentperformancepredictor_train_model_via_backend($courseid, $datasetid, $algorithm);

            if ($modelid) {
                mtrace("Model training completed successfully. Model ID: {$modelid}");

                // Trigger model trained event
                $context = \context_course::instance($courseid > 0 ? $courseid : SITEID);
                $event = \block_studentperformancepredictor\event\model_trained::create([
                    'context' => $context,
                    'objectid' => $modelid,
                    'other' => [
                        'courseid' => $courseid,
                        'datasetid' => $datasetid,
                        'algorithm' => $algorithm
                    ]
                ]);
                $event->trigger();

                // Generate initial predictions for all students in the course
                mtrace("Generating initial predictions for students in course {$courseid}");
                $result = block_studentperformancepredictor_refresh_predictions_via_backend($courseid);
                mtrace("Generated predictions: {$result['success']} successful, {$result['errors']} errors");

                // If user ID specified, send a notification
                if ($userid) {
                    $this->send_success_notification($userid, $courseid, $modelid);
                }
            } else {
                mtrace("Error: Model training failed or returned null model ID");

                // Update model status if we have a model ID
                if ($modelid) {
                    $model = $DB->get_record('block_spp_models', ['id' => $modelid]);
                    if ($model) {
                        $model->trainstatus = 'failed';
                        $model->errormessage = "Failed to create a valid model";
                        $model->timemodified = time();
                        $DB->update_record('block_spp_models', $model);
                        mtrace("Updated model status to 'failed'");
                    }
                }

                // If user ID specified, send error notification
                if ($userid) {
                    $this->send_error_notification($userid, $courseid, "Failed to create a valid model");
                }
            }
        } catch (\Exception $e) {
            mtrace("Error during model training: " . $e->getMessage());
            mtrace($e->getTraceAsString()); // Add stack trace for better debugging

            // Update model status if we have a model ID
            if ($modelid) {
                $model = $DB->get_record('block_spp_models', ['id' => $modelid]);
                if ($model) {
                    $model->trainstatus = 'failed';
                    $model->errormessage = $e->getMessage();
                    $model->timemodified = time();
                    $DB->update_record('block_spp_models', $model);
                    mtrace("Updated model status to 'failed'");
                }
            }

            // If user ID specified, send error notification
            if ($userid) {
                $this->send_error_notification($userid, $courseid, $e->getMessage());
            }
        }
    }

    /**
     * Send a notification about successful model training.
     *
     * @param int $userid User to notify
     * @param int $courseid Course ID
     * @param int $modelid Newly created model ID
     */
    protected function send_success_notification($userid, $courseid, $modelid) {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            return;
        }

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return;
        }

        $model = $DB->get_record('block_spp_models', ['id' => $modelid]);
        if (!$model) {
            return;
        }

        $subject = get_string('model_training_success_subject', 'block_studentperformancepredictor');

        $messagedata = new \stdClass();
        $messagedata->modelname = format_string($model->modelname);
        $messagedata->coursename = format_string($course->fullname);

        $message = get_string('model_training_success_message', 'block_studentperformancepredictor', $messagedata);

        $eventdata = new \core\message\message();
        $eventdata->courseid = $courseid;
        $eventdata->component = 'block_studentperformancepredictor';
        $eventdata->name = 'model_training_success';
        $eventdata->userfrom = \core_user::get_noreply_user();
        $eventdata->userto = $user;
        $eventdata->subject = $subject;
        $eventdata->fullmessage = $message;
        $eventdata->fullmessageformat = FORMAT_HTML;
        $eventdata->fullmessagehtml = $message;
        $eventdata->smallmessage = $subject;
        $eventdata->notification = 1;

        message_send($eventdata);
    }

    /**
     * Send a notification about failed model training.
     *
     * @param int $userid User to notify
     * @param int $courseid Course ID
     * @param string $error Error message
     */
    protected function send_error_notification($userid, $courseid, $error) {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            return;
        }

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return;
        }

        $subject = get_string('model_training_error_subject', 'block_studentperformancepredictor');

        $messagedata = new \stdClass();
        $messagedata->coursename = format_string($course->fullname);
        $messagedata->error = $error;

        $message = get_string('model_training_error_message', 'block_studentperformancepredictor', $messagedata);

        $eventdata = new \core\message\message();
        $eventdata->courseid = $courseid;
        $eventdata->component = 'block_studentperformancepredictor';
        $eventdata->name = 'model_training_error';
        $eventdata->userfrom = \core_user::get_noreply_user();
        $eventdata->userto = $user;
        $eventdata->subject = $subject;
        $eventdata->fullmessage = $message;
        $eventdata->fullmessageformat = FORMAT_HTML;
        $eventdata->fullmessagehtml = $message;
        $eventdata->smallmessage = $subject;
        $eventdata->notification = 1;

        message_send($eventdata);
    }
}
