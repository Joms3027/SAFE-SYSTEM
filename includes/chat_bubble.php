<?php
// Only show chat bubble for faculty, admin, super_admin, and staff users
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['admin', 'super_admin', 'faculty', 'staff'])) {
    return; // Don't render chat bubble for other user types
}

// Calculate sender_id for JavaScript use (matches chat_api logic)
$chat_sender_id = $_SESSION['user_id'] ?? 0;
$chat_user_type = $_SESSION['user_type'] ?? '';
// DB stores super_admin as 'admin' - use this for sent/received comparison
$chat_sender_type_for_compare = ($chat_user_type === 'super_admin') ? 'admin' : $chat_user_type;

if ($chat_user_type === 'faculty') {
    try {
        if (!class_exists('Database')) {
            require_once __DIR__ . '/database.php';
        }
        $database = Database::getInstance();
        $pdo = $database->getConnection();
        
        $stmt = $pdo->prepare("SELECT id FROM faculty_profiles WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $faculty_data = $stmt->fetch();
        if ($faculty_data) {
            $chat_sender_id = $faculty_data['id'];
        }
    } catch (Exception $e) {
        error_log('Chat bubble: Failed to get faculty ID - ' . $e->getMessage());
    }
} elseif (in_array($chat_user_type, ['admin', 'super_admin', 'staff'])) {
    // For admin and staff, use user ID directly
    $chat_sender_id = $_SESSION['user_id'];
}
?>
<!-- Chat Bubble Component -->
<div id="chatBubble" class="chat-bubble-container">
    <!-- Chat Toggle Button with wrapper for dot positioning on mobile -->
    <div class="chat-toggle-wrapper">
        <button id="chatToggleBtn" class="chat-toggle-btn" onclick="toggleChat()">
            <i class="fas fa-comments"></i>
            <span id="chatUnreadBadge" class="chat-unread-badge" style="display: none;">0</span>
        </button>
        <span id="chatOnlineDot" class="chat-online-dot" style="display: none;" title="Admin online"></span>
    </div>
    
    <!-- Chat Window -->
    <div id="chatWindow" class="chat-window" style="display: none;">
        <!-- Chat Header -->
        <div class="chat-header">
            <div class="chat-header-left">
                <i class="fas fa-comments"></i>
                <span id="chatHeaderTitle">Messages</span>
            </div>
            <div class="chat-header-actions">
                <button class="chat-sound-test-btn" onclick="testNotificationSound()" title="Test notification sound">
                    <i class="fas fa-volume-up"></i>
                </button>
                <button class="chat-minimize-btn" onclick="minimizeChat()" title="Minimize">
                    <i class="fas fa-minus"></i>
                </button>
                <button class="chat-close-btn" onclick="toggleChat()" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        
        <!-- Chat Body -->
        <div class="chat-body">
            <!-- Conversation List View -->
            <div id="conversationList" class="conversation-list">
                <div class="conversation-list-header">
                    <input type="text" id="conversationSearch" placeholder="Search conversations..." class="conversation-search">
                    <button class="new-chat-btn" onclick="showNewChatModal()">
                        <i class="fas fa-plus"></i> New Chat
                    </button>
                </div>
                <div id="conversationListItems" class="conversation-list-items">
                    <div class="loading-state">
                        <i class="fas fa-spinner fa-spin"></i> Loading...
                    </div>
                </div>
            </div>
            
            <!-- Chat Messages View -->
            <div id="chatMessagesView" class="chat-messages-view" style="display: none;">
                <div class="chat-back-header">
                    <button class="back-btn" onclick="backToConversations()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <div class="chat-recipient-info">
                        <div id="chatRecipientAvatar" class="chat-recipient-avatar"></div>
                        <div id="chatRecipientName" class="chat-recipient-name">Select a conversation</div>
                    </div>
                </div>
                
                <div id="chatMessages" class="chat-messages">
                    <div class="no-messages">
                        <i class="fas fa-comments"></i>
                        <p>No messages yet. Start a conversation!</p>
                    </div>
                </div>
                
                <div class="chat-input-container">
                    <textarea id="chatMessageInput" placeholder="Type a message..." rows="1" onkeydown="handleChatKeyPress(event)"></textarea>
                    <button id="sendMessageBtn" onclick="sendMessage()" disabled>
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New Chat Modal -->
<div id="newChatModal" class="chat-modal" style="display: none;">
    <div class="chat-modal-content">
        <div class="chat-modal-header">
            <h3>Start New Chat</h3>
            <button class="chat-modal-close" onclick="closeNewChatModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="chat-modal-body">
            <input type="text" id="userSearch" placeholder="Search users..." class="user-search">
            <div id="userList" class="user-list">
                <div class="loading-state">
                    <i class="fas fa-spinner fa-spin"></i> Loading users...
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chat Notification Sound -->
<!-- Using Web Audio API for notification sound (better browser compatibility) -->
<script>
// Create a pleasant notification sound using Web Audio API (like Meta Messenger)
// NOTE: Only call this after user gesture - audioContext should be created by enableAudioOnInteraction
function createNotificationBeep() {
    if (!audioContext) {
        try {
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
        } catch (e) {
            return; // AudioContext requires user gesture - skip if creation fails
        }
    }
    
    // Create two-tone notification sound (more pleasant)
    const playTone = (frequency, startTime, duration) => {
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        
        oscillator.frequency.value = frequency;
        oscillator.type = 'sine';
        
        // Volume envelope
        gainNode.gain.setValueAtTime(0, startTime);
        gainNode.gain.linearRampToValueAtTime(0.2, startTime + 0.01);
        gainNode.gain.exponentialRampToValueAtTime(0.01, startTime + duration);
        
        oscillator.start(startTime);
        oscillator.stop(startTime + duration);
    };
    
    // Play two tones for a pleasant notification sound
    const now = audioContext.currentTime;
    playTone(587.33, now, 0.1);        // D5 note
    playTone(783.99, now + 0.08, 0.15); // G5 note
    
    console.log('🔔 Notification sound played');
}
</script>

<style>
/* Chat Bubble Styles */
.chat-bubble-container {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 10000 !important; /* Higher than Bootstrap modals (1055) */
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
    overflow: visible; /* Ensure green dot is never clipped on mobile */
}

/* Ensure chat bubble stays above Bootstrap modals and backdrops */
body.modal-open .chat-bubble-container,
.modal.show ~ .chat-bubble-container,
.chat-bubble-container {
    z-index: 10000 !important;
}

/* Ensure modal backdrops don't cover chat bubble */
.modal-backdrop {
    z-index: 1040 !important; /* Bootstrap default is 1040, keep it lower than chat bubble */
}

.modal-backdrop.show {
    z-index: 1040 !important;
}

.chat-toggle-wrapper {
    position: relative;
    display: inline-block;
    overflow: visible; /* Ensure green dot is never clipped on mobile */
}

.chat-toggle-btn {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: #4a90e2;
    border: none;
    color: white;
    font-size: 20px;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    transition: background 0.2s ease;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10001 !important; /* Ensure button is clickable above modals */
}

.chat-toggle-btn:hover {
    background: #357abd;
}

.chat-toggle-btn.pulse {
    animation: chatPulse 0.6s ease-in-out 2;
}

@keyframes chatPulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: 0.7;
    }
}

.chat-online-dot {
    position: absolute;
    bottom: 2px;
    right: 2px;
    width: 10px;
    height: 10px;
    background: #22c55e;
    border: 2px solid white;
    border-radius: 50%;
    box-shadow: 0 0 4px rgba(34, 197, 94, 0.6);
    z-index: 10002 !important; /* Above button so always visible */
    -webkit-tap-highlight-color: transparent;
    pointer-events: none; /* Dot is indicator only, don't block bubble tap */
}

.chat-unread-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #e74c3c;
    color: white;
    border-radius: 10px;
    padding: 2px 6px;
    font-size: 10px;
    font-weight: 600;
    min-width: 18px;
    text-align: center;
}

.chat-window {
    position: absolute;
    bottom: 65px;
    right: 0;
    width: 320px;
    height: 70vh;
    max-height: 70vh;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    border: 1px solid #e0e0e0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    z-index: 10001 !important; /* Higher than container to ensure window is above modals */
}


.chat-header {
    background: #4a90e2;
    color: white;
    padding: 0px 11px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 8px 8px 0 0;
}

.chat-header-left {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    font-size: 14px;
}

.chat-header-actions {
    display: flex;
    gap: 5px;
}

.chat-sound-test-btn,
.chat-minimize-btn,
.chat-close-btn {
    background: transparent;
    border: none;
    color: white;
    width: 28px;
    height: 28px;
    border-radius: 4px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
    font-size: 13px;
}

.chat-sound-test-btn:hover,
.chat-minimize-btn:hover,
.chat-close-btn:hover {
    background: rgba(255, 255, 255, 0.15);
}

.chat-body {
    flex: 1;
    min-height: 0; /* Allow shrinking so conversation list can scroll */
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

/* Conversation List */
.conversation-list {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0; /* Allow shrinking so list can scroll when >10 conversations */
    overflow: hidden;
}

.conversation-list-header {
    padding: 10px;
    border-bottom: 1px solid #e0e0e0;
}

.conversation-search {
    width: 100%;
    padding: 7px 10px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 12px;
    margin-bottom: 8px;
}

.conversation-search:focus {
    outline: none;
    border-color: #4a90e2;
}

.new-chat-btn {
    width: 100%;
    padding: 8px !important;
    background: #4a90e2;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 500;
    transition: background 0.2s;
}

.new-chat-btn:hover {
    background: #357abd;
}

.conversation-list-items {
    flex: 1;
    min-height: 0; /* Required for flex child to shrink and enable scroll when >10 persons */
    max-height: calc(82vh - 220px); /* Force bounded height so list scrolls when >10 items */
    overflow-y: auto;
    overflow-x: hidden;
    overscroll-behavior-y: contain; /* Prevent scroll from propagating to page when hovering */
    -webkit-overflow-scrolling: touch;
}

.conversation-item {
    padding: 8px 10px;
    border-bottom: 1px solid #f0f0f0;
    cursor: pointer;
    transition: background 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
}

.conversation-item:hover {
    background: #f8f8f8;
}

.conversation-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #6c757d;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 500;
    font-size: 14px;
    flex-shrink: 0;
}

.conversation-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
}

.conversation-info {
    flex: 1;
    min-width: 0;
}

.conversation-name {
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 2px;
    color: #333;
}

.conversation-last-message {
    font-size: 11px;
    color: #666;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.conversation-badge {
    background: #4a90e2;
    color: white;
    border-radius: 10px;
    padding: 2px 6px;
    font-size: 10px;
    font-weight: 600;
    min-width: 18px;
    text-align: center;
}

/* Chat Messages View */
.chat-messages-view {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow: hidden;
}

.chat-back-header {
    display: flex;
    align-items: center;
    padding: 8px 10px;
    border-bottom: 1px solid #e0e0e0;
    gap: 8px;
    height: 7vh;
}

.back-btn {
    background: none;
    border: none;
    color: #4a90e2;
    font-size: 16px;
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: background 0.2s;
}

.back-btn:hover {
    background: #f0f0f0;
}

.chat-recipient-info {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
}

.chat-recipient-avatar {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: #6c757d;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 500;
    font-size: 12px;
}

.chat-recipient-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
}

.chat-recipient-name {
    font-weight: 600;
    font-size: 13px;
    color: #333;
}

.chat-messages {
    flex: 1;
    min-height: 0;
    max-height: calc(70vh - 180px); /* Bounded height so messages scroll when long */
    overflow-y: auto;
    padding: 10px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}


.no-messages {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: #999;
    text-align: center;
}

.no-messages i {
    font-size: 36px;
    margin-bottom: 8px;
}

.no-messages p {
    font-size: 12px;
}

.chat-message {
    display: flex;
    gap: 6px;
    max-width: 75%;
    margin-bottom: 4px;
}

/* Sent messages (current user) - right side */
.chat-message.sent {
    align-self: flex-end;
    flex-direction: row-reverse;
    margin-left: auto;
}

/* Received messages (other party) - left side */
.chat-message.received {
    align-self: flex-start;
}

.message-bubble {
    padding: 8px 12px;
    border-radius: 8px;
    word-wrap: break-word;
    font-size: 13px;
    line-height: 1.4;
}

.chat-message.sent .message-bubble {
    background: #4a90e2;
    color: white;
}

.chat-message.received .message-bubble {
    background: #f5f5f5;
    color: #333;
}

.message-time {
    font-size: 9px;
    color: #999;
    margin-top: 2px;
    text-align: right;
}

.chat-message.received .message-time {
    text-align: left;
}

/* Chat Input - always at bottom, never shrinks or scrolls out of view */
.chat-input-container {
    flex-shrink: 0;
    padding: 8px 10px;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 6px;
    align-items: flex-end;
    background: white;
}

#chatMessageInput {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 13px;
    resize: none;
    max-height: 80px;
    font-family: inherit;
}

#chatMessageInput:focus {
    outline: none;
    border-color: #4a90e2;
}

#sendMessageBtn {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    background: #4a90e2;
    border: none;
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
    font-size: 13px;
}

#sendMessageBtn:disabled {
    background: #ccc;
    cursor: not-allowed;
}

#sendMessageBtn:not(:disabled):hover {
    background: #357abd;
}

/* Chat Modal */
.chat-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10001 !important;
    pointer-events: auto !important;
}

.chat-modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 320px;
    max-height: 70vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    border: 1px solid #e0e0e0;
    position: relative;
    z-index: 10002 !important;
    pointer-events: auto !important;
    isolation: isolate !important;
}

.chat-modal-header {
    padding: 12px;
    border-bottom: 1px solid #e0e0e0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    z-index: 10003 !important;
    pointer-events: auto !important;
    height: 8vh !important;
}

.chat-modal-header h3 {
    margin: 0;
    font-size: 14px;
    color: #333;
}

.chat-modal-close {
    background: none;
    border: none;
    font-size: 18px;
    color: #666;
    cursor: pointer;
    padding: 0 !important;
    position: relative;
    z-index: 10004 !important;
    pointer-events: auto !important;
    touch-action: manipulation !important;
    -webkit-tap-highlight-color: rgba(0, 0, 0, 0.1);
    isolation: isolate !important;
    min-width: 36px;
    min-height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: background 0.2s;
    -webkit-user-select: none;
    user-select: none;
}

.chat-modal-close:hover {
    background: #f0f0f0;
}

.chat-modal-body {
    padding: 12px;
    flex: 1;
    overflow-y: auto;
}

.user-search {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 13px;
    margin-bottom: 10px;
}

.user-search:focus {
    outline: none;
    border-color: #4a90e2;
}

.user-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.user-item {
    padding: 8px;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
}

.user-item:hover {
    background: #f8f8f8;
    border-color: #4a90e2;
}

.user-avatar-wrap {
    position: relative;
    flex-shrink: 0;
}

.user-online-dot {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 8px;
    height: 8px;
    background: #22c55e;
    border: 2px solid white;
    border-radius: 50%;
    box-shadow: 0 0 2px rgba(34, 197, 94, 0.6);
}

.user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #6c757d;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 500;
    font-size: 13px;
}

.user-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
}

.user-name {
    font-weight: 500;
    font-size: 12px;
    color: #333;
}

.loading-state {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 30px;
    color: #999;
    font-size: 12px;
    gap: 8px;
}

.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 30px;
    color: #999;
    text-align: center;
}

.empty-state i {
    font-size: 36px;
    margin-bottom: 10px;
}

.empty-state p {
    font-size: 12px;
}

/* Scrollbar Styles */
.chat-messages::-webkit-scrollbar,
.conversation-list-items::-webkit-scrollbar,
.chat-modal-body::-webkit-scrollbar {
    width: 8px;
}

.chat-messages::-webkit-scrollbar-track,
.conversation-list-items::-webkit-scrollbar-track,
.chat-modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.chat-messages::-webkit-scrollbar-thumb,
.conversation-list-items::-webkit-scrollbar-thumb,
.chat-modal-body::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 3px;
}

.chat-messages::-webkit-scrollbar-thumb:hover,
.conversation-list-items::-webkit-scrollbar-thumb:hover,
.chat-modal-body::-webkit-scrollbar-thumb:hover {
    background: #555;
}

/* Responsive - Mobile-first improvements for UX */
@media (max-width: 768px) {
    /* Touch-friendly toggle button (min 44px target) */
    .chat-toggle-btn {
        width: 56px;
        height: 56px;
        font-size: 22px;
        box-shadow: 0 4px 12px rgba(74, 144, 226, 0.35);
        -webkit-tap-highlight-color: transparent;
        touch-action: manipulation;
    }
    .chat-toggle-btn:active {
        transform: scale(0.96);
        transition: transform 0.1s ease;
    }
    .chat-unread-badge {
        min-width: 20px;
        height: 20px;
        padding: 0 6px;
        font-size: 11px;
        line-height: 20px;
        top: -2px;
        right: -2px;
    }
    /* Green dot - slightly larger on mobile, ensure always visible */
    .chat-online-dot {
        width: 12px;
        height: 12px;
        bottom: 3px;
        right: 3px;
        z-index: 10002 !important;
    }
}

@media (max-width: 480px) {
    /* Extra visibility for green dot on phones */
    .chat-toggle-wrapper .chat-online-dot {
        width: 14px;
        height: 14px;
        bottom: 2px;
        right: 2px;
        box-shadow: 0 0 6px rgba(34, 197, 94, 0.8);
    }
}

@media (max-width: 480px) {
    .chat-bubble-container {
        z-index: 10000 !important;
        bottom: 20px;
        right: 16px;
        left: auto;
    }
    
    /* Center chat window on viewport when open on mobile */
    .chat-window {
        position: fixed !important;
        left: 50% !important;
        right: auto !important;
        transform: translateX(-50%) !important;
        width: calc(100vw - 24px);
        max-width: 400px;
        bottom: 80px;
        max-height: min(calc(100dvh - 100px), calc(100vh - 100px));
        transition: bottom 0.25s ease, max-height 0.25s ease, border-radius 0.25s ease;
        z-index: 10001 !important;
        border-radius: 16px 16px 0 0;
        box-shadow: 0 -4px 24px rgba(0, 0, 0, 0.12);
    }
    
    .chat-window.keyboard-open {
        bottom: 0;
        max-height: 100dvh;
        border-radius: 0;
        width: 100vw;
        max-width: none;
        left: 0 !important;
        right: 0 !important;
        transform: none !important;
    }
    
    /* Taller, touch-friendly header on mobile */
    .chat-header {
        padding: 12px 12px 12px 14px;
        min-height: 52px;
    }
    .chat-header-left {
        font-size: 15px;
    }
    .chat-sound-test-btn,
    .chat-minimize-btn,
    .chat-close-btn {
        width: 40px;
        height: 40px;
        min-width: 40px;
        min-height: 40px;
        font-size: 16px;
        -webkit-tap-highlight-color: transparent;
        touch-action: manipulation;
    }
    
    /* Conversation list - touch-friendly rows */
    .conversation-list-header {
        padding: 12px;
    }
    .conversation-search {
        padding: 10px 12px;
        font-size: 16px; /* Prevents iOS zoom on focus */
        min-height: 44px;
        border-radius: 10px;
    }
    .new-chat-btn {
        padding: 12px !important;
        min-height: 44px;
        font-size: 14px;
        border-radius: 10px;
    }
    .conversation-item {
        padding: 14px 12px;
        min-height: 64px;
        -webkit-tap-highlight-color: transparent;
    }
    .conversation-avatar {
        width: 44px;
        height: 44px;
        font-size: 16px;
    }
    .conversation-name {
        font-size: 14px;
    }
    .conversation-last-message {
        font-size: 13px;
    }
    
    /* Messages view - back bar and input */
    .chat-back-header {
        padding: 10px 12px;
        min-height: 52px;
    }
    .back-btn {
        min-width: 44px;
        min-height: 44px;
        padding: 10px;
        font-size: 18px;
        -webkit-tap-highlight-color: transparent;
        touch-action: manipulation;
    }
    .chat-recipient-avatar {
        width: 36px;
        height: 36px;
        font-size: 14px;
    }
    .chat-recipient-name {
        font-size: 14px;
    }
    .chat-messages {
        padding: 12px;
        gap: 10px;
    }
    .message-bubble {
        padding: 10px 14px;
        font-size: 15px;
        line-height: 1.45;
    }
    .chat-input-container {
        padding: 10px 12px;
        padding-bottom: max(10px, env(safe-area-inset-bottom));
    }
    #chatMessageInput {
        padding: 12px 14px;
        font-size: 16px; /* Prevents iOS zoom */
        min-height: 44px;
        border-radius: 10px;
    }
    #sendMessageBtn {
        width: 44px;
        height: 44px;
        min-width: 44px;
        min-height: 44px;
        font-size: 16px;
        border-radius: 10px;
        -webkit-tap-highlight-color: transparent;
        touch-action: manipulation;
    }
    
    /* New chat modal - mobile friendly */
    .chat-modal-content {
        width: calc(100vw - 24px);
        max-width: 400px;
        max-height: 85dvh;
        border-radius: 16px;
    }
    .chat-modal-header {
        min-height: 52px;
        padding: 14px 12px;
    }
    .chat-modal-header h3 {
        font-size: 17px;
    }
    .chat-modal-close {
        min-width: 44px !important;
        min-height: 44px !important;
        font-size: 20px;
    }
    .chat-modal-body {
        padding: 12px;
    }
    .user-search {
        padding: 12px 14px;
        font-size: 16px;
        min-height: 44px;
        border-radius: 10px;
        margin-bottom: 12px;
    }
    .user-item {
        padding: 12px;
        min-height: 56px;
        border-radius: 10px;
        -webkit-tap-highlight-color: transparent;
    }
    .user-avatar {
        width: 40px;
        height: 40px;
        font-size: 15px;
    }
    .user-online-dot {
        width: 10px;
        height: 10px;
        bottom: -1px;
        right: -1px;
    }
    .user-name {
        font-size: 15px;
    }
    
    /* Ensure chat bubble stays visible when modals are open on mobile */
    body.modal-open .chat-bubble-container,
    .modal.show ~ .chat-bubble-container {
        z-index: 10000 !important;
    }
}

/* Remove excessive padding on mobile for New Chat button */
@media (max-width: 991px) {
    .new-chat-btn {
        padding: 8px !important;
    }
    
    .chat-modal-close {
        padding: 0 !important;
        min-width: 44px !important;
        min-height: 44px !important;
    }
    .chat-bubble-container {
        top: 85%;
        z-index: 10000 !important; /* Ensure chat bubble is above modals on mobile */
    }
    
    /* Ensure chat bubble stays above Bootstrap modals and backdrops */
    .chat-bubble-container,
    .chat-toggle-btn,
    .chat-window {
        z-index: 10000 !important;
    }
    
    /* When modal is open, ensure chat bubble is still visible */
    body.modal-open .chat-bubble-container {
        z-index: 10000 !important;
    }
    .chat-window {
        width: calc(100vw - 40px);
        /* height: calc(100vh - 120px); */
        bottom: 120px;
        max-height: calc(100vh - 140px);
        transition: bottom 0.3s ease, max-height 0.3s ease;
    }
    
    .chat-window.keyboard-open {
        bottom: 0;
        max-height: 100vh;
        border-radius: 0;
    }
    
    /* Ensure chat input container stays visible above keyboard */
    .chat-input-container {
        position: sticky;
        bottom: 0;
        background: white;
        z-index: 10;
    }
    
    /* When keyboard is open, ensure messages area is scrollable */
    .chat-window.keyboard-open .chat-messages {
        overflow-y: auto;
        flex: 1;
        min-height: 0;
    }
    
    .chat-window.keyboard-open .chat-messages-view {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
    }
    
    .chat-window.keyboard-open .chat-body {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
    }
    .chat-header {
    background: #4a90e2;
    color: white;
    padding: 0px 11px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 8px 8px 0 0;
}
.chat-modal-header {
    padding: 12px;
    border-bottom: 1px solid #e0e0e0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    z-index: 10003 !important;
    pointer-events: auto !important;
    height: 8vh !important;
}
}
</style>

<script>
// Avatar base path for profile pictures (API returns filename only, e.g. 69a97e76eac8d_1772715638.jpg)
<?php
if (function_exists('getBasePath')) {
    $chat_base = getBasePath();
} else {
    $chat_script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    $chat_base = dirname($chat_script_dir);
}
$chat_base = trim($chat_base ?? '', '/');
$chat_avatar_base = ($chat_base !== '' ? '/' . $chat_base : '') . '/uploads/profiles/';
// Prevent protocol-relative URL (//) which makes browser treat "uploads" as hostname
$chat_avatar_base = preg_replace('#^/+#', '/', $chat_avatar_base);
// API base path - use absolute path to avoid 404 when page is at root (e.g. login)
$chat_api_base = ($chat_base !== '' ? '/' . $chat_base : '') . '/includes/chat_api.php';
$chat_api_base = preg_replace('#^/+#', '/', $chat_api_base);
?>
const CHAT_AVATAR_BASE = <?php echo json_encode($chat_avatar_base); ?>;
const CHAT_API_BASE = <?php echo json_encode($chat_api_base); ?>;

function chatAvatarUrl(filename) {
    if (!filename || typeof filename !== 'string') return null;
    return CHAT_AVATAR_BASE + filename;
}

// Chat variables
let currentChatUser = null;
let lastMessageId = 0;
let chatPollingInterval = null;
let unreadPollingInterval = null;
let conversationListPollingInterval = null;
let onlineAdminPollingInterval = null;
let previousUnreadCount = 0;
let audioContext = null;
// Store conversation/user data for click handlers (avoids JSON-in-attribute escaping issues)
// Attached to window so inline onclick handlers can access them
window.conversationDataMap = new Map();
window.userDataMap = new Map();
// Cache last_message when loaded from get_messages (fixes "No messages yet" when API subquery returns null)
window.lastMessageCache = {};
let audioInitialized = false;
let audioInitializing = false; // Prevent multiple initialization attempts
let audioElement = null;

// Initialize chat
document.addEventListener('DOMContentLoaded', function() {
    initializeChat();
    enableAudioOnInteraction();
    initializeMobileKeyboardHandling();
    
    // CRITICAL: Ensure chat modal close button is always clickable
    function ensureChatModalCloseButton() {
        const closeBtn = document.querySelector('.chat-modal-close');
        if (closeBtn) {
            closeBtn.style.pointerEvents = 'auto';
            closeBtn.style.zIndex = '10004';
            closeBtn.style.position = 'relative';
            closeBtn.style.cursor = 'pointer';
            closeBtn.style.touchAction = 'manipulation';
            closeBtn.style.padding = '0';
            
            // Ensure the button has proper event handling
            const handleClose = function(e) {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                closeNewChatModal();
                return false;
            };
            
            // Add event listeners with capture phase to ensure they fire first
            closeBtn.addEventListener('click', handleClose, { capture: true });
            closeBtn.addEventListener('touchend', handleClose, { capture: true, passive: false });
        }
    }
    
    // Run immediately
    ensureChatModalCloseButton();
    
    // Also run when modal is shown (in case it's added dynamically)
    const observer = new MutationObserver(function(mutations) {
        const modal = document.getElementById('newChatModal');
        if (modal && modal.style.display !== 'none' && modal.style.display !== '') {
            ensureChatModalCloseButton();
        }
    });
    
    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['style', 'class']
    });
    
    console.log('💡 Chat system using Web Audio API for notification sounds');
});

// Enable audio on first user interaction (required by browsers)
function enableAudioOnInteraction() {
    const events = ['click', 'touchstart', 'keydown', 'mousedown', 'touchend'];
    const initAudio = function(e) {
        // Only create in response to trusted user gesture (prevents browser warning)
        if (e && !e.isTrusted) return;
        // Prevent multiple simultaneous initialization attempts
        if (audioInitialized || audioInitializing) {
            return;
        }
        
        audioInitializing = true;
        
        try {
            // Create AudioContext for Web Audio API beeps
            if (!audioContext) {
                audioContext = new (window.AudioContext || window.webkitAudioContext)();
            }
            
            // Resume audio context if suspended (required by some browsers)
            if (audioContext.state === 'suspended') {
                audioContext.resume().then(() => {
                    audioInitialized = true;
                    audioInitializing = false;
                    console.log('✅ Chat audio initialized successfully (Web Audio API)');
                    
                    // Remove event listeners after successful init
                    events.forEach(event => {
                        document.removeEventListener(event, initAudio);
                    });
                }).catch(e => {
                    audioInitializing = false;
                    console.log('⏳ Audio init pending');
                });
            } else {
                audioInitialized = true;
                audioInitializing = false;
                console.log('✅ Chat audio initialized successfully (Web Audio API)');
                
                // Remove event listeners after successful init
                events.forEach(event => {
                    document.removeEventListener(event, initAudio);
                });
            }
        } catch (error) {
            audioInitializing = false;
            console.log('❌ Audio initialization error:', error);
        }
    };
    
    // Add listeners for all events (passive: true for touch to avoid scroll-blocking violation)
    events.forEach(event => {
        const opts = { once: false };
        if (['touchstart', 'touchmove', 'touchend'].includes(event)) opts.passive = true;
        document.addEventListener(event, initAudio, opts);
    });
}

// Function to play notification sound (like Meta Messenger)
function playChatNotification() {
    try {
        // Add visual pulse effect to chat button
        const chatBtn = document.getElementById('chatToggleBtn');
        if (chatBtn) {
            chatBtn.classList.remove('pulse');
            // Force reflow to restart animation
            void chatBtn.offsetWidth;
            chatBtn.classList.add('pulse');
            
            // Remove pulse class after animation completes
            setTimeout(() => {
                chatBtn.classList.remove('pulse');
            }, 3000);
        }
        
        // If not initialized, show notice and exit
        if (!audioInitialized) {
            console.log('⚠️ Audio not initialized. Please click anywhere on the page.');
            showAudioPermissionNotice();
            return;
        }
        
        // Use Web Audio API beep since MP3 file is missing
        createNotificationBeep();
        
    } catch (error) {
        console.log('❌ Error playing notification sound:', error);
    }
}

// Show a temporary notice that user needs to interact with the page
function showAudioPermissionNotice() {
    // Only show once per session
    if (window.audioNoticeShown) return;
    window.audioNoticeShown = true;
    
    const notice = document.createElement('div');
    notice.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #4a90e2;
        color: white;
        padding: 12px 16px;
        border-radius: 6px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        z-index: 10000;
        font-size: 13px;
        max-width: 300px;
    `;
    notice.innerHTML = `
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-volume-up" style="font-size: 20px;"></i>
            <div>
                <strong>Enable Sound Notifications</strong>
                <p style="margin: 5px 0 0 0; font-size: 12px; opacity: 0.9;">
                    Click anywhere to enable chat sounds
                </p>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" 
                    style="background: rgba(255,255,255,0.2); border: none; color: white; 
                           width: 24px; height: 24px; border-radius: 50%; cursor: pointer; 
                           margin-left: auto;">
                ×
            </button>
        </div>
    `;
    document.body.appendChild(notice);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notice.parentElement) {
            notice.remove();
        }
    }, 5000);
    
    // Remove on any click
    const removeOnClick = function() {
        if (notice.parentElement) {
            notice.remove();
        }
        document.removeEventListener('click', removeOnClick);
    };
    setTimeout(() => {
        document.addEventListener('click', removeOnClick);
    }, 100);
}

// Mobile keyboard handling
function initializeMobileKeyboardHandling() {
    const chatWindow = document.getElementById('chatWindow');
    const chatMessageInput = document.getElementById('chatMessageInput');
    const conversationSearch = document.getElementById('conversationSearch');
    
    if (!chatWindow) return;
    
    let initialViewportHeight = window.innerHeight;
    let isKeyboardOpen = false;
    
    // Function to handle keyboard visibility
    function handleKeyboardVisibility() {
        const currentViewportHeight = window.innerHeight;
        const heightDifference = initialViewportHeight - currentViewportHeight;
        
        // Consider keyboard open if viewport height decreased by more than 150px (typical keyboard height)
        const keyboardIsOpen = heightDifference > 150;
        
        if (keyboardIsOpen !== isKeyboardOpen) {
            isKeyboardOpen = keyboardIsOpen;
            
            if (keyboardIsOpen) {
                chatWindow.classList.add('keyboard-open');
                // Scroll input into view and ensure messages are visible
                setTimeout(() => {
                    if (chatMessageInput && document.activeElement === chatMessageInput) {
                        // Scroll the input into view
                        chatMessageInput.scrollIntoView({ behavior: 'smooth', block: 'end' });
                        
                        // Also scroll messages container to bottom if there are messages
                        const chatMessages = document.getElementById('chatMessages');
                        if (chatMessages && chatMessages.querySelectorAll('.chat-message').length > 0) {
                            chatMessages.scrollTop = chatMessages.scrollHeight;
                        }
                    }
                }, 100);
            } else {
                chatWindow.classList.remove('keyboard-open');
            }
        }
    }
    
    // Use Visual Viewport API if available (modern browsers)
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', function() {
            handleKeyboardVisibility();
        });
        
        window.visualViewport.addEventListener('scroll', function() {
            handleKeyboardVisibility();
        });
    } else {
        // Fallback: detect viewport height changes
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(handleKeyboardVisibility, 100);
        });
        
        // Also check on orientation change
        window.addEventListener('orientationchange', function() {
            setTimeout(() => {
                initialViewportHeight = window.innerHeight;
                handleKeyboardVisibility();
            }, 300);
        });
    }
    
    // Handle input focus/blur
    if (chatMessageInput) {
        chatMessageInput.addEventListener('focus', function() {
            setTimeout(() => {
                handleKeyboardVisibility();
                // Scroll input into view
                this.scrollIntoView({ behavior: 'smooth', block: 'end' });
                
                // Also ensure messages container scrolls to bottom
                const chatMessages = document.getElementById('chatMessages');
                if (chatMessages) {
                    setTimeout(() => {
                        chatMessages.scrollTop = chatMessages.scrollHeight;
                    }, 350);
                }
            }, 300);
        });
        
        chatMessageInput.addEventListener('blur', function() {
            setTimeout(() => {
                handleKeyboardVisibility();
            }, 300);
        });
    }
    
    // Handle conversation search focus/blur
    if (conversationSearch) {
        conversationSearch.addEventListener('focus', function() {
            setTimeout(() => {
                handleKeyboardVisibility();
            }, 300);
        });
        
        conversationSearch.addEventListener('blur', function() {
            setTimeout(() => {
                handleKeyboardVisibility();
            }, 300);
        });
    }
    
    // Update initial height on load
    setTimeout(() => {
        initialViewportHeight = window.innerHeight;
    }, 500);
}

function initializeChat() {
    // Load conversations
    loadConversations();
    
    // Start polling for unread messages
    startUnreadPolling();
    
    // Fast-poll online admin count (faculty/staff only - for green dot on bubble)
    const userType = <?php echo json_encode($_SESSION['user_type'] ?? ''); ?>;
    if (userType === 'faculty' || userType === 'staff') {
        updateOnlineAdminDot();
        onlineAdminPollingInterval = setInterval(updateOnlineAdminDot, 2000);
    }
    
    // Enable send button when message is typed
    document.getElementById('chatMessageInput').addEventListener('input', function() {
        const sendBtn = document.getElementById('sendMessageBtn');
        sendBtn.disabled = this.value.trim() === '';
    });
    
    // Auto-resize textarea
    document.getElementById('chatMessageInput').addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 100) + 'px';
    });
    
    // Search conversations
    document.getElementById('conversationSearch').addEventListener('input', function() {
        filterConversations(this.value);
    });
    
    // Search users in new chat modal
    document.getElementById('userSearch').addEventListener('input', function() {
        filterUsers(this.value);
    });
    
    // Ensure conversation list scrolls with mouse wheel when hovered (fixes scroll not working on some browsers)
    const convListEl = document.getElementById('conversationListItems');
    if (convListEl) {
        convListEl.addEventListener('wheel', function(e) {
            const el = this;
            const { scrollTop, scrollHeight, clientHeight } = el;
            if (scrollHeight <= clientHeight) return;
            const isScrollingDown = e.deltaY > 0;
            const atBottom = scrollTop + clientHeight >= scrollHeight - 1;
            const atTop = scrollTop <= 1;
            if ((isScrollingDown && atBottom) || (!isScrollingDown && atTop)) return;
            e.preventDefault();
            el.scrollTop += e.deltaY;
        }, { passive: false });
    }
}

// Test notification sound
function testNotificationSound() {
    console.log('🧪 Testing notification sound...');
    
    // If not initialized, try to initialize first
    if (!audioInitialized && !audioInitializing) {
        audioInitializing = true;
        
        try {
            if (!audioContext) {
                audioContext = new (window.AudioContext || window.webkitAudioContext)();
            }
            
            if (audioContext.state === 'suspended') {
                audioContext.resume().then(() => {
                    audioInitialized = true;
                    audioInitializing = false;
                    console.log('✅ Audio initialized, playing test sound');
                    createNotificationBeep();
                    showTestSuccess();
                }).catch(error => {
                    audioInitializing = false;
                    console.log('❌ Test sound failed:', error.message);
                    alert('⚠️ Could not play sound.\n\nPlease:\n1. Click somewhere else on the page first\n2. Then try the test button again\n\nThis is required by your browser\'s security policy.');
                });
            } else {
                audioInitialized = true;
                audioInitializing = false;
                console.log('✅ Test sound played successfully');
                createNotificationBeep();
                showTestSuccess();
            }
        } catch (error) {
            audioInitializing = false;
            console.log('❌ Test sound failed:', error.message);
            alert('⚠️ Could not initialize audio: ' + error.message);
        }
    } else if (audioInitialized) {
        // Already initialized, just play
        playChatNotification();
        showTestSuccess();
    } else {
        // Currently initializing
        console.log('⏳ Audio is initializing, please wait...');
    }
}

function showTestSuccess() {
    const testBtn = document.querySelector('.chat-sound-test-btn i');
    if (testBtn) {
        const originalClass = testBtn.className;
        testBtn.className = 'fas fa-check';
        setTimeout(() => {
            testBtn.className = originalClass;
        }, 1500);
    }
}

function toggleChat() {
    const chatWindow = document.getElementById('chatWindow');
    const isVisible = chatWindow.style.display !== 'none';
    
    if (isVisible) {
        chatWindow.style.display = 'none';
        stopChatPolling();
        stopConversationListPolling();
    } else {
        // Try to initialize audio when chat opens (if not already initialized)
        if (!audioInitialized && !audioInitializing) {
            audioInitializing = true;
            
            try {
                if (!audioContext) {
                    audioContext = new (window.AudioContext || window.webkitAudioContext)();
                }
                
                if (audioContext.state === 'suspended') {
                    audioContext.resume().then(() => {
                        audioInitialized = true;
                        audioInitializing = false;
                        console.log('✅ Audio initialized on chat open');
                    }).catch(e => {
                        audioInitializing = false;
                        console.log('⏳ Audio init requires user click first');
                    });
                } else {
                    audioInitialized = true;
                    audioInitializing = false;
                    console.log('✅ Audio initialized on chat open');
                }
            } catch (error) {
                audioInitializing = false;
                console.log('⏳ Audio init error:', error.message);
            }
        }
        
        chatWindow.style.display = 'block';
        loadConversations();
        startConversationListPolling(); // Start polling conversation list
        if (currentChatUser) {
            startChatPolling();
        }
    }
}

function minimizeChat() {
    document.getElementById('chatWindow').style.display = 'none';
    stopChatPolling();
    stopConversationListPolling();
}

function backToConversations() {
    document.getElementById('conversationList').style.display = 'flex';
    document.getElementById('chatMessagesView').style.display = 'none';
    document.getElementById('chatHeaderTitle').textContent = 'Messages';
    currentChatUser = null;
    lastMessageId = 0;
    stopChatPolling();
    loadConversations();
    startConversationListPolling(); // Resume polling when back to conversation list
}

function loadConversations() {
    fetch(CHAT_API_BASE + '?action=get_conversations', {
        credentials: 'same-origin'
    })
        .then(response => {
            // Silently ignore 403/401 errors - show empty list without logging
            if (response.status === 403 || response.status === 401) {
                return Promise.resolve({ success: true, conversations: [] });
            }
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data && data.success) {
                displayConversations(data.conversations);
            } else {
                console.error('API Error:', (data && data.message) || 'Failed to load conversations');
                if (data && data.migration_url) {
                    const container = document.getElementById('conversationListItems');
                    container.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>${data.message}</p>
                            <a href="${data.migration_url}" class="btn btn-primary btn-sm" target="_blank">Run Migration</a>
                        </div>
                    `;
                }
            }
        })
        .catch(error => {
            // Silently ignore 403/401 errors and fetch failures
            const errorMsg = error.message || error.toString() || '';
            const is403Error = errorMsg.includes('403') || errorMsg.includes('Forbidden');
            const is401Error = errorMsg.includes('401') || errorMsg.includes('Unauthorized');
            const isFetchError = errorMsg.includes('Failed to fetch') || errorMsg.includes('NetworkError');
            
            // Only log non-permission errors
            if (!is403Error && !is401Error && !isFetchError) {
                console.error('Error loading conversations:', error);
            }
        });
}

function displayConversations(conversations) {
    const container = document.getElementById('conversationListItems');
    
    if (conversations.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-comments"></i>
                <p>No conversations yet.<br>Click "New Chat" to start!</p>
            </div>
        `;
        return;
    }
    
    window.conversationDataMap.clear();
    container.innerHTML = conversations.map((conv, index) => {
        const initial = conv.name.charAt(0).toUpperCase();
        const avatarSrc = chatAvatarUrl(conv.avatar);
        const avatarHtml = avatarSrc 
            ? `<img src="${escapeHtml(avatarSrc)}" alt="${escapeHtml(conv.name)}">` 
            : escapeHtml(initial);
        const unreadBadge = conv.unread_count > 0 
            ? `<span class="conversation-badge">${conv.unread_count}</span>` 
            : '';
        const key = 'c' + index;
        window.conversationDataMap.set(key, conv);
        const cacheKey = String(conv.id) + '-' + (conv.type || '');
        const cachedLast = window.lastMessageCache && window.lastMessageCache[cacheKey];
        const apiLast = (conv.last_message != null && String(conv.last_message).trim() !== '') ? conv.last_message : null;
        const lastMsg = apiLast || (cachedLast != null && String(cachedLast).trim() !== '' ? cachedLast : null) || 'No messages yet';
        
        return `
            <div class="conversation-item" onclick='openChat(window.conversationDataMap.get("${key}"))' data-conv-id="${escapeHtml(String(conv.id))}" data-conv-type="${escapeHtml(conv.type || '')}">
                <div class="conversation-avatar">${avatarHtml}</div>
                <div class="conversation-info">
                    <div class="conversation-name">${escapeHtml(conv.name)}</div>
                    <div class="conversation-last-message">${escapeHtml(lastMsg)}</div>
                </div>
                ${unreadBadge}
            </div>
        `;
    }).join('');
}

function filterConversations(searchTerm) {
    const items = document.querySelectorAll('.conversation-item');
    const lowerSearch = searchTerm.toLowerCase();
    
    items.forEach(item => {
        const name = item.querySelector('.conversation-name').textContent.toLowerCase();
        item.style.display = name.includes(lowerSearch) ? 'flex' : 'none';
    });
}

function openChat(user) {
    currentChatUser = user;
    lastMessageId = 0;
    
    // Update header
    document.getElementById('chatHeaderTitle').textContent = user.name;
    document.getElementById('chatRecipientName').textContent = user.name;
    
    // Update avatar
    const avatarContainer = document.getElementById('chatRecipientAvatar');
    const initial = user.name.charAt(0).toUpperCase();
    const avatarSrc = chatAvatarUrl(user.avatar);
    if (avatarSrc) {
        avatarContainer.innerHTML = `<img src="${avatarSrc}" alt="${user.name}">`;
    } else {
        avatarContainer.innerHTML = initial;
    }
    
    // Switch view
    document.getElementById('conversationList').style.display = 'none';
    document.getElementById('chatMessagesView').style.display = 'flex';
    
    // Stop conversation list polling when viewing messages
    stopConversationListPolling();
    
    // Clear messages and load
    document.getElementById('chatMessages').innerHTML = '<div class="loading-state"><i class="fas fa-spinner fa-spin"></i> Loading messages...</div>';
    
    loadMessages();
    startChatPolling();
}

function loadMessages() {
    if (!currentChatUser) return;
    
    const isInitialLoad = lastMessageId === 0;
    
    fetch(CHAT_API_BASE + `?action=get_messages&receiver_id=${currentChatUser.id}&receiver_type=${currentChatUser.type}&last_id=${lastMessageId}`, {
        credentials: 'same-origin'
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.messages.length > 0) {
                    // On initial load, clear the container first
                    if (isInitialLoad) {
                        const container = document.getElementById('chatMessages');
                        container.innerHTML = '';
                    }
                    appendMessages(data.messages);
                    // Update lastMessageId to the highest message ID
                    const maxId = Math.max(...data.messages.map(m => parseInt(m.id)));
                    if (maxId > lastMessageId) {
                        lastMessageId = maxId;
                    }
                    // Sync last_message to conversation list (fixes "No messages yet" when API subquery returns null)
                    if (currentChatUser) {
                        const lastMsg = data.messages[data.messages.length - 1];
                        const msgText = (lastMsg && lastMsg.message != null && String(lastMsg.message).trim() !== '') ? lastMsg.message : 'No messages yet';
                        const cacheKey = String(currentChatUser.id) + '-' + (currentChatUser.type || '');
                        if (window.lastMessageCache) window.lastMessageCache[cacheKey] = msgText;
                        const item = document.querySelector('.conversation-item[data-conv-id="' + String(currentChatUser.id) + '"][data-conv-type="' + (currentChatUser.type || '') + '"]');
                        if (item) {
                            const el = item.querySelector('.conversation-last-message');
                            if (el) el.textContent = msgText;
                        }
                        window.conversationDataMap.forEach(function(c) {
                            if (c.id == currentChatUser.id && (c.type || '') === (currentChatUser.type || '')) {
                                c.last_message = msgText;
                            }
                        });
                    }
                } else if (isInitialLoad) {
                    // No messages at all on initial load
                    document.getElementById('chatMessages').innerHTML = `
                        <div class="no-messages">
                            <i class="fas fa-comments"></i>
                            <p>No messages yet. Start a conversation!</p>
                        </div>
                    `;
                }
                updateUnreadCount();
            } else {
                console.error('API Error:', (data && data.message) || 'Failed to load messages');
                if (isInitialLoad) {
                    document.getElementById('chatMessages').innerHTML = `
                        <div class="no-messages">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>Error loading messages. Please try again.</p>
                        </div>
                    `;
                }
            }
        })
        .catch(error => {
            console.error('Error loading messages:', error);
            if (isInitialLoad) {
                document.getElementById('chatMessages').innerHTML = `
                    <div class="no-messages">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Error loading messages. Please try again.</p>
                    </div>
                `;
            }
        });
}

function appendMessages(messages) {
    const container = document.getElementById('chatMessages');
    const wasEmpty = container.querySelector('.no-messages') || container.querySelector('.loading-state');
    
    // Clear empty/loading states
    if (wasEmpty) {
        container.innerHTML = '';
    }
    
    // Track existing message IDs to avoid duplicates
    const existingMessageIds = new Set();
    container.querySelectorAll('.chat-message').forEach(msgEl => {
        const msgId = msgEl.getAttribute('data-message-id');
        if (msgId) {
            existingMessageIds.add(msgId);
        }
    });
    
    let hasNewReceivedMessages = false;
    let addedCount = 0;
    
    messages.forEach(msg => {
        // Skip if message already exists (prevent duplicates)
        if (existingMessageIds.has(String(msg.id))) {
            return;
        }
        
        const isSent = msg.sender_type === <?php echo json_encode($chat_sender_type_for_compare); ?> && 
                       parseInt(msg.sender_id) === <?php echo (int)$chat_sender_id; ?>;
        const messageClass = isSent ? 'sent' : 'received';
        const time = new Date(msg.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        
        // Check if this is a new received message (not sent by current user)
        if (!isSent && !wasEmpty) {
            hasNewReceivedMessages = true;
        }
        
        // Ensure message is properly escaped to prevent HTML injection and image loading
        // Use textContent approach to completely prevent any HTML interpretation
        const messageText = String(msg.message || '').trim();
        
        // Create message element using DOM methods to ensure no HTML injection
        const messageDiv = document.createElement('div');
        messageDiv.className = `chat-message ${messageClass}`;
        messageDiv.setAttribute('data-message-id', msg.id);
        
        const contentDiv = document.createElement('div');
        contentDiv.className = 'message-content';
        
        const bubbleDiv = document.createElement('div');
        bubbleDiv.className = 'message-bubble';
        bubbleDiv.textContent = messageText; // Use textContent to prevent any HTML interpretation
        
        const timeDiv = document.createElement('div');
        timeDiv.className = 'message-time';
        timeDiv.textContent = time;
        
        contentDiv.appendChild(bubbleDiv);
        contentDiv.appendChild(timeDiv);
        messageDiv.appendChild(contentDiv);
        container.appendChild(messageDiv);
        addedCount++;
    });
    
    // Play sound if there are new received messages and chat is visible
    if (hasNewReceivedMessages && addedCount > 0) {
        const chatWindow = document.getElementById('chatWindow');
        const isChatOpen = chatWindow.style.display !== 'none';
        
        // Always play sound for new messages in active conversation
        if (isChatOpen && !document.hidden) {
            playChatNotification();
        }
    }
    
    // If there are more than 6 messages, make the container scrollable
    const messageCount = container.querySelectorAll('.chat-message').length;
    if (messageCount > 6) {
        container.classList.add('scrollable');
        // Scroll to bottom of the scrollable container
        setTimeout(() => {
            container.scrollTop = container.scrollHeight;
        }, 100);
    } else {
        container.classList.remove('scrollable');
        setTimeout(() => {
            container.scrollTop = container.scrollHeight; // keep view at bottom
        }, 100);
    }
}

function sendMessage() {
    if (!currentChatUser) return;
    
    const input = document.getElementById('chatMessageInput');
    const message = input.value.trim();
    
    if (!message) return;
    
    const formData = new FormData();
    formData.append('action', 'send_message');
    formData.append('message', message);
    formData.append('receiver_id', currentChatUser.id);
    formData.append('receiver_type', currentChatUser.type);
    
    fetch(CHAT_API_BASE, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            input.value = '';
            input.style.height = 'auto';
            document.getElementById('sendMessageBtn').disabled = true;
            loadMessages();
        } else {
            alert('Failed to send message: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error sending message:', error);
        alert('Failed to send message');
    });
}

function handleChatKeyPress(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        if (!document.getElementById('sendMessageBtn').disabled) {
            sendMessage();
        }
    }
}

function startChatPolling() {
    stopChatPolling();
    chatPollingInterval = setInterval(() => {
        if (currentChatUser) {
            loadMessages();
        }
    }, 3000); // Poll every 3 seconds
}

function stopChatPolling() {
    if (chatPollingInterval) {
        clearInterval(chatPollingInterval);
        chatPollingInterval = null;
    }
}

function startUnreadPolling() {
    updateUnreadCount();
    unreadPollingInterval = setInterval(updateUnreadCount, 10000); // Poll every 10 seconds
}

function startConversationListPolling() {
    stopConversationListPolling();
    // Poll conversation list every 15 seconds when visible
    conversationListPollingInterval = setInterval(() => {
        const conversationList = document.getElementById('conversationList');
        const chatMessagesView = document.getElementById('chatMessagesView');
        const chatWindow = document.getElementById('chatWindow');
        
        // Only refresh if chat window is open and conversation list is visible
        if (conversationList && chatWindow.style.display !== 'none' && 
            conversationList.style.display !== 'none' && 
            (!chatMessagesView || chatMessagesView.style.display === 'none')) {
            loadConversations();
        }
    }, 15000); // Poll every 15 seconds
}

function stopConversationListPolling() {
    if (conversationListPollingInterval) {
        clearInterval(conversationListPollingInterval);
        conversationListPollingInterval = null;
    }
}

function updateUnreadCount() {
    fetch(CHAT_API_BASE + '?action=get_unread_count', {
        credentials: 'same-origin'
    })
        .then(response => {
            // Silently ignore 403/401 errors - these are expected for unauthenticated users
            if (response.status === 403 || response.status === 401) {
                return Promise.resolve({ success: false, unread_count: 0 });
            }
            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }
            return response.json();
        })
        .catch(error => {
            // Silently handle network errors and other failures
            // Don't log to console to avoid cluttering with expected errors
            return { success: false, unread_count: 0 };
        })
        .then(data => {
            if (data.success) {
                const badge = document.getElementById('chatUnreadBadge');
                const currentCount = data.count;
                const chatWindow = document.getElementById('chatWindow');
                const isChatOpen = chatWindow.style.display !== 'none';
                const conversationList = document.getElementById('conversationList');
                const chatMessagesView = document.getElementById('chatMessagesView');
                const isConversationListVisible = conversationList && conversationList.style.display !== 'none';
                
                // Check if new messages arrived
                const hasNewMessages = currentCount > previousUnreadCount && previousUnreadCount >= 0;
                
                // Play sound if unread count increased (new message received)
                // Only play if chat is closed or minimized
                if (hasNewMessages) {
                    if (!isChatOpen || document.hidden) {
                        playChatNotification();
                    }
                    
                    // If chat is open and conversation list is visible, refresh it to show new chats/counts
                    if (isChatOpen && isConversationListVisible) {
                        loadConversations();
                    }
                }
                
                previousUnreadCount = currentCount;
                
                if (currentCount > 0) {
                    badge.textContent = currentCount > 99 ? '99+' : currentCount;
                    badge.style.display = 'block';
                } else {
                    badge.style.display = 'none';
                }
            }
        })
        .catch(error => {
            // Silently ignore 403/401 errors and fetch failures
            const errorMsg = error.message || error.toString() || '';
            const is403Error = errorMsg.includes('403') || errorMsg.includes('Forbidden');
            const is401Error = errorMsg.includes('401') || errorMsg.includes('Unauthorized');
            const isFetchError = errorMsg.includes('Failed to fetch') || errorMsg.includes('NetworkError');
            
            // Only log non-permission errors
            if (!is403Error && !is401Error && !isFetchError) {
                console.error('Error updating unread count:', error);
            }
        });
}

function showNewChatModal() {
    const modal = document.getElementById('newChatModal');
    const modalContent = modal.querySelector('.chat-modal-content');
    const closeBtn = modal.querySelector('.chat-modal-close');
    
    modal.style.display = 'flex';
    
    // Ensure close button is always clickable with highest priority
    if (closeBtn) {
        // Force styles to ensure button is clickable
        closeBtn.style.pointerEvents = 'auto';
        closeBtn.style.zIndex = '10004';
        closeBtn.style.position = 'relative';
        closeBtn.style.cursor = 'pointer';
        closeBtn.style.touchAction = 'manipulation';
        closeBtn.style.padding = '0';
        
        // Ensure onclick handler works - add as backup if inline handler fails
        const ensureCloseHandler = function(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            closeNewChatModal();
            return false;
        };
        
        // Remove old listeners to prevent duplicates
        const newHandler = ensureCloseHandler.bind(null);
        closeBtn.removeEventListener('click', newHandler);
        closeBtn.removeEventListener('touchend', newHandler);
        
        // Add click handler as backup
        closeBtn.addEventListener('click', ensureCloseHandler, { capture: true });
        
        // Add touch handler for mobile
        closeBtn.addEventListener('touchend', ensureCloseHandler, { capture: true, passive: false });
    }
    
    // Prevent clicks on modal content from closing the modal
    if (modalContent) {
        const stopPropagation = function(e) {
            e.stopPropagation();
        };
        modalContent.removeEventListener('click', stopPropagation);
        modalContent.addEventListener('click', stopPropagation);
    }
    
    // Close modal when clicking on backdrop (only if clicking directly on backdrop, not content)
    const backdropHandler = function(e) {
        if (e.target === modal && !modalContent.contains(e.target)) {
            closeNewChatModal();
        }
    };
    modal.removeEventListener('click', backdropHandler);
    modal.addEventListener('click', backdropHandler);
    
    loadUserList();
}

function closeNewChatModal() {
    const modal = document.getElementById('newChatModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function loadUserList() {
    const userType = <?php echo json_encode($_SESSION['user_type'] ?? ''); ?>;
    
    if (userType === 'faculty' || userType === 'staff') {
        // Faculty and staff can only chat with admins
        fetch(CHAT_API_BASE + '?action=get_admin_list', {
            credentials: 'same-origin'
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const users = data.admins || [];
                    displayUserList(users);
                    // Sync bubble green dot from admin list (ensures dot shows on mobile if stream delayed)
                    const onlineCount = users.filter(u => u.is_online).length;
                    const dot = document.getElementById('chatOnlineDot');
                    if (dot) {
                        dot.style.display = onlineCount > 0 ? 'block' : 'none';
                    }
                }
            })
            .catch(error => console.error('Error loading users:', error));
    } else if (userType === 'admin' || userType === 'super_admin') {
        // Admins and super_admins need to load both faculty and staff
        Promise.all([
            fetch(CHAT_API_BASE + '?action=get_faculty_list', { credentials: 'same-origin' }).then(res => res.json()),
            fetch(CHAT_API_BASE + '?action=get_staff_list', { credentials: 'same-origin' }).then(res => res.json())
        ])
        .then(([facultyData, staffData]) => {
            const faculty = facultyData.success ? (facultyData.faculty || []) : [];
            const staff = staffData.success ? (staffData.staff || []) : [];
            
            // Combine and sort both lists by name
            const allUsers = [...faculty, ...staff].sort((a, b) => 
                a.name.localeCompare(b.name)
            );
            
            displayUserList(allUsers);
        })
        .catch(error => console.error('Error loading users:', error));
    }
}

function displayUserList(users) {
    const container = document.getElementById('userList');
    const userType = <?php echo json_encode($_SESSION['user_type'] ?? ''); ?>;
    
    if (users.length === 0) {
        container.innerHTML = '<div class="empty-state"><p>No users found</p></div>';
        return;
    }
    
    window.userDataMap.clear();
    container.innerHTML = users.map((user, index) => {
        const initial = user.name.charAt(0).toUpperCase();
        const avatarSrc = chatAvatarUrl(user.avatar);
        const avatarHtml = avatarSrc 
            ? `<img src="${escapeHtml(avatarSrc)}" alt="${escapeHtml(user.name)}">` 
            : escapeHtml(initial);
        const onlineDot = (userType === 'faculty' || userType === 'staff') && user.is_online
            ? '<span class="user-online-dot" title="Online"></span>'
            : '';
        const key = 'u' + index;
        window.userDataMap.set(key, user);
        
        return `
            <div class="user-item" onclick='startChatWithUser(window.userDataMap.get("${key}"))'>
                <div class="user-avatar-wrap">
                    <div class="user-avatar">${avatarHtml}</div>
                    ${onlineDot}
                </div>
                <div class="user-name">${escapeHtml(user.name)}</div>
            </div>
        `;
    }).join('');
}

function updateOnlineAdminDot() {
    const userType = <?php echo json_encode($_SESSION['user_type'] ?? ''); ?>;
    if (userType !== 'faculty' && userType !== 'staff') return;
    
    fetch(CHAT_API_BASE + '?action=get_online_admin_count', { credentials: 'same-origin' })
        .then(res => res.json())
        .then(data => {
            const dot = document.getElementById('chatOnlineDot');
            if (dot && data.success) {
                dot.style.display = (data.count > 0) ? 'block' : 'none';
            }
        })
        .catch(() => {});
}

function filterUsers(searchTerm) {
    const items = document.querySelectorAll('.user-item');
    const lowerSearch = searchTerm.toLowerCase();
    
    items.forEach(item => {
        const name = item.querySelector('.user-name').textContent.toLowerCase();
        item.style.display = name.includes(lowerSearch) ? 'flex' : 'none';
    });
}

function startChatWithUser(user) {
    closeNewChatModal();
    openChat(user);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Clean up on page unload
window.addEventListener('beforeunload', function() {
    stopChatPolling();
    stopConversationListPolling();
    if (unreadPollingInterval) {
        clearInterval(unreadPollingInterval);
    }
    if (onlineAdminPollingInterval) {
        clearInterval(onlineAdminPollingInterval);
    }
});
</script>
