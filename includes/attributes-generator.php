<?php

/**
 * Attributes_Generator_With_Crawler
 * -----------------------------------------------------------------------
 * این کلاس دو قابلیت دارد:
 *  1) کپی ویژگی‌ها از یک محصول دیگر
 *  2) تکمیل خودکار مقادیر ویژگی‌ها با کمک هوش مصنوعی (Crawler) با استفاده از API سایت GapGPT
 * 
 * تغییرات:
 *  - ساختار یکپارچه شده با API سایت GapGPT
 *  - استفاده از cURL با تنظیمات بهینه (مانند IPv4 Resolve)
 *  - جستجوی آنلاین فعال (web_search = true)
 *  - افزوده شدن فیلد اختیاری الگو برای فرمت و لحن نوشتار ویژگی‌ها
 *  - بازنویسی استراتژی جستجو در پرامپت جهت جستجوی چندمرحله‌ای (آبشاری در سایت‌های مختلف)
 * -----------------------------------------------------------------------
 */

class Attributes_Generator_With_Crawler
{
    private $gapgpt_base_url = 'https://api.gapgpt.app/v1/responses';
    // کلید پیش‌فرض API (در صورت عدم تنظیم در پنل مدیریت استفاده می‌شود)
    private $default_api_key = 'sk-qslrC2LFNooKAtGAAWfI7ZcAbB045Rftzt6vnrzqi4dZ8aJk';

    public function __construct()
    {
        // قابلیت قبلی: کپی ویژگی‌ها از محصول دیگر
        add_action('woocommerce_product_options_attributes', [$this, 'add_copy_attributes_button_ui']);
        add_action('wp_ajax_copy_woocommerce_product_attributes', [$this, 'process_copy_woocommerce_product_attributes']);

        // قابلیت جدید: Crawler مبتنی بر AI
        add_action('woocommerce_product_options_attributes', [$this, 'render_ai_crawler_ui']);
        add_action('wp_ajax_aig_generate_attributes_ai', [$this, 'ajax_generate_attributes_ai']);
        add_action('wp_ajax_aig_verify_attributes_ai', [$this, 'ajax_verify_attributes_ai']);
        add_action('wp_ajax_aig_ensure_attribute_term', [$this, 'ajax_ensure_attribute_term']);

        // صفحه تنظیمات (کلید API و مدل‌ها)
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_init', [$this, 'register_settings_fields']);
        
        // تنظیمات عمومی cURL برای وردپرس (اختیاری جهت اطمینان)
        add_action('http_api_curl', function ($handle, $r, $url) {
            if (strpos($url, 'gapgpt.app') !== false) {
                curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 30);
                curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            }
        }, 10, 3);
    }

    /* =====================================================================
     * 1) قابلیت کپی ویژگی‌ها
     * =================================================================== */

    public function add_copy_attributes_button_ui()
    {
        global $post;
        $nonce = wp_create_nonce('copy_product_attributes_nonce');
?>
        <div class="toolbar" style="padding: 15px; background: #f0f0f1; border: 1px solid #c3c4c7; margin-bottom: 15px; border-radius: 4px;">
            <h4 style="margin-top: 0; margin-bottom: 10px;">کپی ویژگی‌ها از محصول دیگر</h4>
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <select class="wc-product-search" id="source_product_for_attributes" style="width: 300px;" data-placeholder="نام محصول مبدا را جستجو کنید..." data-action="woocommerce_json_search_products_and_variations"></select>
                <button type="button" class="button button-primary" id="btn-do-copy-attributes" data-target-id="<?php echo esc_attr($post->ID); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">کپی ویژگی‌ها</button>
                <span class="spinner" id="copy-attributes-spinner" style="float: none; margin: 0;"></span>
            </div>
            <p style="margin-bottom: 0; margin-top: 10px; color: #d63638; font-size: 12px;"><strong>توجه:</strong> با انجام این کار، ویژگی‌های فعلی این محصول حذف شده و ویژگی‌های محصول مبدا جایگزین آن می‌شود. (صفحه پس از موفقیت آمیز بودن رفرش می‌شود)</p>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#btn-do-copy-attributes').on('click', function(e) {
                    e.preventDefault();
                    var source_id = $('#source_product_for_attributes').val();
                    var target_id = $(this).data('target-id');
                    var nonce = $(this).data('nonce');

                    if (!source_id) {
                        alert('لطفاً ابتدا یک محصول را برای کپی کردن ویژگی‌های آن انتخاب کنید.');
                        return;
                    }
                    if (source_id == target_id) {
                        alert('شما نمی‌توانید ویژگی‌ها را از خود همین محصول کپی کنید.');
                        return;
                    }
                    var confirmCopy = confirm('آیا مطمئن هستید؟ تمام ویژگی‌های فعلی این محصول با ویژگی‌های محصول انتخابی جایگزین خواهند شد. لطفاً تغییرات دیگر صفحه را ابتدا ذخیره کنید.');
                    if (confirmCopy) {
                        $('#copy-attributes-spinner').addClass('is-active');
                        $(this).prop('disabled', true);
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'copy_woocommerce_product_attributes',
                                source_id: source_id,
                                target_id: target_id,
                                security: nonce
                            },
                            success: function(response) {
                                if (response.success) {
                                    alert(response.data.message);
                                    location.reload();
                                } else {
                                    alert(response.data.message || 'خطایی رخ داد.');
                                    $('#copy-attributes-spinner').removeClass('is-active');
                                    $('#btn-do-copy-attributes').prop('disabled', false);
                                }
                            },
                            error: function() {
                                alert('خطا در ارتباط با سرور.');
                                $('#copy-attributes-spinner').removeClass('is-active');
                                $('#btn-do-copy-attributes').prop('disabled', false);
                            }
                        });
                    }
                });
            });
        </script>
<?php
    }

    public function process_copy_woocommerce_product_attributes()
    {
        check_ajax_referer('copy_product_attributes_nonce', 'security');

        $source_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
        $target_id = isset($_POST['target_id']) ? absint($_POST['target_id']) : 0;

        if (! $source_id || ! $target_id) {
            wp_send_json_error(array('message' => 'شناسه محصول نامعتبر است.'));
        }
        if (! current_user_can('edit_product', $target_id)) {
            wp_send_json_error(array('message' => 'شما اجازه ویرایش این محصول را ندارید.'));
        }

        $source_product = wc_get_product($source_id);
        $target_product = wc_get_product($target_id);

        if (! $source_product || ! $target_product) {
            wp_send_json_error(array('message' => 'محصول مورد نظر یافت نشد.'));
        }

        $attributes = $source_product->get_attributes();
        $target_product->set_attributes($attributes);
        $target_product->save();

        wp_send_json_success(array('message' => 'ویژگی‌ها با موفقیت کپی شدند! صفحه در حال بروزرسانی است...'));
    }

    /* =====================================================================
     * 2) قابلیت Crawler مبتنی بر AI
     * =================================================================== */

    public function render_ai_crawler_ui()
    {
        global $post;
        $nonce = wp_create_nonce('aig_ai_crawler_nonce');

        $default_name = $post ? get_the_title($post->ID) : '';

        $existing_attr_names = [];
        $attr_label_to_slug_map = [];
        if ($post) {
            $product = wc_get_product($post->ID);
            if ($product) {
                foreach ($product->get_attributes() as $attribute) {
                    $slug = $attribute->get_name();
                    $label = $attribute->is_taxonomy() ? wc_attribute_label($slug, $product) : $slug;

                    $existing_attr_names[] = $label;
                    $attr_label_to_slug_map[$label] = [
                        'slug'        => $slug,
                        'is_taxonomy' => $attribute->is_taxonomy(),
                    ];
                }
            }
        }
?>
        <div class="toolbar" style="padding: 15px; background: #eef6ff; border: 1px solid #b6d4f5; margin-bottom: 15px; border-radius: 4px;">
            <h4 style="margin-top: 0; margin-bottom: 10px;">تکمیل خودکار مقادیر ویژگی‌ها با هوش مصنوعی</h4>

            <p style="margin: 0 0 10px;">
                <label style="display:block; font-weight:600; margin-bottom:4px;">نام دقیق محصول (برای جستجو):</label>
                <input type="text" id="aig_product_name" style="width: 420px;" value="<?php echo esc_attr($default_name); ?>" />
            </p>

            <p style="margin: 0 0 10px;">
                <label style="display:block; font-weight:600; margin-bottom:4px;">
                    لینک نمونه برای فرمت نوشتار <span style="color:#666;">(اختیاری)</span>:
                </label>
                <input type="url" id="aig_format_url" style="width: 420px;" placeholder="https://www.yourdomain.com/sample-product" />
                <span style="display:block; font-size:11px; color:#666; margin-top:3px;">
                    اگر می‌خواهید مقادیر ویژگی‌ها از نظر لحن و فرمت نگارشی دقیقاً مشابه محصولات دیگر سایتتان باشد، لینک یکی از آن‌ها را اینجا قرار دهید.
                </span>
            </p>

            <p style="margin: 0 0 10px;">
                <label style="display:block; font-weight:600; margin-bottom:4px;">نام ویژگی‌هایی که باید مقدارشان پر شود (با کاما جدا کنید):</label>
                <textarea id="aig_attribute_names" rows="2" style="width: 420px;"><?php echo esc_textarea(implode(', ', $existing_attr_names)); ?></textarea>
            </p>
            <input type="hidden" id="aig_attr_label_map" value="<?php echo esc_attr(wp_json_encode($attr_label_to_slug_map)); ?>" />

            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <button type="button" class="button button-primary" id="aig-btn-generate"
                    data-target-id="<?php echo esc_attr($post->ID); ?>"
                    data-nonce="<?php echo esc_attr($nonce); ?>">
                    ۱) دریافت مشخصات از AI
                </button>
                <button type="button" class="button" id="aig-btn-verify" disabled>
                    ۲) بررسی صحت با AI دوم
                </button>
                <span class="spinner" id="aig-spinner" style="float: none; margin: 0;"></span>
            </div>

            <div id="aig-result-wrapper" style="margin-top: 15px; display:none;">
                <table class="widefat striped" id="aig-result-table">
                    <thead>
                        <tr>
                            <th style="width:25%;">ویژگی</th>
                            <th>مقدار (قابل ویرایش)</th>
                            <th style="width:30%;">نتیجه بررسی AI دوم</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <p>
                    <button type="button" class="button button-primary" id="aig-btn-apply">
                        اعمال مقادیر به فیلدهای ویژگی محصول
                    </button>
                </p>
                <p style="color:#d63638; font-size:12px;">
                    <strong>توجه:</strong> این ابزار فقط فیلدهای ویژگی را در همین صفحه پر می‌کند و
                    چیزی را مستقیماً ذخیره نمی‌کند. پس از بررسی مقادیر، حتماً دکمه‌ی «به‌روزرسانی»
                    محصول را بزنید.
                </p>

                <p style="color:#d63638; font-size:12px;">
                    <strong>توجه:</strong> فیلدهایی که نیاز ندارید مقدارشان در ویژگی مورد نظر قرار بگیرد، آن فیلد را خالی بگذارید.
                </p>
            </div>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var lastTableData = null;

                function renderTable(rows, verification) {
                    var $tbody = $('#aig-result-table tbody');
                    $tbody.empty();
                    rows.forEach(function(row, idx) {
                        var check = (verification && verification[idx]) ? verification[idx] : null;
                        var checkHtml = '—';
                        if (check) {
                            var color = check.status === 'confirmed' ? '#1a7f37' : (check.status === 'uncertain' ? '#b45309' : '#d63638');
                            checkHtml = '<span style="color:' + color + '; font-weight:600;">' + (check.status_label || check.status) + '</span>' +
                                (check.note ? '<br><span style="font-size:11px; color:#555;">' + $('<div>').text(check.note).html() + '</span>' : '');
                        }
                        $tbody.append(
                            '<tr>' +
                            '<td>' + $('<div>').text(row.attribute).html() + '</td>' +
                            '<td><input type="text" class="aig-value-input" style="width:100%;" value="' + $('<div>').text(row.value).html() + '" /></td>' +
                            '<td>' + checkHtml + '</td>' +
                            '</tr>'
                        );
                    });
                    $('#aig-result-wrapper').show();
                }

                function collectTableFromUI() {
                    var rows = [];
                    $('#aig-result-table tbody tr').each(function() {
                        var attribute = $(this).find('td').eq(0).text();
                        var value = $(this).find('.aig-value-input').val();
                        rows.push({
                            attribute: attribute,
                            value: value
                        });
                    });
                    return rows;
                }

                $('#aig-btn-generate').on('click', function(e) {
                    e.preventDefault();
                    var target_id = $(this).data('target-id');
                    var nonce = $(this).data('nonce');
                    var product_name = $('#aig_product_name').val().trim();
                    var attribute_names = $('#aig_attribute_names').val().trim();
                    var format_url = $('#aig_format_url').val().trim();

                    if (!product_name) {
                        alert('لطفاً نام محصول را وارد کنید.');
                        return;
                    }
                    if (format_url && !/^https?:\/\/.+/i.test(format_url)) {
                        alert('لینک نمونه فرمت معتبر نیست. لطفاً یک لینک کامل با http:// یا https:// وارد کنید یا آن را خالی بگذارید.');
                        $('#aig_format_url').focus();
                        return;
                    }
                    if (!attribute_names) {
                        alert('لطفاً حداقل یک ویژگی وارد کنید.');
                        return;
                    }

                    $('#aig-spinner').addClass('is-active');
                    $('#aig-btn-generate').prop('disabled', true);
                    $('#aig-btn-verify').prop('disabled', true);

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'aig_generate_attributes_ai',
                            product_id: target_id,
                            product_name: product_name,
                            attribute_names: attribute_names,
                            format_url: format_url,
                            security: nonce
                        },
                        success: function(response) {
                            $('#aig-spinner').removeClass('is-active');
                            $('#aig-btn-generate').prop('disabled', false);
                            if (response.success) {
                                lastTableData = response.data.table;
                                renderTable(lastTableData, null);
                                $('#aig-btn-verify').prop('disabled', false);
                            } else {
                                alert(response.data.message || 'خطایی در دریافت اطلاعات از AI رخ داد.');
                            }
                        },
                        error: function() {
                            $('#aig-spinner').removeClass('is-active');
                            $('#aig-btn-generate').prop('disabled', false);
                            alert('خطا در ارتباط با سرور.');
                        }
                    });
                });

                $('#aig-btn-verify').on('click', function(e) {
                    e.preventDefault();
                    var target_id = $('#aig-btn-generate').data('target-id');
                    var nonce = $('#aig-btn-generate').data('nonce');
                    var product_name = $('#aig_product_name').val().trim();
                    var currentTable = collectTableFromUI();

                    $('#aig-spinner').addClass('is-active');
                    $(this).prop('disabled', true);

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'aig_verify_attributes_ai',
                            product_id: target_id,
                            product_name: product_name,
                            table: JSON.stringify(currentTable),
                            security: nonce
                        },
                        success: function(response) {
                            $('#aig-spinner').removeClass('is-active');
                            $('#aig-btn-verify').prop('disabled', false);
                            if (response.success) {
                                renderTable(currentTable, response.data.verification);
                            } else {
                                alert(response.data.message || 'خطایی در بررسی صحت رخ داد.');
                            }
                        },
                        error: function() {
                            $('#aig-spinner').removeClass('is-active');
                            $('#aig-btn-verify').prop('disabled', false);
                            alert('خطا در ارتباط با سرور.');
                        }
                    });
                });

                $('#aig-btn-apply').on('click', async function(e) {
                    e.preventDefault();
                    var rows = collectTableFromUI();
                    var labelMap = {};
                    try {
                        labelMap = JSON.parse($('#aig_attr_label_map').val() || '{}');
                    } catch (err) {
                        labelMap = {};
                    }

                    var targetProductId = $('#aig-btn-generate').data('target-id');
                    var nonce = $('#aig-btn-generate').data('nonce');

                    var $applyBtn = $(this);
                    var originalBtnText = $applyBtn.text();
                    $applyBtn.prop('disabled', true).text('در حال اعمال مقادیر...');
                    $('#aig-spinner').addClass('is-active');

                    var appliedMsgs = [];
                    var failedMsgs = [];

                    function ensureTermSelected(taxonomy, value) {
                        return new Promise(function(resolve, reject) {
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'aig_ensure_attribute_term',
                                    taxonomy: taxonomy,
                                    value: value,
                                    product_id: targetProductId,
                                    security: nonce
                                }
                            }).done(resolve).fail(reject);
                        });
                    }

                    for (const row of rows) {
                        if (!row.value) {
                            continue;
                        }

                        var mapEntry = labelMap[row.attribute];

                        if (mapEntry && mapEntry.is_taxonomy) {
                            var $taxWrapper = $('#product_attributes .woocommerce_attribute[data-taxonomy="' + mapEntry.slug + '"]');
                            if (!$taxWrapper.length) {
                                failedMsgs.push(row.attribute + ' → ردیف ویژگی در صفحه پیدا نشد.');
                                continue;
                            }

                            try {
                                var resp = await ensureTermSelected(mapEntry.slug, row.value);
                                if (!resp.success) {
                                    failedMsgs.push(row.attribute + ' → ' + (resp.data && resp.data.message ? resp.data.message : 'خطا در ساخت/یافتن مقدار.'));
                                    continue;
                                }

                                var $select = $taxWrapper.find('select.attribute_values, select[name^="attribute_values"]').first();
                                if (!$select.length) {
                                    failedMsgs.push(row.attribute + ' → فیلد انتخاب مقدار پیدا نشد.');
                                    continue;
                                }

                                var termId = String(resp.data.term_id);
                                var termName = resp.data.term_name;

                                $select.find('option').prop('selected', false);
                                var $existingOption = $select.find('option[value="' + termId + '"]');
                                if ($existingOption.length) {
                                    $existingOption.prop('selected', true);
                                } else {
                                    $select.append(new Option(termName, termId, true, true));
                                }
                                $select.trigger('change');

                                appliedMsgs.push(row.attribute + ' → ' + termName + (resp.data.created ? ' (مقدار جدید ساخته شد)' : ''));
                            } catch (err) {
                                failedMsgs.push(row.attribute + ' → خطا در ارتباط با سرور.');
                            }

                            continue;
                        }

                        // ویژگی سفارشی (متنی)
                        var $existingNameInput = $('#product_attributes .woocommerce_attribute .attribute_name')
                            .filter(function() {
                                return $(this).val().trim().toLowerCase() === row.attribute.trim().toLowerCase();
                            });

                        if ($existingNameInput.length) {
                            var $wrapper = $existingNameInput.closest('.woocommerce_attribute');
                            $wrapper.find('.attribute_value').val(row.value).trigger('change');
                            appliedMsgs.push(row.attribute + ' → ' + row.value);
                        } else {
                            $('#product_attributes .add_attribute').trigger('click');
                            var $lastRow = $('#product_attributes .woocommerce_attribute').last();
                            $lastRow.find('.attribute_name').val(row.attribute).trigger('change');
                            $lastRow.find('.attribute_value').val(row.value).trigger('change');
                            appliedMsgs.push(row.attribute + ' → ' + row.value + ' (ردیف سفارشی جدید)');
                        }
                    }

                    $('#aig-spinner').removeClass('is-active');
                    $applyBtn.prop('disabled', false).text(originalBtnText);

                    var msg = '';
                    if (appliedMsgs.length) {
                        msg += 'مقادیر زیر با موفقیت اعمال شدند:\n' + appliedMsgs.join('\n');
                    }
                    if (failedMsgs.length) {
                        msg += (msg ? '\n\n' : '') + 'خطا در موارد زیر:\n' + failedMsgs.join('\n');
                    }
                    if (!msg) {
                        msg = 'موردی برای اعمال (با مقدار غیرخالی) یافت نشد.';
                    }
                    msg += '\n\nلطفاً پیش از بستن صفحه، محصول را «به‌روزرسانی» کنید.';
                    alert(msg);
                });
            });
        </script>
<?php
    }

    /* ---------------------------------------------------------------------
     * AJAX: گام اول - تولید جدول مشخصات با مدل مرحله اول (Primary)
     * ------------------------------------------------------------------- */
    public function ajax_generate_attributes_ai()
    {
        check_ajax_referer('aig_ai_crawler_nonce', 'security');

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (! current_user_can('edit_product', $product_id)) {
            wp_send_json_error(['message' => 'شما اجازه ویرایش این محصول را ندارید.']);
        }

        $product_name = isset($_POST['product_name']) ? sanitize_text_field(wp_unslash($_POST['product_name'])) : '';
        $attribute_names_raw = isset($_POST['attribute_names']) ? sanitize_text_field(wp_unslash($_POST['attribute_names'])) : '';
        $format_url = isset($_POST['format_url']) ? esc_url_raw(wp_unslash($_POST['format_url'])) : '';

        $attribute_names = array_filter(array_map('trim', explode(',', $attribute_names_raw)));

        if (! $product_name || empty($attribute_names)) {
            wp_send_json_error(['message' => 'نام محصول یا لیست ویژگی‌ها نامعتبر است.']);
        }

        $prompt = $this->build_generation_prompt($product_name, $attribute_names, $format_url);

        $model = get_option('aig_primary_model', 'gemini-2.5-pro');
        $ai_text = $this->call_gapgpt_api($model, $prompt);
        
        if (is_wp_error($ai_text)) {
            wp_send_json_error(['message' => $ai_text->get_error_message()]);
        }

        $parsed = $this->extract_json_from_text($ai_text);
        if (! $parsed || ! isset($parsed['attributes']) || ! is_array($parsed['attributes'])) {
            wp_send_json_error(['message' => 'پاسخ AI قابل تجزیه نبود. لطفاً دوباره تلاش کنید.']);
        }

        $table = [];
        foreach ($attribute_names as $name) {
            $value = '';
            foreach ($parsed['attributes'] as $item) {
                if (isset($item['attribute']) && mb_strtolower(trim($item['attribute'])) === mb_strtolower($name)) {
                    $value = isset($item['value']) ? $item['value'] : '';
                    break;
                }
            }
            $table[] = ['attribute' => $name, 'value' => $value];
        }

        wp_send_json_success(['table' => $table]);
    }

    /* ---------------------------------------------------------------------
     * AJAX: گام دوم - بررسی صحت جدول با مدل مرحله دوم (Verifier)
     * ------------------------------------------------------------------- */
    public function ajax_verify_attributes_ai()
    {
        check_ajax_referer('aig_ai_crawler_nonce', 'security');

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (! current_user_can('edit_product', $product_id)) {
            wp_send_json_error(['message' => 'شما اجازه ویرایش این محصول را ندارید.']);
        }

        $product_name = isset($_POST['product_name']) ? sanitize_text_field(wp_unslash($_POST['product_name'])) : '';
        $table_raw = isset($_POST['table']) ? wp_unslash($_POST['table']) : '';
        $table = json_decode($table_raw, true);

        if (! $product_name || ! is_array($table) || empty($table)) {
            wp_send_json_error(['message' => 'داده‌های ورودی نامعتبر است.']);
        }

        foreach ($table as &$row) {
            $row['attribute'] = isset($row['attribute']) ? sanitize_text_field($row['attribute']) : '';
            $row['value']     = isset($row['value']) ? sanitize_text_field($row['value']) : '';
        }
        unset($row);

        $prompt = $this->build_verification_prompt($product_name, $table);

        $model = get_option('aig_verifier_model', 'gpt-4o');
        $ai_text = $this->call_gapgpt_api($model, $prompt);
        
        if (is_wp_error($ai_text)) {
            wp_send_json_error(['message' => $ai_text->get_error_message()]);
        }

        $parsed = $this->extract_json_from_text($ai_text);
        if (! $parsed || ! isset($parsed['verification']) || ! is_array($parsed['verification'])) {
            wp_send_json_error(['message' => 'پاسخ AI دوم قابل تجزیه نبود.']);
        }

        $verification = [];
        foreach ($table as $row) {
            $match = null;
            foreach ($parsed['verification'] as $item) {
                if (isset($item['attribute']) && mb_strtolower(trim($item['attribute'])) === mb_strtolower($row['attribute'])) {
                    $match = $item;
                    break;
                }
            }
            $verification[] = [
                'status'       => $match['status'] ?? 'uncertain',
                'status_label' => $this->status_label($match['status'] ?? 'uncertain'),
                'note'         => $match['note'] ?? '',
            ];
        }

        wp_send_json_success(['verification' => $verification]);
    }

    private function status_label($status)
    {
        switch ($status) {
            case 'confirmed':
                return 'تأیید شد';
            case 'incorrect':
                return 'اشتباه است';
            default:
                return 'نامشخص/نیاز به بررسی';
        }
    }

    /* ---------------------------------------------------------------------
     * ساخت پرامپت‌ها (با استراتژی جستجوی چندمرحله‌ای و آبشاری)
     * ------------------------------------------------------------------- */

    private function build_generation_prompt($product_name, $attribute_names, $format_url = '')
    {
        $attributes_list = implode("\n- ", $attribute_names);

        $format_instruction = "";
        if (!empty($format_url) && filter_var($format_url, FILTER_VALIDATE_URL)) {
            $format_instruction = "🔴 دستورالعمل بسیار مهم برای قالب‌بندی (Formatting):\n"
                . "برای درک استایل و نحوه نگارش مقادیر، حتماً لینک نمونه زیر را بررسی کن:\n"
                . "لینک نمونه فرمت: {$format_url}\n"
                . "تو موظف هستی مقادیری که پیدا می‌کنی را دقیقاً شبیه به سبک نوشتار و معماری این لینک در بیاوری. به موارد زیر به شدت دقت کن:\n"
                . "۱. نحوه چسبیدن یا فاصله داشتن واحدها (مثل GHz, MB, TB) به اعداد (مثلاً اگر در الگو نوشته شده 3.9GHz تو هم نباید بین عدد و واحد فاصله بیندازی).\n"
                . "۲. نحوه درج لیست‌ها (مثلاً اگر در لینک نمونه مدل‌های پردازنده ی سازگار به صورت لیست زیر هم نوشته شده‌اند، تو هم باید تمام مدل‌های سازگار را پیدا کرده و با استفاده از خط جدید (Enter) زیر هم لیست کنی).\n"
                . "۳. حروف بزرگ و کوچک کلمات انگلیسی.\n"
                . "مقادیر خام را مستقیماً از وب کپی نکن، بلکه آن‌ها را حتماً از فیلتر این الگو رد کن و تغییر ظاهر بده!\n\n";
        }

        $prompt = "تو یک دستیار تخصصی در زمینه استخراج مشخصات فنی سخت‌افزار، سرور و تجهیزات شبکه هستی.\n"
            . "ماموریت تو یافتن کامل مشخصات فنی محصول «{$product_name}» از طریق موتور جستجوی وب برای پر کردن ویژگی‌های زیر است:\n\n"
            . "لیست فیلدهای درخواستی:\n- {$attributes_list}\n\n"
            . "⚡ دستورالعمل الزامی جستجوی عمیق و چندمرحله‌ای (آبشاری):\n"
            . "۱. مرحله اول (منبع اصلی): ابتدا وب‌سایت رسمی سازنده (Official Manufacturer / QuickSpecs / Datasheet) را بررسی کن.\n"
            . "۲. مرحله دوم (Fallback و جستجوی چندمنبعی): اگر هر کدام از ویژگی‌های بالا در سایت اصلی پیدا نشد یا خلاصه ذکر شده بود، جستجو را متوقف نکن! موظف هستی نام محصول را همراه با نام همان ویژگی خاص در وب جستجو کنی و به سایر سایت‌های فنی معتبر، پایگاه‌های داده سخت‌افزار، دیتاشیت‌های توزیع‌کنندگان (مانند IT Creations, ServerMonkey, CNET, TechSpecs و ...) مراجعه کنی تا حتماً مقدار را بیابی.\n"
            . "۳. ترکیب اطلاعات: برای یک محصول مجاز و موظف هستی اطلاعات ویژگی‌های مختلف را از چندین سایت مختلف جمع‌آوری و با یکدیگر ترکیب کنی تا جدول تا حد ممکن کامل و بدون فیلد خالی باشد.\n"
            . "۴. تنها در صورتی برای یک ویژگی مقدار رشته خالی \"\" بگذار که حتی با جستجو در منابع ثانویه وب هم هیچ سند فنی معتبری برای آن وجود نداشته باشد.\n\n"
            . $format_instruction
            . "خروجی را فقط و فقط به‌صورت JSON خام و بدون هیچ توضیح اضافه یا بلاک مارک‌داون (بدون ```json و بدون ```) برگردان:\n"
            . '{"attributes": [{"attribute": "نام ویژگی", "value": "مقدار دقیق و استخراج‌شده"}]}';

        return $prompt;
    }

    private function build_verification_prompt($product_name, $table)
    {
        $table_text = '';
        foreach ($table as $row) {
            $table_text .= "- {$row['attribute']}: {$row['value']}\n";
        }

        $prompt = "به‌عنوان کارشناس کنترل کیفیت، صحت مشخصات فنی ادعا شده برای محصول «{$product_name}» را بررسی کن.\n\n"
            . "دستورالعمل بررسی:\n"
            . "- جستجوی وب را فقط به یک صفحه محدود نکن؛ اگر در یک سایت دیتایی را نیافتی، در سایر منابع و دیتاشیت‌های انگلیسی وب جستجو کن.\n"
            . "- برای هر ویژگی بررسی کن که آیا با مشخصات واقعی این پارت‌نامبر/مدل همخوانی دارد یا خیر.\n\n"
            . "جدول مشخصات برای بررسی:\n{$table_text}\n\n"
            . "وضعیت‌ها:\n"
            . "confirmed: در صورتی که در منابع فنی تأیید شود.\n"
            . "incorrect: در صورتی که با مشخصات مدل مغایرت آشکار داشته باشد (در فیلد note مقدار صحیح را بگو).\n"
            . "uncertain: فقط اگر بعد از جستجو در چند سایت باز هم به نتیجه قطعی نرسیدی.\n\n"
            . "خروجی را فقط و فقط به‌صورت JSON خام و بدون هیچ توضیح اضافه یا بک‌تیک، با ساختار زیر برگردان:\n"
            . '{"verification": [{"attribute": "نام ویژگی", "status": "confirmed|incorrect|uncertain", "note": "توضیح کوتاه در صورت نیاز"}]}';

        return $prompt;
    }

    /* ---------------------------------------------------------------------
     * AJAX: پیدا کردن یا ساختن ترم برای ویژگی‌های سراسری (pa_*)
     * ------------------------------------------------------------------- */
    public function ajax_ensure_attribute_term()
    {
        check_ajax_referer('aig_ai_crawler_nonce', 'security');

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (! current_user_can('edit_product', $product_id)) {
            wp_send_json_error(['message' => 'شما اجازه ویرایش این محصول را ندارید.']);
        }

        $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field(wp_unslash($_POST['taxonomy'])) : '';
        $value    = isset($_POST['value']) ? sanitize_text_field(wp_unslash($_POST['value'])) : '';

        if (! $taxonomy || strpos($taxonomy, 'pa_') !== 0 || ! taxonomy_exists($taxonomy)) {
            wp_send_json_error(['message' => 'نام ویژگی سراسری نامعتبر است.']);
        }
        if ($value === '') {
            wp_send_json_error(['message' => 'مقدار خالی است.']);
        }
        if (! current_user_can('manage_product_terms')) {
            wp_send_json_error(['message' => 'شما اجازه ساخت مقدار جدید برای ویژگی‌ها را ندارید.']);
        }

        $term = get_term_by('name', $value, $taxonomy);
        if (! $term) {
            $term = get_term_by('slug', sanitize_title($value), $taxonomy);
        }

        $created = false;

        if (! $term) {
            $inserted = wp_insert_term($value, $taxonomy);
            if (is_wp_error($inserted)) {
                if ('term_exists' === $inserted->get_error_code() && $inserted->get_error_data()) {
                    $term = get_term((int) $inserted->get_error_data(), $taxonomy);
                } else {
                    wp_send_json_error(['message' => 'خطا در ساخت مقدار جدید: ' . $inserted->get_error_message()]);
                }
            } else {
                $term = get_term($inserted['term_id'], $taxonomy);
                $created = true;
            }
        }

        if (! $term || is_wp_error($term)) {
            wp_send_json_error(['message' => 'خطا در پیدا کردن یا ساختن مقدار ویژگی.']);
        }

        wp_send_json_success([
            'term_id'   => $term->term_id,
            'term_name' => $term->name,
            'created'   => $created,
        ]);
    }

    /* ---------------------------------------------------------------------
     * ارتباط با GapGPT با استفاده از cURL
     * ------------------------------------------------------------------- */
    private function call_gapgpt_api($model, $prompt)
    {
        $api_key = get_option('aig_gapgpt_api_key', $this->default_api_key);

        $ch = curl_init($this->gapgpt_base_url);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => wp_json_encode([
                'model'      => $model,
                'input'      => $prompt,
                'web_search' => true,
            ]),
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 240,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $result   = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            return new WP_Error('aig_curl_error', 'خطا در برقراری شبکه (cURL error ' . $errno . '): ' . $error);
        }

        $body = json_decode($result, true);

        $text = '';
        if (! empty($body['output']) && is_array($body['output'])) {
            foreach ($body['output'] as $output_item) {
                if (isset($output_item['type']) && $output_item['type'] === 'reasoning') {
                    continue;
                }
                if (! empty($output_item['content']) && is_array($output_item['content'])) {
                    foreach ($output_item['content'] as $content_piece) {
                        if (isset($content_piece['text'])) {
                            $text .= $content_piece['text'];
                        }
                    }
                }
            }
        }

        if ($text !== '') {
            return $text;
        }

        if (isset($body['output_text']) && is_string($body['output_text'])) {
            return $body['output_text'];
        }

        return new WP_Error('aig_bad_response', 'پاسخ نامعتبر از هوش مصنوعی دریافت شد: ' . wp_strip_all_tags($result));
    }

    /**
     * حذف بک‌تیک/کدفنس احتمالی و تبدیل متن به آرایه JSON
     */
    private function extract_json_from_text($text)
    {
        $text = trim($text);
        $text = preg_replace('/^```(json)?/i', '', $text);$text = preg_replace('/```$/', '', $text);
        $text = trim($text);

        $decoded = json_decode($text, true);
        return $decoded ?: null;
    }

    /* =====================================================================
     * صفحه تنظیمات: کلید API و مدل‌ها
     * =================================================================== */
    public function register_settings_page()
    {
        add_options_page(
            'AI Attributes Crawler',
            'تنظیمات هوش مصنوعی (Crawler)',
            'manage_options',
            'aig-ai-crawler-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings_fields()
    {
        register_setting('aig_settings_group', 'aig_gapgpt_api_key');
        register_setting('aig_settings_group', 'aig_primary_model');
        register_setting('aig_settings_group', 'aig_verifier_model');
    }

    public function render_settings_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
?>
        <div class="wrap">
            <h1>تنظیمات AI Attributes Crawler (با GapGPT)</h1>
            <form method="post" action="options.php">
                <?php settings_fields('aig_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">کلید API گپ‌جی‌پی‌تی (GapGPT)</th>
                        <td>
                            <input type="text" style="width:400px;" name="aig_gapgpt_api_key" value="<?php echo esc_attr(get_option('aig_gapgpt_api_key', $this->default_api_key)); ?>" />
                            <br><small style="color: #666;">در صورت خالی گذاشتن از کلید پیش‌فرض داخل کد استفاده می‌شود.</small>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">مدل مرحله اول (تولید مشخصات)</th>
                        <td>
                            <select name="aig_primary_model" style="width: 400px;">
                                <option value="gemini-3.1-flash-lite" <?php selected(get_option('aig_primary_model', 'gemini-3.1-flash-lite'), 'gemini-3.1-flash-lite'); ?>>Gemini 3.1 Flash Lite</option>
                                <option value="gemini-2.5-pro" <?php selected(get_option('aig_primary_model', 'gemini-3.1-flash-lite'), 'gemini-2.5-pro'); ?>>Gemini 2.5 Pro (پیشنهادی)</option>
                                <option value="gpt-4o" <?php selected(get_option('aig_primary_model', 'gemini-3.1-flash-lite'), 'gpt-4o'); ?>>GPT-4o</option>
                                <option value="claude-3-5-sonnet-20241022" <?php selected(get_option('aig_primary_model', 'gemini-3.1-flash-lite'), 'claude-3-5-sonnet-20241022'); ?>>Claude 3.5 Sonnet</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">مدل مرحله دوم (بررسی صحت و تصحیح)</th>
                        <td>
                            <select name="aig_verifier_model" style="width: 400px;">
                                <option value="gpt-4o" <?php selected(get_option('aig_verifier_model', 'gpt-4o'), 'gpt-4o'); ?>>GPT-4o (پیشنهادی)</option>
                                <option value="gemini-2.5-pro" <?php selected(get_option('aig_verifier_model', 'gpt-4o'), 'gemini-2.5-pro'); ?>>Gemini 2.5 Pro</option>
                                <option value="claude-3-5-sonnet-20241022" <?php selected(get_option('aig_verifier_model', 'gpt-4o'), 'claude-3-5-sonnet-20241022'); ?>>Claude 3.5 Sonnet</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <p style="color:#666;font-size:12px;">
                    توصیه می‌شود مدل‌های مرحله اول و دوم را متفاوت انتخاب کنید تا فرآیند بازبینی، مستقل و دقیق‌تر انجام شود. (قابلیت جستجوی آنلاین برای تمام درخواست‌ها در API به‌صورت خودکار روشن است).
                </p>
                <?php submit_button(); ?>
            </form>
        </div>
<?php
    }
}