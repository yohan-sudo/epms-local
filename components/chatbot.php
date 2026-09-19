<?php
/**
 * U EPMS - Floating Chatbot Widget (v2.3)
 * Loaded on every authenticated page via footer.php.
 * Asks api_chatbot.php; answers are role-scoped server-side.
 */
if (empty($currentUserId)) {
    return; // guests get nothing
}
$chatGreeting = ($currentUserRole ?? 'Guest') === 'CEO'
    ? 'Ask me anything about the whole system: "overview", "spend", "revenue", "production", "inventory", "attendance"...'
    : 'Ask me what needs your attention - reminders of your pending approvals and notifications.';
?>
<!-- v2.3 Chatbot widget -->
<div id="chat-widget" style="position:fixed; bottom:88px; right:20px; z-index:1400;">
    <div id="chat-panel" style="display:none; width:340px; height:460px; background:#fff; border:1px solid var(--border, #e2e8f0); border-radius:14px; box-shadow:0 12px 48px rgba(0,0,0,.18); overflow:hidden; flex-direction:column;">
        <div style="background:var(--primary, #2563eb); color:#fff; padding:10px 14px; display:flex; justify-content:space-between; align-items:center;">
            <strong style="font-size:13px;">&#129302; EPMS Assistant</strong>
            <button type="button" id="chat-close" style="background:none; border:none; color:#fff; cursor:pointer; font-size:15px;">&times;</button>
        </div>
        <div id="chat-log" style="flex:1; overflow-y:auto; padding:12px; display:flex; flex-direction:column; gap:8px; background:#f8fafc;">
            <div style="align-self:flex-start; background:#fff; border:1px solid var(--border,#e2e8f0); border-radius:10px 10px 10px 2px; padding:8px 11px; font-size:12px; max-width:85%; line-height:1.45;">
                <?= htmlspecialchars($chatGreeting) ?>
            </div>
        </div>
        <form id="chat-form" style="display:flex; gap:6px; padding:10px; border-top:1px solid var(--border,#e2e8f0); background:#fff;">
            <input id="chat-input" autocomplete="off" placeholder="Type a question..." maxlength="200"
                   style="flex:1; border:1px solid var(--border,#e2e8f0); border-radius:8px; padding:7px 10px; font-size:12px; outline:none;">
            <button type="submit" style="background:var(--primary,#2563eb); color:#fff; border:none; border-radius:8px; padding:7px 12px; font-size:12px; font-weight:700; cursor:pointer;">Send</button>
        </form>
    </div>
    <button type="button" id="chat-toggle" title="EPMS Assistant"
            style="width:52px; height:52px; border-radius:50%; background:var(--primary,#2563eb); color:#fff; border:none; cursor:pointer; font-size:22px; box-shadow:0 6px 20px rgba(37,99,235,.4); display:flex; align-items:center; justify-content:center;">
        &#128172;
    </button>
</div>
<script>
(function () {
    var toggle = document.getElementById('chat-toggle');
    var panel  = document.getElementById('chat-panel');
    var close  = document.getElementById('chat-close');
    var form   = document.getElementById('chat-form');
    var input  = document.getElementById('chat-input');
    var log    = document.getElementById('chat-log');
    if (!toggle || !panel) return;

    function addBubble(text, who) {
        var b = document.createElement('div');
        b.style.whiteSpace = 'pre-line';
        if (who === 'me') {
            b.style.cssText += 'align-self:flex-end; background:var(--primary,#2563eb); color:#fff; border-radius:10px 10px 2px 10px; padding:8px 11px; font-size:12px; max-width:85%; line-height:1.45;';
        } else {
            b.style.cssText += 'align-self:flex-start; background:#fff; border:1px solid var(--border,#e2e8f0); border-radius:10px 10px 10px 2px; padding:8px 11px; font-size:12px; max-width:85%; line-height:1.45;';
        }
        b.textContent = text;
        log.appendChild(b);
        log.scrollTop = log.scrollHeight;
    }

    toggle.addEventListener('click', function () {
        var open = panel.style.display === 'flex';
        panel.style.display = open ? 'none' : 'flex';
        if (!open) { input.focus(); }
    });
    close.addEventListener('click', function () { panel.style.display = 'none'; });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var msg = input.value.trim();
        if (!msg) return;
        addBubble(msg, 'me');
        input.value = '';
        var thinking = document.createElement('div');
        thinking.style.cssText = 'align-self:flex-start; color:#94a3b8; font-size:11px; padding:4px;';
        thinking.textContent = '...';
        log.appendChild(thinking);
        log.scrollTop = log.scrollHeight;

        fetch('/api_chatbot.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: msg })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            thinking.remove();
            addBubble((d && d.reply) ? d.reply : 'Sorry, I did not understand that.', 'bot');
        })
        .catch(function () {
            thinking.remove();
            addBubble('I could not reach the system just now. Please try again.', 'bot');
        });
    });
})();
</script>
