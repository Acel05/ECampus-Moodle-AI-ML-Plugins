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
 * Test backend connection for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
