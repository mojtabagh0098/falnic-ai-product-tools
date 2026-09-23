<?php
class Review_Content_Generator
{

    // کلید API هوش مصنوعی را اینجا وارد کنید
    private $api_key = 'sk-qslrC2LFNooKAtGAAWfI7ZcAbB045Rftzt6vnrzqi4dZ8aJk';
    private $base_url = 'https://api.gapgpt.app/v1/responses';

    public function __construct()
    {
        add_action('media_buttons', [$this, 'add_button'], 20);
        add_action('admin_footer', [$this, 'render_modal_and_js']);
        add_action('wp_ajax_aipc_generate_action', [$this, 'handle_ajax_request']);
        add_action('http_api_curl', function ($handle, $r, $url) {
            if (strpos($url, 'gapgpt.app') !== false) {
                curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 30);
                // اگر مشکل IPv6 باشه (خیلی رایجه روی سرورهای WHM)، این هم کمک می‌کنه:
                curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            }
        }, 10, 3);
    }

    // ۱. اضافه کردن دکمه به بالای ادیتور محصول
    public function add_button($editor_id)
    {
        global $current_screen;
        if ($current_screen && $current_screen->post_type === 'product' && $editor_id === 'content') {
            echo '<button type="button" class="button button-primary" id="aipc-btn-review" style="margin-right:5px;">
                    <span class="dashicons dashicons-editor-code" style="margin-top:3px;"></span> تولید محتوای ریویو
                  </button>';
        }
    }

    // ۲. رندر کردن مودال، استخراج خودکار ویژگی‌ها و منطق جایگذاری پرامپت
    public function render_modal_and_js()
    {
        global $current_screen, $post;
        if (!$current_screen && $current_screen->post_type !== 'product') return;

        // --- استخراج خودکار ویژگی‌های ووکامرس بجز Server Config ---
        $product_features_text = '';
        if ($post && function_exists('wc_get_product')) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $attributes = $product->get_attributes();
                foreach ($attributes as $attribute) {
                    $attr_name = $attribute->get_name();
                    $attr_label = $attribute->is_taxonomy() ? wc_attribute_label($attr_name) : $attr_name;

                    if (!empty($attr_label) && strtolower(trim($attr_label)) === 'pa_server_config') {
                        continue;
                    }
                    if (!empty($attr_name) && strtolower(trim($attr_name)) === 'pa_server_config') {
                        continue;
                    }

                    if ($attribute->is_taxonomy()) {
                        $terms = wp_get_post_terms($post->ID, $attr_name, array('fields' => 'names'));
                        $attr_values = is_array($terms) ? implode('، ', $terms) : '';
                    } else {
                        $attr_values = implode('، ', $attribute->get_options());
                    }

                    if (!empty($attr_values)) {
                        $product_features_text .= $attr_label . ': ' . $attr_values . "\n";
                    }
                }
            }
        }
?>
        <div id="aipc-modal-review" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:999999; direction:rtl;">
            <div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); background:#fff; padding:20px; border-radius:6px; width:700px; max-width:95%; max-height:90vh; overflow-y:auto; box-shadow:0 4px 20px rgba(0,0,0,0.3); text-align:right;">

                <h3 style="margin-top:0; padding-bottom:12px; border-bottom:1px solid #eee; font-size:16px;">تنظیمات تولید محتوای ریویو محصول</h3>

                <div id="aipc-review-step-1">

                    <p>
                        <label><b>اسم محصول:</b></label>
                        <input type="text" id="aipc-review-input-title" style="width:100%; margin-top:5px;" placeholder="مثال: HP ProLiant DL380 Gen11">
                    </p>

                    <p style="margin-bottom:0;"><label><b>کلمات کلیدی:</b></label></p>
                    <div style="display:flex; gap:10px; margin-top:5px;">
                        <input type="text" id="aipc-review-input-kw1" style="width:33.3%;" placeholder="کلمه کلیدی اول">
                        <input type="text" id="aipc-review-input-kw2" style="width:33.3%;" placeholder="کلمه کلیدی دوم">
                        <input type="text" id="aipc-review-input-kw3" style="width:33.3%;" placeholder="کلمه کلیدی سوم">
                    </div>

                    <p style="margin-top:15px;">
                        <label><b>ویژگی‌های محصول (استخراج شده از ویژگی های محصول):</b></label>
                        <textarea id="aipc-review-input-features" style="width:100%; margin-top:5px; height:100px; background:#f9f9f9;" placeholder="ویژگی‌های محصول یافت نشد..."><?php echo esc_textarea($product_features_text); ?></textarea>
                    </p>


                    <!-- فیلد انتخاب مدل هوش مصنوعی -->
                    <p>
                        <label><b>انتخاب مدل هوش مصنوعی:</b></label>
                        <select id="aipc-review-input-model" style="width:100%; margin-top:5px; padding: 5px;">
                            <option value="gemini-3.1-flash-lite" selected>Gemini 3.1 Flash Lite (پیش‌فرض)</option>
                            <option value="gemini-2.5-pro">Gemini 2.5 Pro (پیشرفته و با کیفیت بالا)</option>
                            <option value="gemini-3.5-flash">Gemini 3.5 Flash (سریع)</option>
                            <option value="gpt-4o">GPT-4o (قدرتمند و هوشمند)</option>
                            <option value="gpt-4o-mini">GPT-4o Mini (اقتصادی و سریع)</option>
                            <option value="claude-3-5-haiku-20241022">Claude 3.5 Haiku</option>
                            <option value="claude-3-5-sonnet-20241022">Claude 3.5 Sonnet</option>
                        </select>
                    </p>

                    <div style="text-align:left; margin-top:20px;">
                        <button type="button" class="button" id="aipc-review-close">انصراف</button>
                        <button type="button" class="button button-primary" id="aipc-review-next">مرحله بعد (تولید پرامپت)</button>
                    </div>
                </div>

                <div id="aipc-review-step-2" style="display:none;">
                    <p><b>قالب پرامپت نهایی اختصاصی (در صورت نیاز ویرایش کنید):</b></p>
                    <?php
                    wp_editor('', 'aipc_review_prompt_editor', [
                        'textarea_rows' => 22,
                        'media_buttons' => false,
                        'quicktags'     => false,
                        'tinymce'       => false
                    ]);
                    ?>
                    <div style="text-align:left; margin-top:20px;">
                        <span id="aipc-review-loading" style="display:none; margin-left:15px; color:#2271b1; font-weight:bold;">در حال تولید محتوای جامع توسط هوش مصنوعی (ممکن است ۱ الی ۲ دقیقه طول بکشد)...</span>
                        <button type="button" class="button" id="aipc-review-back">بازگشت</button>
                        <button type="button" class="button button-primary" id="aipc-review-submit">تایید و ساخت محتوای محصول</button>
                    </div>
                </div>

            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                const customTemplate = `تو یک سنیور سئو، کارشناس ارشد محتوا و کارشناس باتجربه در زمینه سرور، شبکه و تجهیزات اونها هستی.
میخوایم برای {اسم محصول}، متن معرفی برای قرارگیری در صفحه محصول فروشگاه تولید کنیم. این متن باید به خوبی اینتنت «خرید» رو پوشش بده و به سؤالات مشتری پاسخ بده تا مشتری نسبت به خرید محصول ترغیب بشه. همچنین محتوا باید کاملاً منطبق با دیتاشیت محصول باشه و هیچ گونه تناقضی با مشخصات اعلام شده از جانب سازنده نداشته باشه. پس هیچ اشتباهی در مشخصات فنی و متن نباید باشه.
کاری که انجام میدیم:
مطابق با موارد زیر متن معرفی محصول رو به من بده:

اسم محصول:
[اسم_محصول]

کلمات کلیدی:
[کلمات_کلیدی]

مشخصات فنی:
[ویژگی_های_محصول]

متنی که به من میدی دارای ساختار html و قوانین زیر باشه:

تیتر اول: تیتر معرفی محصول
متن اول: معرفی محصول 100 کلمه
             
تیتر دوم: ویژگی ها و مزایای خرید محصول
متن دوم: ابتدا یک توضیح 45 کلمه ای (کاملاً متفاوت با بخش معرفی)، و بعد 8 تیتر h3. هر تیتر یکی از ویژگی های فنی مهم اون محصول رو با 70 کلمه توضیح بده.

تیتر سوم: خرید محصول از فالنیک
متن سوم: در این تیتر مزایای خرید محصول از فالنیک رو مفصل تر توضیح بده. بگو برای خرید و دریافت قیمت با کارشناسان ما تماس بگیرند.در انتها هم در محتوایی که میدی، از کلمات کلیدی که بهت دادم با چگالی اصولی استفاده کن تا متن به خوبی سئو بشه.

نکته: قبل و بعد از متن هیچ کلمه یا کاراکتری اضافه نکن.`;

                $('#aipc-btn-review').on('click', function(e) {
                    e.preventDefault();
                    const wpTitle = $('#title').val() || '';
                    if (wpTitle && !$('#aipc-review-input-title').val()) {
                        $('#aipc-review-input-title').val(wpTitle);
                    }
                    $('#aipc-review-step-1').show();
                    $('#aipc-review-step-2').hide();
                    $('#aipc-modal-review').fadeIn(150);
                });

                $('#aipc-review-close').on('click', function() {
                    $('#aipc-modal-review').fadeOut(150);
                });

                $('#aipc-review-next').on('click', function() {
                    const titleVal = $('#aipc-review-input-title').val() || 'نامشخص';

                    const kw1 = $('#aipc-review-input-kw1').val();
                    const kw2 = $('#aipc-review-input-kw2').val();
                    const kw3 = $('#aipc-review-input-kw3').val();
                    const keywordsVal = [kw1, kw2, kw3].filter(Boolean).join('، ') || 'نامشخص';

                    const featuresVal = $('#aipc-review-input-features').val() || 'نامشخص';

                    let parsedPrompt = customTemplate
                        .replace(/\[اسم_محصول\]/g, titleVal)
                        .replace(/\[کلمات_کلیدی\]/g, keywordsVal)
                        .replace(/\[ویژگی_های_محصول\]/g, featuresVal);

                    $('#aipc-modal-review #aipc_review_prompt_editor').val(parsedPrompt);
                    $('#aipc-review-step-1').hide();
                    $('#aipc-review-step-2').show();
                });

                $('#aipc-review-back').on('click', function() {
                    $('#aipc-review-step-2').hide();
                    $('#aipc-review-step-1').show();
                });

                $('#aipc-review-submit').on('click', function() {
                    let finalPrompt = $('#aipc-modal-review #aipc_review_prompt_editor').val();
                    let selectedModel = $('#aipc-review-input-model').val(); // دریافت مدل انتخاب شده

                    $('#aipc-review-loading').show();
                    $('#aipc_review_prompt_editor').prop('disabled', true);
                    $('#aipc-review-submit').prop('disabled', true);

                    $.post(ajaxurl, {
                        action: 'aipc_generate_action',
                        nonce: '<?php echo wp_create_nonce('aipc_simple_nonce'); ?>',
                        prompt: finalPrompt,
                        ai_model: selectedModel // ارسال مدل به سرور
                    }, function(res) {
                        $('#aipc-review-loading').hide();
                        $('#aipc_review_prompt_editor').prop('disabled', false);
                        $('#aipc-review-submit').prop('disabled', false);

                        if (res.success) {
                            let cleanData = res.data;

                            // 🛠️ ۱. حذف تگ‌های احتمالی ابتدایی و انتهایی بلوک کد مارک‌داون
                            cleanData = cleanData.replace(/^```html\s*/i, '');
                            cleanData = cleanData.replace(/^```\s*/i, '');
                            cleanData = cleanData.replace(/```\s*$/, '');

                            // 🛠️ ۲. پاکسازی فاصله‌ها، شکستگی خطوط و &nbsp; از ابتدا و انتهای متن
                            cleanData = cleanData.replace(/^(\s|&nbsp;|<br\s*\/?>)+/gi, '');
                            cleanData = cleanData.replace(/(\s|&nbsp;|<br\s*\/?>)+$/gi, '');

                            // 🛠️ ۳. حذف کل ساختار تگ h1 و متن درون آن در صورت اصرار ربات بر تولید آن
                            cleanData = cleanData.replace(/<h1[^>]*>.*?<\/h1>/gi, '');

                            // 🛠️ ۴. یک بار پاکسازی مجدد فضاهای خالی ابتدایی ناشی از حذف h1
                            cleanData = cleanData.replace(/^(\s|&nbsp;|<br\s*\/?>)+/gi, '');

                            if (typeof tinymce !== 'undefined' && tinymce.get('content') && !tinymce.get('content').isHidden()) {
                                tinymce.get('content').setContent(cleanData);
                            } else {
                                $('#content').val(cleanData);
                            }
                            $('#aipc-modal-review').fadeOut(150);
                        } else {
                            alert('خطا در هوش مصنوعی: ' + JSON.parse(res.data).error.message);
                        }
                    });
                });
            });
        </script>
<?php
    }

    // ۳. ارسال پرامپت به API هوش مصنوعی همراه با فعال‌سازی سرچ آنلاین
    public function handle_ajax_request()
    {
        check_ajax_referer('aipc_simple_nonce', 'nonce');

        $prompt = isset($_POST['prompt']) ? wp_unslash($_POST['prompt']) : '';
        $ai_model = isset($_POST['ai_model']) ? sanitize_text_field($_POST['ai_model']) : 'gemini-3.1-flash-lite';

        if (empty($prompt)) {
            wp_send_json_error('متن پرامپت خالی است.');
        }

        $ch = curl_init($this->base_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->api_key,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => wp_json_encode([
                'model'      => $ai_model,
                'input'      => $prompt,
                'web_search' => true,
            ]),
            CURLOPT_CONNECTTIMEOUT => 30,   // فرصت کافی برای برقراری اتصال
            CURLOPT_TIMEOUT        => 240,  // فرصت کل برای پاسخ کامل
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // معمولاً مشکل کندی IPv6 رو حل میکنه
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $result   = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            wp_send_json_error('خطا در برقراری شبکه (cURL error ' . $errno . '): ' . $error);
        }

        $body = json_decode($result, true);

        if (isset($body['output'][0]['content'][0]['text'])) {
            wp_send_json_success($body['output'][0]['content'][0]['text']);
        }

        wp_send_json_error(json_encode($body));
    }
}
