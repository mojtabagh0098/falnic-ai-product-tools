jQuery(document).ready(function($) {
    $('#generate_ai_content').on('click', function() {
        // نمایش پیام لودینگ
        $('#ai_generated_content').html('<p>لطفاً صبر کنید... محتوا در حال تولید است...</p>').show();

        // ارسال درخواست Ajax
        $.ajax({
            url: wcAiGenerator.ajax_url,
            method: 'POST',
            data: {
                action: 'wc_generate_ai_description',
                nonce: wcAiGenerator.nonce,
                product_name: $('input#title').val(), // دریافت عنوان محصول
            },
            success: function(response) {
                if (response.success) {
                    $('#ai_generated_content').html('<textarea style="width: 100%; height: 150px;">' + response.data + '</textarea>');
                } else {
                    $('#ai_generated_content').html('<p style="color: red;">خطایی رخ داده است: ' + response.data + '</p>');
                }
            },
            error: function() {
                $('#ai_generated_content').html('<p style="color: red;">یک خطای غیرمنتظره رخ داده است.</p>');
            }
        });
    });
});