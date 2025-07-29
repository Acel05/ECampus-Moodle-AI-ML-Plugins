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
 * EIRA AI Academic Assistant Controller
 *
 * @package    block_chatbot
 * @copyright  2023 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once('../../../config.php');

// Security: Require login
require_login(null, true);

// Start session for conversation context
session_start();

// Load required classes and dependencies
if (file_exists($CFG->dirroot . '/blocks/chatbot/classes/academic_resources.php')) {
    require_once($CFG->dirroot . '/blocks/chatbot/classes/academic_resources.php');
}

if (file_exists($CFG->dirroot . '/blocks/chatbot/classes/analytics.php')) {
    require_once($CFG->dirroot . '/blocks/chatbot/classes/analytics.php');
}

// Set appropriate headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Read JSON input
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

// Get user input
$message = isset($data['message']) ? trim($data['message']) : '';
$command = isset($data['command']) ? trim($data['command']) : '';

// Initialize conversation context if not exists
if (!isset($_SESSION['eira_conversation_context'])) {
    $_SESSION['eira_conversation_context'] = [
        'last_topic' => '',
        'history' => [],
        'user_courses' => [],
        'user_interests' => []
    ];
}

// Process the request
try {
    // Main request handler logic
    if (!empty($message)) {
        // Categorize and log the query if analytics is available
        if (class_exists('\block_chatbot\analytics')) {
            $category = \block_chatbot\analytics::categorize_query($message);
            \block_chatbot\analytics::log_interaction($USER->id, $message, $category);
        }

        // Get query response
        $query_lower = strtolower($message);

        // Check for resource-specific queries (special handling)
        if (contains_any($query_lower, ['resources', 'support', 'help center', 'library', 'study materials']) && 
            class_exists('\block_chatbot\academic_resources')) {
            $response = [['text' => \block_chatbot\academic_resources::format_as_message('study')]];
        } else {
            // Process general academic query
            $response = process_academic_query($message);
        }

        // Return the response
        echo json_encode(['messages' => $response]);
    } 
    // Handle welcome command
    elseif (!empty($command) && $command === 'welcome_message') {
        $firstName = $USER->firstname;

        $welcomeMessages = [
            ['text' => "Hi, $firstName! I'm EIRA, your academic assistant. I'm here to help you with anything related to your studies."],
            ['text' => "I can assist you with:
• Course information and materials
• Assignment help and study tips
• Academic planning and schedules
• Learning strategies and resources

How can I support your academic journey today?"]
        ];

        echo json_encode(['messages' => $welcomeMessages]);
    } 
    // Default response
    else {
        echo json_encode(['messages' => [['text' => "Need info on your courses, help with assignments, or tips for studying? Just ask! How can I support you today?"]]]);
    }
} catch (Exception $e) {
    // Log the error
    error_log('EIRA AI Error: ' . $e->getMessage());
    echo json_encode(['messages' => [['text' => "Hmm, that didn\'t work. Please refresh the page and try again."]]]);
}

/**
 * Helper function to check if a string contains any of the given patterns
 * 
 * @param string $string The string to check
 * @param array $patterns Array of patterns to look for
 * @return bool True if any pattern is found
 */
function contains_any($string, $patterns) {
    foreach ($patterns as $pattern) {
        if (strpos($string, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Process academic queries and generate responses
 *
 * @param string $query The student's query
 * @return array Array of response messages
 */
function process_academic_query($query) {
    global $USER, $DB, $CFG;

    // Track conversation context
    $context = &$_SESSION['eira_conversation_context'];

    // Add this query to history (limit to last 2)
    $context['history'][] = $query;
    if (count($context['history']) > 2) {
        array_shift($context['history']);
    }

    // Convert to lowercase for easier matching
    $query_lower = strtolower($query);

    // Check if this is a follow-up question (no specific keywords but related to last topic)
    if ($context['last_topic'] && !contains_any($query_lower, ['course', 'assignment', 'study', 'exam', 'resource'])) {
        // This might be a follow-up to the previous topic
        switch ($context['last_topic']) {
            case 'courses':
                if (contains_any($query_lower, ['which one', 'tell me more', 'details', 'about that', 'specific'])) {
                    return get_specific_course_details($query);
                }
                break;

            case 'assignments':
                if (contains_any($query_lower, ['how to', 'help with', 'more info', 'details', 'specific'])) {
                    return get_specific_assignment_help($query);
                }
                break;

            case 'study_tips':
                if (contains_any($query_lower, ['more', 'elaborate', 'specific', 'details', 'example'])) {
                    return get_detailed_study_tips($query);
                }
                break;
        }
    }

    // Regular topic detection
    if (contains_any($query_lower, ['my courses', 'enrolled', 'what courses', 'which courses', 'class', 'subject'])) {
        $context['last_topic'] = 'courses';
        return get_courses_response();
    }

    // Check for assignment-related queries
    if (contains_any($query_lower, ['assignment', 'homework', 'task', 'project', 'deadline', 'due date', 'submission'])) {
        $context['last_topic'] = 'assignments';
        return get_assignments_response();
    }

    // Check for study tips and strategies
    if (contains_any($query_lower, ['study tips', 'how to study', 'study strategy', 'study better', 'improve grades', 'learning technique'])) {
        $context['last_topic'] = 'study_tips';
        return get_study_tips();
    }

    // Check for exam preparation
    if (contains_any($query_lower, ['exam', 'test', 'quiz', 'prepare', 'revision', 'study for'])) {
        $context['last_topic'] = 'exams';
        return get_exam_preparation();
    }

    // Check for time management
    if (contains_any($query_lower, ['time management', 'schedule', 'plan', 'organize', 'routine', 'productivity'])) {
        $context['last_topic'] = 'time_management';
        return get_time_management();
    }

    // Check for resource queries
    if (contains_any($query_lower, ['resource', 'material', 'book', 'article', 'reading', 'video', 'learn more'])) {
        $context['last_topic'] = 'resources';
        return get_learning_resources();
    }

    // Check for help with specific subjects
    if (contains_any($query_lower, ['math', 'science', 'history', 'english', 'language', 'programming', 'physics', 'chemistry', 'biology', 'economics'])) {
        $context['last_topic'] = 'subjects';
        return get_subject_help($query_lower);
    }

    // Check for career and future planning
    if (contains_any($query_lower, ['career', 'job', 'future', 'graduate', 'profession', 'industry', 'internship'])) {
        $context['last_topic'] = 'career';
        return get_career_guidance();
    }

    // Check for motivation and wellbeing
    if (contains_any($query_lower, ['motivation', 'stress', 'anxiety', 'overwhelmed', 'tired', 'mental health', 'wellbeing', 'balance'])) {
        $context['last_topic'] = 'wellbeing';
        return get_wellbeing_support();
    }

    // Check for technology and tools
    if (contains_any($query_lower, ['tool', 'software', 'app', 'technology', 'digital', 'online', 'platform'])) {
        $context['last_topic'] = 'technology';
        return get_technology_tools();
    }

    // Check for greetings
    if (contains_any($query_lower, ['hello', 'hi', 'hey', 'greetings', 'good morning', 'good afternoon', 'good evening'])) {
        $context['last_topic'] = 'greeting';
        return [['text' => "Hey {$USER->firstname}, great to see you! Need help with an assignment, study tips, or anything else for class?"]];
    }

    // Check for gratitude
    if (contains_any($query_lower, ['thank', 'thanks', 'appreciate', 'helpful'])) {
        $context['last_topic'] = 'gratitude';
        return [['text' => "You're very welcome! Let me know if there's anything else I can help you with."]];
    }

    // Check for bot identity
    if (contains_any($query_lower, ['who are you', 'what are you', 'your name', 'about you'])) {
        $context['last_topic'] = 'identity';
        return [['text' => "I'm EIRA, your AI study buddy. I can help you with your courses, share study tips, suggest resources, and answer academic questions. Just think of me as your go-to helper for school stuff!"]];
    }

    // If we get here, no specific topic was identified
    $context['last_topic'] = 'general';

    // Default response for unrecognized queries with suggestions
    return [
        ['text' => "I'm not quite sure I understand your question. Here are some topics I can help you with:"],
        ['text' => "• Information about your courses and assignments
• Study tips and exam preparation
• Time management and scheduling
• Subject-specific help and resources
• Academic planning and career guidance

Could you please rephrase your question or select one of these topics?"]
    ];
}

/**
 * Extract a course name from a query
 * 
 * @param string $query The user's query
 * @return string|null The extracted course name or null
 */
function extract_course_name($query) {
    // Common patterns for course mentions
    $patterns = [
        '/about\s+([A-Za-z0-9\s]+)(\s+course)?/i',
        '/more\s+on\s+([A-Za-z0-9\s]+)(\s+course)?/i',
        '/details\s+for\s+([A-Za-z0-9\s]+)(\s+course)?/i',
        '/([A-Za-z0-9\s]+)\s+course/i',
        '/course\s+([A-Za-z0-9\s]+)/i'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $query, $matches)) {
            return trim($matches[1]);
        }
    }

    return null;
}

/**
 * Get information about a specific course
 * 
 * @param string $query The user's query
 * @return array Response messages
 */
function get_specific_course_details($query) {
    global $USER, $DB, $CFG;

    // Get the course name from the query if possible
    $courseName = extract_course_name($query);

    if ($courseName) {
        // Try to find this specific course
        $courses = enrol_get_users_courses($USER->id, true);
        foreach ($courses as $course) {
            if (stripos($course->fullname, $courseName) !== false || 
                stripos($course->shortname, $courseName) !== false) {

                // Get course details
                $context = context_course::instance($course->id);
                $courseUrl = $CFG->wwwroot . '/course/view.php?id=' . $course->id;

                // Get upcoming assignments in this course
                $modinfo = get_fast_modinfo($course);
                $assignmentCount = 0;
                $assignmentText = '';

                foreach ($modinfo->get_instances_of('assign') as $cm) {
                    if ($cm->uservisible) {
                        $assignmentCount++;
                        $assignment = $DB->get_record('assign', ['id' => $cm->instance]);
                        if ($assignment) {
                            $duedate = $assignment->duedate ? userdate($assignment->duedate) : 'No due date set';
                            $assignmentText .= "• {$assignment->name} - Due: {$duedate}\n";
                        }
                    }
                }

                // Build response
                $response = "**{$course->fullname}**\n\n";

                if (!empty($course->summary)) {
                    $response .= "**Description**: " . strip_tags($course->summary) . "\n\n";
                }

                $response .= "**Course Link**: [Access course materials]({$courseUrl})\n\n";

                if ($assignmentCount > 0) {
                    $response .= "**Upcoming Assignments**:\n{$assignmentText}\n";
                } else {
                    $response .= "There are no upcoming assignments in this course at the moment.\n";
                }

                $response .= "\nWould you like information about another course, or help with anything else?";

                return [['text' => $response]];
            }
        }
    }

    // If we couldn't find a specific course or no course was mentioned
    return [['text' => "Got it! Which course would you like to know more about? Just let me know the course name or number."]];
}

/**
 * Get information about the user's courses
 * 
 * @return array Response messages
 */
function get_courses_response() {
    global $USER, $DB, $CFG;

    // Get user's enrolled courses
    $courses = enrol_get_users_courses($USER->id, true);

    if (empty($courses)) {
        return [['text' => "Looks like you're not enrolled in any courses at the moment. If that doesn't seem right, it's a good idea to check with your academic advisor or the registrar's office."]];
    }

    $coursesText = "Here are the courses you're currently enrolled in:\n\n";

    foreach ($courses as $course) {
        $url = $CFG->wwwroot . '/course/view.php?id=' . $course->id;
        $coursesText .= "• **{$course->fullname}**\n";
        $coursesText .= "  Access: [Click here to view course]({$url})\n\n";
    }

    return [
        ['text' => $coursesText],
        ['text' => "Would you like information about:
• Upcoming assignments in these courses
• Course materials and resources
• Your progress in these courses

Let me know how I can help you with your studies!"]
    ];
}

/**
 * Get information about assignments
 * 
 * @return array Response messages
 */
function get_assignments_response() {
    global $USER, $DB, $CFG;

    // Get user's enrolled courses
    $courses = enrol_get_users_courses($USER->id, true);

    if (empty($courses)) {
        return [['text' => "You don't appear to be enrolled in any courses, so I can't find assignment information. Please contact your academic advisor if you believe this is an error."]];
    }

    // Prepare a response based on Moodle data
    $assignmentsText = "Here are your upcoming assignments:\n\n";
    $hasAssignments = false;

    // For each course, try to find assignments
    foreach ($courses as $course) {
        try {
            $coursemodinfo = get_fast_modinfo($course);
            $cms = $coursemodinfo->get_cms();

            foreach ($cms as $cm) {
                if ($cm->modname === 'assign' && $cm->uservisible) {
                    $assignment = $DB->get_record('assign', ['id' => $cm->instance]);
                    if ($assignment) {
                        $hasAssignments = true;
                        $duedate = $assignment->duedate ? userdate($assignment->duedate) : 'No due date set';
                        $assignmentsText .= "• **{$assignment->name}** - {$course->shortname}\n";
                        $assignmentsText .= "  Due: {$duedate}\n";
                        $assignmentsText .= "  [View assignment details]({$CFG->wwwroot}/mod/assign/view.php?id={$cm->id})\n\n";
                    }
                }
            }
        } catch (Exception $e) {
            // Skip courses with errors and continue
            continue;
        }
    }

    if (!$hasAssignments) {
        $assignmentsText = "You don't have any upcoming assignments right now. Great chance to catch up on reading or review what you've learned so far!";
    }

    $response = [['text' => $assignmentsText]];

    // Add tips for assignment success
    $response[] = ['text' => "**Tips for assignment success:**

• Start early to avoid last-minute stress
• Break large assignments into smaller tasks
• Use the library and academic resources
• Don't hesitate to ask your instructor for clarification
• Consider forming a study group for complex projects

Would you like more specific advice on how to approach your assignments?"];

    return $response;
}

/**
 * Get specific help for an assignment type
 * 
 * @param string $query The user's query
 * @return array Response messages
 */
function get_specific_assignment_help($query) {
    // Extract assignment type or topic
    $assignmentTypes = [
        'essay' => [
            'keywords' => ['essay', 'writing', 'paper', 'report'],
            'tips' => [
                "**Essay Writing Tips:**\n\n1. **Start with a Clear Thesis**: Your thesis statement should concisely state your main argument\n2. **Create an Outline**: Plan your essay structure before writing\n3. **Use Evidence**: Support claims with research and citations\n4. **Draft, Revise, Edit**: Never submit your first draft\n5. **Follow Citation Guidelines**: Use the required format (APA, MLA, etc.)\n\nWould you like specific help with thesis development, research methods, or citation?",
            ]
        ],
        'presentation' => [
            'keywords' => ['presentation', 'slides', 'powerpoint', 'speaking', 'talk'],
            'tips' => [
                "**Presentation Tips:**\n\n1. **Start with a Hook**: Capture attention in the first 30 seconds\n2. **Follow the 6x6 Rule**: No more than 6 points per slide, 6 words per point\n3. **Use Visual Aids**: Include relevant images, charts, and minimal text\n4. **Practice Delivery**: Rehearse timing, tone, and pacing\n5. **Prepare for Questions**: Anticipate and research potential questions\n\nWould you like specific help with slide design, delivery techniques, or managing presentation anxiety?",
            ]
        ],
        'group project' => [
            'keywords' => ['group', 'team', 'project', 'collaboration', 'partner'],
            'tips' => [
                "**Group Project Strategies:**\n\n1. **Define Clear Roles**: Assign specific responsibilities to each member\n2. **Set Deadlines**: Create a timeline with milestones before the final due date\n3. **Maintain Communication**: Regular check-ins keep everyone accountable\n4. **Use Collaborative Tools**: Consider Google Docs, Trello, or Microsoft Teams\n5. **Have a Backup Plan**: Prepare for potential member absence or contribution issues\n\nWould you like advice on handling group conflicts, coordinating schedules, or project management tools?",
            ]
        ],
        'research' => [
            'keywords' => ['research', 'data', 'analysis', 'study', 'experiment', 'survey'],
            'tips' => [
                "**Research Project Advice:**\n\n1. **Narrow Your Focus**: Choose a specific research question\n2. **Literature Review**: Examine existing research before starting\n3. **Methodology Matters**: Select appropriate methods for your question\n4. **Data Management**: Plan how you'll collect and analyze data\n5. **Ethics Consideration**: Ensure your research follows ethical guidelines\n\nWould you like help with research questions, data analysis methods, or finding scholarly sources?",
            ]
        ],
        'programming' => [
            'keywords' => ['programming', 'coding', 'software', 'development', 'code'],
            'tips' => [
                "**Programming Assignment Tips:**\n\n1. **Understand Requirements**: Clarify all requirements before coding\n2. **Plan Before Coding**: Create pseudocode or flowcharts\n3. **Incremental Development**: Build and test in small sections\n4. **Comment Your Code**: Document your logic for yourself and others\n5. **Start Early**: Programming always takes longer than expected\n\nWould you like advice on debugging techniques, code organization, or specific programming languages?",
            ]
        ]
    ];

    $query_lower = strtolower($query);

    foreach ($assignmentTypes as $type => $data) {
        foreach ($data['keywords'] as $keyword) {
            if (strpos($query_lower, $keyword) !== false) {
                // Return the first tip from this type
                return [['text' => $data['tips'][0]]];
            }
        }
    }

    // Generic assignment help if no specific type was identified
    return [['text' => "**General Assignment Tips:**\n\n1. **Understand the Requirements**: Carefully read all instructions\n2. **Create a Plan**: Break the assignment into manageable parts\n3. **Research Thoroughly**: Gather relevant information and sources\n4. **Start Early**: Allow time for revisions and unexpected challenges\n5. **Seek Feedback**: Have someone review your work before submission\n\nCould you tell me what type of assignment you need help with? (Essay, presentation, group project, research, programming, etc.)"]];
}

/**
 * Provide study tips and strategies
 * 
 * @return array Response messages
 */
function get_study_tips() {
    return [
        ['text' => "**Effective Study Strategies:**

1. **Active Recall**: Test yourself regularly instead of just re-reading notes
2. **Spaced Repetition**: Spread out your study sessions rather than cramming
3. **The Pomodoro Technique**: Study in 25-minute focused sessions with short breaks
4. **Create Mind Maps**: Visualize connections between concepts
5. **Teach Someone Else**: Explaining topics helps solidify your understanding
6. **Use Multiple Resources**: Textbooks, videos, practice problems, and discussions
7. **Take Effective Notes**: Use the Cornell method or other structured approaches
8. **Eliminate Distractions**: Create a dedicated study environment

Which of these would you like to learn more about?"]
    ];
}

/**
 * Provide detailed study tips based on query
 * 
 * @param string $query The user's query
 * @return array Response messages
 */
function get_detailed_study_tips($query) {
    $query_lower = strtolower($query);

    // Study techniques based on keywords
    $techniques = [
        'memory' => [
            'keywords' => ['memory', 'remember', 'memorize', 'forget', 'recall'],
            'tips' => "**Memory Enhancement Techniques:**\n\n1. **Spaced Repetition**: Review material at increasing intervals (1 day, 3 days, 1 week)\n2. **Mnemonics**: Create acronyms, rhymes, or visual associations\n3. **Mind Palace**: Associate information with specific locations\n4. **Chunking**: Group information into manageable units\n5. **Active Recall**: Test yourself instead of just reviewing\n6. **Teach Others**: Explaining concepts reinforces memory\n\nThese techniques work by forming stronger neural connections and utilizing multiple memory pathways in your brain."
        ],
        'focus' => [
            'keywords' => ['focus', 'concentrate', 'distraction', 'attention', 'procrastination'],
            'tips' => "**Improving Focus and Concentration:**\n\n1. **Pomodoro Technique**: Work for 25 minutes, then take a 5-minute break\n2. **Environment Optimization**: Create a dedicated, distraction-free study space\n3. **Digital Detox**: Use website blockers and put your phone on Do Not Disturb\n4. **Brain Training**: Gradually increase focus duration over time\n5. **Physical Exercise**: Regular exercise improves cognitive function\n6. **Mindfulness Practice**: Brief meditation before studying sharpens attention\n\nRemember that focus is like a muscle - it gets stronger with consistent practice."
        ],
        'notes' => [
            'keywords' => ['notes', 'taking notes', 'notetak', 'writing down', 'summarize'],
            'tips' => "**Effective Note-Taking Methods:**\n\n1. **Cornell Method**: Divide page into cues, notes, and summary sections\n2. **Mind Mapping**: Create visual connections between related concepts\n3. **Outline Method**: Organize information hierarchically with headings and subpoints\n4. **Charting Method**: Create tables for comparing and contrasting topics\n5. **Sentence Method**: Write complete, concise sentences for each main point\n6. **Digital Tools**: Consider apps like Notion, OneNote, or Evernote for organization\n\nThe best method varies by subject and your learning style - experiment to find what works for you."
        ],
        'exam' => [
            'keywords' => ['exam', 'test', 'quiz', 'final', 'midterm'],
            'tips' => "**Exam Preparation Strategies:**\n\n1. **Create a Study Schedule**: Allocate time for each topic based on importance/difficulty\n2. **Practice Past Exams**: Familiarize yourself with format and common question types\n3. **Group Study Sessions**: Explain concepts to peers and learn from their perspectives\n4. **Cumulative Review**: Regularly revisit previously studied material\n5. **Simulate Exam Conditions**: Practice under timed, distraction-free conditions\n6. **Healthy Routine**: Maintain proper sleep, nutrition, and exercise\n\nPrepare mentally by visualizing success and planning stress-management techniques for exam day."
        ]
    ];

    foreach ($techniques as $technique => $data) {
        foreach ($data['keywords'] as $keyword) {
            if (strpos($query_lower, $keyword) !== false) {
                return [['text' => $data['tips']]];
            }
        }
    }

    // General detailed study advice if no specific technique was mentioned
    return [['text' => "**Comprehensive Study Strategies:**\n\n1. **Understand Your Learning Style**:\n   • Visual learners benefit from diagrams and videos\n   • Auditory learners should record and listen to lectures\n   • Kinesthetic learners should incorporate movement and hands-on activities\n\n2. **Strategic Time Management**:\n   • Study difficult subjects when you're most alert\n   • Break study sessions into 45-50 minute blocks\n   • Include short breaks to prevent mental fatigue\n\n3. **Active Learning Techniques**:\n   • Summarize information in your own words\n   • Create practice tests for yourself\n   • Apply concepts to real-world examples\n   • Use flashcards for key terms and concepts\n\n4. **Environment Optimization**:\n   • Find a consistent study location with minimal distractions\n   • Ensure proper lighting and comfortable seating\n   • Keep all necessary materials within reach\n\nWhat specific aspect of studying would you like more detailed advice on?"]];
}

/**
 * Provide exam preparation advice
 * 
 * @return array Response messages
 */
function get_exam_preparation() {
    return [
        ['text' => "**Exam Preparation Guide:**

1. **Start Early**: Begin studying at least 1-2 weeks before the exam
2. **Create a Study Schedule**: Allocate time for each topic based on its difficulty
3. **Review Past Papers**: Practice with previous exams if available
4. **Form Study Groups**: Discuss difficult concepts with classmates
5. **Use Practice Tests**: Test yourself under timed conditions
6. **Take Care of Yourself**: Get enough sleep, eat well, and take breaks
7. **Know the Format**: Understand what types of questions will be asked
8. **Prepare Strategically**: Focus more on high-value topics

Would you like specific strategies for multiple-choice, essay, or problem-solving exams?"],
        ['text' => "**On Exam Day:**

• Arrive early to settle your nerves
• Read all instructions carefully before starting
• Budget your time based on question point values
• Answer easier questions first to build confidence
• Review your answers if time permits
• Stay calm and focused throughout the exam

Good luck with your preparation!"]
    ];
}

/**
 * Provide time management advice
 * 
 * @return array Response messages
 */
function get_time_management() {
    return [
        ['text' => "**Effective Time Management for Students:**

1. **Use a Planner or Digital Calendar**: Record all deadlines, classes, and commitments
2. **Prioritize Tasks**: Use the Eisenhower Matrix (Urgent/Important grid)
3. **Break Down Large Projects**: Divide major assignments into smaller, manageable tasks
4. **Identify Your Peak Hours**: Schedule difficult tasks when you're most alert
5. **Set Specific Goals**: Define what you want to accomplish in each study session
6. **Eliminate Time Wasters**: Be mindful of social media and other distractions
7. **Learn to Say No**: Don't overcommit yourself
8. **Use 'Dead Time' Effectively**: Study flashcards while waiting for the bus

Would you like help creating a weekly study schedule?"],
        ['text' => "**Sample Weekly Schedule Template:**

• **Morning**: Review notes from the previous day (15-30 minutes)
• **Between Classes**: Quick review of upcoming class material
• **Afternoon**: Work on assignments and projects (2-3 hours)
• **Evening**: Prepare for the next day's classes (1-2 hours)
• **Weekend**: Catch up on readings and start on upcoming assignments

Remember to schedule breaks and leisure activities to avoid burnout!"]
    ];
}

/**
 * Provide learning resources
 * 
 * @return array Response messages
 */
function get_learning_resources() {
    return [
        ['text' => "**Recommended Academic Resources:**

1. **Online Learning Platforms:**
   • Khan Academy - Free courses in math, science, and more
   • Coursera - University courses across various disciplines
   • edX - Courses from top institutions worldwide

2. **Research Tools:**
   • Google Scholar - Search academic papers
   • JSTOR - Digital library of academic journals
   • Library databases specific to your institution

3. **Study Aid Websites:**
   • Quizlet - Create and use flashcards
   • Grammarly - Writing assistance and grammar checker
   • Zotero - Reference management software

What subject are you looking for resources in specifically?"]
    ];
}

/**
 * Provide subject-specific help
 * 
 * @param string $query The user's query
 * @return array Response messages
 */
function get_subject_help($query) {
    // Determine which subject was mentioned
    $subjects = [
        'math' => 'mathematics',
        'science' => 'science',
        'physics' => 'physics',
        'chemistry' => 'chemistry',
        'biology' => 'biology',
        'history' => 'history',
        'english' => 'English',
        'language' => 'languages',
        'programming' => 'programming',
        'economics' => 'economics'
    ];

    $subject = '';
    foreach ($subjects as $keyword => $name) {
        if (strpos($query, $keyword) !== false) {
            $subject = $name;
            break;
        }
    }

    if ($subject) {
        return [
            ['text' => "**Resources for {$subject} students:**

1. **Online Learning:**
   • Khan Academy has excellent {$subject} tutorials
   • YouTube channels like Crash Course {$subject}
   • MIT OpenCourseWare offers free {$subject} lectures

2. **Helpful Websites:**
   • Subject-specific forums where you can ask questions
   • Interactive practice problem sites
   • Visual learning tools and simulations

3. **Study Strategies for {$subject}:**
   • Practice regularly with varied problem sets
   • Join study groups with classmates
   • Create concept maps to connect ideas
   • Teach concepts to others to reinforce understanding

Would you like more specific resources for particular topics in {$subject}?"]
        ];
    }

    return [['text' => "Looking for help with a specific subject? Let me know what topic you're working on so I can find the best resources for you."]];
}

/**
 * Provide career guidance
 * 
 * @return array Response messages
 */
function get_career_guidance() {
    return [
        ['text' => "**Career Planning and Development:**

1. **Explore Career Paths:**
   • Research job descriptions and requirements in your field
   • Speak with professionals through informational interviews
   • Attend career fairs and industry events

2. **Build Relevant Skills:**
   • Identify key skills needed in your desired field
   • Take relevant courses and seek certifications
   • Work on personal projects that demonstrate your abilities

3. **Gain Experience:**
   • Look for internships and volunteer opportunities
   • Participate in research projects with faculty
   • Join relevant student organizations and competitions

4. **Networking:**
   • Create a LinkedIn profile and connect with professionals
   • Attend industry meetups and conferences
   • Reach out to alumni from your institution

Would you like to discuss specific career paths related to your field of study?"],
        ['text' => "**Career Resources at Your Institution:**

• Visit your university's career services center
• Schedule an appointment with a career counselor
• Attend resume and interview workshops
• Check your institution's job board for opportunities

Remember that career planning is an ongoing process, and it's normal to explore different paths before finding the right fit!"]
    ];
}

/**
 * Provide wellbeing support
 * 
 * @return array Response messages
 */
function get_wellbeing_support() {
    return [
        ['text' => "**Academic Wellbeing and Balance:**

1. **Managing Academic Stress:**
   • Break large tasks into smaller, manageable steps
   • Set realistic goals and celebrate small wins
   • Practice positive self-talk and avoid perfectionism
   • Use breathing exercises during stressful moments

2. **Maintaining Balance:**
   • Schedule regular breaks and leisure activities
   • Exercise regularly to clear your mind
   • Prioritize sleep and proper nutrition
   • Connect with friends and family for support

3. **When to Seek Help:**
   • If stress persists or interferes with daily functioning
   • When feeling overwhelmed or unable to cope
   • If experiencing persistent negative thoughts

Remember that seeking help is a sign of strength, not weakness. Your institution likely has counseling services available to students."],
        ['text' => "**Motivation Strategies:**

• Set meaningful, specific goals for your studies
• Find your 'why' - connect your studies to your larger purpose
• Create a reward system for completing tasks
• Visualize your success and the benefits of your education
• Find an accountability partner or study group

Would you like more specific strategies for academic motivation or stress management?"]
    ];
}

/**
 * Provide technology and tools information
 * 
 * @return array Response messages
 */
function get_technology_tools() {
    return [
        ['text' => "**Helpful Academic Tools and Technologies:**

1. **Note-Taking and Organization:**
   • Notion - All-in-one workspace for notes, tasks, and projects
   • OneNote/Evernote - Digital notebooks with organization features
   • Obsidian - Knowledge management with linked notes

2. **Productivity:**
   • Forest - Stay focused and avoid phone distractions
   • Todoist - Task management and to-do lists
   • Focus@Will - Music designed to improve concentration

3. **Research and Writing:**
   • Zotero - Reference management
   • Grammarly - Writing assistance and proofreading
   • Google Scholar - Academic paper search

4. **Study Aids:**
   • Anki - Spaced repetition flashcards
   • Quizlet - Study sets and practice tests
   • Pomodoro Timer apps - Structured study sessions

Which category of tools are you most interested in learning more about?"]
    ];
}