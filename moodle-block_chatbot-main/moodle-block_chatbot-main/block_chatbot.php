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
 * Chatbot block for Moodle
 *
 * @package    block_chatbot
 * @copyright  2023 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Chatbot block class
 */
class block_chatbot extends block_base {

    /**
     * Initialize the block
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_chatbot');
    }

    /**
     * Get the content of the block
     *
     * @return stdClass
     */
    public function get_content() {
        global $PAGE, $CFG, $USER;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        // Only show chatbot if user is logged in
        if (isloggedin() && !isguestuser()) {
            // Don't show in popup windows, print mode, or during upgrades
            if ($PAGE->pagelayout !== 'popup' && $PAGE->pagelayout !== 'print' && 
                $PAGE->pagelayout !== 'maintenance' && !during_initial_install()) {

                // Include the CSS directly - this ensures it's applied correctly
                $this->content->text .= '<style>
#chatbot-container {
    position: fixed !important;
    bottom: 20px !important;
    right: 20px !important;
    z-index: 99999 !important;
}
 
#chatbot-toggle-btn {
    width: 60px !important;
    height: 60px !important;
    border-radius: 50% !important;
    background: linear-gradient(135deg, #2926ad, #6abbfe) !important;
    color: white !important;
    border: none !important;
    box-shadow: 0 4px 8px rgba(27, 27, 27, 0.15) !important;
    cursor: pointer !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

#chatbot-window {
    position: fixed !important;
    bottom: 90px !important;
    right: 20px !important;
    width: 350px !important;
    height: 500px !important;
    background: white !important;
    border-radius: 12px !important;
    box-shadow: 0 5px 25px rgba(27, 27, 27, 0.15) !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
}
 
#chatbot-window.chatbot-hidden {
    display: none !important;
}
 
#chatbot-window.chatbot-visible {
    display: flex !important;
}
 
.chatbot-header {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    padding: 15px 20px !important;
    background: linear-gradient(135deg, #2926ad, #6abbfe) !important;
    color: white !important;
}
 
.chatbot-body {
    flex: 1 !important;
    display: flex !important;
    flex-direction: column !important;
    height: calc(100% - 56px) !important;
}
 
#chatbot-messages {
    flex: 1 !important;
    overflow-y: auto !important;
    padding: 15px !important;
    display: flex !important;
    flex-direction: column !important;
    background-color: #f8f9fa !important;
}
 
.chatbot-message {
    max-width: 80% !important;
    padding: 10px 14px !important;
    margin-bottom: 10px !important;
    border-radius: 18px !important;
    position: relative !important;
    word-wrap: break-word !important;
}
 
.chatbot-bot-message {
    align-self: flex-start !important;
    background: white !important;
    border: 1px solid #e9ecef !important;
    border-bottom-left-radius: 4px !important;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05) !important;
}
 
.chatbot-user-message {
    align-self: flex-end !important;
    background: linear-gradient(135deg, #2926ad, #6abbfe) !important;
    color: white !important;
    border-bottom-right-radius: 4px !important;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1) !important;
}
 
#chatbot-input-area {
    display: flex !important;
    padding: 12px !important;
    border-top: 1px solid #e9ecef !important;
    background-color: white !important;
    align-items: center !important;
}

#chatbot-close-btn {
    background: transparent;
    border: 2px solid white;
    border-radius: 50%;
    padding: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
}

#chatbot-close-btn:hover {
    background: rgba(255, 255, 255, 0.1);
}

#chatbot-close-btn svg {
    stroke: white;
}
 
#chatbot-message-input {
    flex: 1 !important;
    border: 1px solid #ced4da !important;
    border-radius: 20px !important;
    padding: 10px 15px !important;
    outline: none !important;
    font-size: 14px !important;
    resize: none !important;
    max-height: 100px !important;
    min-height: 40px !important;
}
 
#chatbot-send-button {
    background: rgb(56, 174, 218) !important;
    color: white !important;
    border: none !important;
    border-radius: 50% !important;
    width: 40px !important;
    height: 40px !important;
    margin-left: 10px !important;
    cursor: pointer !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}
 
#chatbot-send-button:disabled {
    opacity: 0.5 !important;
    cursor: not-allowed !important;
}
 
.chatbot-typing-indicator {
    display: inline-block !important;
    width: 50px !important;
    height: 20px !important;
    text-align: center !important;
}
 
.chatbot-typing-indicator span {
    display: inline-block !important;
    width: 6px !important;
    height: 6px !important;
    background-color: rgba(0, 0, 0, 0.3) !important;
    border-radius: 50% !important;
    margin: 0 1px !important;
    animation: chatbot-bounce 1.2s infinite !important;
}
 
.chatbot-typing-indicator span:nth-child(2) {
    animation-delay: 0.2s !important;
}
 
.chatbot-typing-indicator span:nth-child(3) {
    animation-delay: 0.4s !important;
}
 
@keyframes chatbot-bounce {
    0%, 80%, 100% { transform: translateY(0); }
    40% { transform: translateY(-6px); }
}
</style>';

                // Generate security token
                $token = md5(sesskey() . $USER->id . time());
                set_user_preference('block_chatbot_token', $token);

                // Add the chatbot HTML directly
                $chatbotHTML = $this->get_chatbot_html($token);
                $this->content->text .= $chatbotHTML;

                // Add JavaScript
                $PAGE->requires->js_call_amd('block_chatbot/chatbot', 'init');
            }
        }

        return $this->content;
    }

    /**
     * Define where the block can be added
     *
     * @return array
     */
    public function applicable_formats() {
        return ['all' => true];
    }

    /**
     * Allow only one instance of the block
     *
     * @return bool
     */
    public function instance_allow_multiple() {
        return false;
    }

    /**
     * Hide header and borders
     *
     * @return bool
     */
    public function hide_header() {
        return true;
    }

    /**
     * Generate the chatbot HTML
     *
     * @param string $token Security token
     * @return string HTML for the chatbot
     */
    private function get_chatbot_html($token) {
        global $CFG, $PAGE;
    
        // Add the chat bot to the page
        $html = '
        <div id="chatbot-container">
            <button id="chatbot-toggle-btn" aria-label="' . get_string('chattoggle', 'block_chatbot') . '">
                <img src="/moodle/blocks/chatbot/images/bot_img.png" alt="Chat with EIRA" width="60" height="60" />
            </button>
    
            <div id="chatbot-window" class="chatbot-hidden">
                <div class="chatbot-header">
                    <div class="chatbot-title">EIRA Academic Assistant</div>
                    <button id="chatbot-close-btn" aria-label="' . get_string('chatclose', 'block_chatbot') . '">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M18 6L6 18" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M6 6L18 18" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>
                <div class="chatbot-body">
                    <div id="chatbot-messages"></div>
                    <div id="chatbot-input-area">
                        <textarea id="chatbot-message-input" placeholder="Ask about courses, assignments, or study tips..."></textarea>
                        <button id="chatbot-send-button" disabled>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M22 2L11 13" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M22 2L15 22L11 13L2 9L22 2Z" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <script>
            var CHATBOT_CONFIG = {
                token: "' . $token . '",
                context: ' . $PAGE->context->id . ',
                course: ' . $PAGE->course->id . ',
                wwwroot: "' . $CFG->wwwroot . '"
            };
        </script>';
    
        return $html;
    }
}