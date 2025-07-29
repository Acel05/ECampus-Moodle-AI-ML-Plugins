<?php
// admin/train_model.php

// Basic Moodle config
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

use block_studentperformancepredictor\analytics\training_manager;

// Get and validate parameters
$courseid = required_param('courseid', PARAM_INT);
$datasetid = required_param('datasetid', PARAM_INT);
$algorithm = optional_param('algorithm', null, PARAM_ALPHANUMEXT);

// Set up page
$url = new moodle_url('/blocks/studentperformancepredictor/admin/train_model.php', array('courseid' => $courseid));
$PAGE->set_url($url);

// Security checks
require_login();
require_sesskey();

// Check if we're training a global model or a course-specific model
if ($courseid == 0) {
    // Global model - need site admin permission
    admin_externalpage_setup('blocksettingstudentperformancepredictor');
    require_capability('moodle/site:config', context_system::instance());

    // Check if global models are enabled
    if (!get_config('block_studentperformancepredictor', 'enableglobalmodel')) {
        \core\notification::add(
            get_string('globalmodeldisabled', 'block_studentperformancepredictor'),
            \core\notification::ERROR
        );
        redirect(new moodle_url('/blocks/studentperformancepredictor/admin/trainglobalmodel.php'));
    }

    // Verify dataset exists (for global model it can be from any course)
    if (!$DB->record_exists('block_spp_datasets', array('id' => $datasetid))) {
        \core\notification::add(
            get_string('dataset_not_found', 'block_studentperformancepredictor'),
            \core\notification::ERROR
        );
        redirect(new moodle_url('/blocks/studentperformancepredictor/admin/trainglobalmodel.php'));
    }
} else {
    // Course-specific model
    $course = get_course($courseid);
    $coursecontext = context_course::instance($courseid);
    require_capability('block/studentperformancepredictor:managemodels', $coursecontext);

    // Verify dataset exists and belongs to the course
    if (!$DB->record_exists('block_spp_datasets', array('id' => $datasetid, 'courseid' => $courseid))) {
        \core\notification::add(
            get_string('dataset_not_found', 'block_studentperformancepredictor'),
            \core\notification::ERROR
        );
        redirect($url);
    }
}

// Check for pending training - this might be stopping new tasks
if (training_manager::has_pending_training($courseid)) {
    \core\notification::add(
        get_string('training_already_scheduled', 'block_studentperformancepredictor'),
        \core\notification::WARNING
    );
    if ($courseid == 0) {
        redirect(new moodle_url('/blocks/studentperformancepredictor/admin/trainglobalmodel.php'));
    } else {
        redirect(new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', array('courseid' => $courseid)));
    }
}

// Add debugging information directly to the page output for testing
$PAGE->set_title('Training Model');
$PAGE->set_heading('Training Model');
echo $OUTPUT->header();
echo "<h3>Model Training Debug Information</h3>";
echo "<p>Attempting to schedule training task for course $courseid with dataset $datasetid using algorithm $algorithm</p>";

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Schedule training with detailed logging
try {
    echo "<p>Calling training_manager::schedule_training()...</p>";
    $result = training_manager::schedule_training($courseid, $datasetid, $algorithm);

    if ($result) {
        echo "<div class='alert alert-success'>Task scheduled successfully</div>";

        // Look for the queued task
        global $DB;
        $tasks = $DB->get_records_sql(
            "SELECT * FROM {task_adhoc} 
             WHERE classname = '\\\\block_studentperformancepredictor\\\\task\\\\adhoc_train_model' 
             ORDER BY id DESC LIMIT 1"
        );

        if (!empty($tasks)) {
            echo "<p>Found newly created task:</p>";
            foreach ($tasks as $task) {
                echo "<pre>".print_r(json_decode($task->customdata), true)."</pre>";
            }
        } else {
            echo "<div class='alert alert-warning'>No tasks found in the database!</div>";
        }

        \core\notification::success(get_string('model_training_queued_backend', 'block_studentperformancepredictor'));
    } else {
        echo "<div class='alert alert-danger'>Task scheduling failed</div>";
        throw new \moodle_exception('trainingschedulefailed', 'block_studentperformancepredictor');
    }
} catch (\Exception $e) {
    echo "<div class='alert alert-danger'>Exception: ".$e->getMessage()."</div>";
    echo "<pre>".$e->getTraceAsString()."</pre>";
    \core\notification::error($e->getMessage());
}

// Add a return button
echo '<div class="mt-3">';
if ($courseid == 0) {
    echo '<a href="' . new moodle_url('/blocks/studentperformancepredictor/admin/trainglobalmodel.php') . '" class="btn btn-primary">Return to global models</a>';
} else {
    echo '<a href="' . new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', array('courseid' => $courseid)) . '" class="btn btn-primary">Return to models</a>';
}
echo '</div>';

echo $OUTPUT->footer();
// Stop execution here to show debug information
exit;

// Normal code flow continues if debugging is disabled
// Schedule training (backend-driven orchestration)
try {
    // This will queue a training task that calls the Python backend /train endpoint
    if (training_manager::schedule_training($courseid, $datasetid, $algorithm)) {
        \core\notification::success(get_string('model_training_queued_backend', 'block_studentperformancepredictor'));
    } else {
        throw new \moodle_exception('trainingschedulefailed', 'block_studentperformancepredictor');
    }
} catch (\Exception $e) {
    \core\notification::error($e->getMessage());
}

// Redirect back to appropriate page
if ($courseid == 0) {
    redirect(new moodle_url('/blocks/studentperformancepredictor/admin/trainglobalmodel.php'));
} else {
    redirect(new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', array('courseid' => $courseid)));
}
