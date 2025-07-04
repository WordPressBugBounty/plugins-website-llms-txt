jQuery(document).on('click', '.llms-admin-notice .notice-dismiss', function () {
    jQuery.post(llmsNoticeAjax.ajax_url, {
        action: 'dismiss_llms_admin_notice',
        nonce: llmsNoticeAjax.nonce
    });
});

jQuery(document).on('click', '.llms-ai-banner .notice-dismiss', function () {
    jQuery.post(llmsNoticeAjax.ajax_url, {
        action: 'dismiss_llms_ai_banner_dismissed',
        nonce: llmsNoticeAjax.nonce
    });
});