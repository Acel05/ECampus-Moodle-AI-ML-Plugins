<?php
// classes/analytics/training_manager.php

namespace block_studentperformancepredictor\analytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Training manager for student performance predictor.
 *
 * This class manages the model training process through the Python backend API.
 */
class training_manager {
    /**
     * Schedules a model training task.
     *
     * @param int $courseid The course ID
     * @param int $datasetid The dataset ID
     * @param string|null $algorithm Optional algorithm to use
     * @return bool True if task was scheduled successfully
     */
    public static function schedule_training(int $courseid, int $datasetid, ?string $algorithm = null): bool {
        global $USER, $DB;

        // Add more detailed logging for debugging
        error_log("[SPP] Scheduling training task for course: $courseid, dataset: $datasetid, algorithm: $algorithm");

        // Verify the dataset exists and belongs to the course if course-specific
        if ($courseid > 0) {
            $dataset = $DB->get_record('block_spp_datasets', ['id' => $datasetid, 'courseid' => $courseid]);
            if (!$dataset) {
                error_log("[SPP] Dataset not found or does not belong to course");
                debugging('Dataset not found or does not belong to course', DEBUG_DEVELOPER);
                return false;
            }
        } else {
            // For global models, just check if dataset exists
            $dataset = $DB->get_record('block_spp_datasets', ['id' => $datasetid]);
            if (!$dataset) {
                error_log("[SPP] Dataset not found");
                debugging('Dataset not found', DEBUG_DEVELOPER);
                return false;
            }
        }

        // Create a record in block_spp_models table with trainstatus='pending'
        $model = new \stdClass();
        $model->courseid = $courseid;
        $model->datasetid = $datasetid;
        $model->modelname = ($algorithm ? ucfirst($algorithm) : 'Model') . ' - ' . date('Y-m-d H:i');
        $model->algorithmtype = $algorithm ?? 'randomforest';
        $model->active = 0;
        $model->trainstatus = 'pending';
        $model->timecreated = time();
        $model->timemodified = time();
        $model->usermodified = $USER->id;

        try {
            $modelid = $DB->insert_record('block_spp_models', $model);
            if (!$modelid) {
                error_log("[SPP] Failed to create model record");
                debugging('Failed to create model record', DEBUG_DEVELOPER);
                return false;
            }

            error_log("[SPP] Created model record with ID: $modelid");

            // Log initial training event
            self::log_training_event($modelid, 'scheduled', 'Training task scheduled');
        } catch (\Exception $e) {
            error_log("[SPP] Error creating model record: " . $e->getMessage());
            debugging('Error creating model record: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }

        // Create adhoc task
        try {
            // Make sure the class exists and is loaded
            if (!class_exists('\\block_studentperformancepredictor\\task\\adhoc_train_model')) {
                error_log("[SPP] adhoc_train_model class not found");
                debugging('adhoc_train_model class not found', DEBUG_DEVELOPER);
                return false;
            }

            $task = new \block_studentperformancepredictor\task\adhoc_train_model();

            // Set custom data with all required information
            $customdata = [
                'courseid' => $courseid,
                'datasetid' => $datasetid,
                'algorithm' => $algorithm,
                'userid' => $USER->id,
                'timequeued' => time(),
                'modelid' => $modelid
            ];

            error_log("[SPP] Setting custom data: " . json_encode($customdata));
            $task->set_custom_data($customdata);

            // Add debugging information
            debugging('Scheduling training task for course ' . $courseid . ' with dataset ' . $datasetid, DEBUG_DEVELOPER);

            // Queue the task with high priority
            $taskid = \core\task\manager::queue_adhoc_task($task, true);
            error_log("[SPP] Task queued with ID: $taskid");
            debugging('Training task scheduled successfully with ID: ' . $taskid, DEBUG_DEVELOPER);

            return true;
        } catch (\Exception $e) {
            error_log("[SPP] Error scheduling training task: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            debugging('Error scheduling training task: ' . $e->getMessage(), DEBUG_DEVELOPER);

            // Update model record to reflect failure
            $model = new \stdClass();
            $model->id = $modelid;
            $model->trainstatus = 'failed';
            $model->errormessage = 'Failed to schedule training task: ' . $e->getMessage();
            $model->timemodified = time();
            $DB->update_record('block_spp_models', $model);

            return false;
        }
    }

    /**
     * Check if there's already a training task scheduled.
     *
     * @param int $courseid The course ID
     * @return bool True if a task exists
     */
    public static function has_pending_training(int $courseid): bool {
        global $DB;

        // Check for pending adhoc_train_model tasks
        $sql = "SELECT COUNT(*) FROM {task_adhoc}
                WHERE classname = ?
                AND " . $DB->sql_like('customdata', '?');

        $classname = '\\block_studentperformancepredictor\\task\\adhoc_train_model';
        $customdata = '%"courseid":' . $courseid . '%';
        $count = $DB->count_records_sql($sql, [$classname, $customdata]);

        // Also check for models with 'pending' or 'training' status
        $pendingmodels = $DB->count_records('block_spp_models', [
            'courseid' => $courseid, 
            'trainstatus' => 'pending'
        ]);

        $trainingmodels = $DB->count_records('block_spp_models', [
            'courseid' => $courseid, 
            'trainstatus' => 'training'
        ]);

        return ($count > 0 || $pendingmodels > 0 || $trainingmodels > 0);
    }

    /**
     * Logs a training event.
     *
     * @param int $modelid The model ID
     * @param string $event The event type
     * @param string $message The log message
     * @param string $level The log level (error, warning, info)
     * @return bool True if logged successfully
     */
    public static function log_training_event(int $modelid, string $event, string $message, string $level = 'info'): bool {
        global $DB;
        // Only log if modelid is valid
        if (empty($modelid) || $modelid <= 0) {
            debugging("Skipping training log: invalid modelid ($modelid) for event '$event'", DEBUG_DEVELOPER);
            return false;
        }

        $log = new \stdClass();
        $log->modelid = $modelid;
        $log->event = $event;
        $log->message = $message;
        $log->level = $level;
        $log->timecreated = time();

        try {
            $DB->insert_record('block_spp_training_log', $log);
            debugging("Training log: [$level] $event - $message", DEBUG_DEVELOPER);
            return true;
        } catch (\Exception $e) {
            debugging('Error logging training event: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Update model status after training.
     *
     * @param int $modelid The model ID
     * @param string $status New status ('complete', 'failed')
     * @param array $data Additional data (metrics, error message, etc.)
     * @return bool True if updated successfully
     */
    public static function update_model_status(int $modelid, string $status, array $data = []): bool {
        global $DB;

        // Only update if modelid is valid
        if (empty($modelid) || $modelid <= 0) {
            debugging("Cannot update model status: invalid modelid ($modelid)", DEBUG_DEVELOPER);
            return false;
        }

        try {
            $model = $DB->get_record('block_spp_models', ['id' => $modelid]);
            if (!$model) {
                debugging("Model not found with ID: $modelid", DEBUG_DEVELOPER);
                return false;
            }

            // Update model fields
            $model->trainstatus = $status;
            $model->timemodified = time();

            // Add any additional data
            if (isset($data['accuracy'])) {
                $model->accuracy = $data['accuracy'];
            }

            if (isset($data['metrics'])) {
                $model->metrics = json_encode($data['metrics']);
            }

            if (isset($data['modelid'])) {
                $model->modelid = $data['modelid'];
            }

            if (isset($data['modelpath'])) {
                $model->modelpath = $data['modelpath'];
            }

            if (isset($data['error'])) {
                $model->errormessage = $data['error'];
                self::log_training_event($modelid, 'error', $data['error'], 'error');
            }

            // Update the record
            $DB->update_record('block_spp_models', $model);

            // Log the status change
            self::log_training_event($modelid, 'status_change', "Model status changed to: $status");

            return true;
        } catch (\Exception $e) {
            debugging('Error updating model status: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }
}
