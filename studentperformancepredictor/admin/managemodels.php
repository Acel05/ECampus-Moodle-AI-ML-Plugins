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
* Manage prediction models page for Student Performance Predictor.
*
* @package    block_studentperformancepredictor
* @copyright  2023 Your Name <your.email@example.com>
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

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
        $row[] = isset($model->accuracy) ? (is_numeric($model->accuracy) ? round($model->accuracy * 100, 2) . '%' : '-') : '-';
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

// Output footer
echo $OUTPUT->footer();