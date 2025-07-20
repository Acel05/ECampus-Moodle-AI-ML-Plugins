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
* Manage training datasets page for Student Performance Predictor.
*
* @package    block_studentperformancepredictor
* @copyright  2023 Your Name <your.email@example.com>
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

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

// Output footer
echo $OUTPUT->footer();