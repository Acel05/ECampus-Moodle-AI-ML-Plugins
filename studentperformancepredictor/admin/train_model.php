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
 * Train model handler for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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

    // Set up page title for global model
    $PAGE->set_title(get_string('trainglobalmodel', 'block_studentperformancepredictor'));
    $PAGE->set_heading(get_string('trainglobalmodel', 'block_studentperformancepredictor'));
    $PAGE->set_pagelayout('admin');

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

    // Set up page title
    $PAGE->set_title($course->shortname . ': ' . get_string('training_model', 'block_studentperformancepredictor'));
    $PAGE->set_heading($course->fullname);
    $PAGE->set_context($coursecontext);

    // Verify dataset exists and belongs to the course
    if (!$DB->record_exists('block_spp_datasets', array('id' => $datasetid, 'courseid' => $courseid))) {
        \core\notification::add(
            get_string('dataset_not_found', 'block_studentperformancepredictor'),
            \core\notification::ERROR
        );
        redirect($url);
    }
}

// Check for pending training
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