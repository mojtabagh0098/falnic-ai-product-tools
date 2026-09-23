<?php
class AI_Product_Content_Generator
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
            echo '<button type="button" class="button button-primary" id="aipc-btn" style="margin-right:5px;">
                    <span class="dashicons dashicons-editor-code" style="margin-top:3px;"></span> تولید محتوای هوشمند سئو
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
        <div id="aipc-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:999999; direction:rtl;">
            <div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); background:#fff; padding:20px; border-radius:6px; width:700px; max-width:95%; max-height:90vh; overflow-y:auto; box-shadow:0 4px 20px rgba(0,0,0,0.3); text-align:right;">

                <h3 style="margin-top:0; padding-bottom:12px; border-bottom:1px solid #eee; font-size:16px;">تنظیمات تولید محتوای هوشمند محصول</h3>

                <div id="aipc-step-1">

                    <p>
                        <label><b>اسم محصول:</b></label>
                        <input type="text" id="aipc-input-title" style="width:100%; margin-top:5px;" placeholder="مثال: HP ProLiant DL380 Gen11">
                    </p>
                    <p>
                        <label><b>لینک اول همین محصول در سایت رقیب:</b></label>
                        <input type="text" id="aipc-input-competitor-link" style="width:100%; margin-top:5px;" placeholder="مثال: https://raghib1.com/product/dl380">
                    </p>
                    <p>
                        <label><b>لینک دوم همین محصول در سایت رقیب (اختیاری):</b></label>
                        <input type="text" id="aipc-input-competitor-link-2" style="width:100%; margin-top:5px;" placeholder="مثال: https://raghib2.com/product/dl380">
                    </p>

                    <p style="margin-bottom:0;"><label><b>کلمات کلیدی:</b></label></p>
                    <div style="display:flex; gap:10px; margin-top:5px;">
                        <input type="text" id="aipc-input-kw1" style="width:33.3%;" placeholder="کلمه کلیدی اول">
                        <input type="text" id="aipc-input-kw2" style="width:33.3%;" placeholder="کلمه کلیدی دوم">
                        <input type="text" id="aipc-input-kw3" style="width:33.3%;" placeholder="کلمه کلیدی سوم">
                    </div>

                    <p style="margin-bottom:0; margin-top:15px;"><label><b>مدل‌های مشابه (جهت جدول مقایسه):</b></label></p>
                    <div style="display: flex; flex-flow:row;justify-content: space-between;">

                        <div style="display:flex; gap:10px; margin-top:5px;">
                            <input type="text" id="aipc-input-sim1-name" placeholder="اسم مدل مشابه ۱">
                        </div>
                        <div style="display:flex; gap:10px; margin-top:5px;">
                            <input type="text" id="aipc-input-sim2-name" placeholder="اسم مدل مشابه ۲">
                        </div>
                        <div style="display:flex; gap:10px; margin-top:5px;">
                            <input type="text" id="aipc-input-sim3-name" placeholder="اسم مدل مشابه ۳">
                        </div>
                    </div>

                    <p style="margin-top:15px;">
                        <label><b>ویژگی‌های محصول (استخراج شده از ویژگی های محصول):</b></label>
                        <textarea id="aipc-input-features" style="width:100%; margin-top:5px; height:100px; background:#f9f9f9;" placeholder="ویژگی‌های محصول یافت نشد..."><?php echo esc_textarea($product_features_text); ?></textarea>
                    </p>


                    <!-- فیلد انتخاب مدل هوش مصنوعی -->
                    <p>
                        <label><b>انتخاب مدل هوش مصنوعی:</b></label>
                        <select id="aipc-input-model" style="width:100%; margin-top:5px; padding: 5px;">
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
                        <button type="button" class="button" id="aipc-close">انصراف</button>
                        <button type="button" class="button button-primary" id="aipc-next">مرحله بعد (تولید پرامپت)</button>
                    </div>
                </div>

                <div id="aipc-step-2" style="display:none;">
                    <p><b>قالب پرامپت نهایی اختصاصی (در صورت نیاز ویرایش کنید):</b></p>
                    <?php
                    wp_editor('', 'aipc_prompt_editor', [
                        'textarea_rows' => 22,
                        'media_buttons' => false,
                        'quicktags'     => false,
                        'tinymce'       => false
                    ]);
                    ?>
                    <div style="text-align:left; margin-top:20px;">
                        <span id="aipc-loading" style="display:none; margin-left:15px; color:#2271b1; font-weight:bold;">در حال تولید محتوای جامع توسط هوش مصنوعی (ممکن است ۱ الی ۲ دقیقه طول بکشد)...</span>
                        <button type="button" class="button" id="aipc-back">بازگشت</button>
                        <button type="button" class="button button-primary" id="aipc-submit">تایید و ساخت محتوای محصول</button>
                    </div>
                </div>

            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                const customTemplate = `تو یک کارشناس ارشد سئو فنی و متخصص محتوای تجهیزات سرور و شبکه هستی. می‌خواهیم برای محصول [اسم_محصول] یک محتوای فوق‌العاده جامع تولید کنیم.
            
                قوانین ساختاری بسیار سخت‌گیرانه (حیاتی):
                1. کل متن خروجی باید کاملاً ساختار استاندارد HTML داشته باشد.
                2. به هیچ عنوان از کاراکترهای دابل‌هش (##)، ستاره (**)، خط تیره (---) یا تکست عادی بدون تگ برای مشخص کردن تیترها استفاده نکن.
                3. تمام تیترهای اصلی را حتماً در تگ <h2> و زیرتیترها را در <h3> قرار بده. متون معمولی را در تگ <p> بگذار. لیست‌ها باید کاملاً ساختار <ul> و <li> داشته باشند.
                4. به هیچ عنوان و تحت هیچ شرایطی از تگ <h1> استفاده نکن. اولین تگ متن تو باید مستقیماً با تگ <h2> شروع شود.
                5. خروجی را مطلقاً درون بلوک‌های کد مارک‌داون (مثل \`\`\`html یا \`\`\`) قرار نده. متن را مستقیماً به صورت رشته متنی خالص HTML ارسال کن.

                اطلاعات اولیه ورودی:
                - اسم محصول فعلی ما: [اسم_محصول]
                - لینک‌های محصول در سایت‌های رقیب جهت آنالیز محتوایی شما: 
                [لینک_محصول_رقیب]
                - ویژگی‌های فنی پایه محصول ما: 
                [ویژگی_های_محصول]

                دستورالعمل تولید بخش‌های محتوا به صورت کدهای HTML:

                بخش اول:
                <h2>معرفی [اسم_محصول]</h2>
                یک متن معرفی جذاب سئوشده بین 140 تا 150 کلمه در تگ <p> بنویس.

                بخش دوم:
                <h2>[اسم_محصول] برای چه کاربردهایی مناسب است؟</h2>
                ابتدا یک توضیح کلی در 70 کلمه داخل تگ <p> بده که با بخش معرفی متمایز باشد. سپس کاربردهای اصلی را در یک لیست ساختاریافته با تگ‌های <ul> و <li> به صورت کاملاً خلاصه و تیتروار بنویس. (حداقل 10 کاربرد – کاربردهای بیشتری هم در لیست درج شود مشکلی نیست).

                بخش سوم:
                <h2>ویژگی‌ها و مزایای [اسم_محصول]</h2>
                ابتدا یکپاراگراف 70 کلمه‌ای با تگ <p> درباره مزیت‌های کلی بنویس. سپس بر اساس مشخصات فنی محصول، تک‌تک ویژگی‌های مهم را در ساختار <ul> و <li>  برای این بولت لیست بین 8 تا 10 مورد نیاز است، بیاور و هر مورد را دقیقاً در حد 70 کلمه در همان تگ لیست تشریح کن.

                بخش چهارم:
                <h2>مقایسه [اسم_محصول] با مدل‌های مشابه</h2>
                مدل‌های مشابه جهت سرچ آنلاین و مقایسه:
                [مدل_های_مشابه]
                از قابلیت سرچ آنلاین خود استفاده کن و مشخصات دقیق این مدل‌ها را پیدا کن. یک جدول HTML استاندارد (تگ <table> بدون هیچ استایل یا کلاس CSS اضافه) بساز.
                اگر محصول سرور است، ستون اول جدول محصول فعلی ([اسم_محصول]) باشد و ردیف‌ها شامل معیارهای زیر باشند:
                نوع شاسی، حداکثر تعداد پردازنده، معماری پردازنده، حداکثر تعداد هسته پردازشی، نوع حافظه، حداکثر ظرفیت رم، اسلات‌های توسعه، پشتیبانی از GPU، موتور مدیریتی.
                اگر سرور نیست، با توجه به این 3 مدل مشابه، ویژگی‌های کلیدی‌شان را پیدا کرده و در جدول مقایسه کن. به هیچ وجه عباراتی مثل "نیازمند تایید" در جدول ننویس و اطلاعات دقیق را استخراج کن.

                بخش پنجم:
                <h2>آیا [اسم_محصول] ارزش خرید دارد؟</h2>
                یک پاراگراف 80 کلمه‌ای در تگ <p> بنویس و در آن از کیوردهای "خرید [اسم_محصول]" و "قیمت [اسم_محصول]" استفاده کن. سپس یک بولت لیست (تگ ul) بساز که تمام آیتم‌های آن با عبارت «زمانی که ...» شروع شوند و برای بولت لیست این بخش حداقل 7 مورد نیاز است.

                بخش ششم:
                <h2>قیمت [اسم_محصول] چگونه تعیین می‌شود؟</h2>
                در تگ <p> توضیح بده که قیمت ثابت نیست و به کانفیگ انتخابی بستگی دارد. قطعات تاثیرگذار را در تگ <ul> به اندازه حداقل 10 مورد نام ببر و در انتها بگو برای قیمت دقیق با کارشناسان فالنیک تماس بگیرند.

                بخش هفتم:
                <h2>سؤالات متداول درباره [اسم_محصول]</h2>
                تعداد 10 سوال متداول (FAQ) شماره‌گذاری‌شده و کاملاً مرتبط با اینتنت خرید کاربران در مورد محصول [نام دقیق محصول را اینجا وارد کنید] بنویس.

                الزامات زیر را دقیقاً رعایت کن:
                ۱. هر سوال را با تگ <h3> بنویس و حتماً آن‌ها را به ترتیب شماره‌گذاری کن (مثلاً <h3>۱. ...؟</h3>).
                ۲. در متن تک‌تک سوالات، حتماً نام محصول و کلمات کلیدی هدف را به کار ببر. به هیچ وجه از ضمایر مبهم استفاده نکن (مثلاً به جای "از چه پردازنده‌ای استفاده می‌کند؟"، صراحتاً بپرس "[نام محصول] از چه پردازنده‌هایی پشتیبانی می‌کند؟").
                ۳. پاسخ هر سوال را با تگ <p> دقیقاً زیر همان سوال قرار بده.
                ۴. طول پاسخ هر سوال باید حداقل 40 کلمه باشد.
                
                کلمات کلیدی الزامی که باید در طول متن با چگالی مناسب توزیع شوند:
                [کلمات_کلیدی]`;

                $('#aipc-btn').on('click', function(e) {
                    e.preventDefault();
                    const wpTitle = $('#title').val() || '';
                    if (wpTitle && !$('#aipc-input-title').val()) {
                        $('#aipc-input-title').val(wpTitle);
                    }
                    $('#aipc-step-1').show();
                    $('#aipc-step-2').hide();
                    $('#aipc-modal').fadeIn(150);
                });

                $('#aipc-close').on('click', function() {
                    $('#aipc-modal').fadeOut(150);
                });

                $('#aipc-next').on('click', function() {
                    const titleVal = $('#aipc-input-title').val() || 'نامشخص';

                    // دریافت لینک هر دو سایت رقیب
                    const compLink1 = $('#aipc-input-competitor-link').val();
                    const compLink2 = $('#aipc-input-competitor-link-2').val();

                    let competitorLinkVal = '';
                    if (compLink1) competitorLinkVal += '- رقیب اول: ' + compLink1 + '\n                ';
                    if (compLink2) competitorLinkVal += '- رقیب دوم: ' + compLink2 + '\n                ';
                    if (!competitorLinkVal) competitorLinkVal = 'وارد نشده';

                    const kw1 = $('#aipc-input-kw1').val();
                    const kw2 = $('#aipc-input-kw2').val();
                    const kw3 = $('#aipc-input-kw3').val();
                    const keywordsVal = [kw1, kw2, kw3].filter(Boolean).join('، ') || 'نامشخص';

                    let similarArr = [];
                    const sim1Name = $('#aipc-input-sim1-name').val();

                    const sim2Name = $('#aipc-input-sim2-name').val();

                    const sim3Name = $('#aipc-input-sim3-name').val();

                    const similarVal = similarArr.join('\n') || 'نامشخص';
                    const featuresVal = $('#aipc-input-features').val() || 'نامشخص';

                    let parsedPrompt = customTemplate
                        .replace(/\[اسم_محصول\]/g, titleVal)
                        .replace(/\[لینک_محصول_رقیب\]/g, competitorLinkVal)
                        .replace(/\[کلمات_کلیدی\]/g, keywordsVal)
                        .replace(/\[مدل_های_مشابه\]/g, similarVal)
                        .replace(/\[ویژگی_های_محصول\]/g, featuresVal);

                    $('#aipc-modal #aipc_prompt_editor').val(parsedPrompt);
                    $('#aipc-step-1').hide();
                    $('#aipc-step-2').show();
                });

                $('#aipc-back').on('click', function() {
                    $('#aipc-step-2').hide();
                    $('#aipc-step-1').show();
                });

                $('#aipc-submit').on('click', function() {
                    let finalPrompt = $('#aipc-modal #aipc_prompt_editor').val();
                    let selectedModel = $('#aipc-input-model').val(); // دریافت مدل انتخاب شده

                    $('#aipc-loading').show();
                    $('#aipc_prompt_editor').prop('disabled', true);
                    $('#aipc-submit').prop('disabled', true);

                    $.post(ajaxurl, {
                        action: 'aipc_generate_action',
                        nonce: '<?php echo wp_create_nonce('aipc_simple_nonce'); ?>',
                        prompt: finalPrompt,
                        ai_model: selectedModel // ارسال مدل به سرور
                    }, function(res) {
                        $('#aipc-loading').hide();
                        $('#aipc_prompt_editor').prop('disabled', false);
                        $('#aipc-submit').prop('disabled', false);

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
                            $('#aipc-modal').fadeOut(150);
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
