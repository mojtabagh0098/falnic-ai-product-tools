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
     * اعتبارسنجی URLهای واردشده؛ فقط آدرس‌های HTTP/HTTPS و قابل‌دسترسی عمومی پذیرفته می‌شوند.
     */
    private function validate_http_url( $url ) {
        $url = html_entity_decode( trim( (string) $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $url = esc_url_raw( $url, [ 'http', 'https' ] );

        if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
            return '';
        }

        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
            return '';
        }

        return $url;
    }

    /**
     * آماده‌سازی لینک مرجع که کاربر در پنل محصول وارد می‌کند.
     */
    private function sanitize_reference_url( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return '';
        }

        if ( 0 === strpos( $url, '//' ) ) {
            $url = 'https:' . $url;
        } elseif ( ! preg_match( '#^https?://#i', $url ) ) {
            $url = 'https://' . $url;
        }

        return $this->validate_http_url( $url );
    }

    /**
     * تبدیل آدرس نسبی تصاویر داخل صفحه به URL مطلق و امن.
     */
    private function resolve_reference_image_url( $candidate, $page_url ) {
        $candidate = html_entity_decode( trim( (string) $candidate ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( '' === $candidate || '#' === substr( $candidate, 0, 1 ) || preg_match( '#^(?:data|blob|javascript):#i', $candidate ) ) {
            return '';
        }

        if ( preg_match( '#^https?://#i', $candidate ) ) {
            return $this->validate_http_url( $candidate );
        }

        $base = wp_parse_url( $page_url );
        if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
            return '';
        }

        $scheme = strtolower( $base['scheme'] );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            return '';
        }

        if ( 0 === strpos( $candidate, '//' ) ) {
            return $this->validate_http_url( $scheme . ':' . $candidate );
        }

        // رد سایر schemeها مانند javascript: و file: را بده.
        if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $candidate ) ) {
            return '';
        }

        $candidate_parts = wp_parse_url( $candidate );
        if ( false === $candidate_parts ) {
            return '';
        }

        $path = isset( $candidate_parts['path'] ) ? $candidate_parts['path'] : '';
        if ( '' === $path ) {
            $path = isset( $base['path'] ) ? $base['path'] : '/';
        } elseif ( '/' !== substr( $path, 0, 1 ) ) {
            $base_path = isset( $base['path'] ) ? $base['path'] : '/';
            $base_dir  = preg_replace( '#[^/]*$#', '', $base_path );
            $path      = $base_dir . $path;
        }

        // حذف بخش‌های نقطه‌ای مسیر برای جلوگیری از URLهای مبهم.
        $segments = [];
        foreach ( explode( '/', $path ) as $segment ) {
            if ( '' === $segment || '.' === $segment ) {
                continue;
            }
            if ( '..' === $segment ) {
                array_pop( $segments );
                continue;
            }
            $segments[] = $segment;
        }
        $normalized_path = '/' . implode( '/', $segments );
        if ( '/' !== $normalized_path && '/' === substr( $path, -1 ) ) {
            $normalized_path .= '/';
        }

        $origin = $scheme . '://' . $base['host'];
        if ( ! empty( $base['port'] ) ) {
            $origin .= ':' . absint( $base['port'] );
        }

        $absolute_url = $origin . $normalized_path;
        if ( isset( $candidate_parts['query'] ) ) {
            $absolute_url .= '?' . $candidate_parts['query'];
        }

        return $this->validate_http_url( $absolute_url );
    }

    /**
     * تشخیص لینک مستقیم تصویر بر اساس پسوند URL.
     */
    private function is_direct_image_url( $url ) {
        $path = wp_parse_url( $url, PHP_URL_PATH );
        return is_string( $path ) && (bool) preg_match( '/\.(?:jpe?g|png|webp|gif|avif|bmp|tiff?|svg)$/i', $path );
    }

    /**
     * دریافت عکس‌های صفحه مرجع؛ لینک مستقیم تصویر هم بدون جستجوی دوباره وارد نتایج می‌شود.
     */
    private function get_reference_images( $url ) {
        if ( $this->is_direct_image_url( $url ) ) {
            return [ $url ];
        }

        $url_parts = wp_parse_url( $url );
        if ( ( empty( $url_parts['path'] ) || '/' === $url_parts['path'] ) && empty( $url_parts['query'] ) ) {
            // آدرس دامنه‌ی اصلی را برای جستجوی site: نگه می‌داریم، اما لوگوی صفحه اصلی را نتیجه محسوب نمی‌کنیم.
            return [];
        }

        $response = wp_safe_remote_get( $url, [
            'timeout'             => 12,
            'redirection'         => 4,
            'limit_response_size' => 2097152,
            'user-agent'          => 'Mozilla/5.0 (compatible; FalnicAIProductTools/1.0; WordPress)',
            'headers'             => [
                'Accept'        => 'text/html,application/xhtml+xml,image/*;q=0.8,*/*;q=0.5',
                'Cache-Control' => 'no-cache',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        if ( $status_code < 200 || $status_code >= 400 ) {
            return [];
        }

        $content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
        if ( 0 === strpos( $content_type, 'image/' ) ) {
            return [ $url ];
        }

        $html = wp_remote_retrieve_body( $response );
        if ( '' === trim( $html ) ) {
            return [];
        }

        // بعضی CDNها برای تصویرِ بدون پسوند، نوع application/octet-stream می‌فرستند.
        if ( function_exists( 'getimagesizefromstring' ) && @getimagesizefromstring( $html ) ) {
            return [ $url ];
        }

        // اگر سرور نوع محتوا را اشتباه گزارش کند، فقط در صورت وجود نشانه‌های HTML آن را parse می‌کنیم.
        $looks_like_html = (bool) preg_match( '/<\\s*(?:!doctype\\s+html|html|head|body|meta|img|picture|source)\\b/i', $html );
        if ( '' !== $content_type && false === strpos( $content_type, 'html' ) && false === strpos( $content_type, 'xml' ) && ! $looks_like_html ) {
            return [];
        }

        return $this->extract_reference_page_images( $html, $url );
    }

    /**
     * استخراج تصویر شاخص صفحه (Open Graph/Twitter) و تصاویر بدنه مقاله.
     */
    private function extract_reference_page_images( $html, $page_url ) {
        $images = [];
        $add_image = function ( $candidate ) use ( &$images, $page_url ) {
            $image_url = $this->resolve_reference_image_url( $candidate, $page_url );
            if ( $image_url && ! in_array( $image_url, $images, true ) && count( $images ) < 20 ) {
                $images[] = $image_url;
            }
        };

        if ( class_exists( 'DOMDocument' ) ) {
            $document = new DOMDocument();
            $previous_error_setting = libxml_use_internal_errors( true );
            $loaded = $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
            libxml_clear_errors();
            libxml_use_internal_errors( $previous_error_setting );

            if ( $loaded ) {
                $preferred_meta = [ 'og:image', 'og:image:secure_url', 'twitter:image', 'twitter:image:src' ];
                foreach ( $document->getElementsByTagName( 'meta' ) as $meta ) {
                    $key = strtolower( $meta->getAttribute( 'property' ) );
                    if ( '' === $key ) {
                        $key = strtolower( $meta->getAttribute( 'name' ) );
                    }
                    if ( in_array( $key, $preferred_meta, true ) ) {
                        $add_image( $meta->getAttribute( 'content' ) );
                    }
                }

                foreach ( $document->getElementsByTagName( 'link' ) as $link ) {
                    $rel = strtolower( $link->getAttribute( 'rel' ) );
                    if ( false !== strpos( $rel, 'image_src' ) ) {
                        $add_image( $link->getAttribute( 'href' ) );
                    }
                }

                foreach ( [ 'img', 'source' ] as $tag_name ) {
                    foreach ( $document->getElementsByTagName( $tag_name ) as $image_element ) {
                        foreach ( [ 'data-src', 'data-lazy-src', 'data-original', 'data-actualsrc', 'src' ] as $attribute ) {
                            $add_image( $image_element->getAttribute( $attribute ) );
                        }
                        $srcset = $image_element->getAttribute( 'data-srcset' );
                        if ( '' === $srcset ) {
                            $srcset = $image_element->getAttribute( 'srcset' );
                        }
                        $this->add_srcset_images( $srcset, $add_image );
                    }
                }
            }
        } else {
            // جایگزین سبک در محیط‌هایی که افزونه DOM نصب نشده باشد.
            if ( preg_match_all( '/<(meta|link)\b[^>]*>/is', $html, $tag_matches ) ) {
                foreach ( $tag_matches[0] as $tag ) {
                    $key = strtolower( $this->get_html_attribute( $tag, 'property' ) );
                    if ( '' === $key ) {
                        $key = strtolower( $this->get_html_attribute( $tag, 'name' ) );
                    }
                    if ( in_array( $key, [ 'og:image', 'og:image:secure_url', 'twitter:image', 'twitter:image:src' ], true ) ) {
                        $add_image( $this->get_html_attribute( $tag, 'content' ) );
                    } elseif ( 'link' === strtolower( $this->get_html_tag_name( $tag ) ) && false !== strpos( strtolower( $this->get_html_attribute( $tag, 'rel' ) ), 'image_src' ) ) {
                        $add_image( $this->get_html_attribute( $tag, 'href' ) );
                    }
                }
            }

            if ( preg_match_all( '/<(?:img|source)\b[^>]*>/is', $html, $image_tags ) ) {
                foreach ( $image_tags[0] as $tag ) {
                    foreach ( [ 'data-src', 'data-lazy-src', 'data-original', 'data-actualsrc', 'src' ] as $attribute ) {
                        $add_image( $this->get_html_attribute( $tag, $attribute ) );
                    }
                    $srcset = $this->get_html_attribute( $tag, 'data-srcset' );
                    if ( '' === $srcset ) {
                        $srcset = $this->get_html_attribute( $tag, 'srcset' );
                    }
                    $this->add_srcset_images( $srcset, $add_image );
                }
            }
        }

        return $images;
    }

    private function add_srcset_images( $srcset, $add_image ) {
        foreach ( explode( ',', (string) $srcset ) as $srcset_item ) {
            $parts = preg_split( '/\s+/', trim( $srcset_item ) );
            if ( ! empty( $parts[0] ) ) {
                $add_image( $parts[0] );
            }
        }
    }

    private function get_html_attribute( $tag, $attribute ) {
        $attribute = preg_quote( $attribute, '/' );
        if ( preg_match( "/\\b" . $attribute . "\\s*=\\s*([\"'])(.*?)\\1/is", $tag, $matches ) ) {
            return html_entity_decode( $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        }
        if ( preg_match( '/\\b' . $attribute . '\\s*=\\s*([^\\s>]+)/i', $tag, $matches ) ) {
            return html_entity_decode( trim( $matches[1], "\"'" ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        }
        return '';
    }

    private function get_html_tag_name( $tag ) {
        return preg_match( '/^<\s*([a-z0-9]+)/i', $tag, $matches ) ? $matches[1] : '';
    }

    /**
     * ساختن عملگر site: با حفظ مسیر صفحه؛ به همین دلیل لینک تغییرکرده به کوئری جدید تبدیل می‌شود.
     */
    private function get_site_search_operator( $url ) {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) {
            return '';
        }

        $path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
        return 'site:' . strtolower( $parts['host'] ) . $path;
    }

    private function normalize_search_image_url( $url ) {
        $url = trim( (string) $url );
        if ( 0 === strpos( $url, '//' ) ) {
            $url = 'https:' . $url;
        } elseif ( ! preg_match( '#^https?://#i', $url ) ) {
            $url = 'https://' . ltrim( $url, '/' );
        }
        return $this->validate_http_url( $url );
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

        $image_url = isset( $_POST['image_url'] ) && is_string( $_POST['image_url'] )
            ? $this->normalize_search_image_url( wp_unslash( $_POST['image_url'] ) )
            : '';
        $post_id = isset( $_POST['post_id'] ) && is_scalar( $_POST['post_id'] )
            ? absint( wp_unslash( $_POST['post_id'] ) )
            : 0;
        $file_name = isset( $_POST['image_filename'] ) && is_string( $_POST['image_filename'] )
            ? sanitize_text_field( wp_unslash( $_POST['image_filename'] ) )
            : '';
        $debug_log = [];

        if ( empty( $image_url ) || empty( $post_id ) || 'product' !== get_post_type( $post_id ) ) {
            wp_send_json_error( [ 'message' => 'اطلاعات ارسالی نامعتبر است.' ] );
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'شما اجازه ویرایش این محصول را ندارید.' ] );
            return;
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

        // ۱. دانلود تصویر انتخاب‌شده
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
        @unlink( $temp_product_file );

        if ( is_wp_error( $final_file_path ) ) {
            wp_send_json_error( [
                'message' => 'خطا در ساخت واترمارک: ' . $final_file_path->get_error_message(),
                'debug'   => $debug_log
            ] );
            return;
        }

        // ۳. ثبت فایل با نام انتخاب‌شده در دایرکتوری uploads وردپرس
        $upload_dir = wp_upload_dir();
        if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['path'] ) || ! wp_mkdir_p( $upload_dir['path'] ) ) {
            @unlink( $final_file_path );
            wp_send_json_error( [ 'message' => 'پوشه آپلود وردپرس در دسترس نیست.' ] );
            return;
        }

        $extension = strtolower( pathinfo( $final_file_path, PATHINFO_EXTENSION ) );
        if ( ! in_array( $extension, [ 'webp', 'png' ], true ) ) {
            @unlink( $final_file_path );
            wp_send_json_error( [ 'message' => 'فرمت فایل نهایی تصویر پشتیبانی نمی‌شود.' ] );
            return;
        }

        if ( '' !== trim( $file_name ) ) {
            $file_base = sanitize_file_name( $file_name );
            $file_base = preg_replace( '/\.(?:jpe?g|png|webp|gif|avif|bmp)$/i', '', $file_base );
            $file_base = trim( $file_base, '.-_ ' );
        } else {
            $file_base = sanitize_file_name( get_the_title( $post_id ) . '-featured-' . time() );
        }

        if ( '' === $file_base ) {
            $file_base = 'product-' . $post_id . '-featured-' . time();
        }

        // اگر نام تکراری باشد، وردپرس به‌صورت امن پسوند عددی اضافه می‌کند.
        $filename  = wp_unique_filename( $upload_dir['path'], $file_base . '.' . $extension );
        $dest_path = trailingslashit( $upload_dir['path'] ) . $filename;

        if ( ! @rename( $final_file_path, $dest_path ) ) {
            $copied = @copy( $final_file_path, $dest_path );
            @unlink( $final_file_path );
            if ( ! $copied ) {
                @unlink( $dest_path );
                wp_send_json_error( [ 'message' => 'انتقال فایل تصویر به پوشه آپلود ناموفق بود.' ] );
                return;
            }
        }

        // ثبت فایل در دیتابیس وردپرس به عنوان Attachment
        $filetype = wp_check_filetype( $filename, null );
        $attachment = [
            'post_mime_type' => $filetype['type'] ?: ( 'webp' === $extension ? 'image/webp' : 'image/png' ),
            'post_title'     => sanitize_text_field( $file_base ),
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
            'filename'       => $filename,
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

        // خروجی نهایی به صورت WebP (یا PNG در سرورهای بدون WebP) ذخیره می‌شود.
        $temp_path = wp_tempnam( 'final_frame_' );
        if ( ! $temp_path ) {
            imagedestroy( $final_canvas );
            imagedestroy( $prod_img );
            imagedestroy( $watermark );
            return new WP_Error( 'temp_file_error', 'امکان ایجاد فایل موقت تصویر نهایی وجود ندارد.' );
        }
        @unlink( $temp_path );

        if ( function_exists( 'imagewebp' ) ) {
            $output_path = $temp_path . '.webp';
            $saved = imagewebp( $final_canvas, $output_path, 85 );
        } else {
            $output_path = $temp_path . '.png';
            $saved = imagepng( $final_canvas, $output_path, 8 );
        }

        $debug_log['canvas_size']        = "{$canvas_w}x{$canvas_h}";
        $debug_log['product_resized_to'] = "{$target_prod_w}x{$target_prod_h}";

        imagedestroy( $final_canvas );
        imagedestroy( $prod_img );
        imagedestroy( $watermark );

        if ( ! $saved || ! file_exists( $output_path ) ) {
            @unlink( $output_path );
            return new WP_Error( 'image_save_failed', 'ذخیره تصویر واترمارک‌شده ناموفق بود.' );
        }

        return $output_path;
    }

    public function handle_search_product_images() {
        check_ajax_referer( 'ai_image_search_nonce', 'security' );
        nocache_headers();

        $post_id = isset( $_POST['post_id'] ) && is_scalar( $_POST['post_id'] )
            ? absint( wp_unslash( $_POST['post_id'] ) )
            : 0;
        $product_title = isset( $_POST['product_title'] ) && is_string( $_POST['product_title'] )
            ? sanitize_text_field( wp_unslash( $_POST['product_title'] ) )
            : '';

        if ( empty( $post_id ) || 'product' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'شما اجازه جستجوی تصویر برای این محصول را ندارید.' ] );
            return;
        }

        if ( '' === $product_title ) {
            wp_send_json_error( [ 'message' => 'نام محصول وارد نشده است.' ] );
            return;
        }

        $raw_site_1 = isset( $_POST['target_site_1'] ) && is_string( $_POST['target_site_1'] )
            ? trim( wp_unslash( $_POST['target_site_1'] ) )
            : '';
        $raw_site_2 = isset( $_POST['target_site_2'] ) && is_string( $_POST['target_site_2'] )
            ? trim( wp_unslash( $_POST['target_site_2'] ) )
            : '';

        if ( '' === $raw_site_1 ) {
            wp_send_json_error( [ 'message' => 'لطفاً لینک سایت، مقاله یا تصویر مرجع اول را وارد کنید.' ] );
            return;
        }

        $target_site_1 = $this->sanitize_reference_url( $raw_site_1 );
        $target_site_2 = '' === $raw_site_2 ? '' : $this->sanitize_reference_url( $raw_site_2 );
        if ( '' === $target_site_1 || ( '' !== $raw_site_2 && '' === $target_site_2 ) ) {
            wp_send_json_error( [ 'message' => 'یکی از لینک‌های واردشده معتبر نیست. فقط لینک HTTP یا HTTPS قابل قبول است.' ] );
            return;
        }

        $sites = array_values( array_unique( array_filter( [ $target_site_1, $target_site_2 ] ) ) );
        $reference_images = [];
        foreach ( $sites as $site ) {
            $site_images = $this->get_reference_images( $site );
            foreach ( $site_images as $site_image ) {
                if ( ! in_array( $site_image, $reference_images, true ) ) {
                    $reference_images[] = $site_image;
                }
                if ( count( $reference_images ) >= 12 ) {
                    break 2;
                }
            }
        }

        $serper_api_key = '6cb5c63d94f29884848c990e7da5f1b026f2ae2f';
        $serpstack_api_key = 'YOUR_SERPSTACK_API_KEY';

        $image_results = $reference_images;
        $search_source = '';
        $debug_info = [
            'product_title'          => $product_title,
            'reference_links'        => $sites,
            'reference_image_count'  => count( $reference_images ),
        ];

        $formatted_sites = array_filter( array_map( function ( $site ) {
            return $this->get_site_search_operator( $site );
        }, $sites ) );
        $site_query = implode( ' OR ', array_unique( $formatted_sites ) );
        $debug_info['site_query'] = $site_query;

        $fetch_from_serper = function( $query ) use ( $serper_api_key, &$debug_info ) {
            $debug_info['used_api'] = 'Serper.dev';
            $payload = json_encode( [ 'q' => $query, 'gl' => 'us', 'hl' => 'en' ] );
            $proxy_url = 'https://proxy.falnic.com/proxy.php?token=OToBdvgjDESwtJiF87kiw8XHq2uQDog8oErDrss3a0PHTXRqD8iYbFOflywyp8El&url=' . urlencode( 'https://google.serper.dev/images' );
            $ch = curl_init( $proxy_url );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $ch, CURLOPT_POST, true );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, [
                'X-API-KEY: ' . $serper_api_key,
                'Content-Type: application/json'
            ] );
            curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );

            $response = curl_exec( $ch );
            $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $curl_error = curl_error( $ch );
            curl_close( $ch );

            if ( false === $response || 200 !== $http_code ) {
                $debug_info['serper_error'] = $curl_error ?: 'HTTP ' . $http_code;
                return [];
            }

            $data = json_decode( $response, true );
            $urls = [];
            if ( ! empty( $data['images'] ) && is_array( $data['images'] ) ) {
                foreach ( $data['images'] as $image ) {
                    if ( count( $urls ) >= 12 ) {
                        break;
                    }
                    if ( ! empty( $image['imageUrl'] ) ) {
                        $urls[] = $image['imageUrl'];
                    }
                }
            }
            return $urls;
        };

        $fetch_from_serpstack = function( $query ) use ( $serpstack_api_key, &$debug_info ) {
            $debug_info['used_api'] = 'SerpStack';
            $api_url = 'http://api.serpstack.com/search?access_key=' . $serpstack_api_key . '&query=' . urlencode( $query ) . '&type=images';
            $proxy_url = 'https://proxy.falnic.com/proxy.php?token=OToBdvgjDESwtJiF87kiw8XHq2uQDog8oErDrss3a0PHTXRqD8iYbFOflywyp8El&url=' . urlencode( $api_url );

            $ch = curl_init( $proxy_url );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );

            $response = curl_exec( $ch );
            $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $curl_error = curl_error( $ch );
            curl_close( $ch );

            if ( false === $response ) {
                $debug_info['serpstack_error'] = 'cURL Error: ' . $curl_error;
                return [ 'status' => 'error', 'data' => [] ];
            }

            $data = json_decode( $response, true );
            if ( isset( $data['error'] ) || 200 !== $http_code ) {
                $debug_info['serpstack_error'] = isset( $data['error'] ) ? json_encode( $data['error'] ) : 'HTTP Error ' . $http_code;
                return [ 'status' => 'error', 'data' => [] ];
            }

            $urls = [];
            if ( ! empty( $data['image_results'] ) && is_array( $data['image_results'] ) ) {
                foreach ( $data['image_results'] as $image ) {
                    if ( count( $urls ) >= 12 ) {
                        break;
                    }
                    $image_url = ! empty( $image['image_url'] ) ? $image['image_url'] : ( ! empty( $image['url'] ) ? $image['url'] : '' );
                    if ( ! empty( $image_url ) ) {
                        $urls[] = $image_url;
                    }
                }
            }
            return [ 'status' => 'success', 'data' => $urls ];
        };

        $fetch_images_with_fallback = function( $query ) use ( $fetch_from_serpstack, $fetch_from_serper, &$debug_info ) {
            $debug_info['last_query'] = $query;
            $result = $fetch_from_serpstack( $query );

            if ( 'error' === $result['status'] || empty( $result['data'] ) ) {
                $debug_info['fallback_triggered'] = true;
                return $fetch_from_serper( $query );
            }
            return $result['data'];
        };

        if ( '' !== $site_query ) {
            // مسیر هر لینک (نه فقط دامنه) وارد query می‌شود تا لینک مرجع جدید نتیجه تازه‌ای بدهد.
            $specific_query = $product_title . ' ' . $site_query;
            $debug_info['search_type'] = 'specific';
            $image_results = array_merge( $image_results, $fetch_images_with_fallback( $specific_query ) );
            $search_source = 'specific';
        }

        if ( empty( $image_results ) ) {
            $debug_info['search_type'] = 'fallback';
            $image_results = $fetch_images_with_fallback( $product_title );
            $search_source = 'google_fallback';
        }

        // نتایج صفحه مرجع در ابتدای فهرست قرار می‌گیرند و URLهای تکراری حذف می‌شوند.
        $normalized_images = [];
        foreach ( $image_results as $image_url ) {
            $normalized_url = $this->normalize_search_image_url( $image_url );
            if ( $normalized_url && ! in_array( $normalized_url, $normalized_images, true ) ) {
                $normalized_images[] = $normalized_url;
            }
            if ( count( $normalized_images ) >= 20 ) {
                break;
            }
        }
        $image_results = $normalized_images;
        $debug_info['final_image_count'] = count( $image_results );

        if ( empty( $image_results ) ) {
            wp_send_json_error( [
                'message' => 'هیچ تصویری برای این محصول یا لینک مرجع پیدا نشد.',
                'debug'   => $debug_info
            ] );
            return;
        }

        wp_send_json_success( [
            'images' => $image_results,
            'source' => $search_source,
            'debug'  => $debug_info
        ] );
    }

    public function render_ai_image_search_metabox( $post ) {
        $post_id = $post && isset( $post->ID ) ? absint( $post->ID ) : 0;
        wp_nonce_field( 'ai_image_search_nonce', 'ai_image_search_nonce_field' );
        ?>
        <div style="margin-bottom: 10px;">
            <label for="featured_image_filename" style="display: block; font-weight: bold; margin-bottom: 5px;">
                نام فایل تصویر شاخص <span style="color: #999; font-size: 11px;">(اختیاری)</span>
            </label>
            <input type="text" id="featured_image_filename" maxlength="180" dir="ltr" autocomplete="off" style="width:100%; margin-top:5px;" placeholder="مثلاً product-image" />
            <p class="description" style="margin: 5px 0 0;">در صورت خالی‌بودن، نام پیش‌فرض محصول استفاده می‌شود؛ پسوند فایل خودکار تعیین خواهد شد.</p>
        </div>
        <div style="margin-bottom: 10px;">
            <label for="target_site_1" style="display: block; font-weight: bold; margin-bottom: 5px;">
                لینک سایت، مقاله یا تصویر مرجع اول <span style="color: #dc3232;">*</span>
            </label>
            <input type="url" id="target_site_1" style="width:100%; margin-top:5px;" placeholder="https://example.com/article یا لینک تصویر" />
            <p class="description" style="margin: 5px 0 0;">می‌توانید آدرس سایت، صفحه مقاله یا لینک مستقیم عکس را وارد کنید.</p>
        </div>
        <div style="margin-bottom: 10px;">
            <label for="target_site_2" style="display: block; font-weight: bold; margin-bottom: 5px;">
                لینک سایت، مقاله یا تصویر مرجع دوم <span style="color: #999; font-size: 11px;">(اختیاری)</span>
            </label>
            <input type="url" id="target_site_2" style="width:100%; margin-top:5px;" placeholder="https://another-site.com/article" />
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
            function escapeHtml(value) {
                return $('<div>').text(String(value == null ? '' : value)).html();
            }

            function safeHttpUrl(value) {
                try {
                    var parsedUrl = new URL(value, window.location.href);
                    return parsedUrl.protocol === 'http:' || parsedUrl.protocol === 'https:' ? parsedUrl.href : '';
                } catch (e) {
                    return '';
                }
            }

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
                var site1 = ($('#target_site_1').val() || '').trim();
                var site2 = ($('#target_site_2').val() || '').trim();
                var productTitle = ($('#title').val() || '').trim();
                
                if(!productTitle) {
                    alert('لطفا ابتدا نام محصول را وارد کنید.');
                    return;
                }
                if(!site1) {
                    alert('لطفا لینک سایت، مقاله یا تصویر مرجع اول را وارد کنید.');
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
                    cache: false,
                    data: {
                        action: 'search_product_images',
                        security: $('#ai_image_search_nonce_field').val(),
                        product_title: productTitle,
                        target_site_1: site1,
                        target_site_2: site2,
                        search_request: Date.now(),
                        post_id: <?php echo esc_js( $post_id ); ?>
                    },
                    success: function(response) {
                        button.text('جستجوی تصاویر').prop('disabled', false);

                        // اگر لینک‌ها هنگام اجرای درخواست تغییر کرده باشند، پاسخ قدیمی را نشان نده.
                        if (site1 !== ($('#target_site_1').val() || '').trim() || site2 !== ($('#target_site_2').val() || '').trim() || productTitle !== ($('#title').val() || '').trim()) {
                            $('#ai-image-modal-body').html('<div class="ai-image-modal-error"><p>لینک مرجع یا نام محصول تغییر کرده است؛ برای دریافت نتایج جدید دوباره جستجو کنید.</p></div>');
                            return;
                        }
                        
                        if(response.success && response.data.images.length > 0) {
                            let sourceText = response.data.source === 'specific' 
                                ? '✅ تصاویر از سایت‌های مرجع یافت شدند:' 
                                : '⚠️ از سایت‌های مرجع تصویری یافت نشد، در کل وب جستجو شد:';
                                
                            let apiUsed = response.data.debug && response.data.debug.used_api ? response.data.debug.used_api : 'نامشخص';
                            sourceText += ` <span style="font-size:11px; color:#888;">(از طریق API: ${escapeHtml(apiUsed)})</span>`;
                            
                            let imagesList = '<ul class="ai-image-list">';
                            let displayedImageCount = 0;
                            
                            $.each(response.data.images, function(i, imgUrl) {
                                let safeImageUrl = safeHttpUrl(imgUrl);
                                if (!safeImageUrl) return;
                                let escapedImageUrl = escapeHtml(safeImageUrl);
                                let proxyImgUrl = escapeHtml(ajaxurl + '?action=proxy_ai_image&url=' + encodeURIComponent(safeImageUrl));
                                displayedImageCount++;
                                imagesList += `
                                    <li>
                                        <img src="${proxyImgUrl}" alt="${escapeHtml(productTitle)}" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                                        <span style="display:none; width:80px; height:80px; background:#f0f0f0; align-items:center; justify-content:center; border-radius:5px; font-size:11px; color:#999; flex-shrink:0; text-align:center; padding:5px;">خطا در بارگذاری</span>
                                        <div class="image-info">
                                            <div class="image-url">${escapedImageUrl}</div>
                                            <div class="image-actions">
                                                <button type="button" class="button button-primary btn-set-featured" data-url="${escapedImageUrl}">
                                                    انتخاب با واترمارک به عنوان تصویر شاخص
                                                </button>
                                                <a href="${escapedImageUrl}" target="_blank" rel="noopener noreferrer" class="button button-small">مشاهده</a>
                                                <button type="button" class="button button-small btn-copy-url" data-url="${escapedImageUrl}">کپی لینک</button>
                                            </div>
                                        </div>
                                    </li>
                                `;
                            });
                            
                            imagesList += '</ul>';
                            
                            $('#ai-image-modal-body').html(`
                                <div class="ai-image-modal-source">${sourceText} (${displayedImageCount} تصویر)</div>
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
                                        image_filename: ($('#featured_image_filename').val() || '').trim(),
                                        post_id: <?php echo esc_js( $post_id ); ?>
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
                            let errorMessage = response.data && response.data.message ? response.data.message : 'تصویری یافت نشد.';
                            let errorMsg = '<div class="ai-image-modal-error"><p>❌ ' + escapeHtml(errorMessage) + '</p></div>';
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
