<?php
/*
Plugin Name: ChatGPT Bot
Description: A WordPress chatbot plugin using the ChatGPT API, with conversation storage and CSV export.
Version: 1.1
Author: Gabriel Rips
*/

// Prevent direct access
if (!defined('ABSPATH')) exit;

// Start a session to store conversation history
function chatgpt_start_session() {
    if (!isset($_COOKIE['chatgpt_session_id'])) {
        $session_id = wp_generate_uuid4();
        setcookie('chatgpt_session_id', $session_id, time() + 86400, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        $_COOKIE['chatgpt_session_id'] = $session_id;
    }
}
add_action('init', 'chatgpt_start_session');



// Create a database table for storing conversations when the plugin is activated
function chatgpt_create_conversation_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'chatgpt_conversations';

    // Check if the table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
        // If the table doesn't exist, create it
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(255) NOT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            conversation LONGTEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY session_id (session_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    } else {
        // If the table exists, ensure the user_id column is present
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'user_id'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN user_id BIGINT(20) UNSIGNED DEFAULT NULL");
        }
    }
}
register_activation_hook(__FILE__, 'chatgpt_create_conversation_table');



// Enqueue JavaScript and pass AJAX URL to the frontend
function chatgpt_enqueue_scripts() {
    wp_enqueue_script('chatgpt-js', plugin_dir_url(__FILE__) . 'chatgpt.js', array('jquery'), '1.0', true);
    wp_localize_script('chatgpt-js', 'chatgpt_api', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('chatgpt_nonce'),
    ));
}
add_action('wp_enqueue_scripts', 'chatgpt_enqueue_scripts');



// Handle AJAX request to ChatGPT API
function chatgpt_api_request() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'chatgpt_conversations';

    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatgpt_nonce')) {
        wp_send_json_error('Invalid request. Nonce verification failed.', 403);
        wp_die();
    }

    // Clear any previous output
    ob_clean();

    // Validate the input message
    if (!isset($_POST['message']) || empty($_POST['message'])) {
        wp_send_json_error('Please enter a message.', 400);
        wp_die();
    }

    // Sanitize the input message
    $message = sanitize_text_field($_POST['message']);

    // Get the session ID from the cookie
    if (isset($_COOKIE['chatgpt_session_id'])) {
        $session_id = sanitize_text_field($_COOKIE['chatgpt_session_id']);
    } else {
        wp_send_json_error('Session expired. Please refresh the page.', 400);
        wp_die();
    }

    // IMPORTANT: Get existing conversation using prepare statement
    $existing_conversation = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE session_id = %s LIMIT 1",
        $session_id
    ));

    // Initialize chat history
    if ($existing_conversation && !empty($existing_conversation->conversation)) {
        // Decode existing conversation
        $chatgpt_history = json_decode($existing_conversation->conversation, true);
        if (!is_array($chatgpt_history)) {
            // If decoding fails, initialize new history
            $chatgpt_history = initialize_chat_history();
        }
    } else {
        $chatgpt_history = initialize_chat_history();
    }

    // Add the user's message to the conversation history
    $chatgpt_history[] = array('role' => 'user', 'content' => $message);

    // Get the API key
    $api_key = get_option('chatgpt_api_key', '');
    if (empty($api_key)) {
        wp_send_json_error('API key is not configured. Please check the settings.', 500);
        wp_die();
    }

    // Get model name and API endpoint from settings
    $model_name = sanitize_text_field(get_option('chatgpt_model_name', 'gpt-3.5-turbo'));
    $api_endpoint = esc_url_raw(get_option('chatgpt_api_endpoint', 'https://api.openai.com/v1/chat/completions'));

    // Prepare the ChatGPT API request
    $api_request_body = array(
        'model' => $model_name,
        'messages' => $chatgpt_history,
        'temperature' => 0,
    );

    // Send the API request
    $response = wp_remote_post($api_endpoint, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ),
        'body' => wp_json_encode($api_request_body),
        'timeout' => 15,
    ));

    if (is_wp_error($response)) {
        error_log('ChatGPT API Error: ' . $response->get_error_message());
        wp_send_json_error('An error occurred while processing your request.');
        wp_die();
    }

    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    if (isset($data['choices'][0]['message']['content'])) {
        $assistant_response = $data['choices'][0]['message']['content'];

        // Append the assistant's response to the conversation history
        $chatgpt_history[] = array('role' => 'assistant', 'content' => $assistant_response);

        // Get the user ID
        $user_id = get_current_user_id() ?: 0;

        // Prepare the conversation data
        $conversation_data = wp_json_encode($chatgpt_history);

        // IMPORTANT: Use proper database operations with error checking
        if ($existing_conversation) {
            // Update existing conversation
            $update_result = $wpdb->update(
                $table_name,
                array(
                    'conversation' => $conversation_data,
                    'user_id' => $user_id
                ),
                array('session_id' => $session_id),
                array('%s', '%d'),
                array('%s')
            );

            if ($update_result === false) {
                error_log('Failed to update conversation: ' . $wpdb->last_error);
                wp_send_json_error('Failed to update conversation.');
                wp_die();
            }
        } else {
            // Insert new conversation
            $insert_result = $wpdb->insert(
                $table_name,
                array(
                    'session_id' => $session_id,
                    'user_id' => $user_id,
                    'conversation' => $conversation_data
                ),
                array('%s', '%d', '%s')
            );

            if ($insert_result === false) {
                error_log('Failed to insert conversation: ' . $wpdb->last_error);
                wp_send_json_error('Failed to save conversation.');
                wp_die();
            }
        }

        wp_send_json_success($assistant_response);
    } else {
        wp_send_json_error('No response from assistant.');
        wp_die();
    }
}

// Helper function to initialize chat history
function initialize_chat_history() {
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $currentDate = new DateTime();
    $currentDay = $days[$currentDate->format('w')];
    $tomorrowDate = (clone $currentDate)->modify('+1 day');
    $tomorrowDay = $days[$tomorrowDate->format('w')];
    $yesterdayDate = (clone $currentDate)->modify('-1 day');
    $yesterdayDay = $days[$yesterdayDate->format('w')];
    $currentDateNum = $currentDate->format('j');
    $currentMonth = $currentDate->format('F');

    return array(
        array(
            'role' => 'system',
            'content' => "Today is $currentDay, the $currentDateNum of $currentMonth. Yesterday was $yesterdayDay, and tomorrow will be $tomorrowDay. You are a chatbot that will be placed on the Third Wave BBQ website to answer any customer's questions. Answer the question like a good customer service agent might. Be friendly and personable when answering. Ensure you consider things like the day and future days when answering questions. Elaborate on answers when you feel more information would be helpful."
        )
    );
}

// Add this debug function right before your action hooks
function chatgpt_debug_log() {
    error_log('Session ID: ' . (isset($_COOKIE['chatgpt_session_id']) ? $_COOKIE['chatgpt_session_id'] : 'not set'));
}

// Modify your existing action hooks to include the debug function
add_action('wp_ajax_chatgpt_api_request', 'chatgpt_debug_log', 9); // Run before main function
add_action('wp_ajax_chatgpt_api_request', 'chatgpt_api_request', 10); // Main function

add_action('wp_ajax_nopriv_chatgpt_api_request', 'chatgpt_debug_log', 9); // Run before main function
add_action('wp_ajax_nopriv_chatgpt_api_request', 'chatgpt_api_request', 10); // Main function

// Shortcode to display the chatbot form
function chatgpt_display_form() {
    return '
        <div id="chatgpt-chatbot">
            <div id="chatgpt-overlay" class="chatgpt-overlay" style="display: none;"></div>
            <div id="chatgpt-expanded" class="chatgpt-expanded" style="display: none;">
                <!-- Chat content goes here -->
                <div class="chat-container">
                    <div id="chatgpt-response" class="chat-window"></div>
                    <form id="chatgpt-form" class="chat-form">
                        <textarea id="chatgpt-message" placeholder="Write a message..." rows="1"></textarea>
                        <button type="submit" class="send-button">➤</button>
                    </form>
                </div>
                <div id="chatgpt-close" class="chatgpt-close">×</div>
            </div>
        </div>
                <style>
           /* Position the chat widget */
            #chatgpt-chatbot {
                position: fixed;
                z-index: 9999;
                font-family: Arial, sans-serif;
            }

            /* Styles for the minimized chat input */
            .chatgpt-minimized {
                position: fixed;
                bottom: 20px;
                right: 20px;
                cursor: pointer;
            }

            .chatgpt-minimized input {
                width: 250px;
                padding: 10px;
                border-radius: 20px;
                border: 1px solid #ccc;
                cursor: pointer;
                background-color: #fff;
            }

            /* Styles for the expanded chat window */
            .chatgpt-expanded {
                border: none;
                outline: none;
                width: 350px;
                height: 500px;
                background-color: #fff;
                border-radius: 10px;
                overflow: hidden;
                display: none; /* Initially hidden */
                flex-direction: column;
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%); /* Center the window */
                z-index: 10000; /* Ensure it\'s above other elements */
            }

            /* Close button for the chat window */
            .chatgpt-close {
                position: absolute;
                top: 10px;
                right: 15px;
                cursor: pointer;
                font-size: 24px;
                color: #999;
            }

            .chatgpt-close:hover {
                color: #333;
            }

            /* Chat container styles */
            .chat-container {
                display: flex;
                flex-direction: column;
                height: 100%;
            }

            .chat-window {
                flex: 1;
                padding: 10px;
                overflow-y: auto;
                background-color: #414046;
                display: flex;
                flex-direction: column;
            }
                
            .chat-form {
                display: flex;
                align-items: center;
                padding: 8px;
                background-color: #6A6A70;
            }

            .chat-form textarea {
                flex: 1;
                padding: 10px;
                border: none;
                border-radius: 20px;
                background-color: #c6c6c6;
                font-size: 1rem;
                color: #eee;
                outline: none;
                resize: none;
                margin-right: 10px;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
                transition: background-color 0.3s ease;
            }

            .chat-form textarea:focus {
                background-color: #a6a6a6;
            }

            .chat-form textarea::placeholder {
                color: #666666; /* Darker gray placeholder text */
            }

            .send-button {
                background-color: #A14944;
                color: #ffffff;
                border: none;
                border-radius: 50%;
                width: 36px;
                height: 36px;
                font-size: 1.2rem;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: background-color 0.3s ease;
            }

            .send-button:hover {
                background-color: #842F2B;
            }


            /* Chat bubbles */
            .chat-bubble {
                display: inline-block;
                padding: 12px;
                margin: 8px 0;
                border-radius: 18px;
                font-size: 0.9rem;
                line-height: 1.4;
                max-width: 80%;
                word-wrap: break-word;
            }

            .user-message {
                background-color: #A14944;
                color: #ffffff;
                align-self: flex-end;
                border-radius: 18px 18px 0px 18px;
                margin-left: auto;
                text-align: right;
            }

            .bot-message {
                background-color: #666666;
                color: #ffffff;
                align-self: flex-start;
                border-radius: 18px 18px 18px 0px;
                margin-right: auto;
                text-align: left;
            }
            .chatgpt-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 9998; /* Just below the chat window */
            }
        
            .bot-typing {
                font-size: 0.9rem;
                color: #ffffff;
                position: relative;
                display: inline-block;
            }

            .bot-typing::after {
                content: "...";
                animation: ellipsis 1.2s infinite;
            }

            @keyframes ellipsis {
                0% {
                    content: "";
                }
                33% {
                    content: ".";
                }
                66% {
                    content: "..";
                }
                100% {
                    content: "...";
                }
            }

            .chatgpt-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 9998; /* Just below the chat window */
            }
        </style>';
}
add_shortcode('chatgpt_bot', 'chatgpt_display_form');

// Register an admin menu item for viewing conversations
function chatgpt_register_admin_page() {
    add_menu_page('ChatGPT Conversations', 'ChatGPT Conversations', 'manage_options', 'chatgpt-conversations', 'chatgpt_display_conversations', 'dashicons-format-chat', 6);
}
add_action('admin_menu', 'chatgpt_register_admin_page');

// Display the conversations on the admin page
function chatgpt_display_conversations() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'chatgpt_conversations';

    echo '<div class="wrap"><h1>ChatGPT Conversations</h1>';
    echo '<a href="' . admin_url('admin-post.php?action=export_chatgpt_conversations') . '" class="button-primary" style="margin-bottom: 20px;">Download Conversations as CSV</a>';

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>ID</th><th>User ID</th><th>Conversation</th><th>Date</th></tr></thead><tbody>';

    $conversations = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");

    foreach ($conversations as $conversation) {
        echo '<tr>';
        echo '<td>' . esc_html($conversation->id) . '</td>';
        echo '<td>' . esc_html($conversation->user_id) . '</td>';
        echo '<td><pre>' . esc_html(json_encode(json_decode($conversation->conversation), JSON_PRETTY_PRINT)) . '</pre></td>';
        echo '<td>' . esc_html($conversation->created_at) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

// Handle CSV export of conversations
function chatgpt_export_conversations() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'chatgpt_conversations';

    // Retrieve conversations from the database
    $conversations = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");

    // Set headers for CSV file download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ChatGPT_Conversations.csv"');

    // Open output stream for writing CSV
    $output = fopen('php://output', 'w');

    // Write column headers
    fputcsv($output, array('ID', 'User ID', 'Conversation', 'Date'));

    // Write each conversation as a row in the CSV
    foreach ($conversations as $conversation) {
        fputcsv($output, array(
            $conversation->id,
            $conversation->user_id,
            json_encode(json_decode($conversation->conversation), JSON_PRETTY_PRINT),
            $conversation->created_at
        ));
    }

    fclose($output);
    exit;
}
add_action('admin_post_export_chatgpt_conversations', 'chatgpt_export_conversations');

// Add a settings page for the API key
function chatgpt_add_settings_page() {
    add_options_page(
        'ChatGPT Bot Settings',
        'ChatGPT Bot',
        'manage_options',
        'chatgpt-bot-settings',
        'chatgpt_render_settings_page'
    );
}
add_action('admin_menu', 'chatgpt_add_settings_page');

// Render the settings page
function chatgpt_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>ChatGPT Bot Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('chatgpt_bot_settings_group');
            do_settings_sections('chatgpt-bot-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Register the settings
function chatgpt_register_settings() {
    register_setting('chatgpt_bot_settings_group', 'chatgpt_api_key');
    register_setting('chatgpt_bot_settings_group', 'chatgpt_model_name');
    register_setting('chatgpt_bot_settings_group', 'chatgpt_api_endpoint');
}
add_action('admin_init', 'chatgpt_register_settings');

// Add fields to the settings page
function chatgpt_settings_init() {
    add_settings_section(
        'chatgpt_bot_main_section',
        'Main Settings',
        null,
        'chatgpt-bot-settings'
    );

    add_settings_field(
        'chatgpt_api_key',
        'API Key',
        'chatgpt_api_key_callback',
        'chatgpt-bot-settings',
        'chatgpt_bot_main_section'
    );

    add_settings_field(
        'chatgpt_model_name',
        'Model Name',
        'chatgpt_model_name_callback',
        'chatgpt-bot-settings',
        'chatgpt_bot_main_section'
    );

    add_settings_field(
        'chatgpt_api_endpoint',
        'API Endpoint',
        'chatgpt_api_endpoint_callback',
        'chatgpt-bot-settings',
        'chatgpt_bot_main_section'
    );
}
add_action('admin_init', 'chatgpt_settings_init');

// Callback for API Key field
function chatgpt_api_key_callback() {
    $api_key = esc_attr(get_option('chatgpt_api_key', ''));
    echo '<input type="password" name="chatgpt_api_key" value="' . $api_key . '" class="regular-text">';
    echo '<p class="description">Enter your OpenAI API key securely.</p>';
}

// Callback for Model Name field
function chatgpt_model_name_callback() {
    $model_name = esc_attr(get_option('chatgpt_model_name', 'gpt-3.5-turbo'));
    echo '<input type="text" name="chatgpt_model_name" value="' . $model_name . '" class="regular-text">';
    echo '<p class="description">Enter the OpenAI model name (e.g., gpt-3.5-turbo).</p>';
}

// Callback for API Endpoint field
function chatgpt_api_endpoint_callback() {
    $api_endpoint = esc_url(get_option('chatgpt_api_endpoint', 'https://api.openai.com/v1/chat/completions'));
    echo '<input type="text" name="chatgpt_api_endpoint" value="' . $api_endpoint . '" class="regular-text">';
    echo '<p class="description">Enter the API endpoint URL.</p>';
}

add_action('wp_footer', 'add_chatgpt_bot_html');
function add_chatgpt_bot_html() {
    echo do_shortcode('[chatgpt_bot]');
}