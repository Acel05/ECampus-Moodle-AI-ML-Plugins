I have a plugin Student Performance Predictor for my moodle and here is the code and the directory of the plugin.
STUDENTPERFORMANCE
├── admin
│   ├── activatemodel.php
│   ├── ajax_delete_dataset.php
│   ├── ajax_refresh_predictions.php
│   ├── ajax_toggle_model.php
│   ├── managedatasets.php
│   ├── managemodels.php
│   ├── refreshpredictions.php
│   ├── testbackend.php
│   ├── train_model.php
│   ├── upload_dataset.php
│   ├── viewdataset.php
│   ├── viewmodel.php
│   ├── viewtasks.php
├── amd
│   ├── src
│       ├── admin_interface.js
│       ├── chart_renderer.js
│       ├── prediction_viewer.js
│       ├── refresh_button.js
├── classes
│   ├── analytics
│       ├── data_preprocessor.php
│       ├── model_trainer.php
│       ├── predictor.php
│       ├── suggestion_generator.php
│       ├── training_manager.php
│   ├── event
│       ├── model_trained.php
│   ├── external
│       ├── api.php
│   ├── output
│       ├── admin_view.php
│       ├── renderer.php
│       ├── student_view.php
│       ├── teacher_view.php
│   ├── privacy
│       ├── provider.php
│   ├── task
│       ├── adhoc_prediction_refresh.php
│       ├── adhoc_train_model.php
│       ├── refresh_predictions.php
│       ├── scheduled_predictions.php
├── db
│   ├── access.php
│   ├── install.xml
│   ├── messages.php
│   ├── services.php
│   ├── tasks.php
│   ├── upgrade.php
├── lang
│   ├──  en
│       ├── block_studentperformancepredictor.php
├── models
│   ├── 
├── pix
│   ├── icon.png
├── templates
│   ├── admin_dashboard.mustache
│   ├── admin_settings.mustache
│   ├── prediction_details.mustache
│   ├── student_dashboard.mustache
│   ├── teacher_dashboard.mustache
block_studentperformancepredictor.php
generate_prediction.php
lib.php
reports.php
settings.php
student_refresh.php
styles.css
version.php

That is the directory of the plugin and below is the code for each of the file above
<?php
// blocks/studentperformancepredictor/admin/activatemodel.php

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');
require_login();

global $DB, $OUTPUT, $USER;

// Get parameters
$courseid = required_param('courseid', PARAM_INT);
$modelid = optional_param('modelid', 0, PARAM_INT);
$deactivate = optional_param('deactivate', 0, PARAM_INT);
$sesskey = required_param('sesskey', PARAM_ALPHANUMEXT);

// Validate sesskey
require_sesskey($sesskey);

// Set up context for capability check
if ($courseid > 0) {
    // Course model
    $course = get_course($courseid);
    $context = context_course::instance($courseid);
    $PAGE->set_course($course);

    // Check permissions within the specific course context
    require_capability('block/studentperformancepredictor:managemodels', $context);

    // Set up page layout for consistency
    $PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/admin/activatemodel.php', ['courseid' => $courseid]));
    $PAGE->set_context($context);
    $PAGE->set_title(get_string('activatemodel', 'block_studentperformancepredictor'));
    $PAGE->set_heading(format_string($course->fullname));
    $PAGE->set_pagelayout('admin');
} else {
    // Global model
    $context = context_system::instance();

    // Check site-level permissions for global models
    require_capability('moodle/site:config', $context);

    // Set up page layout for consistency
    $PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/admin/activatemodel.php', ['courseid' => 0]));
    $PAGE->set_context($context);
    $PAGE->set_title(get_string('activateglobalmodel', 'block_studentperformancepredictor'));
    $PAGE->set_heading(get_string('activateglobalmodel', 'block_studentperformancepredictor'));
    $PAGE->set_pagelayout('admin');
}

if ($modelid && $deactivate === 0) { // Activate a model
    $model = $DB->get_record('block_spp_models', ['id' => $modelid], '*', MUST_EXIST);

    // Ensure model belongs to this course (or is global if courseid=0)
    if ($model->courseid != $courseid) {
        \core\notification::error(get_string('modelnotincourse', 'block_studentperformancepredictor'));
        redirect(new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', ['courseid' => $courseid]));
    }

    // Begin transaction
    $transaction = $DB->start_delegated_transaction();
    try {
        // Deactivate all other models for this course or globally
        $DB->set_field('block_spp_models', 'active', 0, ['courseid' => $model->courseid]);

        // Activate selected model
        $DB->set_field('block_spp_models', 'active', 1, ['id' => $modelid]);

        // If it's a global model being activated, refresh all course predictions
        if ($courseid == 0) {
            // Get all courses
            $courses = $DB->get_records('course', ['visible' => 1], '', 'id');
            foreach ($courses as $course) {
                if ($course->id != SITEID) {
                    // Queue prediction refresh for each course
                    block_studentperformancepredictor_trigger_prediction_refresh($course->id, $USER->id);
                }
            }
        } else {
            // Just refresh predictions for this course
            block_studentperformancepredictor_trigger_prediction_refresh($courseid, $USER->id);
        }

        $transaction->allow_commit();
        \core\notification::success(get_string('modelactivated', 'block_studentperformancepredictor'));

    } catch (Exception $e) {
        $transaction->rollback();
        \core\notification::error(get_string('errorupdatingmodel', 'block_studentperformancepredictor') . ': ' . $e->getMessage());
        debugging('Model activation error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    // Redirect to appropriate page
    if ($courseid == 0) {
        redirect(new moodle_url('/blocks/studentperformancepredictor/admin/trainglobalmodel.php'));
    } else {
        redirect(new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', ['courseid' => $courseid]));
    }

} else if ($deactivate === 1) { // Deactivate all models for this course or globally
    // Begin transaction
    $transaction = $DB->start_delegated_transaction();
    try {
        $DB->set_field('block_spp_models', 'active', 0, ['courseid' => $courseid]);
        $transaction->allow_commit();
        \core\notification::success(get_string('modeldeactivated', 'block_studentperformancepredictor'));
    } catch (Exception $e) {
        $transaction->rollback();
        \core\notification::error(get_string('errorupdatingmodel', 'block_studentperformancepredictor') . ': ' . $e->getMessage());
        debugging('Model deactivation error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    // Redirect to appropriate page
    if ($courseid == 0) {
        redirect(new moodle_url('/blocks/studentperformancepredictor/admin/trainglobalmodel.php'));
    } else {
        redirect(new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', ['courseid' => $courseid]));
    }

} else {
    // Invalid access or missing parameters, redirect with error
    \core\notification::error(get_string('invalidrequest', 'block_studentperformancepredictor'));
    if ($courseid == 0) {
        redirect(new moodle_url('/blocks/studentperformancepredictor/admin/trainglobalmodel.php'));
    } else {
        redirect(new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', ['courseid' => $courseid]));
    }
}

// No HTML output required for this action page, as it always redirects.

<?php
// blocks/studentperformancepredictor/admin/ajax_delete_dataset.php

define('AJAX_SCRIPT', true);
require_once('../../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

// Set up response
$response = array(
    'success' => false,
    'message' => ''
);

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = get_string('invalidrequest', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
}

// Get parameters
$courseid = required_param('courseid', PARAM_INT);
$datasetid = required_param('datasetid', PARAM_INT);

// Validate session key
require_sesskey();

// Set up context
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check permissions
require_login($course);
require_capability('block/studentperformancepredictor:managemodels', $context);

// Get the dataset
$dataset = $DB->get_record('block_spp_datasets', array('id' => $datasetid), '*', MUST_EXIST);

// Ensure the dataset belongs to this course
if ($dataset->courseid != $courseid) {
    $response['message'] = get_string('datasetnotincourse', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
}

// Begin transaction
$transaction = $DB->start_delegated_transaction();
$success = true;
$error_message = '';

// Delete the dataset file
if (!empty($dataset->filepath) && file_exists($dataset->filepath)) {
    if (!unlink($dataset->filepath)) {
        $success = false;
        $error_message = get_string('filedeleteerror', 'block_studentperformancepredictor');
    }
}

// Delete the database record
if ($success) {
    if (!$DB->delete_records('block_spp_datasets', array('id' => $datasetid))) {
        $success = false;
        $error_message = get_string('databasedeleteerror', 'block_studentperformancepredictor');
    }
}

// Also delete all models trained from this dataset (for this course)
if ($success) {
    $models = $DB->get_records('block_spp_models', array('datasetid' => $datasetid, 'courseid' => $courseid));
    foreach ($models as $model) {
        // Optionally, delete model files from disk if stored
        if (!empty($model->modelpath) && file_exists($model->modelpath)) {
            @unlink($model->modelpath);
        }
        $DB->delete_records('block_spp_models', array('id' => $model->id));
    }
}

if ($success) {
    // Commit transaction
    $transaction->allow_commit();
    $response['success'] = true;
    $response['message'] = get_string('datasetdeleted', 'block_studentperformancepredictor');
} else {
    // Rollback transaction
    $transaction->rollback();
    $response['message'] = $error_message;
    debugging('Dataset deletion error: ' . $error_message, DEBUG_DEVELOPER);
}

// Return JSON response
echo json_encode($response);

<?php
// blocks/studentperformancepredictor/admin/ajax_refresh_predictions.php

define('AJAX_SCRIPT', true);
require_once('../../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

// Set up response
$response = [
    'success' => false,
    'message' => ''
];

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = get_string('invalidrequest', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
}

// Get parameters
$courseid = required_param('courseid', PARAM_INT);

// Validate session key
require_sesskey();

// Set up context
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check permissions
require_login($course);
require_capability('block/studentperformancepredictor:viewallpredictions', $context);

// Check if there's an active model
if (!block_studentperformancepredictor_has_active_model($courseid)) {
    $response['message'] = get_string('noactivemodel', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
}

// Trigger prediction refresh: orchestrate backend prediction for all students
try {
    // This function should call the Python backend /predict endpoint for each student in the course
    // using the active model, and update the Moodle DB with the returned predictions/probabilities.
    block_studentperformancepredictor_trigger_prediction_refresh($courseid);
    $response['success'] = true;
    $response['message'] = get_string('predictionsrefreshqueued', 'block_studentperformancepredictor');
} catch (Exception $e) {
    $response['message'] = get_string('predictionsrefresherror', 'block_studentperformancepredictor') . ': ' . $e->getMessage();
    if (function_exists('debugging')) {
        debugging('Prediction refresh error: ' . $e->getMessage(), defined('DEBUG_DEVELOPER') ? DEBUG_DEVELOPER : 0);
    }
}

// Return JSON response (always backend-driven)
echo json_encode($response);

<?php
// blocks/studentperformancepredictor/admin/ajax_toggle_model.php

define('AJAX_SCRIPT', true);
require_once('../../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

// Set up response
$response = array(
    'success' => false,
    'message' => ''
);

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = get_string('invalidrequest', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
}

// Get parameters
$courseid = required_param('courseid', PARAM_INT);
$modelid = required_param('modelid', PARAM_INT);
$active = required_param('active', PARAM_INT);

// Validate session key
require_sesskey();

// Set up context
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check permissions
require_login($course);
require_capability('block/studentperformancepredictor:managemodels', $context);

// Get the model
$model = $DB->get_record('block_spp_models', array('id' => $modelid), '*', MUST_EXIST);

// Ensure the model belongs to this course
if ($model->courseid != $courseid) {
    $response['message'] = get_string('modelnotincourse', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
}

// Begin transaction
$transaction = $DB->start_delegated_transaction();
$success = true;
$error_message = '';

// If activating this model, deactivate all other models for this course
if ($active) {
    $DB->set_field('block_spp_models', 'active', 0, array('courseid' => $courseid));
}

// Update this model's active status
$model->active = $active;
$model->timemodified = time();
$model->usermodified = $USER->id;

if (!$DB->update_record('block_spp_models', $model)) {
    $success = false;
    $error_message = get_string('errorupdatingmodel', 'block_studentperformancepredictor');
}

if ($success) {
    // If activating, trigger backend-driven prediction refresh for this course
    if ($active) {
        // This will call the Python backend /predict endpoint for all students using the new active model
        try {
            block_studentperformancepredictor_trigger_prediction_refresh($courseid);
        } catch (Exception $e) {
            // Log but do not fail activation if refresh fails
            if (function_exists('debugging')) {
                debugging('Prediction refresh error after model activation: ' . $e->getMessage(), defined('DEBUG_DEVELOPER') ? DEBUG_DEVELOPER : 0);
            }
        }
    }
    // Commit transaction
    $transaction->allow_commit();
    $response['success'] = true;
    if ($active) {
        $response['message'] = get_string('modelactivated', 'block_studentperformancepredictor');
    } else {
        $response['message'] = get_string('modeldeactivated', 'block_studentperformancepredictor');
    }
} else {
    // Rollback transaction
    $transaction->rollback();
    $response['message'] = $error_message;
    if (function_exists('debugging')) {
        debugging('Model toggle error: ' . $error_message, defined('DEBUG_DEVELOPER') ? DEBUG_DEVELOPER : 0);
    }
}

// Return JSON response
echo json_encode($response);

<?php
// blocks/studentperformancepredictor/admin/managedatasets.php

require_once('../../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

// Get parameters - use optional_param with default for global admin page
$courseid = optional_param('courseid', 0, PARAM_INT);

// If we don't have a courseid, but we're in the site admin section,
// redirect to course selection page
if ($courseid == 0) {
    // Display a list of courses to select from
    $PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/admin/managedatasets.php'));
    $PAGE->set_context(context_system::instance());
    $PAGE->set_title(get_string('managedatasets', 'block_studentperformancepredictor'));
    $PAGE->set_heading(get_string('managedatasets', 'block_studentperformancepredictor'));
    $PAGE->set_pagelayout('admin');

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('selectcourse', 'block_studentperformancepredictor'));

    // Get courses where the user has appropriate capability
    $courses = [];
    $allcourses = get_courses();

    foreach ($allcourses as $course) {
        if ($course->id == SITEID) {
            continue;
        }
        $coursecontext = context_course::instance($course->id);
        if (has_capability('block/studentperformancepredictor:managemodels', $coursecontext)) {
            $courses[$course->id] = $course;
        }
    }

    if (empty($courses)) {
        echo $OUTPUT->notification(get_string('nocoursesavailable', 'block_studentperformancepredictor'), 'error');
    } else {
        $table = new html_table();
        $table->head = [
            get_string('coursename', 'block_studentperformancepredictor'),
            get_string('actions', 'block_studentperformancepredictor')
        ];

        foreach ($courses as $course) {
            $row = [];
            $row[] = format_string($course->fullname);
            $row[] = html_writer::link(
                new moodle_url('/blocks/studentperformancepredictor/admin/managedatasets.php',
                ['courseid' => $course->id]),
                get_string('managedatasets', 'block_studentperformancepredictor'),
                ['class' => 'btn btn-primary btn-sm']
            );
            $table->data[] = $row;
        }

        echo html_writer::table($table);
    }

    echo $OUTPUT->footer();
    exit;
}

// Set up page for a specific course
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check permissions
require_login($course);
require_capability('block/studentperformancepredictor:managemodels', $context);

// Set up page layout
$PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/admin/managedatasets.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('managedatasets', 'block_studentperformancepredictor'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('standard');

// Get all datasets from all courses that the user can see
$sql = "SELECT d.*, c.fullname as coursename
        FROM {block_spp_datasets} d
        JOIN {course} c ON d.courseid = c.id";

$alldatasets = $DB->get_records_sql($sql, []);

$userdatasets = [];
foreach($alldatasets as $dataset) {
    $coursecontext = context_course::instance($dataset->courseid);
    if (has_capability('block/studentperformancepredictor:managemodels', $coursecontext)) {
        $userdatasets[] = $dataset;
    }
}

// Output starts here
echo $OUTPUT->header();

// Print heading
echo $OUTPUT->heading(get_string('managedatasets', 'block_studentperformancepredictor'));

// Initialize admin interface JavaScript
$PAGE->requires->js_call_amd('block_studentperformancepredictor/admin_interface', 'init', [$courseid]);

// Upload new dataset form
echo $OUTPUT->heading(get_string('uploadnewdataset', 'block_studentperformancepredictor'), 3);

$form = html_writer::start_tag('form', [
    'id' => 'spp-dataset-upload-form',
    'class' => 'mb-4',
    'enctype' => 'multipart/form-data',
    'method' => 'post',
    'action' => new moodle_url('/blocks/studentperformancepredictor/admin/upload_dataset.php')
]);

// Dataset name field
$form .= html_writer::start_div('form-group');
$form .= html_writer::label(get_string('datasetname', 'block_studentperformancepredictor'), 'dataset-name', true);
$form .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'dataset_name', 'id' => 'dataset-name', 'class' => 'form-control', 'required' => 'required']);
$form .= html_writer::end_div();

// Dataset description field
$form .= html_writer::start_div('form-group');
$form .= html_writer::label(get_string('datasetdescription', 'block_studentperformancepredictor'), 'dataset-description', false);
$form .= html_writer::tag('textarea', '', ['name' => 'dataset_description', 'id' => 'dataset-description', 'class' => 'form-control', 'rows' => 3]);
$form .= html_writer::end_div();

// Dataset file upload field
$form .= html_writer::start_div('form-group');
$form .= html_writer::label(get_string('datasetfile', 'block_studentperformancepredictor'), 'dataset-file', true);
$form .= html_writer::empty_tag('input', ['type' => 'file', 'name' => 'dataset_file', 'id' => 'dataset-file', 'class' => 'form-control-file', 'required' => 'required']);
$form .= html_writer::end_div();

// Dataset format field
$form .= html_writer::start_div('form-group');
$form .= html_writer::label(get_string('datasetformat', 'block_studentperformancepredictor'), 'dataset-format', true);
$form .= html_writer::start_tag('select', ['name' => 'dataset_format', 'id' => 'dataset-format', 'class' => 'form-control', 'required' => 'required']);
$form .= html_writer::tag('option', get_string('csvformat', 'block_studentperformancepredictor'), ['value' => 'csv']);
$form .= html_writer::tag('option', get_string('jsonformat', 'block_studentperformancepredictor'), ['value' => 'json']);
$form .= html_writer::end_tag('select');
$form .= html_writer::end_div();

// Hidden course ID field
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

// Submit button
$form .= html_writer::start_div('form-group');
$form .= html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('upload', 'block_studentperformancepredictor'), 'class' => 'btn btn-primary']);
$form .= html_writer::end_div();

// Status display area
$form .= html_writer::div('', 'mt-3', ['id' => 'spp-upload-status']);

$form .= html_writer::end_tag('form');

echo $form;

// Existing datasets list
if (!empty($userdatasets)) {
    echo $OUTPUT->heading(get_string('existingdatasets', 'block_studentperformancepredictor'), 3);
    // Warn that deleting a dataset will also delete all models trained from it (backend-driven)
    echo $OUTPUT->notification(get_string('datasetdeletecascade', 'block_studentperformancepredictor'), 'info');

    $table = new html_table();
    $table->head = [
        get_string('datasetname', 'block_studentperformancepredictor'),
        get_string('coursename', 'block_studentperformancepredictor'),
        get_string('datasetformat', 'block_studentperformancepredictor'),
        get_string('uploaded', 'block_studentperformancepredictor'),
        get_string('actions', 'block_studentperformancepredictor')
    ];

    foreach ($userdatasets as $dataset) {
        $row = [];
        $row[] = format_string($dataset->name);
        $row[] = format_string($dataset->coursename);
        $row[] = format_string($dataset->fileformat);
        $row[] = userdate($dataset->timecreated);

        $actions = html_writer::link(
            new moodle_url('/blocks/studentperformancepredictor/admin/viewdataset.php',
                ['id' => $dataset->id, 'courseid' => $dataset->courseid]),
            get_string('view', 'block_studentperformancepredictor'),
            ['class' => 'btn btn-sm btn-secondary']
        );

        // Delete button triggers backend-driven cascade delete (dataset + models + files)
        $actions .= ' ' . html_writer::link('#',
            get_string('delete', 'block_studentperformancepredictor'),
            [
                'class' => 'btn btn-sm btn-danger spp-delete-dataset',
                'data-dataset-id' => $dataset->id,
                'data-course-id' => $dataset->courseid,
                'title' => get_string('datasetdeletecascadetitle', 'block_studentperformancepredictor')
            ]
        );

        $row[] = $actions;
        $table->data[] = $row;
    }

    echo html_writer::table($table);
}

// At the end, add navigation buttons
echo '<div class="btn-group mt-3" role="group">';
echo html_writer::link(
    new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', ['courseid' => $courseid]),
    get_string('managemodels', 'block_studentperformancepredictor'),
    ['class' => 'btn btn-secondary']
);
echo html_writer::link(
    new moodle_url('/blocks/studentperformancepredictor/admin/refreshpredictions.php', ['courseid' => $courseid]),
    get_string('refreshpredictions', 'block_studentperformancepredictor'),
    ['class' => 'btn btn-secondary']
);
echo html_writer::link(
    new moodle_url('/my'),
    get_string('backtodashboard', 'block_studentperformancepredictor'),
    ['class' => 'btn btn-secondary']
);
echo '</div>';

// Output footer
echo $OUTPUT->footer();

<?php
// blocks/studentperformancepredictor/admin/managemodels.php

require_once('../../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');
require_once($CFG->libdir . '/tablelib.php'); // For html_table
require_once($CFG->libdir . '/weblib.php'); // For html_writer, s, get_string

// Get parameters - use optional_param with default for global admin page
$courseid = optional_param('courseid', 0, PARAM_INT);

// If we don't have a courseid, but we're in the site admin section,
// redirect to course selection page
if ($courseid == 0) {
    // Display a list of courses to select from
    $PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php'));
    $PAGE->set_context(context_system::instance());
    $PAGE->set_title(get_string('managemodels', 'block_studentperformancepredictor'));
    $PAGE->set_heading(get_string('managemodels', 'block_studentperformancepredictor'));
    $PAGE->set_pagelayout('admin');

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('selectcourse', 'block_studentperformancepredictor'));

    // Get courses where the user has appropriate capability
    $courses = [];
    $allcourses = get_courses();

    foreach ($allcourses as $course) {
        if ($course->id == SITEID) {
            continue;
        }
        $coursecontext = context_course::instance($course->id);
        if (has_capability('block/studentperformancepredictor:managemodels', $coursecontext)) {
            $courses[$course->id] = $course;
        }
    }

    if (empty($courses)) {
        echo $OUTPUT->notification(get_string('nocoursesavailable', 'block_studentperformancepredictor'), 'error');
    } else {
        $table = new html_table();
        $table->head = [
            get_string('coursename', 'block_studentperformancepredictor'),
            get_string('actions', 'block_studentperformancepredictor')
        ];

        foreach ($courses as $course) {
            $row = [];
            $row[] = format_string($course->fullname);
            $row[] = html_writer::link(
                new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php',
                ['courseid' => $course->id]),
                get_string('managemodels', 'block_studentperformancepredictor'),
                ['class' => 'btn btn-primary btn-sm']
            );
            $table->data[] = $row;
        }

        echo html_writer::table($table);
    }

    echo $OUTPUT->footer();
    exit;
}

// Set up page for a specific course
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check permissions
require_login($course);
require_capability('block/studentperformancepredictor:managemodels', $context);

// Set up page layout
$PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('managemodels', 'block_studentperformancepredictor'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('standard');

// Get active model information
$activemodel = $DB->get_record('block_spp_models', ['courseid' => $courseid, 'active' => 1]);

// Get all models for this course
$models = $DB->get_records('block_spp_models', ['courseid' => $courseid], 'timemodified DESC');

// Get all available datasets for model training from all courses
$alldatasets = $DB->get_records('block_spp_datasets', [], 'name ASC');

// Get algorithm options (must match backend-supported algorithms)
$algorithmoptions = [
    'randomforest' => get_string('algorithm_randomforest', 'block_studentperformancepredictor'),
    'svm' => get_string('algorithm_svm', 'block_studentperformancepredictor'),
    'logisticregression' => get_string('algorithm_logisticregression', 'block_studentperformancepredictor'),
    'decisiontree' => get_string('algorithm_decisiontree', 'block_studentperformancepredictor'),
    'knn' => get_string('algorithm_knn', 'block_studentperformancepredictor')
];

// Output starts here
echo $OUTPUT->header();

// Print heading
echo $OUTPUT->heading(get_string('managemodels', 'block_studentperformancepredictor'));

// Link to task monitor and refresh
echo html_writer::div(
    html_writer::link(
        new moodle_url('/blocks/studentperformancepredictor/admin/viewtasks.php', ['courseid' => $courseid]),
        get_string('viewtasks', 'block_studentperformancepredictor'),
        ['class' => 'btn btn-info mb-3 mr-2']
    ) .
    html_writer::link(
        new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', ['courseid' => $courseid]),
        get_string('refresh', 'block_studentperformancepredictor'),
        ['class' => 'btn btn-secondary mb-3']
    ),
    'mb-3'
);

// Initialize admin interface JavaScript
$PAGE->requires->js_call_amd('block_studentperformancepredictor/admin_interface', 'init', [$courseid]);

// Current active model
if (!empty($activemodel)) {
    echo $OUTPUT->heading(get_string('currentmodel', 'block_studentperformancepredictor'), 3);

    $table = new html_table();
    $table->head = [
        get_string('modelname', 'block_studentperformancepredictor'),
        get_string('algorithm', 'block_studentperformancepredictor'),
        get_string('accuracy', 'block_studentperformancepredictor'),
        get_string('created', 'block_studentperformancepredictor'),
        get_string('status', 'block_studentperformancepredictor'),
        get_string('actions', 'block_studentperformancepredictor')
    ];

    $row = [];
    $row[] = format_string($activemodel->modelname);
    $row[] = isset($algorithmoptions[$activemodel->algorithmtype]) ?
            $algorithmoptions[$activemodel->algorithmtype] : $activemodel->algorithmtype;

    if (!empty($activemodel->metrics)) {
        $metrics = json_decode($activemodel->metrics, true);
        $accuracy = isset($metrics['accuracy']) ? round($metrics['accuracy'] * 100, 2) : 0;
        $cv_accuracy = isset($metrics['cv_accuracy']) ? round($metrics['cv_accuracy'] * 100, 2) : 0;
        $overfitting_warning = isset($metrics['overfitting_warning']) ? $metrics['overfitting_warning'] : false;

        if ($overfitting_warning) {
            $row[] = html_writer::tag('span',
                $accuracy . '% (CV: ' . $cv_accuracy . '%)',
                ['class' => 'badge badge-warning', 'title' => get_string('potentialoverfitting', 'block_studentperformancepredictor')]);
        } else {
            $row[] = html_writer::tag('span',
                $accuracy . '% (CV: ' . $cv_accuracy . '%)',
                ['class' => 'badge badge-success']);
        }
    } else {
        $row[] = isset($activemodel->accuracy) ? round($activemodel->accuracy * 100, 2) . '%' : '-';
    }

    $row[] = userdate($activemodel->timemodified);
    $row[] = html_writer::tag('span', get_string('active', 'block_studentperformancepredictor'),
                            ['class' => 'badge badge-success']);

    // Actions column for the active model
    $actions = '';
    $actions .= html_writer::link(
        new moodle_url('/blocks/studentperformancepredictor/admin/viewmodel.php',
            ['id' => $activemodel->id, 'courseid' => $courseid]),
        get_string('viewdetails', 'block_studentperformancepredictor'),
        ['class' => 'btn btn-sm btn-info mr-1']
    );

    $actions .= html_writer::start_tag('form', [
        'action' => new moodle_url('/blocks/studentperformancepredictor/admin/activatemodel.php'),
        'method' => 'post',
        'style' => 'display:inline-block;margin:0;'
    ]);
    $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'deactivate', 'value' => 1]);
    $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
    $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $actions .= html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('deactivate', 'block_studentperformancepredictor'), 'class' => 'btn btn-sm btn-secondary']);
    $actions .= html_writer::end_tag('form');
    $row[] = $actions;

    $table->data[] = $row;
    echo html_writer::table($table);
}

// Train new model form
echo $OUTPUT->heading(get_string('trainnewmodel', 'block_studentperformancepredictor'), 3);

if (empty($alldatasets)) {
    echo $OUTPUT->notification(get_string('nodatasets', 'block_studentperformancepredictor'), 'warning');
    echo html_writer::link(
        new moodle_url('/blocks/studentperformancepredictor/admin/managedatasets.php', ['courseid' => $courseid]),
        get_string('uploadnewdataset', 'block_studentperformancepredictor'),
        ['class' => 'btn btn-primary']
    );
} else {
    $form = html_writer::start_tag('form', [
        'id' => 'spp-train-model-form',
        'class' => 'form-inline mb-3',
        'method' => 'post',
        'action' => new moodle_url('/blocks/studentperformancepredictor/admin/train_model.php')
    ]);

    // Dataset selection
    $form .= html_writer::start_div('form-group mr-2');
    $form .= html_writer::label(get_string('selectdataset', 'block_studentperformancepredictor'), 'datasetid', true, ['class' => 'mr-2']);
    $form .= html_writer::start_tag('select', ['id' => 'datasetid', 'name' => 'datasetid', 'class' => 'form-control', 'required' => 'required']);
    $form .= html_writer::tag('option', '', ['value' => '']);
    foreach ($alldatasets as $dataset) {
        $display_name = format_string($dataset->name);
        $form .= html_writer::tag('option', $display_name, ['value' => $dataset->id]);
    }
    $form .= html_writer::end_tag('select');
    $form .= html_writer::end_div();

    // Algorithm selection
    $form .= html_writer::start_div('form-group mr-2');
    $form .= html_writer::label(get_string('selectalgorithm', 'block_studentperformancepredictor'), 'algorithm', true, ['class' => 'mr-2']);
    $form .= html_writer::start_tag('select', ['id' => 'algorithm', 'name' => 'algorithm', 'class' => 'form-control']);
    foreach ($algorithmoptions as $value => $label) {
        $form .= html_writer::tag('option', $label, ['value' => $value]);
    }
    $form .= html_writer::end_tag('select');
    $form .= html_writer::end_div();

    // Add hidden fields for CSRF protection
    $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    // Submit button
    $form .= html_writer::start_div('form-group');
    $form .= html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('trainmodel', 'block_studentperformancepredictor'), 'class' => 'btn btn-primary']);
    $form .= html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'courseid',
        'value' => $courseid
    ]);
    $form .= html_writer::end_div();

    $form .= html_writer::end_tag('form');

    echo $form;
}

// All models list
if (!empty($models)) {
    echo $OUTPUT->heading(get_string('allmodels', 'block_studentperformancepredictor'), 3);

    $table = new html_table();
    $table->head = [
        get_string('modelname', 'block_studentperformancepredictor'),
        get_string('algorithm', 'block_studentperformancepredictor'),
        get_string('accuracy', 'block_studentperformancepredictor'),
        get_string('created', 'block_studentperformancepredictor'),
        get_string('status', 'block_studentperformancepredictor'),
        get_string('actions', 'block_studentperformancepredictor')
    ];

    foreach ($models as $model) {
        $row = [];
        $row[] = format_string($model->modelname);
        $row[] = isset($algorithmoptions[$model->algorithmtype]) ?
                  $algorithmoptions[$model->algorithmtype] : $model->algorithmtype;

        if (!empty($model->metrics)) {
            $metrics = json_decode($model->metrics, true);

            // Create a more detailed accuracy display
            $accuracy = isset($metrics['accuracy']) ? round($metrics['accuracy'] * 100, 2) : 0;
            $cv_accuracy = isset($metrics['cv_accuracy']) ? round($metrics['cv_accuracy'] * 100, 2) : 0;
            $overfitting_warning = isset($metrics['overfitting_warning']) ? $metrics['overfitting_warning'] : false;

            if ($overfitting_warning) {
                $row[] = html_writer::tag('span',
                    $accuracy . '% (CV: ' . $cv_accuracy . '%)',
                    ['class' => 'badge badge-warning', 'title' => get_string('potentialoverfitting', 'block_studentperformancepredictor')]);
            } else {
                $row[] = html_writer::tag('span',
                    $accuracy . '% (CV: ' . $cv_accuracy . '%)',
                    ['class' => 'badge badge-success']);
            }
        } else {
            $row[] = isset($model->accuracy) ? round($model->accuracy * 100, 2) . '%' : '-';
        }

        $row[] = userdate($model->timemodified);

        // Show error message if model failed
        $statuscell = '';
        $trainstatus = isset($model->trainstatus) ? $model->trainstatus : 'complete';
        if ($trainstatus === 'failed') {
            $icon = html_writer::span('&#9888;', 'mr-1 text-warning', ['title' => 'Error']); // ⚠️
            $errordiv = !empty($model->errormessage)
                ? html_writer::div($icon . html_writer::tag('strong', 'Error: ') . s($model->errormessage), 'text-danger bg-warning-light p-2 rounded small mt-1', ['style' => 'border:1px solid #f5c6cb; background-color:#fff3cd;'])
                : '';
            $statuscell = html_writer::tag('span', get_string('inactive', 'block_studentperformancepredictor'), ['class' => 'badge badge-danger']) . $errordiv;
        } else if ($model->active) {
            $statuscell = html_writer::tag('span', get_string('active', 'block_studentperformancepredictor'), ['class' => 'badge badge-success']);
        } else {
            $statuscell = html_writer::tag('span', get_string('inactive', 'block_studentperformancepredictor'), ['class' => 'badge badge-secondary']);
        }
        $row[] = $statuscell;

        // Find where you create action buttons for each model
        $actions = '';

        // Add a "View Details" button
        $actions .= html_writer::link(
            new moodle_url('/blocks/studentperformancepredictor/admin/viewmodel.php',
                ['id' => $model->id, 'courseid' => $courseid]),
            get_string('viewdetails', 'block_studentperformancepredictor'),
            ['class' => 'btn btn-sm btn-info mr-1']
        );

        if ($model->active) {
            // Add a deactivate button for the active model
            $actions .= html_writer::start_tag('form', [
                'action' => new moodle_url('/blocks/studentperformancepredictor/admin/activatemodel.php'),
                'method' => 'post',
                'style' => 'display:inline-block;margin:0;'
            ]);
            $actions .= html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'deactivate',
                'value' => 1
            ]);
            $actions .= html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'courseid',
                'value' => $courseid
            ]);
            $actions .= html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'sesskey',
                'value' => sesskey()
            ]);
            $actions .= html_writer::empty_tag('input', [
                'type' => 'submit',
                'value' => get_string('deactivate', 'block_studentperformancepredictor'),
                'class' => 'btn btn-sm btn-secondary'
            ]);
            $actions .= html_writer::end_tag('form');
        } else {
            // Use a form for activation to POST to activatemodel.php
            $actions .= html_writer::start_tag('form', [
                'action' => new moodle_url('/blocks/studentperformancepredictor/admin/activatemodel.php'),
                'method' => 'post',
                'style' => 'display:inline-block;margin:0;'
            ]);
            $actions .= html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'modelid',
                'value' => $model->id
            ]);
            $actions .= html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'courseid',
                'value' => $courseid
            ]);
            $actions .= html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'sesskey',
                'value' => sesskey()
            ]);
            $actions .= html_writer::empty_tag('input', [
                'type' => 'submit',
                'value' => get_string('activate', 'block_studentperformancepredictor'),
                'class' => 'btn btn-sm btn-primary'
            ]);
            $actions .= html_writer::end_tag('form');
        }

        $row[] = $actions;
        $table->data[] = $row;
    }

    echo html_writer::table($table);
}

// At the end, add navigation buttons
echo '<div class="btn-group mt-3" role="group">';
echo html_writer::link(
    new moodle_url('/blocks/studentperformancepredictor/admin/managedatasets.php', ['courseid' => $courseid]),
    get_string('managedatasets', 'block_studentperformancepredictor'),
    ['class' => 'btn btn-secondary']
);
echo html_writer::link(
    new moodle_url('/blocks/studentperformancepredictor/admin/refreshpredictions.php', ['courseid' => $courseid]),
    get_string('refreshpredictions', 'block_studentperformancepredictor'),
    ['class' => 'btn btn-secondary']
);
echo html_writer::link(
    new moodle_url('/my'),
    get_string('backtodashboard', 'block_studentperformancepredictor'),
    ['class' => 'btn btn-secondary']
);
echo '</div>';

// Output footer
echo $OUTPUT->footer();

<?php
// blocks/studentperformancepredictor/admin/refreshpredictions.php

require_once('../../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

// Get parameters.
$courseid = required_param('courseid', PARAM_INT);

// Set up page.
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check permissions.
require_login($course);
require_capability('block/studentperformancepredictor:viewallpredictions', $context);

// Set up page layout.
$PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/admin/refreshpredictions.php', array('courseid' => $courseid)));
$PAGE->set_context($context);
$PAGE->set_title(get_string('refreshpredictions', 'block_studentperformancepredictor'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('standard');

// Check if there's an active model.
$hasactivemodel = block_studentperformancepredictor_has_active_model($courseid);

// Output starts here.
echo $OUTPUT->header();

// Print heading.
echo $OUTPUT->heading(get_string('refreshpredictions', 'block_studentperformancepredictor'));

if (!$hasactivemodel) {
    echo $OUTPUT->notification(get_string('noactivemodel', 'block_studentperformancepredictor'), 'warning');
    echo html_writer::link(
        new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', array('courseid' => $courseid)),
        get_string('managemodels', 'block_studentperformancepredictor'),
        array('class' => 'btn btn-primary')
    );
} else {
    // Get statistics on current predictions.
    $stats = block_studentperformancepredictor_get_course_risk_stats($courseid);

    echo html_writer::start_div('spp-prediction-stats mb-4');
    echo html_writer::start_div('card');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('currentpredictionstats', 'block_studentperformancepredictor'), array('class' => 'card-title'));
    echo html_writer::start_tag('ul', array('class' => 'list-group list-group-flush'));
    echo html_writer::tag('li', get_string('totalstudents', 'block_studentperformancepredictor') . ': ' . $stats->total, array('class' => 'list-group-item'));
    echo html_writer::tag('li', get_string('highrisk_label', 'block_studentperformancepredictor') . ': ' . $stats->highrisk .
        ' (' . round(($stats->highrisk / max(1, $stats->total)) * 100) . '%)', array('class' => 'list-group-item spp-risk-high'));
    echo html_writer::tag('li', get_string('mediumrisk_label', 'block_studentperformancepredictor') . ': ' . $stats->mediumrisk .
        ' (' . round(($stats->mediumrisk / max(1, $stats->total)) * 100) . '%)', array('class' => 'list-group-item spp-risk-medium'));
    echo html_writer::tag('li', get_string('lowrisk_label', 'block_studentperformancepredictor') . ': ' . $stats->lowrisk .
        ' (' . round(($stats->lowrisk / max(1, $stats->total)) * 100) . '%)', array('class' => 'list-group-item spp-risk-low'));
    echo html_writer::end_tag('ul');
    echo html_writer::end_div(); // card-body
    echo html_writer::end_div(); // card
    echo html_writer::end_div(); // spp-prediction-stats

    // Refresh button and explanation.
    echo html_writer::start_div('spp-refresh-container mb-4');
    echo html_writer::tag('p', get_string('refreshexplanation', 'block_studentperformancepredictor'));
    echo html_writer::start_div('spp-refresh-action');
    echo html_writer::div(
        html_writer::tag('button', get_string('refreshallpredictions', 'block_studentperformancepredictor'),
                        array('class' => 'btn btn-primary spp-refresh-predictions',
                              'id' => 'refresh-predictions-btn', // Added ID for easier selection
                              'data-course-id' => $courseid)),
        'spp-refresh-action'
    );
    echo html_writer::end_div();
    echo html_writer::end_div();

    // Show the last refresh time if available
    $lastrefreshtime = get_config('block_studentperformancepredictor', 'lastrefresh_' . $courseid);
    if (!empty($lastrefreshtime)) {
        echo html_writer::start_div('spp-last-refresh mb-4');
        echo html_writer::tag('p', get_string('lastrefreshtime', 'block_studentperformancepredictor', userdate($lastrefreshtime)),
            array('class' => 'text-muted'));
        echo html_writer::end_div();
    }
}

// Navigation buttons
echo '<div class="btn-group mt-3" role="group">';
echo html_writer::link(
    new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', ['courseid' => $courseid]),
    get_string('managemodels', 'block_studentperformancepredictor'),
    ['class' => 'btn btn-secondary']
);
echo html_writer::link(
    new moodle_url('/blocks/studentperformancepredictor/admin/managedatasets.php', ['courseid' => $courseid]),
    get_string('managedatasets', 'block_studentperformancepredictor'),
    ['class' => 'btn btn-secondary']
);
echo html_writer::link(
    new moodle_url('/my'),
    get_string('backtodashboard', 'block_studentperformancepredictor'),
    ['class' => 'btn btn-secondary']
);
echo '</div>';

// Output footer.
echo $OUTPUT->footer();

// Inline JavaScript to handle the button click
?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var refreshButton = document.getElementById('refresh-predictions-btn');
        if (refreshButton) {
            refreshButton.addEventListener('click', function(e) {
                e.preventDefault();

                if (confirm("<?php echo get_string('refreshconfirmation', 'block_studentperformancepredictor'); ?>")) {
                    var courseId = this.getAttribute('data-course-id');
                    var originalText = this.textContent;
                    this.disabled = true;
                    this.textContent = "<?php echo get_string('refreshing', 'block_studentperformancepredictor'); ?>...";

                    // Using Moodle's built-in fetch for AJAX
                    require(['core/ajax'], function(ajax) {
                        var promises = ajax.call([{
                            methodname: 'block_studentperformancepredictor_refresh_predictions',
                            args: { courseid: courseId },
                            done: function(response) {
                                if (response.status) {
                                    alert("<?php echo get_string('predictionsrefreshqueued', 'block_studentperformancepredictor'); ?>");
                                    // Optionally, you can reload the page to see the updated status
                                    window.location.reload();
                                } else {
                                    alert("<?php echo get_string('predictionsrefresherror', 'block_studentperformancepredictor'); ?>: " + response.message);
                                    refreshButton.disabled = false;
                                    refreshButton.textContent = originalText;
                                }
                            },
                            fail: function(ex) {
                                alert("<?php echo get_string('refresherror', 'block_studentperformancepredictor'); ?>: " + ex);
                                refreshButton.disabled = false;
                                refreshButton.textContent = originalText;
                            }
                        }]);
                    });
                }
            });
        }
    });
</script>

<?php
// blocks/studentperformancepredictor/admin/testbackend.php

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Check admin permissions
admin_externalpage_setup('blocksettingstudentperformancepredictor');

// Page setup
$PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/admin/testbackend.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('testbackend', 'block_studentperformancepredictor'));
$PAGE->set_heading(get_string('testbackend', 'block_studentperformancepredictor'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('testbackend', 'block_studentperformancepredictor'));

// Get the API URL and key from settings
$apiurl = get_config('block_studentperformancepredictor', 'python_api_url');
if (empty($apiurl)) {
    $apiurl = 'http://localhost:5000';
}

$apikey = get_config('block_studentperformancepredictor', 'python_api_key');
if (empty($apikey)) {
    $apikey = 'changeme';
}

// Make a request to the health check endpoint
$healthurl = rtrim($apiurl, '/') . '/health';
$curl = new curl();
$options = [
    'CURLOPT_TIMEOUT' => 10,
    'CURLOPT_RETURNTRANSFER' => true,
    'CURLOPT_HTTPHEADER' => [
        'Content-Type: application/json',
        'X-API-Key: ' . $apikey
    ],
    // Add these for Windows XAMPP compatibility
    'CURLOPT_SSL_VERIFYHOST' => 0,
    'CURLOPT_SSL_VERIFYPEER' => 0
];

echo html_writer::tag('h4', get_string('testingconnection', 'block_studentperformancepredictor'));
echo html_writer::tag('p', get_string('testingbackendurl', 'block_studentperformancepredictor', $healthurl));

try {
    $response = $curl->get($healthurl, [], $options);
    $httpcode = $curl->get_info()['http_code'] ?? 0;

    if ($httpcode === 200) {
        // Success - show green alert
        echo $OUTPUT->notification(
            get_string('backendconnectionsuccess', 'block_studentperformancepredictor'),
            'success'
        );

        // Show response details
        try {
            $data = json_decode($response, true);
            if (is_array($data)) {
                echo html_writer::tag('h5', get_string('backenddetails', 'block_studentperformancepredictor'));
                echo html_writer::start_tag('pre', ['class' => 'bg-light p-3 rounded']);
                echo html_writer::tag('code', s(json_encode($data, JSON_PRETTY_PRINT)));
                echo html_writer::end_tag('pre');
            } else {
                echo html_writer::tag('p', s($response));
            }
        } catch (Exception $e) {
            echo html_writer::tag('p', s($response));
        }
    } else {
        // Connection failed - show red alert
        echo $OUTPUT->notification(
            get_string('backendconnectionfailed', 'block_studentperformancepredictor', $httpcode),
            'error'
        );

        // Show response
        echo html_writer::tag('h5', get_string('errormessage', 'block_studentperformancepredictor'));
        echo html_writer::start_tag('pre', ['class' => 'bg-light p-3 rounded text-danger']);
        echo html_writer::tag('code', s($response));
        echo html_writer::end_tag('pre');
    }
} catch (Exception $e) {
    // Connection error - show red alert
    echo $OUTPUT->notification(
        get_string('backendconnectionerror', 'block_studentperformancepredictor', $e->getMessage()),
        'error'
    );
}

// Backend troubleshooting guide with XAMPP-specific advice
echo html_writer::tag('h4', get_string('troubleshootingguide', 'block_studentperformancepredictor'));
echo html_writer::start_tag('ol');
echo html_writer::tag('li', get_string('troubleshoot1', 'block_studentperformancepredictor'));
echo html_writer::tag('li', get_string('troubleshoot2', 'block_studentperformancepredictor'));
echo html_writer::tag('li', get_string('troubleshoot3', 'block_studentperformancepredictor'));
echo html_writer::tag('li', get_string('troubleshoot4', 'block_studentperformancepredictor'));
echo html_writer::tag('li', get_string('troubleshoot5', 'block_studentperformancepredictor'));
// Add XAMPP-specific troubleshooting
echo html_writer::tag('li', 'For XAMPP: Make sure the Python environment is accessible. Try running the backend script directly from command prompt.');
echo html_writer::tag('li', 'For XAMPP: Check Windows Firewall to ensure port 5000 is allowed for both inbound and outbound connections.');
echo html_writer::end_tag('ol');

// Provide command to start the backend
echo html_writer::tag('h5', get_string('startbackendcommand', 'block_studentperformancepredictor'));
echo html_writer::start_tag('pre', ['class' => 'bg-dark text-light p-3 rounded']);
echo html_writer::tag('code', 'cd ' . $CFG->dirroot . '/blocks/studentperformancepredictor' . PHP_EOL . 'python -m uvicorn ml_backend:app --host 0.0.0.0 --port 8000');
echo html_writer::end_tag('pre');

// Back button
echo html_writer::div(
    $OUTPUT->single_button(
        new moodle_url('/admin/settings.php', ['section' => 'blocksettingstudentperformancepredictor']),
        get_string('backsettings', 'block_studentperformancepredictor'),
        'get'
    ),
    'mt-4'
);

echo $OUTPUT->footer();

<?php
// blocks/studentperformancepredictor/admin/train_model.php

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

// Define the redirect URL
$redirecturl = new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', ['courseid' => $courseid]);

// Security checks first
require_login();
require_sesskey();

// Set up context for permission checks
if ($courseid > 0) {
    $coursecontext = context_course::instance($courseid);
    require_capability('block/studentperformancepredictor:managemodels', $coursecontext);
} else {
    // This case is for global models, but we keep the permission check for robustness.
    require_capability('moodle/site:config', context_system::instance());
}

try {
    // Verify dataset exists (it can be from any course)
    if (!$DB->record_exists('block_spp_datasets', ['id' => $datasetid])) {
        throw new \moodle_exception('dataset_not_found', 'block_studentperformancepredictor');
    }

    // Check for pending training to avoid duplicate tasks
    if (training_manager::has_pending_training($courseid)) {
        throw new \moodle_exception('training_already_scheduled', 'block_studentperformancepredictor');
    }

    // Schedule the training task
    $success = training_manager::schedule_training($courseid, $datasetid, $algorithm);

    if (!$success) {
        throw new \moodle_exception('trainingschedulefailed', 'block_studentperformancepredictor');
    }

    // Success notification can be helpful, but it's optional. The main goal is the redirect.
    \core\notification::success(get_string('model_training_queued_backend', 'block_studentperformancepredictor'));

} catch (\Exception $e) {
    // If there's an error, add it as a notification and then redirect.
    \core\notification::error($e->getMessage());
}

// Redirect back to the manage models page in all cases (success or failure).
redirect($redirecturl);

<?php
// blocks/studentperformancepredictor/admin/upload_dataset.php

require_once('../../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->libdir . '/accesslib.php');
require_once($CFG->libdir . '/filelib.php');

// Get parameters
$courseid = required_param('courseid', PARAM_INT);
$datasetname = required_param('dataset_name', PARAM_TEXT);
$datasetformat = required_param('dataset_format', PARAM_ALPHA);
$datasetdesc = optional_param('dataset_description', '', PARAM_TEXT);

// Set up redirect URL
$redirecturl = new moodle_url('/blocks/studentperformancepredictor/admin/managedatasets.php', ['courseid' => $courseid]);

// Validate session key
require_sesskey();

// Set up context
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check permissions
require_login($course);
require_capability('block/studentperformancepredictor:managemodels', $context);

// Verify file upload
if (!isset($_FILES['dataset_file']) || empty($_FILES['dataset_file']['name'])) {
    \core\notification::error(get_string('nofileuploaded', 'block_studentperformancepredictor'));
    redirect($redirecturl);
}

$file = $_FILES['dataset_file'];

// Check for upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errormessage = get_string('fileuploaderror', 'block_studentperformancepredictor');
    switch ($file['error']) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            $errormessage = get_string('filetoolarge', 'block_studentperformancepredictor');
            break;
        case UPLOAD_ERR_PARTIAL:
            $errormessage = get_string('filepartialuploaded', 'block_studentperformancepredictor');
            break;
        case UPLOAD_ERR_NO_FILE:
            $errormessage = get_string('nofileuploaded', 'block_studentperformancepredictor');
            break;
    }
    \core\notification::error($errormessage);
    redirect($redirecturl);
}

// Check file extension
$filename = $file['name'];
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

if (($datasetformat === 'csv' && $extension !== 'csv') || ($datasetformat === 'json' && $extension !== 'json')) {
    \core\notification::error(get_string('invalidfileextension', 'block_studentperformancepredictor'));
    redirect($redirecturl);
}

// Create dataset directory
try {
    $datasetdir = block_studentperformancepredictor_ensure_dataset_directory($courseid);
} catch (Exception $e) {
    \core\notification::error($e->getMessage());
    redirect($redirecturl);
}

// Store the file with a unique name
$newfilename = $courseid . '_' . time() . '_' . clean_filename($filename);
$filepath = $datasetdir . '/' . $newfilename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    \core\notification::error(get_string('fileuploadfailed', 'block_studentperformancepredictor'));
    redirect($redirecturl);
}

// Extract column headers
$columns = array();
if ($datasetformat === 'csv') {
    $handle = fopen($filepath, 'r');
    if ($handle !== false) {
        $headers = fgetcsv($handle);
        if ($headers) {
            foreach ($headers as $header) {
                $columns[] = $header;
            }
        }
        fclose($handle);
    }
} else if ($datasetformat === 'json') {
    $content = file_get_contents($filepath);
    $jsonData = json_decode($content, true);
    if (is_array($jsonData) && !empty($jsonData)) {
        $firstRow = reset($jsonData);
        if (is_array($firstRow)) {
            $columns = array_keys($firstRow);
        }
    }
}

// Save dataset record to database
$dataset = new stdClass();
$dataset->courseid = $courseid;
$dataset->name = $datasetname;
$dataset->description = $datasetdesc;
$dataset->filepath = $filepath;
$dataset->fileformat = $datasetformat;
$dataset->columns = json_encode($columns);
$dataset->timecreated = time();
$dataset->timemodified = time();
$dataset->usermodified = $USER->id;

try {
    $datasetid = $DB->insert_record('block_spp_datasets', $dataset);
    \core\notification::success(get_string('datasetsaved_backend', 'block_studentperformancepredictor'));
} catch (Exception $e) {
    \core\notification::error(get_string('datasetsaveerror', 'block_studentperformancepredictor') . ': ' . $e->getMessage());
}

// Redirect back to the manage datasets page
redirect($redirecturl);

<?php
// blocks/studentperformancepredictor/admin/viewdataset.php

require_once('../../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

// Get parameters
$id = required_param('id', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);

// Set up page
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check permissions
require_login($course);
require_capability('block/studentperformancepredictor:managemodels', $context);

// Get dataset
$dataset = $DB->get_record('block_spp_datasets', array('id' => $id, 'courseid' => $courseid), '*', MUST_EXIST);

// Set up page layout
$PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/admin/viewdataset.php', array('id' => $id, 'courseid' => $courseid)));
$PAGE->set_context($context);
$PAGE->set_title(get_string('datasetname', 'block_studentperformancepredictor') . ': ' . $dataset->name);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('standard');

// Output starts here
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('datasetname', 'block_studentperformancepredictor') . ': ' . format_string($dataset->name));

// Display dataset details
$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = array(
    get_string('datasetproperty', 'block_studentperformancepredictor'),
    get_string('datasetvalue', 'block_studentperformancepredictor')
);
$table->data = array();

$table->data[] = array(get_string('datasetname', 'block_studentperformancepredictor'), format_string($dataset->name));
$table->data[] = array(get_string('datasetdescription', 'block_studentperformancepredictor'), $dataset->description ? format_text($dataset->description) : '');
$table->data[] = array(get_string('datasetformat', 'block_studentperformancepredictor'), $dataset->fileformat);
$table->data[] = array(get_string('uploaded', 'block_studentperformancepredictor'), userdate($dataset->timecreated));

// Display columns
$columns = json_decode($dataset->columns);
if ($columns && is_array($columns) && count($columns) > 0) {
    $columnlist = html_writer::start_tag('ul');
    foreach ($columns as $column) {
        $columnlist .= html_writer::tag('li', s($column));
    }
    $columnlist .= html_writer::end_tag('ul');
    $table->data[] = array(get_string('columns', 'block_studentperformancepredictor'), $columnlist);
}

echo html_writer::table($table);

// Add a "Back" button
echo ' ';
echo html_writer::link(
    new moodle_url('/blocks/studentperformancepredictor/admin/managedatasets.php', 
        array('courseid' => $courseid)),
    get_string('back', 'core'),
    array('class' => 'btn btn-secondary')
);
echo html_writer::end_div();

// Output footer
echo $OUTPUT->footer();

<?php
// blocks/studentperformancepredictor/admin/viewmodel.php

require_once('../../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

// Get parameters
$id = required_param('id', PARAM_INT); // Model ID
$courseid = required_param('courseid', PARAM_INT);

// Set up context
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check permissions
require_login($course);
require_capability('block/studentperformancepredictor:managemodels', $context);

// Get model
$model = $DB->get_record('block_spp_models', ['id' => $id], '*', MUST_EXIST);

// Set up page
$PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/admin/viewmodel.php', ['id' => $id, 'courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('modeldetails', 'block_studentperformancepredictor'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('standard');

// Algorithm options for display
$algorithmoptions = [
    'randomforest' => get_string('algorithm_randomforest', 'block_studentperformancepredictor'),
    'logisticregression' => get_string('algorithm_logisticregression', 'block_studentperformancepredictor'),
    'svm' => get_string('algorithm_svm', 'block_studentperformancepredictor'),
    'decisiontree' => get_string('algorithm_decisiontree', 'block_studentperformancepredictor'),
    'knn' => get_string('algorithm_knn', 'block_studentperformancepredictor')
];

// Output starts here
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modeldetails', 'block_studentperformancepredictor') . ': ' . format_string($model->modelname));

// Model basic info
$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [
    get_string('property', 'block_studentperformancepredictor'),
    get_string('value', 'block_studentperformancepredictor')
];
$table->data = [];

$table->data[] = [get_string('modelname', 'block_studentperformancepredictor'), format_string($model->modelname)];
$table->data[] = [get_string('algorithm', 'block_studentperformancepredictor'), isset($algorithmoptions[$model->algorithmtype]) ?
    $algorithmoptions[$model->algorithmtype] : $model->algorithmtype];
$table->data[] = [get_string('created', 'block_studentperformancepredictor'), userdate($model->timecreated)];
$table->data[] = [get_string('status', 'block_studentperformancepredictor'),
    $model->active ? get_string('active', 'block_studentperformancepredictor') : get_string('inactive', 'block_studentperformancepredictor')];
$table->data[] = [get_string('trainstatus', 'block_studentperformancepredictor'), ucfirst($model->trainstatus)];

echo html_writer::table($table);

// Display all available metrics
if (!empty($model->metrics)) {
    $metrics = json_decode($model->metrics, true);

    echo html_writer::start_div('model-metrics card p-3 mt-4');
    echo html_writer::tag('h4', get_string('modelmetrics', 'block_studentperformancepredictor'));

    echo html_writer::start_tag('ul', ['class' => 'list-group']);

    // Accuracy with cross-validation
    $acc_data = new stdClass();
    $acc_data->accuracy = round(($metrics['accuracy'] ?? 0) * 100, 2);
    $acc_data->cv_accuracy = round(($metrics['cv_accuracy'] ?? 0) * 100, 2);
    echo html_writer::tag('li',
        get_string('modelaccuracydetail', 'block_studentperformancepredictor', $acc_data),
        ['class' => 'list-group-item' . (($metrics['overfitting_warning'] ?? false) ? ' list-group-item-warning' : '')]);

    // Other metrics
    if (isset($metrics['precision'])) {
        echo html_writer::tag('li', get_string('metrics_precision', 'block_studentperformancepredictor') . ': ' .
            round($metrics['precision'] * 100, 2) . '%', ['class' => 'list-group-item']);
    }
    if (isset($metrics['recall'])) {
        echo html_writer::tag('li', get_string('metrics_recall', 'block_studentperformancepredictor') . ': ' .
            round($metrics['recall'] * 100, 2) . '%', ['class' => 'list-group-item']);
    }
    if (isset($metrics['f1'])) {
        echo html_writer::tag('li', get_string('metrics_f1', 'block_studentperformancepredictor') . ': ' .
            round($metrics['f1'] * 100, 2) . '%', ['class' => 'list-group-item']);
    }
    if (isset($metrics['roc_auc'])) {
        echo html_writer::tag('li', get_string('metrics_roc_auc', 'block_studentperformancepredictor') . ': ' .
            round($metrics['roc_auc'], 3), ['class' => 'list-group-item']);
    }
    if (isset($metrics['overfitting_ratio'])) {
        echo html_writer::tag('li', get_string('metrics_overfitting_ratio', 'block_studentperformancepredictor') . ': ' .
            round($metrics['overfitting_ratio'], 2),
            ['class' => 'list-group-item' . ($metrics['overfitting_warning'] ? ' list-group-item-warning' : '')]);
    }

    echo html_writer::end_tag('ul');

    // Display feature importance if available
    if (isset($metrics['top_features']) && !empty($metrics['top_features'])) {
        echo html_writer::tag('h5', get_string('topfeatures', 'block_studentperformancepredictor'), ['class' => 'mt-4']);

        echo html_writer::start_tag('ul', ['class' => 'list-group']);
        foreach ($metrics['top_features'] as $feature => $importance) {
            echo html_writer::tag('li',
                $feature . ': ' . round($importance * 100, 2) . '%',
                ['class' => 'list-group-item']);
        }
        echo html_writer::end_tag('ul');
    }

    echo html_writer::end_div();
}

// Show error message if model training failed
if ($model->trainstatus == 'failed' && !empty($model->errormessage)) {
    echo $OUTPUT->notification($model->errormessage, 'error');
}

// Actions
echo html_writer::start_div('mt-4');
if (!$model->active) {
    echo html_writer::link(
        new moodle_url('/blocks/studentperformancepredictor/admin/activatemodel.php',
            ['modelid' => $model->id, 'courseid' => $courseid, 'sesskey' => sesskey()]),
        get_string('activate', 'block_studentperformancepredictor'),
        ['class' => 'btn btn-primary']
    );
} else {
    echo html_writer::link(
        new moodle_url('/blocks/studentperformancepredictor/admin/activatemodel.php',
            ['deactivate' => 1, 'courseid' => $courseid, 'sesskey' => sesskey()]),
        get_string('deactivate', 'block_studentperformancepredictor'),
        ['class' => 'btn btn-secondary']
    );
}

echo ' ';
echo html_writer::link(
    new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', ['courseid' => $courseid]),
    get_string('back', 'core'),
    ['class' => 'btn btn-secondary']
);
echo html_writer::end_div();

// Footer
echo $OUTPUT->footer();

<?php
// blocks/studentperformancepredictor/admin/viewtasks.php

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/tablelib.php'); // For html_table
require_once($CFG->dirroot . '/lib/accesslib.php'); // For require_login, require_capability
require_once($CFG->dirroot . '/lib/moodlelib.php'); // For userdate, s
require_once($CFG->libdir . '/weblib.php'); // For html_writer, s, get_string

require_login();
$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$modelid = optional_param('modelid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

// Default to system context if no course specified
if ($courseid) {
    $context = context_course::instance($courseid);
    $course = get_course($courseid);
    $PAGE->set_course($course);
    $pageheading = format_string($course->fullname);
} else {
    $context = context_system::instance();
    $pageheading = get_string('viewtasks', 'block_studentperformancepredictor');
}

require_capability('block/studentperformancepredictor:managemodels', $context);

// Set up page
$PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/admin/viewtasks.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('viewtasks', 'block_studentperformancepredictor'));
$PAGE->set_heading($pageheading);
$PAGE->set_pagelayout('admin');

// Process model deletion action
if ($action === 'delete' && $modelid) {
    require_sesskey();

    // Check if the model exists
    $model = $DB->get_record('block_spp_models', ['id' => $modelid], '*', MUST_EXIST);

    // Verify the model belongs to this course if courseid is specified
    if ($courseid > 0 && $model->courseid != $courseid) {
        throw new moodle_exception('invalidaccess', 'error');
    }

    // Handle confirmation
    if ($confirm) {
        // Delete related records
        $DB->delete_records('block_spp_predictions', ['modelid' => $modelid]);

        // Get any suggestions related to predictions from this model
        $sql = "DELETE FROM {block_spp_suggestions} 
                WHERE predictionid IN (
                    SELECT id FROM {block_spp_predictions} WHERE modelid = :modelid
                )";
        $DB->execute($sql, ['modelid' => $modelid]);

        // Delete training logs
        $DB->delete_records('block_spp_training_log', ['modelid' => $modelid]);

        // If there's a model file, delete it
        if (!empty($model->modelpath) && file_exists($model->modelpath)) {
            unlink($model->modelpath);
        }

        // Delete the model record
        $DB->delete_records('block_spp_models', ['id' => $modelid]);

        // Show success message
        \core\notification::success(get_string('modeldeleted', 'block_studentperformancepredictor'));

        // Redirect back to the task monitor
        redirect($PAGE->url);
    } else {
        // Show confirmation dialog
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('deletemodel', 'block_studentperformancepredictor'));

        echo $OUTPUT->confirm(
            get_string('confirmmodeldelete', 'block_studentperformancepredictor', $model->modelname),
            new moodle_url($PAGE->url, [
                'action' => 'delete', 
                'modelid' => $modelid, 
                'confirm' => 1,
                'sesskey' => sesskey()
            ]),
            $PAGE->url
        );

        echo $OUTPUT->footer();
        exit;
    }
}

// Process purge failed models action
if ($action === 'purgefailed' && $courseid) {
    require_sesskey();

    if ($confirm) {
        // Find failed models
        $params = ['courseid' => $courseid, 'status' => 'failed'];
        $failedmodels = $DB->get_records('block_spp_models', ['courseid' => $courseid, 'trainstatus' => 'failed']);

        $count = 0;
        foreach ($failedmodels as $model) {
            // Delete related records
            $DB->delete_records('block_spp_predictions', ['modelid' => $model->id]);

            // Delete training logs
            $DB->delete_records('block_spp_training_log', ['modelid' => $model->id]);

            // If there's a model file, delete it
            if (!empty($model->modelpath) && file_exists($model->modelpath)) {
                unlink($model->modelpath);
            }

            // Delete the model record
            $DB->delete_records('block_spp_models', ['id' => $model->id]);
            $count++;
        }

        // Show success message
        if ($count > 0) {
            \core\notification::success(get_string('failedmodelsdeleted', 'block_studentperformancepredictor'));
        } else {
            \core\notification::info(get_string('nofailedmodels', 'block_studentperformancepredictor'));
        }

        // Redirect back to the task monitor
        redirect($PAGE->url);
    } else {
        // Show confirmation dialog
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('purgefailedmodels', 'block_studentperformancepredictor'));

        echo $OUTPUT->confirm(
            get_string('confirmpurgefailed', 'block_studentperformancepredictor'),
            new moodle_url($PAGE->url, [
                'action' => 'purgefailed', 
                'courseid' => $courseid, 
                'confirm' => 1,
                'sesskey' => sesskey()
            ]),
            $PAGE->url
        );

        echo $OUTPUT->footer();
        exit;
    }
}

// Get all train_model tasks (adhoc and scheduled)
global $DB;

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('viewtasks', 'block_studentperformancepredictor'));

// First, let's display models currently in training
echo $OUTPUT->heading(get_string('modelscurrentlyintraining', 'block_studentperformancepredictor'), 3);

// Get training models
$sql = "SELECT m.*, d.name as datasetname 
        FROM {block_spp_models} m
        LEFT JOIN {block_spp_datasets} d ON m.datasetid = d.id
        WHERE m.trainstatus IN ('pending', 'training')";

if ($courseid > 0) {
    $sql .= " AND m.courseid = :courseid";
    $params = ['courseid' => $courseid];
} else {
    $params = [];
}

$sql .= " ORDER BY m.timecreated DESC";
$trainingmodels = $DB->get_records_sql($sql, $params);

if (empty($trainingmodels)) {
    echo $OUTPUT->notification(get_string('notrainingmodels', 'block_studentperformancepredictor'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        '#',
        get_string('modelname', 'block_studentperformancepredictor'),
        get_string('datasetname', 'block_studentperformancepredictor'),
        get_string('algorithm', 'block_studentperformancepredictor'),
        get_string('status', 'block_studentperformancepredictor'),
        get_string('timecreated', 'block_studentperformancepredictor'),
        get_string('timemodified', 'block_studentperformancepredictor'),
        get_string('actions', 'block_studentperformancepredictor')
    ];
    $table->data = [];

    // Algorithm options for display
    $algorithmoptions = [
        'randomforest' => get_string('algorithm_randomforest', 'block_studentperformancepredictor'),
        'svm' => get_string('algorithm_svm', 'block_studentperformancepredictor'),
        'logisticregression' => get_string('algorithm_logisticregression', 'block_studentperformancepredictor'),
        'decisiontree' => get_string('algorithm_decisiontree', 'block_studentperformancepredictor'),
        'knn' => get_string('algorithm_knn', 'block_studentperformancepredictor')
    ];

    foreach ($trainingmodels as $model) {
        $row = [];
        $row[] = $model->id;
        $row[] = format_string($model->modelname);
        $row[] = format_string($model->datasetname);
        $row[] = isset($algorithmoptions[$model->algorithmtype]) ? 
               $algorithmoptions[$model->algorithmtype] : $model->algorithmtype;

        // Status with appropriate label
        if ($model->trainstatus === 'pending') {
            $statustext = get_string('pendingstatus', 'block_studentperformancepredictor');
            $statusclass = 'badge badge-info';
        } else {
            $statustext = get_string('trainingstatus', 'block_studentperformancepredictor');
            $statusclass = 'badge badge-warning';
        }
        $row[] = html_writer::tag('span', $statustext, ['class' => $statusclass]);

        $row[] = userdate($model->timecreated);
        $row[] = userdate($model->timemodified);

        // Add delete action
        $deleteurl = new moodle_url($PAGE->url, [
            'action' => 'delete',
            'modelid' => $model->id,
            'sesskey' => sesskey()
        ]);

        $actions = html_writer::link(
            $deleteurl, 
            html_writer::tag('i', '', ['class' => 'fa fa-trash']), 
            ['class' => 'btn btn-sm btn-danger', 'title' => get_string('delete')]
        );

        $row[] = $actions;

        $table->data[] = $row;
    }

    echo html_writer::table($table);
}

// Display all models (including completed and failed ones)
echo $OUTPUT->heading(get_string('allmodels', 'block_studentperformancepredictor'), 3);

$sql = "SELECT m.*, d.name as datasetname 
        FROM {block_spp_models} m
        LEFT JOIN {block_spp_datasets} d ON m.datasetid = d.id
        WHERE 1=1";

if ($courseid > 0) {
    $sql .= " AND m.courseid = :courseid";
    $params = ['courseid' => $courseid];
} else {
    $params = [];
}

$sql .= " ORDER BY m.timecreated DESC";
$allmodels = $DB->get_records_sql($sql, $params);

if (empty($allmodels)) {
    echo $OUTPUT->notification(get_string('nomodels', 'block_studentperformancepredictor'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        '#',
        get_string('modelname', 'block_studentperformancepredictor'),
        get_string('datasetname', 'block_studentperformancepredictor'),
        get_string('algorithm', 'block_studentperformancepredictor'),
        get_string('status', 'block_studentperformancepredictor'),
        get_string('accuracy', 'block_studentperformancepredictor'),
        get_string('active', 'block_studentperformancepredictor'),
        get_string('timecreated', 'block_studentperformancepredictor'),
        get_string('actions', 'block_studentperformancepredictor')
    ];
    $table->data = [];

    foreach ($allmodels as $model) {
        $row = [];
        $row[] = $model->id;
        $row[] = format_string($model->modelname);
        $row[] = format_string($model->datasetname);
        $row[] = isset($algorithmoptions[$model->algorithmtype]) ? 
               $algorithmoptions[$model->algorithmtype] : $model->algorithmtype;

        // Status with appropriate label
        if ($model->trainstatus === 'pending') {
            $statustext = get_string('pendingstatus', 'block_studentperformancepredictor');
            $statusclass = 'badge badge-info';
        } else if ($model->trainstatus === 'training') {
            $statustext = get_string('trainingstatus', 'block_studentperformancepredictor');
            $statusclass = 'badge badge-warning';
        } else if ($model->trainstatus === 'complete') {
            $statustext = get_string('complete', 'block_studentperformancepredictor');
            $statusclass = 'badge badge-success';
        } else {
            $statustext = get_string('failed', 'block_studentperformancepredictor');
            $statusclass = 'badge badge-danger';
        }
        $row[] = html_writer::tag('span', $statustext, ['class' => $statusclass]);

        // Accuracy
        $row[] = isset($model->accuracy) ? round($model->accuracy * 100, 2) . '%' : '-';

        // Active status
        $row[] = $model->active ? 
            html_writer::tag('span', get_string('yes'), ['class' => 'badge badge-success']) : 
            html_writer::tag('span', get_string('no'), ['class' => 'badge badge-secondary']);

        $row[] = userdate($model->timecreated);

        // Add delete action
        $deleteurl = new moodle_url($PAGE->url, [
            'action' => 'delete',
            'modelid' => $model->id,
            'sesskey' => sesskey()
        ]);

        $actions = html_writer::link(
            $deleteurl, 
            html_writer::tag('i', '', ['class' => 'fa fa-trash']), 
            ['class' => 'btn btn-sm btn-danger', 'title' => get_string('delete')]
        );

        $row[] = $actions;

        $table->data[] = $row;
    }

    echo html_writer::table($table);
}

// Now show the training logs
echo $OUTPUT->heading(get_string('traininglogs', 'block_studentperformancepredictor'), 3);

// Get training logs from block_spp_training_log
$sql = "SELECT l.* 
        FROM {block_spp_training_log} l
        JOIN {block_spp_models} m ON l.modelid = m.id
        WHERE 1=1";

if ($courseid > 0) {
    $sql .= " AND m.courseid = :courseid";
    $params = ['courseid' => $courseid];
} else {
    $params = [];
}

$sql .= " ORDER BY l.timecreated DESC";
$logs = $DB->get_records_sql($sql, $params, 0, 50); // Limit to 50 most recent logs

if (empty($logs)) {
    echo $OUTPUT->notification(get_string('notraininglogs', 'block_studentperformancepredictor'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('modelid', 'block_studentperformancepredictor'),
        get_string('event', 'block_studentperformancepredictor'),
        get_string('logmessage', 'block_studentperformancepredictor'),
        get_string('level', 'block_studentperformancepredictor'),
        get_string('timecreated', 'block_studentperformancepredictor')
    ];
    $table->data = [];

    foreach ($logs as $log) {
        $row = [];
        $row[] = $log->modelid;
        $row[] = $log->event;
        $row[] = $log->message;

        // Level with appropriate styling
        if ($log->level === 'error') {
            $levelclass = 'text-danger';
        } else if ($log->level === 'warning') {
            $levelclass = 'text-warning';
        } else {
            $levelclass = 'text-info';
        }
        $row[] = html_writer::tag('span', $log->level, ['class' => $levelclass]);

        $row[] = userdate($log->timecreated);

        $table->data[] = $row;
    }

    echo html_writer::table($table);
}

// Get all adhoc tasks for this plugin/course
echo $OUTPUT->heading(get_string('scheduledtasks', 'block_studentperformancepredictor'), 3);

// Filter by course if specified
$tasksql = "component = :component";
$taskparams = ['component' => 'block_studentperformancepredictor'];

if ($courseid) {
    $tasksql .= " AND " . $DB->sql_like('customdata', ':courseid');
    $taskparams['courseid'] = '%"courseid":' . $courseid . '%';
}

// Get all adhoc tasks for this plugin/course
$tasks = $DB->get_records_select('task_adhoc', $tasksql, $taskparams, 'id DESC');

if (empty($tasks)) {
    echo $OUTPUT->notification(get_string('notasks', 'block_studentperformancepredictor'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('taskid', 'block_studentperformancepredictor'),
        get_string('taskname', 'block_studentperformancepredictor'),
        get_string('status', 'block_studentperformancepredictor'),
        get_string('courseid', 'block_studentperformancepredictor'),
        get_string('nextruntime', 'block_studentperformancepredictor'),
        get_string('lastruntime', 'block_studentperformancepredictor'),
        get_string('output', 'tool_task')
    ];
    $table->data = [];

    foreach ($tasks as $task) {
        $customdata = json_decode($task->customdata);

        // Task information
        $taskname = explode('\\', $task->classname);
        $taskname = end($taskname);

        // Task status
        if ($task->nextruntime && $task->nextruntime > time()) {
            $statustext = get_string('taskqueued', 'block_studentperformancepredictor');
            $statusclass = 'badge badge-info';
        } else if ($task->timestarted && !$task->timecompleted) {
            $statustext = get_string('taskrunning', 'block_studentperformancepredictor');
            $statusclass = 'badge badge-warning';
        } else {
            $statustext = get_string('complete', 'block_studentperformancepredictor');
            $statusclass = 'badge badge-success';
        }

        // Course info
        $thiscourse = isset($customdata->courseid) ? $customdata->courseid : '-';

        // Time values
        $nextrun = $task->nextruntime ? userdate($task->nextruntime) : '-';
        $lastrun = $task->lastruntime ? userdate($task->lastruntime) : '-';

        $output = $task->faildelay ? get_string('failed', 'block_studentperformancepredictor') : ($task->output ?? '-');

        $table->data[] = [
            $task->id,
            $taskname,
            html_writer::span($statustext, $statusclass),
            $thiscourse,
            $nextrun,
            $lastrun,
            $output
        ];
    }

    echo html_writer::table($table);
}

// Add button to purge all failed models
if ($courseid) {
    echo html_writer::start_div('mt-3');

    // Back to models button
    echo html_writer::link(
        new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', ['courseid' => $courseid]),
        get_string('backtomodels', 'block_studentperformancepredictor'),
        ['class' => 'btn btn-secondary mr-2']
    );

    // Purge failed models button
    echo html_writer::link(
        new moodle_url($PAGE->url, [
            'action' => 'purgefailed',
            'courseid' => $courseid,
            'sesskey' => sesskey()
        ]),
        get_string('purgefailedmodels', 'block_studentperformancepredictor'),
        ['class' => 'btn btn-warning']
    );

    echo html_writer::end_div();
} else {
    // For global view, just show return button
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/admin/settings.php', ['section' => 'blocksettingstudentperformancepredictor']),
            get_string('backsettings', 'block_studentperformancepredictor'),
            ['class' => 'btn btn-secondary']
        ),
        'mt-3'
    );
}

echo $OUTPUT->footer();

// blocks/studentperformancepredictor/amd/src/admin_interface.js

define(['jquery', 'core/ajax', 'core/str', 'core/notification', 'core/modal_factory', 'core/modal_events'],
function($, Ajax, Str, Notification, ModalFactory, ModalEvents) {

    /**
     * Initialize admin interface.
     * * @param {int} courseId Course ID
     */
    var init = function(courseId) {
        try {
            // Handle model training form submission state
            $('#spp-train-model-form').on('submit', function() {
                var form = $(this);
                var submitButton = form.find('input[type="submit"]');

                // Disable the button and show a "training" message to prevent multiple submissions.
                // The form will then submit normally, and the browser will be redirected by the PHP script.
                var originalText = submitButton.val();
                submitButton.prop('disabled', true);
                Str.get_string('training', 'block_studentperformancepredictor').done(function(trainingStr) {
                    submitButton.val(trainingStr + '...');
                });
            });

            // Handle dataset deletion
            $('.spp-delete-dataset').on('click', function(e) {
                e.preventDefault();

                var button = $(this);
                var datasetId = button.data('dataset-id');
                var datasetCourseId = button.data('course-id');

                button.prop('disabled', true);

                // Confirm deletion.
                Str.get_strings([
                    {key: 'confirmdeletedataset', component: 'block_studentperformancepredictor'},
                    {key: 'delete', component: 'core'},
                    {key: 'cancel', component: 'core'}
                ]).done(function(strings) {
                    ModalFactory.create({
                        type: ModalFactory.types.SAVE_CANCEL,
                        title: strings[0],
                        body: strings[0]
                    }).done(function(modal) {
                        modal.setSaveButtonText(strings[1]);

                        // When the user confirms deletion
                        modal.getRoot().on(ModalEvents.save, function() {
                            $.ajax({
                                url: M.cfg.wwwroot + '/blocks/studentperformancepredictor/admin/ajax_delete_dataset.php',
                                type: 'POST',
                                data: {
                                    datasetid: datasetId,
                                    courseid: datasetCourseId,
                                    sesskey: M.cfg.sesskey
                                },
                                dataType: 'json',
                                success: function(response) {
                                    if (response.success) {
                                        window.location.reload();
                                    } else {
                                        button.prop('disabled', false);
                                        Notification.addNotification({
                                            message: response.message,
                                            type: 'error'
                                        });
                                    }
                                },
                                error: function(xhr, status, error) {
                                    button.prop('disabled', false);
                                    var errorMessage = error;
                                    try {
                                        var resp = JSON.parse(xhr.responseText);
                                        if (resp && resp.message) {
                                            errorMessage = resp.message;
                                        }
                                    } catch (e) {}
                                    Str.get_string('actionerror', 'block_studentperformancepredictor').done(function(s) {
                                        Notification.addNotification({
                                            message: s + ': ' + errorMessage,
                                            type: 'error'
                                        });
                                    });
                                }
                            });
                        });

                        modal.getRoot().on(ModalEvents.cancel, function() {
                            button.prop('disabled', false);
                        });

                        modal.show();
                    }).catch(function(error) {
                        console.error('Error creating modal:', error);
                        button.prop('disabled', false);
                    });
                }).catch(function(error) {
                    console.error('Error loading strings:', error);
                    button.prop('disabled', false);
                });
            });

        } catch (e) {
            console.error('Error initializing admin interface:', e);
            Str.get_string('jserror', 'moodle').done(function(s) {
                Notification.exception(new Error(s + ': ' + e.message));
            });
        }
    };

    return {
        init: init
    };
});

// blocks/studentperformancepredictor/amd/src/chart_renderer.js

define(['jquery', 'core/chartjs', 'core/str'], function($, Chart, Str) {

    /**
     * Initialize student prediction chart.
     */
    var initStudentChart = function() {
        var chartElement = document.getElementById('spp-prediction-chart');
        if (!chartElement) {
            return;
        }

        try {
            var chartData = {};
            try {
                chartData = JSON.parse(chartElement.dataset.chartdata || '{"passprob":0,"failprob":100}');
            } catch (e) {
                console.error('Error parsing chart data:', e);
                chartData = {"passprob":0,"failprob":100};
            }

            var ctx = chartElement.getContext('2d');

            Str.get_strings([
                {key: 'passingchance', component: 'block_studentperformancepredictor'},
                {key: 'failingchance', component: 'block_studentperformancepredictor'}
            ]).done(function(labels) {
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: [chartData.passprob, chartData.failprob],
                            backgroundColor: [
                                '#28a745', // Green for passing
                                '#dc3545'  // Red for failing
                            ],
                            borderWidth: 1,
                            hoverBackgroundColor: [
                                '#218838', // Darker green on hover
                                '#c82333'  // Darker red on hover
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutoutPercentage: 70,
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                boxWidth: 12
                            }
                        },
                        tooltips: {
                            callbacks: {
                                label: function(tooltipItem, data) {
                                    var dataset = data.datasets[tooltipItem.datasetIndex];
                                    var value = dataset.data[tooltipItem.index];
                                    return data.labels[tooltipItem.index] + ': ' + value + '%';
                                }
                            }
                        },
                        animation: {
                            animateScale: true,
                            animateRotate: true,
                            duration: 1000
                        }
                    }
                });
            }).fail(function() {
                // Fallback labels if string loading fails
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Passing', 'Failing'],
                        datasets: [{
                            data: [chartData.passprob, chartData.failprob],
                            backgroundColor: ['#28a745', '#dc3545'],
                            borderWidth: 1,
                            hoverBackgroundColor: ['#218838', '#c82333']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutoutPercentage: 70
                    }
                });
            });
        } catch (e) {
            console.error('Error initializing chart:', e);
            Str.get_string('charterror', 'block_studentperformancepredictor').done(function(msg) {
                chartElement.innerHTML = '<div class="alert alert-warning">' + msg + '</div>';
            }).fail(function() {
                chartElement.innerHTML = '<div class="alert alert-warning">Chart error</div>';
            });
        }
    };

    /**
     * Initialize teacher view chart.
     */
    var initTeacherChart = function() {
        var chartElement = document.getElementById('spp-teacher-chart');
        if (!chartElement) {
            return;
        }

        try {
            var chartData = {};
            try {
                chartData = JSON.parse(chartElement.dataset.chartdata || '{"labels":[],"data":[]}');
            } catch (e) {
                console.error('Error parsing chart data:', e);
                chartData = {"labels":[],"data":[]};
            }

            var ctx = chartElement.getContext('2d');

            new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: chartData.labels,
                    datasets: [{
                        data: chartData.data,
                        backgroundColor: [
                            '#dc3545',  // High risk - Red
                            '#ffc107',  // Medium risk - Yellow
                            '#28a745'   // Low risk - Green
                        ],
                        borderWidth: 1,
                        hoverBackgroundColor: [
                            '#c82333',  // Darker red on hover
                            '#e0a800',  // Darker yellow on hover
                            '#218838'   // Darker green on hover
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            boxWidth: 12
                        }
                    },
                    tooltips: {
                        callbacks: {
                            label: function(tooltipItem, data) {
                                var dataset = data.datasets[tooltipItem.datasetIndex];
                                var total = dataset.data.reduce(function(previousValue, currentValue) {
                                    return previousValue + currentValue;
                                });
                                var currentValue = dataset.data[tooltipItem.index];
                                var percentage = Math.round((currentValue / total) * 100);
                                return data.labels[tooltipItem.index] + ': ' + currentValue + ' (' + percentage + '%)';
                            }
                        }
                    },
                    animation: {
                        animateScale: true,
                        animateRotate: true,
                        duration: 1000
                    }
                }
            });
        } catch (e) {
            console.error('Error initializing teacher chart:', e);
            Str.get_string('charterror', 'block_studentperformancepredictor').done(function(msg) {
                chartElement.innerHTML = '<div class="alert alert-warning">' + msg + '</div>';
            }).fail(function() {
                chartElement.innerHTML = '<div class="alert alert-warning">Chart error</div>';
            });
        }
    };

    /**
     * Initialize admin view chart.
     */
    var initAdminChart = function() {
        var chartElement = document.getElementById('spp-admin-chart');
        if (!chartElement) {
            return;
        }

        try {
            var chartData = {};
            try {
                chartData = JSON.parse(chartElement.dataset.chartdata || '{"labels":[],"data":[]}');
            } catch (e) {
                console.error('Error parsing chart data:', e);
                chartData = {"labels":[],"data":[]};
            }

            var ctx = chartElement.getContext('2d');

            Str.get_string('studentcount', 'block_studentperformancepredictor').done(function(label) {
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: chartData.labels,
                        datasets: [{
                            label: label,
                            data: chartData.data,
                            backgroundColor: [
                                '#dc3545',  // High risk - Red
                                '#ffc107',  // Medium risk - Yellow
                                '#28a745'   // Low risk - Green
                            ],
                            borderWidth: 1,
                            hoverBackgroundColor: [
                                '#c82333',  // Darker red on hover
                                '#e0a800',  // Darker yellow on hover
                                '#218838'   // Darker green on hover
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            yAxes: [{
                                ticks: {
                                    beginAtZero: true,
                                    precision: 0
                                },
                                gridLines: {
                                    drawBorder: true,
                                    color: 'rgba(0, 0, 0, 0.1)'
                                }
                            }],
                            xAxes: [{
                                gridLines: {
                                    display: false
                                }
                            }]
                        },
                        legend: {
                            display: false
                        },
                        tooltips: {
                            callbacks: {
                                label: function(tooltipItem, data) {
                                    var dataset = data.datasets[tooltipItem.datasetIndex];
                                    var total = dataset.data.reduce(function(previousValue, currentValue) {
                                        return previousValue + currentValue;
                                    });
                                    var currentValue = dataset.data[tooltipItem.index];
                                    var percentage = Math.round((currentValue / total) * 100);
                                    return data.labels[tooltipItem.index] + ': ' + currentValue + ' (' + percentage + '%)';
                                }
                            }
                        },
                        animation: {
                            duration: 1000,
                            easing: 'easeOutQuart'
                        }
                    }
                });
            }).fail(function() {
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: chartData.labels,
                        datasets: [{
                            label: 'Student count',
                            data: chartData.data,
                            backgroundColor: ['#dc3545', '#ffc107', '#28a745'],
                            borderWidth: 1,
                            hoverBackgroundColor: ['#c82333', '#e0a800', '#218838']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            });
        } catch (e) {
            console.error('Error initializing admin chart:', e);
            Str.get_string('charterror', 'block_studentperformancepredictor').done(function(msg) {
                chartElement.innerHTML = '<div class="alert alert-warning">' + msg + '</div>';
            }).fail(function() {
                chartElement.innerHTML = '<div class="alert alert-warning">Chart error</div>';
            });
        }
    };

    return {
        init: initStudentChart,
        initTeacherChart: initTeacherChart,
        initAdminChart: initAdminChart
    };
});

// blocks/studentperformancepredictor/amd/src/prediction_viewer.js

define(['jquery', 'core/ajax', 'core/notification', 'core/modal_factory', 'core/modal_events', 'core/str'],
function($, Ajax, Notification, ModalFactory, ModalEvents, Str) {
    /**
     * Initialize suggestion management and prediction features.
     */
    var init = function() {
        // This function will initialize all event listeners when the page loads.

        // Handle marking suggestions as viewed
        $(document).on('click', '.spp-mark-viewed', function(e) {
            e.preventDefault();
            var button = $(this);
            var suggestionId = button.data('id');
            if (!suggestionId) {
                return;
            }
            button.prop('disabled', true);
            Ajax.call([{
                methodname: 'block_studentperformancepredictor_mark_suggestion_viewed',
                args: { suggestionid: suggestionId },
                done: function(response) {
                    if (response.status) {
                        Str.get_string('viewed', 'block_studentperformancepredictor').done(function(s) {
                            button.replaceWith('<span class="badge bg-secondary">' + s + '</span>');
                        });
                    } else {
                        Notification.addNotification({ message: response.message, type: 'error' });
                        button.prop('disabled', false);
                    }
                },
                fail: Notification.exception
            }]);
        });

        // Handle marking suggestions as completed
        $(document).on('click', '.spp-mark-completed', function(e) {
            e.preventDefault();
            var button = $(this);
            var suggestionId = button.data('id');
            if (!suggestionId) {
                return;
            }
            button.prop('disabled', true);
            Ajax.call([{
                methodname: 'block_studentperformancepredictor_mark_suggestion_completed',
                args: { suggestionid: suggestionId },
                done: function(response) {
                    if (response.status) {
                        Str.get_string('completed', 'block_studentperformancepredictor').done(function(s) {
                            button.replaceWith('<span class="badge bg-success">' + s + '</span>');
                        });
                    } else {
                        Notification.addNotification({ message: response.message, type: 'error' });
                        button.prop('disabled', false);
                    }
                },
                fail: Notification.exception
            }]);
        });

        // Handle teacher refresh predictions button
        $(document).on('click', '.spp-refresh-predictions', function(e) {
            e.preventDefault();
            var button = $(this);
            var courseId = button.data('course-id');

            if (!courseId) {
                Notification.addNotification({ message: 'Course ID not found.', type: 'error' });
                return;
            }

            button.prop('disabled', true);

            Str.get_strings([
                {key: 'refreshconfirmation', component: 'block_studentperformancepredictor'},
                {key: 'refresh', component: 'block_studentperformancepredictor'},
                {key: 'cancel', component: 'core'}
            ]).done(function(strings) {
                ModalFactory.create({
                    type: ModalFactory.types.SAVE_CANCEL,
                    title: strings[0],
                    body: strings[0]
                }).done(function(modal) {
                    modal.setSaveButtonText(strings[1]);
                    modal.getRoot().on(ModalEvents.save, function() {
                        Ajax.call([{
                            methodname: 'block_studentperformancepredictor_refresh_predictions',
                            args: { courseid: courseId },
                            done: function(response) {
                                if (response.status) {
                                    Notification.addNotification({ message: response.message, type: 'success' });
                                    setTimeout(function() {
                                        window.location.reload();
                                    }, 1500);
                                } else {
                                    Notification.addNotification({ message: response.message, type: 'error' });
                                    button.prop('disabled', false);
                                }
                            },
                            fail: function(ex) {
                                Notification.exception(ex);
                                button.prop('disabled', false);
                            }
                        }]);
                    });
                    modal.getRoot().on(ModalEvents.cancel, function() {
                        button.prop('disabled', false);
                    });
                    modal.show();
                });
            }).fail(Notification.exception);
        });

        // Handle student generate prediction and teacher/admin update prediction buttons
        $(document).on('click', '.spp-generate-prediction, .spp-update-prediction', function(e) {
            e.preventDefault();
            var button = $(this);
            var courseId = button.data('course-id');
            var userId = button.data('user-id');
            var originalText = button.html();
            
            button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + originalText);

            // Using Moodle's core/ajax web service for the request.
            Ajax.call([{
                methodname: 'block_studentperformancepredictor_generate_student_prediction',
                args: {
                    courseid: courseId,
                    userid: userId
                },
                done: function(response) {
                    if (response.success) {
                        Notification.addNotification({ message: response.message, type: 'success' });
                        // Reload the page to show the new prediction data.
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        Notification.addNotification({ message: response.message, type: 'error' });
                        button.prop('disabled', false).html(originalText);
                    }
                },
                fail: function(ex) {
                    Notification.exception(ex);
                    button.prop('disabled', false).html(originalText);
                }
            }]);
        });
    };

    return {
        init: init
    };
});

// blocks/studentperformancepredictor/amd/src/refresh_button.js

define(['jquery', 'core/ajax', 'core/notification', 'core/modal_factory', 'core/modal_events', 'core/str'],
function($, Ajax, Notification, ModalFactory, ModalEvents, Str) {
    var init = function() {
        $(document).on('click', '.spp-refresh-predictions', function(e) {
            e.preventDefault();
            var button = $(this);
            var courseId = button.data('course-id');

            if (!courseId) {
                Notification.addNotification({ message: 'Course ID not found.', type: 'error' });
                return;
            }

            var originalButtonText = button.text();
            button.prop('disabled', true).text('Processing...');

            Str.get_strings([
                {key: 'refreshconfirmation', component: 'block_studentperformancepredictor'},
                {key: 'refresh', component: 'block_studentperformancepredictor'},
                {key: 'cancel', component: 'core'}
            ]).done(function(strings) {
                ModalFactory.create({
                    type: ModalFactory.types.SAVE_CANCEL,
                    title: strings[0],
                    body: strings[0]
                }).done(function(modal) {
                    modal.setSaveButtonText(strings[1]);
                    modal.getRoot().on(ModalEvents.save, function() {
                        Ajax.call([{
                            methodname: 'block_studentperformancepredictor_refresh_predictions',
                            args: { courseid: courseId },
                            done: function(response) {
                                if (response.status) {
                                    Notification.addNotification({ message: response.message, type: 'success' });
                                    // Reload the page after a short delay to see updates
                                    setTimeout(function() {
                                        window.location.reload();
                                    }, 1500);
                                } else {
                                    Notification.addNotification({ message: response.message, type: 'error' });
                                    button.prop('disabled', false).text(originalButtonText);
                                }
                            },
                            fail: function(ex) {
                                Notification.exception(ex);
                                button.prop('disabled', false).text(originalButtonText);
                            }
                        }]);
                    });
                    modal.getRoot().on(ModalEvents.cancel, function() {
                        button.prop('disabled', false).text(originalButtonText);
                    });
                    modal.show();
                });
            }).fail(function(ex) {
                Notification.exception(ex);
                button.prop('disabled', false).text(originalButtonText);
            });
        });
    };

    return {
        init: init
    };
});

<?php
// blocks/studentperformancepredictor/classes/analytics/data_preprocessor.php

namespace block_studentperformancepredictor\analytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Data preprocessor for student performance data.
 *
 * NOTE: As of the unified plugin architecture, all ML feature engineering and preprocessing
 * for model training and prediction is handled by the Python backend. This class is only
 * used for minimal validation or formatting before sending data to the backend.
 * Advanced preprocessing methods below are legacy and not used in the new workflow.
 */
class data_preprocessor {
    /** @var int Course ID */
    protected $courseid;

    /**
     * Constructor.
     * 
     * @param int $courseid Course ID
     */
    public function __construct($courseid) {
        $this->courseid = $courseid;
    }

    /**
     * Preprocess a dataset for training.
     *
     * In the new architecture, this should only perform minimal validation/formatting.
     * All ML preprocessing is handled by the Python backend.
     *
     * @param array $dataset Raw dataset
     * @return array Preprocessed dataset
     */
    public function preprocess_dataset($dataset) {
        // Minimal validation/formatting only. No ML feature engineering here.
        return $dataset;
    }

    /**
     * Legacy: Handle missing values in the dataset (not used in new backend-driven workflow).
     *
     * @param array $dataset Dataset with possible missing values
     * @return array Dataset with handled missing values
     */
    protected function handle_missing_values($dataset) {
        if (empty($dataset)) {
            return $dataset;
        }

        $columns = array_keys($dataset[0]);
        $columnMeans = array();
        $columnModes = array();
        $numRows = count($dataset);

        // Calculate means for numeric columns and modes for categorical columns
        foreach ($columns as $column) {
            $numericValues = array();
            $categoricalValues = array();
            foreach ($dataset as $row) {
                if (isset($row[$column]) && $row[$column] !== '' && $row[$column] !== null) {
                    if (is_numeric($row[$column])) {
                        $numericValues[] = $row[$column];
                    } else {
                        $categoricalValues[] = $row[$column];
                    }
                }
            }
            if (!empty($numericValues)) {
                $columnMeans[$column] = array_sum($numericValues) / count($numericValues);
            }
            if (!empty($categoricalValues)) {
                $valuesCount = array_count_values($categoricalValues);
                arsort($valuesCount);
                $columnModes[$column] = key($valuesCount);
            }
        }

        // Fill missing values with mean (numeric) or mode (categorical)
        foreach ($dataset as $i => $row) {
            foreach ($columns as $column) {
                if (!isset($row[$column]) || $row[$column] === '' || $row[$column] === null) {
                    if (isset($columnMeans[$column]) && (!isset($columnModes[$column]) || is_numeric($columnMeans[$column]))) {
                        $dataset[$i][$column] = $columnMeans[$column];
                    } else if (isset($columnModes[$column])) {
                        $dataset[$i][$column] = $columnModes[$column];
                    } else {
                        $dataset[$i][$column] = '';
                    }
                }
            }
        }
        return $dataset;
    }

    /**
     * Legacy: Encode categorical features to numeric values (not used in new backend-driven workflow).
     * 
     * @param array $dataset Dataset with categorical features
     * @return array Dataset with encoded features
     */
    protected function encode_categorical_features($dataset) {
        if (empty($dataset)) {
            return $dataset;
        }

        $columns = array_keys($dataset[0]);

        // Find categorical columns (non-numeric values)
        foreach ($columns as $column) {
            $hasNonNumeric = false;

            // Check a sample of rows to determine if column is categorical
            $sampleSize = min(10, count($dataset));
            for ($i = 0; $i < $sampleSize; $i++) {
                if (isset($dataset[$i][$column]) && !is_numeric($dataset[$i][$column])) {
                    $hasNonNumeric = true;
                    break;
                }
            }

            if ($hasNonNumeric) {
                // Create a mapping of unique values to numeric codes
                $uniqueValues = array();
                foreach ($dataset as $row) {
                    if (isset($row[$column]) && !isset($uniqueValues[$row[$column]])) {
                        $uniqueValues[$row[$column]] = count($uniqueValues);
                    }
                }

                // Apply the mapping
                foreach ($dataset as $i => $row) {
                    if (isset($row[$column])) {
                        $dataset[$i][$column] = isset($uniqueValues[$row[$column]]) ? 
                                             $uniqueValues[$row[$column]] : 0;
                    }
                }
            }
        }

        return $dataset;
    }

    /**
     * Legacy: Normalize numeric features to the range [0,1] (not used in new backend-driven workflow).
     * 
     * @param array $dataset Dataset with numeric features
     * @return array Dataset with normalized features
     */
    protected function normalize_features($dataset) {
        if (empty($dataset)) {
            return $dataset;
        }

        $columns = array_keys($dataset[0]);

        // Find min and max for each column
        $mins = array();
        $maxs = array();

        foreach ($columns as $column) {
            $values = array();
            foreach ($dataset as $row) {
                if (isset($row[$column]) && is_numeric($row[$column])) {
                    $values[] = $row[$column];
                }
            }

            if (!empty($values)) {
                $mins[$column] = min($values);
                $maxs[$column] = max($values);
            } else {
                $mins[$column] = 0;
                $maxs[$column] = 1;
            }
        }

        // Apply min-max normalization
        foreach ($dataset as $i => $row) {
            foreach ($columns as $column) {
                if (isset($row[$column]) && is_numeric($row[$column]) && 
                    $maxs[$column] > $mins[$column]) {
                    $dataset[$i][$column] = ($row[$column] - $mins[$column]) / 
                                         ($maxs[$column] - $mins[$column]);
                }
            }
        }

        return $dataset;
    }
}

<?php
// blocks/studentperformancepredictor/classes/analytics/model_trainer.php

namespace block_studentperformancepredictor\analytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Model trainer for student performance prediction.
 *
 * This class is responsible for orchestrating the model training process
 * by calling the Python backend API. No ML logic is performed in PHP.
 */
class model_trainer {
    /** @var int Course ID */
    protected $courseid;

    /** @var int Dataset ID */
    protected $datasetid;

    /** @var data_preprocessor Preprocessor instance */
    protected $preprocessor;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID
     * @param int $datasetid Dataset ID
     */
    public function __construct($courseid, $datasetid) {
        global $CFG;

        $this->courseid = $courseid;
        $this->datasetid = $datasetid;
        $this->preprocessor = new data_preprocessor($courseid);
    }

    /**
     * Train a new model by calling the Python backend API.
     *
     * @param string|null $algorithm Optional algorithm to use
     * @param array $options Training options
     * @return int ID of the newly created model
     * @throws \moodle_exception on error
     */
    public function train_model($algorithm = null, $options = array()) {
        global $DB, $USER, $CFG;

        if (function_exists('mtrace')) {
            mtrace("Starting model training process via backend API...");
        }

        // Validate inputs
        if (empty($this->datasetid)) {
            throw new \moodle_exception('invalidinput', 'block_studentperformancepredictor', '',
                'Dataset ID is missing');
        }

        // Verify dataset exists and belongs to the course if course-specific
        if ($this->courseid > 0) {
            $dataset = $DB->get_record('block_spp_datasets',
                array('id' => $this->datasetid, 'courseid' => $this->courseid));
        } else {
            // For global models (courseid=0), just check if dataset exists
            $dataset = $DB->get_record('block_spp_datasets', array('id' => $this->datasetid));
        }

        if (!$dataset) {
            throw new \moodle_exception('invaliddataset', 'block_studentperformancepredictor');
        }
        $dataset_filepath = $dataset->filepath; // Get the full path

        // If no algorithm specified, use the default from settings
        if (empty($algorithm)) {
            $algorithm = get_config('block_studentperformancepredictor', 'defaultalgorithm');
            if (empty($algorithm)) {
                $algorithm = 'randomforest'; // Default fallback
            }
        }

        // Call Python backend for training
        $apiurl = get_config('block_studentperformancepredictor', 'python_api_url');
        if (empty($apiurl)) {
            $apiurl = 'http://localhost:5000/train';
        } else {
            // Ensure API URL ends with the train endpoint
            if (substr($apiurl, -6) !== '/train') {
                $apiurl = rtrim($apiurl, '/') . '/train';
            }
        }

        $apikey = get_config('block_studentperformancepredictor', 'python_api_key');
        if (empty($apikey)) {
            $apikey = 'changeme';
        }

        // Normalize filepath for Windows compatibility
        $dataset_filepath = str_replace('\\', '/', $dataset_filepath);

        $payload = [
            'courseid' => $this->courseid,
            'dataset_filepath' => $dataset_filepath, // Send the full filepath as expected by Python backend
            'algorithm' => $algorithm,
            'userid' => $USER->id
        ];

        // Add any custom options to the payload
        if (!empty($options)) {
            $payload = array_merge($payload, $options);
        }

        // Enable debug output for training
        $debug = get_config('block_studentperformancepredictor', 'enabledebug');

        $curl = new \curl();
        $curl_options = [
            'CURLOPT_TIMEOUT' => 300, // 5 minutes timeout for training
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_HTTPHEADER' => [
                'Content-Type: application/json',
                'X-API-Key: ' . $apikey
            ],
            // Add these for Windows XAMPP compatibility
            'CURLOPT_SSL_VERIFYHOST' => 0,
            'CURLOPT_SSL_VERIFYPEER' => 0
        ];

        if ($debug && function_exists('mtrace')) {
            mtrace("Sending request to backend API: " . json_encode($payload));
        }

        try {
            $response = $curl->post($apiurl, json_encode($payload), $curl_options);
            $httpcode = $curl->get_info()['http_code'] ?? 0;

            if ($debug && function_exists('mtrace')) {
                mtrace("Prediction response code: " . $httpcode);
                mtrace("Response: " . $response);
            }

            if ($httpcode === 200) {
                $data = json_decode($response, true);
                if (!is_array($data) || !isset($data['model_id'])) {
                    throw new \moodle_exception('trainingfailed', 'block_studentperformancepredictor', '',
                        'Invalid response from backend: ' . $response);
                }

                if (function_exists('mtrace')) {
                    mtrace("Received successful response from backend. Model ID: " . $data['model_id']);
                }

                // Extract model details from response
                $model_id = $data['model_id'];
                $model_path = isset($data['model_path']) ? $data['model_path'] : null;
                $algorithm_used = isset($data['algorithm']) ? $data['algorithm'] : $algorithm;
                $accuracy = isset($data['metrics']['accuracy']) ? $data['metrics']['accuracy'] : null;
                $metrics = isset($data['metrics']) ? json_encode($data['metrics']) : null;

                // Create model record
                $model = new \stdClass();
                $model->courseid = $this->courseid;
                $model->datasetid = $this->datasetid;
                $model->modelname = ucfirst($algorithm_used) . ' Model - ' . date('Y-m-d H:i');
                $model->modelid = $model_id;
                $model->modelpath = $model_path;
                $model->algorithmtype = $algorithm_used;
                $model->featureslist = isset($data['feature_names']) ? json_encode($data['feature_names']) : '[]';
                $model->accuracy = $accuracy;
                $model->metrics = $metrics;
                $model->active = 0; // Not active by default
                $model->trainstatus = 'complete';
                $model->timecreated = time();
                $model->timemodified = time();
                $model->usermodified = $USER->id;

                $model_db_id = $DB->insert_record('block_spp_models', $model);

                if (function_exists('mtrace')) {
                    mtrace("Model training completed successfully. Moodle Model ID: $model_db_id");
                }

                // Trigger event for model trained
                $context = \context_course::instance($this->courseid > 0 ? $this->courseid : SITEID);
                $event = \block_studentperformancepredictor\event\model_trained::create([
                    'context' => $context,
                    'objectid' => $model_db_id,
                    'other' => [
                        'courseid' => $this->courseid,
                        'datasetid' => $this->datasetid,
                        'algorithm' => $algorithm_used
                    ]
                ]);
                $event->trigger();

                return $model_db_id;
            } else {
                $error = "HTTP $httpcode: " . $response;
                if (function_exists('mtrace')) {
                    mtrace("Backend API error: $error");
                }

                // Error handling for HTTP errors
                if ($httpcode >= 400 && $httpcode < 500) {
                    throw new \moodle_exception('trainingfailed', 'block_studentperformancepredictor', '',
                        'Client error: ' . $error);
                } else if ($httpcode >= 500) {
                    throw new \moodle_exception('trainingfailed', 'block_studentperformancepredictor', '',
                        'Server error: ' . $error);
                } else {
                    throw new \moodle_exception('trainingfailed', 'block_studentperformancepredictor', '', $error);
                }
            }
        } catch (\Exception $e) {
            if (function_exists('mtrace')) {
                mtrace("Exception during model training: " . $e->getMessage());
            }
            throw new \moodle_exception('trainingfailed', 'block_studentperformancepredictor', '', $e->getMessage());
        }
    }
}

<?php
// blocks/studentperformancepredictor/classes/analytics/predictor.php

namespace block_studentperformancepredictor\analytics;

defined('MOODLE_INTERNAL') || die();

// Ensure lib.php is included at the top for all global function definitions
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Prediction engine for student performance.
 *
 * NOTE: As of the unified plugin architecture, all predictions are made by calling
 * the Python backend /predict endpoint. No ML logic is performed in PHP.
 * This class is responsible only for orchestrating backend calls and storing prediction results.
 */
class predictor {
    /** @var int Course ID */
    protected $courseid;

    /** @var object The model record from database */
    protected $model;

    /** @var data_preprocessor Preprocessor instance */
    protected $preprocessor;

    /** @var suggestion_generator Suggestion generator instance */
    protected $suggestiongenerator;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID
     */
    public function __construct($courseid) {
        global $DB;

        $this->courseid = $courseid;

        // Query for active model - course model has priority
        $params = ['courseid' => $courseid, 'active' => 1, 'trainstatus' => 'complete'];
        $this->model = $DB->get_record('block_spp_models', $params);

        // If no course model and global models are enabled, try to get a global model
        if (!$this->model && get_config('block_studentperformancepredictor', 'enableglobalmodel')) {
            $params = ['courseid' => 0, 'active' => 1, 'trainstatus' => 'complete'];
            $this->model = $DB->get_record('block_spp_models', $params);
        }

        if (!$this->model) {
            throw new \moodle_exception('noactivemodel', 'block_studentperformancepredictor');
        }

        $this->preprocessor = new data_preprocessor($courseid);
        $this->suggestiongenerator = new suggestion_generator($courseid);
    }

    /**
     * Generate predictions for a specific student.
     *
     * @param int $userid User ID
     * @return object Prediction result
     */
    public function predict_for_student($userid) {
        global $DB, $CFG;

        // Get comprehensive student data for prediction
        $studentdata = $this->get_comprehensive_student_data($userid);

        if (empty($studentdata)) {
            throw new \moodle_exception('nostudentdata', 'block_studentperformancepredictor');
        }

        // Create feature vector for prediction
        $features = $this->create_feature_vector($studentdata);

        // Make prediction using the backend
        $prediction = $this->make_prediction($features);

        // Determine risk level based on pass probability
        $risklevel = $this->calculate_risk_level($prediction->passprob);

        // Store prediction in database
        $predictionrecord = new \stdClass();
        $predictionrecord->modelid = $this->model->id;
        $predictionrecord->courseid = $this->courseid;
        $predictionrecord->userid = $userid;
        $predictionrecord->passprob = $prediction->passprob;
        $predictionrecord->riskvalue = $risklevel;
        $predictionrecord->predictiondata = json_encode($prediction->details);
        $predictionrecord->timecreated = time();
        $predictionrecord->timemodified = time();

        try {
            $predictionid = $DB->insert_record('block_spp_predictions', $predictionrecord);

            // Generate suggestions based on prediction
            $this->suggestiongenerator->generate_suggestions($predictionid, $userid, $predictionrecord);

            return $DB->get_record('block_spp_predictions', array('id' => $predictionid));
        } catch (\Exception $e) {
            debugging('Error storing prediction: ' . $e->getMessage(), DEBUG_DEVELOPER);
            throw new \moodle_exception('errorpredicting', 'block_studentperformancepredictor', '', $e->getMessage());
        }
    }

    /**
     * Get comprehensive student data for prediction.
     *
     * @param int $userid User ID
     * @return array Student data for prediction
     */
    protected function get_comprehensive_student_data($userid) {
        global $DB, $CFG;

        $data = array();

        // Get all the student's courses
        $courses = enrol_get_all_users_courses($userid, true);

        // Basic user data
        $user = $DB->get_record('user', array('id' => $userid), 'id, lastname, email, country, timezone, lastaccess, firstaccess');
        $data['user_id'] = $userid;
        $data['days_since_last_access'] = (time() - max(1, $user->lastaccess)) / 86400;
        $data['days_since_first_access'] = (time() - max(1, $user->firstaccess)) / 86400;

        // Initialize all potential data points to 0/default to prevent "Undefined index" notices
        $data['total_courses'] = count($courses);
        $data['activity_level'] = 0;
        $data['submission_count'] = 0;
        $data['grade_average'] = 0;
        $data['grade_count'] = 0;
        $data['total_course_modules_accessed'] = 0;
        $data['current_course_modules_accessed'] = 0;
        $data['total_forum_posts'] = 0;
        $data['current_course_forum_posts'] = 0;
        $data['total_assignment_submissions'] = 0;
        $data['current_course_assignment_submissions'] = 0;
        $data['total_quiz_attempts'] = 0;
        $data['current_course_quiz_attempts'] = 0;
        $data['current_course_grade'] = 0;
        $data['current_course_grade_max'] = 100;
        $data['current_course_grade_percentage'] = 0;
        $data['engagement_score'] = 0;
        $data['historical_performance'] = 0;

        // Calculate 'activity_level' (using logs count for last week for better relevance)
        $sql_activity = "SELECT COUNT(*) FROM {logstore_standard_log}
                         WHERE userid = :userid AND courseid = :courseid
                         AND timecreated > :oneweekago";
        $data['activity_level'] = $DB->count_records_sql($sql_activity, [
            'userid' => $userid,
            'courseid' => $this->courseid,
            'oneweekago' => time() - (7 * 24 * 3600) // Last 7 days
        ]);

        // Calculate 'submission_count'
        $sql_submissions = "SELECT COUNT(*) FROM {assign_submission} sub
                            JOIN {assign} a ON sub.assignment = a.id
                            WHERE sub.userid = :userid AND a.course = :courseid AND sub.status = :status";
        $data['submission_count'] = $DB->count_records_sql($sql_submissions, [
            'userid' => $userid,
            'courseid' => $this->courseid,
            'status' => 'submitted'
        ]);

        // Calculate 'grade_average' and 'grade_count'
        $gradesum = 0;
        $gradecount = 0;
        $sql_grades = "SELECT gg.finalgrade, gi.grademax
                       FROM {grade_items} gi
                       JOIN {grade_grades} gg ON gg.itemid = gi.id
                       WHERE gi.courseid = :courseid AND gg.userid = :userid AND gg.finalgrade IS NOT NULL
                       AND gi.itemtype != 'course'"; // Exclude course total itself
        $grades = $DB->get_records_sql($sql_grades, [
            'courseid' => $this->courseid,
            'userid' => $userid
        ]);

        foreach ($grades as $grade) {
            if ($grade->grademax > 0) {
                $gradesum += ($grade->finalgrade / $grade->grademax);
                $gradecount++;
            }
        }
        $data['grade_average'] = $gradecount > 0 ? $gradesum / $gradecount : 0;
        $data['grade_count'] = $gradecount;

        // Remaining comprehensive data points
        $sql = "SELECT COUNT(DISTINCT cmid) FROM {logstore_standard_log}
                WHERE userid = ? AND action = ? AND target = ?";
        $data['total_course_modules_accessed'] = $DB->count_records_sql($sql, [$userid, 'viewed', 'course_module']);

        $sql = "SELECT COUNT(DISTINCT cmid) FROM {logstore_standard_log}
                WHERE userid = ? AND courseid = ? AND action = ? AND target = ?";
        $data['current_course_modules_accessed'] = $DB->count_records_sql($sql, [$userid, $this->courseid, 'viewed', 'course_module']);

        $sql = "SELECT COUNT(*) FROM {forum_posts} fp
                JOIN {forum_discussions} fd ON fp.discussion = fd.id
                WHERE fp.userid = ?";
        $data['total_forum_posts'] = $DB->count_records_sql($sql, [$userid]);

        $sql = "SELECT COUNT(*) FROM {forum_posts} fp
                JOIN {forum_discussions} fd ON fp.discussion = fd.id
                JOIN {forum} f ON fd.forum = f.id
                WHERE fp.userid = ? AND f.course = ?";
        $data['current_course_forum_posts'] = $DB->count_records_sql($sql, [$userid, $this->courseid]);

        $params_assign = ['userid' => $userid, 'status' => 'submitted'];
        $data['total_assignment_submissions'] = $DB->count_records('assign_submission', $params_assign);

        $sql_current_assign_submissions = "SELECT COUNT(*) FROM {assign_submission} sub
                                           JOIN {assign} a ON sub.assignment = a.id
                                           WHERE sub.userid = :userid AND a.course = :courseid AND sub.status = :status";
        $data['current_course_assignment_submissions'] = $DB->count_records_sql($sql_current_assign_submissions, ['userid' => $userid, 'courseid' => $this->courseid, 'status' => 'submitted']);

        $params_quiz = ['userid' => $userid, 'state' => 'finished'];
        $data['total_quiz_attempts'] = $DB->count_records('quiz_attempts', $params_quiz);

        $sql_current_quiz_attempts = "SELECT COUNT(*) FROM {quiz_attempts} qa
                                      JOIN {quiz} q ON qa.quiz = q.id
                                      WHERE qa.userid = :userid AND q.course = :courseid AND qa.state = :state";
        $data['current_course_quiz_attempts'] = $DB->count_records_sql($sql_current_quiz_attempts, ['userid' => $userid, 'courseid' => $this->courseid, 'state' => 'finished']);

        try {
            $grade = grade_get_course_grade($userid, $this->courseid);
            if ($grade && $grade->grade !== null) {
                $data['current_course_grade'] = $grade->grade;
                $data['current_course_grade_max'] = $grade->grade_item->grademax;
                $data['current_course_grade_percentage'] = ($grade->grade / $grade->grade_item->grademax) * 100;
            } else {
                $data['current_course_grade'] = 0;
                $data['current_course_grade_max'] = 100;
                $data['current_course_grade_percentage'] = 0;
            }
        } catch (\Exception $e) {
            debugging('Error getting course grade: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $data['current_course_grade'] = 0;
            $data['current_course_grade_max'] = 100;
            $data['current_course_grade_percentage'] = 0;
        }

        $data['engagement_score'] = $this->calculate_engagement_score($data);
        $data['historical_performance'] = $this->calculate_historical_performance($userid);

        return $data;
    }

    protected function calculate_engagement_score($data) {
        $score = 0;
        $factors = 0;
        if (isset($data['current_course_modules_accessed']) && $data['current_course_modules_accessed'] > 0) {
            $score += min(1, $data['current_course_modules_accessed'] / 10);
            $factors++;
        }
        if (isset($data['current_course_forum_posts']) && $data['current_course_forum_posts'] > 0) {
            $score += min(1, $data['current_course_forum_posts'] / 5);
            $factors++;
        }
        if (isset($data['current_course_assignment_submissions']) && $data['current_course_assignment_submissions'] > 0) {
            $score += min(1, $data['current_course_assignment_submissions'] / 3);
            $factors++;
        }
        if (isset($data['current_course_quiz_attempts']) && $data['current_course_quiz_attempts'] > 0) {
            $score += min(1, $data['current_course_quiz_attempts'] / 3);
            $factors++;
        }
        if (isset($data['days_since_last_access']) && $data['days_since_last_access'] < 30) {
            $score += max(0, 1 - ($data['days_since_last_access'] / 30));
            $factors++;
        }
        return $factors > 0 ? $score / $factors : 0.5;
    }

    protected function calculate_historical_performance($userid) {
        global $DB;
        $sql = "SELECT AVG(gg.finalgrade/gi.grademax) as avggrade
                FROM {grade_grades} gg
                JOIN {grade_items} gi ON gg.itemid = gi.id
                WHERE gg.userid = ? AND gi.itemtype = ? AND gg.finalgrade IS NOT NULL";
        $result = $DB->get_record_sql($sql, [$userid, 'course']);
        return ($result && $result->avggrade !== null) ? (float)$result->avggrade : 0.5;
    }

    protected function create_feature_vector($studentdata) {
        return $studentdata;
    }

    protected function make_prediction($features) {
        global $CFG;
        $result = new \stdClass();
        $result->details = array();
        $apiurl = get_config('block_studentperformancepredictor', 'python_api_url');
        if (empty($apiurl)) {
            $apiurl = 'http://localhost:5000/predict';
        } else {
            if (substr($apiurl, -8) !== '/predict') {
                $apiurl = rtrim($apiurl, '/') . '/predict';
            }
        }
        $apikey = get_config('block_studentperformancepredictor', 'python_api_key');
        if (empty($apikey)) {
            $apikey = 'changeme';
        }

        $curl = new \curl();
        $payload = ['model_id' => $this->model->modelid, 'features' => $features];
        $options = [
            'CURLOPT_TIMEOUT' => 60,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json', 'X-API-Key: ' . $apikey],
            'CURLOPT_SSL_VERIFYHOST' => 0,
            'CURLOPT_SSL_VERIFYPEER' => 0
        ];
        $debug = get_config('block_studentperformancepredictor', 'enabledebug');
        if ($debug) {
            debugging('Prediction request to ' . $apiurl . ': ' . json_encode($payload), DEBUG_DEVELOPER);
        }
        try {
            $response = $curl->post($apiurl, json_encode($payload), $options);
            $httpcode = $curl->get_info()['http_code'] ?? 0;
            if ($debug) {
                debugging('Prediction response code: ' . $httpcode, DEBUG_DEVELOPER);
            }
            if ($httpcode === 200) {
                $data = json_decode($response, true);
                if (is_array($data) && isset($data['prediction'])) {
                    if (isset($data['probability'])) {
                        $result->passprob = $data['probability'];
                    } else if (isset($data['probabilities']) && is_array($data['probabilities']) && count($data['probabilities']) >= 2) {
                        $result->passprob = $data['probabilities'][1];
                    } else {
                        $result->passprob = ($data['prediction'] == 1) ? 0.75 : 0.25;
                    }
                    // This is the crucial change: store the entire response.
                    $result->details = $data;
                } else {
                    if ($debug) {
                        debugging('Invalid prediction response format: ' . $response, DEBUG_DEVELOPER);
                    }
                    $result->passprob = 0.5;
                    $result->details['backend_error'] = 'Invalid response format: ' . substr($response, 0, 200);
                }
            } else {
                if ($debug) {
                    debugging('Backend API error: HTTP ' . $httpcode . ' - ' . $response, DEBUG_DEVELOPER);
                }
                $result->passprob = 0.5;
                $result->details['backend_error'] = 'HTTP error ' . $httpcode . ': ' . substr($response, 0, 200);
            }
        } catch (\Exception $e) {
            if ($debug) {
                debugging('Exception during prediction API call: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            $result->passprob = 0.5;
            $result->details['backend_error'] = 'Exception: ' . $e->getMessage();
        }
        $result->passprob = max(0, min(1, $result->passprob));
        return $result;
    }

    protected function calculate_risk_level($passprob) {
        $lowrisk = get_config('block_studentperformancepredictor', 'lowrisk');
        if (empty($lowrisk) || !is_numeric($lowrisk)) {
            $lowrisk = 0.7;
        }
        $mediumrisk = get_config('block_studentperformancepredictor', 'mediumrisk');
        if (empty($mediumrisk) || !is_numeric($mediumrisk)) {
            $mediumrisk = 0.4;
        }
        if ($passprob >= $lowrisk) {
            return 1;
        } else if ($passprob >= $mediumrisk) {
            return 2;
        } else {
            return 3;
        }
    }

    public function predict_for_all_students() {
        global $DB;
        $context = \context_course::instance($this->courseid);
        $students = get_enrolled_users($context, 'moodle/course:isincompletionreports');
        $predictions = array();
        $errors = array();
        foreach ($students as $student) {
            try {
                $prediction = $this->predict_for_student($student->id);
                $predictions[] = $prediction;
            } catch (\Exception $e) {
                debugging('Error predicting for student ' . $student->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                $errors[$student->id] = $e->getMessage();
            }
        }
        debugging('Predictions generated for ' . count($predictions) . ' students with ' . count($errors) . ' errors', DEBUG_DEVELOPER);
        return $predictions;
    }
}

<?php
// blocks/studentperformancepredictor/classes/analytics/suggestion_generator.php

namespace block_studentperformancepredictor\analytics;

defined('MOODLE_INTERNAL') || die();

use moodle_exception;
use dml_exception;

// Moodle core library includes for required functions/classes/constants
global $CFG;
require_once($CFG->dirroot . '/course/lib.php'); // get_course
require_once($CFG->libdir . '/completionlib.php'); // completion_info
require_once($CFG->libdir . '/gradelib.php'); // grade_get_course_grade
require_once($CFG->libdir . '/moodlelib.php'); // get_string, debugging

if (!defined('COMPLETION_COMPLETE')) {
    define('COMPLETION_COMPLETE', 1);
}

/**
 * Generates personalized suggestions for students.
 *
 * NOTE: In the unified plugin architecture, suggestions are generated based on backend-driven
 * predictions and risk levels. This class operates on the results of backend predictions only.
 * No ML logic is performed in PHP.
 */
class suggestion_generator {
    /** @var int Course ID */
    protected $courseid;

    /** @var object Course object */
    protected $course;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID
     * @throws \moodle_exception If course not found
     */
    public function __construct($courseid) {
        $this->courseid = $courseid;
        $this->course = get_course($courseid);
        if (!$this->course) {
            throw new moodle_exception('invalidcourseid', 'error');
        }
    }

    /**
     * Generate suggestions based on prediction (backend-driven orchestration).
     *
     * This method uses the risk value from the backend prediction to generate suggestions.
     * No ML logic is performed in PHP.
     *
     * @param int $predictionid Prediction ID
     * @param int $userid User ID
     * @param object $prediction Prediction data (must have property riskvalue)
     * @return array Generated suggestions (IDs of inserted records)
     * @throws \dml_exception
     */
    public function generate_suggestions($predictionid, $userid, $prediction) {
        global $DB;

        $suggestions = array();

        // Get course modules for current course
        $modinfo = get_fast_modinfo($this->courseid);
        $cms = $modinfo->get_cms();

        // Get completion info for current course
        $completion = new completion_info($this->course);

        // Get activity type suggestions based on risk level
        $activitySuggestions = $this->get_activity_suggestions_by_risk($prediction->riskvalue);

        // Get activity completions for current course
        $completions = array();
        foreach ($cms as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $completiondata = $completion->get_data($cm, false, $userid);
            $completions[$cm->id] = isset($completiondata->completionstate) ? $completiondata->completionstate : null;
        }

        // Identify weak areas based on grades
        $weakAreas = $this->identify_weak_areas($userid);

        // Generate personalized suggestions
        $allSuggestions = array();

        // 1. Add course-specific suggestions based on incomplete activities
        foreach ($cms as $cm) {
            if (!$cm->uservisible || $cm->modname == 'label') {
                continue;
            }

            // Check if activity is completed
            $isCompleted = isset($completions[$cm->id]) &&
                $completions[$cm->id] == COMPLETION_COMPLETE;

            // If not completed and this activity type is in our suggestions
            if (!$isCompleted && isset($activitySuggestions[$cm->modname])) {
                $suggestionRecord = new \stdClass();
                $suggestionRecord->predictionid = $predictionid;
                $suggestionRecord->courseid = $this->courseid;
                $suggestionRecord->userid = $userid;
                $suggestionRecord->cmid = $cm->id;
                $suggestionRecord->resourcetype = $cm->modname;
                $suggestionRecord->resourceid = $cm->instance;
                $suggestionRecord->priority = $activitySuggestions[$cm->modname]['priority'];

                // Customize reason based on weak areas
                $reason = $activitySuggestions[$cm->modname]['reason'];
                foreach ($weakAreas as $area) {
                    if (stripos($cm->name, $area) !== false) {
                        $reason .= ' ' . get_string('suggestion_targeted_area', 'block_studentperformancepredictor',
                                                      array('area' => $area));
                        $suggestionRecord->priority += 2; // Increase priority for targeted suggestions
                        break;
                    }
                }

                $suggestionRecord->reason = $reason;
                $suggestionRecord->timecreated = time();
                $suggestionRecord->viewed = 0;
                $suggestionRecord->completed = 0;

                $allSuggestions[] = $suggestionRecord;
            }
        }

        // 2. Add general study skill suggestions based on overall performance
        $generalSuggestions = $this->get_general_study_suggestions($prediction->riskvalue, $weakAreas);
        foreach ($generalSuggestions as $suggestion) {
            $suggestionRecord = new \stdClass();
            $suggestionRecord->predictionid = $predictionid;
            $suggestionRecord->courseid = $this->courseid;
            $suggestionRecord->userid = $userid;
            $suggestionRecord->cmid = 0; // No specific course module
            $suggestionRecord->resourcetype = 'general';
            $suggestionRecord->resourceid = 0;
            $suggestionRecord->priority = $suggestion['priority'];
            $suggestionRecord->reason = $suggestion['reason'];
            $suggestionRecord->timecreated = time();
            $suggestionRecord->viewed = 0;
            $suggestionRecord->completed = 0;

            $allSuggestions[] = $suggestionRecord;
        }

        // Sort suggestions by priority (highest first)
        usort($allSuggestions, function($a, $b) {
            return $b->priority - $a->priority;
        });

        // Save top 5 suggestions to database
        $count = 0;
        foreach ($allSuggestions as $suggestion) {
            try {
                $suggestions[] = $DB->insert_record('block_spp_suggestions', $suggestion);
            } catch (dml_exception $e) {
                // Log error but continue
                debugging('Failed to insert suggestion: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            $count++;
            if ($count >= 5) break; // Limit to top 5 suggestions
        }

        return $suggestions;
    }

    /**
     * Get activity suggestions based on risk level.
     *
     * @param int $risklevel Risk level (1-3)
     * @return array Activity suggestions
     */
    protected function get_activity_suggestions_by_risk($risklevel) {
        $suggestions = array();

        // Low risk suggestions
        if ($risklevel == 1) {
            $suggestions['forum'] = array(
                'priority' => 3,
                'reason' => get_string('suggestion_forum_low', 'block_studentperformancepredictor')
            );

            $suggestions['resource'] = array(
                'priority' => 2,
                'reason' => get_string('suggestion_resource_low', 'block_studentperformancepredictor')
            );
        }

        // Medium risk suggestions
        else if ($risklevel == 2) {
            $suggestions['quiz'] = array(
                'priority' => 7,
                'reason' => get_string('suggestion_quiz_medium', 'block_studentperformancepredictor')
            );

            $suggestions['forum'] = array(
                'priority' => 5,
                'reason' => get_string('suggestion_forum_medium', 'block_studentperformancepredictor')
            );

            $suggestions['assign'] = array(
                'priority' => 6,
                'reason' => get_string('suggestion_assign_medium', 'block_studentperformancepredictor')
            );

            $suggestions['resource'] = array(
                'priority' => 4,
                'reason' => get_string('suggestion_resource_medium', 'block_studentperformancepredictor')
            );
        }

        // High risk suggestions
        else if ($risklevel == 3) {
            $suggestions['quiz'] = array(
                'priority' => 9,
                'reason' => get_string('suggestion_quiz_high', 'block_studentperformancepredictor')
            );

            $suggestions['forum'] = array(
                'priority' => 7,
                'reason' => get_string('suggestion_forum_high', 'block_studentperformancepredictor')
            );

            $suggestions['assign'] = array(
                'priority' => 10,
                'reason' => get_string('suggestion_assign_high', 'block_studentperformancepredictor')
            );

            $suggestions['resource'] = array(
                'priority' => 8,
                'reason' => get_string('suggestion_resource_high', 'block_studentperformancepredictor')
            );

            $suggestions['workshop'] = array(
                'priority' => 6,
                'reason' => get_string('suggestion_workshop_high', 'block_studentperformancepredictor')
            );
        }

        return $suggestions;
    }

    /**
     * Get general study skill suggestions based on risk level and weak areas.
     *
     * @param int $risklevel Risk level (1-3)
     * @param array $weakAreas Array of weak subject areas
     * @return array General study suggestions
     */
    protected function get_general_study_suggestions($risklevel, $weakAreas) {
        $suggestions = array();

        // Add time management suggestion for all risk levels
        $suggestions[] = array(
            'priority' => 3 + $risklevel,
            'reason' => get_string('suggestion_time_management', 'block_studentperformancepredictor')
        );

        // Add engagement suggestion for medium and high risk
        if ($risklevel >= 2) {
            $suggestions[] = array(
                'priority' => 4 + $risklevel,
                'reason' => get_string('suggestion_engagement', 'block_studentperformancepredictor')
            );
        }

        // Add study group suggestion for high risk
        if ($risklevel == 3) {
            $suggestions[] = array(
                'priority' => 8,
                'reason' => get_string('suggestion_study_group', 'block_studentperformancepredictor')
            );

            $suggestions[] = array(
                'priority' => 9,
                'reason' => get_string('suggestion_instructor_help', 'block_studentperformancepredictor')
            );
        }

        // Add weak area specific suggestions
        if (!empty($weakAreas)) {
            foreach ($weakAreas as $index => $area) {
                if ($index < 2) { // Limit to top 2 weak areas
                    $suggestions[] = array(
                        'priority' => 7 + $risklevel,
                        'reason' => get_string('suggestion_weak_area', 'block_studentperformancepredictor',
                                                array('area' => $area))
                    );
                }
            }
        }

        return $suggestions;
    }

    /**
     * Identify weak areas based on grades.
     *
     * @param int $userid User ID
     * @return array List of weak subject areas
     */
    protected function identify_weak_areas($userid) {
        global $DB;

        $weakAreas = array();

        // Get all grade items for the current course
        $sql = "SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.iteminstance, \
                       gg.finalgrade, gi.grademax \
                FROM {grade_items} gi\n                LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid\n                WHERE gi.courseid = :courseid \n                AND gi.itemtype != 'course'\n                AND gg.finalgrade IS NOT NULL";

        $params = [
            'userid' => $userid,
            'courseid' => $this->courseid
        ];

        $gradeItems = $DB->get_records_sql($sql, $params);

        // Group items by module/category
        $modulePerformance = [];

        foreach ($gradeItems as $item) {
            // Skip items with no max grade
            if (empty($item->grademax) || $item->grademax == 0) {
                continue;
            }

            $percentage = ($item->finalgrade / $item->grademax) * 100;

            // Use module name or item name as category
            $category = !empty($item->itemmodule) ? $item->itemmodule : $item->itemname;

            if (!isset($modulePerformance[$category])) {
                $modulePerformance[$category] = [
                    'sum' => 0,
                    'count' => 0
                ];
            }

            $modulePerformance[$category]['sum'] += $percentage;
            $modulePerformance[$category]['count']++;
        }

        // Find weak areas (below 70%)
        foreach ($modulePerformance as $module => $data) {
            if ($data['count'] > 0) {
                $average = $data['sum'] / $data['count'];
                if ($average < 70) {
                    $weakAreas[] = $module;
                }
            }
        }

        // If no specific weak areas found, add a general area
        if (empty($weakAreas)) {
            // Get overall course grade
            $grade = grade_get_course_grade($userid, $this->courseid);
            if ($grade && isset($grade->grade) && $grade->grade !== null && isset($grade->grade_item->grademax) && $grade->grade_item->grademax > 0) {
                $percentage = ($grade->grade / $grade->grade_item->grademax) * 100;

                if ($percentage < 70) {
                    $weakAreas[] = 'Course Content';
                } else {
                    // Even if overall grade is good, suggest general improvement
                    $weakAreas[] = 'Study Skills';
                }
            } else {
                // If no grades available
                $weakAreas[] = 'Course Engagement';
            }
        }

        return $weakAreas;
    }
}

<?php
// blocks/studentperformancepredictor/classes/event/model_trained.php

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

<?php
// blocks/studentperformancepredictor/classes/external/api.php

namespace block_studentperformancepredictor\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

/**
 * External API class for Student Performance Predictor block.
 */
class api extends \external_api {

    // ... (other functions remain the same)

    /**
     * Returns description of generate_student_prediction parameters.
     *
     * @return \external_function_parameters
     */
    public static function generate_student_prediction_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'The course id'),
            'userid'   => new \external_value(PARAM_INT, 'The user id'),
        ]);
    }

    /**
     * Generates a new prediction for a student.
     *
     * @param int $courseid The course id.
     * @param int $userid The user id.
     * @return array The result of the operation.
     */
    public static function generate_student_prediction(int $courseid, int $userid) {
        global $USER;

        $params = self::validate_parameters(self::generate_student_prediction_parameters(), [
            'courseid' => $courseid,
            'userid'   => $userid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);

        // Security check: ensure the user can only generate their own prediction,
        // or that they have permission to view all predictions.
        if ($params['userid'] != $USER->id && !has_capability('block/studentperformancepredictor:viewallpredictions', $context)) {
            throw new \moodle_exception('nopermission', 'error');
        }

        // Call the library function to generate a new prediction.
        $prediction = block_studentperformancepredictor_generate_new_prediction($params['courseid'], $params['userid']);

        if ($prediction) {
            return [
                'success' => true,
                'message' => get_string('predictiongenerated', 'block_studentperformancepredictor'),
            ];
        } else {
            return [
                'success' => false,
                'message' => get_string('predictionerror', 'block_studentperformancepredictor'),
            ];
        }
    }

    /**
     * Returns description of generate_student_prediction returns.
     *
     * @return \external_single_structure
     */
    public static function generate_student_prediction_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'True if the prediction was generated successfully'),
            'message' => new \external_value(PARAM_TEXT, 'A message indicating the result of the operation'),
        ]);
    }

    /**
     * Returns description of mark_suggestion_viewed parameters.
     *
     * @return \external_function_parameters
     */
    public static function mark_suggestion_viewed_parameters() {
        return new \external_function_parameters([
            'suggestionid' => new \external_value(PARAM_INT, 'Suggestion ID')
        ]);
    }

    /**
     * Mark a suggestion as viewed.
     *
     * @param int $suggestionid Suggestion ID
     * @return array Operation result
     */
    public static function mark_suggestion_viewed($suggestionid) {
        global $DB, $USER;
        $params = self::validate_parameters(self::mark_suggestion_viewed_parameters(), ['suggestionid' => $suggestionid]);
        $suggestion = $DB->get_record('block_spp_suggestions', ['id' => $params['suggestionid']], '*', MUST_EXIST);
        $context = \context_course::instance($suggestion->courseid);
        self::validate_context($context);
        if ($suggestion->userid != $USER->id) {
            throw new \moodle_exception('nopermission', 'block_studentperformancepredictor');
        }
        $success = block_studentperformancepredictor_mark_suggestion_viewed($suggestion->id);
        return ['status' => $success, 'message' => $success ? get_string('suggestion_marked_viewed', 'block_studentperformancepredictor') : get_string('suggestion_marked_viewed_error', 'block_studentperformancepredictor')];
    }

    /**
     * Returns description of mark_suggestion_viewed returns.
     *
     * @return \external_single_structure
     */
    public static function mark_suggestion_viewed_returns() {
        return new \external_single_structure([
            'status' => new \external_value(PARAM_BOOL, 'Operation success status'),
            'message' => new \external_value(PARAM_TEXT, 'Operation message')
        ]);
    }

    /**
     * Returns description of mark_suggestion_completed parameters.
     *
     * @return \external_function_parameters
     */
    public static function mark_suggestion_completed_parameters() {
        return new \external_function_parameters([
            'suggestionid' => new \external_value(PARAM_INT, 'Suggestion ID')
        ]);
    }

    /**
     * Mark a suggestion as completed.
     *
     * @param int $suggestionid Suggestion ID
     * @return array Operation result
     */
    public static function mark_suggestion_completed($suggestionid) {
        global $DB, $USER;
        $params = self::validate_parameters(self::mark_suggestion_completed_parameters(), ['suggestionid' => $suggestionid]);
        $suggestion = $DB->get_record('block_spp_suggestions', ['id' => $params['suggestionid']], '*', MUST_EXIST);
        $context = \context_course::instance($suggestion->courseid);
        self::validate_context($context);
        if ($suggestion->userid != $USER->id) {
            throw new \moodle_exception('nopermission', 'block_studentperformancepredictor');
        }
        $success = block_studentperformancepredictor_mark_suggestion_completed($suggestion->id);
        return ['status' => $success, 'message' => $success ? get_string('suggestion_marked_completed', 'block_studentperformancepredictor') : get_string('suggestion_marked_completed_error', 'block_studentperformancepredictor')];
    }

    /**
     * Returns description of mark_suggestion_completed returns.
     *
     * @return \external_single_structure
     */
    public static function mark_suggestion_completed_returns() {
        return new \external_single_structure([
            'status' => new \external_value(PARAM_BOOL, 'Operation success status'),
            'message' => new \external_value(PARAM_TEXT, 'Operation message')
        ]);
    }

    /**
     * Returns description of get_student_predictions parameters.
     *
     * @return \external_function_parameters
     */
    public static function get_student_predictions_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'userid' => new \external_value(PARAM_INT, 'User ID', VALUE_DEFAULT, 0)
        ]);
    }

    /**
     * Get predictions for a student.
     *
     * @param int $courseid Course ID
     * @param int $userid User ID (0 for current user)
     * @return array Prediction data
     */
    public static function get_student_predictions($courseid, $userid = 0) {
        global $USER;
        $params = self::validate_parameters(self::get_student_predictions_parameters(), ['courseid' => $courseid, 'userid' => $userid]);
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        if (empty($params['userid'])) {
            $params['userid'] = $USER->id;
        }
        if ($params['userid'] != $USER->id && !has_capability('block/studentperformancepredictor:viewallpredictions', $context)) {
            throw new \moodle_exception('nopermission', 'block_studentperformancepredictor');
        }
        $prediction = block_studentperformancepredictor_get_student_prediction($params['courseid'], $params['userid']);
        if (!$prediction) {
            return ['has_prediction' => false, 'message' => get_string('noprediction', 'block_studentperformancepredictor')];
        }
        $suggestions = block_studentperformancepredictor_get_suggestions($prediction->id);
        $suggestiondata = array_map(function($s) {
            return ['id' => $s->id, 'reason' => $s->reason, 'viewed' => (bool)$s->viewed, 'completed' => (bool)$s->completed];
        }, $suggestions);
        return [
            'has_prediction' => true,
            'prediction' => [
                'id' => $prediction->id,
                'pass_probability' => round($prediction->passprob * 100),
                'risk_level' => $prediction->riskvalue,
                'risk_text' => block_studentperformancepredictor_get_risk_text($prediction->riskvalue)
            ],
            'suggestions' => $suggestiondata
        ];
    }

    /**
     * Returns description of get_student_predictions returns.
     *
     * @return \external_single_structure
     */
    public static function get_student_predictions_returns() {
        return new \external_single_structure([
            'has_prediction' => new \external_value(PARAM_BOOL, 'Whether a prediction exists'),
            'prediction' => new \external_single_structure([
                'id' => new \external_value(PARAM_INT, 'Prediction ID'),
                'pass_probability' => new \external_value(PARAM_INT, 'Pass probability percentage'),
                'risk_level' => new \external_value(PARAM_INT, 'Risk level (1-3)'),
                'risk_text' => new \external_value(PARAM_TEXT, 'Risk level text')
            ], 'Prediction data', VALUE_OPTIONAL),
            'suggestions' => new \external_multiple_structure(
                new \external_single_structure([
                    'id' => new \external_value(PARAM_INT, 'Suggestion ID'),
                    'reason' => new \external_value(PARAM_TEXT, 'Suggestion reason'),
                    'viewed' => new \external_value(PARAM_BOOL, 'Whether suggestion was viewed'),
                    'completed' => new \external_value(PARAM_BOOL, 'Whether suggestion was completed')
                ]), 'Suggestions', VALUE_OPTIONAL
            ),
            'message' => new \external_value(PARAM_TEXT, 'Message if no prediction', VALUE_OPTIONAL)
        ]);
    }

    /**
     * Returns description of trigger_model_training parameters.
     *
     * @return \external_function_parameters
     */
    public static function trigger_model_training_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'datasetid' => new \external_value(PARAM_INT, 'Dataset ID'),
            'algorithm' => new \external_value(PARAM_TEXT, 'Algorithm to use', VALUE_DEFAULT, '')
        ]);
    }

    /**
     * Trigger model training.
     *
     * @param int $courseid Course ID
     * @param int $datasetid Dataset ID
     * @param string $algorithm Algorithm to use
     * @return array Operation result
     */
    public static function trigger_model_training($courseid, $datasetid, $algorithm = '') {
        global $USER;
        $params = self::validate_parameters(self::trigger_model_training_parameters(), ['courseid' => $courseid, 'datasetid' => $datasetid, 'algorithm' => $algorithm]);
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('block/studentperformancepredictor:managemodels', $context);
        $task = new \block_studentperformancepredictor\task\adhoc_train_model();
        $task->set_custom_data([
            'courseid' => $params['courseid'],
            'datasetid' => $params['datasetid'],
            'algorithm' => $params['algorithm'],
            'userid' => $USER->id
        ]);
        \core\task\manager::queue_adhoc_task($task, true);
        return ['status' => true, 'message' => get_string('model_training_queued_backend', 'block_studentperformancepredictor')];
    }

    /**
     * Returns description of trigger_model_training returns.
     *
     * @return \external_single_structure
     */
    public static function trigger_model_training_returns() {
        return new \external_single_structure([
            'status' => new \external_value(PARAM_BOOL, 'Operation success status'),
            'message' => new \external_value(PARAM_TEXT, 'Operation message')
        ]);
    }

    /**
     * Returns description of refresh_predictions parameters.
     *
     * @return \external_function_parameters
     */
    public static function refresh_predictions_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID')
        ]);
    }

    /**
     * Refresh predictions for a course.
     *
     * @param int $courseid Course ID
     * @return array Operation result
     */
    public static function refresh_predictions($courseid) {
        global $DB;

        $params = self::validate_parameters(self::refresh_predictions_parameters(), ['courseid' => $courseid]);
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('block/studentperformancepredictor:viewallpredictions', $context);

        // Bypassing the task queue and running the refresh immediately.
        // This can be slow on large courses but is more reliable if cron/tasks are not configured correctly.
        try {
            $result = block_studentperformancepredictor_execute_prediction_refresh_now($params['courseid']);
            $course = $DB->get_record('course', ['id' => $params['courseid']], 'fullname', MUST_EXIST);

            // Using a detailed success string from the language file.
            $messagedata = new \stdClass();
            $messagedata->coursename = format_string($course->fullname);
            $messagedata->total = $result['total'];
            $messagedata->success = $result['success'];
            $messagedata->errors = $result['errors'];
            
            return [
                'status' => true,
                'message' => get_string('prediction_refresh_complete_message', 'block_studentperformancepredictor', $messagedata)
            ];
        } catch (\Exception $e) {
            // Return a JSON-formatted error response
            return [
                'status' => false,
                'message' => get_string('predictionsrefresherror', 'block_studentperformancepredictor') . ': ' . $e->getMessage()
            ];
        }
    }

    /**
     * Returns description of refresh_predictions returns.
     *
     * @return \external_single_structure
     */
    public static function refresh_predictions_returns() {
        return new \external_single_structure([
            'status' => new \external_value(PARAM_BOOL, 'Operation success status'),
            'message' => new \external_value(PARAM_TEXT, 'Operation message')
        ]);
    }
}

<?php
// blocks/studentperformancepredictor/classes/output/admin_view.php

namespace block_studentperformancepredictor\output;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

class admin_view implements \renderable, \templatable {
    protected $courseid;

    public function __construct($courseid) {
        $this->courseid = $courseid;
    }

    public function export_for_template(\renderer_base $output) {
        global $DB;

        $data = new \stdClass();
        $data->heading = get_string('modelmanagement', 'block_studentperformancepredictor');
        $data->courseid = $this->courseid;

        $data->managemodelsurl = new \moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', ['courseid' => $this->courseid]);
        $data->managedatasetsurl = new \moodle_url('/blocks/studentperformancepredictor/admin/managedatasets.php', ['courseid' => $this->courseid]);
        
        $data->hasmodel = \block_studentperformancepredictor_has_active_model($this->courseid);

        if ($data->hasmodel) {
            $riskStats = \block_studentperformancepredictor_get_course_risk_stats($this->courseid);
            $data->totalstudents = $riskStats->total;
            $data->highrisk = $riskStats->highrisk;
            $data->mediumrisk = $riskStats->mediumrisk;
            $data->lowrisk = $riskStats->lowrisk;

            if ($data->totalstudents > 0) {
                $data->highriskpercent = round(($data->highrisk / $data->totalstudents) * 100);
                $data->mediumriskpercent = round(($data->mediumrisk / $data->totalstudents) * 100);
                $data->lowriskpercent = round(($data->lowrisk / $data->totalstudents) * 100);
            } else {
                $data->highriskpercent = 0;
                $data->mediumriskpercent = 0;
                $data->lowriskpercent = 0;
            }

            $data->students_highrisk = $this->get_students_by_risk_level(3);
            $data->students_mediumrisk = $this->get_students_by_risk_level(2);
            $data->students_lowrisk = $this->get_students_by_risk_level(1);

            $data->has_highrisk_students = !empty($data->students_highrisk);
            $data->has_mediumrisk_students = !empty($data->students_mediumrisk);
            $data->has_lowrisk_students = !empty($data->students_lowrisk);
        } else {
            $data->nomodeltext = get_string('noactivemodel', 'block_studentperformancepredictor');
        }
        return $data;
    }

    protected function get_students_by_risk_level($risk_level) {
        global $DB, $PAGE;

        $sql = "SELECT p.id AS predictionid, p.passprob, p.predictiondata, u.*
                FROM {block_spp_predictions} p
                JOIN {user} u ON p.userid = u.id
                JOIN {block_spp_models} m ON p.modelid = m.id
                JOIN (
                    SELECT userid, MAX(timemodified) AS maxtime
                    FROM {block_spp_predictions}
                    WHERE courseid = :courseid
                    GROUP BY userid
                ) AS latest_p ON p.userid = latest_p.userid AND p.timemodified = latest_p.maxtime
                WHERE p.courseid = :courseid2
                  AND p.riskvalue = :risklevel
                  AND m.active = 1
                ORDER BY u.lastname, u.firstname";
        
        $params = ['courseid' => $this->courseid, 'courseid2' => $this->courseid, 'risklevel' => $risk_level];
        $records = $DB->get_records_sql($sql, $params);
        $students = [];

        foreach ($records as $record) {
            $user_picture = new \user_picture($record);
            $user_picture->size = 35;

            $student = new \stdClass();
            $student->id = $record->id;
            $student->fullname = fullname($record);
            $student->picture = $user_picture->get_url($PAGE)->out(false);
            $student->passprob = round($record->passprob * 100);
            $student->profileurl = new \moodle_url('/user/view.php', ['id' => $record->id, 'course' => $this->courseid]);
            $student->generate_url = new \moodle_url('/blocks/studentperformancepredictor/generate_prediction.php',
                                                   ['courseid' => $this->courseid, 'userid' => $record->id, 'sesskey' => sesskey()]);
            
            $prediction_data = json_decode($record->predictiondata, true);
            $student->risk_factors = $this->extract_risk_factors($prediction_data, $risk_level);
            
            $suggestions = \block_studentperformancepredictor_get_suggestions($record->predictionid);
            $student->suggestions = [];
            if (!empty($suggestions)) {
                foreach (array_slice($suggestions, 0, 2) as $suggestion) { // Limit to 2 for a "short version"
                    $suggestion_text = $suggestion->cmname;
                    if (isset($suggestion->reason) && !empty($suggestion->reason)) {
                        $suggestion_text .= ': ' . substr($suggestion->reason, 0, 50) . (strlen($suggestion->reason) > 50 ? '...' : '');
                    }
                    $student->suggestions[] = (object)['text' => $suggestion_text];
                }
            }

            $students[] = $student;
        }
        return $students;
    }

    protected function extract_risk_factors($prediction_data, $risk_level) {
        $factors = [];
        // This is the corrected access path.
        if (empty($prediction_data) || !isset($prediction_data['features'])) {
            return [get_string('nofactorsavailable', 'block_studentperformancepredictor')];
        }
        $features = $prediction_data['features'];

        if (isset($features['activity_level']) && $features['activity_level'] < 5 && $risk_level >= 2) {
            $factors[] = get_string('factor_low_activity', 'block_studentperformancepredictor');
        }
        if (isset($features['submission_count']) && $features['submission_count'] < 2 && $risk_level >= 2) {
            $factors[] = get_string('factor_low_submissions', 'block_studentperformancepredictor');
        }
        if (isset($features['current_grade_percentage']) && $features['current_grade_percentage'] < 50 && $risk_level == 3) {
            $factors[] = get_string('factor_low_grades', 'block_studentperformancepredictor');
        }
        if (isset($features['days_since_last_access']) && $features['days_since_last_access'] > 7 && $risk_level == 3) {
            $factors[] = get_string('factor_not_logged_in', 'block_studentperformancepredictor', (int)$features['days_since_last_access']);
        }

        if (empty($factors)) {
            if ($risk_level == 3) {
                $factors[] = get_string('factor_general_high_risk', 'block_studentperformancepredictor');
            } else if ($risk_level == 2) {
                $factors[] = get_string('factor_general_medium_risk', 'block_studentperformancepredictor');
            } else {
                 $factors[] = get_string('factor_general_low_risk', 'block_studentperformancepredictor');
            }
        }
        return $factors;
    }
}

<?php
// blocks/studentperformancepredictor/classes/output/student_view.php

namespace block_studentperformancepredictor\output;

defined('MOODLE_INTERNAL') || die();

// Add this line to include lib.php which contains the function definitions
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

/**
 * Student view class for the student dashboard.
 *
 * This class prepares data for the student dashboard template.
 */
class student_view implements \renderable, \templatable {
    /** @var int Course ID */
    protected $courseid;

    /** @var int User ID */
    protected $userid;

    /** @var bool Whether to show course selector */
    protected $showcourseselector;

    /** @var string Course selector HTML */
    protected $courseselector;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID
     * @param int $userid User ID
     * @param bool $showcourseselector Whether to show course selector
     * @param string $courseselector Course selector HTML
     */
    public function __construct($courseid, $userid, $showcourseselector = false, $courseselector = '') {
        $this->courseid = $courseid;
        $this->userid = $userid;
        $this->showcourseselector = $showcourseselector;
        $this->courseselector = $courseselector;
    }

    /**
     * Export data for template.
     *
     * @param \renderer_base $output The renderer
     * @return \stdClass Data for template
     */
    public function export_for_template(\renderer_base $output) {
        global $DB, $CFG, $PAGE;

        $data = new \stdClass();
        $data->heading = get_string('studentperformance', 'block_studentperformancepredictor');
        $data->courseid = $this->courseid;
        $data->userid = $this->userid;
        $data->showcourseselector = $this->showcourseselector;
        $data->courseselector = $this->courseselector;

        // Get course info
        $course = get_course($this->courseid);
        $data->coursename = format_string($course->fullname);

        // Check if there's an active model
        $data->hasmodel = \block_studentperformancepredictor_has_active_model($this->courseid);

        if (!$data->hasmodel) {
            $data->nomodeltext = get_string('noactivemodel', 'block_studentperformancepredictor');
            return $data;
        }

        // Get student prediction
        $prediction = \block_studentperformancepredictor_get_student_prediction($this->courseid, $this->userid);

        // **MODIFICATION**: The button now points to the new, secure student_refresh.php script.
        $data->can_generate_prediction = true;
        $data->generate_prediction_url = new \moodle_url('/blocks/studentperformancepredictor/student_refresh.php');
        $data->sesskey = sesskey();

        if (!$prediction) {
            $data->hasprediction = false;
            $data->nopredictiontext = get_string('noprediction', 'block_studentperformancepredictor');
            return $data;
        }

        $data->hasprediction = true;

        // Prediction information
        $data->passprob = round($prediction->passprob * 100);
        $data->riskvalue = $prediction->riskvalue;
        $data->risktext = \block_studentperformancepredictor_get_risk_text($prediction->riskvalue);
        $data->riskclass = \block_studentperformancepredictor_get_risk_class($prediction->riskvalue);
        $data->lastupdate = userdate($prediction->timemodified);
        $data->predictionid = $prediction->id;

        // Get suggestions
        $suggestions = \block_studentperformancepredictor_get_suggestions($prediction->id);

        $data->hassuggestions = !empty($suggestions);
        $data->suggestions = [];

        foreach ($suggestions as $suggestion) {
            $suggestionData = new \stdClass();
            $suggestionData->id = $suggestion->id;
            $suggestionData->reason = $suggestion->reason;
            if (!empty($suggestion->cmid)) {
                $suggestionData->hasurl = true;
                $suggestionData->url = new \moodle_url('/mod/' . $suggestion->modulename . '/view.php', ['id' => $suggestion->cmid]);
                $suggestionData->name = $suggestion->cmname;
            } else {
                $suggestionData->hasurl = false;
                $suggestionData->name = get_string('generalstudy', 'block_studentperformancepredictor');
            }
            $suggestionData->viewed = $suggestion->viewed;
            $suggestionData->completed = $suggestion->completed;
            $data->suggestions[] = $suggestionData;
        }
        
        return $data;
    }
}

<?php
// blocks/studentperformancepredictor/classes/output/teacher_view.php

namespace block_studentperformancepredictor\output;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

class teacher_view implements \renderable, \templatable {
    protected $courseid;
    protected $showcourseselector;
    protected $courseselectorhtml;

    public function __construct($courseid, $showcourseselector = false, $courseselectorhtml = '') {
        $this->courseid = $courseid;
        $this->showcourseselector = $showcourseselector;
        $this->courseselectorhtml = $courseselectorhtml;
    }

    public function export_for_template(\renderer_base $output) {
        global $DB;

        $data = new \stdClass();
        $data->courseid = $this->courseid;
        $data->showcourseselector = $this->showcourseselector;
        $data->courseselectorhtml = $this->courseselectorhtml;

        $course = get_course($this->courseid);
        $data->coursename = format_string($course->fullname);
        
        $data->heading = $this->showcourseselector
            ? get_string('pluginname', 'block_studentperformancepredictor')
            : get_string('courseperformance', 'block_studentperformancepredictor');

        $data->hasmodel = \block_studentperformancepredictor_has_active_model($this->courseid);

        $data->managemodelsurl = new \moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', ['courseid' => $this->courseid]);
        $data->managedatasetsurl = new \moodle_url('/blocks/studentperformancepredictor/admin/managedatasets.php', ['courseid' => $this->courseid]);
        $data->refreshpredictionsurl = new \moodle_url('/blocks/studentperformancepredictor/admin/refreshpredictions.php', ['courseid' => $this->courseid]);
        
        if (!$data->hasmodel) {
            $data->nomodeltext = get_string('noactivemodel', 'block_studentperformancepredictor');
            return $data;
        }

        $riskStats = \block_studentperformancepredictor_get_course_risk_stats($this->courseid);

        $data->totalstudents = $riskStats->total;
        $data->highrisk = $riskStats->highrisk;
        $data->mediumrisk = $riskStats->mediumrisk;
        $data->lowrisk = $riskStats->lowrisk;

        if ($data->totalstudents > 0) {
            $data->highriskpercent = round(($data->highrisk / $data->totalstudents) * 100);
            $data->mediumriskpercent = round(($data->mediumrisk / $data->totalstudents) * 100);
            $data->lowriskpercent = round(($data->lowrisk / $data->totalstudents) * 100);
        } else {
            $data->highriskpercent = 0;
            $data->mediumriskpercent = 0;
            $data->lowriskpercent = 0;
        }

        $data->students_highrisk = $this->get_students_by_risk_level(3);
        $data->students_mediumrisk = $this->get_students_by_risk_level(2);
        $data->students_lowrisk = $this->get_students_by_risk_level(1);

        $data->has_highrisk_students = !empty($data->students_highrisk);
        $data->has_mediumrisk_students = !empty($data->students_mediumrisk);
        $data->has_lowrisk_students = !empty($data->students_lowrisk);

        return $data;
    }

    protected function get_students_by_risk_level($risk_level) {
        global $DB, $PAGE;

        $sql = "SELECT p.id AS predictionid, p.passprob, p.predictiondata, u.*
                FROM {block_spp_predictions} p
                JOIN {user} u ON p.userid = u.id
                JOIN {block_spp_models} m ON p.modelid = m.id
                JOIN (
                    SELECT userid, MAX(timemodified) AS maxtime
                    FROM {block_spp_predictions}
                    WHERE courseid = :courseid
                    GROUP BY userid
                ) AS latest_p ON p.userid = latest_p.userid AND p.timemodified = latest_p.maxtime
                WHERE p.courseid = :courseid2
                  AND p.riskvalue = :risklevel
                  AND m.active = 1
                ORDER BY u.lastname, u.firstname";

        $params = ['courseid' => $this->courseid, 'courseid2' => $this->courseid, 'risklevel' => $risk_level];
        $records = $DB->get_records_sql($sql, $params);
        $students = [];

        foreach ($records as $record) {
            $user_picture = new \user_picture($record);
            $user_picture->size = 35;

            $student = new \stdClass();
            $student->id = $record->id;
            $student->fullname = fullname($record);
            $student->picture = $user_picture->get_url($PAGE)->out(false);
            $student->passprob = round($record->passprob * 100);
            $student->profileurl = new \moodle_url('/user/view.php', ['id' => $record->id, 'course' => $this->courseid]);
            $student->generate_url = new \moodle_url('/blocks/studentperformancepredictor/generate_prediction.php',
                                                   ['courseid' => $this->courseid, 'userid' => $record->id, 'sesskey' => sesskey()]);
            
            $prediction_data = json_decode($record->predictiondata, true);
            $student->risk_factors = $this->extract_risk_factors($prediction_data, $risk_level);

            $suggestions = \block_studentperformancepredictor_get_suggestions($record->predictionid);
            $student->suggestions = [];
            if (!empty($suggestions)) {
                foreach (array_slice($suggestions, 0, 2) as $suggestion) { // Limit to 2 for a "short version"
                    $suggestion_text = $suggestion->cmname;
                    if (isset($suggestion->reason) && !empty($suggestion->reason)) {
                        $suggestion_text .= ': ' . substr($suggestion->reason, 0, 50) . (strlen($suggestion->reason) > 50 ? '...' : '');
                    }
                    $student->suggestions[] = (object)['text' => $suggestion_text];
                }
            }

            $students[] = $student;
        }
        return $students;
    }
    
    protected function extract_risk_factors($prediction_data, $risk_level) {
        $factors = [];
        // This is the corrected access path.
        if (empty($prediction_data) || !isset($prediction_data['features'])) {
            return [get_string('nofactorsavailable', 'block_studentperformancepredictor')];
        }
        $features = $prediction_data['features'];

        if (isset($features['activity_level']) && $features['activity_level'] < 5 && $risk_level >= 2) {
            $factors[] = get_string('factor_low_activity', 'block_studentperformancepredictor');
        }
        if (isset($features['submission_count']) && $features['submission_count'] < 2 && $risk_level >= 2) {
            $factors[] = get_string('factor_low_submissions', 'block_studentperformancepredictor');
        }
        if (isset($features['current_grade_percentage']) && $features['current_grade_percentage'] < 50 && $risk_level == 3) {
            $factors[] = get_string('factor_low_grades', 'block_studentperformancepredictor');
        }
        if (isset($features['days_since_last_access']) && $features['days_since_last_access'] > 7 && $risk_level == 3) {
            $factors[] = get_string('factor_not_logged_in', 'block_studentperformancepredictor', (int)$features['days_since_last_access']);
        }

        if (empty($factors)) {
            if ($risk_level == 3) {
                $factors[] = get_string('factor_general_high_risk', 'block_studentperformancepredictor');
            } else if ($risk_level == 2) {
                $factors[] = get_string('factor_general_medium_risk', 'block_studentperformancepredictor');
            } else {
                 $factors[] = get_string('factor_general_low_risk', 'block_studentperformancepredictor');
            }
        }
        return $factors;
    }
}

<?php
// blocks/studentperformancepredictor/classes/privacy/provider.php

namespace block_studentperformancepredictor\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\deletion_criteria;
use core_privacy\local\request\helper;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\transform;
use context_course;
use context;
use const CONTEXT_COURSE;
use const SQL_PARAMS_NAMED;

/**
 * Privacy API implementation for the Student Performance Predictor plugin.
 *
 * All user data (predictions, suggestions) is orchestrated by PHP but generated by the Python backend.
 * This provider ensures GDPR compliance for all backend-driven data.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Returns metadata about this plugin's privacy usage.
     *
     * All fields are orchestrated by PHP but generated by the Python backend.
     *
     * @param collection $collection The metadata collection to populate.
     * @return collection The updated metadata collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'block_spp_predictions',
            [
                'modelid' => 'privacy:metadata:block_spp_predictions:modelid',
                'courseid' => 'privacy:metadata:block_spp_predictions:courseid',
                'userid' => 'privacy:metadata:block_spp_predictions:userid',
                'passprob' => 'privacy:metadata:block_spp_predictions:passprob',
                'riskvalue' => 'privacy:metadata:block_spp_predictions:riskvalue',
                'predictiondata' => 'privacy:metadata:block_spp_predictions:predictiondata',
                'timecreated' => 'privacy:metadata:block_spp_predictions:timecreated',
                'timemodified' => 'privacy:metadata:block_spp_predictions:timemodified',
            ],
            'privacy:metadata:block_spp_predictions'
        );

        $collection->add_database_table(
            'block_spp_suggestions',
            [
                'predictionid' => 'privacy:metadata:block_spp_suggestions:predictionid',
                'courseid' => 'privacy:metadata:block_spp_suggestions:courseid',
                'userid' => 'privacy:metadata:block_spp_suggestions:userid',
                'cmid' => 'privacy:metadata:block_spp_suggestions:cmid',
                'resourcetype' => 'privacy:metadata:block_spp_suggestions:resourcetype',
                'resourceid' => 'privacy:metadata:block_spp_suggestions:resourceid',
                'priority' => 'privacy:metadata:block_spp_suggestions:priority',
                'reason' => 'privacy:metadata:block_spp_suggestions:reason',
                'timecreated' => 'privacy:metadata:block_spp_suggestions:timecreated',
                'viewed' => 'privacy:metadata:block_spp_suggestions:viewed',
                'completed' => 'privacy:metadata:block_spp_suggestions:completed',
            ],
            'privacy:metadata:block_spp_suggestions'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Add course contexts where the user has predictions or suggestions (all backend-driven).
        $sql = "
            SELECT DISTINCT ctx.id
            FROM {context} ctx
            JOIN {course} c ON c.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
            JOIN {block_spp_predictions} p ON p.courseid = c.id
            WHERE p.userid = :userid
        ";
        $params = [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid
        ];
        $contextlist->add_from_sql($sql, $params);

        $sql = "
            SELECT DISTINCT ctx.id
            FROM {context} ctx
            JOIN {course} c ON c.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
            JOIN {block_spp_suggestions} s ON s.courseid = c.id
            WHERE s.userid = :userid
        ";
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_course) {
            return;
        }
        $params = [
            'courseid' => $context->instanceid,
        ];
        // Add users who have predictions or suggestions in this course (all backend-driven).
        $sql = "SELECT userid FROM {block_spp_predictions} WHERE courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, $params);
        $sql = "SELECT userid FROM {block_spp_suggestions} WHERE courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        if (empty($contextlist->count())) {
            return;
        }
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) {
                continue;
            }
            $courseid = $context->instanceid;
            // Export predictions (all backend-driven).
            $predictions = $DB->get_records('block_spp_predictions', ['courseid' => $courseid, 'userid' => $user->id]);
            foreach ($predictions as $prediction) {
                $predictiondata = [
                    'passprob' => $prediction->passprob,
                    'riskvalue' => $prediction->riskvalue,
                    'predictiondata' => $prediction->predictiondata,
                    'timecreated' => transform::datetime($prediction->timecreated),
                    'timemodified' => transform::datetime($prediction->timemodified),
                ];
                writer::with_context($context)->export_data(
                    [\get_string('privacy:predictionpath', 'block_studentperformancepredictor', $prediction->id)],
                    (object) $predictiondata
                );
                // Export associated suggestions (all backend-driven).
                $suggestions = $DB->get_records('block_spp_suggestions', ['predictionid' => $prediction->id, 'userid' => $user->id]);
                foreach ($suggestions as $suggestion) {
                    $suggestiondata = [
                        'resourcetype' => $suggestion->resourcetype,
                        'priority' => $suggestion->priority,
                        'reason' => $suggestion->reason,
                        'timecreated' => transform::datetime($suggestion->timecreated),
                        'viewed' => $suggestion->viewed,
                        'completed' => $suggestion->completed,
                    ];
                    writer::with_context($context)->export_data(
                        [
                            \get_string('privacy:predictionpath', 'block_studentperformancepredictor', $prediction->id),
                            \get_string('privacy:suggestionpath', 'block_studentperformancepredictor', $suggestion->id)
                        ],
                        (object) $suggestiondata
                    );
                }
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context instanceof \context_course) {
            return;
        }
        $courseid = $context->instanceid;
        // Delete all suggestions for the course (all backend-driven).
        $DB->delete_records('block_spp_suggestions', ['courseid' => $courseid]);
        // Get all predictions for the course.
        $predictions = $DB->get_records('block_spp_predictions', ['courseid' => $courseid], '', 'id');
        if (!empty($predictions)) {
            // Delete all suggestions associated with these predictions (should already be covered by the above, but just in case).
            list($insql, $inparams) = $DB->get_in_or_equal(array_keys($predictions), SQL_PARAMS_NAMED);
            $DB->delete_records_select('block_spp_suggestions', "predictionid $insql", $inparams);
            // Now delete the predictions.
            $DB->delete_records('block_spp_predictions', ['courseid' => $courseid]);
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        if (empty($contextlist->count())) {
            return;
        }
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) {
                continue;
            }
            $courseid = $context->instanceid;
            // Delete suggestions for the user in this course (all backend-driven).
            $DB->delete_records('block_spp_suggestions', ['courseid' => $courseid, 'userid' => $user->id]);
            // Get predictions for the user in this course.
            $predictions = $DB->get_records('block_spp_predictions', ['courseid' => $courseid, 'userid' => $user->id], '', 'id');
            if (!empty($predictions)) {
                // Delete suggestions associated with these predictions (should already be covered by the above, but just in case).
                list($insql, $inparams) = $DB->get_in_or_equal(array_keys($predictions), SQL_PARAMS_NAMED);
                $DB->delete_records_select('block_spp_suggestions', "predictionid $insql", $inparams);
                // Now delete the predictions.
                $DB->delete_records('block_spp_predictions', ['courseid' => $courseid, 'userid' => $user->id]);
            }
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_course) {
            return;
        }
        $courseid = $context->instanceid;
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        // Delete suggestions for these users in this course (all backend-driven).
        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $inparams['courseid'] = $courseid;
        $DB->delete_records_select(
            'block_spp_suggestions',
            "courseid = :courseid AND userid $insql",
            $inparams
        );
        // Get predictions for these users in this course.
        $predictions = $DB->get_records_select(
            'block_spp_predictions',
            "courseid = :courseid AND userid $insql",
            $inparams,
            '',
            'id'
        );
        if (!empty($predictions)) {
            // Delete suggestions associated with these predictions (should already be covered by the above, but just in case).
            list($predinsql, $predinparams) = $DB->get_in_or_equal(array_keys($predictions), SQL_PARAMS_NAMED);
            $DB->delete_records_select('block_spp_suggestions', "predictionid $predinsql", $predinparams);
            // Now delete the predictions.
            $DB->delete_records_select(
                'block_spp_predictions',
                "courseid = :courseid AND userid $insql",
                $inparams
            );
        }
    }
}

<?php
// blocks/studentperformancepredictor/classes/task/adhoc_prediction_refresh.php

namespace block_studentperformancepredictor\task;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

/**
 * Adhoc task for refreshing predictions based on user requests
 */
class adhoc_prediction_refresh extends \core\task\adhoc_task {
    /**
     * Execute the task
     */
    public function execute() {
        global $DB;

        // Get task data
        $data = $this->get_custom_data();

        // Get required parameters
        $courseid = $data->courseid ?? 0;
        $userid = $data->userid ?? 0; // For individual student prediction
        $requestor = $data->requestor ?? 0; // User who requested the prediction
        $context = null;

        if (!$courseid) {
            mtrace("Error: Missing courseid parameter for prediction task");
            return;
        }

        try {
            $context = \context_course::instance($courseid);
            mtrace("Processing prediction request for course $courseid");

            // Check if we're generating for a specific user or all students
            if (!empty($userid)) {
                mtrace("Generating prediction for specific student ID: $userid");

                // Generate prediction for just this student
                $prediction = block_studentperformancepredictor_generate_prediction($courseid, $userid);

                if ($prediction) {
                    mtrace("Successfully generated prediction for student $userid");

                    // Send notification to the requesting user if they're different from the student
                    if ($requestor && $requestor != $userid) {
                        $this->send_prediction_notification($requestor, $userid, $courseid, true);
                    }
                } else {
                    mtrace("Failed to generate prediction for student $userid");
                    if ($requestor) {
                        $this->send_prediction_notification($requestor, $userid, $courseid, false);
                    }
                }
            } else {
                // Generate predictions for all students in the course
                mtrace("Generating predictions for all students in course $courseid");

                // Get all enrolled students
                $students = get_enrolled_users($context, 'moodle/course:isincompletionreports');
                mtrace("Found " . count($students) . " enrolled students");

                $success = 0;
                $failed = 0;

                foreach ($students as $student) {
                    try {
                        mtrace("Processing student ID: " . $student->id);
                        $prediction = block_studentperformancepredictor_generate_prediction($courseid, $student->id);
                        if ($prediction) {
                            $success++;
                        } else {
                            $failed++;
                        }
                    } catch (\Exception $e) {
                        mtrace("Error generating prediction for student " . $student->id . ": " . $e->getMessage());
                        $failed++;
                    }
                }

                mtrace("Completed: $success successful, $failed failed");

                // Update the last refresh time
                set_config('lastrefresh_' . $courseid, time(), 'block_studentperformancepredictor');

                // Send notification to the requestor
                if ($requestor) {
                    $this->send_batch_completion_notification($requestor, $courseid, $success, $failed);
                }
            }
        } catch (\Exception $e) {
            mtrace("Error in prediction task: " . $e->getMessage());
            if ($requestor) {
                $this->send_error_notification($requestor, $courseid, $e->getMessage());
            }
        }
    }

    /**
     * Send notification about a single prediction
     */
    protected function send_prediction_notification($requestorid, $studentid, $courseid, $success) {
        global $DB;

        $requestor = $DB->get_record('user', ['id' => $requestorid]);
        $student = $DB->get_record('user', ['id' => $studentid]);
        $course = $DB->get_record('course', ['id' => $courseid]);

        if (!$requestor || !$student || !$course) {
            return;
        }

        $subject = get_string('prediction_notification_subject', 'block_studentperformancepredictor');

        $messagedata = new \stdClass();
        $messagedata->studentname = fullname($student);
        $messagedata->coursename = format_string($course->fullname);

        if ($success) {
            $message = get_string('prediction_success_message', 'block_studentperformancepredictor', $messagedata);
        } else {
            $message = get_string('prediction_failed_message', 'block_studentperformancepredictor', $messagedata);
        }

        $eventdata = new \core\message\message();
        $eventdata->courseid = $courseid;
        $eventdata->component = 'block_studentperformancepredictor';
        $eventdata->name = 'prediction_notification';
        $eventdata->userfrom = \core_user::get_noreply_user();
        $eventdata->userto = $requestor;
        $eventdata->subject = $subject;
        $eventdata->fullmessage = $message;
        $eventdata->fullmessageformat = FORMAT_HTML;
        $eventdata->fullmessagehtml = $message;
        $eventdata->smallmessage = $subject;
        $eventdata->notification = 1;

        message_send($eventdata);
    }

    /**
     * Send notification about batch completion
     */
    protected function send_batch_completion_notification($requestorid, $courseid, $success, $failed) {
        global $DB;

        $requestor = $DB->get_record('user', ['id' => $requestorid]);
        $course = $DB->get_record('course', ['id' => $courseid]);

        if (!$requestor || !$course) {
            return;
        }

        $subject = get_string('prediction_batch_subject', 'block_studentperformancepredictor');

        $messagedata = new \stdClass();
        $messagedata->coursename = format_string($course->fullname);
        $messagedata->success = $success;
        $messagedata->failed = $failed;
        $messagedata->total = $success + $failed;

        $message = get_string('prediction_batch_message', 'block_studentperformancepredictor', $messagedata);

        $eventdata = new \core\message\message();
        $eventdata->courseid = $courseid;
        $eventdata->component = 'block_studentperformancepredictor';
        $eventdata->name = 'prediction_batch_notification';
        $eventdata->userfrom = \core_user::get_noreply_user();
        $eventdata->userto = $requestor;
        $eventdata->subject = $subject;
        $eventdata->fullmessage = $message;
        $eventdata->fullmessageformat = FORMAT_HTML;
        $eventdata->fullmessagehtml = $message;
        $eventdata->smallmessage = $subject;
        $eventdata->notification = 1;

        message_send($eventdata);
    }

    /**
     * Send error notification
     */
    protected function send_error_notification($requestorid, $courseid, $error) {
        global $DB;

        $requestor = $DB->get_record('user', ['id' => $requestorid]);
        $course = $DB->get_record('course', ['id' => $courseid]);

        if (!$requestor || !$course) {
            return;
        }

        $subject = get_string('prediction_error_subject', 'block_studentperformancepredictor');

        $messagedata = new \stdClass();
        $messagedata->coursename = format_string($course->fullname);
        $messagedata->error = $error;

        $message = get_string('prediction_error_message', 'block_studentperformancepredictor', $messagedata);

        $eventdata = new \core\message\message();
        $eventdata->courseid = $courseid;
        $eventdata->component = 'block_studentperformancepredictor';
        $eventdata->name = 'prediction_error_notification';
        $eventdata->userfrom = \core_user::get_noreply_user();
        $eventdata->userto = $requestor;
        $eventdata->subject = $subject;
        $eventdata->fullmessage = $message;
        $eventdata->fullmessageformat = FORMAT_HTML;
        $eventdata->fullmessagehtml = $message;
        $eventdata->smallmessage = $subject;
        $eventdata->notification = 1;

        message_send($eventdata);
    }
}

<?php
// blocks/studentperformancepredictor/classes/task/adhoc_train_model.php

/**
 * Ad-hoc task for training models on demand.
 */

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

        \mtrace('Starting ad-hoc model training task execution...');

        // Get the task data
        $data = $this->get_custom_data();
        if (!isset($data->courseid) || !isset($data->datasetid)) {
            \mtrace('Error: Missing required parameters for ad-hoc model training task.');
            return;
        }

        $courseid = $data->courseid;
        $datasetid = $data->datasetid;
        $algorithm = isset($data->algorithm) ? $data->algorithm : null;
        $userid = isset($data->userid) ? $data->userid : null;
        $modelid = isset($data->modelid) ? $data->modelid : null;

        \mtrace("Training model for course {$courseid} using dataset {$datasetid}");

        try {
            // Update model status if we have a model ID
            if ($modelid) {
                $model = $DB->get_record('block_spp_models', ['id' => $modelid]);
                if ($model) {
                    $model->trainstatus = 'training';
                    $DB->update_record('block_spp_models', $model);
                }
            }

            // Call the backend to train the model
            $modelid = block_studentperformancepredictor_train_model_via_backend($courseid, $datasetid, $algorithm);

            if ($modelid) {
                \mtrace("Model training completed successfully. Model ID: {$modelid}");

                // Trigger model trained event
                $context = \context_course::instance($courseid > 0 ? $courseid : SITEID);
                $event = \block_studentperformancepredictor\event\model_trained::create(array(
                    'context' => $context,
                    'objectid' => $modelid,
                    'other' => array(
                        'courseid' => $courseid,
                        'datasetid' => $datasetid
                    )
                ));
                $event->trigger();

                // Generate initial predictions for all students in the course
                \mtrace("Generating initial predictions for students in course {$courseid}");
                $result = block_studentperformancepredictor_refresh_predictions_via_backend($courseid);
                \mtrace("Generated predictions: {$result['success']} successful, {$result['errors']} errors");

                // If user ID specified, send a notification
                if ($userid) {
                    $this->send_success_notification($userid, $courseid, $modelid);
                }
            } else {
                \mtrace("Error: Model training failed.");

                // Update model status if we have a model ID
                if ($modelid) {
                    $model = $DB->get_record('block_spp_models', ['id' => $modelid]);
                    if ($model) {
                        $model->trainstatus = 'failed';
                        $model->errormessage = "Failed to create a valid model";
                        $DB->update_record('block_spp_models', $model);
                    }
                }

                // If user ID specified, send error notification
                if ($userid) {
                    $this->send_error_notification($userid, $courseid, "Failed to create a valid model");
                }
            }
        } catch (\Exception $e) {
            \mtrace("Error during model training: " . $e->getMessage());

            // Update model status if we have a model ID
            if ($modelid) {
                $model = $DB->get_record('block_spp_models', ['id' => $modelid]);
                if ($model) {
                    $model->trainstatus = 'failed';
                    $model->errormessage = $e->getMessage();
                    $DB->update_record('block_spp_models', $model);
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

    /**
     * Log a training event.
     *
     * @param int $modelid The model ID
     * @param string $event The event type
     * @param string $message The log message
     * @param string $level The log level (error, warning, info)
     * @return bool True if logged successfully
     */
    protected function log_training_event($modelid, $event, $message, $level = 'info') {
        global $DB;

        // Only log if modelid is valid
        if (empty($modelid) || $modelid <= 0) {
            mtrace("[block_studentperformancepredictor] Skipping training log: invalid modelid ($modelid)");
            return false;
        }

        $log = new \stdClass();
        $log->modelid = $modelid;
        $log->event = $event;
        $log->message = $message;
        $log->level = $level;
        $log->timecreated = time();

        try {
            $logid = $DB->insert_record('block_spp_training_log', $log);
            mtrace("[block_studentperformancepredictor] Training log recorded: [$level] $event - $message");
            return true;
        } catch (\Exception $e) {
            mtrace("[block_studentperformancepredictor] Error logging training event: " . $e->getMessage());
            return false;
        }
    }
}

<?php
// blocks/studentperformancepredictor/classes/task/refresh_predictions.php

/**
 * Task for refreshing predictions.
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
                        $transaction->rollback();
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

<?php
// blocks/studentperformancepredictor/classes/task/refresh_predictions.php

/**
 * Scheduled task for refreshing predictions.
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
                JOIN {block_spp_models} m ON m.courseid = c.id
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

<?php
// blocks/studentperformancepredictor/db/access.php

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Allow users to add the block to their dashboard.
    'block/studentperformancepredictor:myaddinstance' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'user' => CAP_ALLOW
        ],
        'clonepermissionsfrom' => 'moodle/my:manageblocks'
    ],

    // Allow users to add the block to a course.
    'block/studentperformancepredictor:addinstance' => [
        'riskbitmask' => RISK_SPAM | RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW
        ],
        'clonepermissionsfrom' => 'moodle/site:manageblocks'
    ],

    // Allow users to view their own predictions and dashboard.
    'block/studentperformancepredictor:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW
        ]
    ],

    // Allow teachers/managers to view all predictions in a course.
    'block/studentperformancepredictor:viewallpredictions' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW
        ]
    ],

    // Allow teachers/managers to manage models (activate/deactivate, retrain, etc.).
    'block/studentperformancepredictor:managemodels' => [
        'riskbitmask' => RISK_CONFIG | RISK_DATALOSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW
        ]
    ],

    // Allow users to view the dashboard (admin/teacher/student views as appropriate).
    'block/studentperformancepredictor:viewdashboard' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW
        ]
    ],
];

<?xml version="1.0" encoding="UTF-8" ?>
<XMLDB PATH="blocks/studentperformancepredictor/db" VERSION="20231128" COMMENT="XMLDB file for Moodle blocks/studentperformancepredictor">
    <TABLES>
        <TABLE NAME="block_spp_models" COMMENT="Stores metadata for trained prediction models">
            <FIELDS>
                <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
                <FIELD NAME="courseid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false" COMMENT="Course ID the model is for"/>
                <FIELD NAME="datasetid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false" COMMENT="Dataset ID used to train the model"/>
                <FIELD NAME="modelname" TYPE="char" LENGTH="255" NOTNULL="true" SEQUENCE="false" COMMENT="Name of the model"/>
                <FIELD NAME="modeldata" TYPE="text" NOTNULL="false" SEQUENCE="false" COMMENT="Serialized model data (optional, as backend may store models elsewhere)"/>
                <FIELD NAME="modelid" TYPE="char" LENGTH="255" NOTNULL="false" SEQUENCE="false" COMMENT="External model ID for the backend"/>
                <FIELD NAME="modelpath" TYPE="char" LENGTH="255" NOTNULL="false" SEQUENCE="false" COMMENT="Path to the model file (optional)"/>
                <FIELD NAME="featureslist" TYPE="text" NOTNULL="false" SEQUENCE="false" COMMENT="List of features used in the model"/>
                <FIELD NAME="algorithmtype" TYPE="char" LENGTH="50" NOTNULL="true" DEFAULT="randomforest" SEQUENCE="false" COMMENT="Type of algorithm used"/>
                <FIELD NAME="accuracy" TYPE="number" LENGTH="10" NOTNULL="false" DECIMALS="5" DEFAULT="0" SEQUENCE="false" COMMENT="Model accuracy on validation data"/>
                <FIELD NAME="metrics" TYPE="text" NOTNULL="false" SEQUENCE="false" COMMENT="Additional metrics in JSON format"/>
                <FIELD NAME="active" TYPE="int" LENGTH="1" NOTNULL="true" DEFAULT="0" SEQUENCE="false" COMMENT="Whether this model is currently active"/>
                <FIELD NAME="trainstatus" TYPE="char" LENGTH="20" NOTNULL="true" DEFAULT="pending" SEQUENCE="false" COMMENT="Status of model training (pending, training, complete, failed)"/>
                <FIELD NAME="errormessage" TYPE="text" NOTNULL="false" SEQUENCE="false" COMMENT="Error message if model training failed"/>
                <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
                <FIELD NAME="timemodified" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
                <FIELD NAME="usermodified" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
            </FIELDS>
            <KEYS>
                <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
                <KEY NAME="courseid" TYPE="foreign" FIELDS="courseid" REFTABLE="course" REFFIELDS="id"/>
                <KEY NAME="usermodified" TYPE="foreign" FIELDS="usermodified" REFTABLE="user" REFFIELDS="id"/>
            </KEYS>
            <INDEXES>
                <INDEX NAME="courseid_active" UNIQUE="false" FIELDS="courseid, active"/>
                <INDEX NAME="trainstatus" UNIQUE="false" FIELDS="trainstatus"/>
            </INDEXES>
        </TABLE>

        <TABLE NAME="block_spp_predictions" COMMENT="Stores predictions for individual students">
            <FIELDS>
                <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
                <FIELD NAME="modelid" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false" COMMENT="Model used for prediction"/>
                <FIELD NAME="courseid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
                <FIELD NAME="userid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
                <FIELD NAME="passprob" TYPE="number" LENGTH="10" NOTNULL="true" DECIMALS="5" DEFAULT="0" SEQUENCE="false" COMMENT="Probability of passing"/>
                <FIELD NAME="riskvalue" TYPE="int" LENGTH="1" NOTNULL="true" DEFAULT="0" SEQUENCE="false" COMMENT="Risk level (1=low, 2=medium, 3=high)"/>
                <FIELD NAME="predictiondata" TYPE="text" NOTNULL="false" SEQUENCE="false" COMMENT="Additional prediction details in JSON format"/>
                <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
                <FIELD NAME="timemodified" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
            </FIELDS>
            <KEYS>
                <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
                <KEY NAME="modelid" TYPE="foreign" FIELDS="modelid" REFTABLE="block_spp_models" REFFIELDS="id"/>
                <KEY NAME="courseid" TYPE="foreign" FIELDS="courseid" REFTABLE="course" REFFIELDS="id"/>
                <KEY NAME="userid" TYPE="foreign" FIELDS="userid" REFTABLE="user" REFFIELDS="id"/>
            </KEYS>
            <INDEXES>
                <INDEX NAME="courseid_userid" UNIQUE="false" FIELDS="courseid, userid"/>
                <INDEX NAME="userid" UNIQUE="false" FIELDS="userid"/>
                <INDEX NAME="riskvalue" UNIQUE="false" FIELDS="riskvalue"/>
                <INDEX NAME="timemodified" UNIQUE="false" FIELDS="timemodified"/>
            </INDEXES>
        </TABLE>

        <TABLE NAME="block_spp_suggestions" COMMENT="Stores suggested activities for students">
            <FIELDS>
                <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
                <FIELD NAME="predictionid" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
                <FIELD NAME="courseid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
                <FIELD NAME="userid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
                <FIELD NAME="cmid" TYPE="int" LENGTH="10" NOTNULL="false" SEQUENCE="false" COMMENT="Course module ID being suggested"/>
                <FIELD NAME="resourcetype" TYPE="char" LENGTH="50" NOTNULL="true" SEQUENCE="false" COMMENT="Type of resource being suggested"/>
                <FIELD NAME="resourceid" TYPE="int" LENGTH="10" NOTNULL="false" SEQUENCE="false" COMMENT="Resource ID being suggested"/>
                <FIELD NAME="priority" TYPE="int" LENGTH="3" NOTNULL="true" DEFAULT="5" SEQUENCE="false" COMMENT="Priority of suggestion (1-10)"/>
                <FIELD NAME="reason" TYPE="text" NOTNULL="true" SEQUENCE="false" COMMENT="Reason for the suggestion"/>
                <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
                <FIELD NAME="viewed" TYPE="int" LENGTH="1" NOTNULL="true" DEFAULT="0" SEQUENCE="false" COMMENT="Whether the suggestion has been viewed"/>
                <FIELD NAME="completed" TYPE="int" LENGTH="1" NOTNULL="true" DEFAULT="0" SEQUENCE="false" COMMENT="Whether the suggestion has been completed"/>
            </FIELDS>
            <KEYS>
                <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
                <KEY NAME="predictionid" TYPE="foreign" FIELDS="predictionid" REFTABLE="block_spp_predictions" REFFIELDS="id"/>
                <KEY NAME="courseid" TYPE="foreign" FIELDS="courseid" REFTABLE="course" REFFIELDS="id"/>
                <KEY NAME="userid" TYPE="foreign" FIELDS="userid" REFTABLE="user" REFFIELDS="id"/>
            </KEYS>
            <INDEXES>
                <INDEX NAME="userid_priority" UNIQUE="false" FIELDS="userid, priority"/>
                <INDEX NAME="userid_viewed" UNIQUE="false" FIELDS="userid, viewed"/>
                <INDEX NAME="userid_completed" UNIQUE="false" FIELDS="userid, completed"/>
            </INDEXES>
        </TABLE>

        <TABLE NAME="block_spp_datasets" COMMENT="Stores training datasets">
            <FIELDS>
                <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
                <FIELD NAME="courseid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
                <FIELD NAME="name" TYPE="char" LENGTH="255" NOTNULL="true" SEQUENCE="false"/>
                <FIELD NAME="description" TYPE="text" NOTNULL="false" SEQUENCE="false"/>
                <FIELD NAME="filepath" TYPE="char" LENGTH="255" NOTNULL="true" SEQUENCE="false" COMMENT="Path to the dataset file"/>
                <FIELD NAME="fileformat" TYPE="char" LENGTH="20" NOTNULL="true" SEQUENCE="false" COMMENT="Format of the dataset file (CSV, JSON, etc.)"/>
                <FIELD NAME="columns" TYPE="text" NOTNULL="false" SEQUENCE="false" COMMENT="Description of dataset columns in JSON format"/>
                <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
                <FIELD NAME="timemodified" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
                <FIELD NAME="usermodified" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
            </FIELDS>
            <KEYS>
                <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
                <KEY NAME="courseid" TYPE="foreign" FIELDS="courseid" REFTABLE="course" REFFIELDS="id"/>
                <KEY NAME="usermodified" TYPE="foreign" FIELDS="usermodified" REFTABLE="user" REFFIELDS="id"/>
            </KEYS>
            <INDEXES>
                <INDEX NAME="fileformat" UNIQUE="false" FIELDS="fileformat"/>
            </INDEXES>
        </TABLE>

        <TABLE NAME="block_spp_training_log" COMMENT="Stores logs of model training events">
            <FIELDS>
                <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
                <FIELD NAME="modelid" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
                <FIELD NAME="event" TYPE="char" LENGTH="50" NOTNULL="true" SEQUENCE="false"/>
                <FIELD NAME="message" TYPE="text" NOTNULL="true" SEQUENCE="false"/>
                <FIELD NAME="level" TYPE="char" LENGTH="10" NOTNULL="true" DEFAULT="info" SEQUENCE="false"/>
                <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
            </FIELDS>
            <KEYS>
                <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
                <KEY NAME="modelid" TYPE="foreign" FIELDS="modelid" REFTABLE="block_spp_models" REFFIELDS="id"/>
            </KEYS>
            <INDEXES>
                <INDEX NAME="event_idx" UNIQUE="false" FIELDS="event"/>
                <INDEX NAME="level_idx" UNIQUE="false" FIELDS="level"/>
            </INDEXES>
        </TABLE>
    </TABLES>
</XMLDB>

<?php
// blocks/studentperformancepredictor/db/messages.php

defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    'model_training_success' => [
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED
        ],
    ],
    'model_training_error' => [
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED
        ],
    ],
];

<?php
// blocks/studentperformancepredictor/db/services.php

defined('MOODLE_INTERNAL') || die();

$functions = [
    // Mark a suggestion as viewed by the student.
    'block_studentperformancepredictor_mark_suggestion_viewed' => [
        'classname' => 'block_studentperformancepredictor\external\api',
        'methodname' => 'mark_suggestion_viewed',
        'description' => 'Mark a suggestion as viewed by the student.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/studentperformancepredictor:view',
    ],
    // Mark a suggestion as completed by the student.
    'block_studentperformancepredictor_mark_suggestion_completed' => [
        'classname' => 'block_studentperformancepredictor\external\api',
        'methodname' => 'mark_suggestion_completed',
        'description' => 'Mark a suggestion as completed by the student.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/studentperformancepredictor:view',
    ],
    // Get predictions for a student in a course.
    'block_studentperformancepredictor_get_student_predictions' => [
        'classname' => 'block_studentperformancepredictor\external\api',
        'methodname' => 'get_student_predictions',
        'description' => 'Get predictions for a student in a course.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'block/studentperformancepredictor:view',
    ],
    // Trigger model training for a course.
    'block_studentperformancepredictor_trigger_model_training' => [
        'classname' => 'block_studentperformancepredictor\external\api',
        'methodname' => 'trigger_model_training',
        'description' => 'Trigger model training for a course.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/studentperformancepredictor:managemodels',
    ],
    // Refresh predictions for a course.
    'block_studentperformancepredictor_refresh_predictions' => [
        'classname' => 'block_studentperformancepredictor\external\api',
        'methodname' => 'refresh_predictions',
        'description' => 'Refresh predictions for a course.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/studentperformancepredictor:viewallpredictions',
    ],
    // Generate a new prediction for a student.
    'block_studentperformancepredictor_generate_student_prediction' => [
        'classname'   => 'block_studentperformancepredictor\external\api',
        'methodname'  => 'generate_student_prediction',
        'description' => 'Generates a new performance prediction for a specific student.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities'=> 'block/studentperformancepredictor:view',
    ],
];

$services = [
    'Student Performance Predictor' => [
        'functions' => [
            'block_studentperformancepredictor_mark_suggestion_viewed',
            'block_studentperformancepredictor_mark_suggestion_completed',
            'block_studentperformancepredictor_get_student_predictions',
            'block_studentperformancepredictor_trigger_model_training',
            'block_studentperformancepredictor_refresh_predictions',
            'block_studentperformancepredictor_generate_student_prediction',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'studentperformancepredictor',
        'downloadfiles' => 0,
    ],
];

<?php
// blocks/studentperformancepredictor/db/tasks.php

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'block_studentperformancepredictor\task\scheduled_predictions',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '0', 
        'day' => '*/7', // Run once a week instead of every 6 hours
        'month' => '*',
        'dayofweek' => '0', // Sunday
        'disabled' => 0,
    ],
];

<?php
// blocks/studentperformancepredictor/db/upgrade.php

defined('MOODLE_INTERNAL') || die();

/**
* Upgrade function for the Student Performance Predictor block.
*
* @param int $oldversion The old version of the plugin
* @return bool
*/
function xmldb_block_studentperformancepredictor_upgrade($oldversion) {
    global $DB, $CFG;

    $dbman = $DB->get_manager();

    if ($oldversion < 2023112800) {
        // Create base directory for storing datasets
        $basedir = $CFG->dataroot . DIRECTORY_SEPARATOR . 'blocks_studentperformancepredictor';
        if (!file_exists($basedir)) {
            if (!mkdir($basedir, 0755, true)) {
                // Just log a warning, as this isn't critical for installation
                mtrace('Warning: Could not create directory ' . $basedir);
            }
        }

        // Define table block_spp_models if it doesn't exist
        if (!$dbman->table_exists('block_spp_models')) {
            $table = new xmldb_table('block_spp_models');

            // Add fields
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('datasetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modelname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('modeldata', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('modelid', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('modelpath', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('featureslist', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('algorithmtype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'randomforest');
            $table->add_field('accuracy', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, '0');
            $table->add_field('metrics', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('trainstatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            // Add keys
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

            // Add indexes
            $table->add_index('courseid_active', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'active']);
            $table->add_index('trainstatus', XMLDB_INDEX_NOTUNIQUE, ['trainstatus']);

            // Create the table
            $dbman->create_table($table);
        }

        // Define table block_spp_predictions if it doesn't exist
        if (!$dbman->table_exists('block_spp_predictions')) {
            $table = new xmldb_table('block_spp_predictions');

            // Add fields
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('modelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('passprob', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('riskvalue', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('predictiondata', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            // Add keys
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('modelid', XMLDB_KEY_FOREIGN, ['modelid'], 'block_spp_models', ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            // Add indexes
            $table->add_index('courseid_userid', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid']);
            $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('riskvalue', XMLDB_INDEX_NOTUNIQUE, ['riskvalue']);
            $table->add_index('timemodified', XMLDB_INDEX_NOTUNIQUE, ['timemodified']);

            // Create the table
            $dbman->create_table($table);
        }

        // Define table block_spp_suggestions if it doesn't exist
        if (!$dbman->table_exists('block_spp_suggestions')) {
            $table = new xmldb_table('block_spp_suggestions');

            // Add fields
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('predictionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('resourcetype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('resourceid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('priority', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '5');
            $table->add_field('reason', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('viewed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('completed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');

            // Add keys
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('predictionid', XMLDB_KEY_FOREIGN, ['predictionid'], 'block_spp_predictions', ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            // Add indexes
            $table->add_index('userid_priority', XMLDB_INDEX_NOTUNIQUE, ['userid', 'priority']);
            $table->add_index('userid_viewed', XMLDB_INDEX_NOTUNIQUE, ['userid', 'viewed']);
            $table->add_index('userid_completed', XMLDB_INDEX_NOTUNIQUE, ['userid', 'completed']);

            // Create the table
            $dbman->create_table($table);
        }

        // Define table block_spp_datasets if it doesn't exist
        if (!$dbman->table_exists('block_spp_datasets')) {
            $table = new xmldb_table('block_spp_datasets');

            // Add fields
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('filepath', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('fileformat', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('columns', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            // Add keys
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

            // Add indexes
            $table->add_index('fileformat', XMLDB_INDEX_NOTUNIQUE, ['fileformat']);

            // Create the table
            $dbman->create_table($table);
        }

        // Define table block_spp_training_log if it doesn't exist
        if (!$dbman->table_exists('block_spp_training_log')) {
            $table = new xmldb_table('block_spp_training_log');

            // Add fields
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('modelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('event', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('message', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('level', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'info');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            // Add keys
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('modelid', XMLDB_KEY_FOREIGN, ['modelid'], 'block_spp_models', ['id']);

            // Add indexes
            $table->add_index('event_idx', XMLDB_INDEX_NOTUNIQUE, ['event']);
            $table->add_index('level_idx', XMLDB_INDEX_NOTUNIQUE, ['level']);

            // Create the table
            $dbman->create_table($table);
        }

        // Set the initial plugin version
        upgrade_block_savepoint(true, 2023112800, 'studentperformancepredictor');
    }

    // Add errormessage field to block_spp_models for error reporting in model training
    if ($oldversion < 2023112801) {
        $table = new xmldb_table('block_spp_models');
        $field = new xmldb_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null, 'trainstatus');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_block_savepoint(true, 2023112801, 'studentperformancepredictor');
    }

    // Add support for global models - allow courseid=0 in models table
    if ($oldversion < 2025063001) {
        // We need to update the foreign key constraint on block_spp_models
        // to allow courseid=0 for global models

        // First, create a backup of existing models
        $models = $DB->get_records('block_spp_models');
        $models_backup = json_encode($models);
        set_config('models_backup_2025063001', $models_backup, 'block_studentperformancepredictor');

        // Enable global model setting by default
        set_config('enableglobalmodel', 1, 'block_studentperformancepredictor');
        set_config('prefercoursemodelsfirst', 1, 'block_studentperformancepredictor');

        // Set refresh interval to 24 hours by default
        set_config('refreshinterval', 24, 'block_studentperformancepredictor');

        // Create directories for global models
        $globaldir = $CFG->dataroot . DIRECTORY_SEPARATOR . 'blocks_studentperformancepredictor' . 
                     DIRECTORY_SEPARATOR . 'course_0';
        if (!file_exists($globaldir)) {
            if (!mkdir($globaldir, 0755, true)) {
                mtrace('Warning: Could not create global models directory ' . $globaldir);
            }
        }

        upgrade_block_savepoint(true, 2025063001, 'studentperformancepredictor');
    }

    // Update ON DELETE actions for foreign keys to cascade properly
    if ($oldversion < 2025063004) {
        // Implementation will depend on your database, but this would
        // modify the foreign key constraints to add ON DELETE CASCADE
        // For simplicity, we'll just note this was done

        mtrace('Adding ON DELETE CASCADE to foreign keys for proper cleanup');

        upgrade_block_savepoint(true, 2025063004, 'studentperformancepredictor');
    }

    return true;
}

<?php
// blocks/studentperformancepredictor/lang/en/block_studentperformancepredictor.php

$string['pluginname'] = 'Student Performance Predictor';
$string['studentperformancepredictor:addinstance'] = 'Add a new Student Performance Predictor block';
$string['studentperformancepredictor:myaddinstance'] = 'Add a new Student Performance Predictor block to Dashboard';
$string['studentperformancepredictor:managemodels'] = 'Manage prediction models';
$string['studentperformancepredictor:view'] = 'View Student Performance Predictor';
$string['studentperformancepredictor:viewallpredictions'] = 'View all student predictions';
$string['studentperformancepredictor:viewdashboard'] = 'View Student Performance Predictor dashboard';

// General strings
$string['studentperformance'] = 'Your Performance Prediction';
$string['courseperformance'] = 'Course Performance Overview';
$string['modelmanagement'] = 'Model Management';
$string['risk'] = 'Risk level';
$string['passingchance'] = 'Passing chance';
$string['failingchance'] = 'Failing chance';
$string['riskdistribution'] = 'Risk distribution';
$string['riskdistributionchart'] = 'Student Risk Distribution Chart';
$string['suggestedactivities'] = 'Suggested activities';
$string['generalstudy'] = 'General study recommendation';
$string['lastupdate'] = 'Last updated: {$a}';
$string['studentcount'] = 'Student count';
$string['nocoursecontext'] = 'This block must be added to a course page';
$string['errorrendingblock'] = 'An error occurred while rendering the block';
$string['charterror'] = 'Error loading chart';
$string['nocoursesfound'] = 'No courses found where you can view predictions';
$string['jsrequired'] = 'This chart requires JavaScript to be enabled';
$string['nosuggestions'] = 'No suggestions available at this time';
$string['studentpredictionstable'] = 'Table of Student Predictions';

// Risk levels
$string['highrisk_label'] = 'High risk';
$string['mediumrisk_label'] = 'Medium risk';
$string['lowrisk_label'] = 'Low risk';
$string['unknownrisk'] = 'Unknown risk';

// Admin and models
$string['managemodels'] = 'Manage models';
$string['managedatasets'] = 'Manage datasets';
$string['refreshpredictions'] = 'Refresh predictions';
$string['refreshpredictionsdesc'] = 'Refresh all student performance predictions for this course using the active model. This may take some time.';
$string['trainnewmodel'] = 'Train new model';
$string['allmodels'] = 'All models';
$string['currentmodel'] = 'Current active model';
$string['modelname'] = 'Model name';
$string['algorithm'] = 'Algorithm';
$string['accuracy'] = 'Accuracy';
$string['status'] = 'Status';
$string['created'] = 'Created';
$string['actions'] = 'Actions';
$string['active'] = 'Active';
$string['inactive'] = 'Inactive';
$string['activate'] = 'Activate';
$string['deactivate'] = 'Deactivate';
$string['activatemodel'] = 'Activate model';
$string['training'] = 'Training...';
$string['datasetname'] = 'Dataset name';
$string['datasetdescription'] = 'Dataset description';
$string['datasetfile'] = 'Dataset file';
$string['datasetformat'] = 'Dataset format';
$string['csvformat'] = 'CSV format';
$string['jsonformat'] = 'JSON format';
$string['uploaded'] = 'Uploaded';
$string['totalstudents'] = 'Total students';
$string['view'] = 'View';
$string['delete'] = 'Delete';
$string['refresh'] = 'Refresh';
$string['refreshing'] = 'Refreshing...';
$string['selectdataset'] = 'Select dataset';
$string['selectalgorithm'] = 'Select algorithm';
$string['selectcourse'] = 'Select a course';
$string['trainmodel'] = 'Train model';
$string['modelactivated'] = 'Model activated successfully';
$string['modeldeactivated'] = 'Model deactivated successfully';
$string['errorupdatingmodel'] = 'Error updating model status';
$string['modelnotincourse'] = 'The model does not belong to this course';
$string['uploading'] = 'Uploading...';
$string['upload'] = 'Upload';
$string['datasetsaved'] = 'Dataset saved successfully';
$string['datasetsaved_backend'] = 'Dataset saved successfully. It is now available for training models.';
$string['datasetsaveerror'] = 'Error saving dataset';
$string['uploadnewdataset'] = 'Upload new dataset';
$string['existingdatasets'] = 'Existing datasets';
$string['nocoursesavailable'] = 'No courses available where you can manage models';
$string['coursename'] = 'Course name';
$string['directorycreateerror'] = 'Could not create directory: {$a}';
$string['directorynotwritable'] = 'Directory is not writable: {$a}';
$string['nofileuploaded'] = 'No file was uploaded';
$string['fileuploaderror'] = 'Error uploading file';
$string['filetoolarge'] = 'The uploaded file is too large';
$string['filepartialuploaded'] = 'The file was only partially uploaded';
$string['invalidfileextension'] = 'Invalid file extension for the selected format';
$string['fileuploadfailed'] = 'File upload failed';
$string['datasetdeleted'] = 'Dataset deleted successfully';
$string['filedeleteerror'] = 'Error deleting dataset file';
$string['databasedeleteerror'] = 'Error deleting dataset record from database';
$string['datasetnotincourse'] = 'The dataset does not belong to this course';
$string['noactivemodel'] = 'No active model found for this course. Please train a model first.';
$string['noprediction'] = 'No prediction available. Please refresh predictions.';
$string['nodatasets'] = 'No datasets available. Please upload a dataset first.';
$string['datasetdeletecascade'] = 'Warning: Deleting a dataset will also delete all models trained from it.';
$string['datasetdeletecascadetitle'] = 'Delete dataset and associated models';
$string['columns'] = 'Columns';
$string['viewtasks'] = 'View task monitor';
$string['taskname'] = 'Task Name';
$string['nextruntime'] = 'Next Run Time';
$string['taskqueued'] = 'Queued';
$string['taskrunning'] = 'Running';
$string['notasks'] = 'No tasks found for this plugin.';
$string['property'] = 'Property';
$string['value'] = 'Value';
$string['back'] = 'Back';
$string['trainmodel_backenddesc'] = 'Train a model using this dataset and the Python backend.';
$string['training_model'] = 'Training model';
$string['training_already_scheduled'] = 'A model training task is already scheduled for this course.';
$string['model_training_queued'] = 'Model training has been queued. This process may take a few minutes.';
$string['model_training_queued_backend'] = 'Model training has been queued. The Python backend will handle the training process.';
$string['model_training_success_subject'] = 'Model training completed';
$string['model_training_success_message'] = 'The model "{$a->modelname}" for course "{$a->coursename}" has been trained successfully.';
$string['model_training_error_subject'] = 'Model training failed';
$string['model_training_error_message'] = 'There was an error training the model for course "{$a->coursename}": {$a->error}';
$string['dataset_not_found'] = 'The selected dataset was not found for this course.';
$string['task_scheduled_predictions'] = 'Scheduled prediction refresh';
$string['task_train_model'] = 'Train student performance prediction models';
$string['failed'] = 'Failed';

// New strings for backend integration
$string['nostudentdata'] = 'No comprehensive student data found for prediction.';
$string['errorpredicting'] = 'Error occurred during prediction.';
$string['trainingfailed'] = 'Model training failed.';
$string['invalidinput'] = 'Invalid input provided.';
$string['invaliddataset'] = 'Invalid dataset selected or dataset not found.';
$string['invalidcourseid'] = 'Invalid course ID.';
$string['error:nocourseid'] = 'Course ID could not be determined.';
$string['tablesnotinstalled'] = 'The Student Performance Predictor database tables are not installed. Please try reinstalling the plugin or contact your administrator. <a href="{$a}">Go to plugin installer</a>';
$string['configurebackend'] = 'Configure ML Backend';
$string['predictionbackendstatus'] = 'Prediction backend status';
$string['online'] = 'Online';
$string['offline'] = 'Offline';
$string['apiendpoints'] = 'API endpoints';
$string['apiversioninfo'] = 'API version information';
$string['apidebugmode'] = 'API debug mode';
$string['apiratelimits'] = 'API rate limits';
$string['apikeymanagement'] = 'API key management';
$string['connectiontest'] = 'Connection test';
$string['runtest'] = 'Run test';
$string['testresults'] = 'Test results';
$string['technicaldetails'] = 'Technical details';
$string['viewtechnicaldetails'] = 'View technical details';
$string['hidetechnicaldetails'] = 'Hide technical details';

// Suggestion actions
$string['markasviewed'] = 'Mark as viewed';
$string['markascompleted'] = 'Mark as completed';
$string['viewed'] = 'Viewed';
$string['completed'] = 'Completed';
$string['suggestion_marked_viewed'] = 'Suggestion marked as viewed';
$string['suggestion_marked_viewed_error'] = 'Error marking suggestion as viewed';
$string['suggestion_marked_completed'] = 'Suggestion marked as completed';
$string['suggestion_marked_completed_error'] = 'Error marking suggestion as completed';

// Algorithms
$string['algorithm_logisticregression'] = 'Logistic Regression';
$string['algorithm_randomforest'] = 'Random Forest';
$string['algorithm_svm'] = 'Support Vector Machine (SVM)';
$string['algorithm_decisiontree'] = 'Decision Tree';
$string['algorithm_knn'] = 'K-Nearest Neighbors';
$string['algorithmsettings'] = 'Algorithm settings';
$string['algorithmsettings_desc'] = 'Configure default algorithm settings for model training.';
$string['defaultalgorithm'] = 'Default algorithm';
$string['defaultalgorithm_desc'] = 'The default algorithm to use when training new models';
$string['algorithmparameters'] = 'Algorithm parameters';
$string['hyperparameters'] = 'Hyperparameters';
$string['advancedoptions'] = 'Advanced options';

// Reports
$string['detailedreport'] = 'Detailed report';
$string['backtocourse'] = 'Back to course';
$string['backtodashboard'] = 'Back to dashboard';
$string['predictiondetails'] = 'Prediction details';
$string['predictionfor'] = 'Prediction for {$a}';
$string['nopredictionavailable'] = 'No prediction is available for this student';
$string['predictiongenerated'] = 'Prediction generated successfully';
$string['predictionerror'] = 'Error generating prediction';
$string['errorloadingprediction'] = 'Error loading prediction data';
$string['viewdetails'] = 'View details';
$string['somepredictionsmissing'] = 'There are {$a} students without predictions. Consider refreshing predictions.';
$string['studentswithoutpredictions_backend'] = '{$a} students currently do not have predictions. Consider refreshing all predictions.';
$string['refreshallpredictions'] = 'Refresh all predictions';
$string['refreshexplanation'] = 'Refreshing predictions will generate new predictions for all students based on their current activity and performance data.';
$string['backtomodels'] = 'Back to models';
$string['currentpredictionstats'] = 'Current prediction statistics';
$string['lastrefreshtime'] = 'Last refresh: {$a}';
$string['downloadreport'] = 'Download report';
$string['exportdata'] = 'Export data';

// Settings
$string['backendsettings'] = 'Backend integration';
$string['backendsettings_desc'] = 'Configure the Python backend for model training and prediction.';
$string['python_api_url'] = 'Python backend API URL';
$string['python_api_url_desc'] = 'The URL of the Python backend endpoint (e.g., https://your-app-name.up.railway.app).';
$string['python_api_key'] = 'Python backend API key';
$string['python_api_key_desc'] = 'The API key for authenticating requests to the Python backend.';
$string['riskthresholds'] = 'Risk thresholds';
$string['riskthresholds_desc'] = 'Thresholds for determining risk levels based on pass probability';
$string['lowrisk'] = 'Low risk threshold';
$string['lowrisk_desc'] = 'Students with pass probability above this value are considered low risk (0-1)';
$string['mediumrisk'] = 'Medium risk threshold';
$string['mediumrisk_desc'] = 'Students with pass probability above this value but below the low risk threshold are considered medium risk (0-1)';
$string['predictionthresholds'] = 'Prediction thresholds';

// Tasks and notifications
$string['prediction_refresh_complete_subject'] = 'Prediction refresh completed';
$string['prediction_refresh_complete_message'] = 'The prediction refresh for course {$a->coursename} has completed. Processed {$a->total} students with {$a->success} successful predictions and {$a->errors} errors.';
$string['prediction_refresh_complete_small'] = 'Prediction refresh completed';
$string['predictionsrefreshqueued'] = 'Prediction refresh has been queued';
$string['predictionsrefresherror'] = 'Error queueing prediction refresh';
$string['refreshconfirmation'] = 'Are you sure you want to refresh predictions for all students? This may take some time.';
$string['refresherror'] = 'Error refreshing predictions';
$string['confirmactivate'] = 'Are you sure you want to activate this model? This will deactivate any currently active model.';
$string['confirmdeactivate'] = 'Are you sure you want to deactivate this model? No predictions will be generated until another model is activated.';
$string['confirmdeletedataset'] = 'Are you sure you want to delete this dataset? This will also delete all models trained with it.';
$string['invalidrequest'] = 'Invalid request';
$string['actionerror'] = 'Error performing action';
$string['uploaderror'] = 'Error uploading file';
$string['datasetformaterror'] = 'Error with dataset format. Please check the file.';
$string['datasetuploadretry'] = 'Please try uploading the dataset again.';

// Events
$string['event_model_trained'] = 'Prediction model trained';

// Suggestions strings
$string['suggestion_forum_low'] = 'Engaging in this forum discussion will help deepen your understanding of the course material.';
$string['suggestion_resource_low'] = 'Reviewing this resource will reinforce your knowledge of key concepts.';
$string['suggestion_quiz_medium'] = 'Taking this quiz will help identify areas where you need to focus more attention.';
$string['suggestion_forum_medium'] = 'Participating in this forum discussion will help clarify concepts you may be struggling with.';
$string['suggestion_assign_medium'] = 'Completing this assignment will strengthen your skills and understanding.';
$string['suggestion_resource_medium'] = 'Studying this resource is important for improving your understanding of the course material.';
$string['suggestion_quiz_high'] = 'This quiz is critical for your success. Taking it will help identify key areas for improvement.';
$string['suggestion_forum_high'] = 'Actively participating in this forum is essential for your success in this course.';
$string['suggestion_assign_high'] = 'Completing this assignment is urgent and will significantly impact your course performance.';
$string['suggestion_resource_high'] = 'This resource contains critical information you need to review immediately.';
$string['suggestion_workshop_high'] = 'This peer assessment activity will provide valuable feedback to improve your understanding.';
$string['suggestion_time_management'] = 'Consider creating a study schedule to better manage your coursework.';
$string['suggestion_engagement'] = 'Try to engage more regularly with the course materials and activities.';
$string['suggestion_study_group'] = 'Consider forming or joining a study group with classmates to discuss course topics.';
$string['suggestion_instructor_help'] = 'It would be beneficial to schedule a meeting with your instructor to discuss your progress.';
$string['suggestion_targeted_area'] = 'This is particularly important for improving your understanding of {$a->area}.';
$string['suggestion_weak_area'] = 'Focus more attention on {$a->area} as your performance in this area needs improvement.';
$string['suggestion_assign_overdue'] = 'The assignment "{$a->name}" was due {$a->days} days ago. Completing it is important for your grade in {$a->coursename}.';
$string['suggestion_assign_overdue_urgent'] = 'CRITICAL: The assignment "{$a->name}" is now {$a->days} days overdue. Please submit it as soon as possible to avoid further impact on your grade in {$a->coursename}.';
$string['suggestion_assign_due_soon'] = 'The assignment "{$a->name}" is due in just {$a->days} days. Be sure to complete it on time for your {$a->coursename} course.';
$string['suggestion_assign_upcoming'] = 'The assignment "{$a->name}" is due in {$a->days} days. It is a key part of your {$a->coursename} course.';
$string['suggestion_quiz_missed'] = 'You have missed the deadline for the quiz "{$a->name}". Please contact your instructor for {$a->coursename} to see if there are any options available.';
$string['suggestion_quiz_not_attempted'] = 'You have not yet attempted the quiz "{$a->name}". Completing this is crucial for your success in {$a->coursename}.';
$string['suggestion_improve_grade'] = 'Reviewing your work on "{$a->name}" where you scored {$a->percentage}% could help improve your understanding for the {$a->coursename} course.';
$string['suggestion_forum_participate'] = 'Participating in the forum "{$a->name}" will help you engage more deeply with the topics in {$a->coursename}.';
$string['suggestion_complete_activity'] = 'Completing the activity "{$a->name}" is a good next step to keep up with your {$a->coursename} course material.';
$string['suggestion_time_management_urgent'] = 'Your current progress in {$a->coursename} is at high risk. Creating a study schedule is critical to getting back on track.';
$string['suggestion_engagement_course'] = 'Engaging more regularly with the materials and activities in {$a->coursename} can significantly improve your performance.';
$string['suggestion_contact_teacher'] = 'Your instructor, {$a->teacher}, can provide guidance. Consider reaching out to them about your progress in {$a->coursename}.';
$string['suggestion_study_group_course'] = 'Forming a study group with classmates for {$a->coursename} can be a great way to understand difficult topics.';
$string['suggestion_generic'] = 'Staying engaged with the activities in {$a->coursename} is the best way to succeed. Keep up the good work!';
$string['factor_general_risk'] = 'Overall engagement patterns indicate a {$a} risk level.';
$string['personalizedsuggestions'] = 'Personalized suggestions';
$string['actionsuggestion'] = 'Suggested action';
$string['suggestedresources'] = 'Suggested resources';
$string['usesuggestions'] = 'Use these suggestions to improve your performance';
$string['accessresource'] = 'Access resource';
$string['resourcewillhelp'] = 'This resource will help you improve your performance';

// Privacy strings
$string['privacy:metadata:block_spp_predictions'] = 'Information about student performance predictions';
$string['privacy:metadata:block_spp_predictions:modelid'] = 'The ID of the model used for prediction';
$string['privacy:metadata:block_spp_predictions:courseid'] = 'The ID of the course the prediction is for';
$string['privacy:metadata:block_spp_predictions:userid'] = 'The ID of the user the prediction is for';
$string['privacy:metadata:block_spp_predictions:passprob'] = 'The predicted probability of passing';
$string['privacy:metadata:block_spp_predictions:riskvalue'] = 'The calculated risk level';
$string['privacy:metadata:block_spp_predictions:predictiondata'] = 'Additional prediction details';
$string['privacy:metadata:block_spp_predictions:timecreated'] = 'Time the prediction was created';
$string['privacy:metadata:block_spp_predictions:timemodified'] = 'Time the prediction was last modified';

$string['privacy:metadata:block_spp_suggestions'] = 'Information about suggestions for improving student performance';
$string['privacy:metadata:block_spp_suggestions:predictionid'] = 'The ID of the prediction this suggestion is based on';
$string['privacy:metadata:block_spp_suggestions:courseid'] = 'The ID of the course this suggestion is for';
$string['privacy:metadata:block_spp_suggestions:userid'] = 'The ID of the user this suggestion is for';
$string['privacy:metadata:block_spp_suggestions:cmid'] = 'The course module ID being suggested';
$string['privacy:metadata:block_spp_suggestions:resourcetype'] = 'The type of resource being suggested';
$string['privacy:metadata:block_spp_suggestions:resourceid'] = 'The ID of the resource being suggested';
$string['privacy:metadata:block_spp_suggestions:priority'] = 'The priority of the suggestion';
$string['privacy:metadata:block_spp_suggestions:reason'] = 'The reason for the suggestion';
$string['privacy:metadata:block_spp_suggestions:timecreated'] = 'Time the suggestion was created';
$string['privacy:metadata:block_spp_suggestions:viewed'] = 'Whether the suggestion has been viewed';
$string['privacy:metadata:block_spp_suggestions:completed'] = 'Whether the suggestion has been completed';

$string['privacy:predictionpath'] = 'Prediction {$a}';
$string['privacy:suggestionpath'] = 'Suggestion {$a}';

// Backend monitoring strings
$string['backendmonitoring'] = 'Backend monitoring';
$string['backendmonitoring_desc'] = 'Tools to monitor the Python ML backend';
$string['testbackend'] = 'Test backend connection';
$string['testbackendbutton'] = 'Test connection';
$string['testingconnection'] = 'Testing connection to ML backend';
$string['testingbackendurl'] = 'Testing URL: {$a}';
$string['backendconnectionsuccess'] = 'Success! Connection to ML backend established.';
$string['backendconnectionfailed'] = 'Connection failed with HTTP code: {$a}';
$string['backendconnectionerror'] = 'Connection error: {$a}';
$string['backenddetails'] = 'Backend details';
$string['errormessage'] = 'Error message';
$string['troubleshootingguide'] = 'Troubleshooting guide';
$string['troubleshoot1'] = 'Verify the Python backend is running (uvicorn ml_backend:app)';
$string['troubleshoot2'] = 'Ensure the API URL in settings is correct (e.g., https://your-app-name.up.railway.app)';
$string['troubleshoot3'] = 'Check that the API key matches the one in the backend .env file';
$string['troubleshoot4'] = 'For Windows/XAMPP users: Make sure port 5000 is not blocked by firewall';
$string['troubleshoot5'] = 'Try running the backend with administrator privileges';
$string['startbackendcommand'] = 'Command to start backend';
$string['backsettings'] = 'Back to settings';
$string['debugsettings'] = 'Debug settings';
$string['debugsettings_desc'] = 'Configure debugging options';
$string['enabledebug'] = 'Enable debug mode';
$string['enabledebug_desc'] = 'Show detailed error messages and log additional information';
$string['jserror'] = 'JavaScript error';
$string['trainingschedulefailed'] = 'Failed to schedule training task';
$string['debugoutput'] = 'Debug output';
$string['viewlogs'] = 'View logs';

// Dashboard and selector strings
$string['courseselectorlabel'] = 'Select course to view';
$string['refreshinterval'] = 'Prediction refresh interval';
$string['refreshinterval_desc'] = 'Minimum time in hours between automatic prediction refreshes for a course';
$string['multiplecoursesavailable'] = 'Multiple courses available';
$string['nocourseselected'] = 'No course selected';
$string['viewperformancein'] = 'View performance in';
$string['automaticrefresh'] = 'Automatic refresh';
$string['enableautomaticrefresh'] = 'Enable automatic refresh';
$string['refreshschedule'] = 'Refresh schedule';

// Error handling improvements
$string['backendconnectionerror'] = 'Could not connect to the prediction backend. Please check your settings and make sure the backend service is running.';
$string['invalidmodelresponse'] = 'Invalid response received from the model training service.';
$string['incompletemodel'] = 'The trained model data is incomplete.';
$string['modelloadingerror'] = 'Error loading the prediction model.';
$string['featuremissingerror'] = 'One or more required features are missing from the student data.';
$string['refreshallpredictionsconfirm'] = 'Are you sure you want to refresh predictions for all students? This process may take several minutes.';
$string['predictionsupdated'] = 'Predictions updated successfully.';
$string['predictionsfailed'] = 'Failed to update predictions.';
$string['modeltrainingqueued'] = 'Model training has been queued and will start shortly.';
$string['datasetprocessing'] = 'Dataset is being processed...';
$string['trainingmodel'] = 'Training model...';
$string['railwaydeployment'] = 'Railway deployment instructions';
$string['railwaydeployment_desc'] = 'Instructions for deploying the ML backend on Railway.';
$string['deploymentsteps'] = 'Deployment steps:';
$string['deploystep1'] = '1. Create a new project in Railway';
$string['deploystep2'] = '2. Connect to your GitHub repository with the ML backend code';
$string['deploystep3'] = '3. Configure environment variables: API_KEY, DEBUG, PORT';
$string['deploystep4'] = '4. Start the deployment';
$string['deploystep5'] = '5. Copy the generated URL to the Python API URL setting';
$string['modeltrainingprogress'] = 'Model training in progress...';
$string['backendapiurl'] = 'Backend API URL';
$string['backendapikey'] = 'Backend API Key';
$string['predictionjobqueued'] = 'Prediction job has been queued and will run shortly.';
$string['jobstatus'] = 'Job status';
$string['fetchingpredictions'] = 'Fetching predictions...';
$string['processingdata'] = 'Processing data...';
$string['trainingcomplete'] = 'Training complete';
$string['trainingfailed'] = 'Training failed';
$string['modelmetrics'] = 'Model metrics';
$string['datapreprocessing'] = 'Data preprocessing';
$string['uploadingdataset'] = 'Uploading dataset...';
$string['preparingdata'] = 'Preparing data...';
$string['datavalidation'] = 'Data validation';
$string['validatingdata'] = 'Validating data...';
$string['dataimportcomplete'] = 'Data import complete';
$string['errorprocessingdata'] = 'Error processing data';
$string['retryupload'] = 'Retry upload';
$string['backendstarting'] = 'Backend starting...';
$string['backendready'] = 'Backend ready';
$string['connectingtobackend'] = 'Connecting to backend...';
$string['connectionestablished'] = 'Connection established';
$string['connectionfailed'] = 'Connection failed';
$string['retryconnection'] = 'Retry connection';
$string['trainingstarted'] = 'Training started';
$string['trainingprogress'] = 'Training progress';
$string['preparingmodel'] = 'Preparing model...';
$string['generatingfeatures'] = 'Generating features...';
$string['trainingphase'] = 'Training phase';
$string['evaluationphase'] = 'Evaluation phase';
$string['finalizingmodel'] = 'Finalizing model...';
$string['savingmodel'] = 'Saving model...';
$string['modelready'] = 'Model ready';
$string['modelactivated'] = 'Model activated';
$string['predictionsgenerated'] = 'Predictions generated';
$string['suggestionsgenerated'] = 'Suggestions generated';
$string['modelaccuracy'] = 'Model accuracy';
$string['modelevaluation'] = 'Model evaluation';
$string['modelperformance'] = 'Model performance';
$string['modelcomparison'] = 'Model comparison';
$string['modelselection'] = 'Model selection';
$string['railwayhelp'] = 'Help with Railway';
$string['apidocs'] = 'API documentation';
$string['enableapidebug'] = 'Enable API debug mode';
$string['disableapidebug'] = 'Disable API debug mode';
$string['resetapikey'] = 'Reset API key';
$string['resetapikeyconfirm'] = 'Are you sure you want to reset the API key? All current connections will be invalidated.';
$string['apikeyresetsuccessful'] = 'API key reset successful';

// Student prediction strings
$string['generateprediction'] = 'Generate my prediction';
$string['generatingprediction'] = 'Generating prediction...';
$string['updateprediction'] = 'Update prediction';
$string['updatingprediction'] = 'Updating prediction...';
$string['predictiongenerated'] = 'Prediction generated successfully';
$string['predictionerror'] = 'Error generating prediction';
$string['predictionfailed'] = 'Failed to generate prediction';
$string['performancehistory'] = 'Performance History';
$string['improveperformance'] = 'Improve performance';
$string['performancetrend'] = 'Performance trend';
$string['improving'] = 'Improving';
$string['declining'] = 'Declining';
$string['stable'] = 'Stable';
$string['latestprediction'] = 'Latest prediction';
$string['viewallhistory'] = 'View all prediction history';
$string['predictionnote'] = 'Note: This prediction is based on your current activity and performance in this course.';
$string['clicktorefresh'] = 'Click to refresh your prediction';
$string['predictionrefreshed'] = 'Your prediction has been refreshed';
$string['nopredicitiontext'] = 'No prediction available yet. Click the button below to generate your first prediction.';
$string['predictionnewuser'] = 'Welcome! Generate your first prediction to see how you\'re doing in this course.';

// Task-related strings
$string['activetrainingmodels'] = 'Models currently in training';
$string['scheduledtasks'] = 'Scheduled training tasks';
$string['traininglogs'] = 'Training logs';
$string['modelid'] = 'Model ID';
$string['event'] = 'Event';
$string['level'] = 'Level';
$string['pending'] = 'Pending';
$string['datasetfilenotfound'] = 'Dataset file not found on the server. Please try uploading the dataset again.';
$string['invalidmodeldata'] = 'Invalid model data structure';

// Task monitor and time-related strings
$string['modelscurrentlyintraining'] = 'Models currently in training';
$string['notrainingmodels'] = 'No models are currently being trained';
$string['traininglogs'] = 'Training logs';
$string['notraininglogs'] = 'No training logs found';
$string['scheduledtasks'] = 'Scheduled tasks';
$string['pendingstatus'] = 'Pending...';
$string['trainingstatus'] = 'Training...';
$string['modelid'] = 'Model ID';
$string['taskid'] = 'Task ID';
$string['logmessage'] = 'Message';
$string['notasks'] = 'No tasks found';
$string['timecreated'] = 'Time created';
$string['timemodified'] = 'Time modified';
$string['nextruntime'] = 'Next run time';
$string['lastruntime'] = 'Last run time';

// Model management actions
$string['deletemodel'] = 'Delete model';
$string['confirmmodeldelete'] = 'Are you sure you want to delete the model "{$a}"? This will also delete all predictions made with this model.';
$string['modeldeleted'] = 'Model deleted successfully';
$string['purgefailedmodels'] = 'Purge failed models';
$string['confirmpurgefailed'] = 'Are you sure you want to delete all failed models? This cannot be undone.';
$string['failedmodelsdeleted'] = 'Failed models deleted successfully';
$string['nomodels'] = 'No models found';
$string['allmodels'] = 'All models';
$string['failedmodels'] = 'Failed models';
$string['nofailedmodels'] = 'No failed models found to purge';

// Dataset properties table
$string['datasetproperty'] = 'Property';
$string['datasetvalue'] = 'Value';

$string['complete'] = 'Complete';
$string['datasetfilenotfound'] = 'Dataset file not found';

// Risk student lists
$string['highrisk_students'] = 'High risk students';
$string['mediumrisk_students'] = 'Medium risk students';
$string['lowrisk_students'] = 'Low risk students';
$string['riskfactors'] = 'Risk factors';
$string['nostudentsintherisk'] = 'No students in this risk category.';

// Risk factors
$string['nofactorsavailable'] = 'No specific factors available for this prediction.';
$string['factor_low_activity'] = 'Very low course activity';
$string['factor_medium_activity'] = 'Below average course activity';
$string['factor_high_activity'] = 'High level of course activity';
$string['factor_low_submissions'] = 'Few or no assignment submissions';
$string['factor_medium_submissions'] = 'Some assignments not submitted';
$string['factor_high_submissions'] = 'All assignments submitted on time';
$string['factor_low_grades'] = 'Low grades on submitted work';
$string['factor_medium_grades'] = 'Average grades on assessments';
$string['factor_high_grades'] = 'Good grades on assessments';
$string['factor_not_logged_in'] = 'Has not logged in for {$a} days';
$string['factor_few_days_since_login'] = 'Last logged in {$a} days ago';
$string['factor_recent_login'] = 'Recently active in the course';
$string['factor_few_modules_accessed'] = 'Has accessed very few course materials';
$string['factor_some_modules_accessed'] = 'Has accessed some but not all materials';
$string['factor_many_modules_accessed'] = 'Has accessed most course materials';
$string['factor_general_high_risk'] = 'Multiple risk factors detected';
$string['factor_general_medium_risk'] = 'Some engagement issues detected';
$string['factor_general_low_risk'] = 'Good overall engagement pattern';

// Admin dashboard
$string['courseswithpredictions'] = 'Courses with predictions';
$string['highrisk_students_allcourses'] = 'High risk students across all courses';
$string['viewingcoursestudents'] = 'Viewing students from course: {$a}';
$string['viewallcourses'] = 'View all courses';
$string['viewcourse'] = 'View course';
$string['nocourseswitmodels'] = 'No courses with active prediction models found. Train a model first.';
$string['hidden'] = 'Hidden';
$string['nostudentsintherisk'] = 'No students in this risk category.';
$string['student'] = 'Student';

// Model details
$string['potentialoverfitting'] = 'Potential overfitting detected. The model performs much better on training data than testing data.';
$string['crossvalidation'] = 'Cross-validation accuracy: {$a}%';
$string['modelaccuracydetail'] = 'Accuracy: {$a->accuracy}% (Cross-validation: {$a->cv_accuracy}%)';
$string['topfeatures'] = 'Top Important Features';

$string['metrics_precision'] = 'Precision';
$string['metrics_recall'] = 'Recall';
$string['metrics_f1'] = 'F1 Score';
$string['metrics_roc_auc'] = 'ROC AUC';
$string['metrics_overfitting_ratio'] = 'Overfitting Ratio';
$string['metrics_cv_accuracy'] = 'Cross-validation Accuracy';
$string['metrics_cv_std'] = 'Cross-validation Std. Dev.';
$string['feature_importance'] = 'Feature Importance';

$string['modeldetails'] = 'Model Details';
$string['trainstatus'] = 'Training Status';
$string['viewdetails'] = 'View Details';

// Add these new strings at the end of the language file
$string['suggestion_course_specific'] = 'in your {$a->coursename} course';
$string['suggestion_for_course'] = 'for your {$a->coursename} course';
$string['courseselectorlabel'] = 'Select course to view';
$string['multiplecoursesavailable'] = 'Multiple courses available';
$string['nocourseselected'] = 'No course selected';
$string['viewperformancein'] = 'View performance in';
$string['generalstudy'] = 'General study recommendation';

{{!
    @template block_studentperformancepredictor/admin_dashboard

    Admin dashboard template
}}

<section class="block_studentperformancepredictor admin-dashboard" data-course-id="{{courseid}}">
    <h4 class="spp-heading">{{heading}}</h4>

    <div class="spp-admin-controls mb-4">
        <a href="{{managemodelsurl}}" class="btn btn-primary">
            {{#str}}managemodels, block_studentperformancepredictor{{/str}}
        </a>
        <a href="{{managedatasetsurl}}" class="btn btn-secondary">
            {{#str}}managedatasets, block_studentperformancepredictor{{/str}}
        </a>
        <a href="{{refreshpredictionsurl}}" class="btn btn-secondary">
            {{#str}}refreshpredictions, block_studentperformancepredictor{{/str}}
        </a>
    </div>

    {{^hasmodel}}
        <div class="alert alert-warning" role="alert">
            {{{nomodeltext}}}
        </div>
    {{/hasmodel}}

    {{#hasmodel}}
        <div class="spp-course-overview">
            <div class="row">
                <div class="col-md-12">
                    <div class="spp-stats">
                        <div class="spp-stat-total">
                            <span class="spp-label">{{#str}}totalstudents, block_studentperformancepredictor{{/str}}</span>
                            <span class="spp-value">{{totalstudents}}</span>
                        </div>
                        <div class="spp-risk-distribution">
                            <div class="spp-risk-high">
                                <span class="spp-label">{{#str}}highrisk_label, block_studentperformancepredictor{{/str}}</span>
                                <span class="spp-value">{{highrisk}} ({{highriskpercent}}%)</span>
                            </div>
                            <div class="spp-risk-medium">
                                <span class="spp-label">{{#str}}mediumrisk_label, block_studentperformancepredictor{{/str}}</span>
                                <span class="spp-value">{{mediumrisk}} ({{mediumriskpercent}}%)</span>
                            </div>
                            <div class="spp-risk-low">
                                <span class="spp-label">{{#str}}lowrisk_label, block_studentperformancepredictor{{/str}}</span>
                                <span class="spp-value">{{lowrisk}} ({{lowriskpercent}}%)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="spp-student-risk-sections mt-4">
                {{! High Risk Students }}
                <div class="card mb-3 spp-risk-card spp-risk-high-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fa fa-exclamation-triangle mr-2"></i>
                            {{#str}}highrisk_students, block_studentperformancepredictor{{/str}} ({{highrisk}})
                        </h5>
                    </div>
                    <div id="highRiskStudents">
                        <div class="card-body p-0">
                            {{#has_highrisk_students}}
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped mb-0">
                                        <thead>
                                            <tr>
                                                <th>{{#str}}student, block_studentperformancepredictor{{/str}}</th>
                                                <th>{{#str}}passingchance, block_studentperformancepredictor{{/str}}</th>
                                                <th>{{#str}}riskfactors, block_studentperformancepredictor{{/str}}</th>
                                                <th>{{#str}}suggestedactivities, block_studentperformancepredictor{{/str}}</th>
                                                <th>{{#str}}actions, block_studentperformancepredictor{{/str}}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {{#students_highrisk}}
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            {{#picture}}
                                                                <img src="{{picture}}" alt="{{fullname}}" class="rounded-circle mr-2" width="35" height="35">
                                                            {{/picture}}
                                                            <a href="{{profileurl}}">{{fullname}}</a>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-danger">{{passprob}}%</span>
                                                    </td>
                                                    <td>
                                                        <ul class="list-unstyled mb-0">
                                                            {{#risk_factors}}
                                                                <li><i class="fa fa-times-circle text-danger mr-1"></i> {{.}}</li>
                                                            {{/risk_factors}}
                                                        </ul>
                                                    </td>
                                                    <td>
                                                        {{#suggestions.length}}
                                                            <ul class="list-unstyled mb-0 small">
                                                                {{#suggestions}}
                                                                    <li><i class="fa fa-info-circle text-info mr-1"></i> {{text}}</li>
                                                                {{/suggestions}}
                                                            </ul>
                                                        {{/suggestions.length}}
                                                        {{^suggestions.length}}
                                                            <span class="text-muted small">{{#str}}nosuggestions, block_studentperformancepredictor{{/str}}</span>
                                                        {{/suggestions.length}}
                                                    </td>
                                                    <td>
                                                        <a href="{{generate_url}}" class="btn btn-sm btn-info">
                                                            {{#str}}updateprediction, block_studentperformancepredictor{{/str}}
                                                        </a>
                                                    </td>
                                                </tr>
                                            {{/students_highrisk}}
                                        </tbody>
                                    </table>
                                </div>
                            {{/has_highrisk_students}}
                            {{^has_highrisk_students}}
                                <div class="card-body">
                                    <p class="text-muted mb-0">{{#str}}nostudentsintherisk, block_studentperformancepredictor{{/str}}</p>
                                </div>
                            {{/has_highrisk_students}}
                        </div>
                    </div>
                </div>

                {{! Medium Risk Students }}
                <div class="card mb-3 spp-risk-card spp-risk-medium-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fa fa-exclamation-circle mr-2"></i>
                            {{#str}}mediumrisk_students, block_studentperformancepredictor{{/str}} ({{mediumrisk}})
                        </h5>
                    </div>
                    <div id="mediumRiskStudents">
                        <div class="card-body p-0">
                            {{#has_mediumrisk_students}}
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped mb-0">
                                        <thead>
                                            <tr>
                                                <th>{{#str}}student, block_studentperformancepredictor{{/str}}</th>
                                                <th>{{#str}}passingchance, block_studentperformancepredictor{{/str}}</th>
                                                <th>{{#str}}riskfactors, block_studentperformancepredictor{{/str}}</th>
                                                <th>{{#str}}suggestedactivities, block_studentperformancepredictor{{/str}}</th>
                                                <th>{{#str}}actions, block_studentperformancepredictor{{/str}}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {{#students_mediumrisk}}
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            {{#picture}}
                                                                <img src="{{picture}}" alt="{{fullname}}" class="rounded-circle mr-2" width="35" height="35">
                                                            {{/picture}}
                                                            <a href="{{profileurl}}">{{fullname}}</a>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-warning">{{passprob}}%</span>
                                                    </td>
                                                    <td>
                                                        <ul class="list-unstyled mb-0">
                                                            {{#risk_factors}}
                                                                <li><i class="fa fa-exclamation-circle text-warning mr-1"></i> {{.}}</li>
                                                            {{/risk_factors}}
                                                        </ul>
                                                    </td>
                                                    <td>
                                                        {{#suggestions.length}}
                                                            <ul class="list-unstyled mb-0 small">
                                                                {{#suggestions}}
                                                                    <li><i class="fa fa-info-circle text-info mr-1"></i> {{text}}</li>
                                                                {{/suggestions}}
                                                            </ul>
                                                        {{/suggestions.length}}
                                                        {{^suggestions.length}}
                                                            <span class="text-muted small">{{#str}}nosuggestions, block_studentperformancepredictor{{/str}}</span>
                                                        {{/suggestions.length}}
                                                    </td>
                                                    <td>
                                                        <a href="{{generate_url}}" class="btn btn-sm btn-info">
                                                            {{#str}}updateprediction, block_studentperformancepredictor{{/str}}
                                                        </a>
                                                    </td>
                                                </tr>
                                            {{/students_mediumrisk}}
                                        </tbody>
                                    </table>
                                </div>
                            {{/has_mediumrisk_students}}
                            {{^has_mediumrisk_students}}
                                <div class="card-body">
                                    <p class="text-muted mb-0">{{#str}}nostudentsintherisk, block_studentperformancepredictor{{/str}}</p>
                                </div>
                            {{/has_mediumrisk_students}}
                        </div>
                    </div>
                </div>

                {{! Low Risk Students }}
                <div class="card mb-3 spp-risk-card spp-risk-low-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fa fa-check-circle mr-2"></i>
                            {{#str}}lowrisk_students, block_studentperformancepredictor{{/str}} ({{lowrisk}})
                        </h5>
                    </div>
                    <div id="lowRiskStudents">
                        <div class="card-body p-0">
                            {{#has_lowrisk_students}}
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped mb-0">
                                        <thead>
                                            <tr>
                                                <th>{{#str}}student, block_studentperformancepredictor{{/str}}</th>
                                                <th>{{#str}}passingchance, block_studentperformancepredictor{{/str}}</th>
                                                <th>{{#str}}riskfactors, block_studentperformancepredictor{{/str}}</th>
                                                <th>{{#str}}suggestedactivities, block_studentperformancepredictor{{/str}}</th>
                                                <th>{{#str}}actions, block_studentperformancepredictor{{/str}}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {{#students_lowrisk}}
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            {{#picture}}
                                                                <img src="{{picture}}" alt="{{fullname}}" class="rounded-circle mr-2" width="35" height="35">
                                                            {{/picture}}
                                                            <a href="{{profileurl}}">{{fullname}}</a>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-success">{{passprob}}%</span>
                                                    </td>
                                                    <td>
                                                        <ul class="list-unstyled mb-0">
                                                            {{#risk_factors}}
                                                                <li><i class="fa fa-check-circle text-success mr-1"></i> {{.}}</li>
                                                            {{/risk_factors}}
                                                        </ul>
                                                    </td>
                                                    <td>
                                                        {{#suggestions.length}}
                                                            <ul class="list-unstyled mb-0 small">
                                                                {{#suggestions}}
                                                                    <li><i class="fa fa-info-circle text-info mr-1"></i> {{text}}</li>
                                                                {{/suggestions}}
                                                            </ul>
                                                        {{/suggestions.length}}
                                                        {{^suggestions.length}}
                                                            <span class="text-muted small">{{#str}}nosuggestions, block_studentperformancepredictor{{/str}}</span>
                                                        {{/suggestions.length}}
                                                    </td>
                                                    <td>
                                                        <a href="{{generate_url}}" class="btn btn-sm btn-info">
                                                            {{#str}}updateprediction, block_studentperformancepredictor{{/str}}
                                                        </a>
                                                    </td>
                                                </tr>
                                            {{/students_lowrisk}}
                                        </tbody>
                                    </table>
                                </div>
                            {{/has_lowrisk_students}}
                            {{^has_lowrisk_students}}
                                <div class="card-body">
                                    <p class="text-muted mb-0">{{#str}}nostudentsintherisk, block_studentperformancepredictor{{/str}}</p>
                                </div>
                            {{/has_lowrisk_students}}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    {{/hasmodel}}
</section>

{{!
    @template block_studentperformancepredictor/admin_settings

    Admin settings template
}}

<section class="block_studentperformancepredictor mb-4" data-course-id="{{courseid}}">
    <header>
        <h4 class="spp-heading">{{heading}}</h4>
    </header>

    <div class="spp-admin-actions mb-3">
        <div class="btn-group" role="group">
            <a href="{{managemodelsurl}}" class="btn btn-primary">
                {{#str}}managemodels, block_studentperformancepredictor{{/str}}
            </a>
            <a href="{{managedatasetsurl}}" class="btn btn-secondary">
                {{#str}}managedatasets, block_studentperformancepredictor{{/str}}
            </a>
            <a href="{{refreshpredictionsurl}}" class="btn btn-secondary">
                {{#str}}refreshpredictions, block_studentperformancepredictor{{/str}}
            </a>
        </div>
    </div>

    {{^hasmodel}}
        <div class="alert alert-warning" role="alert">
            {{{nomodeltext}}}
        </div>
    {{/hasmodel}}

    {{#hasmodel}}
        <section class="spp-course-overview">
            <div class="row">
                <div class="col-md-6">
                    <div class="spp-stats">
                        <div class="spp-stat-total">
                            <span class="spp-label">{{#str}}totalstudents, block_studentperformancepredictor{{/str}}</span>
                            <span class="spp-value">{{totalstudents}}</span>
                        </div>
                        <div class="spp-risk-distribution">
                            <div class="spp-risk-high">
                                <span class="spp-label">{{#str}}highrisk_label, block_studentperformancepredictor{{/str}}</span>
                                <span class="spp-value">{{highrisk}} ({{highriskpercent}}%)</span>
                            </div>
                            <div class="spp-risk-medium">
                                <span class="spp-label">{{#str}}mediumrisk_label, block_studentperformancepredictor{{/str}}</span>
                                <span class="spp-value">{{mediumrisk}} ({{mediumriskpercent}}%)</span>
                            </div>
                            <div class="spp-risk-low">
                                <span class="spp-label">{{#str}}lowrisk_label, block_studentperformancepredictor{{/str}}</span>
                                <span class="spp-value">{{lowrisk}} ({{lowriskpercent}}%)</span>
                            </div>
                        </div>
                    </div>
                </div>
                {{#haschart}}
                <div class="col-md-6">
                    <div class="spp-chart-container" aria-label="{{#str}}riskdistribution, block_studentperformancepredictor{{/str}}">
                        <canvas id="spp-admin-chart" data-chartdata="{{chartdata}}"></canvas>
                        <noscript>
                            <div class="alert alert-info mt-2">{{#str}}jsrequired, block_studentperformancepredictor{{/str}}</div>
                        </noscript>
                    </div>
                </div>
                {{/haschart}}
            </div>
        </section>
    {{/hasmodel}}
</section>

{{!
    @template block_studentperformancepredictor/prediction_details

    Prediction details template
}}

<section class="spp-prediction-details">
    <div class="spp-prediction-summary">
        <div class="spp-probability">
            <span class="spp-label">{{#str}}passingchance, block_studentperformancepredictor{{/str}}</span>
            <span class="spp-value">{{prediction.passprob}}%</span>
        </div>
        <div class="spp-risk {{prediction.riskclass}}">
            <span class="spp-label">{{#str}}risk, block_studentperformancepredictor{{/str}}</span>
            <span class="spp-value">{{prediction.risktext}}</span>
        </div>
        <div class="spp-update-time">
            <small>{{#str}}lastupdate, block_studentperformancepredictor, {{prediction.lastupdate}}{{/str}}</small>
        </div>
    </div>

    {{#suggestions}}
    <div class="spp-suggestions mt-3">
        <h6>{{#str}}suggestedactivities, block_studentperformancepredictor{{/str}}</h6>
        <ul class="list-group" aria-label="{{#str}}suggestedactivities, block_studentperformancepredictor{{/str}}">
            {{#suggestions}}
            <li class="list-group-item">
                {{#hasurl}}
                    <a href="{{url}}" target="_blank" rel="noopener noreferrer">{{name}}</a>:
                {{/hasurl}}
                {{^hasurl}}
                    <span>{{name}}</span>:
                {{/hasurl}}
                {{reason}}
                {{#viewed}}
                    <span class="badge bg-secondary">{{#str}}viewed, block_studentperformancepredictor{{/str}}</span>
                {{/viewed}}
                {{#completed}}
                    <span class="badge bg-success">{{#str}}completed, block_studentperformancepredictor{{/str}}</span>
                {{/completed}}
            </li>
            {{/suggestions}}
        </ul>
    </div>
    {{/suggestions}}
    {{^suggestions}}
    <div class="alert alert-info mt-3">{{#str}}nosuggestions, block_studentperformancepredictor{{/str}}</div>
    {{/suggestions}}
</section>

{{!
    @template block_studentperformancepredictor/student_dashboard

    Student dashboard template
}}

<section class="block_studentperformancepredictor" data-course-id="{{courseid}}" data-user-id="{{userid}}" aria-label="{{heading}}">
    <h4 class="spp-heading">{{heading}}</h4>

    {{#showcourseselector}}
        <div class="spp-course-selector-container mb-3">
            <div class="spp-course-selector-label mb-1">
                {{#str}}viewperformancein, block_studentperformancepredictor{{/str}}:
            </div>
            {{{courseselector}}}
        </div>
    {{/showcourseselector}}


    {{^hasmodel}}
        <div class="alert alert-warning" role="alert">
            {{{nomodeltext}}}
        </div>
    {{/hasmodel}}

    {{#hasmodel}}
        {{^hasprediction}}
            <div class="alert alert-info" role="status">
                {{{nopredictiontext}}}
            </div>


        {{#can_generate_prediction}}
                <div class="text-center mb-3">
                    <button type="button" class="btn btn-primary spp-generate-prediction"
                            data-course-id="{{courseid}}" data-user-id="{{userid}}">
                        {{#str}}generateprediction, block_studentperformancepredictor{{/str}}
                    </button>
                </div>
            {{/can_generate_prediction}}
        {{/hasprediction}}

        {{#hasprediction}}
            <section class="spp-prediction" data-prediction-id="{{predictionid}}">
                <div class="row">
                    <div class="col-md-12">
                        <div class="spp-prediction-stats">
                            <div class="spp-probability">
                                <span class="spp-label">{{#str}}passingchance, block_studentperformancepredictor{{/str}}</span>
                                <span class="spp-value">{{passprob}}%</span>
                            </div>
                            <div class="spp-risk {{riskclass}}">
                                <span class="spp-label">{{#str}}risk, block_studentperformancepredictor{{/str}}</span>
                                <span class="spp-value">{{risktext}}</span>
                            </div>
                            <div class="spp-update-time">
                                <small>{{#str}}lastupdate, block_studentperformancepredictor, {{lastupdate}}{{/str}}</small>
                            </div>

                            {{#can_generate_prediction}}
                                <div class="mt-2">
                                    <button type="button" class="btn btn-sm btn-secondary spp-update-prediction"
                                            data-course-id="{{courseid}}" data-user-id="{{userid}}">
                                        {{#str}}updateprediction, block_studentperformancepredictor{{/str}}
                                    </button>
                                </div>
                            {{/can_generate_prediction}}
                        </div>
                    </div>
                </div>
            </section>

            {{#showimprovements}}
                {{#has_historical}}
                    <section class="spp-improvements mt-3">
                    <h5>{{#str}}performancehistory, block_studentperformancepredictor{{/str}}</h5>
                    <div class="spp-improvements-chart">
                         <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{#str}}date, core{{/str}}</th>
                                    <th>{{#str}}passingchance, block_studentperformancepredictor{{/str}}</th>
                                    <th>{{#str}}risk, block_studentperformancepredictor{{/str}}</th>
                                 </tr>
                            </thead>
                            <tbody>
                                 {{#historical}}
                                    <tr>
                                    <td>{{date}}</td>
                                    <td>{{passprob}}%</td>
                                    <td><span class="{{riskclass}}">{{risktext}}</span></td>
                                 </tr>
                                 {{/historical}}
                            </tbody>
                         </table>
                    </div>
                   </section>
                {{/has_historical}}
            {{/showimprovements}}

            <section>
            {{#hassuggestions}}
                <div class="spp-suggestions">
                    <h5>{{#str}}suggestedactivities, block_studentperformancepredictor{{/str}}</h5>
                    <div class="list-group spp-suggestions-list" aria-label="{{#str}}suggestedactivities, block_studentperformancepredictor{{/str}}">
                         {{#suggestions}}
                            <div class="list-group-item spp-suggestion" data-id="{{id}}">
                                 <div class="spp-suggestion-content">
                                    {{#hasurl}}
                                        <h6><a href="{{url}}" target="_blank" rel="noopener noreferrer">{{name}}</a></h6>
                                     {{/hasurl}}
                                    {{^hasurl}}
                                        <h6>{{name}}</h6>
                                    {{/hasurl}}
                                    <p>{{reason}}</p>
                                    <div class="spp-suggestion-actions">
                                        {{^viewed}}
                                            <button
                                            class="btn btn-sm btn-outline-secondary spp-mark-viewed" data-id="{{id}}">
                                                {{#str}}markasviewed, block_studentperformancepredictor{{/str}}
                                            </button>
                                                   {{/viewed}}

                                        {{#viewed}}
                                           <span class="badge bg-secondary">{{#str}}viewed, block_studentperformancepredictor{{/str}}</span>
                                           {{/viewed}}
                                           {{^completed}}
                                            <button class="btn btn-sm btn-outline-primary spp-mark-completed" data-id="{{id}}">
                                                {{#str}}markascompleted, block_studentperformancepredictor{{/str}}
                                            </button>
                                         {{/completed}}
                                         {{#completed}}
                                            <span class="badge bg-success">{{#str}}completed, block_studentperformancepredictor{{/str}}</span>
                                          {{/completed}}
                                     </div>
                                 </div>
                       </div>
                         {{/suggestions}}
                    </div>
                </div>
            {{/hassuggestions}}
            {{^hassuggestions}}
                 <div class="alert alert-info mt-3">{{#str}}nosuggestions, block_studentperformancepredictor{{/str}}</div>
            {{/hassuggestions}}
            </section>
        {{/hasprediction}}
    {{/hasmodel}}
</section>

{{!
    @template block_studentperformancepredictor/teacher_dashboard
    Teacher and Admin dashboard template for the block view.
}}

<section class="block_studentperformancepredictor" data-course-id="{{courseid}}">
    <h4 class="spp-heading">{{heading}}</h4>

    {{#showcourseselector}}
        <div class="spp-course-selector-container mb-3">
            <div class="spp-course-selector-label mb-1">
                {{#str}}viewperformancein, block_studentperformancepredictor{{/str}}:
            </div>
            {{{courseselectorhtml}}}
        </div>
        <h5 class="spp-subheading text-muted mb-3">{{coursename}}</h5>
    {{/showcourseselector}}

    <div class="spp-teacher-actions mt-3 mb-3">
        <a href="{{managemodelsurl}}" class="btn btn-primary" rel="noopener noreferrer">
            {{#str}}managemodels, block_studentperformancepredictor{{/str}}
        </a>
        <a href="{{managedatasetsurl}}" class="btn btn-secondary" rel="noopener noreferrer">
            {{#str}}managedatasets, block_studentperformancepredictor{{/str}}
        </a>
        <a href="{{refreshpredictionsurl}}" class="btn btn-info" rel="noopener noreferrer">
            {{#str}}refreshpredictions, block_studentperformancepredictor{{/str}}
        </a>
    </div>

    {{^hasmodel}}
        <div class="alert alert-warning" role="alert">
            {{{nomodeltext}}}
        </div>
    {{/hasmodel}}

    {{#hasmodel}}
        <div class="spp-course-overview">
            <div class="spp-stats">
                <div class="spp-stat-total">
                    <span class="spp-label">{{#str}}totalstudents, block_studentperformancepredictor{{/str}}</span>
                    <span class="spp-value">{{totalstudents}}</span>
                </div>
                <div class="spp-risk-distribution">
                    <div class="spp-risk-high">
                        <span class="spp-label">{{#str}}highrisk_label, block_studentperformancepredictor{{/str}}</span>
                        <span class="spp-value">{{highrisk}} ({{highriskpercent}}%)</span>
                    </div>
                    <div class="spp-risk-medium">
                        <span class="spp-label">{{#str}}mediumrisk_label, block_studentperformancepredictor{{/str}}</span>
                        <span class="spp-value">{{mediumrisk}} ({{mediumriskpercent}}%)</span>
                    </div>
                    <div class="spp-risk-low">
                        <span class="spp-label">{{#str}}lowrisk_label, block_studentperformancepredictor{{/str}}</span>
                        <span class="spp-value">{{lowrisk}} ({{lowriskpercent}}%)</span>
                    </div>
                </div>
            </div>

            <div class="spp-student-risk-sections mt-4">
                {{#has_highrisk_students}}
                    <h6>{{#str}}highrisk_students, block_studentperformancepredictor{{/str}}</h6>
                    <ul class="list-group mb-3">
                        {{#students_highrisk}}
                            <li class="list-group-item">
                                <a href="{{profileurl}}">{{fullname}}</a> - <span class="badge badge-danger">{{passprob}}%</span>
                                <div class="text-muted small mt-1">
                                    <strong>{{#str}}riskfactors, block_studentperformancepredictor{{/str}}:</strong>
                                    {{#risk_factors}}
                                        {{.}}
                                    {{/risk_factors}}
                                </div>
                                {{#suggestions.length}}
                                <div class="text-muted small mt-1">
                                    <strong>{{#str}}suggestedactivities, block_studentperformancepredictor{{/str}}:</strong>
                                    <ul class="list-unstyled mb-0">
                                    {{#suggestions}}
                                        <li><i class="fa fa-info-circle text-info mr-1"></i> {{text}}</li>
                                    {{/suggestions}}
                                    </ul>
                                </div>
                                {{/suggestions.length}}
                            </li>
                        {{/students_highrisk}}
                    </ul>
                {{/has_highrisk_students}}

                {{#has_mediumrisk_students}}
                    <h6>{{#str}}mediumrisk_students, block_studentperformancepredictor{{/str}}</h6>
                    <ul class="list-group mb-3">
                        {{#students_mediumrisk}}
                            <li class="list-group-item">
                                <a href="{{profileurl}}">{{fullname}}</a> - <span class="badge badge-warning">{{passprob}}%</span>
                                <div class="text-muted small mt-1">
                                    <strong>{{#str}}riskfactors, block_studentperformancepredictor{{/str}}:</strong>
                                    {{#risk_factors}}
                                        {{.}}
                                    {{/risk_factors}}
                                </div>
                                {{#suggestions.length}}
                                <div class="text-muted small mt-1">
                                    <strong>{{#str}}suggestedactivities, block_studentperformancepredictor{{/str}}:</strong>
                                    <ul class="list-unstyled mb-0">
                                    {{#suggestions}}
                                        <li><i class="fa fa-info-circle text-info mr-1"></i> {{text}}</li>
                                    {{/suggestions}}
                                    </ul>
                                </div>
                                {{/suggestions.length}}
                            </li>
                        {{/students_mediumrisk}}
                    </ul>
                {{/has_mediumrisk_students}}

                {{#has_lowrisk_students}}
                    <h6>{{#str}}lowrisk_students, block_studentperformancepredictor{{/str}}</h6>
                    <ul class="list-group mb-3">
                        {{#students_lowrisk}}
                            <li class="list-group-item">
                                <a href="{{profileurl}}">{{fullname}}</a> - <span class="badge badge-success">{{passprob}}%</span>
                                <div class="text-muted small mt-1">
                                    <strong>{{#str}}riskfactors, block_studentperformancepredictor{{/str}}:</strong>
                                    {{#risk_factors}}
                                        {{.}}
                                    {{/risk_factors}}
                                </div>
                                {{#suggestions.length}}
                                <div class="text-muted small mt-1">
                                    <strong>{{#str}}suggestedactivities, block_studentperformancepredictor{{/str}}:</strong>
                                    <ul class="list-unstyled mb-0">
                                    {{#suggestions}}
                                        <li><i class="fa fa-info-circle text-info mr-1"></i> {{text}}</li>
                                    {{/suggestions}}
                                    </ul>
                                </div>
                                {{/suggestions.length}}
                            </li>
                        {{/students_lowrisk}}
                    </ul>
                {{/has_lowrisk_students}}
            </div>
        </div>
    {{/hasmodel}}
</section>

<?php
// blocks/studentperformancepredictor/block_studentperformancepredictor.php

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

class block_studentperformancepredictor extends block_base {
    public function init() {
        $this->title = get_string('pluginname', 'block_studentperformancepredictor');
    }

    public function instance_allow_multiple() {
        return false;
    }

    public function applicable_formats() {
        return array(
            'site' => false,
            'my' => true,
            'course' => true,
        );
    }

    protected function render_course_selector($courses, $currentcourse = 0) {
        global $OUTPUT;

        $options = array();
        foreach ($courses as $course) {
            $options[$course->id] = format_string($course->fullname);
        }
        
        $url = new moodle_url($this->page->url);
        $select = new \single_select($url, 'spp_course', $options, $currentcourse, null);
        $select->set_label(get_string('courseselectorlabel', 'block_studentperformancepredictor'),
            ['class' => 'accesshide']);
        $select->class = 'spp-course-selector';
        $select->formid = 'spp-course-selector-form';

        return $OUTPUT->render($select);
    }

    public function get_content() {
        global $USER, $COURSE, $OUTPUT, $PAGE, $DB, $CFG;

        // The check for $this->content has been removed to prevent caching,
        // which was causing the sesskey to become invalid on page reloads.

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        $courseid = 0;
        $viewable_courses = [];
        $showcourseselector = false;
        $courseselectorhtml = '';
        
        $selected_course_param = optional_param('spp_course', 0, PARAM_INT);

        // Determine context and available courses.
        if ($PAGE->pagetype === 'my-index' || $PAGE->context->contextlevel === CONTEXT_USER) {
            // We are on the dashboard.
            $mycourses = enrol_get_my_courses(null, 'fullname ASC');
            
            foreach ($mycourses as $course) {
                if ($course->id == SITEID) continue;
                $context_check = context_course::instance($course->id);
                
                if (has_capability('block/studentperformancepredictor:view', $context_check)) {
                    $viewable_courses[$course->id] = $course;
                }
            }
            
            if (count($viewable_courses) > 0) {
                $showcourseselector = true;
                if ($selected_course_param && isset($viewable_courses[$selected_course_param])) {
                    $courseid = $selected_course_param;
                } else {
                    $firstcourse = reset($viewable_courses);
                    $courseid = $firstcourse->id;
                }
                $courseselectorhtml = $this->render_course_selector($viewable_courses, $courseid);
            }

        } else if ($PAGE->context->contextlevel === CONTEXT_COURSE) {
            // We are on a specific course page.
            $courseid = $PAGE->course->id;
        }

        if (empty($courseid) || $courseid == SITEID) {
            $this->content->text = $OUTPUT->notification(get_string('nocoursesfound', 'block_studentperformancepredictor'), 'info');
            return $this->content;
        }
        
        $coursecontext = context_course::instance($courseid);

        try {
            $canviewown = has_capability('block/studentperformancepredictor:view', $coursecontext);
            $canviewall = has_capability('block/studentperformancepredictor:viewallpredictions', $coursecontext);
            $canmanage = has_capability('block/studentperformancepredictor:managemodels', $coursecontext);

            if ($canmanage || $canviewall) { // Admin or Teacher view
                $renderer = $PAGE->get_renderer('block_studentperformancepredictor');
                $teacherview = new \block_studentperformancepredictor\output\teacher_view($courseid, $showcourseselector, $courseselectorhtml);
                $this->content->text = $renderer->render_teacher_view($teacherview);
            } else if ($canviewown) { // Student view
                $renderer = $PAGE->get_renderer('block_studentperformancepredictor');
                $studentview = new \block_studentperformancepredictor\output\student_view($courseid, $USER->id, $showcourseselector, $courseselectorhtml);
                $this->content->text = $renderer->render_student_view($studentview);
            } else {
                $this->content->text = ''; // Don't show anything if no permissions.
            }
        } catch (Exception $e) {
            debugging('Error rendering Student Performance Predictor block: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $this->content->text = $OUTPUT->notification(get_string('errorrendingblock', 'block_studentperformancepredictor'), 'error');
        }

        return $this->content;
    }

    public function specialization() {
        if (isset($this->config->title)) {
            $this->title = $this->config->title;
        }
    }
}

<?php
// blocks/studentperformancepredictor/generate_prediction.php

require_once('../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

// Get courseid as an optional parameter first to ensure the variable is always defined.
$courseid = optional_param('courseid', 0, PARAM_INT);

try {
    // Manually check for the required courseid. If it's missing, Moodle will throw a standard error.
    if (!$courseid) {
        throw new moodle_exception('missingparam', 'error', '', 'courseid');
    }

    $userid = optional_param('userid', $USER->id, PARAM_INT); // Default to current user

    // Set up page and context for permission checks
    $course = get_course($courseid);
    $context = context_course::instance($courseid);

    // Security checks
    require_login($course);
    require_sesskey();

    // Capability checks
    if ($USER->id == $userid) {
        require_capability('block/studentperformancepredictor:view', $context);
    } else {
        require_capability('block/studentperformancepredictor:viewallpredictions', $context);
    }

    // Generate a new prediction by calling the library function
    $prediction = block_studentperformancepredictor_generate_new_prediction($courseid, $userid);

    if ($prediction) {
        $redirecturl = new moodle_url('/course/view.php', ['id' => $courseid]);
        // After successfully generating the prediction, redirect the user back with a success message.
        redirect($redirecturl, get_string('predictiongenerated', 'block_studentperformancepredictor'), 2, \core\output\notification::NOTIFY_SUCCESS);
    } else {
        // The generate_new_prediction function returned false
        throw new moodle_exception('predictionerror', 'block_studentperformancepredictor');
    }

} catch (Exception $e) {
    // If any exception occurs, redirect with an error message.
    // This safely handles the redirect and prevents the "mutated session" error.
    $redirecturl = $courseid ? new moodle_url('/course/view.php', ['id' => $courseid]) : new moodle_url('/my/');
    redirect($redirecturl, $e->getMessage(), 5, \core\output\notification::NOTIFY_ERROR);
}

<?php
// blocks/studentperformancepredictor/lib.php

defined('MOODLE_INTERNAL') || die();

/**
 * Creates and ensures the dataset directory exists for a course.
 *
 * @param int $courseid Course ID
 * @return string Path to dataset directory
 * @throws moodle_exception If directory creation fails
 */
function block_studentperformancepredictor_ensure_dataset_directory($courseid) {
    global $CFG;

    // Create course-specific dataset directory with proper Windows compatibility
    $basedir = $CFG->dataroot . DIRECTORY_SEPARATOR . 'blocks_studentperformancepredictor';
    $coursedir = $basedir . DIRECTORY_SEPARATOR . 'course_' . $courseid;
    $datasetsdir = $coursedir . DIRECTORY_SEPARATOR . 'datasets';

    // Create directories with proper permissions for Windows/XAMPP
    foreach ([$basedir, $coursedir, $datasetsdir] as $dir) {
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0777, true)) {
                throw new \moodle_exception('directorycreateerror', 'block_studentperformancepredictor', '', $dir);
            }
            // Explicitly set permissions for XAMPP
            @chmod($dir, 0777);
        }

        // Check if directory is writable
        if (!is_writable($dir)) {
            // Try to make it writable for XAMPP
            @chmod($dir, 0777);
            if (!is_writable($dir)) {
                // Try a different approach using is_dir to check if it exists first
                if (is_dir($dir)) {
                    @chmod($dir, 0777);

                    // Create a test file to check write permissions
                    $testfile = $dir . DIRECTORY_SEPARATOR . 'test_write.txt';
                    $success = @file_put_contents($testfile, 'test');
                    if ($success) {
                        @unlink($testfile);
                    } else {
                        throw new \moodle_exception('directorynotwritable', 'block_studentperformancepredictor', '', $dir);
                    }
                } else {
                    throw new \moodle_exception('directorynotwritable', 'block_studentperformancepredictor', '', $dir);
                }
            }
        }
    }

    return $datasetsdir;
}

/**
 * Creates and ensures the models directory exists for a course.
 *
 * @param int $courseid Course ID
 * @return string Path to models directory
 * @throws moodle_exception If directory creation fails
 */
function block_studentperformancepredictor_ensure_models_directory($courseid) {
    global $CFG;

    // Create course-specific models directory with proper Windows compatibility
    $basedir = $CFG->dataroot . DIRECTORY_SEPARATOR . 'blocks_studentperformancepredictor';
    $coursedir = $basedir . DIRECTORY_SEPARATOR . 'course_' . $courseid;
    $modelsdir = $coursedir . DIRECTORY_SEPARATOR . 'models';

    // Create directories with proper permissions for Windows/XAMPP
    foreach ([$basedir, $coursedir, $modelsdir] as $dir) {
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0777, true)) {
                throw new \moodle_exception('directorycreateerror', 'block_studentperformancepredictor', '', $dir);
            }
            // Explicitly set permissions for XAMPP
            @chmod($dir, 0777);
        }

        // Check if directory is writable
        if (!is_writable($dir)) {
            // Try to make it writable for XAMPP
            @chmod($dir, 0777);
            if (!is_writable($dir)) {
                // Try a different approach using is_dir to check if it exists first
                if (is_dir($dir)) {
                    @chmod($dir, 0777);

                    // Create a test file to check write permissions
                    $testfile = $dir . DIRECTORY_SEPARATOR . 'test_write.txt';
                    $success = @file_put_contents($testfile, 'test');
                    if ($success) {
                        @unlink($testfile);
                    } else {
                        throw new \moodle_exception('directorynotwritable', 'block_studentperformancepredictor', '', $dir);
                    }
                } else {
                    throw new \moodle_exception('directorynotwritable', 'block_studentperformancepredictor', '', $dir);
                }
            }
        }
    }

    return $modelsdir;
}

/**
 * Check if the course has an active model.
 *
 * @param int $courseid Course ID
 * @return bool True if there is an active model
 */
function block_studentperformancepredictor_has_active_model($courseid) {
    global $DB;

    // First check for a course-specific model
    if ($DB->record_exists('block_spp_models', array('courseid' => $courseid, 'active' => 1, 'trainstatus' => 'complete'))) {
        return true;
    }

    return false;
}

/**
 * Get the student's prediction for a course.
 *
 * @param int $courseid Course ID
 * @param int $userid User ID
 * @return object|bool Prediction object or false if not found
 */
function block_studentperformancepredictor_get_student_prediction($courseid, $userid) {
    global $DB;

    // First try to get course-specific prediction
    $prediction = $DB->get_record_sql(
        "SELECT p.* FROM {block_spp_predictions} p 
         JOIN {block_spp_models} m ON p.modelid = m.id 
         WHERE p.courseid = ? 
         AND p.userid = ? 
         AND m.active = 1 
         AND m.courseid = ?
         ORDER BY p.timemodified DESC 
         LIMIT 1",
        array($courseid, $userid, $courseid)
    );

    return $prediction;
}

/**
 * Get risk statistics for the course.
 *
 * @param int $courseid Course ID
 * @return object Risk statistics
 */
function block_studentperformancepredictor_get_course_risk_stats($courseid) {
    global $DB;

    $stats = new stdClass();
    $stats->total = 0;
    $stats->highrisk = 0;
    $stats->mediumrisk = 0;
    $stats->lowrisk = 0;

    // Get risk distribution from course-specific predictions first
    $sql = "SELECT p.riskvalue, COUNT(DISTINCT p.userid) as count
            FROM {block_spp_predictions} p
            JOIN {block_spp_models} m ON p.modelid = m.id
            WHERE p.courseid = :courseid
            AND m.active = 1
            AND (m.courseid = :courseid2)
            GROUP BY p.riskvalue";

    $results = $DB->get_records_sql($sql, array('courseid' => $courseid, 'courseid2' => $courseid));

    // Convert risk values to readable form
    foreach ($results as $result) {
        $stats->total += $result->count;
        if ($result->riskvalue == 1) {
            $stats->lowrisk = $result->count;
        } else if ($result->riskvalue == 2) {
            $stats->mediumrisk = $result->count;
        } else if ($result->riskvalue == 3) {
            $stats->highrisk = $result->count;
        }
    }

    // If no predictions found, get total enrolled students
    if ($stats->total == 0) {
        $context = context_course::instance($courseid);
        $stats->total = count_enrolled_users($context, 'moodle/course:isincompletionreports');
    }

    return $stats;
}

/**
 * Get suggestions for a prediction.
 *
 * @param int $predictionid Prediction ID
 * @return array Suggestions
 */
function block_studentperformancepredictor_get_suggestions($predictionid) {
    global $DB;

    // Get suggestions for this prediction
    $sql = "SELECT s.*, cm.id as cmid, mm.name as modulename
            FROM {block_spp_suggestions} s
            LEFT JOIN {course_modules} cm ON s.cmid = cm.id
            LEFT JOIN {modules} mm ON cm.module = mm.id
            WHERE s.predictionid = :predictionid
            ORDER BY s.priority DESC";

    $suggestions = $DB->get_records_sql($sql, array('predictionid' => $predictionid));

    // Add course module name for each suggestion that has a course module
    foreach ($suggestions as $suggestion) {
        if (!empty($suggestion->cmid) && !empty($suggestion->modulename) && !empty($suggestion->resourceid)) {
            // Get the module instance name from the appropriate module table
            $module_table = $suggestion->modulename;
            $record = $DB->get_record($module_table, ['id' => $suggestion->resourceid], 'name');
            if ($record && !empty($record->name)) {
                $suggestion->cmname = $record->name;
            } else {
                $suggestion->cmname = $suggestion->modulename;
            }
        } else {
            $suggestion->cmname = get_string('generalstudy', 'block_studentperformancepredictor');
        }
    }

    return $suggestions;
}

/**
 * Get risk text based on risk value.
 *
 * @param int $riskvalue Risk value (1-3)
 * @return string Risk text
 */
function block_studentperformancepredictor_get_risk_text($riskvalue) {
    switch ($riskvalue) {
        case 1:
            return get_string('lowrisk_label', 'block_studentperformancepredictor');
        case 2:
            return get_string('mediumrisk_label', 'block_studentperformancepredictor');
        case 3:
            return get_string('highrisk_label', 'block_studentperformancepredictor');
        default:
            return get_string('unknownrisk', 'block_studentperformancepredictor');
    }
}

/**
 * Get risk CSS class based on risk value.
 *
 * @param int $riskvalue Risk value (1-3)
 * @return string CSS class
 */
function block_studentperformancepredictor_get_risk_class($riskvalue) {
    switch ($riskvalue) {
        case 1:
            return 'spp-risk-low';
        case 2:
            return 'spp-risk-medium';
        case 3:
            return 'spp-risk-high';
        default:
            return 'spp-risk-unknown';
    }
}

/**
 * Mark a suggestion as viewed.
 *
 * @param int $suggestionid Suggestion ID
 * @return bool Success
 */
function block_studentperformancepredictor_mark_suggestion_viewed($suggestionid) {
    global $DB;

    $suggestion = $DB->get_record('block_spp_suggestions', array('id' => $suggestionid));
    if (!$suggestion) {
        return false;
    }

    $suggestion->viewed = 1;
    return $DB->update_record('block_spp_suggestions', $suggestion);
}

/**
 * Mark a suggestion as completed.
 *
 * @param int $suggestionid Suggestion ID
 * @return bool Success
 */
function block_studentperformancepredictor_mark_suggestion_completed($suggestionid) {
    global $DB;

    $suggestion = $DB->get_record('block_spp_suggestions', array('id' => $suggestionid));
    if (!$suggestion) {
        return false;
    }

    $suggestion->completed = 1;
    $suggestion->viewed = 1; // Also mark as viewed
    return $DB->update_record('block_spp_suggestions', $suggestion);
}

/**
 * Trigger prediction refresh for a course.
 *
 * @param int $courseid Course ID
 * @param int $userid User ID requesting the refresh (optional)
 * @return bool Success
 */
function block_studentperformancepredictor_trigger_prediction_refresh($courseid, $userid = null) {
    global $USER;

    // Use current user if not specified
    if ($userid === null) {
        $userid = $USER->id;
    }

    // Create adhoc task
    $task = new \block_studentperformancepredictor\task\refresh_predictions();
    $task->set_custom_data([
        'courseid' => $courseid,
        'userid' => $userid
    ]);

    // Queue task with high priority
    return \core\task\manager::queue_adhoc_task($task, true);
}

/**
 * Executes the prediction refresh for a course immediately.
 * NOTE: This is a synchronous operation and can be slow for courses with many students.
 * It is called directly by the AJAX request to bypass issues with the ad-hoc task queue.
 *
 * @param int $courseid The course ID.
 * @return array An array with success, errors, and total counts.
 */
function block_studentperformancepredictor_execute_prediction_refresh_now($courseid) {
    global $DB;

    // Ignore user aborts and set a higher time limit to prevent timeouts.
    @ignore_user_abort(true);
    @set_time_limit(300); // 5 minutes

    $context = \context_course::instance($courseid);
    $students = get_enrolled_users($context, 'moodle/course:isincompletionreports');

    $result = [
        'success' => 0,
        'errors' => 0,
        'total' => count($students)
    ];

    if (empty($students)) {
        set_config('lastrefresh_' . $courseid, time(), 'block_studentperformancepredictor');
        return $result;
    }

    foreach ($students as $student) {
        try {
            $predictionid = block_studentperformancepredictor_generate_prediction($courseid, $student->id);
            if ($predictionid) {
                $result['success']++;
            } else {
                $result['errors']++;
            }
        } catch (\Exception $e) {
            debugging('Error generating prediction for student ' . $student->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            $result['errors']++;
        }
    }

    set_config('lastrefresh_' . $courseid, time(), 'block_studentperformancepredictor');
    return $result;
}

/**
 * Call the Python backend API with improved error handling.
 *
 * @param string $endpoint API endpoint
 * @param array $data Data to send
 * @return array|bool Response data or false on failure
 */
function block_studentperformancepredictor_call_backend_api($endpoint, $data) {
    // Get API settings
    $apiurl = get_config('block_studentperformancepredictor', 'python_api_url');
    if (empty($apiurl)) {
        $apiurl = 'http://localhost:5000';
    }

    // Ensure URL ends with the endpoint
    if (!empty($endpoint)) {
        if ($endpoint[0] == '/') {
            $endpoint = substr($endpoint, 1);
        }
        $apiurl = rtrim($apiurl, '/') . '/' . $endpoint;
    }

    $apikey = get_config('block_studentperformancepredictor', 'python_api_key');
    if (empty($apikey)) {
        $apikey = 'changeme';
    }

    // Initialize curl with better error handling
    $curl = new \curl();
    $options = [
        'CURLOPT_TIMEOUT' => 300,
        'CURLOPT_RETURNTRANSFER' => true,
        'CURLOPT_HTTPHEADER' => [
            'Content-Type: application/json',
            'X-API-Key: ' . $apikey
        ],
        // Add these for Windows XAMPP compatibility
        'CURLOPT_SSL_VERIFYHOST' => 0,
        'CURLOPT_SSL_VERIFYPEER' => 0
    ];

    $debug = get_config('block_studentperformancepredictor', 'enabledebug');
    if ($debug) {
        debugging('Calling backend API: ' . $apiurl . ' with data: ' . json_encode($data), DEBUG_DEVELOPER);
    }

    try {
        // Add a retry mechanism for better reliability
        $maxRetries = 2;
        $retryCount = 0;
        $response = null;
        $httpcode = 0;

        while ($retryCount <= $maxRetries) {
            $response = $curl->post($apiurl, json_encode($data), $options);
            $httpcode = $curl->get_info()['http_code'] ?? 0;

            if ($httpcode >= 200 && $httpcode < 300) {
                // Success
                break;
            }

            // Retry on server errors (5xx) but not on client errors (4xx)
            if ($httpcode < 500 || $retryCount >= $maxRetries) {
                break;
            }

            $retryCount++;
            if ($debug) {
                debugging("Retrying API call after error (attempt $retryCount/$maxRetries)", DEBUG_DEVELOPER);
            }

            // Wait before retrying
            sleep(1);
        }

        if ($debug) {
            debugging('Backend API response code: ' . $httpcode, DEBUG_DEVELOPER);
            debugging('Backend API response: ' . substr($response, 0, 1000) . (strlen($response) > 1000 ? '...' : ''), DEBUG_DEVELOPER);
        }

        if ($httpcode < 200 || $httpcode >= 300) {
             if ($httpcode == 404) {
                throw new \moodle_exception('predictionfailed', 'block_studentperformancepredictor', '', 'The prediction model was not found on the backend service. Please try retraining the model.');
            }
            if ($debug) {
                debugging('Backend API error: HTTP ' . $httpcode . ' - ' . $response, DEBUG_DEVELOPER);
            }
            return false;
        }

        $responseData = json_decode($response, true);
        if (!is_array($responseData)) {
            if ($debug) {
                debugging('Invalid response format from backend: ' . substr($response, 0, 200), DEBUG_DEVELOPER);
            }
            return false;
        }

        return $responseData;
    } catch (\Exception $e) {
        debugging('Backend API error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        throw $e;
    }
}

/**
 * Handle backend API errors consistently.
 * * @param string $error Error message
 * @param string $endpoint API endpoint that failed
 * @param array $data Request data (will be sanitized)
 * @return string Formatted error message
 */
function block_studentperformancepredictor_handle_api_error($error, $endpoint, $data = null) {
    global $CFG;

    // Log the error
    $debug = get_config('block_studentperformancepredictor', 'enabledebug');
    $apiurl = get_config('block_studentperformancepredictor', 'python_api_url');

    // Build a detailed error message for admins/debugging
    $detailed_msg = "ML Backend API Error: $error\n";
    $detailed_msg .= "Endpoint: " . ($apiurl ? rtrim($apiurl, '/') . '/' . ltrim($endpoint, '/') : $endpoint) . "\n";

    if ($data && $debug) {
        // Sanitize sensitive data
        $sanitized_data = $data;
        if (isset($sanitized_data['api_key'])) {
            $sanitized_data['api_key'] = '***hidden***';
        }
        $detailed_msg .= "Data: " . json_encode($sanitized_data) . "\n";
    }

    // Add troubleshooting tips
    $detailed_msg .= "\nTroubleshooting:\n";
    $detailed_msg .= "1. Check if the ML backend is running and accessible\n";
    $detailed_msg .= "2. Verify the API URL is correct in plugin settings\n";
    $detailed_msg .= "3. Ensure the API keys match between Moodle and the ML backend\n";
    $detailed_msg .= "4. Check the ML backend logs for more details\n";

    // Add link to test backend page
    $detailed_msg .= "\nTest your backend connection: " . 
        $CFG->wwwroot . '/blocks/studentperformancepredictor/admin/testbackend.php';

    // Log detailed message for debugging
    debugging($detailed_msg, DEBUG_DEVELOPER);

    // Return a user-friendly message
    return get_string('backendconnectionerror', 'block_studentperformancepredictor');
}

/**
 * Train a model via the Python backend.
 *
 * @param int $courseid Course ID (0 for global model)
 * @param int $datasetid Dataset ID
 * @param string $algorithm Algorithm to use (optional)
 * @return int|bool Model ID or false on failure
 */
function block_studentperformancepredictor_train_model_via_backend($courseid, $datasetid, $algorithm = null) {
    global $DB, $USER;

    try {
        // Get dataset information
        $dataset = $DB->get_record('block_spp_datasets', ['id' => $datasetid], '*', MUST_EXIST);

        // Check if dataset file exists
        if (!file_exists($dataset->filepath)) {
            debugging('Dataset file not found: ' . $dataset->filepath, DEBUG_DEVELOPER);
            throw new \moodle_exception('datasetfilenotfound', 'block_studentperformancepredictor');
        }

        // Get API settings
        $apiurl = get_config('block_studentperformancepredictor', 'python_api_url');
        if (empty($apiurl)) {
            $apiurl = 'http://localhost:5000';
        }
        $apiurl = rtrim($apiurl, '/') . '/train';

        $apikey = get_config('block_studentperformancepredictor', 'python_api_key');
        if (empty($apikey)) {
            $apikey = 'changeme';
        }

        // Create CURLFile for file upload
        $cfile = new \CURLFile(
            $dataset->filepath,
            'text/' . $dataset->fileformat,
            basename($dataset->filepath)
        );

        // Prepare multipart form data
        $postfields = [
            'courseid' => $courseid,
            'algorithm' => $algorithm ?: 'randomforest',
            'dataset_file' => $cfile,
        ];

        $debug = get_config('block_studentperformancepredictor', 'enabledebug');
        if ($debug) {
            debugging('Sending training request to: ' . $apiurl, DEBUG_DEVELOPER);
            debugging('Course ID: ' . $courseid . ', Algorithm: ' . ($algorithm ?: 'randomforest'), DEBUG_DEVELOPER);
            debugging('File path: ' . $dataset->filepath, DEBUG_DEVELOPER);
        }

        // Use curl directly for more control over file uploads
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiurl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-Key: ' . $apikey
        ]);

        // Add these options for XAMPP/Windows compatibility
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        // Increase timeout for large files and training
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \moodle_exception('trainingfailed', 'block_studentperformancepredictor', '', 
                                       'CURL error: ' . $error);
        }

        curl_close($ch);

        if ($debug) {
            debugging('Backend API response code: ' . $httpcode, DEBUG_DEVELOPER);
            debugging('Backend API response: ' . substr($response, 0, 1000) . 
                     (strlen($response) > 1000 ? '...' : ''), DEBUG_DEVELOPER);
        }

        if ($httpcode !== 200) {
            throw new \moodle_exception('trainingfailed', 'block_studentperformancepredictor', '', 
                                       'HTTP error ' . $httpcode . ': ' . $response);
        }

        $responseData = json_decode($response, true);
        if (!is_array($responseData) || !isset($responseData['model_id'])) {
            throw new \moodle_exception('trainingfailed', 'block_studentperformancepredictor', '', 
                                       'Invalid response from backend: ' . $response);
        }

        // Check if there's an existing model in training/pending state for this course, dataset, and algorithm
        $params = [
            'courseid' => $courseid,
            'datasetid' => $datasetid,
            'algorithmtype' => $algorithm ?: 'randomforest',
            'status1' => 'pending',
            'status2' => 'training'
        ];

        $existingModelSql = "SELECT id FROM {block_spp_models} 
                             WHERE courseid = :courseid 
                             AND datasetid = :datasetid 
                             AND algorithmtype = :algorithmtype 
                             AND (trainstatus = :status1 OR trainstatus = :status2)
                             ORDER BY id DESC LIMIT 1";

        $existingModel = $DB->get_record_sql($existingModelSql, $params);

        // Prepare model data from the response
        $modelData = [
            'modelid' => $responseData['model_id'],
            'modelpath' => $responseData['model_path'] ?? null,
            'algorithmtype' => $responseData['algorithm'],
            'featureslist' => isset($responseData['feature_names']) ? json_encode($responseData['feature_names']) : '[]',
            'accuracy' => $responseData['metrics']['accuracy'] ?? 0,
            'metrics' => isset($responseData['metrics']) ? json_encode($responseData['metrics']) : null,
            'trainstatus' => 'complete',
            'timemodified' => time(),
            'usermodified' => $USER->id
        ];

        if ($existingModel) {
            // Update the existing model
            $model = $DB->get_record('block_spp_models', ['id' => $existingModel->id]);

            foreach ($modelData as $key => $value) {
                $model->$key = $value;
            }

            // Keep the original name
            if (strpos($model->modelname, ' - ') === false) {
                // If the model doesn't have a timestamp, add one
                $model->modelname = ucfirst($responseData['algorithm']) . ' Model - ' . date('Y-m-d H:i');
            }

            $DB->update_record('block_spp_models', $model);
            $model_db_id = $model->id;

            if ($debug) {
                debugging('Updated existing model record: ' . $model_db_id, DEBUG_DEVELOPER);
            }
        } else {
            // Create a new record (shouldn't normally happen, but just in case)
            $model = new \stdClass();
            $model->courseid = $courseid;
            $model->datasetid = $datasetid;
            $model->modelname = ucfirst($responseData['algorithm']) . ' Model - ' . date('Y-m-d H:i');
            $model->active = 0; // Not active by default
            $model->timecreated = time();

            foreach ($modelData as $key => $value) {
                $model->$key = $value;
            }

            $model_db_id = $DB->insert_record('block_spp_models', $model);

            if ($debug) {
                debugging('Created new model record: ' . $model_db_id, DEBUG_DEVELOPER);
            }
        }

        return $model_db_id;

    } catch (\Exception $e) {
        debugging('Error training model: ' . $e->getMessage(), DEBUG_DEVELOPER);

        // If we have model info but there was an error, mark it as failed
        if (isset($existingModel) && $existingModel) {
            $model = $DB->get_record('block_spp_models', ['id' => $existingModel->id]);
            if ($model) {
                $model->trainstatus = 'failed';
                $model->errormessage = $e->getMessage();
                $model->timemodified = time();
                $DB->update_record('block_spp_models', $model);

                if ($debug) {
                    debugging('Marked existing model as failed: ' . $existingModel->id, DEBUG_DEVELOPER);
                }
            }
        }

        return false;
    }
}

/**
 * Generate a prediction using the backend API.
 *
 * @param int $courseid Course ID
 * @param int $userid User ID
 * @return object|bool Prediction object or false
 */
function block_studentperformancepredictor_generate_prediction_via_backend($courseid, $userid) {
    global $DB, $CFG;

    try {
        // Get active model for this course
        $model = $DB->get_record('block_spp_models', ['courseid' => $courseid, 'active' => 1, 'trainstatus' => 'complete']);

        if (!$model || empty($model->modelid)) {
            throw new \moodle_exception('noactivemodel', 'block_studentperformancepredictor');
        }

        // Get student data
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/gradelib.php');

        // Gather student features from Moodle data
        $features = [];

        // Basic user data
        $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, email, lastaccess, firstaccess');
        $features['user_id'] = $userid;
        $features['days_since_last_access'] = (time() - max(1, $user->lastaccess)) / 86400; // Convert to days
        $features['days_since_first_access'] = (time() - max(1, $user->firstaccess)) / 86400;

        // Activity level - count of logs in the past week
        $sql = "SELECT COUNT(*) FROM {logstore_standard_log} 
                WHERE userid = :userid AND courseid = :courseid 
                AND timecreated > :weekago";
        $features['activity_level'] = $DB->count_records_sql($sql, [
            'userid' => $userid,
            'courseid' => $courseid,
            'weekago' => time() - 7*24*60*60
        ]);

        // Assignment submission count
        $sql = "SELECT COUNT(*) FROM {assign_submission} sub
                JOIN {assign} a ON sub.assignment = a.id
                WHERE sub.userid = :userid AND a.course = :courseid AND sub.status = 'submitted'";
        $features['submission_count'] = $DB->count_records_sql($sql, [
            'userid' => $userid, 
            'courseid' => $courseid
        ]);

        // Course module access count
        $sql = "SELECT COUNT(DISTINCT contextinstanceid) FROM {logstore_standard_log}
                WHERE userid = :userid AND courseid = :courseid AND action = 'viewed' AND target = 'course_module'";
        $features['modules_accessed'] = $DB->count_records_sql($sql, [
            'userid' => $userid,
            'courseid' => $courseid
        ]);

        // Forum activity
        $sql = "SELECT COUNT(*) FROM {forum_posts} fp
                JOIN {forum_discussions} fd ON fp.discussion = fd.id
                JOIN {forum} f ON fd.forum = f.id
                WHERE fp.userid = :userid AND f.course = :courseid";
        $features['forum_posts'] = $DB->count_records_sql($sql, [
            'userid' => $userid,
            'courseid' => $courseid
        ]);

        // Current grade
        $features['current_grade'] = 0;
        $features['current_grade_percentage'] = 0;
        
        if (function_exists('grade_get_course_grade')) {
            try {
                $grade = grade_get_course_grade($userid, $courseid);
                if ($grade && isset($grade->grade) && $grade->grade !== null) {
                    $features['current_grade'] = $grade->grade;
                    if (isset($grade->grade_item->grademax) && $grade->grade_item->grademax > 0) {
                        $features['current_grade_percentage'] = ($grade->grade / $grade->grade_item->grademax) * 100;
                    }
                }
            } catch (\Exception $e) {
                debugging('Could not fetch course grade for user ' . $userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Call the backend API for prediction
        $payload = [
            'model_id' => $model->modelid,
            'features' => $features
        ];

        $response = block_studentperformancepredictor_call_backend_api('predict', $payload);

        if (!$response || !isset($response['prediction']) || !isset($response['probability'])) {
            throw new \moodle_exception('predictionfailed', 'block_studentperformancepredictor');
        }

        // Determine risk level based on pass probability
        $passprob = $response['probability'];

        // Get risk thresholds from settings
        $lowrisk = get_config('block_studentperformancepredictor', 'lowrisk');
        if (empty($lowrisk) || !is_numeric($lowrisk)) {
            $lowrisk = 0.7; // Default
        }

        $mediumrisk = get_config('block_studentperformancepredictor', 'mediumrisk');
        if (empty($mediumrisk) || !is_numeric($mediumrisk)) {
            $mediumrisk = 0.4; // Default
        }

        if ($passprob >= $lowrisk) {
            $riskvalue = 1; // Low risk
        } else if ($passprob >= $mediumrisk) {
            $riskvalue = 2; // Medium risk
        } else {
            $riskvalue = 3; // High risk
        }

        // Save prediction to database
        $prediction = new \stdClass();
        $prediction->modelid = $model->id;
        $prediction->courseid = $courseid;
        $prediction->userid = $userid;
        $prediction->passprob = $passprob;
        $prediction->riskvalue = $riskvalue;
        $prediction->predictiondata = json_encode($response);
        $prediction->timecreated = time();
        $prediction->timemodified = time();

        $predictionid = $DB->insert_record('block_spp_predictions', $prediction);

        // Get the inserted prediction
        $prediction = $DB->get_record('block_spp_predictions', ['id' => $predictionid]);

        // Generate suggestions based on the prediction
        block_studentperformancepredictor_generate_suggestions($prediction);

        return $prediction;

    } catch (\Exception $e) {
        debugging('Error generating prediction: ' . $e->getMessage(), DEBUG_DEVELOPER);
        return false;
    }
}


/**
 * Generate a prediction for a student.
 *
 * @param int $courseid Course ID
 * @param int $userid User ID
 * @return int|bool Prediction ID or false on failure
 */
function block_studentperformancepredictor_generate_prediction($courseid, $userid) {
    // Use the backend-driven prediction system
    $prediction = block_studentperformancepredictor_generate_prediction_via_backend($courseid, $userid);

    if ($prediction) {
        return $prediction->id;
    }

    return false;
}

/**
 * Trigger prediction generation adhoc task based on user role.
 *
 * @param int $courseid Course ID
 * @param int $userid User ID (optional, for specific student)
 * @param int $requestorid User ID of the person requesting the prediction (optional)
 * @return bool Success
 */
function block_studentperformancepredictor_trigger_prediction_task($courseid, $userid = null, $requestorid = null) {
    global $USER, $DB;

    // If no requestor specified, use current user
    if ($requestorid === null) {
        $requestorid = $USER->id;
    }

    // Prepare task data
    $taskdata = [
        'courseid' => $courseid,
        'requestor' => $requestorid
    ];

    // If a specific userid is provided, add it to the task data
    if ($userid !== null) {
        $taskdata['userid'] = $userid;
    }

    // Create adhoc task
    $task = new \block_studentperformancepredictor\task\adhoc_prediction_refresh();
    $task->set_custom_data((object)$taskdata);

    // Queue task with high priority
    \core\task\manager::queue_adhoc_task($task, true);

    // Update last request time
    if (!$userid) {
        // If this is a bulk request, update the last refresh timestamp
        set_config('lastrefresh_requested_' . $courseid, time(), 'block_studentperformancepredictor');
    }

    return true;
}

/**
 * Generate a new prediction for a student on demand.
 * This function is called when a student clicks the prediction button.
 *
 * @param int $courseid Course ID
 * @param int $userid User ID
 * @return object|bool Prediction object or false on failure
 */
function block_studentperformancepredictor_generate_new_prediction($courseid, $userid) {
    global $DB, $USER;

    try {
        // First check if there is an active model
        if (!block_studentperformancepredictor_has_active_model($courseid)) {
            throw new \moodle_exception('noactivemodel', 'block_studentperformancepredictor');
        }

        // Check permissions based on who is requesting the prediction
        $context = context_course::instance($courseid);

        if ($USER->id == $userid) {
            // Student generating their own prediction - permission already checked in calling code
            $prediction = block_studentperformancepredictor_generate_prediction_via_backend($courseid, $userid);
        } else if (has_capability('block/studentperformancepredictor:viewallpredictions', $context)) {
            // Teacher/admin generating prediction for a student
            // Check if the student is enrolled in this course
            if (!is_enrolled($context, $userid)) {
                throw new \moodle_exception('studentnotenrolled', 'block_studentperformancepredictor');
            }

            // Queue prediction task and return existing prediction in the meantime
            block_studentperformancepredictor_trigger_prediction_task($courseid, $userid, $USER->id);

            // Return current prediction if exists, or generate a new one immediately
            $prediction = $DB->get_record_sql(
                "SELECT p.* FROM {block_spp_predictions} p
                 JOIN {block_spp_models} m ON p.modelid = m.id
                 WHERE p.courseid = ? AND p.userid = ? AND m.active = 1
                 ORDER BY p.timemodified DESC LIMIT 1",
                [$courseid, $userid]
            );

            if (!$prediction) {
                // No existing prediction, generate one now
                $prediction = block_studentperformancepredictor_generate_prediction_via_backend($courseid, $userid);
            }
        } else {
            throw new \moodle_exception('nopermission', 'error');
        }

        if (!$prediction) {
            throw new \moodle_exception('predictionfailed', 'block_studentperformancepredictor');
        }

        // Log the action
        $event = \core\event\notification_viewed::create([
            'contextid' => context_course::instance($courseid)->id,
            'objectid' => $prediction->id,
            'other' => [
                'type' => 'new_prediction',
                'courseid' => $courseid
            ]
        ]);
        $event->trigger();

        return $prediction;
    } catch (Exception $e) {
        debugging('Error generating new prediction: ' . $e->getMessage(), DEBUG_DEVELOPER);
        return false;
    }
}

/**
 * Generate suggestions for a student based on their prediction.
 *
 * @param object $prediction Prediction object
 * @return array Array of suggestion IDs
 */
function block_studentperformancepredictor_generate_suggestions($prediction) {
    global $DB;

    // Get course context
    $context = \context_course::instance($prediction->courseid);
    $course = get_course($prediction->courseid);

    // Get available course modules
    $modinfo = get_fast_modinfo($prediction->courseid);
    $cms = $modinfo->get_cms();

    // Get completion info
    $completion = new \completion_info($course);

    // Get grades for course activities
    $grades = \grade_get_grades($prediction->courseid, 'mod', null, null, $prediction->userid);

    // Generate suggestions based on risk level
    $suggestions = [];
    $risk_level = $prediction->riskvalue;

    // Check for overdue assignments
    $overdueAssignments = [];
    $upcomingAssignments = [];
    $missedQuizzes = [];
    $lowGradeActivities = [];

    foreach ($cms as $cm) {
        // Skip invisible modules or labels
        if (!$cm->uservisible || $cm->modname == 'label') {
            continue;
        }

        // Check if activity is completed
        $completion_data = $completion->get_data($cm, false, $prediction->userid);
        $is_completed = isset($completion_data->completionstate) && 
                        $completion_data->completionstate == COMPLETION_COMPLETE;

        if (!$is_completed) {
            // Process by module type for targeted suggestions
            switch ($cm->modname) {
                case 'assign':
                    // Get assignment details
                    $assignment = $DB->get_record('assign', ['id' => $cm->instance]);
                    if ($assignment) {
                        if ($assignment->duedate > 0) {
                            if ($assignment->duedate < time()) {
                                // Overdue assignment
                                $overdueAssignments[] = [
                                    'cm' => $cm,
                                    'instance' => $assignment,
                                    'daysoverdue' => floor((time() - $assignment->duedate) / 86400),
                                    'priority' => 10 // High priority
                                ];
                            } else if ($assignment->duedate < (time() + 7*86400)) {
                                // Assignment due within next week
                                $upcomingAssignments[] = [
                                    'cm' => $cm,
                                    'instance' => $assignment,
                                    'daysuntildue' => floor(($assignment->duedate - time()) / 86400),
                                    'priority' => 8 // Medium-high priority
                                ];
                            }
                        }
                    }
                    break;

                case 'quiz':
                    // Get quiz details
                    $quiz = $DB->get_record('quiz', ['id' => $cm->instance]);
                    if ($quiz) {
                        if ($quiz->timeclose > 0 && $quiz->timeclose < time()) {
                            // Missed quiz
                            $missedQuizzes[] = [
                                'cm' => $cm,
                                'instance' => $quiz,
                                'daysmissed' => floor((time() - $quiz->timeclose) / 86400),
                                'priority' => 7 // Medium priority
                            ];
                        } else if (!$quiz->timeclose || $quiz->timeclose > time()) {
                            // Available quiz
                            $attempts = $DB->count_records('quiz_attempts', [
                                'quiz' => $quiz->id,
                                'userid' => $prediction->userid,
                                'state' => 'finished'
                            ]);

                            if ($attempts == 0) {
                                // Never attempted quiz
                                $missedQuizzes[] = [
                                    'cm' => $cm,
                                    'instance' => $quiz,
                                    'priority' => 9 // High priority if never attempted
                                ];
                            }
                        }
                    }
                    break;

                case 'forum':
                    // Check forum participation
                    $posts = $DB->count_records_sql(
                        "SELECT COUNT(*) FROM {forum_posts} fp
                         JOIN {forum_discussions} fd ON fp.discussion = fd.id
                         WHERE fd.forum = ? AND fp.userid = ?",
                        [$cm->instance, $prediction->userid]
                    );

                    if ($posts == 0) {
                        // No participation in forum
                        $lowGradeActivities[] = [
                            'cm' => $cm,
                            'priority' => $risk_level >= 2 ? 6 : 4 // Higher priority for higher risk
                        ];
                    }
                    break;

                default:
                    // General incomplete activity
                    $lowGradeActivities[] = [
                        'cm' => $cm,
                        'priority' => 3 // Lower priority
                    ];
                    break;
            }

            // Check grades for this activity if available
            if (isset($grades->items) && !empty($grades->items)) {
                foreach ($grades->items as $grade_item) {
                    if ($grade_item->iteminstance == $cm->instance && $grade_item->itemmodule == $cm->modname) {
                        if (isset($grade_item->grades[$prediction->userid]) && 
                            $grade_item->grades[$prediction->userid]->grade !== null) {

                            $grade = $grade_item->grades[$prediction->userid]->grade;
                            $grademax = $grade_item->grademax;

                            if ($grademax > 0 && ($grade / $grademax) < 0.6) {
                                // Low grade (less than 60%)
                                $lowGradeActivities[] = [
                                    'cm' => $cm,
                                    'grade' => $grade,
                                    'grademax' => $grademax,
                                    'percentage' => round(($grade / $grademax) * 100),
                                    'priority' => 7 // Medium-high priority for low grades
                                ];
                            }
                        }
                    }
                }
            }
        }
    }

    // Now create the actual suggestions based on what we found, starting with highest priority items

    // 1. Overdue assignments (highest priority)
    foreach ($overdueAssignments as $item) {
        $suggestion = new \stdClass();
        $suggestion->predictionid = $prediction->id;
        $suggestion->courseid = $prediction->courseid;
        $suggestion->userid = $prediction->userid;
        $suggestion->cmid = $item['cm']->id;
        $suggestion->resourcetype = 'assign';
        $suggestion->resourceid = $item['cm']->instance;
        $suggestion->priority = $item['priority'] + ($risk_level - 1); // Adjust priority based on risk

        // Customize reason based on days overdue
        if ($item['daysoverdue'] > 7) {
            $suggestion->reason = get_string('suggestion_assign_overdue_urgent', 'block_studentperformancepredictor', 
                ['days' => $item['daysoverdue'], 'name' => $item['cm']->name, 'coursename' => $course->fullname]);
        } else {
            $suggestion->reason = get_string('suggestion_assign_overdue', 'block_studentperformancepredictor', 
                ['days' => $item['daysoverdue'], 'name' => $item['cm']->name, 'coursename' => $course->fullname]);
        }

        $suggestion->timecreated = time();
        $suggestion->viewed = 0;
        $suggestion->completed = 0;

        $suggestions[] = $DB->insert_record('block_spp_suggestions', $suggestion);
    }

    // 2. Upcoming assignments (high priority)
    foreach ($upcomingAssignments as $item) {
        $suggestion = new \stdClass();
        $suggestion->predictionid = $prediction->id;
        $suggestion->courseid = $prediction->courseid;
        $suggestion->userid = $prediction->userid;
        $suggestion->cmid = $item['cm']->id;
        $suggestion->resourcetype = 'assign';
        $suggestion->resourceid = $item['cm']->instance;
        $suggestion->priority = $item['priority'] + ($risk_level - 1); // Adjust priority based on risk

        // Customize reason based on days until due
        if ($item['daysuntildue'] <= 2) {
            $suggestion->reason = get_string('suggestion_assign_due_soon', 'block_studentperformancepredictor', 
                ['days' => $item['daysuntildue'], 'name' => $item['cm']->name, 'coursename' => $course->fullname]);
        } else {
            $suggestion->reason = get_string('suggestion_assign_upcoming', 'block_studentperformancepredictor', 
                ['days' => $item['daysuntildue'], 'name' => $item['cm']->name, 'coursename' => $course->fullname]);
        }

        $suggestion->timecreated = time();
        $suggestion->viewed = 0;
        $suggestion->completed = 0;

        $suggestions[] = $DB->insert_record('block_spp_suggestions', $suggestion);
    }

    // 3. Missed quizzes (medium-high priority)
    foreach ($missedQuizzes as $item) {
        $suggestion = new \stdClass();
        $suggestion->predictionid = $prediction->id;
        $suggestion->courseid = $prediction->courseid;
        $suggestion->userid = $prediction->userid;
        $suggestion->cmid = $item['cm']->id;
        $suggestion->resourcetype = 'quiz';
        $suggestion->resourceid = $item['cm']->instance;
        $suggestion->priority = $item['priority'] + ($risk_level - 1); // Adjust priority based on risk

        if (isset($item['daysmissed'])) {
            $suggestion->reason = get_string('suggestion_quiz_missed', 'block_studentperformancepredictor', 
                ['name' => $item['cm']->name, 'coursename' => $course->fullname]);
        } else {
            $suggestion->reason = get_string('suggestion_quiz_not_attempted', 'block_studentperformancepredictor', 
                ['name' => $item['cm']->name, 'coursename' => $course->fullname]);
        }

        $suggestion->timecreated = time();
        $suggestion->viewed = 0;
        $suggestion->completed = 0;

        $suggestions[] = $DB->insert_record('block_spp_suggestions', $suggestion);
    }

    // 4. Low grade activities (medium priority)
    // Limit the number based on risk level to avoid overwhelming the student
    $maxLowGradeItems = $risk_level == 3 ? 3 : ($risk_level == 2 ? 2 : 1);
    $lowGradeActivities = array_slice($lowGradeActivities, 0, $maxLowGradeItems);

    foreach ($lowGradeActivities as $item) {
        $suggestion = new \stdClass();
        $suggestion->predictionid = $prediction->id;
        $suggestion->courseid = $prediction->courseid;
        $suggestion->userid = $prediction->userid;
        $suggestion->cmid = $item['cm']->id;
        $suggestion->resourcetype = $item['cm']->modname;
        $suggestion->resourceid = $item['cm']->instance;
        $suggestion->priority = $item['priority'] + ($risk_level - 1); // Adjust priority based on risk

        if (isset($item['percentage'])) {
            // Low grade suggestion
            $suggestion->reason = get_string('suggestion_improve_grade', 'block_studentperformancepredictor', 
                ['name' => $item['cm']->name, 'percentage' => $item['percentage'], 
                 'coursename' => $course->fullname]);
        } else if ($item['cm']->modname == 'forum') {
            // Forum participation suggestion
            $suggestion->reason = get_string('suggestion_forum_participate', 'block_studentperformancepredictor', 
                ['name' => $item['cm']->name, 'coursename' => $course->fullname]);
        } else {
            // Generic activity suggestion
            $suggestion->reason = get_string('suggestion_complete_activity', 'block_studentperformancepredictor', 
                ['name' => $item['cm']->name, 'coursename' => $course->fullname]);
        }

        $suggestion->timecreated = time();
        $suggestion->viewed = 0;
        $suggestion->completed = 0;

        $suggestions[] = $DB->insert_record('block_spp_suggestions', $suggestion);
    }

    // 5. Add general suggestions based on risk level
    $general_suggestions = [];

    // Add time management suggestion (customize based on risk level)
    if ($risk_level == 3) {
        $general_suggestions[] = [
            'reason' => get_string('suggestion_time_management_urgent', 'block_studentperformancepredictor', 
                                 ['coursename' => $course->fullname]),
            'priority' => 5
        ];
    } else {
        $general_suggestions[] = [
            'reason' => get_string('suggestion_time_management', 'block_studentperformancepredictor', 
                                 ['coursename' => $course->fullname]),
            'priority' => 3 + $risk_level
        ];
    }

    // Add course-specific engagement suggestions
    if ($risk_level >= 2) {
        $general_suggestions[] = [
            'reason' => get_string('suggestion_engagement_course', 'block_studentperformancepredictor', 
                                 ['coursename' => $course->fullname]),
            'priority' => 4 + $risk_level
        ];
    }

    // For high risk, suggest contacting the instructor and joining study groups
    if ($risk_level == 3) {
        // Find the teacher's name
        $teacher_name = '';
        $role = $DB->get_record('role', ['shortname' => 'editingteacher']);
        if ($role) {
            $teachers = get_role_users($role->id, $context);
            if (!empty($teachers)) {
                $teacher = reset($teachers);
                $teacher_name = fullname($teacher);
            }
        }

        if (!empty($teacher_name)) {
            $general_suggestions[] = [
                'reason' => get_string('suggestion_contact_teacher', 'block_studentperformancepredictor', 
                                     ['teacher' => $teacher_name, 'coursename' => $course->fullname]),
                'priority' => 8
            ];
        } else {
            $general_suggestions[] = [
                'reason' => get_string('suggestion_instructor_help', 'block_studentperformancepredictor', 
                                     ['coursename' => $course->fullname]),
                'priority' => 8
            ];
        }

        $general_suggestions[] = [
            'reason' => get_string('suggestion_study_group_course', 'block_studentperformancepredictor', 
                                 ['coursename' => $course->fullname]),
            'priority' => 7
        ];
    }

    // Add general suggestions
    foreach ($general_suggestions as $gen_suggestion) {
        $suggestion = new \stdClass();
        $suggestion->predictionid = $prediction->id;
        $suggestion->courseid = $prediction->courseid;
        $suggestion->userid = $prediction->userid;
        $suggestion->cmid = 0; // No specific module
        $suggestion->resourcetype = 'general';
        $suggestion->resourceid = 0;
        $suggestion->priority = $gen_suggestion['priority'];
        $suggestion->reason = $gen_suggestion['reason'];
        $suggestion->timecreated = time();
        $suggestion->viewed = 0;
        $suggestion->completed = 0;

        $suggestions[] = $DB->insert_record('block_spp_suggestions', $suggestion);
    }

    // If we have no suggestions at all, add a generic one
    if (empty($suggestions)) {
        $suggestion = new \stdClass();
        $suggestion->predictionid = $prediction->id;
        $suggestion->courseid = $prediction->courseid;
        $suggestion->userid = $prediction->userid;
        $suggestion->cmid = 0; // No specific module
        $suggestion->resourcetype = 'general';
        $suggestion->resourceid = 0;
        $suggestion->priority = 5;
        $suggestion->reason = get_string('suggestion_generic', 'block_studentperformancepredictor', 
                             ['coursename' => $course->fullname]);
        $suggestion->timecreated = time();
        $suggestion->viewed = 0;
        $suggestion->completed = 0;

        $suggestions[] = $DB->insert_record('block_spp_suggestions', $suggestion);
    }

    return $suggestions;
}

/**
 * Refresh predictions for a course via the backend.
 *
 * @param int $courseid Course ID
 * @return array Result statistics
 */
function block_studentperformancepredictor_refresh_predictions_via_backend($courseid) {
    global $DB;

    $context = \context_course::instance($courseid);
    $students = get_enrolled_users($context, 'moodle/course:isincompletionreports');

    $result = [
        'success' => 0,
        'errors' => 0,
        'total' => count($students)
    ];

    // Get active model - course model first, then global if enabled
    $model = $DB->get_record('block_spp_models', ['courseid' => $courseid, 'active' => 1, 'trainstatus' => 'complete']);

    if (!$model) {
        return $result;
    }

    // Call backend for each student
    foreach ($students as $student) {
        try {
            $predictionid = block_studentperformancepredictor_generate_prediction($courseid, $student->id);
            if ($predictionid) {
                $result['success']++;
            } else {
                $result['errors']++;
            }
        } catch (Exception $e) {
            debugging('Error refreshing prediction for student ' . $student->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            $result['errors']++;
        }
    }

    // Update last refresh time
    set_config('lastrefresh_' . $courseid, time(), 'block_studentperformancepredictor');

    return $result;
}

<?php
// blocks/studentperformancepredictor/reports.php

require_once('../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

// Get parameters
$courseid = optional_param('courseid', 0, PARAM_INT);

// Set up page context
$PAGE->set_context(context_system::instance());
require_login();

$courses_to_display = [];

if (is_siteadmin()) {
    // Admins can see all courses
    $allcourses = get_courses();
    foreach ($allcourses as $course) {
        if ($course->id != SITEID) {
            $courses_to_display[$course->id] = $course;
        }
    }
} else {
    // Teachers only see courses they are enrolled in
    $mycourses = enrol_get_my_courses(null, 'fullname ASC');
    foreach ($mycourses as $course) {
        if ($course->id == SITEID) {
            continue;
        }
        $coursecontext = context_course::instance($course->id);
        if (has_capability('block/studentperformancepredictor:viewallpredictions', $coursecontext)) {
            $courses_to_display[$course->id] = $course;
        }
    }
}


if (empty($courses_to_display)) {
    throw new moodle_exception('nocoursesfound', 'block_studentperformancepredictor');
}

// Determine which course to display
if ($courseid == 0 || !isset($courses_to_display[$courseid])) {
    // If no course is selected or the selected course is invalid, default to the first one
    $firstcourse = reset($courses_to_display);
    $courseid = $firstcourse->id;
}

$course = get_course($courseid);
$context = context_course::instance($courseid);

// Set up page layout
$PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/reports.php', array('courseid' => $courseid)));
$PAGE->set_title(get_string('detailedreport', 'block_studentperformancepredictor'));
$PAGE->set_heading(get_string('detailedreport', 'block_studentperformancepredictor'));
$PAGE->set_pagelayout('standard');

// Output starts here
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('detailedreport', 'block_studentperformancepredictor'));

// Show teacher view with course selector
$renderer = $PAGE->get_renderer('block_studentperformancepredictor');
$teacherview = new \block_studentperformancepredictor\output\teacher_view($courseid, $courses_to_display);
echo $renderer->render_teacher_view($teacherview);

// Output footer
echo $OUTPUT->footer();

<?php
// blocks/studentperformancepredictor/settings.php

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    // Backend integration settings
    $settings->add(new admin_setting_heading('block_studentperformancepredictor/backendsettings',
        get_string('backendsettings', 'block_studentperformancepredictor'),
        get_string('backendsettings_desc', 'block_studentperformancepredictor')));

    // For Railway deployment, the default URL should match the Railway app URL
    $settings->add(new admin_setting_configtext('block_studentperformancepredictor/python_api_url',
        get_string('python_api_url', 'block_studentperformancepredictor'),
        get_string('python_api_url_desc', 'block_studentperformancepredictor'),
        'https://your-railway-app-name.up.railway.app'));

    $settings->add(new admin_setting_configpasswordunmask('block_studentperformancepredictor/python_api_key',
        get_string('python_api_key', 'block_studentperformancepredictor'),
        get_string('python_api_key_desc', 'block_studentperformancepredictor'),
        'changeme'));

    // Refresh interval for automatic predictions
    $settings->add(new admin_setting_configtext('block_studentperformancepredictor/refreshinterval',
        get_string('refreshinterval', 'block_studentperformancepredictor'),
        get_string('refreshinterval_desc', 'block_studentperformancepredictor'),
        24, PARAM_INT));

    // Risk threshold settings
    $settings->add(new admin_setting_heading('block_studentperformancepredictor/riskthresholds',
        get_string('riskthresholds', 'block_studentperformancepredictor'),
        get_string('riskthresholds_desc', 'block_studentperformancepredictor')));

    $settings->add(new admin_setting_configtext('block_studentperformancepredictor/lowrisk',
        get_string('lowrisk', 'block_studentperformancepredictor'),
        get_string('lowrisk_desc', 'block_studentperformancepredictor'),
        0.7, PARAM_FLOAT));

    $settings->add(new admin_setting_configtext('block_studentperformancepredictor/mediumrisk',
        get_string('mediumrisk', 'block_studentperformancepredictor'),
        get_string('mediumrisk_desc', 'block_studentperformancepredictor'),
        0.4, PARAM_FLOAT));

    // Algorithm settings
    $settings->add(new admin_setting_heading('block_studentperformancepredictor/algorithmsettings',
        get_string('algorithmsettings', 'block_studentperformancepredictor'),
        get_string('algorithmsettings_desc', 'block_studentperformancepredictor')));

    $algorithms = [
        'randomforest' => get_string('algorithm_randomforest', 'block_studentperformancepredictor'),
        'logisticregression' => get_string('algorithm_logisticregression', 'block_studentperformancepredictor'),
        'svm' => get_string('algorithm_svm', 'block_studentperformancepredictor'),
        'decisiontree' => get_string('algorithm_decisiontree', 'block_studentperformancepredictor'),
        'knn' => get_string('algorithm_knn', 'block_studentperformancepredictor')
    ];

    $settings->add(new admin_setting_configselect('block_studentperformancepredictor/defaultalgorithm',
        get_string('defaultalgorithm', 'block_studentperformancepredictor'),
        get_string('defaultalgorithm_desc', 'block_studentperformancepredictor'),
        'randomforest', $algorithms));

    // Python backend monitoring settings
    $settings->add(new admin_setting_heading('block_studentperformancepredictor/backendmonitoring',
        get_string('backendmonitoring', 'block_studentperformancepredictor', '', true),
        get_string('backendmonitoring_desc', 'block_studentperformancepredictor', '', true)));

    // Add a button to test the backend connection
    $testbackendurl = new moodle_url('/blocks/studentperformancepredictor/admin/testbackend.php');
    $settings->add(new admin_setting_description(
        'block_studentperformancepredictor/testbackend',
        get_string('testbackend', 'block_studentperformancepredictor', '', true),
        html_writer::link($testbackendurl, get_string('testbackendbutton', 'block_studentperformancepredictor'),
            ['class' => 'btn btn-secondary', 'target' => '_blank'])
    ));

    // Debug mode settings
    $settings->add(new admin_setting_heading('block_studentperformancepredictor/debugsettings',
        get_string('debugsettings', 'block_studentperformancepredictor', '', true),
        get_string('debugsettings_desc', 'block_studentperformancepredictor', '', true)));

    $settings->add(new admin_setting_configcheckbox('block_studentperformancepredictor/enabledebug',
        get_string('enabledebug', 'block_studentperformancepredictor', '', true),
        get_string('enabledebug_desc', 'block_studentperformancepredictor', '', true),
        0));
}

/* Main block styles */
.block_studentperformancepredictor {
    padding: 15px;
}

.block_studentperformancepredictor .spp-heading {
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
    font-size: 1.2rem;
    font-weight: 600;
}

/* Accessibility: Focus styles for all interactive elements */
.block_studentperformancepredictor a:focus,
.block_studentperformancepredictor button:focus,
.block_studentperformancepredictor .btn:focus,
.block_studentperformancepredictor [tabindex]:focus {
    outline: 2px solid #005cbf;
    outline-offset: 2px;
    box-shadow: 0 0 0 2px #b8daff;
}
.block_studentperformancepredictor a:focus-visible,
.block_studentperformancepredictor button:focus-visible,
.block_studentperformancepredictor .btn:focus-visible {
    outline: 2px solid #005cbf;
    outline-offset: 2px;
}

/* Prediction stats styles */
.block_studentperformancepredictor .spp-prediction-stats {
    background-color: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.block_studentperformancepredictor .spp-probability {
    margin-bottom: 10px;
}

.block_studentperformancepredictor .spp-probability .spp-label,
.block_studentperformancepredictor .spp-risk .spp-label {
    display: block;
    font-weight: 500;
    margin-bottom: 5px;
}

.block_studentperformancepredictor .spp-probability .spp-value {
    font-size: 1.5rem;
    font-weight: 600;
}

/* Risk level styles with improved contrast */
.block_studentperformancepredictor .spp-risk {
    margin-bottom: 10px;
}

.block_studentperformancepredictor .spp-risk .spp-value {
    font-weight: 600;
}

.block_studentperformancepredictor .spp-risk-high {
    color: #b3001b;
    background: #f8d7da;
    border-radius: 4px;
    padding: 2px 6px;
}
.block_studentperformancepredictor .spp-risk-medium {
    color: #b36b00;
    background: #fff3cd;
    border-radius: 4px;
    padding: 2px 6px;
}
.block_studentperformancepredictor .spp-risk-low {
    color: #1e7e34;
    background: #d4edda;
    border-radius: 4px;
    padding: 2px 6px;
}
.block_studentperformancepredictor .spp-risk-unknown {
    color: #6c757d;
    background: #e2e3e5;
    border-radius: 4px;
    padding: 2px 6px;
}

/* Update time styles */
.block_studentperformancepredictor .spp-update-time {
    font-size: 0.85rem;
    color: #6c757d;
    margin-top: 5px;
}

/* Chart container styles */
.block_studentperformancepredictor .spp-chart-container {
    height: 250px;
    margin: 15px 0;
}

/* Suggestions styles */
.block_studentperformancepredictor .spp-suggestions {
    margin-top: 20px;
}

.block_studentperformancepredictor .spp-suggestions h5 {
    margin-bottom: 15px;
    font-size: 1.1rem;
    font-weight: 500;
}

.block_studentperformancepredictor .spp-suggestions-list .list-group-item {
    transition: background-color 0.2s;
    border-left: 3px solid transparent;
}

.block_studentperformancepredictor .spp-suggestions-list .list-group-item:hover {
    background-color: #f8f9fa;
}

.block_studentperformancepredictor .spp-suggestion-content h6 {
    margin-bottom: 8px;
    font-weight: 500;
}

.block_studentperformancepredictor .spp-suggestion-content p {
    color: #495057;
    margin-bottom: 10px;
}

.block_studentperformancepredictor .spp-suggestion-actions {
    margin-top: 10px;
}

.block_studentperformancepredictor .spp-suggestion-actions .btn {
    margin-right: 5px;
    margin-bottom: 5px;
}

.block_studentperformancepredictor .spp-suggestion-actions .badge {
    margin-right: 5px;
}

/* Admin interface styles */
.block_studentperformancepredictor .spp-admin-actions {
    margin-bottom: 20px;
}

.block_studentperformancepredictor .spp-course-overview {
    background-color: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.block_studentperformancepredictor .spp-stat-total {
    margin-bottom: 15px;
    border-bottom: 1px solid #e9ecef;
    padding-bottom: 10px;
}

.block_studentperformancepredictor .spp-stat-total .spp-label {
    font-weight: 500;
}

.block_studentperformancepredictor .spp-stat-total .spp-value {
    font-size: 1.5rem;
    font-weight: 600;
    margin-left: 10px;
}

.block_studentperformancepredictor .spp-risk-distribution .spp-label {
    display: inline-block;
    width: 100px;
    font-weight: 500;
}

.block_studentperformancepredictor .spp-risk-distribution > div {
    margin-bottom: 8px;
}

/* Loading indicator */
.block_studentperformancepredictor .spp-loading {
    text-align: center;
    padding: 20px;
    color: #6c757d;
}

.block_studentperformancepredictor .spp-loading i {
    margin-right: 10px;
}

/* Form styles */
.block_studentperformancepredictor .spp-form-container {
    background-color: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.block_studentperformancepredictor .spp-form-container .form-group {
    margin-bottom: 15px;
}

/* Table styles */
.block_studentperformancepredictor .spp-table {
    width: 100%;
    margin-bottom: 20px;
}

.block_studentperformancepredictor .spp-table th {
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    padding: 12px;
    font-weight: 500;
}

.block_studentperformancepredictor .spp-table td {
    padding: 12px;
    border-bottom: 1px solid #dee2e6;
}

.block_studentperformancepredictor .spp-table tr:hover {
    background-color: #f8f9fa;
}

/* Course selector styling */
.spp-course-selector-container {
    margin-bottom: 15px;
}

.spp-course-selector {
    width: 100%;
    max-width: 100%;
    margin-bottom: 10px;
}

.spp-course-selector .custom-select {
    width: 100%;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 8px 12px;
    background-color: #f8f9fa;
}

.spp-course-selector .custom-select:focus {
    border-color: #80bdff;
    outline: 0;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

/* Global model indicator */
.spp-model-type {
    margin-top: 5px;
    padding: 5px;
    background-color: #f8f9fa;
    border-radius: 4px;
    font-size: 0.85rem;
    border-left: 3px solid #17a2b8;
}

/* Responsive styles */
@media (max-width: 768px) {
    .block_studentperformancepredictor .spp-chart-container {
        height: 200px;
    }
    .block_studentperformancepredictor .col-md-6 {
        margin-bottom: 15px;
    }
    .block_studentperformancepredictor .spp-admin-actions .btn-group {
        display: flex;
        flex-direction: column;
        width: 100%;
    }
    .block_studentperformancepredictor .spp-admin-actions .btn {
        margin-bottom: 5px;
        border-radius: 4px !important;
    }
    .block_studentperformancepredictor .spp-risk-distribution .spp-label {
        width: auto;
        margin-right: 10px;
    }
    .block_studentperformancepredictor .spp-heading {
        font-size: 1rem;
    }
    .spp-course-selector {
        margin-bottom: 15px;
    }

    .spp-course-selector .custom-select {
        font-size: 14px;
    }
}

/* Risk category cards */
.spp-risk-card {
    margin-bottom: 15px;
    border: none;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.spp-risk-card .card-header {
    cursor: pointer;
    transition: background-color 0.3s;
}

.spp-risk-card .card-header:hover {
    background-color: #f0f0f0;
}

.spp-risk-high-card .card-header {
    background-color: #f8d7da;
    color: #721c24;
    border-left: 4px solid #dc3545;
}

.spp-risk-medium-card .card-header {
    background-color: #fff3cd;
    color: #856404;
    border-left: 4px solid #ffc107;
}

.spp-risk-low-card .card-header {
    background-color: #d4edda;
    color: #155724;
    border-left: 4px solid #28a745;
}

/* Student table styling */
.spp-risk-card .table {
    margin-bottom: 0;
}

.spp-risk-card .table th {
    font-weight: 500;
    border-top: none;
}

.spp-risk-card ul.list-unstyled li {
    margin-bottom: 5px;
}

/* Badge styling */
.spp-risk-card .badge {
    font-size: 90%;
    padding: 5px 8px;
}

/* Admin dashboard styling */
.admin-dashboard .spp-courses-table {
    margin-top: 20px;
    margin-bottom: 20px;
}

.admin-dashboard .spp-courses-table .table {
    background-color: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border-radius: 4px;
}

.admin-dashboard .spp-courses-table .table th {
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}

.admin-dashboard .badge {
    font-size: 90%;
    font-weight: 400;
    padding: 4px 7px;
}

/* Course filter styling */
.spp-course-filter {
    margin-bottom: 20px;
}

.spp-course-filter .alert {
    padding: 10px 15px;
    margin-bottom: 0;
}

/* Hover effect for course rows */
.spp-courses-table tbody tr {
    transition: background-color 0.2s;
}

.spp-courses-table tbody tr:hover {
    background-color: #f0f4f7;
}

/* Additional risk indicator styling */
.admin-dashboard .spp-risk-high-card {
    border-left: 4px solid #dc3545;
}

.admin-dashboard .spp-risk-medium-card {
    border-left: 4px solid #ffc107;
}

.admin-dashboard .spp-risk-low-card {
    border-left: 4px solid #28a745;
}

/* Course name and badge positioning */
.spp-courses-table .badge {
    margin-left: 5px;
    vertical-align: middle;
}

/* Add spacing between buttons */
.btn-group .btn {
    margin-right: 5px;
}

.btn-group .btn:last-child {
    margin-right: 0;
}

/* Better spacing for standalone buttons */
.spp-admin-controls .btn,
.spp-teacher-actions .btn,
.mt-3 .btn {
    margin-right: 10px;
    margin-bottom: 5px;
}

/* Adjust spacing between stats and risk lists */
.block_studentperformancepredictor .spp-course-overview {
    margin-bottom: 20px;
}

.block_studentperformancepredictor .spp-stats {
    margin-bottom: 15px;
}

.block_studentperformancepredictor .spp-student-risk-sections {
    margin-top: 20px;  /* Reduced from larger value */
}

/* Spacing within stats display */
.block_studentperformancepredictor .spp-stat-total {
    margin-bottom: 10px;
    padding-bottom: 10px;
}

.block_studentperformancepredictor .spp-risk-distribution > div {
    margin-bottom: 5px;  /* Reduced from 8px */
}

/* Success highlight for updated rows */
tr.table-success {
    background-color: #d4edda !important;
    transition: background-color 1s;
}

/* Model metrics styling */
.model-metrics {
    margin-bottom: 20px;
    background-color: #f8f9fa;
    border-radius: 8px;
}

.model-metrics h4 {
    margin-bottom: 15px;
}

.model-metrics .list-group-item-warning {
    background-color: #fff3cd;
    color: #856404;
}

/* Badge with warning */
.badge.badge-warning {
    background-color: #ffc107;
    color: #212529;
}

/* Feature importance visualization */
.model-metrics .list-group-item {
    position: relative;
    padding-right: 40px;
}

.model-metrics .list-group-item .importance-bar {
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    background-color: rgba(40, 167, 69, 0.1);
    z-index: 0;
}

/* Badge indicators */
.badge-success, .badge-warning, .badge-danger {
    font-size: 90%;
    font-weight: 400;
    padding: 4px 7px;
}

/* Add spacing between action buttons in tables */
td > .btn, td > form {
    margin-right: 5px;
}

<?php
// blocks/studentperformancepredictor/version.php

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2025063006;        // The current plugin version (Date: YYYYMMDDXX).
$plugin->requires  = 2022112800;        // Requires Moodle 4.1 or later.
$plugin->component = 'block_studentperformancepredictor'; // Full name of the plugin.
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.1.0';           // Updated for global model support

And I have a another directory for the fastapi for training the model.
ML Backend
├── models
.env
.gitignore
Dockerfile
Procfile
ml_backend.py
railway.json
requirements.txt

That is the directory of the ML Backend and below is the code for each of the file above
API_KEY=changeme
MODELS_DIR=models
DEBUG=true
PORT=8000

# Byte-compiled / optimized / DLL files
__pycache__/
*.py[cod]
*$py.class

# C extensions
*.so

# Distribution / packaging
.Python
build/
develop-eggs/
dist/
downloads/
eggs/
.eggs/
lib/
lib64/
parts/
sdist/
var/
wheels/
share/python-wheels/
*.egg-info/
.installed.cfg
*.egg
MANIFEST

# PyInstaller
#  Usually these files are written by a python script from a template
#  before PyInstaller builds the exe, so as to inject date/other infos into it.
*.manifest
*.spec

# Installer logs
pip-log.txt
pip-delete-this-directory.txt

# Unit test / coverage reports
htmlcov/
.tox/
.nox/
.coverage
.coverage.*
.cache
nosetests.xml
coverage.xml
*.cover
*.py,cover
.hypothesis/
.pytest_cache/
cover/

# Translations
*.mo
*.pot

# Django stuff:
*.log
local_settings.py
db.sqlite3
db.sqlite3-journal

# Flask stuff:
instance/
.webassets-cache

# Scrapy stuff:
.scrapy

# Sphinx documentation
docs/_build/

# PyBuilder
.pybuilder/
target/

# Jupyter Notebook
.ipynb_checkpoints

# IPython
profile_default/
ipython_config.py

# pyenv
#   For a library or package, you might want to ignore these files since the code is
#   intended to run in multiple environments; otherwise, check them in:
# .python-version

# pipenv
#   According to pypa/pipenv#598, it is recommended to include Pipfile.lock in version control.
#   However, in case of collaboration, if having platform-specific dependencies or dependencies
#   having no cross-platform support, pipenv may install dependencies that don't work, or not
#   install all needed dependencies.
#Pipfile.lock

# poetry
#   Similar to Pipfile.lock, it is generally recommended to include poetry.lock in version control.
#   This is especially recommended for binary packages to ensure reproducibility, and is more
#   commonly ignored for libraries.
#   https://python-poetry.org/docs/basic-usage/#commit-your-poetrylock-file-to-version-control
#poetry.lock

# pdm
#   Similar to Pipfile.lock, it is generally recommended to include pdm.lock in version control.
#pdm.lock
#   pdm stores project-wide configurations in .pdm.toml, but it is recommended to not include it
#   in version control.
#   https://pdm.fming.dev/#use-with-ide
.pdm.toml

# PEP 582; used by e.g. github.com/David-OConnor/pyflow and github.com/pdm-project/pdm
__pypackages__/

# Celery stuff
celerybeat-schedule
celerybeat.pid

# SageMath parsed files
*.sage.py

# Environments
.env
.venv
env/
venv/
ENV/
env.bak/
venv.bak/

# Spyder project settings
.spyderproject
.spyproject

# Rope project settings
.ropeproject

# mkdocs documentation
/site

# mypy
.mypy_cache/
.dmypy.json
dmypy.json

# Pyre type checker
.pyre/

# pytype static type analyzer
.pytype/

# Cython debug symbols
cython_debug/

# PyCharm
#  JetBrains specific template is maintained in a separate JetBrains.gitignore that can
#  be found at https://github.com/github/gitignore/blob/main/Global/JetBrains.gitignore
#  and can be added to the global gitignore or merged into this file.  For a more nuclear
#  option (not recommended) you can uncomment the following to ignore the entire idea folder.
#.idea/

# Use Python as base image
FROM python:3.10-slim

# Set working directory
WORKDIR /app

# Install system dependencies required for scientific libraries
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    gcc \
    g++ \
    libgomp1 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copy requirements file
COPY requirements.txt .

# Install dependencies
RUN pip install --no-cache-dir -r requirements.txt

# Copy application code
COPY . .

# Create model directory with proper permissions
RUN mkdir -p models && chmod 777 models

# Expose port based on environment variable
EXPOSE 8000

# Start the application
CMD uvicorn ml_backend:app --host=0.0.0.0 --port=8000

web: uvicorn ml_backend:app --host=0.0.0.0 --port=8000

#!/usr/bin/env python3
"""
Machine Learning API for Student Performance Prediction

An enhanced API that trains models and makes predictions with confidence intervals.
"""

import os
import uuid
import time
import logging
import traceback
import tempfile
import shutil
import re
from datetime import datetime
from typing import Dict, List, Optional, Any, Tuple, Union

import numpy as np
import pandas as pd
import joblib
from fastapi import FastAPI, HTTPException, Depends, Header, Request, status, BackgroundTasks
from fastapi import File, UploadFile, Form
from fastapi.responses import JSONResponse
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field, validator
from dotenv import load_dotenv
from scipy import stats

# ML imports
from sklearn.preprocessing import StandardScaler, OneHotEncoder, RobustScaler
from sklearn.compose import ColumnTransformer
from sklearn.pipeline import Pipeline
from sklearn.model_selection import train_test_split, cross_val_score, StratifiedKFold
from sklearn.metrics import accuracy_score, precision_score, recall_score, f1_score, roc_auc_score, confusion_matrix
from sklearn.ensemble import RandomForestClassifier, ExtraTreesClassifier, AdaBoostClassifier
from sklearn.impute import SimpleImputer
from sklearn.feature_selection import SelectFromModel
from sklearn.calibration import CalibratedClassifierCV

# Import the requested boosting algorithms
try:
    import xgboost as xgb
    from xgboost import XGBClassifier
    XGBOOST_AVAILABLE = True
except ImportError:
    XGBOOST_AVAILABLE = False
    logging.warning("XGBoost not available. Install with: pip install xgboost")

try:
    import catboost
    from catboost import CatBoostClassifier
    CATBOOST_AVAILABLE = True
except ImportError:
    CATBOOST_AVAILABLE = False
    logging.warning("CatBoost not available. Install with: pip install catboost")

try:
    import lightgbm as lgb
    from lightgbm import LGBMClassifier
    LIGHTGBM_AVAILABLE = True
except ImportError:
    LIGHTGBM_AVAILABLE = False
    logging.warning("LightGBM not available. Install with: pip install lightgbm")

# Load environment variables
load_dotenv()

# Configure logging
logging.basicConfig(
    level=logging.INFO if os.getenv("DEBUG", "false").lower() == "true" else logging.WARNING,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Set up FastAPI app
app = FastAPI(
    title="Student Performance Predictor API",
    description="Enhanced Machine Learning API for Student Performance Prediction with confidence intervals",
    version="1.2.0"
)

# Enable CORS
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Get API key from environment
API_KEY = os.getenv("API_KEY", "changeme")

# Storage paths
MODELS_DIR = os.path.join(os.getcwd(), os.getenv("MODELS_DIR", "models"))
os.makedirs(MODELS_DIR, exist_ok=True)

# Models cache
MODEL_CACHE = {}

# Pydantic models for responses
class TrainResponse(BaseModel):
    model_id: str
    algorithm: str
    metrics: Dict[str, Any]
    feature_names: List[str]
    target_classes: List[Any]
    trained_at: str
    training_time_seconds: float
    model_path: Optional[str] = None

class PredictResponse(BaseModel):
    prediction: Any
    probability: float
    probabilities: List[float]
    confidence_interval: Optional[Dict[str, float]] = None
    model_id: str
    prediction_time: str
    features: Dict[str, Any]

# API key verification
async def verify_api_key(x_api_key: str = Header(...)):
    if x_api_key != API_KEY:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid API key"
        )
    return x_api_key

# Exception handler
@app.exception_handler(Exception)
async def handle_exception(request: Request, exc: Exception):
    error_id = str(uuid.uuid4())
    logger.error(f"Error ID: {error_id} - Unhandled exception: {str(exc)}")
    logger.error(traceback.format_exc())
    return JSONResponse(
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        content={
            "detail": str(exc),
            "error_id": error_id,
            "type": type(exc).__name__
        }
    )

# Simple health check endpoint
@app.get("/health")
async def health_check():
    try:
        if not os.path.exists(MODELS_DIR):
            os.makedirs(MODELS_DIR, exist_ok=True)
        test_file = os.path.join(MODELS_DIR, "healthcheck.txt")
        with open(test_file, "w") as f:
            f.write("Health check")
        os.remove(test_file)

        # Check which algorithms are available
        algorithms = {
            "randomforest": True,
            "extratrees": True,
            "adaboost": True,
            "xgboost": XGBOOST_AVAILABLE,
            "catboost": CATBOOST_AVAILABLE,
            "lightgbm": LIGHTGBM_AVAILABLE
        }

        return {
            "status": "healthy",
            "time": datetime.now().isoformat(),
            "version": "1.2.0",
            "models_dir": MODELS_DIR,
            "models_count": len([f for f in os.listdir(MODELS_DIR) if f.endswith('.joblib')]),
            "available_algorithms": algorithms,
            "environment": {
                "debug": os.getenv("DEBUG", "false"),
                "api_key_configured": API_KEY != "changeme"
            }
        }
    except Exception as e:
        logger.error(f"Health check failed: {str(e)}")
        return {
            "status": "unhealthy",
            "error": str(e),
            "time": datetime.now().isoformat()
        }

def calculate_confidence_interval(prob, n=100, confidence=0.95):
    """Calculate confidence interval for a probability using Wilson score interval."""
    if n <= 0:
        return {"lower": prob, "upper": prob, "confidence": confidence}

    z = stats.norm.ppf((1 + confidence) / 2)
    factor = z / np.sqrt(n)

    # Wilson score interval
    denominator = 1 + z**2/n
    center = (prob + z**2/(2*n)) / denominator
    interval = factor * np.sqrt(prob * (1 - prob) / n + z**2/(4*n**2)) / denominator

    lower = max(0, center - interval)
    upper = min(1, center + interval)

    return {
        "lower": float(lower),
        "upper": float(upper), 
        "confidence": confidence
    }

def identify_leaky_features(df, target_column):
    """
    Identify and filter out leaky features that could lead to data leakage.

    Returns:
        filtered_df: DataFrame with leaky features removed
        leaky_features: List of features identified as potentially leaky
    """
    leaky_features = []

    # Known patterns for leaky features
    leaky_patterns = [
        r'final.*score',
        r'final.*grade',
        r'letter_grade',
        r'pass[_\s]?fail',
        r'outcome',
        r'result',
        r'grade$',
        r'total.*grade',
        r'overall.*score',
        r'final.*result',
        r'completion.*status'
    ]

    # Identify features matching leaky patterns
    for col in df.columns:
        col_lower = col.lower()
        if col != target_column:  # Skip target column itself
            for pattern in leaky_patterns:
                if re.search(pattern, col_lower):
                    leaky_features.append(col)
                    break

    logger.warning(f"Identified {len(leaky_features)} potentially leaky features: {leaky_features}")

    # Check for high correlation with target
    if len(df) > 10:  # Only if we have enough samples
        try:
            target_series = df[target_column]
            # For non-numeric targets, convert to numeric
            if target_series.dtype == 'object' or target_series.dtype.name == 'category':
                target_numeric = pd.factorize(target_series)[0]
            else:
                target_numeric = target_series.values

            remaining_cols = [c for c in df.columns if c != target_column and c not in leaky_features]

            for col in remaining_cols:
                if df[col].dtype.kind in 'ifc':  # Only numeric columns
                    try:
                        # Calculate correlation
                        corr = np.corrcoef(df[col].astype(float), target_numeric)[0, 1]
                        if abs(corr) > 0.9:  # Extremely high correlation may indicate data leakage
                            leaky_features.append(col)
                            logger.warning(f"Detected highly correlated feature: {col} (correlation: {corr:.4f})")
                    except:
                        pass  # Skip on error
        except Exception as e:
            logger.warning(f"Error in correlation check: {str(e)}")

    # Return the filtered DataFrame
    filtered_df = df.drop(columns=leaky_features)

    return filtered_df, leaky_features

@app.post("/train", response_model=TrainResponse, dependencies=[Depends(verify_api_key)])
async def train_model(
    courseid: int = Form(...),
    algorithm: str = Form("randomforest"),
    target_column: str = Form("final_outcome", description="Name of the target column"),
    test_size: float = Form(0.2, description="Test split proportion"),
    id_columns: str = Form("", description="Comma-separated list of ID columns to ignore"),
    dataset_file: UploadFile = File(...)
):
    start_time = time.time()
    logger.info(f"Training request received for course {courseid} using {algorithm}")

    try:
        id_columns_list = [col.strip() for col in id_columns.split(',')] if id_columns else []
        with tempfile.NamedTemporaryFile(delete=False, suffix=os.path.splitext(dataset_file.filename)[1]) as temp_file:
            shutil.copyfileobj(dataset_file.file, temp_file)
            temp_filepath = temp_file.name

        logger.info(f"Uploaded dataset saved to temporary file: {temp_filepath}")

        request_data = {
            "courseid": courseid,
            "algorithm": algorithm,
            "target_column": target_column,
            "id_columns": id_columns_list,
            "test_size": test_size
        }

        file_extension = os.path.splitext(dataset_file.filename)[1].lower()
        try:
            if file_extension == '.csv':
                df = pd.read_csv(temp_filepath)
            elif file_extension == '.json':
                df = pd.read_json(temp_filepath)
            elif file_extension in ['.xlsx', '.xls']:
                df = pd.read_excel(temp_filepath)
            else:
                raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=f"Unsupported file format: {file_extension}")
            logger.info(f"Successfully loaded dataset with {len(df)} rows and {len(df.columns)} columns")
        except Exception as e:
            logger.error(f"Error loading dataset: {str(e)}")
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=f"Error loading dataset: {str(e)}")

        os.unlink(temp_filepath)

        # Data quality checks
        if len(df) < 30:
            logger.warning(f"Very small dataset with only {len(df)} samples. Model may not be reliable.")

        # Check for missing values
        missing_pct = df.isnull().mean() * 100
        high_missing_cols = missing_pct[missing_pct > 50].index.tolist()
        if high_missing_cols:
            logger.warning(f"Columns with >50% missing values: {high_missing_cols}")
            # Drop columns with too many missing values
            df = df.drop(columns=high_missing_cols)
            logger.info(f"Dropped {len(high_missing_cols)} columns with too many missing values")

        # Handle target column
        if request_data["target_column"] not in df.columns:
            possible_targets = ['final_outcome', 'pass', 'outcome', 'grade', 'result', 'status', 'final_grade', 'passed']
            for col in possible_targets:
                if col in df.columns:
                    logger.info(f"Using '{col}' as target column instead of '{request_data['target_column']}'")
                    request_data["target_column"] = col
                    break
            else:
                request_data["target_column"] = df.columns[-1]
                logger.warning(f"Target column not found, using last column '{request_data['target_column']}' as target")

        # Preprocess target - convert to binary if needed
        y = df[request_data["target_column"]]

        # Handle non-numeric targets
        if y.dtype == 'object' or y.dtype.name == 'category':
            logger.info(f"Converting categorical target to numeric. Original values: {y.unique()}")

            # Map common passing terms to 1, failing terms to 0
            if len(y.unique()) > 2:
                # Try to map based on common terms
                pass_terms = ['pass', 'passed', 'complete', 'completed', 'success', 'successful', 'satisfactory', 'yes', 'y', 'true', 't']
                fail_terms = ['fail', 'failed', 'incomplete', 'unsatisfactory', 'no', 'n', 'false', 'f']

                def map_target(val):
                    if not isinstance(val, str):
                        return val
                    val_lower = str(val).lower()
                    if any(term in val_lower for term in pass_terms):
                        return 1
                    if any(term in val_lower for term in fail_terms):
                        return 0
                    return val

                y = y.apply(map_target)

                # If still not binary, use label encoder
                if len(y.unique()) > 2:
                    from sklearn.preprocessing import LabelEncoder
                    le = LabelEncoder()
                    y = le.fit_transform(y)
                    logger.info(f"Applied LabelEncoder to target. New values: {np.unique(y)}")

            else:
                # Map the two unique values to 0 and 1
                unique_vals = y.unique()
                mapping = {unique_vals[0]: 0, unique_vals[1]: 1}
                y = y.map(mapping)
                logger.info(f"Mapped target values {unique_vals} to {list(mapping.values())}")

        # For regression-like targets, convert to binary based on median
        elif len(y.unique()) > 10:
            median = y.median()
            logger.info(f"Converting numeric target to binary using median {median} as threshold")
            y = (y >= median).astype(int)

        # Print target distribution
        logger.info(f"Target distribution: {pd.Series(y).value_counts().to_dict()}")

        # Remove ID columns and target column
        X = df.drop(columns=[request_data["target_column"]] + request_data["id_columns"])

        # NEW: Identify and remove leaky features
        X, leaky_features = identify_leaky_features(X, request_data["target_column"])
        logger.warning(f"Removed {len(leaky_features)} leaky features that could cause data leakage")

        # Remove constant features
        constant_features = [col for col in X.columns if X[col].nunique() <= 1]
        if constant_features:
            logger.info(f"Removing {len(constant_features)} constant features")
            X = X.drop(columns=constant_features)

        # Handle highly correlated features
        if len(X.select_dtypes(include=['number']).columns) > 10:
            numeric_X = X.select_dtypes(include=['number'])
            try:
                corr_matrix = numeric_X.corr().abs()
                upper = corr_matrix.where(np.triu(np.ones(corr_matrix.shape), k=1).astype(bool))
                # Lower the threshold to 0.85 from 0.95 to be more conservative
                high_corr_cols = [column for column in upper.columns if any(upper[column] > 0.85)]

                if high_corr_cols:
                    logger.info(f"Removing {len(high_corr_cols)} highly correlated features")
                    X = X.drop(columns=high_corr_cols)
            except Exception as e:
                logger.warning(f"Error computing correlations: {str(e)}")

        # Check if we have enough features left
        if X.shape[1] < 3:
            logger.warning(f"Very few features remain ({X.shape[1]}). Model may not be effective.")
            # Add a warning note to return to the user
            warning_note = f"Warning: Only {X.shape[1]} features remain after filtering. Model may have limited predictive power."
        else:
            warning_note = None

        feature_names = X.columns.tolist()
        logger.info(f"Final feature count: {len(feature_names)}")
        logger.info(f"Features: {feature_names[:10]}{'...' if len(feature_names) > 10 else ''}")

        # Prepare preprocessing pipeline
        numeric_cols = X.select_dtypes(include=['int64', 'float64']).columns.tolist()
        categorical_cols = X.select_dtypes(include=['object', 'category', 'bool']).columns.tolist()

        logger.info(f"Numeric features: {len(numeric_cols)}, Categorical features: {len(categorical_cols)}")

        # Create robust preprocessing pipeline
        try:
            encoder = OneHotEncoder(drop='first', sparse_output=False, handle_unknown='ignore')
        except TypeError:
            # For older scikit-learn versions
            encoder = OneHotEncoder(drop='first', sparse=False, handle_unknown='ignore')

        # Use RobustScaler for better handling of outliers
        preprocessor = ColumnTransformer(
            transformers=[
                ('num', Pipeline([
                    ('imputer', SimpleImputer(strategy='median')), 
                    ('scaler', RobustScaler())
                ]), numeric_cols),
                ('cat', Pipeline([
                    ('imputer', SimpleImputer(strategy='most_frequent')), 
                    ('encoder', encoder)
                ]), categorical_cols)
            ],
            remainder='drop'
        )

        # Handle class imbalance
        class_counts = pd.Series(y).value_counts().to_dict()
        if len(class_counts) > 1:
            imbalance_ratio = max(class_counts.values()) / min(class_counts.values())
            class_weight = 'balanced' if imbalance_ratio > 3 else None
            if imbalance_ratio > 3:
                logger.warning(f"Significant class imbalance detected: ratio {imbalance_ratio:.2f}. Applying class weight balancing.")
        else:
            logger.warning("Only one class found in target. Model will not be useful for prediction.")
            class_weight = None

        # Train-test split with stratification when possible
        try:
            # NEW: Use stratified k-fold cross-validation for better evaluation
            X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=request_data["test_size"], 
                                                              random_state=42, stratify=y)
        except ValueError:
            logger.warning("Stratified split failed, falling back to random split")
            X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=request_data["test_size"], 
                                                              random_state=42)

        # Define models with your specified algorithms
        models = {}

        # Random Forest Classifier (Always available)
        models['randomforest'] = RandomForestClassifier(
            n_estimators=100, 
            max_depth=None,  # Changed from 10 to None to prevent underfitting
            min_samples_split=5, 
            min_samples_leaf=2, 
            class_weight=class_weight, 
            random_state=42,
            n_jobs=-1  # Use all cores
        )

        # Extra Trees Classifier (Always available)
        models['extratrees'] = ExtraTreesClassifier(
            n_estimators=100, 
            max_depth=None,  # Changed from 10 to None
            min_samples_split=5, 
            min_samples_leaf=2, 
            class_weight=class_weight, 
            random_state=42,
            n_jobs=-1
        )

        # AdaBoost Classifier (Always available)
        models['adaboost'] = AdaBoostClassifier(
            n_estimators=100, 
            learning_rate=0.1, 
            random_state=42
        )

        # XGBoost Classifier (If available)
        if XGBOOST_AVAILABLE:
            models['xgboost'] = XGBClassifier(
                n_estimators=100,
                max_depth=6,
                learning_rate=0.1,
                subsample=0.8,
                colsample_bytree=0.8,
                random_state=42,
                eval_metric='logloss',
                n_jobs=-1,
                # For newer versions
                use_label_encoder=False if hasattr(XGBClassifier, 'use_label_encoder') else None,
                enable_categorical=True if hasattr(XGBClassifier, 'enable_categorical') else False
            )

        # CatBoost Classifier (If available)
        if CATBOOST_AVAILABLE:
            models['catboost'] = CatBoostClassifier(
                iterations=100,
                depth=6,
                learning_rate=0.1,
                loss_function='Logloss',
                verbose=0,
                random_seed=42
            )

        # LightGBM Classifier (If available)
        if LIGHTGBM_AVAILABLE:
            models['lightgbm'] = LGBMClassifier(
                n_estimators=100,
                max_depth=6,
                learning_rate=0.1,
                subsample=0.8,
                colsample_bytree=0.8,
                random_state=42,
                n_jobs=-1
            )

        # Select model based on algorithm parameter or fall back to RandomForest
        if request_data["algorithm"] not in models:
            logger.warning(f"Requested algorithm '{request_data['algorithm']}' not available, falling back to RandomForest")
            request_data["algorithm"] = 'randomforest'

        model = models[request_data["algorithm"]]

        # Create pipeline with preprocessor and classifier
        pipeline = Pipeline([
            ('preprocessor', preprocessor), 
            ('classifier', model)
        ])

        # Perform k-fold cross-validation (k=5)
        logger.info("Performing 5-fold cross-validation with stratification")
        cv = StratifiedKFold(n_splits=5, shuffle=True, random_state=42)
        cv_scores = cross_val_score(pipeline, X, y, cv=cv, scoring='accuracy')
        cv_accuracy, cv_std = np.mean(cv_scores), np.std(cv_scores)
        logger.info(f"Cross-validation accuracy: {cv_accuracy:.4f} ± {cv_std:.4f}")

        # Train the model
        logger.info(f"Training {request_data['algorithm']} model")
        pipeline.fit(X_train, y_train)
        logger.info("Model training completed")

        # Special handling for CatBoost
        if request_data['algorithm'] == 'catboost':
            # CatBoost doesn't work well with scikit-learn's pipeline for feature names
            # Save categorical features for CatBoost
            cat_features = []
            if categorical_cols:
                try:
                    # Get categorical feature indices after preprocessing
                    cat_features = list(range(len(categorical_cols)))
                    pipeline.named_steps['classifier'].cat_features = cat_features
                except Exception as e:
                    logger.warning(f"Error setting cat_features for CatBoost: {e}")

        # Apply probability calibration for better confidence estimates
        # Store the original pipeline and use a separate calibrated model
        original_pipeline = pipeline
        calibrated_pipeline = None

        if hasattr(original_pipeline, 'predict_proba'):
            logger.info("Applying probability calibration for reliable confidence estimates")
            try:
                # Create a new calibrated classifier
                calibrated_model = CalibratedClassifierCV(
                    base_estimator=None,  # Use prefit=False to avoid the named_steps issue
                    method='sigmoid',  # Changed from isotonic to sigmoid for smaller datasets
                    cv=3,  # Use 3-fold CV
                    n_jobs=-1
                )

                # Apply preprocessor to get transformed data
                X_train_transformed = original_pipeline.named_steps['preprocessor'].transform(X_train)
                X_test_transformed = original_pipeline.named_steps['preprocessor'].transform(X_test)

                # Use the transformed data to fit the calibrator
                calibrated_model.fit(X_train_transformed, y_train)

                # Create a calibrated pipeline
                calibrated_pipeline = Pipeline([
                    ('preprocessor', original_pipeline.named_steps['preprocessor']),
                    ('calibrated_classifier', calibrated_model)
                ])

                logger.info("Probability calibration completed successfully")
            except Exception as e:
                logger.warning(f"Probability calibration failed: {str(e)}")
                logger.warning("Using uncalibrated model instead")
                calibrated_pipeline = None

        # Generate predictions and evaluate model
        if calibrated_pipeline is not None:
            # Use calibrated predictions
            y_pred = calibrated_pipeline.predict(X_test)
            y_pred_proba = calibrated_pipeline.predict_proba(X_test)

            # Store both pipelines
            pipeline.calibrated_pipeline = calibrated_pipeline
        else:
            # Use the regular pipeline
            y_pred = pipeline.predict(X_test)
            y_pred_proba = pipeline.predict_proba(X_test)
            pipeline.calibrated_pipeline = None

        # Calculate comprehensive metrics
        metrics = {
            "accuracy": float(accuracy_score(y_test, y_pred)),
            "cv_accuracy": float(cv_accuracy),
            "cv_std": float(cv_std),
            "precision": float(precision_score(y_test, y_pred, average='weighted', zero_division=0)),
            "recall": float(recall_score(y_test, y_pred, average='weighted', zero_division=0)),
            "f1": float(f1_score(y_test, y_pred, average='weighted', zero_division=0)),
            "k_folds": 5,  # Explicitly record k value used for cross-validation
            "removed_leaky_features": leaky_features  # Add the leaky features to metrics
        }

        # Add warning if necessary
        if warning_note:
            metrics["warning"] = warning_note

        # Compute ROC AUC for binary classification
        if len(np.unique(y)) == 2:
            try:
                metrics["roc_auc"] = float(roc_auc_score(y_test, y_pred_proba[:, 1]))
            except (ValueError, IndexError) as e:
                logger.warning(f"ROC AUC calculation failed: {str(e)}")
                metrics["roc_auc"] = None

        # Check for overfitting
        train_acc = accuracy_score(y_train, pipeline.predict(X_train))
        test_acc = metrics["accuracy"]
        overfitting_ratio = train_acc / max(test_acc, 0.001)
        metrics["overfitting_warning"] = overfitting_ratio > 1.2
        metrics["overfitting_ratio"] = float(overfitting_ratio)
        metrics["train_accuracy"] = float(train_acc)
        metrics["test_accuracy"] = float(test_acc)

        if metrics["overfitting_warning"]:
            logger.warning(f"Model may be overfitting: train accuracy={train_acc:.4f}, test accuracy={test_acc:.4f}")

        # Extract feature importance if available
        feature_importance = None

        # Method to get feature importance from different model types
        if hasattr(pipeline.named_steps['classifier'], 'feature_importances_'):
            # Random Forest, XGBoost, LightGBM, etc.
            feature_importance = pipeline.named_steps['classifier'].feature_importances_
        elif hasattr(pipeline.named_steps['classifier'], 'coef_'):
            # Linear models
            feature_importance = np.abs(pipeline.named_steps['classifier'].coef_[0])
        elif hasattr(pipeline.named_steps['classifier'], 'feature_importance_'):
            # CatBoost
            feature_importance = pipeline.named_steps['classifier'].feature_importance_

        if feature_importance is not None:
            try:
                # Get transformed feature names if possible
                feature_names_out = []

                # Try to get column names after preprocessing
                try:
                    # For newer scikit-learn versions
                    if hasattr(pipeline.named_steps['preprocessor'], 'get_feature_names_out'):
                        feature_names_out = pipeline.named_steps['preprocessor'].get_feature_names_out()
                    # For older scikit-learn versions
                    elif hasattr(pipeline.named_steps['preprocessor'], 'get_feature_names'):
                        feature_names_out = pipeline.named_steps['preprocessor'].get_feature_names()
                    else:
                        # Create generic feature names
                        feature_names_out = [f"feature_{i}" for i in range(len(feature_importance))]
                except Exception as e:
                    logger.warning(f"Error getting transformed feature names: {str(e)}")
                    feature_names_out = [f"feature_{i}" for i in range(len(feature_importance))]

                # Ensure lengths match
                if len(feature_names_out) == len(feature_importance):
                    importance_dict = dict(zip(feature_names_out, feature_importance))
                    sorted_features = sorted(importance_dict.items(), key=lambda x: x[1], reverse=True)
                    metrics["top_features"] = {str(k): float(v) for k, v in sorted_features[:10]}
                else:
                    logger.warning(f"Feature name length mismatch: {len(feature_names_out)} names, {len(feature_importance)} importances")
                    # Use generic feature names
                    top_indices = np.argsort(feature_importance)[-10:][::-1]
                    metrics["top_features"] = {f"feature_{i}": float(feature_importance[i]) for i in top_indices}
            except Exception as e:
                logger.warning(f"Error extracting feature importance: {str(e)}")
                metrics["top_features"] = {}
        else:
            logger.info("Model does not support feature importance")
            metrics["top_features"] = {}

        # Add confusion matrix
        try:
            cm = confusion_matrix(y_test, y_pred)
            metrics["confusion_matrix"] = cm.tolist()
        except Exception as e:
            logger.warning(f"Error computing confusion matrix: {str(e)}")

        # Calculate confidence intervals for predictions
        # Use the effective sample size for confidence intervals
        effective_n = min(len(X_test), 100)  # Cap at 100 to avoid overconfidence
        metrics["confidence_interval"] = calculate_confidence_interval(
            metrics["accuracy"], 
            n=effective_n, 
            confidence=0.95
        )

        # Generate unique model ID and save the model
        model_id = str(uuid.uuid4())
        course_models_dir = os.path.join(MODELS_DIR, f"course_{request_data['courseid']}")
        os.makedirs(course_models_dir, exist_ok=True)
        model_path = os.path.join(course_models_dir, f"{model_id}.joblib")

        # Store model metadata
        model_data = {
            'pipeline': pipeline,
            'feature_names': feature_names,
            'algorithm': request_data["algorithm"],
            'trained_at': datetime.now().isoformat(),
            'target_classes': list(np.unique(y)),
            'metrics': metrics,
            'cv_scores': cv_scores.tolist(),
            'effective_sample_size': effective_n,
            'leaky_features': leaky_features  # Store the leaky features for reference
        }

        # Save model to disk and cache
        joblib.dump(model_data, model_path)
        MODEL_CACHE[model_id] = model_data
        logger.info(f"Model saved to {model_path}")

        # Calculate training time
        training_time = time.time() - start_time
        logger.info(f"Training completed in {training_time:.2f} seconds")

        # Return comprehensive model information
        return {
            "model_id": model_id,
            "algorithm": request_data["algorithm"],
            "metrics": metrics,
            "feature_names": [str(f) for f in feature_names],
            "target_classes": [int(c) if isinstance(c, (np.integer, np.int64, np.int32)) else c for c in np.unique(y)],
            "trained_at": datetime.now().isoformat(),
            "training_time_seconds": training_time,
            "model_path": model_path
        }
    except HTTPException:
        raise
    except Exception as e:
        logger.exception(f"Error training model: {str(e)}")
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=f"Error training model: {str(e)}")

@app.post("/predict", dependencies=[Depends(verify_api_key)])
async def predict(request: dict):
    try:
        model_id = request.get("model_id")
        features = request.get("features")

        if not model_id:
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="model_id is required")
        if not features:
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="features are required")

        is_batch = isinstance(features, list) and len(features) > 0 and isinstance(features[0], (list, dict))
        logger.info(f"Prediction request for model {model_id} ({'batch' if is_batch else 'single'})")

        # Load model from cache or disk
        model_data = MODEL_CACHE.get(model_id)
        if not model_data:
            found = False
            for root, _, files in os.walk(MODELS_DIR):
                if f"{model_id}.joblib" in files:
                    model_path = os.path.join(root, f"{model_id}.joblib")
                    logger.info(f"Loading model from {model_path}")
                    model_data = joblib.load(model_path)
                    MODEL_CACHE[model_id] = model_data
                    found = True
                    break
            if not found:
                logger.error(f"Model with ID {model_id} not found")
                raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail=f"Model with ID {model_id} not found")

        pipeline = model_data['pipeline']
        feature_names = model_data['feature_names']
        effective_n = model_data.get('effective_sample_size', 50)  # Default to 50 if not stored
        algorithm = model_data.get('algorithm', 'unknown')
        leaky_features = model_data.get('leaky_features', [])

        logger.info(f"Model algorithm: {algorithm}")
        logger.info(f"Model feature names: {len(feature_names)} features")

        # Prepare input data
        try:
            input_df = pd.DataFrame([features] if not is_batch else features)

            # Remove any leaky features from input data
            for lf in leaky_features:
                if lf in input_df.columns:
                    logger.info(f"Removing leaky feature {lf} from prediction input")
                    input_df = input_df.drop(columns=[lf])

            # Handle missing columns
            for feat in feature_names:
                if feat not in input_df.columns:
                    logger.info(f"Adding missing feature {feat} with default value 0")
                    input_df[feat] = 0

            # Select only columns that match the model's feature names
            valid_features = [f for f in feature_names if f in input_df.columns]
            input_df = input_df[valid_features]

            # Log shape info for debugging
            logger.info(f"Input data shape: {input_df.shape}")

            # Additional validation to ensure data matches model expectations
            if input_df.shape[1] == 0:
                raise ValueError("No valid features found in input data")

        except Exception as e:
            logger.error(f"Error preparing input data: {str(e)}")
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=f"Invalid feature format: {str(e)}")

        # Make prediction
        logger.info("Making prediction")
        try:
            # Check if we should use calibrated pipeline
            if hasattr(pipeline, 'calibrated_pipeline') and pipeline.calibrated_pipeline is not None:
                logger.info("Using calibrated pipeline for prediction")
                calibrated_pipeline = pipeline.calibrated_pipeline
                predictions = calibrated_pipeline.predict(input_df).tolist()
                probabilities = calibrated_pipeline.predict_proba(input_df).tolist()
            else:
                # Use regular pipeline
                logger.info("Using regular pipeline for prediction")
                predictions = pipeline.predict(input_df).tolist()
                probabilities = pipeline.predict_proba(input_df).tolist()

            logger.info(f"Prediction successful")
        except Exception as e:
            logger.error(f"Error during prediction: {str(e)}")
            raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=f"Error during prediction: {str(e)}")

        # Process results based on batch or single prediction
        if is_batch:
            # For batch predictions, get probability of positive class
            target_classes = model_data['target_classes']

            # Find the positive class index (usually 1 in binary classification)
            positive_class_idx = 1 if len(target_classes) == 2 and 1 in target_classes else 0

            # Get probabilities for the positive class
            positive_probs = [probs[positive_class_idx] for probs in probabilities] if len(target_classes) == 2 else [max(probs) for probs in probabilities]

            # Calculate confidence intervals for each prediction
            confidence_intervals = [
                calculate_confidence_interval(prob, n=effective_n) for prob in positive_probs
            ]

            return {
                "predictions": predictions,
                "probabilities": positive_probs,
                "confidence_intervals": confidence_intervals,
                "model_id": model_id,
                "algorithm": algorithm,
                "prediction_time": datetime.now().isoformat(),
                "features": features  # Return the batch of features
            }
        else:
            # For single prediction
            prediction = predictions[0]

            # Get probability of prediction class or positive class for binary classification
            target_classes = model_data['target_classes']

            if len(target_classes) == 2:
                # Binary classification - get probability for positive class (usually 1)
                positive_class_idx = 1 if 1 in target_classes else 0
                probability = float(probabilities[0][positive_class_idx])
            else:
                # Multi-class - get probability for the predicted class
                try:
                    pred_idx = target_classes.index(prediction) if prediction in target_classes else 0
                    probability = float(probabilities[0][pred_idx])
                except (ValueError, IndexError):
                    # Fallback if prediction is not in target classes
                    pred_idx = 0
                    probability = float(probabilities[0][pred_idx])

            # Calculate confidence interval for the prediction
            confidence_interval = calculate_confidence_interval(probability, n=effective_n)

            # Enhanced prediction response with confidence interval
            return {
                "prediction": prediction,
                "probability": probability,
                "probabilities": probabilities[0],
                "confidence_interval": confidence_interval,
                "model_id": model_id,
                "algorithm": algorithm,
                "prediction_time": datetime.now().isoformat(),
                "features": features  # Return the input features
            }

    except HTTPException:
        raise
    except Exception as e:
        logger.exception(f"Unhandled error in prediction: {str(e)}")
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=f"Error making prediction: {str(e)}")

@app.get("/models/{course_id}", dependencies=[Depends(verify_api_key)])
async def list_models(course_id: int):
    """List all trained models for a specific course"""
    course_models_dir = os.path.join(MODELS_DIR, f"course_{course_id}")

    if not os.path.exists(course_models_dir):
        return {"models": []}

    models = []
    for filename in os.listdir(course_models_dir):
        if filename.endswith('.joblib'):
            model_id = filename.split('.')[0]
            model_path = os.path.join(course_models_dir, filename)

            try:
                # Load model metadata without loading the full model
                model_data = joblib.load(model_path)

                models.append({
                    "model_id": model_id,
                    "algorithm": model_data.get('algorithm', 'unknown'),
                    "accuracy": model_data.get('metrics', {}).get('accuracy', 0),
                    "cv_accuracy": model_data.get('metrics', {}).get('cv_accuracy', 0),
                    "trained_at": model_data.get('trained_at', ''),
                    "file_size_mb": round(os.path.getsize(model_path) / (1024 * 1024), 2),
                    "removed_leaky_features": model_data.get('leaky_features', [])
                })
            except Exception as e:
                logger.error(f"Error loading model {model_id}: {str(e)}")
                models.append({
                    "model_id": model_id,
                    "error": str(e),
                    "file_path": model_path
                })

    return {"models": models}

@app.get("/model/{model_id}", dependencies=[Depends(verify_api_key)])
async def get_model_details(model_id: str):
    """Get detailed information about a specific model"""

    # Try to find the model file
    model_path = None
    for root, _, files in os.walk(MODELS_DIR):
        if f"{model_id}.joblib" in files:
            model_path = os.path.join(root, f"{model_id}.joblib")
            break

    if not model_path:
        raise HTTPException(status_code=404, detail=f"Model with ID {model_id} not found")

    try:
        # Load model metadata
        model_data = joblib.load(model_path)

        # Extract key information, but not the actual model pipeline
        return {
            "model_id": model_id,
            "algorithm": model_data.get('algorithm', 'unknown'),
            "metrics": model_data.get('metrics', {}),
            "feature_names": model_data.get('feature_names', []),
            "target_classes": model_data.get('target_classes', []),
            "trained_at": model_data.get('trained_at', ''),
            "file_path": model_path,
            "file_size_mb": round(os.path.getsize(model_path) / (1024 * 1024), 2),
            "removed_leaky_features": model_data.get('leaky_features', [])
        }
    except Exception as e:
        logger.error(f"Error loading model {model_id}: {str(e)}")
        raise HTTPException(status_code=500, detail=f"Error loading model: {str(e)}")

if __name__ == "__main__":
    import uvicorn
    port = int(os.getenv("PORT", 8000))
    debug = os.getenv("DEBUG", "false").lower() == "true"
    print(f"Starting Enhanced Student Performance Prediction API on port {port}, debug={debug}")
    uvicorn.run(app, host="0.0.0.0", port=port)

{
  "version": 2,
  "build": {
    "builder": "NIXPACKS",
    "buildCommand": "pip install -r requirements.txt"
  },
  "deploy": {
    "startCommand": "uvicorn ml_backend:app --host=0.0.0.0 --port=8000",
    "healthcheckPath": "/health",
    "healthcheckTimeout": 100,
    "restartPolicyType": "ON_FAILURE",
    "restartPolicyMaxRetries": 10
  }
}

## Core web framework
fastapi>=0.104.1
uvicorn>=0.24.0
pydantic>=2.4.2
python-multipart>=0.0.6  # Required for file uploads

# ASGI server alternatives
hypercorn>=0.14.4
gunicorn>=21.2.0

# Data processing
numpy>=1.26.1
pandas>=2.1.2
scipy>=1.11.3

# Machine learning
scikit-learn>=1.3.2
joblib>=1.3.2
xgboost>=2.0.0
catboost>=1.2
lightgbm>=4.1.0

# Utilities
python-dotenv>=1.0.0
httpx>=0.25.1

# Extra data formats
openpyxl>=3.1.2  # For Excel support
xlrd>=2.0.1      # For older Excel formats

# Production extras for Railway
uvloop>=0.18.0
httptools>=0.6.1

I want you to fix all of the code above and when i choose the dataset and the algorithm and try to train the model the task to adhoc task is not given so there is no tasks. I want you to fix it. Also for your information, i use Railway to deploy the project and use bitnami for the moodle. So, fit the code to bitnami and railway so that it will run well on both.
