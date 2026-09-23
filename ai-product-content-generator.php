<?php
/**
 * Plugin Name: AI Product Content Generator
 * Plugin URI:  https://falnic.com
 * Description: افزونه تولید محتوا با AI برای محصولات ووکامرس (نسخه اصلاح‌شده با پاکسازی خودکار تگ h1 و کدهای اضافی مارک‌داون).
 * Version:     1.4
 * Author:      Mojtaba Ghazi Nejad
 * Author URI:  https://falnic.com
 * Text Domain: ai-product-content-generator
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/attributes-generator.php';
require_once plugin_dir_path(__FILE__) . 'includes/content-generator.php';
require_once plugin_dir_path(__FILE__) . 'includes/search-product-thumbnail.php';
require_once plugin_dir_path(__FILE__) . 'includes/review-content-generator.php';

new Attributes_Generator_With_Crawler();
new AI_Product_Content_Generator();
new Search_Product_thumbnail();
new Review_Content_Generator();
