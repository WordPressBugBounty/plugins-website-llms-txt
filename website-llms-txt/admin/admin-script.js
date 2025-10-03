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

    const $btn  = $('#llms-generate-now');
    const $wrap = $('#llms-progress');
    const $bar  = $('#llms-progress-bar');
    const $txt  = $('#llms-progress-text');

    let queueId = null;
    let running = false;

    function setProgress(done, total){
        const pct = total ? Math.round(done/total*100) : 0;
        $bar.css('width', pct+'%');
        $txt.text(`${done} / ${total} (${pct}%)`);
    }

    function step(){
        if(!running) return;
        $.post(ajaxurl, {
            action: 'llms_gen_step',
            queue_id: queueId,
            _wpnonce: LLMS_GEN.nonce
        }).done(function(r){
            if(!r || r.success === false){
                running = false; $txt.text(r && r.data ? r.data : 'Error'); return;
            }
            setProgress(r.data.done, r.data.total);
            if(r.data.done < r.data.total) {
                setTimeout(step, 150);
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

    $btn.on('click', function(e){
        e.preventDefault();
        if(running) return;
        $wrap.show();
        setProgress(0,0);
        $txt.text('Initializing…');

        $.post(ajaxurl, {
            action: 'llms_gen_init',
            _wpnonce: LLMS_GEN.nonce
        }).done(function(r){
            if(!r || r.success === false){
                $txt.text(r && r.data ? r.data : 'Init error'); return;
            }
            queueId = r.data.queue_id; running = true;
            setProgress(0, r.data.total);
            step();
        })
        .fail(function(){ $txt.text('Init request failed'); });
    });
});