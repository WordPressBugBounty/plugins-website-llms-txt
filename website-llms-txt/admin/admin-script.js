jQuery(document).ready(function($) {
    const $sortable = $("#llms-post-types-sortable");
    const $form = $("#llms-settings-form");

    // Initialize sortable
    $sortable.sortable({
        items: '.sortable-item',
        axis: 'y',
        cursor: 'move',
        handle: 'label',
        update: function(event, ui) {
            updateActiveStates();
        }
    });

    // Handle checkbox changes
    $sortable.on('change', 'input[type="checkbox"]', function() {
        $(this).closest('.sortable-item').toggleClass('active', $(this).is(':checked'));
    });

    // Update active states
    function updateActiveStates() {
        $sortable.find('.sortable-item').each(function() {
            const $item = $(this);
            const $checkbox = $item.find('input[type="checkbox"]');
            $item.toggleClass('active', $checkbox.is(':checked'));
        });
    }

    // Ensure proper order on form submission
    $form.on('submit', function() {
        // Move unchecked items to the end
        $sortable.find('.sortable-item:not(.active)').appendTo($sortable);
        return true;
    });

    let queueId = null;
    let running = false;

    function setProgress(done, total, $txt, $bar){
        const pct = total ? Math.round(done/total*100) : 0;
        $bar.css('width', pct+'%');
        $txt.text(`${done} / ${total} (${pct}%)`);
    }

    function step( $txt, $bar ){
        if(!running) return;
        $.post(ajaxurl, {
            action: 'llms_gen_step',
            queue_id: queueId,
            _wpnonce: LLMS_GEN.nonce
        }).done(function(r){
            if(!r || r.success === false){
                running = false; $txt.text(r && r.data ? r.data : 'Error'); return;
            }
            setProgress(r.data.done, r.data.total, $txt, $bar);
            if(r.data.done < r.data.total) {
                setTimeout(() => step($txt, $bar), 150);
            } else {
                running = false;
                $txt.text('Done ✓');
                $.post(ajaxurl, {
                    action:'llms_update_file',
                    _wpnonce: LLMS_GEN.nonce
                }).done(function(){
                    window.location.reload();
                });
            }
        }).fail(function(){
            running = false; $txt.text('Request failed');
        });
    }

    $('#llms-generate-now').on('click', function(e){
        e.preventDefault();
        if(running) return;
        $('#llms-progress').show();
        const $bar  = $('#llms-progress-bar');
        const $txt  = $('#llms-progress-text');
        setProgress(0,0, $txt, $bar);
        $txt.text('Initializing…');

        $.post(ajaxurl, {
            action: 'llms_gen_init',
            _wpnonce: LLMS_GEN.nonce
        }).done(function(r){
            if(!r || r.success === false){
                $txt.text(r && r.data ? r.data : 'Init error'); return;
            }
            queueId = r.data.queue_id; running = true;
            setProgress(0, r.data.total, $txt, $bar);
            step( $txt, $bar );
        })
        .fail(function(){ $txt.text('Init request failed'); });
    });

    $('#llms-delete-and-recreate').on('click', function(e){
        e.preventDefault();
        if(running) return;
        $('#llms-reset-progress').show();
        const $bar  = $('#llms-reset-progress-bar');
        const $txt  = $('#llms-reset-progress-text');
        setProgress(0,0, $txt, $bar);
        $txt.text('Initializing…');

        $.post(ajaxurl, {
            action: 'run_llms_txt_reset_file',
            _wpnonce: LLMS_GEN.nonce
        }).done(function(r){
            if(!r || r.success === false){
                $txt.text(r && r.data ? r.data : 'Init error'); return;
            }
            queueId = r.data.queue_id; running = true;
            setProgress(0, r.data.total, $txt, $bar);
            step( $txt, $bar );
        })
        .fail(function(){ $txt.text('Init request failed'); });
    });

    // --- Visibility Kit Connect / Disconnect ---

    var btConnectBtn = document.getElementById('vk-connect-btn');
    var btDisconnectBtn = document.getElementById('vk-disconnect-btn');

    function btShowStatus(msg, type) {
        var el = document.getElementById('vk-connect-status');
        if (!el) return;
        if (!msg) { el.style.display = 'none'; return; }
        el.style.display = 'block';
        el.style.color = type === 'error' ? '#dc2626' : '#16a34a';
        el.textContent = msg;
    }

    function btResetConnectBtn() {
        btConnectBtn.disabled = false;
        btConnectBtn.textContent = 'Start tracking';
    }

    function btRenderTakeoverPrompt(email, message) {
        var el = document.getElementById('vk-connect-status');
        if (!el) return;
        el.style.display = 'block';
        el.style.color = '#475569';
        el.textContent = '';

        var msg = document.createElement('p');
        msg.style.margin = '0 0 8px';
        msg.textContent = message || 'This domain is already connected under a different email.';
        el.appendChild(msg);

        var takeBtn = document.createElement('button');
        takeBtn.type = 'button';
        takeBtn.className = 'button vk-btn-primary';
        takeBtn.style.marginRight = '6px';
        takeBtn.textContent = 'Take it over with this email';

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'button';
        cancelBtn.textContent = 'Cancel';

        takeBtn.addEventListener('click', function() {
            takeBtn.disabled = true;
            cancelBtn.disabled = true;
            takeBtn.textContent = 'Taking over...';
            btSubmitConnect(email, true);
        });
        cancelBtn.addEventListener('click', function() {
            btShowStatus('', '');
            btResetConnectBtn();
        });

        el.appendChild(takeBtn);
        el.appendChild(cancelBtn);
    }

    function btSubmitConnect(email, takeover) {
        var formData = new FormData();
        formData.append('action', 'vk_connect');
        formData.append('email', email);
        formData.append('_wpnonce', LLMS_VK.nonce);
        if (takeover) {
            formData.append('takeover', '1');
        }

        fetch(ajaxurl, {
            method: 'POST',
            body: formData,
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                var msg = (data.data && data.data.message) || 'Connected.';
                btShowStatus(msg, 'success');
                setTimeout(function() { location.reload(); }, 1500);
                return;
            }

            var payload = data.data || {};
            if (payload && typeof payload === 'object' && payload.canTakeover) {
                btRenderTakeoverPrompt(email, payload.message);
                return;
            }

            var errMsg = typeof payload === 'string'
                ? payload
                : (payload.message || 'Connection failed. Please try again.');
            btShowStatus(errMsg, 'error');
            btResetConnectBtn();
        })
        .catch(function() {
            btShowStatus('Connection failed. Please try again.', 'error');
            btResetConnectBtn();
        });
    }

    function btStartConnect() {
        var emailInput = document.getElementById('vk-connect-email');
        if (!emailInput) return;
        var email = emailInput.value.trim();
        if (!email) {
            btShowStatus('Please enter your email address.', 'error');
            return;
        }
        if (email.indexOf('@') === -1) {
            btShowStatus('Please enter a valid email address.', 'error');
            return;
        }

        btConnectBtn.disabled = true;
        btConnectBtn.textContent = 'Connecting...';
        btShowStatus('', '');
        btSubmitConnect(email, false);
    }

    if (btConnectBtn) {
        btConnectBtn.addEventListener('click', btStartConnect);

        var vkEmailInput = document.getElementById('vk-connect-email');
        if (vkEmailInput) {
            vkEmailInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.keyCode === 13) {
                    e.preventDefault();
                    btStartConnect();
                }
            });
        }
    }

    if (btDisconnectBtn) {
        btDisconnectBtn.addEventListener('click', function() {
            if (!confirm('Disconnect from Visibility Kit? This will stop AI referral tracking and remove the tracking script. Your dashboard data will still be available at visibilitykit.ai.')) {
                return;
            }

            var formData = new FormData();
            formData.append('action', 'vk_disconnect');
            formData.append('_wpnonce', LLMS_VK.nonce);

            fetch(ajaxurl, {
                method: 'POST',
                body: formData,
            })
            .then(function() { location.reload(); })
            .catch(function() { location.reload(); });
        });
    }
});
