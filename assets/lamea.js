const lameaScriptSrc = document.currentScript ? document.currentScript.src : "";
const lameaAssetsBase = lameaScriptSrc ? new URL(".", lameaScriptSrc).href : "../assets/";
const lameaAvatarUrl = new URL("Images/Lame3.jpg", lameaAssetsBase).href;

document.addEventListener("DOMContentLoaded", function () {

    const currentLang = localStorage.getItem('app_lang') || 'ar';
    

    const chatbotLexicon = {
        ar: {
            title: "لامع - مساعد ذكي",
            status: "متصل الآن • Online",
            welcome: "أهلاً بك في كليفو! كيف يمكنني مساعدتك اليوم؟ ✨",
            placeholder: "اكتب رسالتك هنا..."
        },
        en: {
            title: "Lame3 - Smart Assistant",
            status: "Online Now",
            welcome: "Welcome to Clevo! How can I help you today? ✨",
            placeholder: "Type your message here..."
        }
    };


    const botTitle = chatbotLexicon[currentLang].title;
    const botStatus = chatbotLexicon[currentLang].status;
    const botWelcome = chatbotLexicon[currentLang].welcome;
    const botPlaceholder = chatbotLexicon[currentLang].placeholder;

    if (!document.getElementById("lamea-chat-style")) {
        const style = document.createElement("style");
        style.id = "lamea-chat-style";
        style.textContent = `

            .lamea-chat-wrapper{position:fixed;bottom:22px;z-index:3000}
            

            html[dir="rtl"] .lamea-chat-wrapper{left:22px; right: auto;}
            html[dir="rtl"] .chat-window{left:0; right: auto;}
            

            html[dir="ltr"] .lamea-chat-wrapper{right:22px; left: auto;}
            html[dir="ltr"] .chat-window{right:0; left: auto;}
            
            .lamea-avatar-btn{width:64px;height:64px;border-radius:50%;overflow:hidden;cursor:pointer;border:3px solid #fff;background:#0ea5e9}
            .lamea-avatar-img{width:100%;height:100%;object-fit:cover}
            .chat-window{display:none;position:absolute;bottom:78px;width:min(330px,calc(100vw - 32px));height:430px;background:#fff;color:#0f172a;border-radius:22px;overflow:hidden;flex-direction:column;border:1px solid rgba(15,23,42,.12)}
            
            [data-theme="dark"] .chat-window{background:#0f172a;color:#f8fafc;border-color:#334155}
            .chat-header{background:#0ea5e9;color:#fff;padding:12px 14px;display:flex;align-items:center}
            .lamea-header-img{width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid #fff}
            .chat-body{flex:1;padding:14px;overflow-y:auto;display:flex;flex-direction:column;gap:10px}
            .msg-bubble{max-width:82%;padding:10px 12px;border-radius:16px;font-size:.88rem;line-height:1.5}
            .bot-msg{align-self:flex-start;background:#eef6ff;color:#0f172a}
            .user-msg{align-self:flex-end;background:#0ea5e9;color:#fff}
            [data-theme="dark"] .bot-msg{background:#1e293b;color:#f8fafc}
            .chat-footer{display:flex;gap:8px;padding:10px;border-top:1px solid rgba(15,23,42,.1)}
            [data-theme="dark"] .chat-footer{border-color:#334155}
            #chatInput{flex:1;border:1px solid #dbeafe;border-radius:999px;padding:10px 12px;outline:none;background:#fff;color:#0f172a}
            [data-theme="dark"] #chatInput{background:#020617;color:#fff;border-color:#334155}
            .chat-btn{width:40px;height:40px;border-radius:50%;border:0;display:flex;align-items:center;justify-content:center;background:#0ea5e9;color:#fff}
            .voice-btn.recording{background:#ef4444}
            .typing-indicator span{animation:lameaBlink 1s infinite}.typing-indicator span:nth-child(2){animation-delay:.15s}.typing-indicator span:nth-child(3){animation-delay:.3s}
            @keyframes lameaBlink{50%{opacity:.2}}
        `;
        document.head.appendChild(style);
    }


    const chatHTML = `
    <div class="lamea-chat-wrapper">
        <div class="lamea-avatar-btn shadow-lg" onclick="toggleChatWindow()">
            <img src="${lameaAvatarUrl}" class="lamea-avatar-img" alt="Lamea Avatar">
        </div>

        <div class="chat-window shadow-2xl" id="chatWindow">
            <div class="chat-header">
                <div class="d-flex align-items-center gap-2">
                    <img src="${lameaAvatarUrl}" class="lamea-header-img" alt="Bot">
                    <div class="d-flex flex-column">
                        <span class="fw-bold" style="font-size: 0.95rem;">${botTitle}</span>
                        <small style="font-size: 0.7rem; opacity: 0.8;">${botStatus}</small>
                    </div>
                </div>
                <button class="btn-close btn-close-white ms-auto" onclick="toggleChatWindow()" style="font-size: 0.7rem Dino;"></button>
            </div>
            
            <div class="chat-body" id="chatBody">
                <div class="msg-bubble bot-msg">
                    <span>${botWelcome}</span>
                </div>
            </div>

            <div class="chat-footer">
                <button class="chat-btn voice-btn" id="voiceBtn" onclick="toggleVoice()">
                    <i class="fas fa-microphone"></i>
                </button>
                <input type="text" id="chatInput" placeholder="${botPlaceholder}">
                <button class="chat-btn send-btn" onclick="sendMessage()">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>`;

    document.body.insertAdjacentHTML("beforeend", chatHTML);


    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && document.activeElement.id === 'chatInput') {
            e.preventDefault(); 
            sendMessage();
        }
    });
});


function toggleChatWindow() {
    const win = document.getElementById('chatWindow');
    if (win) {
        const isHidden = win.style.display === 'none' || win.style.display === '';
        win.style.display = isHidden ? 'flex' : 'none';
        if (isHidden) {
            document.getElementById('chatInput').focus();
            const body = document.getElementById('chatBody');
            body.scrollTop = body.scrollHeight;
        }
    }
}


async function sendMessage() {
    const input = document.getElementById('chatInput');
    const body = document.getElementById('chatBody');
    const message = input.value.trim();

    if (message !== "") {

        const userDiv = document.createElement('div');
        userDiv.className = 'msg-bubble user-msg';
        userDiv.innerText = message;
        body.appendChild(userDiv);

        input.value = ""; 
        body.scrollTop = body.scrollHeight;


        const typingDiv = document.createElement('div');
        typingDiv.className = 'msg-bubble bot-msg typing-indicator';
        typingDiv.innerHTML = '<span>.</span><span>.</span><span>.</span>';
        body.appendChild(typingDiv);
        body.scrollTop = body.scrollHeight;

        try {
            const response = await fetch("../includes/assistant.php", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": window.clevoCsrfToken || "" },
                body: JSON.stringify({
                    message: message,
                    session: "user-clevo-" + (localStorage.getItem('userID') || localStorage.getItem('user_id') || 'guest')
                })
            });

            const data = await response.json();
            typingDiv.remove(); 

            const botDiv = document.createElement('div');
            botDiv.className = 'msg-bubble bot-msg';
            botDiv.innerText = data.reply || data.output || (localStorage.getItem('app_lang') === 'en' ? "Sorry, Lamea is currently busy." : "عذراً، لامع مشغول حالياً.");
            body.appendChild(botDiv);
            body.scrollTop = body.scrollHeight;

        } catch (error) {
            typingDiv.innerText = localStorage.getItem('app_lang') === 'en' ? "Connection error occurred." : "حدث خطأ في الاتصال.";
        }
    }
}


let recognition;
function saveLameaTranscript(text) {
    try {
        const key = 'lamea_transcripts';
        const list = JSON.parse(localStorage.getItem(key) || '[]');
        list.push({ text: String(text || ''), ts: new Date().toISOString() });
        localStorage.setItem(key, JSON.stringify(list));
    } catch (e) {
        console.error('saveLameaTranscript error', e);
    }
}

function downloadLameaTranscripts() {
    try {
        const key = 'lamea_transcripts';
        const list = JSON.parse(localStorage.getItem(key) || '[]');
        if (!list || !list.length) return;
        const content = list.map(i => `[${i.ts}] ${i.text}`).join("\n\n");
        const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'lamea_transcripts.txt';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch (e) {
        console.error('downloadLameaTranscripts error', e);
    }
}

function toggleVoice() {
    if (!('webkitSpeechRecognition' in window)) {
        const body = document.getElementById('chatBody');
        if (body) {
            const botDiv = document.createElement('div');
            botDiv.className = 'msg-bubble bot-msg';
            botDiv.innerText = localStorage.getItem('app_lang') === 'en'
                ? "Your browser does not support voice input."
                : "متصفحك لا يدعم خاصية الصوت.";
            body.appendChild(botDiv);
            body.scrollTop = body.scrollHeight;
        }
        return;
    }

    if (!recognition) {
        recognition = new webkitSpeechRecognition();
        const lang = localStorage.getItem('app_lang') || 'ar';
        recognition.lang = (lang === 'ar' ? 'ar-SA' : 'en-US');
        recognition.continuous = false; 
        recognition.interimResults = false;

        recognition.onstart = () => {
            document.getElementById('voiceBtn').classList.add('recording');
        };

        recognition.onresult = (event) => {
            const result = event.results[0][0].transcript;
            const input = document.getElementById('chatInput');
            input.value = result;
            input.focus();
            document.getElementById('voiceBtn').classList.remove('recording');
        };

        recognition.onerror = () => {
            document.getElementById('voiceBtn').classList.remove('recording');
        };

        recognition.onend = () => {
            document.getElementById('voiceBtn').classList.remove('recording');
        };
    }

    recognition.start();
}
