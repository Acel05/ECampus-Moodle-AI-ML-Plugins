/**
 * Chatbot JavaScript module
 *
 * @module     block_chatbot/chatbot
 * @copyright  2023 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery'], function($) {

    /**
     * Initialize the chatbot
     */
    var init = function() {
        $(document).ready(function() {
            console.log('EIRA AI initializing...');
            setupChatbot();
        });
    };

    /**
     * Set up the chatbot UI and interactions
     */
    var setupChatbot = function() {
        // DOM elements
        const $toggleBtn = $('#chatbot-toggle-btn');
        const $chatWindow = $('#chatbot-window');
        const $closeBtn = $('#chatbot-close-btn');
        const $messageInput = $('#chatbot-message-input');
        const $sendButton = $('#chatbot-send-button');
        const $messagesContainer = $('#chatbot-messages');

        console.log('EIRA AI elements found:', {
            toggleBtn: $toggleBtn.length > 0,
            chatWindow: $chatWindow.length > 0,
            closeBtn: $closeBtn.length > 0,
            messageInput: $messageInput.length > 0,
            sendButton: $sendButton.length > 0,
            messagesContainer: $messagesContainer.length > 0
        });

        // Check if elements exist
        if (!$toggleBtn.length || !$chatWindow.length) {
            console.error('EIRA AI: Required elements not found');
            return;
        }

        // Toggle chat window
        $toggleBtn.on('click', function() {
            console.log('Toggle button clicked');
            $chatWindow.toggleClass('chatbot-hidden chatbot-visible');

            if ($chatWindow.hasClass('chatbot-visible')) {
                // Send welcome message when chat is opened
                if ($messagesContainer.children().length === 0) {
                    console.log('Sending welcome message');
                    sendWelcomeMessage();
                }

                // Focus on input
                $messageInput.focus();
            }
        });

        // Close chat window
        $closeBtn.on('click', function() {
            console.log('Close button clicked');
            $chatWindow.removeClass('chatbot-visible').addClass('chatbot-hidden');
        });

        // Send message on enter (without shift)
        $messageInput.on('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Auto resize textarea
        $messageInput.on('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight < 100) ? this.scrollHeight + 'px' : '100px';

            // Enable/disable send button based on input
            $sendButton.prop('disabled', $(this).val().trim() === '');
        });

        // Send button click handler
        $sendButton.on('click', function() {
            console.log('Send button clicked');
            sendMessage();
        });

        // Handle ESC key to close chat
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $chatWindow.hasClass('chatbot-visible')) {
                $chatWindow.removeClass('chatbot-visible').addClass('chatbot-hidden');
            }
        });

        /**
         * Send a welcome message
         */
        function sendWelcomeMessage() {
            // Show typing indicator immediately
            showTypingIndicator();

            // Get welcome message from the server
            $.ajax({
                url: CHATBOT_CONFIG.wwwroot + '/blocks/chatbot/botman/eira_controller.php',
                method: 'POST',
                dataType: 'json',
                contentType: 'application/json',
                data: JSON.stringify({
                    command: 'welcome_message',
                    context: CHATBOT_CONFIG.context,
                    course: CHATBOT_CONFIG.course,
                    token: CHATBOT_CONFIG.token
                }),
                success: function(data) {
                    console.log('Welcome message received:', data);
                    hideTypingIndicator();

                    // Process bot responses
                    if (data && data.messages && Array.isArray(data.messages)) {
                        data.messages.forEach(function(msg, index) {
                            setTimeout(function() {
                                addMessage(msg.text, 'bot', msg.attachment);
                            }, index * 800);
                        });

                        // Add suggestion chips after welcome messages
                        if (data.messages.length > 0) {
                            setTimeout(function() {
                                addSuggestions([
                                    "What are my current courses?",
                                    "Study tips for exams",
                                    "Time management advice",
                                    "Help with assignments",
                                    "Resources for my studies"
                                ]);
                            }, (data.messages.length) * 800);
                        }
                    } else {
                        // Fallback if no messages in response
                        addMessage("Hello! I'm EIRA, your academic AI assistant. How can I help you today?", 'bot');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error getting welcome message:', error);
                    hideTypingIndicator();

                    // Fallback welcome message if server fails
                    addMessage("Hello! I'm EIRA, your academic AI assistant. How can I help you today?", 'bot');
                    setTimeout(function() {
                        addMessage("I can help with your courses, assignments, study tips, and more. What would you like assistance with?", 'bot');
                    }, 800);
                }
            });
        }

        /**
         * Send a message to the chatbot
         */
        function sendMessage() {
            const message = $messageInput.val().trim();
            if (message === '') return;

            console.log('Sending message:', message);

            // Add user message to chat
            addMessage(message, 'user');

            // Clear input and reset its size
            $messageInput.val('');
            $messageInput.css('height', 'auto');
            $sendButton.prop('disabled', true);

            // Show typing indicator
            showTypingIndicator();

            // Set a timeout to ensure the loading indicator doesn't get stuck
            const timeoutId = setTimeout(function() {
                hideTypingIndicator();
                addMessage("I'm sorry, it's taking longer than expected to process your request. Please try again.", 'bot');
            }, 15000); // 15 second timeout

            // Send to EIRA AI controller
            $.ajax({
                url: CHATBOT_CONFIG.wwwroot + '/blocks/chatbot/botman/eira_controller.php',
                method: 'POST',
                dataType: 'json',
                contentType: 'application/json',
                data: JSON.stringify({
                    message: message,
                    context: CHATBOT_CONFIG.context,
                    course: CHATBOT_CONFIG.course,
                    token: CHATBOT_CONFIG.token
                }),
                success: function(data) {
                    clearTimeout(timeoutId); // Clear the timeout
                    console.log('Response received:', data);
                    hideTypingIndicator();

                    // Process bot responses
                    if (data && data.messages && Array.isArray(data.messages)) {
                        let lastMessageText = '';

                        data.messages.forEach(function(msg, index) {
                            setTimeout(function() {
                                addMessage(msg.text, 'bot', msg.attachment);
                                lastMessageText = msg.text;

                                // Add follow-up suggestions after the last message
                                if (index === data.messages.length - 1) {
                                    setTimeout(function() {
                                        addFollowUpSuggestions(lastMessageText);
                                    }, 1000);
                                }
                            }, index * 800);
                        });
                    } else if (data && data.message) {
                        addMessage(data.message, 'bot');
                        setTimeout(function() {
                            addFollowUpSuggestions(data.message);
                        }, 1000);
                    } else {
                        // Fallback response if no messages
                        addMessage("I've processed your message. Is there anything specific you'd like help with?", 'bot');
                    }
                },
                error: function(xhr, status, error) {
                    clearTimeout(timeoutId); // Clear the timeout
                    console.error('Error:', error);
                    hideTypingIndicator();

                    // Fallback message
                    addMessage("I'm sorry, I encountered an issue while processing your request. Please try again in a moment.", 'bot');
                },
                complete: function() {
                    // Focus back on input
                    $messageInput.focus();
                }
            });
        }

        /**
         * Add a message to the chat
         */
        function addMessage(message, sender, attachment) {
            if (!message) {
                console.error('Empty message received');
                return;
            }

            const $messageElement = $('<div>').addClass('chatbot-message').addClass('chatbot-' + sender + '-message');

            // Process message content
            if (typeof message === 'object' && message.text) {
                message = message.text;
            }

            // Format the message with markdown-like syntax
            message = formatMessage(message);

            // Handle links in the message
            message = linkify(message);

            // Add attachment if any
            if (attachment) {
                if (attachment.type === 'file' || (attachment.type && attachment.type.indexOf('File') !== -1)) {
                    message += '<br><a href="' + attachment.url + '" target="_blank" rel="noopener" class="chatbot-link">View File</a>';
                } else if (attachment.url) {
                    message += '<br><a href="' + attachment.url + '" target="_blank" rel="noopener" class="chatbot-link">Open Link</a>';
                }
            }

            const $messageContent = $('<div>').addClass('chatbot-message-content').html(message);
            const $timestamp = $('<div>').addClass('chatbot-timestamp').text(formatTime(new Date()));

            $messageElement.append($messageContent).append($timestamp);

            $messagesContainer.append($messageElement);

            // Scroll to bottom
            $messagesContainer.scrollTop($messagesContainer[0].scrollHeight);
        }

        /**
         * Add suggestion chips to the chat
         */
        function addSuggestions(suggestions) {
            const $suggestionsContainer = $('<div>').addClass('chatbot-suggestions');

            suggestions.forEach(function(suggestion) {
                const $suggestion = $('<span>')
                    .addClass('chatbot-suggestion')
                    .text(suggestion)
                    .on('click', function() {
                        // When clicked, fill the input with this suggestion
                        $messageInput.val(suggestion);
                        // Enable the send button
                        $sendButton.prop('disabled', false);
                        // Focus the input
                        $messageInput.focus();
                        // Automatically send the message
                        sendMessage();
                    });

                $suggestionsContainer.append($suggestion);
            });

            const $messageElement = $('<div>').addClass('chatbot-message chatbot-bot-message');
            $messageElement.append($suggestionsContainer);
            $messagesContainer.append($messageElement);

            // Scroll to bottom
            $messagesContainer.scrollTop($messagesContainer[0].scrollHeight);
        }

        /**
         * Add follow-up suggestions based on the last bot message
         */
        function addFollowUpSuggestions(lastMessage) {
            if (!lastMessage) return;

            // Convert message to lowercase for easier matching
            const lowerMessage = lastMessage.toLowerCase();

            // Check message content and provide relevant suggestions
            if (lowerMessage.includes('study') || lowerMessage.includes('exam')) {
                addSuggestions([
                    "How to improve my focus",
                    "Memory techniques",
                    "Exam anxiety tips",
                    "Study schedule help"
                ]);
            }
            else if (lowerMessage.includes('assignment') || lowerMessage.includes('project')) {
                addSuggestions([
                    "Writing help",
                    "Research tips",
                    "Time management",
                    "Group project advice"
                ]);
            }
            else if (lowerMessage.includes('career') || lowerMessage.includes('job')) {
                addSuggestions([
                    "Resume writing tips",
                    "Interview preparation",
                    "Internship opportunities",
                    "Career assessment"
                ]);
            }
            else if (lowerMessage.includes('resource') || lowerMessage.includes('tool')) {
                addSuggestions([
                    "Note-taking apps",
                    "Research databases",
                    "Study group tools",
                    "Academic journals"
                ]);
            }
            else if (lowerMessage.includes('course') || lowerMessage.includes('class')) {
                addSuggestions([
                    "Show my assignments",
                    "Course materials",
                    "Class schedule",
                    "Contact my instructor"
                ]);
            }
        }

        /**
         * Show typing indicator
         */
        function showTypingIndicator() {
            if ($('#chatbot-typing-indicator').length) return;

            const $typingElement = $('<div>').addClass('chatbot-message chatbot-bot-message chatbot-typing-container').attr('id', 'chatbot-typing-indicator');
            const $typingIndicator = $('<div>').addClass('chatbot-typing-indicator');

            for (let i = 0; i < 3; i++) {
                $typingIndicator.append($('<span>'));
            }

            $typingElement.append($typingIndicator);
            $messagesContainer.append($typingElement);
            $messagesContainer.scrollTop($messagesContainer[0].scrollHeight);
        }

        /**
         * Hide typing indicator
         */
        function hideTypingIndicator() {
            $('#chatbot-typing-indicator').remove();
        }

        /**
         * Format message with enhanced markdown-like syntax
         */
        function formatMessage(text) {
            if (!text) return '';

            // Convert newlines to <br>
            text = text.replace(/\n/g, '<br>');

            // Bold text: **text**
            text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');

            // Italic text: *text*
            text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');

            // Bullet points: •
            text = text.replace(/•(.*?)(?=<br>|$)/g, '<span class="chatbot-bullet">•</span>$1');

            // Numbered lists: 1., 2., etc.
            text = text.replace(/(\d+)\.(.*?)(?=<br>|$)/g, '<span class="chatbot-number">$1.</span>$2');

            // Links: [text](url)
            text = text.replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" target="_blank" rel="noopener" class="chatbot-link">$1</a>');

            return text;
        }

        /**
         * Format time for message timestamp
         */
        function formatTime(date) {
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        /**
         * Convert URLs in text to clickable links
         */
        function linkify(text) {
            if (!text) return '';

            // URL pattern
            const urlPattern = /(\b(https?|ftp):\/\/[-A-Z0-9+&@#\/%?=~_|!:,.;]*[-A-Z0-9+&@#\/%=~_|])/gim;

            // Replace URLs with anchor tags
            return text.replace(urlPattern, '<a href="$1" target="_blank" rel="noopener" class="chatbot-link">$1</a>');
        }
    };

    return {
        init: init
    };
});