<?php
class Search_Product_thumbnail
{
    // در صورت نیاز به استفاده از پروکسی برای دانلود تصاویر، مقدار true قرار دهید
    private $use_proxy_for_download = true;
    private $proxy = '46.249.103.44:8888';

    private $proxy_auth = 'myuser:mypass';

    public function __construct()
    {
        add_action( 'add_meta_boxes', [$this, 'add_ai_image_search_metabox' ] );
        add_action( 'wp_ajax_search_product_images', [$this, 'handle_search_product_images' ] );
        add_action( 'wp_ajax_nopriv_search_product_images', [$this, 'handle_search_product_images' ] );
        
        // هوک تنظیم تصویر شاخص همراه با اعمال واترمارک
        add_action( 'wp_ajax_set_ai_featured_image', [$this, 'handle_set_featured_image' ] );
        add_action( 'wp_ajax_proxy_ai_image', [$this, 'handle_proxy_image' ] );
    }

    public function add_ai_image_search_metabox() {
        add_meta_box(
            'ai_image_search_box',
            'جستجوی هوشمند تصویر شاخص',
            [$this, 'render_ai_image_search_metabox'],
            'product',
            'side',
            'high'
        );
    }

    /**
     * دانلود امن تصویر با هدرهای مرورگر برای جلوگیری از 403 Forbidden و Timeout
     */
    private function download_image_robust( $url ) {
        $temp_file = wp_tempnam( 'prod_img_' );
        if ( ! $temp_file ) {
            return new WP_Error( 'temp_file_error', 'امکان ایجاد فایل موقت در سرور وجود ندارد.' );
        }

        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Referer: ' . parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/',
        ];

        // تلاش اول: بررسی وضعیت استفاده از پروکسی
        $use_proxy = $this->use_proxy_for_download;

        $attempt_download = function( $with_proxy ) use ( $url, $temp_file, $headers ) {
            $fp = fopen( $temp_file, 'w+' );
            
            // اگر نیاز به پروکسی بود، URL تغییر می‌کند
            $target_url = $url;
            if ( $with_proxy ) {
                // آدرس فایل PHP روی سرور خارج خودتان را بگذارید
                $target_url = 'https://proxy.falnic.com/proxy.php?token=OToBdvgjDESwtJiF87kiw8XHq2uQDog8oErDrss3a0PHTXRqD8iYbFOflywyp8El&url=' . urlencode($url);
            }

            $ch = curl_init( $target_url );

            curl_setopt_array( $ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_TIMEOUT        => 35, // کمی بیشتر به خاطر واسط بودن
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            curl_exec( $ch );
            $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $curl_error = curl_error( $ch );
            curl_close( $ch );
            fclose( $fp );

            return [
                'success'    => ($http_code === 200 && filesize( $temp_file ) > 0),
                'http_code'  => $http_code,
                'curl_error' => $curl_error
            ];
        };

        // اجرای درخواست با پروکسی (برای سایت‌های تحریمی نظیر HPE)
        $res = $attempt_download( true );

        // اگر با پروکسی پاسخ نگرفت، بدون پروکسی تست شود
        if ( ! $res['success'] ) {
            $res = $attempt_download( false );
        }

        if ( ! $res['success'] ) {
            @unlink( $temp_file );
            $err = $res['curl_error'] ? " (خطای cURL: {$res['curl_error']})" : "";
            return new WP_Error( 'download_failed', "دانلود عکس ناموفق بود. کد وضعیت HTTP: {$res['http_code']}{$err}" );
        }

        return $temp_file;
    }

    /**
     * پروکسی داخلی برای نمایش تصاویر و جلوگیری از خطای CORS و CORP
     */
    public function handle_proxy_image() {
        $image_url = isset($_GET['url']) ? esc_url_raw( $_GET['url'] ) : '';
        if ( empty( $image_url ) ) {
            status_header( 400 );
            exit('Invalid URL');
        }

        $temp_file = $this->download_image_robust( $image_url );
        if ( is_wp_error( $temp_file ) ) {
            status_header( 500 );
            exit( $temp_file->get_error_message() );
        }

        $filetype = wp_check_filetype( $image_url );
        $mime_type = $filetype['type'];
        if ( empty( $mime_type ) ) {
            $mime_type = mime_content_type( $temp_file ) ?: 'image/jpeg';
        }

        header( 'Content-Type: ' . $mime_type );
        header( 'Content-Length: ' . filesize( $temp_file ) );
        header( 'Cache-Control: public, max-age=86400' );

        readfile( $temp_file );
        @unlink( $temp_file );
        exit;
    }
    /**
     * دانلود تصویر، درج واترمارک و ثبت در رسانه وردپرس به عنوان تصویر شاخص
     */
    public function handle_set_featured_image() {
        check_ajax_referer( 'ai_image_search_nonce', 'security' );

        $image_url = esc_url_raw( $_POST['image_url'] );
        $post_id   = intval( $_POST['post_id'] );
        $debug_log = [];

        if ( empty( $image_url ) || empty( $post_id ) ) {
            wp_send_json_error( [ 'message' => 'اطلاعات ارسالی نامعتبر است.' ] );
        }

        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );

        // مسیر واترمارک
        $watermark_path = plugin_dir_path( __FILE__ ) . '../assets/img/product-template.png';

        if ( ! file_exists( $watermark_path ) ) {
            wp_send_json_error( [
                'message' => "فایل واترمارک در سرور یافت نشد:\n" . $watermark_path,
                'debug'   => $debug_log
            ] );
            return;
        }

        // ۱. دانلود از طریق cURL و پروکسی مجاز (بدون درگیر شدن با بلاک وردپرس)
        $temp_product_file = $this->download_image_robust( $image_url );
        if ( is_wp_error( $temp_product_file ) ) {
            wp_send_json_error( [
                'message' => $temp_product_file->get_error_message(),
                'debug'   => $debug_log
            ] );
            return;
        }

        // ۲. اعمال لایه‌ها: پس‌زمینه سفید + محصول + واترمارک
        $final_file_path = $this->apply_watermark_to_image( $temp_product_file, $watermark_path, $debug_log );
        @unlink( $temp_product_file ); // حذف عکس خام دانلود شده

        if ( is_wp_error( $final_file_path ) ) {
            wp_send_json_error( [
                'message' => 'خطا در ساخت واترمارک: ' . $final_file_path->get_error_message(),
                'debug'   => $debug_log
            ] );
            return;
        }

        // ۳. ذخیره مستقیم در دایرکتوری uploads وردپرس به صورت بومی
        // (این روش از مسدودسازی WP_HTTP_BLOCK_EXTERNAL عبور می‌کند چون کاملاً لوکال است)
        $upload_dir = wp_upload_dir();
        $filename   = sanitize_file_name( get_the_title( $post_id ) . '-featured-' . time() . '.webp' );
        $dest_path  = $upload_dir['path'] . '/' . $filename;

        // انتقال فایل ساخته شده به پوشه آپلودها
        if ( ! @rename( $final_file_path, $dest_path ) ) {
            copy( $final_file_path, $dest_path );
            @unlink( $final_file_path );
        }

        // ثبت فایل در دیتابیس وردپرس به عنوان Attachment
        $filetype = wp_check_filetype( $filename, null );
        $attachment = [
            'post_mime_type' => $filetype['type'] ?: 'image/png',
            'post_title'     => sanitize_text_field( get_the_title( $post_id ) ),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];

        $attachment_id = wp_insert_attachment( $attachment, $dest_path, $post_id );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            @unlink( $dest_path );
            wp_send_json_error( [ 'message' => 'خطا در ثبت پرونده در دیتابیس رسانه وردپرس.' ] );
            return;
        }

        // ساخت ابعاد و متادیتای تصویر
        $attach_data = wp_generate_attachment_metadata( $attachment_id, $dest_path );
        wp_update_attachment_metadata( $attachment_id, $attach_data );

        // تنظیم به عنوان تصویر شاخص محصول
        set_post_thumbnail( $post_id, $attachment_id );
        $thumbnail_html = wp_get_attachment_image( $attachment_id, 'post-thumbnail' );

        wp_send_json_success( [
            'message'        => 'تصویر شاخص با موفقیت ساخته و تنظیم شد.',
            'thumbnail_html' => $thumbnail_html,
            'debug'          => $debug_log
        ] );
    }

    /**
     * چیدمان لایه‌ای:
     * لایه ۱ (پایین‌ترین): پس‌زمینه سفید خالص
     * لایه ۲ (وسط): عکس محصول در مرکز
     * لایه ۳ (بالاترین): فریم/واترمارک روی همه‌چیز
     */
    private function apply_watermark_to_image( $source_path, $watermark_path, &$debug_log ) {
        if ( ! extension_loaded('gd') ) {
            return new WP_Error( 'gd_missing', 'اکستنشن GD روی سرور فعال نیست.' );
        }

        @ini_set( 'memory_limit', '512M' );

        // ۱. بررسی و خواندن فریم/واترمارک (تعیین ابعاد بستر اصلی)
        $wm_info = @getimagesize( $watermark_path );
        if ( ! $wm_info ) {
            return new WP_Error( 'watermark_invalid', 'فایل واترمارک معتبر نیست یا خوانده نشد.' );
        }

        $watermark = @imagecreatefrompng( $watermark_path );
        if ( ! $watermark ) {
            return new WP_Error( 'watermark_corrupt', 'واترمارک باید یک فایل PNG معتبر باشد.' );
        }

        imagealphablending( $watermark, true );
        imagesavealpha( $watermark, true );

        $canvas_w = $wm_info[0]; // عرض ابعاد فریم
        $canvas_h = $wm_info[1]; // ارتفاع ابعاد فریم

        // ۲. خواندن عکس محصول دانلود شده
        $img_info = @getimagesize( $source_path );
        if ( ! $img_info ) {
            imagedestroy( $watermark );
            return new WP_Error( 'invalid_image', 'تصویر محصول دانلود شده معتبر نیست.' );
        }

        $mime  = $img_info['mime'];
        $src_w = $img_info[0];
        $src_h = $img_info[1];

        switch ( $mime ) {
            case 'image/jpeg':
                $prod_img = @imagecreatefromjpeg( $source_path );
                break;
            case 'image/png':
                $prod_img = @imagecreatefrompng( $source_path );
                break;
            case 'image/webp':
                $prod_img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp( $source_path ) : false;
                break;
            default:
                $prod_img = false;
        }

        if ( ! $prod_img ) {
            imagedestroy( $watermark );
            return new WP_Error( 'image_create_failed', 'فرمت تصویر محصول پشتیبانی نمی‌شود.' );
        }

        // =========================================================================
        // لایه ۱ (پایین‌ترین لایه): ساخت بوم و پر کردن آن با پس‌زمینه سفید خالص
        // =========================================================================
        $final_canvas = imagecreatetruecolor( $canvas_w, $canvas_h );
        $white_color  = imagecolorallocate( $final_canvas, 255, 255, 255 );
        imagefilledrectangle( $final_canvas, 0, 0, $canvas_w, $canvas_h, $white_color );

        // فعال‌سازی Alpha Blending برای نشستن صحیح لایه‌های بعدی با حفظ شفافیت
        imagealphablending( $final_canvas, true );

        // =========================================================================
        // لایه ۲ (لایه وسط): عکس محصول متناسب‌شده در مرکز کادر
        // =========================================================================
        $max_inner_w = (int) ( $canvas_w * 0.75 );
        $max_inner_h = (int) ( $canvas_h * 0.75 );

        $scale = min( $max_inner_w / $src_w, $max_inner_h / $src_h );
        if ( $scale > 1.0 ) {
            $scale = 1.0;
        }

        $target_prod_w = (int) ( $src_w * $scale );
        $target_prod_h = (int) ( $src_h * $scale );

        // موقعیت‌یابی دقیق در مرکز بوم
        $pos_x = (int) ( ( $canvas_w - $target_prod_w ) / 2 );
        $pos_y = (int) ( ( $canvas_h - $target_prod_h ) / 2 );

        // درج عکس محصول بر روی پس‌زمینه سفید
        imagecopyresampled(
            $final_canvas, $prod_img,
            $pos_x, $pos_y, 0, 0,
            $target_prod_w, $target_prod_h,
            $src_w, $src_h
        );

        // =========================================================================
        // لایه ۳ (بالاترین لایه): نشاندن فریم/واترمارک روی همه‌چیز
        // =========================================================================
        imagecopy(
            $final_canvas, $watermark,
            0, 0, 0, 0,
            $canvas_w, $canvas_h
        );

        // خروجی نهایی به صورت تصویر ذخیره می‌شود
        $output_path = wp_tempnam( 'final_frame_' ) . '.webp';
        if ( function_exists( 'imagewebp' ) ) {
            imagewebp( $final_canvas, $output_path, 85 );
        } else {
            imagepng( $final_canvas, $output_path, 8 );
        }

        $debug_log['canvas_size']        = "{$canvas_w}x{$canvas_h}";
        $debug_log['product_resized_to'] = "{$target_prod_w}x{$target_prod_h}";

        imagedestroy( $final_canvas );
        imagedestroy( $prod_img );
        imagedestroy( $watermark );

        return $output_path;
    }

    public function handle_search_product_images() {
        check_ajax_referer( 'ai_image_search_nonce', 'security' );

        $product_title = sanitize_text_field( $_POST['product_title'] );
        $target_site_1 = sanitize_url( $_POST['target_site_1'] );
        $target_site_2 = isset($_POST['target_site_2']) ? sanitize_url( $_POST['target_site_2'] ) : '';
        
        $serper_api_key = '6cb5c63d94f29884848c990e7da5f1b026f2ae2f'; 
        $serpstack_api_key = 'YOUR_SERPSTACK_API_KEY';

        $image_results = [];
        $search_source = '';
        $debug_info = [];

        $site_query = '';
        $sites = array_filter(array_map('trim', [$target_site_1, $target_site_2]));
        
        if ( ! empty( $sites ) ) {
            $formatted_sites = array_map(function($site) {
                $clean_domain = preg_replace('#^https?://#', '', $site);
                $clean_domain = trim( explode('/', $clean_domain)[0], '/' );
                return 'site:' . $clean_domain;
            }, $sites);
            $site_query = implode( ' OR ', $formatted_sites );
        }

        $debug_info['product_title'] = $product_title;
        $debug_info['site_query'] = $site_query;

        $proxy = $this->proxy;

        $fetch_from_serper = function( $query ) use ( $serper_api_key, $proxy, &$debug_info ) {
            $debug_info['used_api'] = 'Serper.dev';
            $payload = json_encode([ 'q' => $query, 'gl' => 'us', 'hl' => 'en' ]);
            
            $proxy_url = 'https://proxy.falnic.com/proxy.php?token=OToBdvgjDESwtJiF87kiw8XHq2uQDog8oErDrss3a0PHTXRqD8iYbFOflywyp8El&url=' . urlencode('https://google.serper.dev/images');
            $ch = curl_init($proxy_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-API-KEY: ' . $serper_api_key,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);


            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ( $response === false || $http_code !== 200 ) {
                $debug_info['serper_error'] = $curl_error ?: 'HTTP '.$http_code;
                return [];
            }

            $data = json_decode( $response, true );
            $urls = [];
            if ( ! empty( $data['images'] ) && is_array( $data['images'] ) ) {
                $count = 0;
                foreach ( $data['images'] as $img ) {
                    if ( $count >= 12 ) break; 
                    if ( !empty( $img['imageUrl'] ) ) {
                        $urls[] = $img['imageUrl'];
                        $count++;
                    }
                }
            }
            return $urls;
        };

        $fetch_from_serpstack = function( $query ) use ( $serpstack_api_key, $proxy, &$debug_info ) {
            $debug_info['used_api'] = 'SerpStack';
            $api_url = 'http://api.serpstack.com/search?access_key=' . $serpstack_api_key . '&query=' . urlencode($query) . '&type=images';
            $proxy_url = 'https://proxy.falnic.com/proxy.php?token=OToBdvgjDESwtJiF87kiw8XHq2uQDog8oErDrss3a0PHTXRqD8iYbFOflywyp8El&url=' . urlencode($api_url);
            
            $ch = curl_init($proxy_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ( $response === false ) {
                $debug_info['serpstack_error'] = 'cURL Error: ' . $curl_error;
                return ['status' => 'error', 'data' => []];
            }

            $data = json_decode($response, true);
            
            if (isset($data['error']) || $http_code !== 200) {
                $debug_info['serpstack_error'] = isset($data['error']) ? json_encode($data['error']) : 'HTTP Error ' . $http_code;
                return ['status' => 'error', 'data' => []];
            }

            $urls = [];
            if ( ! empty( $data['image_results'] ) && is_array( $data['image_results'] ) ) {
                $count = 0;
                foreach ( $data['image_results'] as $img ) {
                    if ( $count >= 12 ) break;
                    $img_url = !empty($img['image_url']) ? $img['image_url'] : (!empty($img['url']) ? $img['url'] : '');
                    if ( !empty($img_url) ) {
                        $urls[] = $img_url;
                        $count++;
                    }
                }
            }
            return ['status' => 'success', 'data' => $urls];
        };

        $fetch_images_with_fallback = function( $query ) use ( $fetch_from_serpstack, $fetch_from_serper, &$debug_info ) {
            $debug_info['last_query'] = $query;
            $result = $fetch_from_serpstack($query);
            
            if ($result['status'] === 'error' || empty($result['data'])) {
                $debug_info['fallback_triggered'] = true;
                return $fetch_from_serper($query);
            }
            return $result['data'];
        };

        if ( ! empty( $site_query ) ) {
            $specific_query = $product_title . ' ' . $site_query;
            $debug_info['search_type'] = 'specific';
            $image_results = $fetch_images_with_fallback( $specific_query );
            $search_source = 'specific';
        }

        if ( empty( $image_results ) ) {
            $debug_info['search_type'] = 'fallback';
            $image_results = $fetch_images_with_fallback( $product_title );
            $search_source = 'google_fallback';
        }

        $debug_info['final_image_count'] = count($image_results);

        if ( empty( $image_results ) ) {
            wp_send_json_error( [
                'message' => 'هیچ تصویری برای این محصول یافت نشد.',
                'debug' => $debug_info
            ] );
            return;
        }

        $image_results = array_map(function($url) {
            if (strpos($url, 'http') !== 0) {
                if (strpos($url, '//') === 0) return 'https:' . $url;
                return 'https://' . ltrim($url, '/');
            }
            return $url;
        }, $image_results);

        wp_send_json_success([
            'images' => $image_results,
            'source' => $search_source,
            'debug' => $debug_info
        ]);
    }

    public function render_ai_image_search_metabox( $post ) {
        wp_nonce_field( 'ai_image_search_nonce', 'ai_image_search_nonce_field' );
        ?>
        <div style="margin-bottom: 10px;">
            <label for="target_site_1" style="display: block; font-weight: bold; margin-bottom: 5px;">
                لینک سایت مرجع اول <span style="color: #dc3232;">*</span>
            </label>
            <input type="url" id="target_site_1" style="width:100%; margin-top:5px;" placeholder="https://example.com" />
        </div>
        <div style="margin-bottom: 10px;">
            <label for="target_site_2" style="display: block; font-weight: bold; margin-bottom: 5px;">
                لینک سایت مرجع دوم <span style="color: #999; font-size: 11px;">(اختیاری)</span>
            </label>
            <input type="url" id="target_site_2" style="width:100%; margin-top:5px;" placeholder="https://another-site.com" />
        </div>
        <button type="button" id="btn-search-ai-images" class="button button-primary" style="width:100%;">جستجوی تصاویر</button>
        
        <style>
            .ai-image-modal-overlay {
                display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0, 0, 0, 0.7); z-index: 100000; justify-content: center; align-items: center;
            }
            .ai-image-modal-overlay.active { display: flex; }
            .ai-image-modal-content {
                background: #fff; border-radius: 8px; width: 90%; max-width: 900px;
                max-height: 85vh; overflow: hidden; display: flex; flex-direction: column;
                box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            }
            .ai-image-modal-header {
                display: flex; justify-content: space-between; align-items: center;
                padding: 15px 20px; border-bottom: 1px solid #ddd; background: #f5f5f5;
            }
            .ai-image-modal-header h3 { margin: 0; font-size: 18px; color: #333; }
            .ai-image-modal-close {
                background: none; border: none; font-size: 24px; cursor: pointer; color: #666;
                padding: 0; width: 30px; height: 30px; display: flex; align-items: center;
                justify-content: center; border-radius: 4px;
            }
            .ai-image-modal-close:hover { background: #e0e0e0; color: #333; }
            .ai-image-modal-body { padding: 20px; overflow-y: auto; flex: 1; }
            .ai-image-modal-source {
                margin-bottom: 15px; padding: 10px; background: #e8f4f8;
                border-radius: 5px; font-size: 14px; color: #0066cc;
            }
            .ai-image-modal-loading { text-align: center; padding: 40px; color: #666; }
            .ai-image-modal-error { text-align: center; padding: 40px; color: #dc3232; }
            .ai-image-list { list-style: none; margin: 0; padding: 0; }
            .ai-image-list li {
                display: flex; align-items: center; padding: 12px;
                border-bottom: 1px solid #eee; gap: 15px;
            }
            .ai-image-list li:last-child { border-bottom: none; }
            .ai-image-list li:hover { background: #f9f9f9; }
            .ai-image-list img {
                width: 80px; height: 80px; object-fit: cover; border-radius: 5px;
                border: 1px solid #ddd; flex-shrink: 0;
            }
            .ai-image-list .image-info { flex: 1; min-width: 0; }
            .ai-image-list .image-url {
                font-size: 12px; color: #666; word-break: break-all;
                margin-bottom: 8px; direction: ltr;
            }
            .ai-image-list .image-actions { display: flex; gap: 8px; flex-wrap: wrap;}
            .ai-image-modal-footer {
                padding: 12px 20px; border-top: 1px solid #ddd;
                background: #f5f5f5; text-align: right;
            }
        </style>
        
        <div id="ai-image-modal" class="ai-image-modal-overlay">
            <div class="ai-image-modal-content">
                <div class="ai-image-modal-header">
                    <h3>نتایج جستجوی تصویر</h3>
                    <button type="button" class="ai-image-modal-close">&times;</button>
                </div>
                <div class="ai-image-modal-body" id="ai-image-modal-body"></div>
                <div class="ai-image-modal-footer">
                    <button type="button" class="button">بستن</button>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            function openModal() {
                $('#ai-image-modal').addClass('active');
                $('body').css('overflow', 'hidden');
            }
            
            function closeModal() {
                $('#ai-image-modal').removeClass('active');
                $('body').css('overflow', '');
            }
            
            $('.ai-image-modal-close, .ai-image-modal-footer button').on('click', function(e) {
                if (e.target === this) closeModal();
            });
            
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $('#ai-image-modal').hasClass('active')) closeModal();
            });

            $('#btn-search-ai-images').on('click', function() {
                var button = $(this);
                var site1 = $('#target_site_1').val().trim();
                var site2 = $('#target_site_2').val().trim();
                var productTitle = $('#title').val();
                
                if(!productTitle) {
                    alert('لطفا ابتدا نام محصول را وارد کنید.');
                    return;
                }
                if(!site1) {
                    alert('لطفا لینک سایت مرجع اول را وارد کنید.');
                    $('#target_site_1').focus();
                    return;
                }

                button.text('⏳ در حال جستجو...').prop('disabled', true);
                openModal();
                $('#ai-image-modal-body').html(`
                    <div class="ai-image-modal-loading">
                        <p>⏳ لطفاً منتظر بمانید...</p>
                        <p style="font-size: 12px; color: #999;">تصاویر در حال دریافت هستند</p>
                    </div>
                `);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'search_product_images',
                        security: $('#ai_image_search_nonce_field').val(),
                        product_title: productTitle,
                        target_site_1: site1,
                        target_site_2: site2,
                        post_id: <?php echo $post->ID; ?>
                    },
                    success: function(response) {
                        button.text('جستجوی تصاویر').prop('disabled', false);
                        
                        if(response.success && response.data.images.length > 0) {
                            let sourceText = response.data.source === 'specific' 
                                ? '✅ تصاویر از سایت‌های مرجع یافت شدند:' 
                                : '⚠️ از سایت‌های مرجع تصویری یافت نشد، در کل وب جستجو شد:';
                                
                            let apiUsed = response.data.debug && response.data.debug.used_api ? response.data.debug.used_api : 'نامشخص';
                            sourceText += ` <span style="font-size:11px; color:#888;">(از طریق API: ${apiUsed})</span>`;
                            
                            let imagesList = '<ul class="ai-image-list">';
                            
                            $.each(response.data.images, function(i, imgUrl) {
                                let proxyImgUrl = ajaxurl + '?action=proxy_ai_image&url=' + encodeURIComponent(imgUrl);
                                imagesList += `
                                    <li>
                                        <img src="${proxyImgUrl}" alt="${productTitle}" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                                        <span style="display:none; width:80px; height:80px; background:#f0f0f0; align-items:center; justify-content:center; border-radius:5px; font-size:11px; color:#999; flex-shrink:0; text-align:center; padding:5px;">خطا در بارگذاری</span>
                                        <div class="image-info">
                                            <div class="image-url">${imgUrl}</div>
                                            <div class="image-actions">
                                                <button type="button" class="button button-primary btn-set-featured" data-url="${imgUrl}">
                                                    انتخاب با واترمارک به عنوان تصویر شاخص
                                                </button>
                                                <a href="${imgUrl}" target="_blank" class="button button-small">مشاهده</a>
                                                <button type="button" class="button button-small btn-copy-url" data-url="${imgUrl}">کپی لینک</button>
                                            </div>
                                        </div>
                                    </li>
                                `;
                            });
                            
                            imagesList += '</ul>';
                            
                            $('#ai-image-modal-body').html(`
                                <div class="ai-image-modal-source">${sourceText} (${response.data.images.length} تصویر)</div>
                                ${imagesList}
                            `);
                            
                            $('.btn-copy-url').on('click', function() {
                                var url = $(this).data('url');
                                if (navigator.clipboard && navigator.clipboard.writeText) {
                                    navigator.clipboard.writeText(url).then(function() {
                                        alert('لینک کپی شد: ' + url);
                                    }).catch(function() { fallbackCopy(url); });
                                } else {
                                    fallbackCopy(url);
                                }
                            });
                            
                            // تنظیم تصویر شاخص همراه با لاگ دیباگ
                            $('.btn-set-featured').on('click', function() {
                                var btn = $(this);
                                var url = btn.data('url');
                                var originalText = btn.text();
                                
                                btn.text('⏳ در حال دانلود و واترمارک...').prop('disabled', true);
                                
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'set_ai_featured_image',
                                        security: $('#ai_image_search_nonce_field').val(),
                                        image_url: url,
                                        post_id: <?php echo $post->ID; ?>
                                    },
                                    success: function(res) {
                                        console.log('پاسخ سرور و لاگ دیباگ:', res);
                                        if(res.success) {
                                            if($('#set-post-thumbnail').length) {
                                                $('#set-post-thumbnail').html(res.data.thumbnail_html);
                                                $('#remove-post-thumbnail').show();
                                            }
                                            
                                            btn.text('✅ با موفقیت تنظیم شد').removeClass('button-primary');
                                            setTimeout(function() { closeModal(); }, 1200);
                                        } else {
                                            let errorDetails = res.data && res.data.message ? res.data.message : 'خطای نامشخص';
                                            if(res.data && res.data.debug) {
                                                console.error('اطلاعات دقیق دیباگ:', res.data.debug);
                                            }
                                            alert('خطا در پردازش تصویر:\n' + errorDetails);
                                            btn.text(originalText).prop('disabled', false);
                                        }
                                    },
                                    error: function(xhr, status, error) {
                                        console.error('خطای شبکه AJAX:', xhr.responseText);
                                        alert('خطا در برقراری ارتباط با سرور: ' + error);
                                        btn.text(originalText).prop('disabled', false);
                                    }
                                });
                            });
                            
                        } else if(response.success === false) {
                            let errorMsg = '<div class="ai-image-modal-error"><p>❌ ' + (response.data.message || 'تصویری یافت نشد.') + '</p></div>';
                            $('#ai-image-modal-body').html(errorMsg);
                        }
                    },
                    error: function() {
                        button.text('جستجوی تصاویر').prop('disabled', false);
                        $('#ai-image-modal-body').html('<div class="ai-image-modal-error"><p>❌ خطا در ارتباط با سرور</p></div>');
                    }
                });
            });
            
            function fallbackCopy(text) {
                var tempInput = $('<input>');
                $('body').append(tempInput);
                tempInput.val(text).select();
                document.execCommand('copy');
                tempInput.remove();
                alert('لینک کپی شد: ' + text);
            }
        });
        </script>
        <?php
    }
}