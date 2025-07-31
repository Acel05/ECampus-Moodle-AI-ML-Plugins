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
│   ├── trainglobalmodel.php
│   ├── upload_dataset.php
│   ├── viewdataset.php
│   ├── viewmodel.php
│   ├── viewtasks.php
├── amd
│   ├── src
│       ├── admin_dashboard.js
│       ├── admin_interface.js
│       ├── chart_renderer.js
│       ├── prediction_viewer.js
│       ├── teacher_dashboard.js
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

// Get all datasets for this course
$datasets = $DB->get_records('block_spp_datasets', ['courseid' => $courseid], 'timemodified DESC');

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
if (!empty($datasets)) {
    echo $OUTPUT->heading(get_string('existingdatasets', 'block_studentperformancepredictor'), 3);
    // Warn that deleting a dataset will also delete all models trained from it (backend-driven)
    echo $OUTPUT->notification(get_string('datasetdeletecascade', 'block_studentperformancepredictor'), 'info');

    $table = new html_table();
    $table->head = [
        get_string('datasetname', 'block_studentperformancepredictor'),
        get_string('datasetformat', 'block_studentperformancepredictor'),
        get_string('uploaded', 'block_studentperformancepredictor'),
        get_string('actions', 'block_studentperformancepredictor')
    ];

    foreach ($datasets as $dataset) {
        $row = [];
        $row[] = format_string($dataset->name);
        $row[] = format_string($dataset->fileformat);
        $row[] = userdate($dataset->timecreated);

        $actions = html_writer::link(
            new moodle_url('/blocks/studentperformancepredictor/admin/viewdataset.php', 
                ['id' => $dataset->id, 'courseid' => $courseid]), 
            get_string('view', 'block_studentperformancepredictor'),
            ['class' => 'btn btn-sm btn-secondary']
        );

        // Delete button triggers backend-driven cascade delete (dataset + models + files)
        $actions .= ' ' . html_writer::link('#', 
            get_string('delete', 'block_studentperformancepredictor'),
            [
                'class' => 'btn btn-sm btn-danger spp-delete-dataset', 
                'data-dataset-id' => $dataset->id,
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

// Get available datasets for model training
$datasets = $DB->get_records('block_spp_datasets', ['courseid' => $courseid], 'timemodified DESC');

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
        get_string('status', 'block_studentperformancepredictor'),  
        get_string('created', 'block_studentperformancepredictor'),
        get_string('actions', 'block_studentperformancepredictor')
    ];

    $row = [];
    $row[] = format_string($activemodel->modelname);
    $row[] = isset($algorithmoptions[$activemodel->algorithmtype]) ? 
            $algorithmoptions[$activemodel->algorithmtype] : $activemodel->algorithmtype;
    $row[] = round($activemodel->accuracy * 100, 2) . '%';
    $row[] = html_writer::tag('span', get_string('active', 'block_studentperformancepredictor'), 
                            ['class' => 'badge badge-success']);
    $row[] = userdate($activemodel->timemodified);
    $row[] = html_writer::link('#', get_string('deactivate', 'block_studentperformancepredictor'), 
                            ['class' => 'btn btn-sm btn-secondary spp-toggle-model-status', 
                                 'data-model-id' => $activemodel->id,
                                 'data-is-active' => 1]);

    $table->data[] = $row;
    echo html_writer::table($table);
}

// Train new model form
echo $OUTPUT->heading(get_string('trainnewmodel', 'block_studentperformancepredictor'), 3);

if (empty($datasets)) {
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
    foreach ($datasets as $dataset) {
        $form .= html_writer::tag('option', format_string($dataset->name), ['value' => $dataset->id]);
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
            $actions = html_writer::start_tag('form', [
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
            $actions = html_writer::start_tag('form', [
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

// Add required JavaScript
$PAGE->requires->js_call_amd('block_studentperformancepredictor/prediction_viewer', 'init');

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

    // Get information about students with no predictions (backend-driven)
    // This count reflects the state after the last backend prediction refresh.
    $sql = "SELECT COUNT(DISTINCT u.id) 
            FROM {user} u
            JOIN {user_enrolments} ue ON ue.userid = u.id
            JOIN {enrol} e ON e.id = ue.enrolid
            LEFT JOIN {block_spp_predictions} p ON p.userid = u.id AND p.courseid = :courseid
            WHERE e.courseid = :courseid2
            AND p.id IS NULL";

    $params = [
        'courseid' => $courseid,
        'courseid2' => $courseid
    ];

    $studentswithoutpredictions = $DB->count_records_sql($sql, $params);

    if ($studentswithoutpredictions > 0) {
        echo html_writer::start_div('spp-missing-predictions');
        echo $OUTPUT->notification(
            get_string('studentswithoutpredictions_backend', 'block_studentperformancepredictor', $studentswithoutpredictions), 
            'info'
        );
        echo html_writer::end_div();
    }
}

// At the end, add navigation buttons
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

// Security checks first
require_login();
require_sesskey();

// Determine redirect URL based on course
$redirect_url = ($courseid == 0) 
    ? new moodle_url('/blocks/studentperformancepredictor/admin/trainglobalmodel.php')
    : new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', ['courseid' => $courseid]);

// Set up page and context
if ($courseid == 0) {
    // Global model - need site admin permission
    admin_externalpage_setup('blocksettingstudentperformancepredictor');
    require_capability('moodle/site:config', context_system::instance());

    // Set up URL
    $url = new moodle_url('/blocks/studentperformancepredictor/admin/train_model.php', ['courseid' => 0]);
    $PAGE->set_url($url);
    $PAGE->set_context(context_system::instance());

    // Check if global models are enabled
    if (!get_config('block_studentperformancepredictor', 'enableglobalmodel')) {
        \core\notification::add(
            get_string('globalmodeldisabled', 'block_studentperformancepredictor'),
            \core\notification::ERROR
        );
        redirect($redirect_url);
    }

    // Set up page title for global model
    $PAGE->set_title(get_string('trainglobalmodel', 'block_studentperformancepredictor'));
    $PAGE->set_heading(get_string('trainglobalmodel', 'block_studentperformancepredictor'));
    $PAGE->set_pagelayout('admin');

    // Verify dataset exists (for global model it can be from any course)
    if (!$DB->record_exists('block_spp_datasets', ['id' => $datasetid])) {
        \core\notification::add(
            get_string('dataset_not_found', 'block_studentperformancepredictor'),
            \core\notification::ERROR
        );
        redirect($redirect_url);
    }
} else {
    // Course-specific model
    $course = get_course($courseid);
    $coursecontext = context_course::instance($courseid);

    // Check capability and require login with the course
    require_login($course);
    require_capability('block/studentperformancepredictor:managemodels', $coursecontext);

    // Set up page with the course context
    $url = new moodle_url('/blocks/studentperformancepredictor/admin/train_model.php', ['courseid' => $courseid]);
    $PAGE->set_url($url);
    $PAGE->set_context($coursecontext);

    // Set up page title
    $PAGE->set_title($course->shortname . ': ' . get_string('training_model', 'block_studentperformancepredictor'));
    $PAGE->set_heading($course->fullname);

    // Verify dataset exists and belongs to the course
    if (!$DB->record_exists('block_spp_datasets', ['id' => $datasetid, 'courseid' => $courseid])) {
        \core\notification::add(
            get_string('dataset_not_found', 'block_studentperformancepredictor'),
            \core\notification::ERROR
        );
        redirect($redirect_url);
    }
}

// Check for pending training
if (training_manager::has_pending_training($courseid)) {
    \core\notification::add(
        get_string('training_already_scheduled', 'block_studentperformancepredictor'),
        \core\notification::WARNING
    );
    redirect($redirect_url);
}

// Schedule training (backend-driven orchestration)
$success = false;
$error_message = '';

try {
    // This will queue a training task that calls the Python backend /train endpoint
    $success = training_manager::schedule_training($courseid, $datasetid, $algorithm);
    if (!$success) {
        $error_message = get_string('trainingschedulefailed', 'block_studentperformancepredictor');
    }
} catch (\Exception $e) {
    $success = false;
    $error_message = $e->getMessage();
}

// Add appropriate notification - we will store this in session before redirecting
if ($success) {
    \core\notification::success(get_string('model_training_queued_backend', 'block_studentperformancepredictor'));
} else {
    \core\notification::error($error_message);
}

// Redirect back to appropriate page
redirect($redirect_url);

<?php
// blocks/studentperformancepredictor/admin/trainglobalmodel.php

require_once('../../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');
require_once($CFG->libdir . '/adminlib.php');

// Check global admin context
admin_externalpage_setup('blocksettingstudentperformancepredictor');
require_capability('moodle/site:config', context_system::instance());

// Set up page
$PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/admin/trainglobalmodel.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('trainglobalmodel', 'block_studentperformancepredictor'));
$PAGE->set_heading(get_string('trainglobalmodel', 'block_studentperformancepredictor'));
$PAGE->set_pagelayout('admin');

// Initialize admin interface JavaScript
$PAGE->requires->js_call_amd('block_studentperformancepredictor/admin_interface', 'init', [0]);

// Output starts here
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('trainglobalmodel', 'block_studentperformancepredictor'));

// Check if global models are enabled
if (!get_config('block_studentperformancepredictor', 'enableglobalmodel')) {
    echo $OUTPUT->notification(get_string('globalmodeldisabled', 'block_studentperformancepredictor'), 'error');
    echo html_writer::link(
        new moodle_url('/admin/settings.php', ['section' => 'blocksettingstudentperformancepredictor']),
        get_string('enableglobalmodelsettings', 'block_studentperformancepredictor'),
        ['class' => 'btn btn-primary']
    );
    echo $OUTPUT->footer();
    exit;
}

// Get all available datasets from all courses
$datasets = [];
$sql = "SELECT d.*, c.fullname as coursename 
        FROM {block_spp_datasets} d
        JOIN {course} c ON d.courseid = c.id
        ORDER BY d.timemodified DESC";
$alldatasets = $DB->get_records_sql($sql);

// Get algorithm options
$algorithmoptions = [
    'randomforest' => get_string('algorithm_randomforest', 'block_studentperformancepredictor'),
    'svm' => get_string('algorithm_svm', 'block_studentperformancepredictor'),
    'logisticregression' => get_string('algorithm_logisticregression', 'block_studentperformancepredictor'),
    'decisiontree' => get_string('algorithm_decisiontree', 'block_studentperformancepredictor'),
    'knn' => get_string('algorithm_knn', 'block_studentperformancepredictor')
];

// Display explanation
echo html_writer::div(
    get_string('trainglobalmodel_desc', 'block_studentperformancepredictor'),
    'alert alert-info'
);

// Display form for dataset selection and training
echo html_writer::start_tag('form', [
    'id' => 'spp-train-global-model-form',
    'class' => 'mb-4',
    'method' => 'post',
    'action' => new moodle_url('/blocks/studentperformancepredictor/admin/train_model.php')
]);

// Dataset selection
echo html_writer::start_div('form-group');
echo html_writer::label(get_string('selectdataset', 'block_studentperformancepredictor'), 'datasetid', true);
echo html_writer::start_tag('select', ['id' => 'datasetid', 'name' => 'datasetid', 'class' => 'form-control', 'required' => 'required']);
echo html_writer::tag('option', '', ['value' => '']);
foreach ($alldatasets as $dataset) {
    echo html_writer::tag('option', 
        format_string($dataset->name) . ' (' . format_string($dataset->coursename) . ')', 
        ['value' => $dataset->id]
    );
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

// Algorithm selection
echo html_writer::start_div('form-group');
echo html_writer::label(get_string('selectalgorithm', 'block_studentperformancepredictor'), 'algorithm', true);
echo html_writer::start_tag('select', ['id' => 'algorithm', 'name' => 'algorithm', 'class' => 'form-control']);
foreach ($algorithmoptions as $value => $label) {
    echo html_writer::tag('option', $label, ['value' => $value]);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

// Hidden fields
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => 0]); // 0 = global model

// Submit button
echo html_writer::start_div('form-group');
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('trainglobalmodel', 'block_studentperformancepredictor'),
    'class' => 'btn btn-primary'
]);
echo html_writer::end_div();

echo html_writer::end_tag('form');

// Get existing global models
$globalmodels = $DB->get_records('block_spp_models', ['courseid' => 0], 'timemodified DESC');

// Display existing global models
if (!empty($globalmodels)) {
    echo $OUTPUT->heading(get_string('existingglobalmodels', 'block_studentperformancepredictor'), 3);

    $table = new html_table();
    $table->head = [
        get_string('modelname', 'block_studentperformancepredictor'),
        get_string('algorithm', 'block_studentperformancepredictor'),
        get_string('accuracy', 'block_studentperformancepredictor'),
        get_string('created', 'block_studentperformancepredictor'),
        get_string('status', 'block_studentperformancepredictor'),
        get_string('actions', 'block_studentperformancepredictor')
    ];

    foreach ($globalmodels as $model) {
        $row = [];
        $row[] = format_string($model->modelname);
        $row[] = isset($algorithmoptions[$model->algorithmtype]) ? 
                  $algorithmoptions[$model->algorithmtype] : $model->algorithmtype;
        $row[] = isset($model->accuracy) ? round($model->accuracy * 100, 2) . '%' : '-';
        $row[] = userdate($model->timemodified);

        // Status
        if ($model->trainstatus === 'failed') {
            $statusclass = 'badge badge-danger';
            $statustext = get_string('failed', 'block_studentperformancepredictor');
            $errordiv = !empty($model->errormessage) 
                ? html_writer::div($model->errormessage, 'text-danger small')
                : '';
            $row[] = html_writer::tag('span', $statustext, ['class' => $statusclass]) . $errordiv;
        } else if ($model->active) {
            $row[] = html_writer::tag('span', get_string('active', 'block_studentperformancepredictor'), 
                                     ['class' => 'badge badge-success']);
        } else {
            $row[] = html_writer::tag('span', get_string('inactive', 'block_studentperformancepredictor'), 
                                     ['class' => 'badge badge-secondary']);
        }

        // Actions
        if ($model->active) {
            $actions = html_writer::start_tag('form', [
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
                'value' => 0
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
            $actions = html_writer::start_tag('form', [
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
                'value' => 0
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

// Link back to admin page
echo html_writer::div(
    html_writer::link(
        new moodle_url('/admin/settings.php', ['section' => 'blocksettingstudentperformancepredictor']),
        get_string('backsettings', 'block_studentperformancepredictor'),
        ['class' => 'btn btn-secondary']
    ),
    'mt-3'
);

echo $OUTPUT->footer();

<?php
// blocks/studentperformancepredictor/admin/upload_dataset.php

define('AJAX_SCRIPT', true);
require_once('../../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');
require_once($CFG->libdir . '/moodlelib.php'); // For get_string, required_param, optional_param, require_sesskey, clean_filename
require_once($CFG->libdir . '/accesslib.php'); // For get_course, context_course, require_capability
require_once($CFG->libdir . '/enrollib.php'); // For require_login
require_once($CFG->libdir . '/filelib.php'); // For file handling
require_once($CFG->libdir . '/adminlib.php'); // For admin functions
require_once($CFG->libdir . '/weblib.php'); // For web utilities

// Set up response array
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
$datasetname = required_param('dataset_name', PARAM_TEXT);
$datasetformat = required_param('dataset_format', PARAM_ALPHA);
$datasetdesc = optional_param('dataset_description', '', PARAM_TEXT);

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
    $response['message'] = get_string('nofileuploaded', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
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
    $response['message'] = $errormessage;
    echo json_encode($response);
    die();
}

// Check file extension
$filename = $file['name'];
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

if ($datasetformat === 'csv' && $extension !== 'csv') {
    $response['message'] = get_string('invalidfileextension', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
} else if ($datasetformat === 'json' && $extension !== 'json') {
    $response['message'] = get_string('invalidfileextension', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
}

// Create dataset directory
try {
    $datasetdir = block_studentperformancepredictor_ensure_dataset_directory($courseid);
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    echo json_encode($response);
    die();
}

// Store the file with a unique name
$newfilename = $courseid . '_' . time() . '_' . clean_filename($filename);
$filepath = $datasetdir . '/' . $newfilename;

// Make sure directory has proper permissions for Railway deployment
if (is_dir($datasetdir)) {
    chmod($datasetdir, 0777); // Set directory permissions
}

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    $response['message'] = get_string('fileuploadfailed', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
}

// Make sure the uploaded file has the right permissions
chmod($filepath, 0666); // Set file permissions for Railway compatibility

// Extract column headers
$columns = array();

if ($datasetformat === 'csv') {
    $handle = fopen($filepath, 'r');
    if ($handle !== false) {
        $headers = fgetcsv($handle);
        foreach ($headers as $header) {
            $columns[] = $header;
        }
        fclose($handle);
    }
} else if ($datasetformat === 'json') {
    $content = file_get_contents($filepath);
    $jsonData = json_decode($content, true);
    if (is_array($jsonData) && !empty($jsonData)) {
        $firstRow = reset($jsonData);
        $columns = array_keys($firstRow);
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
    $response['success'] = true;
    $response['message'] = get_string('datasetsaved_backend', 'block_studentperformancepredictor');
    $response['datasetid'] = $datasetid;
} catch (Exception $e) {
    $response['message'] = get_string('datasetsaveerror', 'block_studentperformancepredictor') . ': ' . $e->getMessage();
    if (function_exists('debugging')) {
        debugging('Dataset upload error: ' . $e->getMessage(), defined('DEBUG_DEVELOPER') ? DEBUG_DEVELOPER : 0);
    }
}

// Return JSON response
// All orchestration is backend-driven
echo json_encode($response);

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

// blocks/studentperformancepredictor/amd/src/admin_dashboard.js

define(['jquery'], function($) {

    /**
     * Initialize the admin dashboard
     */
    var init = function() {
        // Handle risk category card collapsing
        $('.spp-risk-card .card-header').on('click', function() {
            var $header = $(this);
            var $card = $header.closest('.card');
            var $collapseSection = $card.find('.collapse');
            var $indicator = $header.find('.collapse-indicator i');

            // Toggle the collapse section directly
            $collapseSection.collapse('toggle');

            // Update the collapse indicator icon on shown/hidden events
            $collapseSection.on('shown.bs.collapse', function() {
                $indicator.removeClass('fa-chevron-down').addClass('fa-chevron-up');
            });

            $collapseSection.on('hidden.bs.collapse', function() {
                $indicator.removeClass('fa-chevron-up').addClass('fa-chevron-down');
            });
        });
    };

    return {
        init: init
    };
});

// blocks/studentperformancepredictor/amd/src/admin_interface.js

define(['jquery'], function($) {

    /**
     * Initialize the admin dashboard
     */
    var init = function() {
        // Handle risk category card collapsing
        $('.spp-risk-card .card-header').on('click', function() {
            var $header = $(this);
            var $card = $header.closest('.card');
            var $collapseSection = $card.find('.collapse');
            var $indicator = $header.find('.collapse-indicator i');

            // Toggle the collapse section directly
            $collapseSection.collapse('toggle');

            // Update the collapse indicator icon on shown/hidden events
            $collapseSection.on('shown.bs.collapse', function() {
                $indicator.removeClass('fa-chevron-down').addClass('fa-chevron-up');
            });

            $collapseSection.on('hidden.bs.collapse', function() {
                $indicator.removeClass('fa-chevron-up').addClass('fa-chevron-down');
            });
        });
    };

    return {
        init: init
    };
});
define(['jquery', 'core/ajax', 'core/str', 'core/notification', 'core/modal_factory', 'core/modal_events'], 
function($, Ajax, Str, Notification, ModalFactory, ModalEvents) {

    /**
     * Initialize admin interface.
     * 
     * @param {int} courseId Course ID
     */
    var init = function(courseId) {
        try {
            // Handle dataset upload form
            $('#spp-dataset-upload-form').on('submit', function(e) {
                e.preventDefault();

                // Validate form
                var form = $(this)[0];
                if (!form.checkValidity()) {
                    if (typeof form.reportValidity === 'function') {
                        form.reportValidity();
                    }
                    return;
                }

                var formData = new FormData(form);
                var submitButton = $(this).find('input[type="submit"]');
                var originalText = submitButton.val();
                submitButton.prop('disabled', true);

                // Load 'uploading' string asynchronously for button and status
                Str.get_string('uploading', 'moodle').done(function(uploadingStr) {
                    submitButton.val(uploadingStr);
                    $('#spp-upload-status').html(
                        '<div class="spp-loading"><i class="fa fa-spinner fa-spin"></i>' + uploadingStr + '</div>'
                    );
                }).fail(function() {
                    submitButton.val('Uploading...');
                    $('#spp-upload-status').html(
                        '<div class="spp-loading"><i class="fa fa-spinner fa-spin"></i>Uploading...</div>'
                    );
                });

                $.ajax({
                    url: M.cfg.wwwroot + '/blocks/studentperformancepredictor/admin/upload_dataset.php',
                    type: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    success: function(response) {
                        submitButton.prop('disabled', false);
                        submitButton.val(originalText);
                        $('#spp-upload-status').empty();

                        try {
                            // Properly handle response which might be string or object
                            var responseData;
                            if (typeof response === 'string') {
                                try {
                                    responseData = JSON.parse(response);
                                } catch (e) {
                                    console.error('Invalid JSON response', response);
                                    Notification.exception(new Error('Invalid server response'));
                                    return;
                                }
                            } else {
                                responseData = response;
                            }

                            if (responseData && responseData.success) {
                                // Show success message
                                var msg = responseData.message;
                                if (!msg) {
                                    Str.get_string('datasetsaved', 'block_studentperformancepredictor').done(function(s) {
                                        Notification.addNotification({ message: s, type: 'success' });
                                    });
                                } else {
                                    Notification.addNotification({ message: msg, type: 'success' });
                                }
                                setTimeout(function() { window.location.reload(); }, 1500);
                            } else {
                                var msg = responseData ? responseData.message : '';
                                if (!msg) {
                                    Str.get_string('datasetsaveerror', 'block_studentperformancepredictor').done(function(s) {
                                        Notification.addNotification({ message: s, type: 'error' });
                                    });
                                } else {
                                    Notification.addNotification({ message: msg, type: 'error' });
                                }
                            }
                        } catch (e) {
                            console.error('Error handling response:', e);
                            Str.get_string('datasetsaveerror', 'block_studentperformancepredictor').done(function(s) {
                                Notification.addNotification({ message: s, type: 'error' });
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error:', status, error);
                        submitButton.prop('disabled', false);
                        submitButton.val(originalText);
                        $('#spp-upload-status').empty();

                        // Get response error message if available
                        var errorMessage = error;
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response && response.message) {
                                errorMessage = response.message;
                            }
                        } catch (e) {
                            // Use default error message if JSON parsing fails
                        }

                        Str.get_string('uploaderror', 'block_studentperformancepredictor').done(function(s) {
                            Notification.addNotification({ message: s + ': ' + errorMessage, type: 'error' });
                        });
                    }
                });
            });

            // Handle model training form
            $('#spp-train-model-form').on('submit', function(e) {
                e.preventDefault();
                var datasetId = $('#datasetid').val();
                var algorithm = $('#algorithm').val();
                if (!datasetId) {
                    Str.get_string('selectdataset', 'block_studentperformancepredictor').done(function(s) {
                        Notification.addNotification({ message: s, type: 'error' });
                    });
                    return;
                }
                var submitButton = $(this).find('input[type="submit"]');
                var originalText = submitButton.val();
                submitButton.prop('disabled', true);
                Str.get_string('training', 'block_studentperformancepredictor').done(function(trainingStr) {
                    submitButton.val(trainingStr);
                });

                // Using traditional form submission for better file handling compatibility
                var form = $(this)[0];
                form.submit();
            });

            // Handle dataset deletion
            $('.spp-delete-dataset').on('click', function(e) {
                e.preventDefault();

                var button = $(this);
                var datasetId = button.data('dataset-id');

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
                                    courseid: courseId,
                                    sesskey: M.cfg.sesskey
                                },
                                dataType: 'json',
                                success: function(response) {
                                    if (response.success) {
                                        // Reload page to update dataset list.
                                        window.location.reload();
                                    } else {
                                        button.prop('disabled', false);

                                        // Show error message.
                                        Notification.addNotification({
                                            message: response.message,
                                            type: 'error'
                                        });
                                    }
                                },
                                error: function(xhr, status, error) {
                                    button.prop('disabled', false);

                                    // Try to parse error message from response
                                    var errorMessage = error;
                                    try {
                                        var response = JSON.parse(xhr.responseText);
                                        if (response && response.message) {
                                            errorMessage = response.message;
                                        }
                                    } catch (e) {
                                        // Use default error message
                                    }

                                    // Show error message.
                                    Str.get_string('actionerror', 'block_studentperformancepredictor').done(function(s) {
                                        Notification.addNotification({
                                            message: s + ': ' + errorMessage,
                                            type: 'error'
                                        });
                                    });
                                }
                            });
                        });

                        // When the modal is cancelled, re-enable the button
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

            // Handle model toggle (activate/deactivate)
            $('.spp-toggle-model-status').on('click', function(e) {
                e.preventDefault();

                var button = $(this);
                var modelId = button.data('model-id');
                var isActive = button.data('is-active');

                button.prop('disabled', true);

                // Confirm action based on current state
                var confirmKey = isActive ? 'confirmdeactivate' : 'confirmactivate';

                Str.get_strings([
                    {key: confirmKey, component: 'block_studentperformancepredictor'},
                    {key: isActive ? 'deactivate' : 'activate', component: 'block_studentperformancepredictor'},
                    {key: 'cancel', component: 'core'}
                ]).done(function(strings) {
                    ModalFactory.create({
                        type: ModalFactory.types.SAVE_CANCEL,
                        title: strings[0],
                        body: strings[0]
                    }).done(function(modal) {
                        modal.setSaveButtonText(strings[1]);

                        // When user confirms
                        modal.getRoot().on(ModalEvents.save, function() {
                            $.ajax({
                                url: M.cfg.wwwroot + '/blocks/studentperformancepredictor/admin/ajax_toggle_model.php',
                                type: 'POST',
                                data: {
                                    modelid: modelId,
                                    courseid: courseId,
                                    active: isActive ? 0 : 1,
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
                                        var response = JSON.parse(xhr.responseText);
                                        if (response && response.message) {
                                            errorMessage = response.message;
                                        }
                                    } catch (e) {
                                        // Use default message
                                    }

                                    Str.get_string('actionerror', 'block_studentperformancepredictor').done(function(s) {
                                        Notification.addNotification({
                                            message: s + ': ' + errorMessage,
                                            type: 'error'
                                        });
                                    });
                                }
                            });
                        });

                        // When cancelled
                        modal.getRoot().on(ModalEvents.cancel, function() {
                            button.prop('disabled', false);
                        });

                        modal.show();
                    });
                });
            });

        } catch (e) {
            console.error('Error initializing admin interface:', e);
            // Show a generic error notification
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

define(['jquery'], function($) {

    /**
     * Initialize the admin dashboard
     */
    var init = function() {
        // Handle risk category card collapsing
        $('.spp-risk-card .card-header').on('click', function() {
            var $header = $(this);
            var $card = $header.closest('.card');
            var $collapseSection = $card.find('.collapse');
            var $indicator = $header.find('.collapse-indicator i');

            // Toggle the collapse section directly
            $collapseSection.collapse('toggle');

            // Update the collapse indicator icon on shown/hidden events
            $collapseSection.on('shown.bs.collapse', function() {
                $indicator.removeClass('fa-chevron-down').addClass('fa-chevron-up');
            });

            $collapseSection.on('hidden.bs.collapse', function() {
                $indicator.removeClass('fa-chevron-up').addClass('fa-chevron-down');
            });
        });
    };

    return {
        init: init
    };
});
define(['jquery', 'core/ajax', 'core/str', 'core/notification', 'core/modal_factory', 'core/modal_events'], 
function($, Ajax, Str, Notification, ModalFactory, ModalEvents) {

    /**
     * Initialize admin interface.
     * 
     * @param {int} courseId Course ID
     */
    var init = function(courseId) {
        try {
            // Handle dataset upload form
            $('#spp-dataset-upload-form').on('submit', function(e) {
                e.preventDefault();

                // Validate form
                var form = $(this)[0];
                if (!form.checkValidity()) {
                    if (typeof form.reportValidity === 'function') {
                        form.reportValidity();
                    }
                    return;
                }

                var formData = new FormData(form);
                var submitButton = $(this).find('input[type="submit"]');
                var originalText = submitButton.val();
                submitButton.prop('disabled', true);

                // Load 'uploading' string asynchronously for button and status
                Str.get_string('uploading', 'moodle').done(function(uploadingStr) {
                    submitButton.val(uploadingStr);
                    $('#spp-upload-status').html(
                        '<div class="spp-loading"><i class="fa fa-spinner fa-spin"></i>' + uploadingStr + '</div>'
                    );
                }).fail(function() {
                    submitButton.val('Uploading...');
                    $('#spp-upload-status').html(
                        '<div class="spp-loading"><i class="fa fa-spinner fa-spin"></i>Uploading...</div>'
                    );
                });

                $.ajax({
                    url: M.cfg.wwwroot + '/blocks/studentperformancepredictor/admin/upload_dataset.php',
                    type: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    success: function(response) {
                        submitButton.prop('disabled', false);
                        submitButton.val(originalText);
                        $('#spp-upload-status').empty();

                        try {
                            // Properly handle response which might be string or object
                            var responseData;
                            if (typeof response === 'string') {
                                try {
                                    responseData = JSON.parse(response);
                                } catch (e) {
                                    console.error('Invalid JSON response', response);
                                    Notification.exception(new Error('Invalid server response'));
                                    return;
                                }
                            } else {
                                responseData = response;
                            }

                            if (responseData && responseData.success) {
                                // Show success message
                                var msg = responseData.message;
                                if (!msg) {
                                    Str.get_string('datasetsaved', 'block_studentperformancepredictor').done(function(s) {
                                        Notification.addNotification({ message: s, type: 'success' });
                                    });
                                } else {
                                    Notification.addNotification({ message: msg, type: 'success' });
                                }
                                setTimeout(function() { window.location.reload(); }, 1500);
                            } else {
                                var msg = responseData ? responseData.message : '';
                                if (!msg) {
                                    Str.get_string('datasetsaveerror', 'block_studentperformancepredictor').done(function(s) {
                                        Notification.addNotification({ message: s, type: 'error' });
                                    });
                                } else {
                                    Notification.addNotification({ message: msg, type: 'error' });
                                }
                            }
                        } catch (e) {
                            console.error('Error handling response:', e);
                            Str.get_string('datasetsaveerror', 'block_studentperformancepredictor').done(function(s) {
                                Notification.addNotification({ message: s, type: 'error' });
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error:', status, error);
                        submitButton.prop('disabled', false);
                        submitButton.val(originalText);
                        $('#spp-upload-status').empty();

                        // Get response error message if available
                        var errorMessage = error;
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response && response.message) {
                                errorMessage = response.message;
                            }
                        } catch (e) {
                            // Use default error message if JSON parsing fails
                        }

                        Str.get_string('uploaderror', 'block_studentperformancepredictor').done(function(s) {
                            Notification.addNotification({ message: s + ': ' + errorMessage, type: 'error' });
                        });
                    }
                });
            });

            // Handle model training form
            $('#spp-train-model-form').on('submit', function(e) {
                e.preventDefault();
                var datasetId = $('#datasetid').val();
                var algorithm = $('#algorithm').val();
                if (!datasetId) {
                    Str.get_string('selectdataset', 'block_studentperformancepredictor').done(function(s) {
                        Notification.addNotification({ message: s, type: 'error' });
                    });
                    return;
                }
                var submitButton = $(this).find('input[type="submit"]');
                var originalText = submitButton.val();
                submitButton.prop('disabled', true);
                Str.get_string('training', 'block_studentperformancepredictor').done(function(trainingStr) {
                    submitButton.val(trainingStr);
                });

                // Using traditional form submission for better file handling compatibility
                var form = $(this)[0];
                form.submit();
            });

            // Handle dataset deletion
            $('.spp-delete-dataset').on('click', function(e) {
                e.preventDefault();

                var button = $(this);
                var datasetId = button.data('dataset-id');

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
                                    courseid: courseId,
                                    sesskey: M.cfg.sesskey
                                },
                                dataType: 'json',
                                success: function(response) {
                                    if (response.success) {
                                        // Reload page to update dataset list.
                                        window.location.reload();
                                    } else {
                                        button.prop('disabled', false);

                                        // Show error message.
                                        Notification.addNotification({
                                            message: response.message,
                                            type: 'error'
                                        });
                                    }
                                },
                                error: function(xhr, status, error) {
                                    button.prop('disabled', false);

                                    // Try to parse error message from response
                                    var errorMessage = error;
                                    try {
                                        var response = JSON.parse(xhr.responseText);
                                        if (response && response.message) {
                                            errorMessage = response.message;
                                        }
                                    } catch (e) {
                                        // Use default error message
                                    }

                                    // Show error message.
                                    Str.get_string('actionerror', 'block_studentperformancepredictor').done(function(s) {
                                        Notification.addNotification({
                                            message: s + ': ' + errorMessage,
                                            type: 'error'
                                        });
                                    });
                                }
                            });
                        });

                        // When the modal is cancelled, re-enable the button
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

            // Handle model toggle (activate/deactivate)
            $('.spp-toggle-model-status').on('click', function(e) {
                e.preventDefault();

                var button = $(this);
                var modelId = button.data('model-id');
                var isActive = button.data('is-active');

                button.prop('disabled', true);

                // Confirm action based on current state
                var confirmKey = isActive ? 'confirmdeactivate' : 'confirmactivate';

                Str.get_strings([
                    {key: confirmKey, component: 'block_studentperformancepredictor'},
                    {key: isActive ? 'deactivate' : 'activate', component: 'block_studentperformancepredictor'},
                    {key: 'cancel', component: 'core'}
                ]).done(function(strings) {
                    ModalFactory.create({
                        type: ModalFactory.types.SAVE_CANCEL,
                        title: strings[0],
                        body: strings[0]
                    }).done(function(modal) {
                        modal.setSaveButtonText(strings[1]);

                        // When user confirms
                        modal.getRoot().on(ModalEvents.save, function() {
                            $.ajax({
                                url: M.cfg.wwwroot + '/blocks/studentperformancepredictor/admin/ajax_toggle_model.php',
                                type: 'POST',
                                data: {
                                    modelid: modelId,
                                    courseid: courseId,
                                    active: isActive ? 0 : 1,
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
                                        var response = JSON.parse(xhr.responseText);
                                        if (response && response.message) {
                                            errorMessage = response.message;
                                        }
                                    } catch (e) {
                                        // Use default message
                                    }

                                    Str.get_string('actionerror', 'block_studentperformancepredictor').done(function(s) {
                                        Notification.addNotification({
                                            message: s + ': ' + errorMessage,
                                            type: 'error'
                                        });
                                    });
                                }
                            });
                        });

                        // When cancelled
                        modal.getRoot().on(ModalEvents.cancel, function() {
                            button.prop('disabled', false);
                        });

                        modal.show();
                    });
                });
            });

        } catch (e) {
            console.error('Error initializing admin interface:', e);
            // Show a generic error notification
            Str.get_string('jserror', 'moodle').done(function(s) {
                Notification.exception(new Error(s + ': ' + e.message));
            });
        }
    };

    return {
        init: init
    };
});
define(['jquery', 'core/chartjs', 'core/str'], function($, Chart, Str) {

    /**
     * Initialize student prediction chart.
     */
    var init = function() {
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
        init: init,
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
        // Handle marking suggestions as viewed
        $(document).on('click', '.spp-mark-viewed', function(e) {
            e.preventDefault();
            var button = $(this);
            var suggestionId = button.data('id');

            if (!suggestionId) {
                console.error('No suggestion ID found');
                return;
            }

            // Disable button to prevent multiple clicks
            button.prop('disabled', true);
            button.addClass('disabled');

            try {
                var promise = Ajax.call([{
                    methodname: 'block_studentperformancepredictor_mark_suggestion_viewed',
                    args: { suggestionid: suggestionId }
                }]);

                promise[0].done(function(response) {
                    if (response.status) {
                        Str.get_string('viewed', 'block_studentperformancepredictor').done(function(viewedStr) {
                            button.replaceWith('<span class="badge bg-secondary">' + viewedStr + '</span>');
                        }).fail(function() {
                            button.replaceWith('<span class="badge bg-secondary">Viewed</span>');
                        });
                    } else {
                        button.prop('disabled', false);
                        button.removeClass('disabled');
                        Notification.addNotification({ 
                            message: response.message || 'Unknown error', 
                            type: 'error' 
                        });
                    }
                }).fail(function(error) {
                    button.prop('disabled', false);
                    button.removeClass('disabled');
                    console.error('AJAX call failed:', error);
                    Notification.exception(error);
                });
            } catch (err) {
                console.error('Error calling AJAX:', err);
                button.prop('disabled', false);
                button.removeClass('disabled');
                Notification.exception(new Error('Failed to mark suggestion as viewed'));
            }
        });

        // Handle marking suggestions as completed
        $(document).on('click', '.spp-mark-completed', function(e) {
            e.preventDefault();
            var button = $(this);
            var suggestionId = button.data('id');

            if (!suggestionId) {
                console.error('No suggestion ID found');
                return;
            }

            // Disable button to prevent multiple clicks
            button.prop('disabled', true);
            button.addClass('disabled');

            try {
                var promise = Ajax.call([{
                    methodname: 'block_studentperformancepredictor_mark_suggestion_completed',
                    args: { suggestionid: suggestionId }
                }]);

                promise[0].done(function(response) {
                    if (response.status) {
                        Str.get_strings([
                            {key: 'completed', component: 'block_studentperformancepredictor'},
                            {key: 'viewed', component: 'block_studentperformancepredictor'}
                        ]).done(function(strings) {
                            button.replaceWith('<span class="badge bg-success">' + strings[0] + '</span>');
                            var viewedBtn = button.closest('.spp-suggestion-actions').find('.spp-mark-viewed');
                            if (viewedBtn.length) {
                                viewedBtn.replaceWith('<span class="badge bg-secondary">' + strings[1] + '</span>');
                            }
                        }).fail(function() {
                            button.replaceWith('<span class="badge bg-success">Completed</span>');
                            var viewedBtn = button.closest('.spp-suggestion-actions').find('.spp-mark-viewed');
                            if (viewedBtn.length) {
                                viewedBtn.replaceWith('<span class="badge bg-secondary">Viewed</span>');
                            }
                        });
                    } else {
                        button.prop('disabled', false);
                        button.removeClass('disabled');
                        Notification.addNotification({ 
                            message: response.message || 'Unknown error', 
                            type: 'error' 
                        });
                    }
                }).fail(function(error) {
                    button.prop('disabled', false);
                    button.removeClass('disabled');
                    console.error('AJAX call failed:', error);
                    Notification.exception(error);
                });
            } catch (err) {
                console.error('Error calling AJAX:', err);
                button.prop('disabled', false);
                button.removeClass('disabled');
                Notification.exception(new Error('Failed to mark suggestion as completed'));
            }
        });

        // Handle teacher refresh predictions button
        $('.spp-refresh-predictions').on('click', function(e) {
            e.preventDefault();
            var button = $(this);

            // Disable button to prevent multiple clicks
            button.prop('disabled', true);
            button.addClass('disabled');

            var courseId = button.data('course-id');
            if (!courseId) {
                courseId = $('.block_studentperformancepredictor').data('course-id');
            }

            if (!courseId) {
                button.prop('disabled', false);
                button.removeClass('disabled');
                Str.get_string('error:nocourseid', 'block_studentperformancepredictor').done(function(msg) {
                    Notification.addNotification({ message: msg, type: 'error' });
                }).fail(function() {
                    Notification.addNotification({ message: 'No course ID', type: 'error' });
                });
                return;
            }

            Str.get_strings([
                {key: 'refreshconfirmation', component: 'block_studentperformancepredictor'},
                {key: 'refresh', component: 'block_studentperformancepredictor'},
                {key: 'cancel', component: 'moodle'},
                {key: 'refreshing', component: 'block_studentperformancepredictor'}
            ]).done(function(strings) {
                ModalFactory.create({
                    type: ModalFactory.types.SAVE_CANCEL,
                    title: strings[0],
                    body: strings[0]
                }).done(function(modal) {
                    modal.setSaveButtonText(strings[1]);

                    modal.getRoot().on(ModalEvents.save, function() {
                        var loadingMessage = $('<div class="spp-loading"><i class="fa fa-spinner fa-spin"></i> ' + strings[3] + '</div>');
                        button.after(loadingMessage);

                        try {
                            var promise = Ajax.call([{
                                methodname: 'block_studentperformancepredictor_refresh_predictions',
                                args: { courseid: courseId }
                            }]);

                            promise[0].done(function(response) {
                                button.prop('disabled', false);
                                button.removeClass('disabled');
                                loadingMessage.remove();

                                if (response.status) {
                                    Notification.addNotification({ message: response.message, type: 'success' });
                                    setTimeout(function() { window.location.reload(); }, 1500);
                                } else {
                                    Notification.addNotification({ message: response.message, type: 'error' });
                                }
                            }).fail(function(error) {
                                button.prop('disabled', false);
                                button.removeClass('disabled');
                                loadingMessage.remove();
                                console.error('AJAX call failed:', error);
                                Notification.exception(error);
                            });
                        } catch (err) {
                            button.prop('disabled', false);
                            button.removeClass('disabled');
                            loadingMessage.remove();
                            console.error('Error calling AJAX:', err);
                            Notification.exception(new Error('Failed to refresh predictions'));
                        }
                    });

                    modal.getRoot().on(ModalEvents.cancel, function() {
                        button.prop('disabled', false);
                        button.removeClass('disabled');
                    });

                    modal.show();
                }).catch(function(err) {
                    console.error('Error creating modal:', err);
                    button.prop('disabled', false);
                    button.removeClass('disabled');
                    Notification.exception(err);
                });
            }).catch(function(err) {
                console.error('Error loading strings:', err);
                button.prop('disabled', false);
                button.removeClass('disabled');
                Notification.exception(err);
            });
        });

        // Handle student generate prediction button with AJAX
        $('.spp-generate-prediction, .spp-update-prediction').on('click', function(e) {
            e.preventDefault();
            var button = $(this);
            var url = button.attr('href');

            // Disable button and show loading
            button.prop('disabled', true);
            button.closest('div').find('.spp-prediction-loading').show();

            // Extract course and user IDs
            var blockElement = $('.block_studentperformancepredictor');
            var courseId = button.data('course-id') || blockElement.data('course-id');
            var userId = button.data('user-id') || blockElement.data('user-id');

            // Add parameters to URL
            if (url.indexOf('?') !== -1) {
                url += '&redirect=0';
            } else {
                url += '?redirect=0';
            }

            // Add userid and courseid if not already in the URL
            if (url.indexOf('userid=') === -1 && userId) {
                url += '&userid=' + userId;
            }

            if (url.indexOf('courseid=') === -1 && courseId) {
                url += '&courseid=' + courseId;
            }

            // Add sesskey
            url += '&sesskey=' + M.cfg.sesskey;

            // Call the endpoint
            $.ajax({
                url: url,
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        Str.get_string('predictiongenerated', 'block_studentperformancepredictor').done(function(msg) {
                            Notification.addNotification({ message: msg, type: 'success' });
                        });

                        // Check if this is a student row in a table (for teacher's dashboard)
                        var studentRow = button.closest('tr');
                        if (studentRow.length) {
                            // Highlight the row to indicate update
                            studentRow.addClass('table-success');
                            setTimeout(function() {
                                studentRow.removeClass('table-success');
                            }, 3000);

                            // Update pass probability in the row
                            if (response.passprob) {
                                var probCell = studentRow.find('td:eq(1)');
                                if (probCell.length) {
                                    probCell.html('<span class="badge badge-' + 
                                                (response.riskvalue == 3 ? 'danger' : 
                                                response.riskvalue == 2 ? 'warning' : 'success') + 
                                                '">' + response.passprob + '%</span>');
                                }
                            }

                            // Don't reload the page for individual student updates
                        } else {
                            // Reload the page to show new prediction for student view
                            setTimeout(function() {
                                window.location.reload();
                            }, 1000);
                        }
                    } else {
                        // Show error
                        button.prop('disabled', false);
                        button.closest('div').find('.spp-prediction-loading').hide();

                        Notification.addNotification({ 
                            message: response.error || 'Unknown error', 
                            type: 'error' 
                        });
                    }
                },
                error: function(xhr, status, error) {
                    // Handle error
                    button.prop('disabled', false);
                    button.closest('div').find('.spp-prediction-loading').hide();
                    console.error('AJAX error:', xhr, status, error);

                    Str.get_string('predictionerror', 'block_studentperformancepredictor').done(function(msg) {
                        Notification.addNotification({ message: msg, type: 'error' });
                    });
                }
            });
        });
    };

    return {
        init: init
    };
});

// blocks/studentperformancepredictor/amd/src/teacher_dashboard.js

define(['jquery'], function($) {

    /**
     * Initialize the teacher dashboard
     */
    var init = function() {
        // Handle risk category card collapsing
        $('.spp-risk-card .card-header').on('click', function() {
            var $header = $(this);
            var $card = $header.closest('.card');
            var $collapseSection = $card.find('.collapse');
            var $indicator = $header.find('.collapse-indicator i');

            // Toggle the collapse section directly
            $collapseSection.collapse('toggle');

            // Update the collapse indicator icon on shown/hidden events
            $collapseSection.on('shown.bs.collapse', function() {
                $indicator.removeClass('fa-chevron-down').addClass('fa-chevron-up');
            });

            $collapseSection.on('hidden.bs.collapse', function() {
                $indicator.removeClass('fa-chevron-up').addClass('fa-chevron-down');
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
        // (These might be used by suggestion_generator or for other comprehensive reporting,
        // but the core features for ML prediction are the 'activity_level', 'submission_count', etc.)

        // Get log counts for course modules accessed - safer query
        $sql = "SELECT COUNT(DISTINCT cmid) FROM {logstore_standard_log}
                WHERE userid = ? AND action = ? AND target = ?";
        $data['total_course_modules_accessed'] = $DB->count_records_sql($sql, [$userid, 'viewed', 'course_module']);

        // Get log counts for current course - safer query
        $sql = "SELECT COUNT(DISTINCT cmid) FROM {logstore_standard_log}
                WHERE userid = ? AND courseid = ? AND action = ? AND target = ?";
        $data['current_course_modules_accessed'] = $DB->count_records_sql($sql, [$userid, $this->courseid, 'viewed', 'course_module']);

        // Forum posts counts - fixed query
        $sql = "SELECT COUNT(*) FROM {forum_posts} fp
                JOIN {forum_discussions} fd ON fp.discussion = fd.id
                WHERE fp.userid = ?";
        $data['total_forum_posts'] = $DB->count_records_sql($sql, [$userid]);

        // Forum posts in current course - fixed query
        $sql = "SELECT COUNT(*) FROM {forum_posts} fp
                JOIN {forum_discussions} fd ON fp.discussion = fd.id
                JOIN {forum} f ON fd.forum = f.id
                WHERE fp.userid = ? AND f.course = ?";
        $data['current_course_forum_posts'] = $DB->count_records_sql($sql, [$userid, $this->courseid]);

        // Assignment submissions
        $params_assign = ['userid' => $userid, 'status' => 'submitted'];
        $data['total_assignment_submissions'] = $DB->count_records('assign_submission', $params_assign);

        // Assignment submissions in current course
        $sql_current_assign_submissions = "SELECT COUNT(*) FROM {assign_submission} sub
                                           JOIN {assign} a ON sub.assignment = a.id
                                           WHERE sub.userid = :userid AND a.course = :courseid AND sub.status = :status";
        $data['current_course_assignment_submissions'] = $DB->count_records_sql($sql_current_assign_submissions, ['userid' => $userid, 'courseid' => $this->courseid, 'status' => 'submitted']);

        // Quiz attempts
        $params_quiz = ['userid' => $userid, 'state' => 'finished'];
        $data['total_quiz_attempts'] = $DB->count_records('quiz_attempts', $params_quiz);

        // Quiz attempts in current course
        $sql_current_quiz_attempts = "SELECT COUNT(*) FROM {quiz_attempts} qa
                                      JOIN {quiz} q ON qa.quiz = q.id
                                      WHERE qa.userid = :userid AND q.course = :courseid AND qa.state = :state";
        $data['current_course_quiz_attempts'] = $DB->count_records_sql($sql_current_quiz_attempts, ['userid' => $userid, 'courseid' => $this->courseid, 'state' => 'finished']);

        // Current course grade
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

        // Calculate overall engagement score
        $data['engagement_score'] = $this->calculate_engagement_score($data);

        // Calculate historical performance
        $data['historical_performance'] = $this->calculate_historical_performance($userid);

        return $data;
    }

    /**
     * Calculate overall engagement score (0-1).
     *
     * @param array $data Student data
     * @return float Engagement score
     */
    protected function calculate_engagement_score($data) {
        $score = 0;
        $factors = 0;

        // Module access factor
        if (isset($data['current_course_modules_accessed']) && $data['current_course_modules_accessed'] > 0) {
            $score += min(1, $data['current_course_modules_accessed'] / 10); // Scale up to 10 modules
            $factors++;
        }

        // Forum participation factor
        if (isset($data['current_course_forum_posts']) && $data['current_course_forum_posts'] > 0) {
            $score += min(1, $data['current_course_forum_posts'] / 5); // Scale up to 5 posts
            $factors++;
        }

        // Assignment submission factor
        if (isset($data['current_course_assignment_submissions']) && $data['current_course_assignment_submissions'] > 0) {
            $score += min(1, $data['current_course_assignment_submissions'] / 3); // Scale up to 3 submissions
            $factors++;
        }

        // Quiz attempt factor
        if (isset($data['current_course_quiz_attempts']) && $data['current_course_quiz_attempts'] > 0) {
            $score += min(1, $data['current_course_quiz_attempts'] / 3); // Scale up to 3 attempts
            $factors++;
        }

        // Last access factor (more recent = higher score)
        if (isset($data['days_since_last_access']) && $data['days_since_last_access'] < 30) { // Within last month
            $score += max(0, 1 - ($data['days_since_last_access'] / 30));
            $factors++;
        }

        // Return average score, default to 0.5 if no factors available
        return $factors > 0 ? $score / $factors : 0.5;
    }

    /**
     * Calculate historical performance score (0-1).
     *
     * @param int $userid User ID
     * @return float Historical performance score
     */
    protected function calculate_historical_performance($userid) {
        global $DB;

        // Get average course grade percentage for completed courses
        $sql = "SELECT AVG(gg.finalgrade/gi.grademax) as avggrade
                FROM {grade_grades} gg
                JOIN {grade_items} gi ON gg.itemid = gi.id
                WHERE gg.userid = ? AND gi.itemtype = ? AND gg.finalgrade IS NOT NULL";

        $result = $DB->get_record_sql($sql, [$userid, 'course']);

        if ($result && $result->avggrade !== null) {
            return (float)$result->avggrade;
        }

        return 0.5; // Default to neutral if no history
    }

    /**
     * Create feature vector from student data for prediction.
     *
     * @param array $studentdata Student data
     * @return array Feature vector
     */
    protected function create_feature_vector($studentdata) {
        // Return the data as an associative array for the API
        return $studentdata;
    }

    /**
     * Make prediction using the model (backend-driven orchestration).
     *
     * This method calls the Python backend /predict endpoint and uses the returned prediction/probabilities.
     * No ML logic is performed in PHP.
     *
     * @param array $features Feature vector
     * @return object Prediction result
     */
    protected function make_prediction($features) {
        global $CFG;
        $result = new \stdClass();
        $result->details = array();

        // Call Python backend for prediction
        $apiurl = get_config('block_studentperformancepredictor', 'python_api_url');
        if (empty($apiurl)) {
            $apiurl = 'http://localhost:5000/predict';
        } else {
            // Ensure API URL ends with the predict endpoint
            if (substr($apiurl, -8) !== '/predict') {
                $apiurl = rtrim($apiurl, '/') . '/predict';
            }
        }
        $apikey = get_config('block_studentperformancepredictor', 'python_api_key');
        if (empty($apikey)) {
            $apikey = 'changeme';
        }

        // Initialize curl
        $curl = new \curl();
        $payload = [
            'model_id' => $this->model->modelid,
            'features' => $features
        ];
        $options = [
            'CURLOPT_TIMEOUT' => 60,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_HTTPHEADER' => [
                'Content-Type: application/json',
                'X-API-Key: ' . $apikey
            ],
            // Add these for Windows/XAMPP compatibility
            'CURLOPT_SSL_VERIFYHOST' => 0,
            'CURLOPT_SSL_VERIFYPEER' => 0
        ];

        // Log the request for debugging
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
                    // Get the pass probability from response
                    if (isset($data['probability'])) {
                        $result->passprob = $data['probability'];
                    } else if (isset($data['probabilities']) && is_array($data['probabilities']) && count($data['probabilities']) >= 2) {
                        // Use the probability for class 1 (passing)
                        $result->passprob = $data['probabilities'][1];
                    } else if (isset($data['probabilities']) && is_array($data['probabilities'])) {
                        // Use the highest probability if class is unclear
                        $result->passprob = max($data['probabilities']);
                    } else if ($data['prediction'] == 1) {
                        // If only binary prediction available
                        $result->passprob = 0.75; // Default high probability for positive prediction
                    } else {
                        $result->passprob = 0.25; // Default low probability for negative prediction
                    }
                    $result->details['backend'] = $data;
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

        // Ensure passprob is within valid range
        $result->passprob = max(0, min(1, $result->passprob));
        return $result;
    }

    /**
     * Calculate risk level based on pass probability.
     *
     * @param float $passprob Pass probability
     * @return int Risk level (1=low, 2=medium, 3=high)
     */
    protected function calculate_risk_level($passprob) {
        // Get risk thresholds from settings with defaults
        $lowrisk = get_config('block_studentperformancepredictor', 'lowrisk');
        if (empty($lowrisk) || !is_numeric($lowrisk)) {
            $lowrisk = 0.7; // Default
        }

        $mediumrisk = get_config('block_studentperformancepredictor', 'mediumrisk');
        if (empty($mediumrisk) || !is_numeric($mediumrisk)) {
            $mediumrisk = 0.4; // Default
        }

        if ($passprob >= $lowrisk) {
            return 1; // Low risk
        } else if ($passprob >= $mediumrisk) {
            return 2; // Medium risk
        } else {
            return 3; // High risk
        }
    }

    /**
     * Generate predictions for all students in the course.
     *
     * @return array Array of prediction records
     */
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
                // Log error and continue with next student
                debugging('Error predicting for student ' . $student->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                $errors[$student->id] = $e->getMessage();
            }
        }

        // Log summary
        debugging('Predictions generated for ' . count($predictions) . ' students with ' .
                 count($errors) . ' errors', DEBUG_DEVELOPER);

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
// blocks/studentperformancepredictor/classes/analytics/training_manager.php

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
            debugging('Error creating model record', DEBUG_DEVELOPER);
            return false;
        }

        // Create adhoc task with the correct namespace/class
        $task = new \block_studentperformancepredictor\task\adhoc_train_model();
        $customdata = [
            'courseid' => $courseid,
            'datasetid' => $datasetid,
            'algorithm' => $algorithm,
            'userid' => $USER->id,
            'modelid' => $modelid,
            'timequeued' => time()
        ];
        $task->set_custom_data($customdata);

        // Add debugging information
        debugging('Scheduling training task for course ' . $courseid . ' with dataset ' . $datasetid, DEBUG_DEVELOPER);

        try {
            // Queue the task with high priority
            \core\task\manager::queue_adhoc_task($task, true);
            debugging('Training task scheduled successfully', DEBUG_DEVELOPER);

            // Log initial training event
            self::log_training_event($modelid, 'scheduled', 'Training task scheduled');

            return true;
        } catch (\Exception $e) {
            debugging('Error scheduling training task: ' . $e->getMessage(), DEBUG_DEVELOPER);

            // Update the model record to indicate failure
            $model = $DB->get_record('block_spp_models', ['id' => $modelid]);
            if ($model) {
                $model->trainstatus = 'failed';
                $model->errormessage = 'Failed to schedule training task: ' . $e->getMessage();
                $DB->update_record('block_spp_models', $model);
            }

            return false;
        }
    }

    /**
     * Check if there's already a training task scheduled.
     *
     * @param int $courseid The course ID
     * @return bool True if a task exists
     */
    public static function has_pending_training($courseid) {
        global $DB;

        // Check for pending models in the database
        $sql = "SELECT COUNT(*) FROM {block_spp_models} 
                WHERE courseid = :courseid AND trainstatus IN ('pending', 'training')";
        $count = $DB->count_records_sql($sql, ['courseid' => $courseid]);

        if ($count > 0) {
            return true;
        }

        // Also check for pending adhoc_train_model tasks
        $sql = "SELECT COUNT(*) FROM {task_adhoc}
                WHERE classname = :classname
                AND " . $DB->sql_like('customdata', ':customdata') . "
                AND nextruntime > 0";

        $params = [
            'classname' => '\\block_studentperformancepredictor\\task\\adhoc_train_model',
            'customdata' => '%"courseid":' . $courseid . '%'
        ];

        $count = $DB->count_records_sql($sql, $params);

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
    public static function log_training_event($modelid, $event, $message, $level = 'info') {
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
    public static function update_model_status($modelid, $status, $data = []) {
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

        // Validate parameters.
        $params = self::validate_parameters(self::mark_suggestion_viewed_parameters(), [
            'suggestionid' => $suggestionid
        ]);

        // Get suggestion.
        $suggestion = $DB->get_record('block_spp_suggestions', ['id' => $params['suggestionid']], '*', MUST_EXIST);

        // Security checks.
        $context = \context_course::instance($suggestion->courseid);
        self::validate_context($context);

        // Only the user who received the suggestion can mark it as viewed.
        if ($suggestion->userid != $USER->id) {
            throw new \moodle_exception('nopermission', 'block_studentperformancepredictor');
        }

        // Mark as viewed.
        $success = block_studentperformancepredictor_mark_suggestion_viewed($suggestion->id);

        return [
            'status' => $success,
            'message' => $success ? get_string('suggestion_marked_viewed', 'block_studentperformancepredictor') 
                                 : get_string('suggestion_marked_viewed_error', 'block_studentperformancepredictor')
        ];
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

        // Validate parameters.
        $params = self::validate_parameters(self::mark_suggestion_completed_parameters(), [
            'suggestionid' => $suggestionid
        ]);

        // Get suggestion.
        $suggestion = $DB->get_record('block_spp_suggestions', ['id' => $params['suggestionid']], '*', MUST_EXIST);

        // Security checks.
        $context = \context_course::instance($suggestion->courseid);
        self::validate_context($context);

        // Only the user who received the suggestion can mark it as completed.
        if ($suggestion->userid != $USER->id) {
            throw new \moodle_exception('nopermission', 'block_studentperformancepredictor');
        }

        // Mark as completed.
        $success = block_studentperformancepredictor_mark_suggestion_completed($suggestion->id);

        return [
            'status' => $success,
            'message' => $success ? get_string('suggestion_marked_completed', 'block_studentperformancepredictor') 
                                 : get_string('suggestion_marked_completed_error', 'block_studentperformancepredictor')
        ];
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
        global $USER, $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::get_student_predictions_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid
        ]);

        // Security checks.
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);

        // If no user ID specified, use current user.
        if (empty($params['userid'])) {
            $params['userid'] = $USER->id;
        }

        // Check permission if viewing other user's predictions.
        if ($params['userid'] != $USER->id && !has_capability('block/studentperformancepredictor:viewallpredictions', $context)) {
            throw new \moodle_exception('nopermission', 'block_studentperformancepredictor');
        }

        // Get prediction.
        $prediction = block_studentperformancepredictor_get_student_prediction($params['courseid'], $params['userid']);

        if (!$prediction) {
            return [
                'has_prediction' => false,
                'message' => get_string('noprediction', 'block_studentperformancepredictor')
            ];
        }

        // Get suggestions.
        $suggestions = block_studentperformancepredictor_get_suggestions($prediction->id);

        $suggestiondata = [];
        foreach ($suggestions as $suggestion) {
            $suggestiondata[] = [
                'id' => $suggestion->id,
                'reason' => $suggestion->reason,
                'resource_type' => $suggestion->resourcetype,
                'viewed' => (bool)$suggestion->viewed,
                'completed' => (bool)$suggestion->completed,
                'cmid' => $suggestion->cmid,
                'cmname' => $suggestion->cmname ?? '',
                'modulename' => $suggestion->modulename ?? ''
            ];
        }

        return [
            'has_prediction' => true,
            'prediction' => [
                'id' => $prediction->id,
                'pass_probability' => round($prediction->passprob * 100),
                'risk_level' => $prediction->riskvalue,
                'risk_text' => block_studentperformancepredictor_get_risk_text($prediction->riskvalue),
                'time_created' => $prediction->timecreated,
                'time_modified' => $prediction->timemodified
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
                'risk_text' => new \external_value(PARAM_TEXT, 'Risk level text'),
                'time_created' => new \external_value(PARAM_INT, 'Time created timestamp'),
                'time_modified' => new \external_value(PARAM_INT, 'Time modified timestamp')
            ], 'Prediction data', VALUE_OPTIONAL),
            'suggestions' => new \external_multiple_structure(
                new \external_single_structure([
                    'id' => new \external_value(PARAM_INT, 'Suggestion ID'),
                    'reason' => new \external_value(PARAM_TEXT, 'Suggestion reason'),
                    'resource_type' => new \external_value(PARAM_TEXT, 'Resource type'),
                    'viewed' => new \external_value(PARAM_BOOL, 'Whether suggestion was viewed'),
                    'completed' => new \external_value(PARAM_BOOL, 'Whether suggestion was completed'),
                    'cmid' => new \external_value(PARAM_INT, 'Course module ID', VALUE_OPTIONAL),
                    'cmname' => new \external_value(PARAM_TEXT, 'Course module name', VALUE_OPTIONAL),
                    'modulename' => new \external_value(PARAM_TEXT, 'Module name', VALUE_OPTIONAL)
                ]),
                'Suggestions',
                VALUE_OPTIONAL
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

        // Validate parameters.
        $params = self::validate_parameters(self::trigger_model_training_parameters(), [
            'courseid' => $courseid,
            'datasetid' => $datasetid,
            'algorithm' => $algorithm
        ]);

        // Security checks.
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);

        // Check permission.
        require_capability('block/studentperformancepredictor:managemodels', $context);

        // Queue the training task.
        $task = new \block_studentperformancepredictor\task\train_model();
        $task->set_custom_data([
            'courseid' => $params['courseid'],
            'datasetid' => $params['datasetid'],
            'algorithm' => $params['algorithm'],
            'userid' => $USER->id
        ]);

        // Queue the task to run as soon as possible.
        \core\task\manager::queue_adhoc_task($task, true);

        return [
            'status' => true,
            'message' => get_string('model_training_queued', 'block_studentperformancepredictor')
        ];
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
        global $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::refresh_predictions_parameters(), [
            'courseid' => $courseid
        ]);

        // Security checks.
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);

        // Check permission.
        require_capability('block/studentperformancepredictor:viewallpredictions', $context);

        // Trigger refresh.
        $success = block_studentperformancepredictor_trigger_prediction_refresh($params['courseid'], $USER->id);

        return [
            'status' => $success,
            'message' => $success ? get_string('predictionsrefreshqueued', 'block_studentperformancepredictor') 
                                 : get_string('predictionsrefresherror', 'block_studentperformancepredictor')
        ];
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

// Add this line to include lib.php which contains the function definitions
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

/**
 * Admin view class for the admin dashboard.
 *
 * This class prepares data for the admin dashboard template.
 */
class admin_view implements \renderable, \templatable {
    /** @var int Course ID - for admin view this can be 0 to show all courses */
    protected $courseid;

    /** @var int The filter course ID if filtering by a specific course */
    protected $filter_courseid;

    /** @var bool Is this a site-wide admin view */
    protected $is_sitewide;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID (0 for all courses)
     * @param int $filter_courseid Optional course ID to filter by
     */
    public function __construct($courseid, $filter_courseid = 0) {
        $this->courseid = $courseid;
        $this->filter_courseid = $filter_courseid;
        $this->is_sitewide = ($courseid === 0);
    }

    /**
     * Export data for template.
     *
     * @param \renderer_base $output The renderer
     * @return \stdClass Data for template
     */
    public function export_for_template(\renderer_base $output) {
        global $CFG, $DB, $PAGE;

        $data = new \stdClass();
        $data->heading = get_string('modelmanagement', 'block_studentperformancepredictor');
        $data->courseid = $this->courseid;
        $data->is_sitewide = $this->is_sitewide;

        // Create URLs
        $data->managemodelsurl = new \moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', 
                                             ['courseid' => $this->courseid]);
        $data->managedatasetsurl = new \moodle_url('/blocks/studentperformancepredictor/admin/managedatasets.php', 
                                               ['courseid' => $this->courseid]);
        $data->refreshpredictionsurl = new \moodle_url('/blocks/studentperformancepredictor/admin/refreshpredictions.php', 
                                                   ['courseid' => $this->courseid]);

        // For site-wide admin, we need to get courses with active models
        if ($this->is_sitewide) {
            // Get courses with active models
            $courses_with_models = $this->get_courses_with_models();
            $data->has_courses = !empty($courses_with_models);
            $data->courses = $courses_with_models;

            // Check if we're filtering by a specific course
            if ($this->filter_courseid && isset($courses_with_models[$this->filter_courseid])) {
                $data->filter_courseid = $this->filter_courseid;
                $data->filter_coursename = $courses_with_models[$this->filter_courseid]->fullname;
                $data->is_filtered = true;

                // Create course selector URL
                $data->course_selector_url = new \moodle_url('/blocks/studentperformancepredictor/reports.php', 
                                                         ['admin' => 1]);

                // For filtered view, get stats and students for the selected course
                $active_courseid = $this->filter_courseid;
                $data->hasmodel = \block_studentperformancepredictor_has_active_model($active_courseid);

                if ($data->hasmodel) {
                    // Get risk statistics for this course
                    $riskStats = \block_studentperformancepredictor_get_course_risk_stats($active_courseid);

                    $data->totalstudents = $riskStats->total;
                    $data->highrisk = $riskStats->highrisk;
                    $data->mediumrisk = $riskStats->mediumrisk;
                    $data->lowrisk = $riskStats->lowrisk;

                    // Calculate percentages
                    if ($data->totalstudents > 0) {
                        $data->highriskpercent = round(($data->highrisk / $data->totalstudents) * 100);
                        $data->mediumriskpercent = round(($data->mediumrisk / $data->totalstudents) * 100);
                        $data->lowriskpercent = round(($data->lowrisk / $data->totalstudents) * 100);
                    } else {
                        $data->highriskpercent = 0;
                        $data->mediumriskpercent = 0;
                        $data->lowriskpercent = 0;
                    }

                    // Get students in this course by risk level
                    $data->students_highrisk = $this->get_students_by_risk_level(3, $active_courseid);
                    $data->students_mediumrisk = $this->get_students_by_risk_level(2, $active_courseid);
                    $data->students_lowrisk = $this->get_students_by_risk_level(1, $active_courseid);

                    // Check if we have students in each category
                    $data->has_highrisk_students = !empty($data->students_highrisk);
                    $data->has_mediumrisk_students = !empty($data->students_mediumrisk);
                    $data->has_lowrisk_students = !empty($data->students_lowrisk);

                    // Create chart data for this course
                    $data->haschart = true;
                    $chartData = [
                        'labels' => [
                            get_string('highrisk_label', 'block_studentperformancepredictor'),
                            get_string('mediumrisk_label', 'block_studentperformancepredictor'),
                            get_string('lowrisk_label', 'block_studentperformancepredictor')
                        ],
                        'data' => [$data->highrisk, $data->mediumrisk, $data->lowrisk]
                    ];
                    $data->chartdata = json_encode($chartData);
                }
            } else {
                // Not filtering - show aggregated stats for all courses
                $data->is_filtered = false;
                $data->sitewide_stats = $this->get_sitewide_risk_stats();

                // Get high risk students across all courses
                $data->all_highrisk_students = $this->get_all_students_by_risk_level(3);
                $data->has_highrisk_students = !empty($data->all_highrisk_students);

                // Create global chart data
                $data->haschart = true;
                $chartData = [
                    'labels' => [
                        get_string('highrisk_label', 'block_studentperformancepredictor'),
                        get_string('mediumrisk_label', 'block_studentperformancepredictor'),
                        get_string('lowrisk_label', 'block_studentperformancepredictor')
                    ],
                    'data' => [
                        $data->sitewide_stats->highrisk, 
                        $data->sitewide_stats->mediumrisk, 
                        $data->sitewide_stats->lowrisk
                    ]
                ];
                $data->chartdata = json_encode($chartData);
            }
        } else {
            // Course-specific admin view
            $data->hasmodel = \block_studentperformancepredictor_has_active_model($this->courseid);

            if ($data->hasmodel) {
                // Get risk statistics
                $riskStats = \block_studentperformancepredictor_get_course_risk_stats($this->courseid);

                $data->totalstudents = $riskStats->total;
                $data->highrisk = $riskStats->highrisk;
                $data->mediumrisk = $riskStats->mediumrisk;
                $data->lowrisk = $riskStats->lowrisk;

                // Calculate percentages
                if ($data->totalstudents > 0) {
                    $data->highriskpercent = round(($data->highrisk / $data->totalstudents) * 100);
                    $data->mediumriskpercent = round(($data->mediumrisk / $data->totalstudents) * 100);
                    $data->lowriskpercent = round(($data->lowrisk / $data->totalstudents) * 100);
                } else {
                    $data->highriskpercent = 0;
                    $data->mediumriskpercent = 0;
                    $data->lowriskpercent = 0;
                }

                // Get students by risk level
                $data->students_highrisk = $this->get_students_by_risk_level(3, $this->courseid);
                $data->students_mediumrisk = $this->get_students_by_risk_level(2, $this->courseid);
                $data->students_lowrisk = $this->get_students_by_risk_level(1, $this->courseid);

                // Check if we have students in each category
                $data->has_highrisk_students = !empty($data->students_highrisk);
                $data->has_mediumrisk_students = !empty($data->students_mediumrisk);
                $data->has_lowrisk_students = !empty($data->students_lowrisk);

                // Create chart data
                $data->haschart = true;
                $chartData = [
                    'labels' => [
                        get_string('highrisk_label', 'block_studentperformancepredictor'),
                        get_string('mediumrisk_label', 'block_studentperformancepredictor'),
                        get_string('lowrisk_label', 'block_studentperformancepredictor')
                    ],
                    'data' => [$data->highrisk, $data->mediumrisk, $data->lowrisk]
                ];
                $data->chartdata = json_encode($chartData);
            } else {
                $data->nomodeltext = get_string('noactivemodel', 'block_studentperformancepredictor');
            }
        }

        return $data;
    }

    /**
     * Get courses that have active prediction models.
     *
     * @return array Course objects with model information
     */
    protected function get_courses_with_models() {
        global $DB;

        $sql = "SELECT c.id, c.fullname, c.shortname, c.visible, 
                       COUNT(DISTINCT p.id) as prediction_count,
                       SUM(CASE WHEN p.riskvalue = 3 THEN 1 ELSE 0 END) as highrisk,
                       SUM(CASE WHEN p.riskvalue = 2 THEN 1 ELSE 0 END) as mediumrisk,
                       SUM(CASE WHEN p.riskvalue = 1 THEN 1 ELSE 0 END) as lowrisk
                FROM {course} c
                JOIN {block_spp_predictions} p ON p.courseid = c.id
                JOIN {block_spp_models} m ON p.modelid = m.id
                WHERE m.active = 1
                GROUP BY c.id, c.fullname, c.shortname, c.visible
                ORDER BY highrisk DESC, c.fullname ASC";

        $courses = $DB->get_records_sql($sql);

        // Format the course data for display
        foreach ($courses as $course) {
            // Calculate percentages
            $total = $course->prediction_count;
            if ($total > 0) {
                $course->highrisk_percent = round(($course->highrisk / $total) * 100);
                $course->mediumrisk_percent = round(($course->mediumrisk / $total) * 100);
                $course->lowrisk_percent = round(($course->lowrisk / $total) * 100);
            } else {
                $course->highrisk_percent = 0;
                $course->mediumrisk_percent = 0;
                $course->lowrisk_percent = 0;
            }

            // Add view URL
            $course->view_url = new \moodle_url('/blocks/studentperformancepredictor/reports.php', 
                                              ['admin' => 1, 'courseid' => $course->id]);
        }

        return $courses;
    }

    /**
     * Get aggregated risk statistics across all courses.
     *
     * @return object Object with risk statistics
     */
    protected function get_sitewide_risk_stats() {
        global $DB;

        $stats = new \stdClass();

        // Get counts from all predictions
        $sql = "SELECT COUNT(DISTINCT p.id) as total,
                       SUM(CASE WHEN p.riskvalue = 3 THEN 1 ELSE 0 END) as highrisk,
                       SUM(CASE WHEN p.riskvalue = 2 THEN 1 ELSE 0 END) as mediumrisk,
                       SUM(CASE WHEN p.riskvalue = 1 THEN 1 ELSE 0 END) as lowrisk
                FROM {block_spp_predictions} p
                JOIN {block_spp_models} m ON p.modelid = m.id
                WHERE m.active = 1";

        $result = $DB->get_record_sql($sql);

        if ($result) {
            $stats->total = $result->total;
            $stats->highrisk = $result->highrisk;
            $stats->mediumrisk = $result->mediumrisk;
            $stats->lowrisk = $result->lowrisk;

            // Calculate percentages
            if ($stats->total > 0) {
                $stats->highriskpercent = round(($stats->highrisk / $stats->total) * 100);
                $stats->highriskpercent = round(($stats->highrisk / $stats->total) * 100);
                $stats->mediumriskpercent = round(($stats->mediumrisk / $stats->total) * 100);
                $stats->lowriskpercent = round(($stats->lowrisk / $stats->total) * 100);
            } else {
                $stats->highriskpercent = 0;
                $stats->mediumriskpercent = 0;
                $stats->lowriskpercent = 0;
            }
        } else {
            // Default values if no predictions exist
            $stats->total = 0;
            $stats->highrisk = 0;
            $stats->mediumrisk = 0;
            $stats->lowrisk = 0;
            $stats->highriskpercent = 0;
            $stats->mediumriskpercent = 0;
            $stats->lowriskpercent = 0;
        }

        return $stats;
    }

    /**
     * Get students by risk level with prediction details for a specific course.
     *
     * @param int $risk_level Risk level (1=low, 2=medium, 3=high)
     * @param int $courseid Course ID
     * @return array Students with this risk level
     */
    protected function get_students_by_risk_level($risk_level, $courseid) {
        global $DB, $PAGE;

        $sql = "SELECT p.*, u.id as userid, u.firstname, u.lastname, u.email, u.picture, u.imagealt, 
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                       c.fullname as coursename, c.shortname as courseshortname
                FROM {block_spp_predictions} p
                JOIN {user} u ON p.userid = u.id
                JOIN {course} c ON p.courseid = c.id
                JOIN {block_spp_models} m ON p.modelid = m.id
                WHERE p.courseid = :courseid 
                AND p.riskvalue = :risklevel
                AND m.active = 1
                ORDER BY u.lastname, u.firstname";

        $params = [
            'courseid' => $courseid,
            'risklevel' => $risk_level
        ];

        $records = $DB->get_records_sql($sql, $params);
        $students = [];

        foreach ($records as $record) {
            // Get prediction details
            $prediction_data = json_decode($record->predictiondata, true);

            // Extract risk factors from prediction data
            $risk_factors = $this->extract_risk_factors($prediction_data, $risk_level);

            // Get user picture URL
            $user_picture = new \user_picture($record);
            $user_picture->size = 35; // Size in pixels
            $picture_url = $user_picture->get_url($PAGE);

            // Create student object
            $student = new \stdClass();
            $student->id = $record->userid;
            $student->fullname = fullname($record);
            $student->picture = $user_picture->get_url($PAGE)->out(false);
            $student->passprob = round($record->passprob * 100);
            $student->profileurl = new \moodle_url('/user/view.php', 
                                                 ['id' => $record->userid, 'course' => $courseid]);
            $student->risk_factors = $risk_factors;
            $student->prediction_id = $record->id;
            $student->coursename = $record->coursename;
            $student->courseshortname = $record->courseshortname;
            $student->generate_url = new \moodle_url('/blocks/studentperformancepredictor/generate_prediction.php', 
                                                   ['courseid' => $courseid, 
                                                    'userid' => $record->userid, 
                                                    'sesskey' => sesskey()]);

            $students[] = $student;
        }

        return $students;
    }

    /**
     * Get all high-risk students across all courses.
     *
     * @param int $risk_level Risk level (1=low, 2=medium, 3=high)
     * @return array Students with this risk level from all courses
     */
    protected function get_all_students_by_risk_level($risk_level) {
        global $DB, $PAGE;

        $sql = "SELECT p.*, u.id as userid, u.firstname, u.lastname, u.email, u.picture, u.imagealt, 
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                       c.id as courseid, c.fullname as coursename, c.shortname as courseshortname
                FROM {block_spp_predictions} p
                JOIN {user} u ON p.userid = u.id
                JOIN {course} c ON p.courseid = c.id
                JOIN {block_spp_models} m ON p.modelid = m.id
                WHERE p.riskvalue = :risklevel
                AND m.active = 1
                ORDER BY c.fullname, u.lastname, u.firstname";

        $params = [
            'risklevel' => $risk_level
        ];

        $records = $DB->get_records_sql($sql, $params);
        $students = [];

        foreach ($records as $record) {
            // Get prediction details
            $prediction_data = json_decode($record->predictiondata, true);

            // Extract risk factors from prediction data
            $risk_factors = $this->extract_risk_factors($prediction_data, $risk_level);

            // Get user picture URL
            $user_picture = new \user_picture($record);
            $user_picture->size = 35; // Size in pixels
            $picture_url = $user_picture->get_url($PAGE);

            // Create student object
            $student = new \stdClass();
            $student->id = $record->userid;
            $student->fullname = fullname($record);
            $student->picture = $user_picture->get_url($PAGE)->out(false);
            $student->passprob = round($record->passprob * 100);
            $student->profileurl = new \moodle_url('/user/view.php', 
                                                 ['id' => $record->userid, 'course' => $record->courseid]);
            $student->risk_factors = $risk_factors;
            $student->prediction_id = $record->id;
            $student->courseid = $record->courseid;
            $student->coursename = $record->coursename;
            $student->courseshortname = $record->courseshortname;
            $student->generate_url = new \moodle_url('/blocks/studentperformancepredictor/generate_prediction.php', 
                                                   ['courseid' => $record->courseid, 
                                                    'userid' => $record->userid, 
                                                    'sesskey' => sesskey()]);

            $students[] = $student;
        }

        return $students;
    }

    /**
     * Extract risk factors from prediction data.
     * 
     * Same implementation as in the teacher_view class.
     *
     * @param array $prediction_data Prediction data from backend
     * @param int $risk_level Risk level (1=low, 2=medium, 3=high)
     * @return array Risk factors
     */
    protected function extract_risk_factors($prediction_data, $risk_level) {
        $factors = [];

        // Check if we have backend data
        if (empty($prediction_data) || empty($prediction_data['backend'])) {
            return [get_string('nofactorsavailable', 'block_studentperformancepredictor')];
        }

        $backend_data = $prediction_data['backend'];

        // Add activity level factor
        if (isset($backend_data['features']['activity_level'])) {
            $activity = (int)$backend_data['features']['activity_level'];
            if ($risk_level == 3 && $activity < 5) {
                $factors[] = get_string('factor_low_activity', 'block_studentperformancepredictor');
            } else if ($risk_level == 2 && $activity < 10) {
                $factors[] = get_string('factor_medium_activity', 'block_studentperformancepredictor');
            } else if ($risk_level == 1 && $activity >= 15) {
                $factors[] = get_string('factor_high_activity', 'block_studentperformancepredictor');
            }
        }

        // Add submission factor
        if (isset($backend_data['features']['submission_count'])) {
            $submissions = (int)$backend_data['features']['submission_count'];
            if ($risk_level == 3 && $submissions < 2) {
                $factors[] = get_string('factor_low_submissions', 'block_studentperformancepredictor');
            } else if ($risk_level == 2 && $submissions < 4) {
                $factors[] = get_string('factor_medium_submissions', 'block_studentperformancepredictor');
            } else if ($risk_level == 1 && $submissions >= 5) {
                $factors[] = get_string('factor_high_submissions', 'block_studentperformancepredictor');
            }
        }

        // Add grade factor
        if (isset($backend_data['features']['grade_average'])) {
            $grade = (float)$backend_data['features']['grade_average'] * 100;
            if ($risk_level == 3 && $grade < 50) {
                $factors[] = get_string('factor_low_grades', 'block_studentperformancepredictor');
            } else if ($risk_level == 2 && $grade < 70) {
                $factors[] = get_string('factor_medium_grades', 'block_studentperformancepredictor');
            } else if ($risk_level == 1 && $grade >= 75) {
                $factors[] = get_string('factor_high_grades', 'block_studentperformancepredictor');
            }
        }

        // Add login recency factor
        if (isset($backend_data['features']['days_since_last_access'])) {
            $days = (int)$backend_data['features']['days_since_last_access'];
            if ($risk_level == 3 && $days > 7) {
                $factors[] = get_string('factor_not_logged_in', 'block_studentperformancepredictor', $days);
            } else if ($risk_level == 2 && $days > 3) {
                $factors[] = get_string('factor_few_days_since_login', 'block_studentperformancepredictor', $days);
            } else if ($risk_level == 1 && $days <= 1) {
                $factors[] = get_string('factor_recent_login', 'block_studentperformancepredictor');
            }
        }

        // Add modules accessed factor
        if (isset($backend_data['features']['current_course_modules_accessed'])) {
            $modules = (int)$backend_data['features']['current_course_modules_accessed'];
            if ($risk_level == 3 && $modules < 3) {
                $factors[] = get_string('factor_few_modules_accessed', 'block_studentperformancepredictor');
            } else if ($risk_level == 2 && $modules < 6) {
                $factors[] = get_string('factor_some_modules_accessed', 'block_studentperformancepredictor');
            } else if ($risk_level == 1 && $modules >= 8) {
                $factors[] = get_string('factor_many_modules_accessed', 'block_studentperformancepredictor');
            }
        }

        // If no specific factors were identified, add a default message
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
// blocks/studentperformancepredictor/classes/output/renderer.php

namespace block_studentperformancepredictor\output;

defined('MOODLE_INTERNAL') || die();

use plugin_renderer_base;

/**
 * Renderer for Student Performance Predictor block.
 */
class renderer extends plugin_renderer_base {
    /**
     * Renders student_view.
     *
     * @param student_view $studentview The student view object
     * @return string HTML
     */
    public function render_student_view(student_view $studentview) {
        $data = $studentview->export_for_template($this);
        return $this->render_from_template('block_studentperformancepredictor/student_dashboard', $data);
    }

    /**
     * Renders teacher_view.
     *
     * @param teacher_view $teacherview The teacher view object
     * @return string HTML
     */
    public function render_teacher_view(teacher_view $teacherview) {
        $data = $teacherview->export_for_template($this);

        // Initialize teacher dashboard JavaScript
        $this->page->requires->js_call_amd('block_studentperformancepredictor/teacher_dashboard', 'init');

        return $this->render_from_template('block_studentperformancepredictor/teacher_dashboard', $data);
    }

    /**
     * Renders admin_view.
     *
     * @param admin_view $adminview The admin view object
     * @return string HTML
     */
    public function render_admin_view(admin_view $adminview) {
        $data = $adminview->export_for_template($this);

        // Initialize admin dashboard JavaScript
        $this->page->requires->js_call_amd('block_studentperformancepredictor/admin_dashboard', 'init');

        // Initialize chart renderer for the admin view
        $this->page->requires->js_call_amd('block_studentperformancepredictor/chart_renderer', 'initAdminChart');

        return $this->render_from_template('block_studentperformancepredictor/admin_dashboard', $data);
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

        // Check if there's an active model - use global namespace function
        $data->hasmodel = \block_studentperformancepredictor_has_active_model($this->courseid);

        if (!$data->hasmodel) {
            $data->nomodeltext = get_string('noactivemodel', 'block_studentperformancepredictor');
            return $data;
        }

        // Get student prediction - use global namespace function
        $prediction = \block_studentperformancepredictor_get_student_prediction($this->courseid, $this->userid);

        // Add ability to generate new prediction
        $data->can_generate_prediction = true;
        $data->generate_prediction_url = new \moodle_url('/blocks/studentperformancepredictor/generate_prediction.php', 
            ['courseid' => $this->courseid, 'userid' => $this->userid, 'sesskey' => sesskey()]);

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

        // Check if this is from a global model
        $model = $DB->get_record('block_spp_models', ['id' => $prediction->modelid]);
        $data->isglobalmodel = ($model && $model->courseid == 0);

        // Get suggestions - use global namespace function
        $suggestions = \block_studentperformancepredictor_get_suggestions($prediction->id);

        $data->hassuggestions = !empty($suggestions);
        $data->suggestions = [];

        foreach ($suggestions as $suggestion) {
            $suggestionData = new \stdClass();
            $suggestionData->id = $suggestion->id;
            $suggestionData->reason = $suggestion->reason;

            // Create URL to the activity
            if (!empty($suggestion->cmid)) {
                $suggestionData->hasurl = true;
                $modulename = !empty($suggestion->modulename) ? $suggestion->modulename : '';
                $cmname = !empty($suggestion->cmname) ? $suggestion->cmname : '';
                $suggestionData->url = new \moodle_url('/mod/' . $modulename . '/view.php', 
                                                    ['id' => $suggestion->cmid]);
                $suggestionData->name = $cmname;
            } else {
                $suggestionData->hasurl = false;
                $suggestionData->name = get_string('generalstudy', 'block_studentperformancepredictor');
            }

            $suggestionData->viewed = $suggestion->viewed;
            $suggestionData->completed = $suggestion->completed;

            $data->suggestions[] = $suggestionData;
        }

        // Create chart data
        $data->haschart = true;
        $chartData = [
            'passprob' => $data->passprob,
            'failprob' => 100 - $data->passprob
        ];
        $data->chartdata = json_encode($chartData);

        // Add performance improvement tracking
        $data->showimprovements = true;

        // Get historical predictions for this student in this course
        $sql = "SELECT p.*, m.algorithmtype 
                FROM {block_spp_predictions} p
                JOIN {block_spp_models} m ON p.modelid = m.id
                WHERE p.courseid = :courseid 
                AND p.userid = :userid
                ORDER BY p.timemodified DESC
                LIMIT 5";

        $historical = $DB->get_records_sql($sql, [
            'courseid' => $this->courseid,
            'userid' => $this->userid
        ]);

        if (count($historical) > 1) {
            $data->has_historical = true;
            $data->historical = [];

            foreach ($historical as $pred) {
                $item = new \stdClass();
                $item->date = userdate($pred->timemodified, get_string('strftimedateshort', 'langconfig'));
                $item->passprob = round($pred->passprob * 100);
                $item->risktext = \block_studentperformancepredictor_get_risk_text($pred->riskvalue);
                $item->riskclass = \block_studentperformancepredictor_get_risk_class($pred->riskvalue);
                $data->historical[] = $item;
            }
        } else {
            $data->has_historical = false;
        }

        return $data;
    }
}

<?php
// blocks/studentperformancepredictor/classes/output/teacher_view.php

namespace block_studentperformancepredictor\output;

defined('MOODLE_INTERNAL') || die();

// Add this line to include lib.php which contains the function definitions
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

/**
 * Teacher view class for the teacher dashboard.
 *
 * This class prepares data for the teacher dashboard template.
 */
class teacher_view implements \renderable, \templatable {
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
     * Export data for template.
     *
     * @param \renderer_base $output The renderer
     * @return \stdClass Data for template
     */
    public function export_for_template(\renderer_base $output) {
        global $CFG, $DB, $USER;

        $data = new \stdClass();
        $data->heading = get_string('courseperformance', 'block_studentperformancepredictor');
        $data->courseid = $this->courseid;

        // Get course info
        $course = get_course($this->courseid);
        $data->coursename = format_string($course->fullname);
        $data->courseshortname = format_string($course->shortname);

        // Get teacher's courses for selector
        $teachercourses = [];
        $courses = enrol_get_users_courses($USER->id);
        foreach ($courses as $c) {
            if ($c->id == SITEID) {
                continue;
            }
            $context = \context_course::instance($c->id);
            if (has_capability('block/studentperformancepredictor:viewallpredictions', $context)) {
                $teachercourses[] = [
                    'id' => $c->id,
                    'name' => format_string($c->fullname),
                    'shortname' => format_string($c->shortname),
                    'selected' => ($c->id == $this->courseid)
                ];
            }
        }

        $data->has_multiple_courses = count($teachercourses) > 1;
        $data->teachercourses = $teachercourses;

        // Check if there's an active model - use global namespace function
        $data->hasmodel = \block_studentperformancepredictor_has_active_model($this->courseid);

        if (!$data->hasmodel) {
            $data->nomodeltext = get_string('noactivemodel', 'block_studentperformancepredictor');
            return $data;
        }

        // Get risk statistics - use global namespace function
        $riskStats = \block_studentperformancepredictor_get_course_risk_stats($this->courseid);

        $data->totalstudents = $riskStats->total;
        $data->highrisk = $riskStats->highrisk;
        $data->mediumrisk = $riskStats->mediumrisk;
        $data->lowrisk = $riskStats->lowrisk;
        $data->missing_predictions = $riskStats->missing ?? 0;

        // Calculate percentages
        if ($data->totalstudents > 0) {
            $data->highriskpercent = round(($data->highrisk / $data->totalstudents) * 100);
            $data->mediumriskpercent = round(($data->mediumrisk / $data->totalstudents) * 100);
            $data->lowriskpercent = round(($data->lowrisk / $data->totalstudents) * 100);
        } else {
            $data->highriskpercent = 0;
            $data->mediumriskpercent = 0;
            $data->lowriskpercent = 0;
        }

        // Get the last refresh time
        $lastrefresh = get_config('block_studentperformancepredictor', 'lastrefresh_' . $this->courseid);
        $data->has_lastrefresh = !empty($lastrefresh);
        if ($data->has_lastrefresh) {
            $data->lastrefresh = userdate($lastrefresh);
            $data->lastrefresh_ago = format_time(time() - $lastrefresh);
        }

        // Get students with predictions in this course, grouped by risk level
        $data->students_highrisk = $this->get_students_by_risk_level(3);
        $data->students_mediumrisk = $this->get_students_by_risk_level(2);
        $data->students_lowrisk = $this->get_students_by_risk_level(1);

        // Get students with no predictions
        $data->students_missing = $this->get_students_without_predictions();

        // Check if we have students in each category
        $data->has_highrisk_students = !empty($data->students_highrisk);
        $data->has_mediumrisk_students = !empty($data->students_mediumrisk);
        $data->has_lowrisk_students = !empty($data->students_lowrisk);
        $data->has_missing_predictions = !empty($data->students_missing);

        // Create URL to detailed report
        $data->detailreporturl = new \moodle_url('/blocks/studentperformancepredictor/reports.php', 
                                              ['courseid' => $this->courseid]);

        // Create URL for refreshing predictions
        $data->refreshpredictionsurl = new \moodle_url('/blocks/studentperformancepredictor/admin/refreshpredictions.php', 
                                              ['courseid' => $this->courseid]);

        // Create chart data
        $data->haschart = true;
        $chartData = [
            'labels' => [
                get_string('highrisk_label', 'block_studentperformancepredictor'),
                get_string('mediumrisk_label', 'block_studentperformancepredictor'),
                get_string('lowrisk_label', 'block_studentperformancepredictor')
            ],
            'data' => [$data->highrisk, $data->mediumrisk, $data->lowrisk]
        ];
        $data->chartdata = json_encode($chartData);

        return $data;
    }

    /**
     * Get students by risk level with prediction details.
     *
     * @param int $risk_level Risk level (1=low, 2=medium, 3=high)
     * @return array Students with this risk level
     */
    protected function get_students_by_risk_level($risk_level) {
        global $DB, $PAGE;

        $sql = "SELECT p.*, u.id as userid, u.firstname, u.lastname, u.email, u.picture, u.imagealt, 
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                       m.courseid as modelcourseid, m.id as modelid
                FROM {block_spp_predictions} p
                JOIN {user} u ON p.userid = u.id
                JOIN {block_spp_models} m ON p.modelid = m.id
                WHERE p.courseid = :courseid 
                AND p.riskvalue = :risklevel
                AND m.active = 1
                ORDER BY u.lastname, u.firstname";

        $params = [
            'courseid' => $this->courseid,
            'risklevel' => $risk_level
        ];

        $records = $DB->get_records_sql($sql, $params);
        $students = [];

        foreach ($records as $record) {
            // Get prediction details
            $prediction_data = json_decode($record->predictiondata, true);

            // Extract risk factors from prediction data
            $risk_factors = $this->extract_risk_factors($prediction_data, $risk_level);

            // Get user picture URL
            $user_picture = new \user_picture($record);
            $user_picture->size = 35; // Size in pixels

            // Create student object
            $student = new \stdClass();
            $student->id = $record->userid;
            $student->fullname = fullname($record);
            $student->picture = $user_picture->get_url($PAGE)->out(false);
            $student->passprob = round($record->passprob * 100);
            $student->profileurl = new \moodle_url('/user/view.php', 
                                                 ['id' => $record->userid, 'course' => $this->courseid]);
            $student->risk_factors = $risk_factors;
            $student->prediction_id = $record->id;
            $student->generate_url = new \moodle_url('/blocks/studentperformancepredictor/generate_prediction.php', 
                                                   ['courseid' => $this->courseid, 
                                                    'userid' => $record->userid, 
                                                    'sesskey' => sesskey()]);

            // Flag if this is from a global model
            $student->isglobalmodel = ($record->modelcourseid == 0);

            // Get incomplete activities
            $student->incomplete_activities = $this->get_student_incomplete_activities($record->userid);
            $student->has_incomplete_activities = !empty($student->incomplete_activities);

            // Get grade information
            $student->grade = $this->get_student_course_grade($record->userid);

            // Get last access information
            $student->lastaccess = $this->get_student_last_access($record->userid);

            $students[] = $student;
        }

        return $students;
    }

    /**
     * Get students without predictions
     * 
     * @return array Students without predictions
     */
    protected function get_students_without_predictions() {
        global $DB, $PAGE;

        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.picture, u.imagealt, 
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
                FROM {user} u
                JOIN {user_enrolments} ue ON ue.userid = u.id
                JOIN {enrol} e ON e.id = ue.enrolid
                LEFT JOIN {block_spp_predictions} p ON p.userid = u.id AND p.courseid = :courseid
                WHERE e.courseid = :courseid2
                AND p.id IS NULL
                ORDER BY u.lastname, u.firstname";

        $params = [
            'courseid' => $this->courseid,
            'courseid2' => $this->courseid
        ];

        $records = $DB->get_records_sql($sql, $params);
        $students = [];

        foreach ($records as $record) {
            // Get user picture URL
            $user_picture = new \user_picture($record);
            $user_picture->size = 35; // Size in pixels

            // Create student object
            $student = new \stdClass();
            $student->id = $record->id;
            $student->fullname = fullname($record);
            $student->picture = $user_picture->get_url($PAGE)->out(false);
            $student->profileurl = new \moodle_url('/user/view.php', 
                                                 ['id' => $record->id, 'course' => $this->courseid]);
            $student->generate_url = new \moodle_url('/blocks/studentperformancepredictor/generate_prediction.php', 
                                                   ['courseid' => $this->courseid, 
                                                    'userid' => $record->id, 
                                                    'sesskey' => sesskey()]);

            // Get incomplete activities
            $student->incomplete_activities = $this->get_student_incomplete_activities($record->id);
            $student->has_incomplete_activities = !empty($student->incomplete_activities);

            // Get grade information
            $student->grade = $this->get_student_course_grade($record->id);

            // Get last access information
            $student->lastaccess = $this->get_student_last_access($record->id);

            $students[] = $student;
        }

        return $students;
    }

    /**
     * Get student incomplete activities
     * 
     * @param int $userid User ID
     * @return array Incomplete activities
     */
    protected function get_student_incomplete_activities($userid) {
        global $DB;

        $course = get_course($this->courseid);
        $modinfo = get_fast_modinfo($course, $userid);
        $completion = new \completion_info($course);
        $activities = [];

        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible || $cm->modname == 'label') {
                continue;
            }

            if ($completion->is_enabled($cm)) {
                $data = $completion->get_data($cm, false, $userid);
                if ($data->completionstate != COMPLETION_COMPLETE && $data->completionstate != COMPLETION_COMPLETE_PASS) {
                    $activities[] = [
                        'name' => $cm->name,
                        'url' => $cm->url->out(false),
                        'modname' => $cm->modname,
                        'icon' => $cm->get_icon_url()->out(false)
                    ];
                }
            }
        }

        return $activities;
    }

    /**
     * Get student course grade
     * 
     * @param int $userid User ID
     * @return object Grade information
     */
    protected function get_student_course_grade($userid) {
        global $DB;

        $result = new \stdClass();
        $result->grade = null;
        $result->grademax = 100;
        $result->percentage = 0;
        $result->hasgrades = false;

        // Get course grade
        $grade = grade_get_course_grade($userid, $this->courseid);
        if ($grade && isset($grade->grade) && $grade->grade !== null) {
            $result->grade = $grade->grade;
            $result->grademax = $grade->grade_item->grademax;
            $result->percentage = ($grade->grade / $grade->grade_item->grademax) * 100;
            $result->hasgrades = true;

            // Determine grade class based on percentage
            if ($result->percentage >= 70) {
                $result->gradeclass = 'text-success';
            } else if ($result->percentage >= 50) {
                $result->gradeclass = 'text-warning';
            } else {
                $result->gradeclass = 'text-danger';
            }
        }

        return $result;
    }

    /**
     * Get student last access
     * 
     * @param int $userid User ID
     * @return object Last access information
     */
    protected function get_student_last_access($userid) {
        global $DB;

        $result = new \stdClass();
        $result->timestamp = 0;
        $result->timeago = '';
        $result->hasaccess = false;

        // Get last course access
        $lastaccess = $DB->get_record('user_lastaccess', [
            'userid' => $userid,
            'courseid' => $this->courseid
        ]);

        if ($lastaccess) {
            $result->timestamp = $lastaccess->timeaccess;
            $result->timeago = format_time(time() - $lastaccess->timeaccess);
            $result->hasaccess = true;

            // Determine access class based on time
            $dayspassed = (time() - $lastaccess->timeaccess) / (60 * 60 * 24);
            if ($dayspassed <= 3) {
                $result->accessclass = 'text-success';
            } else if ($dayspassed <= 7) {
                $result->accessclass = 'text-warning';
            } else {
                $result->accessclass = 'text-danger';
            }
        }

        return $result;
    }

    /**
     * Extract risk factors from prediction data.
     *
     * @param array $prediction_data Prediction data from backend
     * @param int $risk_level Risk level (1=low, 2=medium, 3=high)
     * @return array Risk factors
     */
    protected function extract_risk_factors($prediction_data, $risk_level) {
        $factors = [];

        // Check if we have backend data
        if (empty($prediction_data) || empty($prediction_data['backend'])) {
            return [get_string('nofactorsavailable', 'block_studentperformancepredictor')];
        }

        $backend_data = $prediction_data['backend'];

        // Add activity level factor
        if (isset($backend_data['features']['activity_level'])) {
            $activity = (int)$backend_data['features']['activity_level'];
            if ($risk_level == 3 && $activity < 5) {
                $factors[] = get_string('factor_low_activity', 'block_studentperformancepredictor');
            } else if ($risk_level == 2 && $activity < 10) {
                $factors[] = get_string('factor_medium_activity', 'block_studentperformancepredictor');
            } else if ($risk_level == 1 && $activity >= 15) {
                $factors[] = get_string('factor_high_activity', 'block_studentperformancepredictor');
            }
        }

        // Add submission factor
        if (isset($backend_data['features']['submission_count'])) {
            $submissions = (int)$backend_data['features']['submission_count'];
            if ($risk_level == 3 && $submissions < 2) {
                $factors[] = get_string('factor_low_submissions', 'block_studentperformancepredictor');
            } else if ($risk_level == 2 && $submissions < 4) {
                $factors[] = get_string('factor_medium_submissions', 'block_studentperformancepredictor');
            } else if ($risk_level == 1 && $submissions >= 5) {
                $factors[] = get_string('factor_high_submissions', 'block_studentperformancepredictor');
            }
        }

        // Add grade factor
        if (isset($backend_data['features']['grade_average'])) {
            $grade = (float)$backend_data['features']['grade_average'] * 100;
            if ($risk_level == 3 && $grade < 50) {
                $factors[] = get_string('factor_low_grades', 'block_studentperformancepredictor');
            } else if ($risk_level == 2 && $grade < 70) {
                $factors[] = get_string('factor_medium_grades', 'block_studentperformancepredictor');
            } else if ($risk_level == 1 && $grade >= 75) {
                $factors[] = get_string('factor_high_grades', 'block_studentperformancepredictor');
            }
        }

        // Add login recency factor
        if (isset($backend_data['features']['days_since_last_access'])) {
            $days = (int)$backend_data['features']['days_since_last_access'];
            if ($risk_level == 3 && $days > 7) {
                $factors[] = get_string('factor_not_logged_in', 'block_studentperformancepredictor', $days);
            } else if ($risk_level == 2 && $days > 3) {
                $factors[] = get_string('factor_few_days_since_login', 'block_studentperformancepredictor', $days);
            } else if ($risk_level == 1 && $days <= 1) {
                $factors[] = get_string('factor_recent_login', 'block_studentperformancepredictor');
            }
        }

        // Add modules accessed factor
        if (isset($backend_data['features']['current_course_modules_accessed'])) {
            $modules = (int)$backend_data['features']['current_course_modules_accessed'];
            if ($risk_level == 3 && $modules < 3) {
                $factors[] = get_string('factor_few_modules_accessed', 'block_studentperformancepredictor');
            } else if ($risk_level == 2 && $modules < 6) {
                $factors[] = get_string('factor_some_modules_accessed', 'block_studentperformancepredictor');
            } else if ($risk_level == 1 && $modules >= 8) {
                $factors[] = get_string('factor_many_modules_accessed', 'block_studentperformancepredictor');
            }
        }

        // If no specific factors were identified, add a default message
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

A simple API that trains models and makes predictions.
"""

import os
import uuid
import time
import logging
import traceback
import tempfile
import shutil
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

# ML imports
from sklearn.preprocessing import StandardScaler, OneHotEncoder
from sklearn.compose import ColumnTransformer
from sklearn.pipeline import Pipeline
from sklearn.model_selection import train_test_split
from sklearn.metrics import accuracy_score, precision_score, recall_score, f1_score, roc_auc_score
from sklearn.ensemble import RandomForestClassifier, GradientBoostingClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.svm import SVC
from sklearn.tree import DecisionTreeClassifier
from sklearn.neighbors import KNeighborsClassifier
from sklearn.impute import SimpleImputer

# Load environment variables
load_dotenv()

# Configure logging
logging.basicConfig(
    level=logging.INFO if os.getenv("DEBUG", "false").lower() == "true" else logging.WARNING,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Set up FastAPI app - simplified for API only
app = FastAPI(
    title="Student Performance Predictor API",
    description="Machine Learning API for Student Performance Prediction",
    version="1.0.0"
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

# Pydantic models for requests and responses
class TrainRequest(BaseModel):
    courseid: int
    algorithm: str = "randomforest"  # Default to RandomForest
    target_column: str = "final_outcome"
    id_columns: List[str] = []
    test_size: float = 0.2

    class Config:
        # Allow arbitrary types for field values
        arbitrary_types_allowed = True

class TrainResponse(BaseModel):
    model_id: str
    algorithm: str
    metrics: Dict[str, Optional[float]]  # Allow None for metrics like roc_auc
    feature_names: List[str]
    target_classes: List[Any]
    trained_at: str
    training_time_seconds: float
    model_path: Optional[str] = None

class PredictRequest(BaseModel):
    model_id: str
    features: Dict[str, Any]

class PredictResponse(BaseModel):
    prediction: Any
    probability: float
    probabilities: List[float]
    model_id: str
    prediction_time: str

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
    """
    Handle any unhandled exceptions and return a friendly error response.
    """
    error_id = str(uuid.uuid4())

    # Log the exception with a traceback
    logger.error(f"Error ID: {error_id} - Unhandled exception: {str(exc)}")
    logger.error(traceback.format_exc())

    # Return a friendly JSON response
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
    """
    Health check endpoint for monitoring.
    """
    try:
        # Check models directory exists
        if not os.path.exists(MODELS_DIR):
            os.makedirs(MODELS_DIR, exist_ok=True)

        # Check if we can write to the models directory
        test_file = os.path.join(MODELS_DIR, "healthcheck.txt")
        with open(test_file, "w") as f:
            f.write("Health check")
        os.remove(test_file)

        return {
            "status": "healthy",
            "time": datetime.now().isoformat(),
            "version": "1.0.0",
            "models_dir": MODELS_DIR,
            "models_count": len([f for f in os.listdir(MODELS_DIR) if f.endswith('.joblib')]),
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

@app.post("/train", response_model=TrainResponse, dependencies=[Depends(verify_api_key)])
async def train_model(
    courseid: int = Form(...),
    algorithm: str = Form("randomforest"),
    target_column: str = Form("final_outcome", description="Name of the target column"),
    test_size: float = Form(0.2, description="Test split proportion"),
    id_columns: str = Form("", description="Comma-separated list of ID columns to ignore"),
    dataset_file: UploadFile = File(...)
):
    """
    Train a machine learning model with the uploaded dataset.
    """
    start_time = time.time()
    logger.info(f"Training request received for course {courseid} using {algorithm}")

    try:
        # Parse id_columns from comma-separated string
        id_columns_list = [col.strip() for col in id_columns.split(',')] if id_columns else []

        # Create a temporary file to store the uploaded dataset
        with tempfile.NamedTemporaryFile(delete=False, suffix=os.path.splitext(dataset_file.filename)[1]) as temp_file:
            # Copy the uploaded file to the temporary file
            shutil.copyfileobj(dataset_file.file, temp_file)
            temp_filepath = temp_file.name

        logger.info(f"Uploaded dataset saved to temporary file: {temp_filepath}")

        # Create a request object with the form data
        request = TrainRequest(
            courseid=courseid,
            algorithm=algorithm,
            target_column=target_column,
            id_columns=id_columns_list,
            test_size=test_size
        )

        # Load data
        file_extension = os.path.splitext(dataset_file.filename)[1].lower()
        try:
            if file_extension == '.csv':
                df = pd.read_csv(temp_filepath)
            elif file_extension == '.json':
                df = pd.read_json(temp_filepath)
            elif file_extension in ['.xlsx', '.xls']:
                df = pd.read_excel(temp_filepath)
            else:
                raise HTTPException(
                    status_code=status.HTTP_400_BAD_REQUEST,
                    detail=f"Unsupported file format: {file_extension}"
                )

            logger.info(f"Successfully loaded dataset with {len(df)} rows and {len(df.columns)} columns")
        except Exception as e:
            logger.error(f"Error loading dataset: {str(e)}")
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=f"Error loading dataset: {str(e)}"
            )

        # Remove the temporary file after loading
        os.unlink(temp_filepath)

        # Check for dataset size and potential issues
        if len(df) < 30:
            logger.warning(f"Very small dataset with only {len(df)} samples. Model may not be reliable.")

        # Use a default target column if not found
        if request.target_column not in df.columns:
            # Look for common target column names
            possible_targets = ['final_outcome', 'pass', 'outcome', 'grade', 'result', 'status']
            for col in possible_targets:
                if col in df.columns:
                    logger.info(f"Using '{col}' as target column instead of '{request.target_column}'")
                    request.target_column = col
                    break
            else:
                # Use the last column as target if none found
                request.target_column = df.columns[-1]
                logger.warning(f"Target column not found, using last column '{request.target_column}' as target")

        # Extract target and features
        y = df[request.target_column]
        X = df.drop(columns=[request.target_column] + request.id_columns)
        feature_names = X.columns.tolist()

        logger.info(f"Features: {feature_names}")
        logger.info(f"Target distribution: {y.value_counts().to_dict()}")

        # Check for class imbalance
        class_counts = y.value_counts().to_dict()
        if len(class_counts) > 1:
            majority_class_count = max(class_counts.values())
            minority_class_count = min(class_counts.values())
            imbalance_ratio = majority_class_count / minority_class_count
            if imbalance_ratio > 3:
                logger.warning(f"Significant class imbalance detected: ratio {imbalance_ratio:.2f}. "
                              f"Applying class weight balancing.")
                class_weight = 'balanced'
            else:
                class_weight = None
        else:
            logger.warning("Only one class found in target. Model will not be useful for prediction.")
            class_weight = None

        # Identify numeric and categorical columns
        numeric_cols = X.select_dtypes(include=['int64', 'float64']).columns.tolist()
        categorical_cols = X.select_dtypes(include=['object', 'category', 'bool']).columns.tolist()

        # Create preprocessing pipeline with robust handling
        try:
            # For newer scikit-learn versions (>=1.2)
            encoder = OneHotEncoder(drop='first', sparse_output=False, handle_unknown='ignore')
        except TypeError:
            # For older scikit-learn versions
            encoder = OneHotEncoder(drop='first', sparse=False, handle_unknown='ignore')

        preprocessor = ColumnTransformer(
            transformers=[
                ('num', Pipeline([
                    ('imputer', SimpleImputer(strategy='median')),  # Use median for robustness to outliers
                    ('scaler', StandardScaler())
                ]), numeric_cols),
                ('cat', Pipeline([
                    ('imputer', SimpleImputer(strategy='most_frequent')),
                    ('encoder', encoder)
                ]), categorical_cols)
            ],
            remainder='drop'
        )

        # Split data with stratification for better class balance
        try:
            X_train, X_test, y_train, y_test = train_test_split(
                X, y, test_size=request.test_size, random_state=42, stratify=y
            )
        except ValueError:
            # If stratification fails (e.g., with only one class), fall back to standard split
            logger.warning("Stratified split failed, falling back to random split")
            X_train, X_test, y_train, y_test = train_test_split(
                X, y, test_size=request.test_size, random_state=42
            )

        # Select algorithm with proper regularization to prevent overfitting
        if request.algorithm == 'randomforest':
            # Use fewer trees and limit depth to prevent overfitting
            model = RandomForestClassifier(
                n_estimators=100, 
                max_depth=10,  # Limit depth
                min_samples_split=5,  # Require more samples to split
                min_samples_leaf=2,   # Require more samples in leaves
                class_weight=class_weight,
                random_state=42
            )
        elif request.algorithm == 'logisticregression':
            # Add L2 regularization
            model = LogisticRegression(
                C=1.0,  # Inverse of regularization strength
                class_weight=class_weight,
                max_iter=1000, 
                random_state=42
            )
        elif request.algorithm == 'gradientboosting':
            model = GradientBoostingClassifier(
                n_estimators=100,
                learning_rate=0.1,
                max_depth=3,  # Shallow trees to prevent overfitting
                subsample=0.8,  # Use 80% of samples per tree
                random_state=42
            )
        elif request.algorithm == 'svm':
            model = SVC(
                C=1.0,
                kernel='rbf',
                class_weight=class_weight,
                probability=True, 
                random_state=42
            )
        elif request.algorithm == 'decisiontree':
            model = DecisionTreeClassifier(
                max_depth=5,  # Limit depth
                min_samples_split=5,
                min_samples_leaf=2,
                class_weight=class_weight,
                random_state=42
            )
        elif request.algorithm == 'knn':
            model = KNeighborsClassifier(
                n_neighbors=5,
                weights='distance'  # Weight by distance for better performance
            )
        else:
            logger.warning(f"Unsupported algorithm '{request.algorithm}', falling back to RandomForest")
            model = RandomForestClassifier(
                n_estimators=100,
                max_depth=10,
                min_samples_split=5,
                min_samples_leaf=2,
                class_weight=class_weight,
                random_state=42
            )
            request.algorithm = 'randomforest'

        # Create pipeline
        pipeline = Pipeline([
            ('preprocessor', preprocessor),
            ('classifier', model)
        ])

        # Implement cross-validation for more reliable metrics
        from sklearn.model_selection import cross_val_score
        logger.info(f"Performing 5-fold cross-validation")
        cv_scores = cross_val_score(pipeline, X, y, cv=5, scoring='accuracy')
        cv_accuracy = np.mean(cv_scores)
        cv_std = np.std(cv_scores)
        logger.info(f"Cross-validation accuracy: {cv_accuracy:.4f} ± {cv_std:.4f}")

        # Train the model on the full training set
        logger.info(f"Training {request.algorithm} model")
        pipeline.fit(X_train, y_train)
        logger.info("Model training completed")

        # Evaluate
        y_pred = pipeline.predict(X_test)
        y_pred_proba = pipeline.predict_proba(X_test)

        # Calculate comprehensive metrics
        metrics = {
            "accuracy": float(accuracy_score(y_test, y_pred)),
            "cv_accuracy": float(cv_accuracy),
            "cv_std": float(cv_std),
            "precision": float(precision_score(y_test, y_pred, average='weighted', zero_division=0)),
            "recall": float(recall_score(y_test, y_pred, average='weighted', zero_division=0)),
            "f1": float(f1_score(y_test, y_pred, average='weighted', zero_division=0))
        }

        # Add ROC AUC if it's a binary classification
        if len(np.unique(y)) == 2:
            try:
                metrics["roc_auc"] = float(roc_auc_score(y_test, y_pred_proba[:, 1]))
            except (ValueError, IndexError) as e:
                logger.warning(f"ROC AUC not defined: {str(e)}")
                metrics["roc_auc"] = None

        # Check for severe overfitting
        train_acc = accuracy_score(y_train, pipeline.predict(X_train))
        test_acc = metrics["accuracy"]
        overfitting_ratio = train_acc / max(test_acc, 0.001)  # Avoid division by zero

        if overfitting_ratio > 1.2:
            logger.warning(f"Model may be overfitting: train accuracy={train_acc:.4f}, test accuracy={test_acc:.4f}")
            metrics["overfitting_warning"] = True
            metrics["overfitting_ratio"] = float(overfitting_ratio)
        else:
            metrics["overfitting_warning"] = False
            metrics["overfitting_ratio"] = float(overfitting_ratio)

        # Add feature importances if available
        if hasattr(pipeline.named_steps['classifier'], 'feature_importances_'):
            # Get feature names after preprocessing (if possible)
            feature_importances = pipeline.named_steps['classifier'].feature_importances_

            # For simplicity, we'll just use the top features
            if len(feature_importances) == len(feature_names):
                # Create a dictionary of feature importance
                importance_dict = dict(zip(feature_names, feature_importances))
                # Sort by importance
                sorted_features = sorted(importance_dict.items(), key=lambda x: x[1], reverse=True)
                # Get top 10 features
                top_features = sorted_features[:10]
                metrics["top_features"] = {str(k): float(v) for k, v in top_features}

        # Convert all metric values to float or None
        metrics = {k: (float(v) if v is not None and not isinstance(v, bool) and not isinstance(v, dict) else v) 
                  for k, v in metrics.items()}

        logger.info(f"Model metrics: {metrics}")

        # Generate model ID
        model_id = str(uuid.uuid4())

        # Create course directory
        course_models_dir = os.path.join(MODELS_DIR, f"course_{request.courseid}")
        os.makedirs(course_models_dir, exist_ok=True)

        # Save model
        model_filename = f"{model_id}.joblib"
        model_path = os.path.join(course_models_dir, model_filename)

        model_data = {
            'pipeline': pipeline,
            'feature_names': feature_names,
            'algorithm': request.algorithm,
            'trained_at': datetime.now().isoformat(),
            'target_classes': list(pipeline.classes_),
            'metrics': metrics,
            'cv_scores': cv_scores.tolist()
        }

        joblib.dump(model_data, model_path)
        MODEL_CACHE[model_id] = model_data

        logger.info(f"Model saved to {model_path}")

        training_time = time.time() - start_time
        logger.info(f"Training completed in {training_time:.2f} seconds")

        return {
            "model_id": model_id,
            "algorithm": request.algorithm,
            "metrics": metrics,
            "feature_names": [str(f) for f in feature_names],
            "target_classes": [int(c) if isinstance(c, (np.integer, np.int64, np.int32)) else c for c in pipeline.classes_],
            "trained_at": datetime.now().isoformat(),
            "training_time_seconds": training_time,
            "model_path": model_path  # Added model path for the Moodle plugin
        }

    except HTTPException:
        raise
    except Exception as e:
        logger.exception(f"Error training model: {str(e)}")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error training model: {str(e)}"
        )

@app.post("/predict", dependencies=[Depends(verify_api_key)])
async def predict(request: dict):
    """
    Make a prediction using a trained model.
    Support both single and batch predictions.
    """
    try:
        model_id = request.get("model_id")
        features = request.get("features")

        if not model_id:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail="model_id is required"
            )

        if not features:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail="features are required"
            )

        # Check if features is a list of lists/dicts (batch) or just a single list/dict
        is_batch = isinstance(features, list) and len(features) > 0 and isinstance(features[0], (list, dict))

        # Log the request for debugging
        logger.info(f"Prediction request for model {model_id}")
        logger.info(f"Feature data type: {'batch' if is_batch else 'single'}")

        # Load model
        model_data = None
        if model_id in MODEL_CACHE:
            model_data = MODEL_CACHE[model_id]
            logger.info(f"Using cached model {model_id}")
        else:
            # Search for model file
            found = False
            for root, dirs, files in os.walk(MODELS_DIR):
                for file in files:
                    if file == f"{model_id}.joblib":
                        model_path = os.path.join(root, file)
                        logger.info(f"Loading model from {model_path}")
                        model_data = joblib.load(model_path)
                        MODEL_CACHE[model_id] = model_data
                        found = True
                        break
                if found:
                    break

            if not found:
                logger.error(f"Model with ID {model_id} not found")
                raise HTTPException(
                    status_code=status.HTTP_404_NOT_FOUND,
                    detail=f"Model with ID {model_id} not found"
                )

        # Get pipeline and feature names
        pipeline = model_data['pipeline']
        feature_names = model_data['feature_names']
        logger.info(f"Model feature names: {feature_names}")

        # Create DataFrame from input
        try:
            if is_batch:
                logger.info(f"Processing batch prediction with {len(features)} samples")
                input_df = pd.DataFrame(features)
            else:
                logger.info("Processing single prediction")
                input_df = pd.DataFrame([features])

            # Add missing features with default values
            for feat in feature_names:
                if feat not in input_df.columns:
                    logger.info(f"Adding missing feature {feat} with default value 0")
                    input_df[feat] = 0

            # Ensure correct column order and only use known features
            valid_features = [f for f in feature_names if f in input_df.columns]
            input_df = input_df[valid_features]
            logger.info(f"Input data shape: {input_df.shape}")

        except Exception as e:
            logger.error(f"Error preparing input data: {str(e)}")
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=f"Invalid feature format: {str(e)}"
            )

        # Make prediction
        logger.info("Making prediction")
        try:
            predictions = pipeline.predict(input_df).tolist()
            probabilities = pipeline.predict_proba(input_df).tolist()
            logger.info(f"Prediction successful: {predictions}")
            logger.info(f"Probabilities: {probabilities}")
        except Exception as e:
            logger.error(f"Error during prediction: {str(e)}")
            raise HTTPException(
                status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
                detail=f"Error during prediction: {str(e)}"
            )

        # Format response based on batch or single prediction
        if is_batch:
            # Get probabilities for the positive class (usually class 1)
            # This works for binary classification
            if len(pipeline.classes_) == 2:
                positive_class_idx = 1 if 1 in pipeline.classes_ else 0
                positive_probs = [probs[positive_class_idx] for probs in probabilities]
            else:
                # For multiclass, return probability of predicted class
                positive_probs = [max(probs) for probs in probabilities]

            return {
                "predictions": predictions,
                "probabilities": positive_probs,
                "model_id": model_id,
                "prediction_time": datetime.now().isoformat()
            }
        else:
            prediction = predictions[0]

            # Get probability of predicted class (for binary, get prob of class 1)
            if len(pipeline.classes_) == 2:
                # For binary classification, return prob of positive class (usually 1)
                positive_class_idx = 1 if 1 in pipeline.classes_ else 0
                probability = float(probabilities[0][positive_class_idx])
            else:
                # For multiclass, return probability of predicted class
                pred_idx = list(pipeline.classes_).index(prediction)
                probability = float(probabilities[0][pred_idx])

            return {
                "prediction": prediction,
                "probability": probability,
                "probabilities": probabilities[0],
                "model_id": model_id,
                "prediction_time": datetime.now().isoformat()
            }

    except HTTPException:
        raise
    except Exception as e:
        logger.exception(f"Unhandled error in prediction: {str(e)}")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error making prediction: {str(e)}"
        )

# Add a debug endpoint for file system checking
@app.get("/debug/filesystem", dependencies=[Depends(verify_api_key)])
async def debug_filesystem(path: str = None):
    """Debug endpoint to check filesystem access."""
    if not os.getenv("DEBUG", "false").lower() == "true":
        raise HTTPException(status_code=403, detail="Debug mode not enabled")

    result = {
        "current_dir": os.getcwd(),
        "models_dir": MODELS_DIR,
        "models_dir_exists": os.path.exists(MODELS_DIR),
        "models_dir_writable": os.access(MODELS_DIR, os.W_OK),
        "models_content": []
    }

    if os.path.exists(MODELS_DIR):
        result["models_content"] = os.listdir(MODELS_DIR)

    if path and os.path.exists(path):
        result["path_exists"] = True
        result["path_is_file"] = os.path.isfile(path)
        result["path_is_dir"] = os.path.isdir(path)
        result["path_size"] = os.path.getsize(path) if os.path.isfile(path) else None
        if os.path.isdir(path):
            result["path_content"] = os.listdir(path)
    else:
        result["path_exists"] = False

    return result

# For deployment
if __name__ == "__main__":
    import uvicorn

    port = int(os.getenv("PORT", 8000))
    debug = os.getenv("DEBUG", "false").lower() == "true"

    print(f"Starting Student Performance Prediction API on port {port}, debug={debug}")

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

# Core web framework
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