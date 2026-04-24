jQuery(document).on('click', '.llms-vk-banner .notice-dismiss', function () {
    jQuery.post(llmsNoticeAjax.ajax_url, {
        action: 'dismiss_llms_vk_banner',
        nonce: llmsNoticeAjax.nonce
    });
});
