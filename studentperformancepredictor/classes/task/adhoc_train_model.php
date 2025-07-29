<?php
// classes/task/adhoc_train_model.php

namespace block_studentperformancepredictor\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Ad-hoc task to train a new model on demand.
 */
class adhoc_train_model extends \core\task\adhoc_task {

    /**
     * Return the name of this task.
     * 
     * @return string
     */
    public function get_name() {
        return get_string('task_train_model', 'block_studentperformancepredictor');
    }

    /**
     * Execute the ad-hoc task.
     */
    public function execute() {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

        mtrace("\n");
        mtrace("======================================");
        mtrace("Starting model training task execution");
        mtrace("======================================");

        // Get the task data
        $data = $this->get_custom_data();
        mtrace("Task data: " . json_encode($data));

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
            mtrace("Calling block_studentperformancepredictor_train_model_via_backend()");
            $result_modelid = block_studentperformancepredictor_train_model_via_backend($courseid, $datasetid, $algorithm);

            if ($result_modelid) {
                mtrace("Model training completed successfully. Model ID: {$result_modelid}");

                // If we already had a model ID from the initial creation, update that record
                if ($modelid && $modelid != $result_modelid) {
                    mtrace("Updating existing model record $modelid with training results");
                    $existing_model = $DB->get_record('block_spp_models', ['id' => $modelid]);
                    $new_model = $DB->get_record('block_spp_models', ['id' => $result_modelid]);

                    // Only update if we found both records
                    if ($existing_model && $new_model) {
                        // Copy values from the new model to the existing one
                        $existing_model->modelid = $new_model->modelid;
                        $existing_model->modelpath = $new_model->modelpath;
                        $existing_model->featureslist = $new_model->featureslist;
                        $existing_model->accuracy = $new_model->accuracy;
                        $existing_model->metrics = $new_model->metrics;
                        $existing_model->trainstatus = 'complete';
                        $existing_model->timemodified = time();

                        // Update the record
                        if ($DB->update_record('block_spp_models', $existing_model)) {
                            mtrace("Successfully updated model record");

                            // Delete the new record since we've merged its data
                            $DB->delete_records('block_spp_models', ['id' => $result_modelid]);
                            $result_modelid = $modelid;
                        } else {
                            mtrace("Failed to update existing model record");
                        }
                    }
                }

                // Trigger model trained event
                $context = \context_course::instance($courseid > 0 ? $courseid : SITEID);
                $event = \block_studentperformancepredictor\event\model_trained::create([
                    'context' => $context,
                    'objectid' => $result_modelid,
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
                    $this->send_success_notification($userid, $courseid, $result_modelid);
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

        mtrace("====================================");
        mtrace("Finished model training task execution");
        mtrace("====================================\n");
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
