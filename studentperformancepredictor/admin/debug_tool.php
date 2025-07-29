<?php
// admin/debug_tool.php

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

// Security check
admin_externalpage_setup('blocksettingstudentperformancepredictor');
require_capability('moodle/site:config', context_system::instance());

// Set up page
$PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/admin/debug_tool.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('debugtool', 'block_studentperformancepredictor', '', true));
$PAGE->set_heading(get_string('debugtool', 'block_studentperformancepredictor', '', true));

// Check for actions
$action = optional_param('action', '', PARAM_ALPHA);
$courseid = optional_param('courseid', 0, PARAM_INT);
$datasetid = optional_param('datasetid', 0, PARAM_INT);
$algorithm = optional_param('algorithm', 'randomforest', PARAM_ALPHA);

$output = '';

if ($action === 'test_api') {
    // Test backend API connection
    $response = block_studentperformancepredictor_call_backend_api('health', []);
    $output .= '<h3>API Health Check Response</h3>';
    $output .= '<pre>' . json_encode($response, JSON_PRETTY_PRINT) . '</pre>';
}
else if ($action === 'list_tasks') {
    // List pending tasks
    global $DB;
    $output .= '<h3>Pending Tasks</h3>';

    $sql = "SELECT * FROM {task_adhoc} 
            WHERE classname LIKE '%studentperformancepredictor%'
            ORDER BY id DESC";
    $tasks = $DB->get_records_sql($sql);

    if (empty($tasks)) {
        $output .= '<p>No pending tasks found.</p>';
    } else {
        $table = new html_table();
        $table->head = ['ID', 'Class', 'Custom Data', 'Next Run', 'Status'];
        $table->data = [];

        foreach ($tasks as $task) {
            $customdata = json_decode($task->customdata);
            $row = [];
            $row[] = $task->id;
            $row[] = $task->classname;
            $row[] = '<pre>' . json_encode($customdata, JSON_PRETTY_PRINT) . '</pre>';
            $row[] = $task->nextruntime ? userdate($task->nextruntime) : 'Not scheduled';
            $row[] = $task->blocking ? 'Blocking' : 'Non-blocking';
            $table->data[] = $row;
        }

        $output .= html_writer::table($table);
    }
}
else if ($action === 'force_task_run') {
    // Force run a pending task
    $taskid = required_param('taskid', PARAM_INT);

    global $DB;
    $task = $DB->get_record('task_adhoc', ['id' => $taskid]);

    if ($task) {
        $output .= '<h3>Running Task ' . $taskid . '</h3>';

        try {
            // Force the task to run now
            $task = \core\task\manager::get_adhoc_task($task->id);
            \core\task\manager::adhoc_task_complete($task);

            $output .= '<div class="alert alert-success">Task completed</div>';
        } catch (\Exception $e) {
            $output .= '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
        }
    } else {
        $output .= '<div class="alert alert-warning">Task not found</div>';
    }
}
else if ($action === 'create_test_task') {
    // Create a test task manually
    if (empty($courseid) || empty($datasetid)) {
        $output .= '<div class="alert alert-warning">Missing required parameters</div>';
    } else {
        $task = new \block_studentperformancepredictor\task\adhoc_train_model();
        $task->set_custom_data([
            'courseid' => $courseid,
            'datasetid' => $datasetid,
            'algorithm' => $algorithm,
            'userid' => $USER->id,
            'timequeued' => time()
        ]);

        try {
            \core\task\manager::queue_adhoc_task($task);
            $output .= '<div class="alert alert-success">Test task created successfully</div>';
        } catch (\Exception $e) {
            $output .= '<div class="alert alert-danger">Error creating task: ' . $e->getMessage() . '</div>';
        }
    }
}
else if ($action === 'list_models') {
    // List all models
    global $DB;
    $output .= '<h3>All Models</h3>';

    $models = $DB->get_records('block_spp_models', [], 'id DESC');

    if (empty($models)) {
        $output .= '<p>No models found.</p>';
    } else {
        $table = new html_table();
        $table->head = ['ID', 'Course', 'Status', 'Algorithm', 'Accuracy', 'Created', 'Error Message'];
        $table->data = [];

        foreach ($models as $model) {
            $row = [];
            $row[] = $model->id;
            $row[] = $model->courseid;
            $row[] = $model->trainstatus;
            $row[] = $model->algorithmtype;
            $row[] = isset($model->accuracy) ? round($model->accuracy * 100, 2) . '%' : '-';
            $row[] = userdate($model->timecreated);
            $row[] = $model->errormessage ?? '-';
            $table->data[] = $row;
        }

        $output .= html_writer::table($table);
    }
}

// Output starts here
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('debugtool', 'block_studentperformancepredictor', '', true));

// Debug actions menu
echo '<div class="mb-4">';
echo '<a href="?action=test_api" class="btn btn-info mr-2">Test API Connection</a>';
echo '<a href="?action=list_tasks" class="btn btn-info mr-2">List Tasks</a>';
echo '<a href="?action=list_models" class="btn btn-info mr-2">List Models</a>';
echo '</div>';

// Create test task form
echo '<div class="card mb-4">';
echo '<div class="card-header">Create Test Training Task</div>';
echo '<div class="card-body">';
echo '<form action="?" method="get">';
echo '<input type="hidden" name="action" value="create_test_task">';

echo '<div class="form-group">';
echo '<label for="courseid">Course ID:</label>';
echo '<input type="number" class="form-control" id="courseid" name="courseid" required>';
echo '</div>';

echo '<div class="form-group">';
echo '<label for="datasetid">Dataset ID:</label>';
echo '<input type="number" class="form-control" id="datasetid" name="datasetid" required>';
echo '</div>';

echo '<div class="form-group">';
echo '<label for="algorithm">Algorithm:</label>';
echo '<select class="form-control" id="algorithm" name="algorithm">';
echo '<option value="randomforest">Random Forest</option>';
echo '<option value="logisticregression">Logistic Regression</option>';
echo '<option value="svm">SVM</option>';
echo '<option value="decisiontree">Decision Tree</option>';
echo '<option value="knn">KNN</option>';
echo '</select>';
echo '</div>';

echo '<button type="submit" class="btn btn-primary">Create Task</button>';
echo '</form>';
echo '</div>';
echo '</div>';

// Output debug information
if (!empty($output)) {
    echo '<div class="debug-output">';
    echo $output;
    echo '</div>';
}

echo $OUTPUT->footer();
