<?php
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

class block_studentperformancepredictor extends block_base {
    /**
     * Initialize the block.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_studentperformancepredictor');
    }

    /**
     * Allow the block to be added multiple times to a single page
     * 
     * @return boolean
     */
    public function instance_allow_multiple() {
        return false;
    }

    /**
     * Set where the block should be allowed to be added.
     * 
     * @return array
     */
    public function applicable_formats() {
        return array(
            'site' => false,
            'my' => true,     // Allow on dashboard
            'course' => true, // Allow on course pages
            'course-view' => true
        );
    }

    /**
     * Render a course selector dropdown for dashboard view
     * 
     * @param array $courses List of course objects
     * @param int $currentcourse Currently selected course ID
     * @return string HTML for course selector
     */
    protected function render_course_selector($courses, $currentcourse = 0) {
        global $OUTPUT;

        $options = array();
        foreach ($courses as $course) {
            $options[$course->id] = format_string($course->fullname);
        }

        $url = new moodle_url('/my/');
        $select = new \single_select($url, 'spp_course', $options, $currentcourse, null);
        $select->set_label(get_string('courseselectorlabel', 'block_studentperformancepredictor'), 
            ['class' => 'accesshide']);
        $select->class = 'spp-course-selector';

        return $OUTPUT->render($select);
    }

    /**
     * Get the block content.
     * 
     * @return stdClass Block content
     */
    public function get_content() {
        global $USER, $COURSE, $OUTPUT, $PAGE, $DB, $CFG;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        // Get course context
        $coursecontext = null;
        $courseid = 0;
        $showcourseselector = false;
        $courseselector = '';

        // Determine if we're on a course page or dashboard
        if ($PAGE->context->contextlevel === CONTEXT_COURSE && $PAGE->course->id !== SITEID) {
            // We're on a course page
            $coursecontext = $PAGE->context;
            $courseid = $PAGE->course->id;
        } else if ($PAGE->context->contextlevel === CONTEXT_USER || $PAGE->context->contextlevel === CONTEXT_SYSTEM) {
            // We're on dashboard or site home, get enrolled courses for the user
            $courses = enrol_get_my_courses('id, shortname, fullname');

            if (count($courses) > 1) {
                // If user has multiple courses, show a selector
                $showcourseselector = true;
                $currentcourse = optional_param('spp_course', 0, PARAM_INT);

                $courseselector = $this->render_course_selector($courses, $currentcourse);

                // Use the selected course or the first one
                if ($currentcourse && isset($courses[$currentcourse])) {
                    $courseid = $currentcourse;
                } else {
                    $course = reset($courses);
                    $courseid = $course->id;
                }
            } else if (count($courses) == 1) {
                // User has only one course
                $course = reset($courses);
                $courseid = $course->id;
            } else {
                // No courses found
                $this->content->text = $OUTPUT->notification(get_string('nocoursesfound', 'block_studentperformancepredictor'), 'info');
                return $this->content;
            }

            // Get course context for the selected course
            if ($courseid) {
                $coursecontext = context_course::instance($courseid);
            }
        }

        // If no course context found, show message
        if (!$coursecontext || $courseid == SITEID) {
            $this->content->text = $OUTPUT->notification(get_string('nocoursecontext', 'block_studentperformancepredictor'), 'info');
            return $this->content;
        }

        try {
            // Check for database tables - safer implementation
            $tablesexist = true;
            try {
                if (is_siteadmin()) {
                    $dbman = $DB->get_manager();
                    $tablesexist = $dbman->table_exists('block_spp_models');
                }
            } catch (Exception $e) {
                $tablesexist = false;
                debugging('Error checking tables: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }

            if (is_siteadmin() && !$tablesexist) {
                $installurl = new moodle_url('/admin/index.php');
                $message = get_string('tablesnotinstalled', 'block_studentperformancepredictor', $installurl->out());
                $this->content->text = $OUTPUT->notification($message, 'error');
                return $this->content;
            }

            // Check permissions
            $canviewown = has_capability('block/studentperformancepredictor:view', $coursecontext);
            $canviewall = has_capability('block/studentperformancepredictor:viewallpredictions', $coursecontext);
            $canmanage = has_capability('block/studentperformancepredictor:managemodels', $coursecontext);

            // Test backend connection if debug is enabled (for admins only)
            if (is_siteadmin() && get_config('block_studentperformancepredictor', 'enabledebug')) {
                $apiurl = get_config('block_studentperformancepredictor', 'python_api_url');
                $apikey = get_config('block_studentperformancepredictor', 'python_api_key');

                if (empty($apiurl) || empty($apikey) || $apikey === 'changeme') {
                    $settingsurl = new moodle_url('/admin/settings.php', 
                        ['section' => 'blocksettingstudentperformancepredictor']);
                    $this->content->footer = html_writer::tag('div', 
                        html_writer::link($settingsurl, get_string('configurebackend', 'block_studentperformancepredictor'), 
                            ['class' => 'btn btn-warning btn-sm']),
                        ['class' => 'text-center mt-2']);
                }
            }

            // Load appropriate renderer based on user role
            if ($canmanage) {
                // Admin view
                $renderer = $PAGE->get_renderer('block_studentperformancepredictor');
                $adminview = new \block_studentperformancepredictor\output\admin_view($courseid);
                $this->content->text = $renderer->render_admin_view($adminview);

                // Initialize chart renderer
                $PAGE->requires->js_call_amd('block_studentperformancepredictor/chart_renderer', 'initAdminChart');
            } else if ($canviewall) {
                // Teacher view
                $renderer = $PAGE->get_renderer('block_studentperformancepredictor');
                $teacherview = new \block_studentperformancepredictor\output\teacher_view($courseid);
                $this->content->text = $renderer->render_teacher_view($teacherview);

                // Initialize chart renderer
                $PAGE->requires->js_call_amd('block_studentperformancepredictor/chart_renderer', 'initTeacherChart');
                $PAGE->requires->js_call_amd('block_studentperformancepredictor/prediction_viewer', 'init');
            } else if ($canviewown) {
                // Student view
                $renderer = $PAGE->get_renderer('block_studentperformancepredictor');
                $studentview = new \block_studentperformancepredictor\output\student_view(
                    $courseid, 
                    $USER->id, 
                    $showcourseselector, 
                    $courseselector
                );
                $this->content->text = $renderer->render_student_view($studentview);

                // Initialize chart renderer
                $PAGE->requires->js_call_amd('block_studentperformancepredictor/chart_renderer', 'init');
                $PAGE->requires->js_call_amd('block_studentperformancepredictor/prediction_viewer', 'init');
            } else {
                // No permission
                $this->content->text = $OUTPUT->notification(get_string('nopermission', 'error'), 'error');
            }
        } catch (Exception $e) {
            // Handle exceptions
            debugging('Error rendering Student Performance Predictor block: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $this->content->text = $OUTPUT->notification(get_string('errorrendingblock', 'block_studentperformancepredictor'), 'error');

            // Add more detailed error for admins
            if (is_siteadmin()) {
                $this->content->text .= $OUTPUT->notification($e->getMessage(), 'error');
            }
        }

        return $this->content;
    }

    /**
     * Specialization function to set up the block instance.
     */
    public function specialization() {
        if (isset($this->config->title)) {
            $this->title = $this->config->title;
        } else {
            $this->title = get_string('pluginname', 'block_studentperformancepredictor');
        }
    }

    /**
     * Get custom JavaScript for the block.
     *
     * @return array JavaScript files
     */
    public function get_required_javascript() {
        parent::get_required_javascript();

        // Add dashboard-specific JS here if needed
        $this->page->requires->js_call_amd('block_studentperformancepredictor/prediction_viewer', 'init');
    }

    /**
     * Define if block has config.
     *
     * @return bool
     */
    public function has_config() {
        return true;
    }

    /**
     * Define if block instance has config.
     *
     * @return bool
     */
    public function instance_allow_config() {
        return true;
    }
}
