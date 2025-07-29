<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Activate a trained model for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');
require_login();

global $DB, $OUTPUT, $USER;

// Clean up any stuck models first
block_studentperformancepredictor_cleanup_pending_models(0);

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
