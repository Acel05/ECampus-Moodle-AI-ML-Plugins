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
 * Train global model for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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