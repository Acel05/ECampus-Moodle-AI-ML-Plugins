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

        // First clean up any stuck models
        block_studentperformancepredictor_cleanup_pending_models($courseid);

        // Add more detailed logging for debugging
        debugging('Scheduling training task for course: ' . $courseid . ', dataset: ' . $datasetid . ', algorithm: ' . $algorithm, DEBUG_DEVELOPER);

        // Verify the dataset exists and belongs to the course if course-specific
        if ($courseid > 0) {
            $dataset = $DB->get_record('block_spp_datasets', ['id' => $datasetid, 'courseid' => $courseid]);
            if (!$dataset) {
                debugging('Dataset not found or does not belong to course', DEBUG_DEVELOPER);
                return false;
            }
        } else {
            // For global models, just check if dataset exists
            $dataset = $DB->get_record('block_spp_datasets', ['id' => $datasetid]);
            if (!$dataset) {
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

        $modelid = $DB->insert_record('block_spp_models', $model);
        if (!$modelid) {
            debugging('Failed to create model record', DEBUG_DEVELOPER);
            return false;
        }

        // Log initial training event
        self::log_training_event($modelid, 'scheduled', 'Training task scheduled');

        // Create adhoc task
        $task = new \block_studentperformancepredictor\task\adhoc_train_model();
        $customdata = [
            'courseid' => $courseid,
            'datasetid' => $datasetid,
            'algorithm' => $algorithm,
            'userid' => $USER->id,
            'timequeued' => time(),
            'modelid' => $modelid
        ];
        $task->set_custom_data($customdata);

        // Add debugging information
        debugging('Scheduling training task for course ' . $courseid . ' with dataset ' . $datasetid, DEBUG_DEVELOPER);

        try {
            // Queue the task with high priority
            \core\task\manager::queue_adhoc_task($task, true);
            debugging('Training task scheduled successfully', DEBUG_DEVELOPER);

            return true;
        } catch (\Exception $e) {
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

        // First, check for actual adhoc tasks in the database
        $sql = "SELECT COUNT(*) FROM {task_adhoc}
                WHERE classname = ?
                AND " . $DB->sql_like('customdata', '?');

        $classname = '\\block_studentperformancepredictor\\task\\adhoc_train_model';
        $customdata = '%"courseid":' . $courseid . '%';
        $adhoc_count = $DB->count_records_sql($sql, [$classname, $customdata]);

        // If we have adhoc tasks, training is pending
        if ($adhoc_count > 0) {
            debugging('Found actual adhoc tasks for course: ' . $courseid, DEBUG_DEVELOPER);
            return true;
        }

        // If no adhoc tasks, check for models in pending/training state
        $pending_models = $DB->count_records('block_spp_models', [
            'courseid' => $courseid, 
            'trainstatus' => 'pending'
        ]);

        $training_models = $DB->count_records('block_spp_models', [
            'courseid' => $courseid, 
            'trainstatus' => 'training'
        ]);

        // If we have pending/training models but no adhoc tasks, fix the inconsistency
        if ($pending_models > 0 || $training_models > 0) {
            debugging('Found stuck models without adhoc tasks for course: ' . $courseid, DEBUG_DEVELOPER);

            // This is the inconsistent state - fix it by updating model status
            if ($pending_models > 0) {
                debugging('Fixing pending models for course: ' . $courseid, DEBUG_DEVELOPER);
                $DB->set_field('block_spp_models', 'trainstatus', 'failed', [
                    'courseid' => $courseid,
                    'trainstatus' => 'pending'
                ]);
                $DB->set_field('block_spp_models', 'errormessage', 'Task missing - state fixed automatically', [
                    'courseid' => $courseid,
                    'trainstatus' => 'failed'
                ]);
            }

            if ($training_models > 0) {
                debugging('Fixing training models for course: ' . $courseid, DEBUG_DEVELOPER);
                $DB->set_field('block_spp_models', 'trainstatus', 'failed', [
                    'courseid' => $courseid,
                    'trainstatus' => 'training'
                ]);
                $DB->set_field('block_spp_models', 'errormessage', 'Task missing - state fixed automatically', [
                    'courseid' => $courseid,
                    'trainstatus' => 'failed'
                ]);
            }

            // Now we don't have pending training anymore
            return false;
        }

        // No pending training
        return false;
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
