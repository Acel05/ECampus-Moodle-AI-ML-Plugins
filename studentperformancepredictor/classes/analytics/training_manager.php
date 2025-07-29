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
 
namespace block_studentperformancepredictor\analytics;
 
defined('MOODLE_INTERNAL') || die();
 
/**
 * Training manager for student performance predictor.
 *
 * This class manages the model training process through the Python backend API.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
 
        // Verify the dataset exists and belongs to the course.
        $dataset = $DB->get_record('block_spp_datasets', ['id' => $datasetid, 'courseid' => $courseid]);
        if (!$dataset) {
            debugging('Dataset not found or does not belong to course', DEBUG_DEVELOPER);
            return false;
        }
 
        // Create adhoc task.
        $task = new \block_studentperformancepredictor\task\adhoc_train_model();
        $customdata = [
            'courseid' => $courseid,
            'datasetid' => $datasetid,
            'algorithm' => $algorithm,
            'userid' => $USER->id,
            'timequeued' => time()
        ];
        $task->set_custom_data($customdata);
 
        // Add debugging information.
        debugging('Scheduling training task for course ' . $courseid . ' with dataset ' . $datasetid, DEBUG_DEVELOPER);
 
        try {
            // Queue the task with high priority.
            \core\task\manager::queue_adhoc_task($task, true);
            debugging('Training task scheduled successfully', DEBUG_DEVELOPER);
 
            // Also create a record in block_spp_models table with trainstatus='pending'
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
            if ($modelid) {
                // Update task custom data with modelid
                $customdata['modelid'] = $modelid;
                $task->set_custom_data($customdata);
                \core\task\manager::reschedule_or_queue_adhoc_task($task, true);
 
                // Log initial training event
                self::log_training_event($modelid, 'scheduled', 'Training task scheduled');
            }
 
            return true;
        } catch (\Exception $e) {
            debugging('Error scheduling training task: ' . $e->getMessage(), DEBUG_DEVELOPER);
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
                AND " . $DB->sql_like('customdata', '?') . "
                AND nextruntime > ?";
 
        $classname = '\\block_studentperformancepredictor\\task\\adhoc_train_model';
        $customdata = '%"courseid":' . $courseid . '%';
        $count = $DB->count_records_sql($sql, [$classname, $customdata, time()]);
 
        return $count > 0;
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