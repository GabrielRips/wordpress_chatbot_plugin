jQuery(document).ready(function ($) {
    let isWaitingForResponse = false; // Flag to prevent double sends

    // Function to get the current day
    function getCurrentDay() {
        const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const currentDate = new Date();
        return days[currentDate.getDay()];
    }

    // Function to replace phrases like today, tomorrow, yesterday with actual days
    function replaceDayPhrases(message) {
        const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const currentDate = new Date();
        const todayIndex = currentDate.getDay();

        // Calculate yesterday's and tomorrow's indices
        const yesterdayIndex = (todayIndex - 1 + 7) % 7; // Wraps around for Sunday
        const tomorrowIndex = (todayIndex + 1) % 7; // Wraps around for Saturday

        // Replace phrases in the message
        return message
            .replace(/\btoday\b/gi, days[todayIndex])
            .replace(/\byesterday\b/gi, days[yesterdayIndex])
            .replace(/\btomorrow\b/gi, days[tomorrowIndex]);
    }

    // Handle click on minimized chat to expand
    $('#chatgpt-minimized').click(function () {
        openChatWindow();
    });

    // Handle click on close button to minimize
    $('#chatgpt-close').click(function () {
        $('#chatgpt-expanded').fadeOut();
        $('#chatgpt-overlay').fadeOut();
        $('#chatgpt-minimized').show();
    });

    function openChatWindow() {
        $('#chatgpt-overlay').fadeIn();
        $('#chatgpt-expanded').fadeIn();
        $('#chatgpt-minimized').hide();

        setTimeout(function () {
            $('#chatgpt-message').focus(); // Automatically focus on the message field
        }, 100);

        // Check if there are no messages in the chat container
        if ($('#chatgpt-response').children().length === 0) {
            $('#chatgpt-response').append(
                '<div class="chat-bubble bot-message"><strong>Amy:</strong> How can we help you today?</div>'
            );
            scrollChatToBottom(); // Ensure the message is visible
        }
    }

    // Scrolls to new message once chat window is full
    function scrollChatToBottom() {
        var chatWindow = $('#chatgpt-response');
        chatWindow.scrollTop(chatWindow[0].scrollHeight);
    }

    // Handle click on the custom button to open chat window
    $(document).on('click', '.custom-search-button', function (event) {
        event.preventDefault();
        openChatWindow();
    });

    // Hide chat window when clicking outside of it
    $(document).mouseup(function (e) {
        var container = $('#chatgpt-expanded');
        if (!container.is(e.target) && container.has(e.target).length === 0) {
            if (container.is(':visible')) {
                container.fadeOut();
                $('#chatgpt-overlay').fadeOut();
                $('#chatgpt-minimized').show();
            }
        }
    });

    // Auto-expand textarea on input
    $('#chatgpt-message').on('input', function () {
        this.style.height = 'auto';
        this.style.height = this.scrollHeight + 'px';
    });

    // Send message on "Enter" key press, without pressing Shift+Enter
    $('#chatgpt-message').on('keypress', function (event) {
        if (event.which === 13 && !event.shiftKey) {
            event.preventDefault();
            $('#chatgpt-form').submit();
        }
    });

    // Handle form submission
    $('#chatgpt-form').on('submit', function (event) {
        event.preventDefault();

        if (isWaitingForResponse) return;

        isWaitingForResponse = true;
        $('.send-button').prop('disabled', true);

        let message = $('#chatgpt-message').val().trim();
        if (message === '') return;

        // Replace day phrases in the user's message
        message = replaceDayPhrases(message);

        $('#chatgpt-response').append('<div class="chat-bubble user-message"><strong>You:</strong> ' + $('<div>').text(message).html() + '</div>');
        scrollChatToBottom();
        $('#chatgpt-message').val('').css('height', 'auto');
        
        // Show typing indicator
        var typingMsg = $('<div class="chat-bubble bot-message bot-typing"><strong>Amy is typing</strong></div>');
        $('#chatgpt-response').append(typingMsg);
        scrollChatToBottom();

        // Send the message via AJAX
        $.ajax({
            type: 'POST',
            url: chatgpt_api.ajax_url,
            data: {
                action: 'chatgpt_api_request',
                message: message, // Send the modified message
                nonce: chatgpt_api.nonce,
            },
            dataType: 'json',
            success: function (response) {
                typingMsg.remove(); // Remove typing indicator
                if (response.success) {
                    var chatbotResponse = response.data;
                    $('#chatgpt-response').append('<div class="chat-bubble bot-message"><strong>Amy:</strong> ' + $('<div>').text(chatbotResponse).html() + '</div>');
                } else {
                    $('#chatgpt-response').append('<div class="chat-bubble bot-message"><strong>Amy:</strong> ' + $('<div>').text(response.data).html() + '</div>');
                }
                scrollChatToBottom();
                $('.send-button').prop('disabled', false);
                isWaitingForResponse = false;
            },
            error: function (xhr, status, error) {
                typingMsg.remove(); // Remove typing indicator
                $('.send-button').prop('disabled', false);
                isWaitingForResponse = false;

                var errorMessage = 'An error occurred. Please try again.';
                if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMessage = xhr.responseJSON.data;
                }

                $('#chatgpt-response').append('<div class="chat-bubble bot-message"><strong>Amy:</strong> ' + $('<div>').text(errorMessage).html() + '</div>');
                scrollChatToBottom();
            },
        });
    });
});