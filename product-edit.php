<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/WooCommerceAPI.php';

$user_id = Config::getCurrentUserId();
$store_config = Config::getCurrentStore();
$store_config_id = $store_config['id'] ?? null;

$api = null;
$connected = false;
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

// Helper function per simbolo valuta
function getCurrencySymbol() {
    return '$'; // Default, può essere configurato
}

// Helper function per unità peso
function getWeightUnit() {
    return 'kg'; // Default
}

// Helper function per unità dimensioni
function getDimensionUnit() {
    return 'cm'; // Default
}

if ($store_config_id && $user_id) {
    try {
        $api = new WooCommerceAPI($user_id, $store_config_id);
        $connected = $api->isConfigured();
        if (!$connected) {
            $error_message = 'WooCommerce API not configured for the current store. Please check store settings.';
        }
    } catch (Exception $e) {
        $error_message = "Error initializing API: " . $e->getMessage();
        $connected = false;
    }
} else {
    $error_message = 'No store selected or user not logged in. Please select a store from settings.';
}

$product_id = isset($_GET['id']) ? intval($_GET['id']) : null;
$is_edit = $product_id !== null;
$page_title = $is_edit ? 'Edit Product' : 'Add New Product';

// Default product structure
$product_data_default = [
    'id' => null,
    'name' => '',
    'slug' => '',
    'type' => 'simple',
    'status' => 'draft',
    'featured' => false,
    'catalog_visibility' => 'visible',
    'description' => '',
    'short_description' => '',
    'sku' => '',
    'regular_price' => '',
    'sale_price' => '',
    'date_on_sale_from' => null,
    'date_on_sale_to' => null,
    'virtual' => false,
    'downloadable' => false,
    'downloads' => [],
    'download_limit' => -1,
    'download_expiry' => -1,
    'external_url' => '',
    'button_text' => '',
    'tax_status' => 'taxable',
    'tax_class' => '',
    'manage_stock' => false,
    'stock_quantity' => null,
    'stock_status' => 'instock',
    'backorders' => 'no',
    'sold_individually' => false,
    'weight' => '',
    'dimensions' => ['length' => '', 'width' => '', 'height' => ''],
    'shipping_class_id' => 0,
    'reviews_allowed' => true,
    'upsell_ids' => [],
    'cross_sell_ids' => [],
    'parent_id' => 0,
    'purchase_note' => '',
    'categories' => [],
    'tags' => [],
    'images' => [],
    'attributes' => [],
    'default_attributes' => [],
    'variations' => [],
    'menu_order' => 0,
    'meta_data' => [],
    'grouped_products' => []
];

$product = $product_data_default;

// Support data arrays
$all_categories = [];
$all_tags = [];
$all_attributes = [];
$all_shipping_classes = [];
$attribute_terms_cache = [];

$product_types = [
    'simple' => 'Simple Product',
    'variable' => 'Variable Product',
    'grouped' => 'Grouped Product',
    'external' => 'External/Affiliate Product'
];

$stock_statuses = [
    'instock' => 'In stock',
    'outofstock' => 'Out of stock',
    'onbackorder' => 'On backorder'
];

$backorder_options = [
    'no' => 'Do not allow',
    'notify' => 'Allow, but notify customer',
    'yes' => 'Allow'
];

$tax_statuses = [
    'taxable' => 'Taxable',
    'shipping' => 'Shipping only',
    'none' => 'None'
];

$catalog_visibility_options = [
    'visible' => 'Shop and search results',
    'catalog' => 'Shop only',
    'search' => 'Search results only', 
    'hidden' => 'Hidden'
];

if ($connected && $api) {
    try {
        // Load product data if editing
        if ($is_edit) {
            $product_response = $api->getProduct($product_id);
            if ($product_response['success'] && isset($product_response['data'])) {
                $product = array_merge($product_data_default, $product_response['data']);
                
                // Normalize data
                $product['shipping_class_id'] = $product['shipping_class_id'] ?? 0;
                $product['stock_quantity'] = $product['stock_quantity'] ?? null;
                
                // Load variations if variable product
                if ($product['type'] === 'variable') {
                    $variations_response = $api->getProductVariations($product_id, ['per_page' => 100]);
                    if ($variations_response['success']) {
                        $product['variations'] = $variations_response['data'];
                    }
                }
            } else {
                $error_message = 'Product not found or error fetching product: ' . ($product_response['error'] ?? 'Unknown error');
                $product = $product_data_default;
            }
        }
        
        // Load supporting data
        $categories_response = $api->getProductCategories(['per_page' => 100, 'orderby' => 'name', 'order' => 'asc']);
        if ($categories_response['success']) $all_categories = $categories_response['data'];
        
        $tags_response = $api->getProductTags(['per_page' => 100, 'orderby' => 'name', 'order' => 'asc']);
        if ($tags_response['success']) $all_tags = $tags_response['data'];
        
        $attributes_response = $api->getProductAttributes(['context' => 'edit']);
        if ($attributes_response['success']) {
            $all_attributes = $attributes_response['data'];
            
            // Load terms for each attribute
            foreach ($all_attributes as &$attr) {
                $terms_response = $api->getProductAttributeTerms($attr['id'], ['per_page' => 100]);
                if ($terms_response['success']) {
                    $attr['terms'] = $terms_response['data'];
                    $attribute_terms_cache[$attr['id']] = $terms_response['data'];
                }
            }
        }
        
        $shipping_response = $api->getProductShippingClasses(['per_page' => 100]);
        if ($shipping_response['success']) $all_shipping_classes = $shipping_response['data'];
        
    } catch (Exception $e) {
        $error_message = 'Error loading supporting data: ' . $e->getMessage();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $connected && $api) {
    try {
        // Prepare product data
        $data_to_submit = [
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'slug' => sanitize_text_field($_POST['slug'] ?? ''),
            'type' => sanitize_text_field($_POST['type'] ?? 'simple'),
            'status' => sanitize_text_field($_POST['status'] ?? 'draft'),
            'featured' => isset($_POST['featured']),
            'catalog_visibility' => sanitize_text_field($_POST['catalog_visibility'] ?? 'visible'),
            'description' => $_POST['description'] ?? '',
            'short_description' => $_POST['short_description'] ?? '',
            'sku' => sanitize_text_field($_POST['sku'] ?? ''),
            'regular_price' => sanitize_text_field($_POST['regular_price'] ?? ''),
            'sale_price' => sanitize_text_field($_POST['sale_price'] ?? ''),
            'manage_stock' => isset($_POST['manage_stock']),
            'stock_status' => sanitize_text_field($_POST['stock_status'] ?? 'instock'),
            'backorders' => sanitize_text_field($_POST['backorders'] ?? 'no'),
            'sold_individually' => isset($_POST['sold_individually']),
            'weight' => sanitize_text_field($_POST['weight'] ?? ''),
            'dimensions' => [
                'length' => sanitize_text_field($_POST['length'] ?? ''),
                'width' => sanitize_text_field($_POST['width'] ?? ''),
                'height' => sanitize_text_field($_POST['height'] ?? '')
            ],
            'shipping_class_id' => intval($_POST['shipping_class_id'] ?? 0),
            'virtual' => isset($_POST['virtual']),
            'downloadable' => isset($_POST['downloadable']),
            'purchase_note' => sanitize_text_field($_POST['purchase_note'] ?? ''),
            'menu_order' => intval($_POST['menu_order'] ?? 0),
            'reviews_allowed' => isset($_POST['reviews_allowed']),
            'tax_status' => sanitize_text_field($_POST['tax_status'] ?? 'taxable'),
            'tax_class' => sanitize_text_field($_POST['tax_class'] ?? '')
        ];

        // Handle stock quantity
        if ($data_to_submit['manage_stock']) {
            $data_to_submit['stock_quantity'] = isset($_POST['stock_quantity']) && $_POST['stock_quantity'] !== '' ? intval($_POST['stock_quantity']) : null;
        } else {
            $data_to_submit['stock_quantity'] = null;
        }
        
        // Handle sale dates
        if (!empty($_POST['date_on_sale_from'])) {
            $data_to_submit['date_on_sale_from'] = sanitize_text_field($_POST['date_on_sale_from']);
        }
        if (!empty($_POST['date_on_sale_to'])) {
            $data_to_submit['date_on_sale_to'] = sanitize_text_field($_POST['date_on_sale_to']);
        }

        // Handle categories
        if (isset($_POST['categories']) && is_array($_POST['categories'])) {
            $data_to_submit['categories'] = array_map(function($cat_id) {
                return ['id' => intval($cat_id)];
            }, $_POST['categories']);
        }

        // Handle tags
        if (isset($_POST['tags_data'])) {
            $tags_input = json_decode(stripslashes($_POST['tags_data']), true);
            if (is_array($tags_input)) {
                $data_to_submit['tags'] = array_map(function($tag) {
                    return is_numeric($tag) ? ['id' => intval($tag)] : ['name' => sanitize_text_field($tag)];
                }, $tags_input);
            }
        }

        // Handle images
        if (isset($_POST['images_data'])) {
            $images_input = json_decode(stripslashes($_POST['images_data']), true);
            if (is_array($images_input)) {
                $data_to_submit['images'] = [];
                foreach($images_input as $img_data) {
                    $image_obj = [];
                    if (!empty($img_data['id']) && is_numeric($img_data['id'])) {
                        $image_obj['id'] = intval($img_data['id']);
                    } elseif (!empty($img_data['src'])) {
                        $image_obj['src'] = filter_var($img_data['src'], FILTER_SANITIZE_URL);
                    }
                    if(!empty($img_data['alt'])) $image_obj['alt'] = sanitize_text_field($img_data['alt']);
                    if(isset($img_data['position'])) $image_obj['position'] = intval($img_data['position']);
                    
                    if (!empty($image_obj)) {
                        $data_to_submit['images'][] = $image_obj;
                    }
                }
            }
        }

        // Handle attributes
        if (isset($_POST['attributes_data'])) {
            $attributes_input = json_decode(stripslashes($_POST['attributes_data']), true);
            if (is_array($attributes_input)) {
                $data_to_submit['attributes'] = [];
                foreach ($attributes_input as $attr) {
                    $api_attr = [];
                    if (!empty($attr['id']) && is_numeric($attr['id']) && $attr['id'] > 0) {
                        $api_attr['id'] = intval($attr['id']);
                    } elseif (!empty($attr['name'])) {
                        $api_attr['name'] = sanitize_text_field($attr['name']);
                    } else {
                        continue;
                    }
                    $api_attr['options'] = array_map('sanitize_text_field', $attr['options'] ?? []);
                    $api_attr['visible'] = isset($attr['visible']) ? (bool)$attr['visible'] : false;
                    $api_attr['variation'] = isset($attr['variation']) ? (bool)$attr['variation'] : false;
                    if (isset($attr['position'])) $api_attr['position'] = intval($attr['position']);
                    $data_to_submit['attributes'][] = $api_attr;
                }
            }
        }
        
        // Handle default attributes for variable products
        if ($data_to_submit['type'] === 'variable' && isset($_POST['default_attributes_data'])) {
            $default_attrs_input = json_decode(stripslashes($_POST['default_attributes_data']), true);
            if (is_array($default_attrs_input)) {
                $data_to_submit['default_attributes'] = [];
                foreach($default_attrs_input as $def_attr) {
                    $api_def_attr = [];
                    if (!empty($def_attr['id']) && is_numeric($def_attr['id']) && $def_attr['id'] > 0) {
                        $api_def_attr['id'] = intval($def_attr['id']);
                    } elseif (!empty($def_attr['name'])) {
                        $api_def_attr['name'] = sanitize_text_field($def_attr['name']);
                    } else {
                        continue;
                    }
                    $api_def_attr['option'] = sanitize_text_field($def_attr['option'] ?? '');
                    $data_to_submit['default_attributes'][] = $api_def_attr;
                }
            }
        }

        // Handle grouped products
        if ($data_to_submit['type'] === 'grouped' && isset($_POST['grouped_products']) && is_array($_POST['grouped_products'])) {
            $data_to_submit['grouped_products'] = array_map('intval', $_POST['grouped_products']);
        }

        // Handle external products
        if ($data_to_submit['type'] === 'external') {
            $data_to_submit['external_url'] = filter_var($_POST['external_url'] ?? '', FILTER_SANITIZE_URL);
            $data_to_submit['button_text'] = sanitize_text_field($_POST['button_text'] ?? '');
        }

        // Handle downloadable files
        if ($data_to_submit['downloadable'] && isset($_POST['downloads_data'])) {
            $downloads_input = json_decode(stripslashes($_POST['downloads_data']), true);
            if (is_array($downloads_input)) {
                $data_to_submit['downloads'] = $downloads_input;
            }
            $data_to_submit['download_limit'] = intval($_POST['download_limit'] ?? -1);
            $data_to_submit['download_expiry'] = intval($_POST['download_expiry'] ?? -1);
        }

        // Save product
        if ($is_edit) {
            $response = $api->updateProduct($product_id, $data_to_submit);
        } else {
            $response = $api->createProduct($data_to_submit);
        }
        
        if ($response['success'] && isset($response['data']['id'])) {
            $saved_product_id = $response['data']['id'];
            $_SESSION['success_message'] = $is_edit ? 'Product updated successfully!' : 'Product created successfully!';
            
            // Handle variations for variable products
            if ($data_to_submit['type'] === 'variable' && isset($_POST['variations_data'])) {
                $variations_input = json_decode(stripslashes($_POST['variations_data']), true);
                if (is_array($variations_input) && !empty($variations_input)) {
                    $batch_variations_data = ['create' => [], 'update' => [], 'delete' => []];
                    
                    foreach ($variations_input as $var_data) {
                        $api_var = [
                            'attributes' => array_map(function($a) {
                                return ['id' => $a['id'] ?? 0, 'name' => $a['name'] ?? '', 'option' => $a['option']];
                            }, $var_data['attributes'] ?? []),
                            'regular_price' => $var_data['regular_price'] ?? '',
                            'sale_price' => $var_data['sale_price'] ?? '',
                            'sku' => $var_data['sku'] ?? '',
                            'manage_stock' => isset($var_data['manage_stock']) ? (bool)$var_data['manage_stock'] : false,
                            'stock_quantity' => (isset($var_data['manage_stock']) && $var_data['manage_stock'] && isset($var_data['stock_quantity'])) ? intval($var_data['stock_quantity']) : null,
                            'stock_status' => $var_data['stock_status'] ?? 'instock',
                            'weight' => $var_data['weight'] ?? '',
                            'dimensions' => [
                                'length' => $var_data['length'] ?? '',
                                'width' => $var_data['width'] ?? '',
                                'height' => $var_data['height'] ?? ''
                            ]
                        ];
                        
                        if (!empty($var_data['image_id'])) {
                            $api_var['image'] = ['id' => intval($var_data['image_id'])];
                        } elseif (!empty($var_data['image_src'])) {
                            $api_var['image'] = ['src' => $var_data['image_src']];
                        }
                        
                        if (isset($var_data['id']) && is_numeric($var_data['id'])) {
                            $api_var['id'] = intval($var_data['id']);
                            $batch_variations_data['update'][] = $api_var;
                        } else {
                            $batch_variations_data['create'][] = $api_var;
                        }
                    }
                    
                    if (isset($_POST['variations_to_delete']) && is_array($_POST['variations_to_delete'])) {
                        $batch_variations_data['delete'] = array_map('intval', $_POST['variations_to_delete']);
                    }

                    if (!empty($batch_variations_data['create']) || !empty($batch_variations_data['update']) || !empty($batch_variations_data['delete'])) {
                        $var_response = $api->batchProductVariations($saved_product_id, $batch_variations_data);
                        if (!$var_response['success']) {
                            $_SESSION['error_message'] = ($_SESSION['error_message'] ?? '') . ' Product saved, but error with variations: ' . ($var_response['error'] ?? 'Unknown variation error.');
                        }
                    }
                }
            }

            header("Location: product-edit.php?id={$saved_product_id}");
            exit;
        } else {
            $error_message = 'Error saving product: ' . ($response['error'] ?? 'Unknown API error.');
            if (isset($response['data']['details'])) {
                foreach ($response['data']['details'] as $field_error) {
                    $error_message .= "<br>- {$field_error['field']}: {$field_error['message']}";
                }
            }
            $product = array_merge($product, $data_to_submit);
        }
    } catch (Exception $e) {
        $error_message = 'Error saving product: ' . $e->getMessage();
        $product = array_merge($product, $data_to_submit ?? []);
    }
}

include 'header.php';
?>

<style>
    :root {
        --primary-color: #4f46e5;
        --primary-hover: #4338ca;
        --success-color: #10b981;
        --error-color: #ef4444;
        --warning-color: #f59e0b;
        --gray-50: #f9fafb;
        --gray-100: #f3f4f6;
        --gray-200: #e5e7eb;
        --gray-300: #d1d5db;
        --gray-400: #9ca3af;
        --gray-500: #6b7280;
        --gray-600: #4b5563;
        --gray-700: #374151;
        --gray-800: #1f2937;
        --gray-900: #111827;
        --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    }

    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        background-color: var(--gray-50);
        margin: 0;
        padding: 0;
    }

    .product-editor {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 2rem;
        max-width: 1600px;
        margin: 0 auto;
        padding: 1rem;
    }

    .editor-main, .editor-sidebar {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }

    .editor-card {
        background: white;
        border-radius: 12px;
        border: 1px solid var(--gray-200);
        padding: 1.5rem;
        box-shadow: var(--shadow-sm);
        transition: box-shadow 0.2s;
    }

    .editor-card:hover {
        box-shadow: var(--shadow);
    }

    .editor-card h3 {
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 1rem;
        color: var(--gray-800);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .form-group {
        margin-bottom: 1rem;
    }

    .form-label {
        display: block;
        font-weight: 500;
        margin-bottom: 0.5rem;
        color: var(--gray-700);
        font-size: 0.875rem;
    }

    .form-input, .form-select, .form-textarea {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid var(--gray-300);
        border-radius: 8px;
        font-size: 0.875rem;
        box-shadow: var(--shadow-sm);
        transition: all 0.2s;
        background-color: white;
        box-sizing: border-box;
    }

    .form-input:focus, .form-select:focus, .form-textarea:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
    }

    .form-textarea {
        min-height: 100px;
        resize: vertical;
    }

    .form-checkbox {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .form-checkbox input[type="checkbox"] {
        width: 1.25rem;
        height: 1.25rem;
        border-radius: 4px;
        border: 1px solid var(--gray-300);
        accent-color: var(--primary-color);
    }

    .tab-nav {
        display: flex;
        border-bottom: 1px solid var(--gray-200);
        margin-bottom: 1.5rem;
        overflow-x: auto;
    }

    .tab-button {
        padding: 0.75rem 1.5rem;
        border: none;
        background: none;
        cursor: pointer;
        font-weight: 500;
        color: var(--gray-600);
        white-space: nowrap;
        transition: all 0.2s;
        position: relative;
    }

    .tab-button:hover {
        color: var(--primary-color);
    }

    .tab-button.active {
        color: var(--primary-color);
    }

    .tab-button.active::after {
        content: '';
        position: absolute;
        bottom: -1px;
        left: 0;
        right: 0;
        height: 2px;
        background-color: var(--primary-color);
    }

    .tab-content {
        display: none;
    }

    .tab-content.active {
        display: block;
    }

    .btn {
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        font-weight: 500;
        font-size: 0.875rem;
        border: 1px solid transparent;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
        background: none;
    }

    .btn-primary {
        background-color: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }

    .btn-primary:hover {
        background-color: var(--primary-hover);
    }

    .btn-outline {
        background-color: white;
        color: var(--gray-700);
        border-color: var(--gray-300);
    }

    .btn-outline:hover {
        background-color: var(--gray-50);
        border-color: var(--gray-400);
    }

    .btn-danger {
        background-color: var(--error-color);
        color: white;
        border-color: var(--error-color);
    }

    .btn-danger:hover {
        background-color: #dc2626;
    }

    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.75rem;
    }

    .btn-xs {
        padding: 0.375rem 0.75rem;
        font-size: 0.7rem;
    }

    .alert {
        padding: 1rem 1.5rem;
        margin-bottom: 1.5rem;
        border-radius: 8px;
        border-width: 1px;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .alert-error {
        background-color: #fee2e2;
        border-color: #fecaca;
        color: #b91c1c;
    }

    .alert-success {
        background-color: #dcfce7;
        border-color: #bbf7d0;
        color: #166534;
    }

    .image-gallery {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }

    .image-item {
        position: relative;
        aspect-ratio: 1;
        border: 2px dashed var(--gray-300);
        border-radius: 8px;
        overflow: hidden;
        background-color: var(--gray-50);
        cursor: grab;
        transition: all 0.2s;
    }

    .image-item:hover {
        border-color: var(--primary-color);
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .image-item.dragging {
        cursor: grabbing;
        transform: rotate(5deg);
        box-shadow: var(--shadow-lg);
        opacity: 0.8;
    }

    .image-item img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .image-item .overlay {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.2s;
        gap: 0.5rem;
        flex-direction: column;
    }

    .image-item:hover .overlay {
        opacity: 1;
    }

    .image-upload-area {
        border: 2px dashed var(--gray-300);
        border-radius: 8px;
        padding: 2rem;
        text-align: center;
        transition: all 0.2s;
        cursor: pointer;
        background-color: var(--gray-50);
    }

    .image-upload-area:hover,
    .image-upload-area.dragover {
        border-color: var(--primary-color);
        background-color: rgba(79, 70, 229, 0.05);
    }

    .attribute-item {
        border: 1px solid var(--gray-200);
        border-radius: 8px;
        margin-bottom: 1rem;
        background: var(--gray-50);
    }

    .attribute-header {
        padding: 1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        user-select: none;
    }

    .attribute-header:hover {
        background-color: var(--gray-100);
    }

    .attribute-content {
        padding: 1rem;
        border-top: 1px solid var(--gray-200);
        background: white;
        display: none;
    }

    .attribute-content.active {
        display: block;
    }

    .variation-item {
        border: 1px solid var(--gray-200);
        border-radius: 8px;
        margin-bottom: 0.5rem;
        background: white;
    }

    .variation-header {
        padding: 1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        user-select: none;
        background: var(--gray-50);
        border-radius: 8px 8px 0 0;
    }

    .variation-header:hover {
        background-color: var(--gray-100);
    }

    .variation-content {
        padding: 1rem;
        border-top: 1px solid var(--gray-200);
        display: none;
    }

    .variation-content.active {
        display: block;
    }

    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(4px);
    }

    .modal.show {
        display: flex;
        align-items: center;
        justify-content: center;
        animation: fadeIn 0.2s ease-out;
    }

    .modal-content {
        background-color: white;
        margin: auto;
        padding: 2rem;
        border: none;
        border-radius: 12px;
        width: 90%;
        max-width: 600px;
        box-shadow: var(--shadow-lg);
        animation: slideIn 0.2s ease-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @keyframes slideIn {
        from { transform: translateY(-20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .autocomplete-container {
        position: relative;
    }

    .autocomplete-suggestions {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid var(--gray-300);
        border-top: none;
        border-radius: 0 0 8px 8px;
        max-height: 200px;
        overflow-y: auto;
        z-index: 100;
        box-shadow: var(--shadow);
    }

    .autocomplete-suggestion {
        padding: 0.75rem;
        cursor: pointer;
        border-bottom: 1px solid var(--gray-100);
        transition: background-color 0.2s;
    }

    .autocomplete-suggestion:hover,
    .autocomplete-suggestion.active {
        background-color: var(--gray-50);
    }

    .tag-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 0.5rem;
    }

    .tag-item {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        background-color: var(--primary-color);
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
    }

    .tag-remove {
        background: none;
        border: none;
        color: white;
        cursor: pointer;
        padding: 0;
        margin-left: 0.25rem;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
    }

    .tag-remove:hover {
        background-color: rgba(255, 255, 255, 0.2);
    }

    .loading {
        opacity: 0.6;
        pointer-events: none;
    }

    .spinner {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 2px solid var(--gray-300);
        border-radius: 50%;
        border-top-color: var(--primary-color);
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    .grid {
        display: grid;
    }

    .grid-cols-2 {
        grid-template-columns: repeat(2, 1fr);
    }

    .grid-cols-3 {
        grid-template-columns: repeat(3, 1fr);
    }

    .gap-4 {
        gap: 1rem;
    }

    .gap-6 {
        gap: 1.5rem;
    }

    .hidden {
        display: none !important;
    }

    .flex {
        display: flex;
    }

    .items-center {
        align-items: center;
    }

    .justify-between {
        justify-content: space-between;
    }

    .space-y-2 > * + * {
        margin-top: 0.5rem;
    }

    .space-y-4 > * + * {
        margin-top: 1rem;
    }

    .text-sm {
        font-size: 0.875rem;
    }

    .text-xs {
        font-size: 0.75rem;
    }

    .font-medium {
        font-weight: 500;
    }

    .font-semibold {
        font-weight: 600;
    }

    .text-gray-500 {
        color: var(--gray-500);
    }

    .text-gray-600 {
        color: var(--gray-600);
    }

    .text-gray-700 {
        color: var(--gray-700);
    }

    .mt-1 {
        margin-top: 0.25rem;
    }

    .mt-2 {
        margin-top: 0.5rem;
    }

    .mt-4 {
        margin-top: 1rem;
    }

    .mb-2 {
        margin-bottom: 0.5rem;
    }

    .mb-4 {
        margin-bottom: 1rem;
    }

    .p-2 {
        padding: 0.5rem;
    }

    .p-4 {
        padding: 1rem;
    }

    .px-3 {
        padding-left: 0.75rem;
        padding-right: 0.75rem;
    }

    .py-2 {
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
    }

    .rounded {
        border-radius: 0.25rem;
    }

    .rounded-md {
        border-radius: 0.375rem;
    }

    .border {
        border-width: 1px;
        border-color: var(--gray-200);
    }

    .bg-gray-50 {
        background-color: var(--gray-50);
    }

    .bg-gray-100 {
        background-color: var(--gray-100);
    }

    .w-full {
        width: 100%;
    }

    .max-h-48 {
        max-height: 12rem;
    }

    .overflow-y-auto {
        overflow-y: auto;
    }

    .col-span-2 {
        grid-column: span 2;
    }

    @media (max-width: 1024px) {
        .product-editor {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
    }

    @media (max-width: 768px) {
        .product-editor {
            padding: 0.5rem;
        }
        
        .editor-card {
            padding: 1rem;
        }
        
        .tab-nav {
            overflow-x: auto;
        }
        
        .grid-cols-2,
        .grid-cols-3 {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="px-4 sm:px-6 lg:px-8 py-6">
    <div class="w-full">
        
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <a href="products.php" class="text-gray-500 hover:text-gray-700 p-2 rounded-full hover:bg-gray-100 transition-colors">
                    ← Back
                </a>
                <h1 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($page_title); ?></h1>
                <?php if ($is_edit && $product['id']): ?>
                    <span class="bg-indigo-100 text-indigo-700 px-3 py-1 rounded-full text-sm font-medium">
                        ID: <?php echo $product['id']; ?>
                    </span>
                <?php endif; ?>
            </div>
            
            <div class="flex gap-3">
                <button type="button" onclick="previewProduct()" class="btn btn-outline">
                    👁 Preview
                </button>
                <button type="button" onclick="saveProduct('draft')" class="btn btn-outline">Save Draft</button>
                <button type="button" onclick="saveProduct('publish')" class="btn btn-primary">
                    ✓ <?php echo $is_edit ? 'Update Product' : 'Publish Product'; ?>
                </button>
            </div>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">
                ⚠ <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                ✓ <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!$connected): ?>
            <div class="alert alert-error">
                ❌ API is not connected. Please <a href="settings.php" class="font-semibold underline">configure your store settings</a>.
            </div>
        <?php else: ?>

        <form id="product-form" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="form_submitted" value="1">
            <input type="hidden" id="product_status_hidden" name="status" value="<?php echo htmlspecialchars($product['status']); ?>">

            <div class="product-editor">
                <div class="editor-main">
                    <!-- Basic Information -->
                    <div class="editor-card">
                        <h3>📝 Basic Information</h3>
                        <div class="form-group">
                            <label class="form-label" for="name">Product Name *</label>
                            <input type="text" name="name" id="name" class="form-input" value="<?php echo htmlspecialchars($product['name']); ?>" required oninput="generateSlug()">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="slug">Slug</label>
                            <input type="text" name="slug" id="slug" class="form-input" value="<?php echo htmlspecialchars($product['slug']); ?>">
                            <p class="text-xs text-gray-500 mt-1">The "slug" is the URL-friendly version of the name. Leave blank to generate automatically.</p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="short_description">Short Description</label>
                            <textarea name="short_description" id="short_description" class="form-textarea" rows="3" placeholder="Brief description for product listings..."><?php echo htmlspecialchars($product['short_description']); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="description">Full Description</label>
                            <textarea name="description" id="description_field" class="form-textarea" rows="8" placeholder="Complete product description..."><?php echo htmlspecialchars($product['description']); ?></textarea>
                        </div>
                    </div>

                    <!-- Product Data Tabs -->
                    <div class="editor-card">
                        <div class="tab-nav">
                            <button type="button" class="tab-button active" data-tab="general">General</button>
                            <button type="button" class="tab-button" data-tab="inventory">Inventory</button>
                            <button type="button" class="tab-button" data-tab="shipping">Shipping</button>
                            <button type="button" class="tab-button" data-tab="attributes">Attributes</button>
                            <button type="button" class="tab-button <?php echo $product['type'] !== 'variable' ? 'hidden' : ''; ?>" id="variations-tab-button" data-tab="variations">Variations</button>
                            <button type="button" class="tab-button" data-tab="advanced">Advanced</button>
                        </div>
                        
                        <!-- General Tab -->
                        <div class="tab-content active" id="tab-general">
                            <div class="grid grid-cols-2 gap-6">
                                <div class="form-group">
                                    <label class="form-label" for="regular_price">Regular Price (<?php echo getCurrencySymbol(); ?>)</label>
                                    <input type="text" name="regular_price" id="regular_price" class="form-input" value="<?php echo htmlspecialchars($product['regular_price']); ?>" placeholder="0.00">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="sale_price">Sale Price (<?php echo getCurrencySymbol(); ?>)</label>
                                    <input type="text" name="sale_price" id="sale_price" class="form-input" value="<?php echo htmlspecialchars($product['sale_price']); ?>" placeholder="0.00">
                                    <button type="button" class="text-xs text-primary-color hover:underline mt-1" onclick="toggleSaleSchedule()">Schedule Sale</button>
                                </div>
                            </div>
                            
                            <div id="sale-schedule-fields" class="<?php echo (empty($product['date_on_sale_from']) && empty($product['date_on_sale_to'])) ? 'hidden' : ''; ?> mt-4 p-4 bg-gray-50 rounded-md border">
                                <div class="grid grid-cols-2 gap-6">
                                    <div class="form-group">
                                        <label class="form-label" for="date_on_sale_from">Sale Start Date</label>
                                        <input type="datetime-local" name="date_on_sale_from" id="date_on_sale_from" class="form-input" value="<?php echo $product['date_on_sale_from'] ? date('Y-m-d\TH:i', strtotime($product['date_on_sale_from'])) : ''; ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="date_on_sale_to">Sale End Date</label>
                                        <input type="datetime-local" name="date_on_sale_to" id="date_on_sale_to" class="form-input" value="<?php echo $product['date_on_sale_to'] ? date('Y-m-d\TH:i', strtotime($product['date_on_sale_to'])) : ''; ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-6 mt-4">
                                <div class="form-group">
                                    <label class="form-label" for="tax_status">Tax Status</label>
                                    <select name="tax_status" id="tax_status" class="form-select">
                                        <?php foreach ($tax_statuses as $value => $label): ?>
                                            <option value="<?php echo $value; ?>" <?php echo $product['tax_status'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="tax_class">Tax Class</label>
                                    <select name="tax_class" id="tax_class" class="form-select">
                                        <option value="" <?php echo $product['tax_class'] === '' ? 'selected' : ''; ?>>Standard</option>
                                        <option value="reduced-rate" <?php echo $product['tax_class'] === 'reduced-rate' ? 'selected' : ''; ?>>Reduced rate</option>
                                        <option value="zero-rate" <?php echo $product['tax_class'] === 'zero-rate' ? 'selected' : ''; ?>>Zero rate</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Inventory Tab -->
                        <div class="tab-content" id="tab-inventory">
                            <div class="form-group">
                                <label class="form-label" for="sku">SKU (Stock Keeping Unit)</label>
                                <input type="text" name="sku" id="sku" class="form-input" value="<?php echo htmlspecialchars($product['sku']); ?>" placeholder="Enter unique product identifier">
                            </div>
                            
                            <div class="form-checkbox">
                                <input type="checkbox" name="manage_stock" id="manage_stock" <?php echo $product['manage_stock'] ? 'checked' : ''; ?> onchange="toggleStockFields()">
                                <label for="manage_stock">Enable stock management at product level</label>
                            </div>
                            
                            <div id="stock-fields" class="<?php echo !$product['manage_stock'] ? 'hidden' : ''; ?> mt-4 p-4 border-l-4 border-primary-color bg-gray-50 rounded">
                                <div class="form-group">
                                    <label class="form-label" for="stock_quantity">Stock Quantity</label>
                                    <input type="number" name="stock_quantity" id="stock_quantity" class="form-input" value="<?php echo htmlspecialchars($product['stock_quantity'] ?? ''); ?>" min="0">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="backorders">Allow Backorders?</label>
                                    <select name="backorders" id="backorders" class="form-select">
                                        <?php foreach ($backorder_options as $value => $label): ?>
                                            <option value="<?php echo $value; ?>" <?php echo $product['backorders'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group mt-4 <?php echo $product['manage_stock'] ? 'hidden' : ''; ?>" id="stock_status_group">
                                <label class="form-label" for="stock_status">Stock Status</label>
                                <select name="stock_status" id="stock_status" class="form-select">
                                    <?php foreach ($stock_statuses as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo $product['stock_status'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-checkbox mt-4">
                                <input type="checkbox" name="sold_individually" id="sold_individually" <?php echo $product['sold_individually'] ? 'checked' : ''; ?>>
                                <label for="sold_individually">Sold individually (limit purchases to 1 item per order)</label>
                            </div>
                        </div>
                        
                        <!-- Shipping Tab -->
                        <div class="tab-content" id="tab-shipping">
                            <div class="form-group">
                                <label class="form-label" for="weight">Weight (<?php echo getWeightUnit(); ?>)</label>
                                <input type="text" name="weight" id="weight" class="form-input" value="<?php echo htmlspecialchars($product['weight']); ?>" placeholder="0.0">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Dimensions (<?php echo getDimensionUnit(); ?>)</label>
                                <div class="grid grid-cols-3 gap-4">
                                    <input type="text" name="length" placeholder="Length" class="form-input" value="<?php echo htmlspecialchars($product['dimensions']['length']); ?>">
                                    <input type="text" name="width" placeholder="Width" class="form-input" value="<?php echo htmlspecialchars($product['dimensions']['width']); ?>">
                                    <input type="text" name="height" placeholder="Height" class="form-input" value="<?php echo htmlspecialchars($product['dimensions']['height']); ?>">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="shipping_class_id">Shipping Class</label>
                                <select name="shipping_class_id" id="shipping_class_id" class="form-select">
                                    <option value="0">No shipping class</option>
                                    <?php foreach ($all_shipping_classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>" <?php echo $product['shipping_class_id'] == $class['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($class['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Attributes Tab -->
                        <div class="tab-content" id="tab-attributes">
                            <div class="flex justify-between items-center mb-4">
                                <h4 class="text-lg font-medium">Product Attributes</h4>
                                <button type="button" class="btn btn-sm btn-primary" onclick="productEditor.showAddAttributeModal()">
                                    + Add Attribute
                                </button>
                            </div>
                            
                            <div id="attributes-list-container" class="space-y-4">
                                <!-- Populated by JavaScript -->
                            </div>
                            
                            <div class="mt-4 p-4 bg-blue-50 text-blue-700 text-sm rounded-md border border-blue-200">
                                <strong>Tip:</strong> Add attributes like size, color, or material. Select "Used for variations" to enable product variations.
                            </div>
                        </div>
                        
                        <!-- Variations Tab -->
                        <div class="tab-content <?php echo $product['type'] !== 'variable' ? 'hidden' : ''; ?>" id="tab-variations">
                            <div class="flex justify-between items-center mb-4">
                                <h4 class="text-lg font-medium">Product Variations</h4>
                                <div class="flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline" onclick="productEditor.generateVariationsFromAttributes()">
                                        🎲 Generate All Variations
                                    </button>
                                    <button type="button" class="btn btn-sm btn-primary" onclick="productEditor.addManualVariation()">
                                        + Add Variation
                                    </button>
                                </div>
                            </div>
                            
                            <div id="variations-list-container" class="space-y-2">
                                <!-- Populated by JavaScript -->
                            </div>
                        </div>
                        
                        <!-- Advanced Tab -->
                        <div class="tab-content" id="tab-advanced">
                            <div class="form-group">
                                <label class="form-label" for="purchase_note">Purchase Note</label>
                                <textarea name="purchase_note" id="purchase_note" class="form-textarea" rows="3" placeholder="Optional note to send the customer after purchase..."><?php echo htmlspecialchars($product['purchase_note']); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="menu_order">Menu Order</label>
                                <input type="number" name="menu_order" id="menu_order" class="form-input" value="<?php echo intval($product['menu_order']); ?>" min="0">
                                <p class="text-xs text-gray-500 mt-1">Custom ordering position (lower numbers appear first).</p>
                            </div>
                            
                            <div class="form-checkbox">
                                <input type="checkbox" name="reviews_allowed" id="reviews_allowed" <?php echo $product['reviews_allowed'] ? 'checked' : ''; ?>>
                                <label for="reviews_allowed">Enable reviews</label>
                            </div>
                        </div>
                    </div>

                    <!-- Type-specific sections -->
                    <div id="external_product_fields" class="editor-card <?php echo ($product['type'] !== 'external') ? 'hidden' : ''; ?>">
                        <h3>🔗 External/Affiliate Product</h3>
                        <div class="form-group">
                            <label class="form-label" for="external_url">Product URL *</label>
                            <input type="url" name="external_url" id="external_url" class="form-input" placeholder="https://example.com/product" value="<?php echo htmlspecialchars($product['external_url']); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="button_text">Button Text *</label>
                            <input type="text" name="button_text" id="button_text" class="form-input" placeholder="Buy product" value="<?php echo htmlspecialchars($product['button_text']); ?>">
                        </div>
                    </div>

                    <div id="grouped_product_fields" class="editor-card <?php echo ($product['type'] !== 'grouped') ? 'hidden' : ''; ?>">
                        <h3>📦 Grouped Products</h3>
                        <div class="form-group">
                            <label class="form-label" for="grouped_products_search">Search for products to group</label>
                            <div class="autocomplete-container">
                                <input type="text" id="grouped_products_search" class="form-input" placeholder="Type to search products...">
                                <div id="grouped_products_suggestions" class="autocomplete-suggestions" style="display: none;"></div>
                            </div>
                        </div>
                        <div id="selected_grouped_products_list" class="mt-4 space-y-2">
                            <!-- Populated by JavaScript -->
                        </div>
                    </div>

                    <div id="downloadable_product_fields" class="editor-card <?php echo !$product['downloadable'] ? 'hidden' : ''; ?>">
                        <h3>📥 Downloadable Files</h3>
                        <div id="downloadable_files_list" class="space-y-3 mb-4">
                            <!-- Populated by JavaScript -->
                        </div>
                        <button type="button" class="btn btn-sm btn-outline" onclick="productEditor.addDownloadableFile()">
                            + Add File
                        </button>
                        
                        <div class="grid grid-cols-2 gap-6 mt-6">
                            <div class="form-group">
                                <label class="form-label" for="download_limit">Download Limit</label>
                                <input type="number" name="download_limit" id="download_limit" class="form-input" placeholder="Unlimited" value="<?php echo $product['download_limit'] == -1 ? '' : $product['download_limit']; ?>" min="-1">
                                <p class="text-xs text-gray-500 mt-1">Leave blank or -1 for unlimited.</p>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="download_expiry">Download Expiry (Days)</label>
                                <input type="number" name="download_expiry" id="download_expiry" class="form-input" placeholder="Never" value="<?php echo $product['download_expiry'] == -1 ? '' : $product['download_expiry']; ?>" min="-1">
                                <p class="text-xs text-gray-500 mt-1">Enter number of days. Leave blank or -1 for never.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="editor-sidebar">
                    <!-- Publish Actions -->
                    <div class="editor-card">
                        <h3>📤 Publish</h3>
                        <div class="form-group">
                            <label class="form-label" for="form_status_select">Status</label>
                            <select id="form_status_select" class="form-select">
                                <option value="draft" <?php echo $product['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="publish" <?php echo $product['status'] === 'publish' ? 'selected' : ''; ?>>Published</option>
                                <option value="pending" <?php echo $product['status'] === 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                                <option value="private" <?php echo $product['status'] === 'private' ? 'selected' : ''; ?>>Private</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="catalog_visibility">Catalog Visibility</label>
                            <select name="catalog_visibility" id="catalog_visibility" class="form-select">
                                <?php foreach ($catalog_visibility_options as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $product['catalog_visibility'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="flex items-center justify-between mt-6">
                            <?php if ($is_edit && $product['id']): ?>
                            <button type="button" onclick="handleDeleteProduct(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars(addslashes($product['name'])); ?>')" class="text-red-600 hover:text-red-800 text-sm font-medium">
                                🗑 Move to Trash
                            </button>
                            <?php endif; ?>
                            
                            <button type="button" onclick="saveProduct(document.getElementById('form_status_select').value)" class="btn btn-primary">
                                ✓ <?php echo $is_edit ? 'Update' : 'Publish'; ?>
                            </button>
                        </div>
                    </div>

                    <!-- Product Type -->
                    <div class="editor-card">
                        <h3>🏷 Product Type</h3>
                        <select name="type" id="product_type_select" class="form-select" onchange="productEditor.handleProductTypeChange(this.value)">
                            <?php foreach ($product_types as $type_key => $type_label): ?>
                                <option value="<?php echo $type_key; ?>" <?php echo $product['type'] === $type_key ? 'selected' : ''; ?>>
                                    <?php echo $type_label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <div class="mt-4 space-y-2">
                            <div class="form-checkbox">
                                <input type="checkbox" name="virtual" id="virtual_cb" <?php echo $product['virtual'] ? 'checked' : ''; ?> onchange="productEditor.handleVirtualToggle(this.checked)">
                                <label for="virtual_cb">Virtual</label>
                            </div>
                            <div class="form-checkbox">
                                <input type="checkbox" name="downloadable" id="downloadable_cb" <?php echo $product['downloadable'] ? 'checked' : ''; ?> onchange="productEditor.handleDownloadableToggle(this.checked)">
                                <label for="downloadable_cb">Downloadable</label>
                            </div>
                        </div>
                    </div>

                    <!-- Categories -->
                    <div class="editor-card">
                        <h3>📂 Categories</h3>
                        <div class="mb-2">
                            <input type="text" id="category-search-input" class="form-input text-sm" placeholder="Search categories..." onkeyup="productEditor.filterTermList('category-list', this.value)">
                        </div>
                        <div id="category-list" class="max-h-48 overflow-y-auto border rounded-md p-3 space-y-2 bg-gray-50">
                            <?php foreach ($all_categories as $category_item): ?>
                                <div class="form-checkbox text-sm">
                                    <input type="checkbox" name="categories[]" value="<?php echo $category_item['id']; ?>" 
                                           id="cat_<?php echo $category_item['id']; ?>"
                                           <?php if (is_array($product['categories'])) { echo in_array($category_item['id'], array_column($product['categories'], 'id')) ? 'checked' : ''; } ?>>
                                    <label for="cat_<?php echo $category_item['id']; ?>"><?php echo htmlspecialchars($category_item['name']); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline mt-3 w-full" onclick="productEditor.showCreateTermModal('category')">
                            + Add New Category
                        </button>
                    </div>

                    <!-- Tags -->
                    <div class="editor-card">
                        <h3>🏷 Tags</h3>
                        <div class="autocomplete-container">
                            <input type="text" id="tag-input" class="form-input text-sm" placeholder="Add tags (comma-separated or Enter)">
                            <div id="tag-suggestions" class="autocomplete-suggestions" style="display: none;"></div>
                        </div>
                        <div id="selected-tags-container" class="tag-list mt-3">
                            <!-- Populated by JavaScript -->
                        </div>
                        <p class="text-xs text-gray-500 mt-2">Separate tags with commas or press Enter to add them.</p>
                    </div>

                    <!-- Product Images -->
                    <div class="editor-card">
                        <h3>🖼 Product Images</h3>
                        
                        <div id="main-image-container" class="mb-4 <?php echo empty($product['images']) ? 'hidden' : '' ?>">
                            <label class="form-label text-sm font-medium">Main Product Image</label>
                            <div class="relative">
                                <img id="main-product-image-preview" src="<?php echo !empty($product['images'][0]['src']) ? htmlspecialchars($product['images'][0]['src']) : ''; ?>" class="w-full h-48 object-cover rounded-lg border-2 border-primary-color" style="<?php echo empty($product['images'][0]['src']) ? 'display: none;' : ''; ?>">
                                <button type="button" id="remove-main-image-btn" class="absolute top-2 right-2 btn btn-xs btn-danger <?php echo empty($product['images']) ? 'hidden' : '' ?>" onclick="productEditor.removeMainImage()">
                                    ×
                                </button>
                            </div>
                        </div>
                        
                        <div id="product-images-gallery" class="image-gallery mb-4">
                            <!-- Populated by JavaScript -->
                        </div>
                        
                        <div class="image-upload-area" id="image-upload-area" onclick="document.getElementById('product_images_input').click()">
                            <div class="text-center py-6">
                                <div style="font-size: 48px; margin-bottom: 12px; color: var(--gray-400);">📷</div>
                                <p class="text-gray-600 font-medium">Click to upload images</p>
                                <p class="text-gray-500 text-sm">or drag and drop files here</p>
                                <p class="text-gray-400 text-xs mt-1">PNG, JPG, GIF up to 10MB each</p>
                            </div>
                        </div>
                        
                        <input type="file" id="product_images_input" multiple accept="image/*" class="hidden" onchange="productEditor.handleImageFiles(this.files)">
                    </div>

                    <!-- Featured Product -->
                    <div class="editor-card">
                        <div class="form-checkbox">
                            <input type="checkbox" name="featured" id="featured_cb" <?php echo $product['featured'] ? 'checked' : ''; ?>>
                            <label for="featured_cb" class="font-medium">
                                ⭐ This is a featured product
                            </label>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Featured products are highlighted in your store and may appear in special sections.</p>
                    </div>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Modals -->

<!-- Add/Edit Attribute Modal -->
<div id="attribute-modal" class="modal">
    <div class="modal-content">
        <h3 id="attribute-modal-title" class="text-xl font-semibold mb-4">Add Attribute</h3>
        <form id="attribute-modal-form">
            <input type="hidden" id="editing-attribute-index" value="-1">
            
            <div class="form-group">
                <label for="attribute-modal-select" class="form-label">Attribute Type</label>
                <select id="attribute-modal-select" class="form-select" onchange="productEditor.handleAttributeModalTypeChange(this.value)">
                    <option value="">- Select Attribute -</option>
                    <?php foreach($all_attributes as $attr): ?>
                        <option value="<?php echo $attr['id']; ?>" data-name="<?php echo htmlspecialchars($attr['name']); ?>">
                            <?php echo htmlspecialchars($attr['name']); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="custom">Custom product attribute</option>
                </select>
            </div>
            
            <div class="form-group hidden" id="custom-attribute-name-group">
                <label for="custom-attribute-name" class="form-label">Attribute Name</label>
                <input type="text" id="custom-attribute-name" class="form-input" placeholder="Enter attribute name (e.g., Size, Color)">
            </div>
            
            <div class="form-group">
                <label for="attribute-modal-values" class="form-label">Value(s)</label>
                <div id="attribute-terms-select-container" class="hidden">
                    <select id="attribute-modal-terms-select" class="form-select" multiple size="6">
                        <!-- Populated by JavaScript -->
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Hold Ctrl/Cmd to select multiple terms.</p>
                </div>
                <textarea id="attribute-modal-textarea-values" class="form-textarea" rows="3" placeholder="Enter values separated by a pipe (|) e.g. Small | Medium | Large"></textarea>
                <p class="text-xs text-gray-500 mt-1">For global attributes, select terms above. For custom attributes, type values here.</p>
            </div>
            
            <div class="space-y-3 mt-4">
                <div class="form-checkbox">
                    <input type="checkbox" id="attribute-modal-visible">
                    <label for="attribute-modal-visible">Visible on the product page</label>
                </div>
                <div class="form-checkbox">
                    <input type="checkbox" id="attribute-modal-variation">
                    <label for="attribute-modal-variation">Used for variations</label>
                </div>
            </div>
            
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" class="btn btn-outline" onclick="productEditor.closeModal('attribute-modal')">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="productEditor.saveAttributeFromModal()">Save Attribute</button>
            </div>
        </form>
    </div>
</div>

<!-- Create Term Modal -->
<div id="create-term-modal" class="modal">
    <div class="modal-content">
        <h3 id="create-term-modal-title" class="text-xl font-semibold mb-4">Create New Term</h3>
        <form id="create-term-modal-form">
            <input type="hidden" id="term-type-to-create" value="">
            
            <div class="form-group">
                <label for="new-term-name" class="form-label">Name</label>
                <input type="text" id="new-term-name" class="form-input" required placeholder="Enter term name">
            </div>
            
            <div id="parent-term-group" class="form-group hidden">
                <label for="parent-term-id" class="form-label">Parent Category</label>
                <select id="parent-term-id" class="form-select">
                    <option value="0">- No Parent -</option>
                    <?php foreach ($all_categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" class="btn btn-outline" onclick="productEditor.closeModal('create-term-modal')">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="productEditor.submitNewTerm()">Create</button>
            </div>
        </form>
    </div>
</div>

<!-- Image Preview Modal -->
<div id="image-preview-modal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-semibold">Image Preview</h3>
            <button type="button" class="btn btn-outline" onclick="productEditor.closeModal('image-preview-modal')">
                ×
            </button>
        </div>
        <img id="modal-image-preview" src="" class="w-full h-auto max-h-96 object-contain rounded-lg">
        <div class="form-group mt-4">
            <label for="modal-image-alt" class="form-label">Alt Text</label>
            <input type="text" id="modal-image-alt" class="form-input" placeholder="Enter alternative text for the image">
        </div>
        <div class="flex justify-end gap-3 mt-4">
            <button type="button" class="btn btn-outline" onclick="productEditor.closeModal('image-preview-modal')">Close</button>
            <button type="button" class="btn btn-primary" onclick="productEditor.saveImageAlt()">Save</button>
        </div>
    </div>
</div>

<script>
// Global variables
let productEditor;

// Utility functions
function generateSlug() {
    const nameVal = document.getElementById('name').value;
    document.getElementById('slug').value = nameVal.toString().toLowerCase()
        .replace(/\s+/g, '-')
        .replace(/[^\w\-]+/g, '')
        .replace(/\-\-+/g, '-')
        .replace(/^-+/, '')
        .replace(/-+$/, '');
}

function toggleSaleSchedule() {
    document.getElementById('sale-schedule-fields').classList.toggle('hidden');
}

function toggleStockFields() {
    const manageStock = document.getElementById('manage_stock').checked;
    document.getElementById('stock-fields').classList.toggle('hidden', !manageStock);
    document.getElementById('stock_status_group').classList.toggle('hidden', manageStock);
}

function saveProduct(status) {
    document.getElementById('product_status_hidden').value = status;
    if (typeof productEditor !== 'undefined') {
        productEditor.prepareFormDataForSubmission(document.getElementById('product-form'));
    }
    document.getElementById('product-form').submit();
}

function previewProduct() {
    if (<?php echo $is_edit ? 'true' : 'false'; ?> && <?php echo $product['id'] ?? 'null'; ?>) {
        const previewUrl = '<?php echo rtrim($store_config['store_url'] ?? '', '/'); ?>/?p=<?php echo $product['id'] ?? ''; ?>&preview=true';
        window.open(previewUrl, '_blank');
    } else {
        alert('Please save the product first to preview it.');
    }
}

function handleDeleteProduct(productId, productName) {
    if (confirm(`Are you sure you want to move "${productName}" to trash? This action can be undone from the WooCommerce admin.`)) {
        // Create a form to submit the delete request
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'product-delete.php';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'product_id';
        idInput.value = productId;
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete';
        
        form.appendChild(idInput);
        form.appendChild(actionInput);
        document.body.appendChild(form);
        form.submit();
    }
}

// Tab Navigation
function setupTabNavigation() {
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            const targetTab = button.dataset.tab;
            
            // Remove active class from all buttons and contents
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Add active class to clicked button and corresponding content
            button.classList.add('active');
            const targetContent = document.getElementById(`tab-${targetTab}`);
            if (targetContent) {
                targetContent.classList.add('active');
            }
        });
    });
}

// Simple drag & drop without external library
function setupImageDragDrop() {
    const uploadArea = document.getElementById('image-upload-area');
    
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        uploadArea.addEventListener(eventName, preventDefaults, false);
        document.body.addEventListener(eventName, preventDefaults, false);
    });
    
    ['dragenter', 'dragover'].forEach(eventName => {
        uploadArea.addEventListener(eventName, () => {
            uploadArea.classList.add('dragover');
        }, false);
    });
    
    ['dragleave', 'drop'].forEach(eventName => {
        uploadArea.addEventListener(eventName, () => {
            uploadArea.classList.remove('dragover');
        }, false);
    });
    
    uploadArea.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        productEditor.handleImageFiles(files);
    }, false);
    
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
}

// Simple sortable without external library
function makeImagesSortable() {
    const gallery = document.getElementById('product-images-gallery');
    let draggedElement = null;
    
    gallery.addEventListener('dragstart', function(e) {
        draggedElement = e.target.closest('.image-item');
        if (draggedElement) {
            draggedElement.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/html', draggedElement.outerHTML);
        }
    });
    
    gallery.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        
        const afterElement = getDragAfterElement(gallery, e.clientY);
        if (afterElement == null) {
            gallery.appendChild(draggedElement);
        } else {
            gallery.insertBefore(draggedElement, afterElement);
        }
    });
    
    gallery.addEventListener('dragend', function(e) {
        if (draggedElement) {
            draggedElement.classList.remove('dragging');
            draggedElement = null;
            // Update image order in productEditor
            productEditor.updateImageOrder();
        }
    });
    
    function getDragAfterElement(container, y) {
        const draggableElements = [...container.querySelectorAll('.image-item:not(.dragging)')];
        
        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }
}

// Product Editor Class
class ProductEditor {
    constructor(productData, categories, tags, globalAttributes, shippingClasses) {
        this.product = productData || {};
        this.allCategories = categories || [];
        this.allTags = tags || [];
        this.allGlobalAttributes = globalAttributes || [];
        this.allShippingClasses = shippingClasses || [];
        this.attributeTermsCache = <?php echo json_encode($attribute_terms_cache); ?>;
        
        this.currentAttributes = JSON.parse(JSON.stringify(this.product.attributes || []));
        this.currentVariations = JSON.parse(JSON.stringify(this.product.variations || []));
        this.currentImages = JSON.parse(JSON.stringify(this.product.images || []));
        this.currentSelectedTagIds = (this.product.tags || []).map(t => t.id || t.name);
        this.currentGroupedProducts = this.product.grouped_products || [];
        this.currentDownloads = this.product.downloads || [];
        
        this.currentImageId = null; // For image modal editing
        this.dragCounter = 0; // For drag & drop
        
        this.init();
    }
    
    init() {
        this.setupTagInput();
        this.setupGroupedProductsSearch();
        
        this.renderAttributesList();
        this.renderSelectedTags();
        this.renderProductImages();
        this.renderVariationsList();
        this.renderGroupedProductsList();
        this.renderDownloadableFilesList();
        
        this.handleProductTypeChange(this.product.type || 'simple');
        
        // Load terms for existing global attributes
        this.currentAttributes.forEach(attr => {
            if (attr.id && attr.id > 0 && !this.attributeTermsCache[attr.id]) {
                this.fetchAttributeTerms(attr.id);
            }
        });
    }
    
    setupTagInput() {
        const tagInput = document.getElementById('tag-input');
        const suggestionsContainer = document.getElementById('tag-suggestions');
        
        tagInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase();
            if (query.length >= 1) {
                this.showTagSuggestions(query);
            } else {
                suggestionsContainer.style.display = 'none';
            }
        });
        
        tagInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                this.addTagsFromInput();
            }
        });
        
        tagInput.addEventListener('blur', () => {
            setTimeout(() => {
                suggestionsContainer.style.display = 'none';
            }, 200);
        });
    }
    
    setupGroupedProductsSearch() {
        const searchInput = document.getElementById('grouped_products_search');
        const suggestionsContainer = document.getElementById('grouped_products_suggestions');
        
        if (!searchInput) return;
        
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase();
            if (query.length >= 2) {
                this.showGroupedProductSuggestions(query);
            } else {
                suggestionsContainer.style.display = 'none';
            }
        });
        
        searchInput.addEventListener('blur', () => {
            setTimeout(() => {
                suggestionsContainer.style.display = 'none';
            }, 200);
        });
    }
    
    showTagSuggestions(query) {
        const suggestionsContainer = document.getElementById('tag-suggestions');
        const suggestions = this.allTags.filter(tag => 
            tag.name.toLowerCase().includes(query) && 
            !this.currentSelectedTagIds.includes(tag.id)
        ).slice(0, 10);
        
        if (suggestions.length > 0) {
            suggestionsContainer.innerHTML = suggestions.map(tag => 
                `<div class="autocomplete-suggestion" onclick="productEditor.selectTag(${tag.id}, '${tag.name.replace(/'/g, "\\'")}')">${tag.name}</div>`
            ).join('');
            suggestionsContainer.style.display = 'block';
        } else {
            suggestionsContainer.style.display = 'none';
        }
    }
    
    showGroupedProductSuggestions(query) {
        // This would typically make an AJAX call to search products
        // For now, we'll show a placeholder
        const suggestionsContainer = document.getElementById('grouped_products_suggestions');
        suggestionsContainer.innerHTML = '<div class="autocomplete-suggestion">Search functionality requires AJAX implementation</div>';
        suggestionsContainer.style.display = 'block';
    }
    
    selectTag(tagId, tagName) {
        if (!this.currentSelectedTagIds.includes(tagId)) {
            this.currentSelectedTagIds.push(tagId);
            this.renderSelectedTags();
        }
        document.getElementById('tag-input').value = '';
        document.getElementById('tag-suggestions').style.display = 'none';
    }
    
    addTagsFromInput() {
        const tagInput = document.getElementById('tag-input');
        const tagNames = tagInput.value.split(',').map(t => t.trim()).filter(Boolean);
        
        tagNames.forEach(name => {
            const existingTag = this.allTags.find(t => t.name.toLowerCase() === name.toLowerCase());
            if (existingTag && !this.currentSelectedTagIds.includes(existingTag.id)) {
                this.currentSelectedTagIds.push(existingTag.id);
            } else if (!existingTag) {
                // New tag - add with temporary ID
                const tempId = 'new_' + Date.now() + Math.random();
                this.currentSelectedTagIds.push(tempId);
                this.allTags.push({ id: tempId, name: name });
            }
        });
        
        tagInput.value = '';
        this.renderSelectedTags();
    }
    
    renderSelectedTags() {
        const container = document.getElementById('selected-tags-container');
        container.innerHTML = '';
        
        this.currentSelectedTagIds.forEach(tagId => {
            const tag = this.allTags.find(t => t.id === tagId || t.id === tagId);
            if (tag) {
                const tagElement = document.createElement('div');
                tagElement.className = 'tag-item';
                tagElement.innerHTML = `
                    ${tag.name}
                    <button type="button" class="tag-remove" onclick="productEditor.removeSelectedTag('${tagId}')">×</button>
                    <input type="hidden" name="tags_data[]" value="${typeof tag.id === 'string' && tag.id.startsWith('new_') ? tag.name : tag.id}">
                `;
                container.appendChild(tagElement);
            }
        });
    }
    
    removeSelectedTag(tagId) {
        this.currentSelectedTagIds = this.currentSelectedTagIds.filter(id => id !== tagId);
        this.renderSelectedTags();
    }
    
    handleProductTypeChange(type) {
        document.getElementById('product_type_select').value = type;
        this.product.type = type;
        
        const variationsTabBtn = document.getElementById('variations-tab-button');
        const variationsTabContent = document.getElementById('tab-variations');
        const externalFields = document.getElementById('external_product_fields');
        const groupedFields = document.getElementById('grouped_product_fields');
        
        // Show/hide variations tab
        variationsTabBtn.classList.toggle('hidden', type !== 'variable');
        if (type !== 'variable' && variationsTabContent && variationsTabContent.classList.contains('active')) {
            document.querySelector('.tab-button[data-tab="general"]').click();
        }
        
        // Show/hide type-specific fields
        if (externalFields) externalFields.classList.toggle('hidden', type !== 'external');
        if (groupedFields) groupedFields.classList.toggle('hidden', type !== 'grouped');
        
        // Disable/enable fields based on type
        if (type === 'grouped') {
            ['regular_price', 'sale_price'].forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    el.disabled = true;
                    el.value = '';
                }
            });
            document.getElementById('manage_stock').checked = false;
            toggleStockFields();
        } else {
            ['regular_price', 'sale_price'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.disabled = false;
            });
        }
    }
    
    handleVirtualToggle(isVirtual) {
        const shippingTab = document.querySelector('.tab-button[data-tab="shipping"]');
        if (shippingTab) {
            shippingTab.classList.toggle('hidden', isVirtual);
            if (isVirtual && document.getElementById('tab-shipping').classList.contains('active')) {
                document.querySelector('.tab-button[data-tab="general"]').click();
            }
        }
    }
    
    handleDownloadableToggle(isDownloadable) {
        document.getElementById('downloadable_product_fields').classList.toggle('hidden', !isDownloadable);
        if (isDownloadable) {
            this.renderDownloadableFilesList();
        }
    }
    
    // Image Management
    handleImageFiles(files) {
        Array.from(files).forEach(file => {
            if (file.type.startsWith('image/') && file.size <= 10 * 1024 * 1024) { // 10MB limit
                const reader = new FileReader();
                reader.onload = (e) => {
                    this.currentImages.push({
                        id: 'temp_' + Date.now() + Math.random(),
                        src: e.target.result,
                        name: file.name,
                        alt: '',
                        file_object: file
                    });
                    this.renderProductImages();
                };
                reader.readAsDataURL(file);
            } else if (file.size > 10 * 1024 * 1024) {
                alert(`File ${file.name} is too large. Maximum size is 10MB.`);
            }
        });
    }
    
    renderProductImages() {
        const galleryContainer = document.getElementById('product-images-gallery');
        const mainImagePreview = document.getElementById('main-product-image-preview');
        const mainImageContainer = document.getElementById('main-image-container');
        const removeMainImageBtn = document.getElementById('remove-main-image-btn');
        
        galleryContainer.innerHTML = '';
        
        if (this.currentImages.length === 0) {
            mainImageContainer.classList.add('hidden');
            return;
        }
        
        // Show main image
        mainImagePreview.src = this.currentImages[0].src;
        mainImagePreview.style.display = 'block';
        mainImageContainer.classList.remove('hidden');
        removeMainImageBtn.classList.remove('hidden');
        
        // Render gallery
        this.currentImages.forEach((img, index) => {
            const div = document.createElement('div');
            div.className = 'image-item';
            div.dataset.index = index;
            div.draggable = true;
            
            div.innerHTML = `
                <img src="${img.src}" alt="${img.alt || 'Product image'}" class="w-full h-full object-cover">
                <div class="overlay">
                    ${index !== 0 ? `<button type="button" class="btn btn-xs btn-primary" onclick="productEditor.setAsMainImage(${index})">Main</button>` : '<span class="btn btn-xs bg-green-500 text-white">Main</span>'}
                    <button type="button" class="btn btn-xs btn-outline" onclick="productEditor.editImage(${index})">Edit</button>
                    <button type="button" class="btn btn-xs btn-danger" onclick="productEditor.removeImage(${index})">×</button>
                </div>
            `;
            galleryContainer.appendChild(div);
        });
        
        // Setup drag and drop for sorting
        this.setupImageSorting();
    }
    
    setupImageSorting() {
        const gallery = document.getElementById('product-images-gallery');
        let draggedElement = null;
        
        gallery.addEventListener('dragstart', (e) => {
            draggedElement = e.target.closest('.image-item');
            if (draggedElement) {
                draggedElement.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
            }
        });
        
        gallery.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        });
        
        gallery.addEventListener('drop', (e) => {
            e.preventDefault();
            if (draggedElement) {
                const allImages = Array.from(gallery.querySelectorAll('.image-item'));
                const dragIndex = allImages.indexOf(draggedElement);
                const dropIndex = allImages.indexOf(e.target.closest('.image-item'));
                
                if (dragIndex !== -1 && dropIndex !== -1 && dragIndex !== dropIndex) {
                    // Reorder images array
                    const movedImage = this.currentImages.splice(dragIndex, 1)[0];
                    this.currentImages.splice(dropIndex, 0, movedImage);
                    this.renderProductImages();
                }
            }
        });
        
        gallery.addEventListener('dragend', (e) => {
            if (draggedElement) {
                draggedElement.classList.remove('dragging');
                draggedElement = null;
            }
        });
    }
    
    updateImageOrder() {
        const gallery = document.getElementById('product-images-gallery');
        const imageElements = Array.from(gallery.querySelectorAll('.image-item'));
        const newOrder = [];
        
        imageElements.forEach(element => {
            const index = parseInt(element.dataset.index);
            if (!isNaN(index) && this.currentImages[index]) {
                newOrder.push(this.currentImages[index]);
            }
        });
        
        this.currentImages = newOrder;
        this.renderProductImages();
    }
    
    setAsMainImage(index) {
        if (index > 0 && index < this.currentImages.length) {
            const imageToMove = this.currentImages.splice(index, 1)[0];
            this.currentImages.unshift(imageToMove);
            this.renderProductImages();
        }
    }
    
    removeImage(index) {
        this.currentImages.splice(index, 1);
        this.renderProductImages();
    }
    
    removeMainImage() {
        if (this.currentImages.length > 0) {
            this.currentImages.shift();
            this.renderProductImages();
        }
    }
    
    editImage(index) {
        this.currentImageId = index;
        const img = this.currentImages[index];
        document.getElementById('modal-image-preview').src = img.src;
        document.getElementById('modal-image-alt').value = img.alt || '';
        this.showModal('image-preview-modal');
    }
    
    saveImageAlt() {
        if (this.currentImageId !== null) {
            const alt = document.getElementById('modal-image-alt').value;
            this.currentImages[this.currentImageId].alt = alt;
            this.closeModal('image-preview-modal');
        }
    }
    
    // Attribute Management
    renderAttributesList() {
        const container = document.getElementById('attributes-list-container');
        container.innerHTML = '';
        
        this.currentAttributes.forEach((attr, index) => {
            const div = document.createElement('div');
            div.className = 'attribute-item';
            div.dataset.index = index;
            
            let attrName = attr.name;
            if (attr.id && attr.id > 0) {
                const globalAttr = this.allGlobalAttributes.find(g => g.id === attr.id);
                if (globalAttr) attrName = globalAttr.name;
            }
            
            div.innerHTML = `
                <div class="attribute-header" onclick="productEditor.toggleAttribute(${index})">
                    <div>
                        <strong class="text-gray-800">${attrName}</strong>
                        <div class="text-sm text-gray-500">${(attr.options || []).join(' | ')}</div>
                        <div class="text-xs mt-1">
                            <span class="${attr.visible ? 'text-green-600' : 'text-gray-400'}">${attr.visible ? 'Visible' : 'Hidden'}</span> • 
                            <span class="${attr.variation ? 'text-blue-600' : 'text-gray-400'}">${attr.variation ? 'For variations' : 'Not for variations'}</span>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline" onclick="event.stopPropagation(); productEditor.editAttribute(${index})">Edit</button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="event.stopPropagation(); productEditor.removeAttribute(${index})">Remove</button>
                    </div>
                </div>
                <div class="attribute-content" id="attribute-content-${index}">
                    <div class="p-4">
                        <div class="form-group">
                            <label class="form-label text-sm">Attribute Values</label>
                            <div class="flex flex-wrap gap-2">
                                ${(attr.options || []).map(option => `<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-sm">${option}</span>`).join('')}
                            </div>
                        </div>
                    </div>
                </div>
            `;
            container.appendChild(div);
        });
    }
    
    toggleAttribute(index) {
        const content = document.getElementById(`attribute-content-${index}`);
        content.classList.toggle('active');
    }
    
    showAddAttributeModal(indexToEdit = -1) {
        const modal = document.getElementById('attribute-modal');
        const form = document.getElementById('attribute-modal-form');
        form.reset();
        document.getElementById('editing-attribute-index').value = indexToEdit;
        document.getElementById('custom-attribute-name-group').classList.add('hidden');
        document.getElementById('attribute-terms-select-container').classList.add('hidden');
        document.getElementById('attribute-modal-textarea-values').classList.remove('hidden');
        
        if (indexToEdit > -1 && this.currentAttributes[indexToEdit]) {
            document.getElementById('attribute-modal-title').textContent = 'Edit Attribute';
            const attr = this.currentAttributes[indexToEdit];
            const select = document.getElementById('attribute-modal-select');
            
            if (attr.id && attr.id > 0) {
                select.value = attr.id;
                this.loadTermsForAttributeModal(attr.id, attr.options);
            } else {
                select.value = 'custom';
                document.getElementById('custom-attribute-name-group').classList.remove('hidden');
                document.getElementById('custom-attribute-name').value = attr.name;
                document.getElementById('attribute-modal-textarea-values').value = (attr.options || []).join(' | ');
            }
            
            document.getElementById('attribute-modal-visible').checked = attr.visible || false;
            document.getElementById('attribute-modal-variation').checked = attr.variation || false;
        } else {
            document.getElementById('attribute-modal-title').textContent = 'Add Attribute';
        }
        
        this.handleAttributeModalTypeChange(document.getElementById('attribute-modal-select').value);
        this.showModal('attribute-modal');
    }
    
    handleAttributeModalTypeChange(selectedValue) {
        const customNameGroup = document.getElementById('custom-attribute-name-group');
        const termsSelectContainer = document.getElementById('attribute-terms-select-container');
        const termsTextarea = document.getElementById('attribute-modal-textarea-values');
        
        if (selectedValue === 'custom') {
            customNameGroup.classList.remove('hidden');
            termsSelectContainer.classList.add('hidden');
            termsTextarea.classList.remove('hidden');
            termsTextarea.value = '';
        } else if (selectedValue) {
            customNameGroup.classList.add('hidden');
            termsSelectContainer.classList.remove('hidden');
            termsTextarea.classList.add('hidden');
            this.loadTermsForAttributeModal(parseInt(selectedValue));
        } else {
            customNameGroup.classList.add('hidden');
            termsSelectContainer.classList.add('hidden');
            termsTextarea.classList.remove('hidden');
            termsTextarea.value = '';
        }
    }
    
    async loadTermsForAttributeModal(attributeId, selectedOptions = []) {
        const termsSelect = document.getElementById('attribute-modal-terms-select');
        termsSelect.innerHTML = '';
        
        const terms = this.attributeTermsCache[attributeId] || [];
        
        if (terms.length > 0) {
            terms.forEach(term => {
                const option = document.createElement('option');
                option.value = term.name;
                option.textContent = term.name;
                if (selectedOptions.includes(term.name)) {
                    option.selected = true;
                }
                termsSelect.appendChild(option);
            });
            document.getElementById('attribute-terms-select-container').classList.remove('hidden');
            document.getElementById('attribute-modal-textarea-values').classList.add('hidden');
        } else {
            document.getElementById('attribute-terms-select-container').classList.add('hidden');
            document.getElementById('attribute-modal-textarea-values').classList.remove('hidden');
            document.getElementById('attribute-modal-textarea-values').value = selectedOptions.join(' | ');
        }
    }
    
    async fetchAttributeTerms(attributeId) {
        // This would be an AJAX call in a real implementation
        // For now, using cached data
        return this.attributeTermsCache[attributeId] || [];
    }
    
    saveAttributeFromModal() {
        const index = parseInt(document.getElementById('editing-attribute-index').value);
        const select = document.getElementById('attribute-modal-select');
        const selectedType = select.value;
        
        let newAttr = {
            id: 0,
            name: '',
            options: [],
            visible: document.getElementById('attribute-modal-visible').checked,
            variation: document.getElementById('attribute-modal-variation').checked,
            position: (index > -1) ? this.currentAttributes[index].position : this.currentAttributes.length
        };
        
        if (selectedType === 'custom') {
            newAttr.name = document.getElementById('custom-attribute-name').value.trim();
            const customValues = document.getElementById('attribute-modal-textarea-values').value.trim();
            if (customValues) newAttr.options = customValues.split('|').map(v => v.trim()).filter(Boolean);
        } else if (selectedType) {
            newAttr.id = parseInt(selectedType);
            const globalAttr = this.allGlobalAttributes.find(ga => ga.id === newAttr.id);
            if (globalAttr) newAttr.name = globalAttr.name;
            
            const termsSelect = document.getElementById('attribute-modal-terms-select');
            if (!document.getElementById('attribute-terms-select-container').classList.contains('hidden')) {
                newAttr.options = Array.from(termsSelect.selectedOptions).map(opt => opt.value);
            } else {
                const customValues = document.getElementById('attribute-modal-textarea-values').value.trim();
                if (customValues) newAttr.options = customValues.split('|').map(v => v.trim()).filter(Boolean);
            }
        }
        
        if (!newAttr.name && newAttr.id === 0) {
            alert('Attribute name is required for custom attributes.');
            return;
        }
        if (newAttr.options.length === 0) {
            alert('Attribute must have at least one value/term.');
            return;
        }
        
        if (index > -1) {
            this.currentAttributes[index] = newAttr;
        } else {
            this.currentAttributes.push(newAttr);
        }
        
        this.renderAttributesList();
        this.closeModal('attribute-modal');
    }
    
    editAttribute(index) {
        this.showAddAttributeModal(index);
    }
    
    removeAttribute(index) {
        if (confirm('Are you sure? This will remove the attribute from this product.')) {
            this.currentAttributes.splice(index, 1);
            this.renderAttributesList();
        }
    }
    
    // Variation Management
    renderVariationsList() {
        const container = document.getElementById('variations-list-container');
        container.innerHTML = '';
        
        this.currentVariations.forEach((variation, index) => {
            const variationId = variation.id || `new_${index}`;
            const div = document.createElement('div');
            div.className = 'variation-item';
            
            div.innerHTML = `
                <div class="variation-header" onclick="productEditor.toggleVariation('${variationId}')">
                    <div>
                        <strong>Variation #${variationId}</strong>
                        <div class="text-sm text-gray-500">${this.formatVariationAttributes(variation.attributes)}</div>
                        <div class="text-xs text-gray-400 mt-1">
                            Price: ${variation.regular_price || 'Not set'} ${variation.sale_price ? `(Sale: ${variation.sale_price})` : ''}
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline" onclick="event.stopPropagation(); productEditor.editVariation(${index})">Edit</button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="event.stopPropagation(); productEditor.removeVariation(${index})">Remove</button>
                    </div>
                </div>
                <div class="variation-content" id="variation-content-${variationId}">
                    <div class="grid grid-cols-2 gap-4 p-4">
                        <div class="form-group">
                            <label class="form-label text-sm">SKU</label>
                            <input type="text" class="form-input text-sm" name="variations[${index}][sku]" value="${variation.sku || ''}" onchange="productEditor.updateVariationField(${index}, 'sku', this.value)">
                        </div>
                        <div class="form-group">
                            <label class="form-label text-sm">Regular Price</label>
                            <input type="text" class="form-input text-sm" name="variations[${index}][regular_price]" value="${variation.regular_price || ''}" onchange="productEditor.updateVariationField(${index}, 'regular_price', this.value)">
                        </div>
                        <div class="form-group">
                            <label class="form-label text-sm">Sale Price</label>
                            <input type="text" class="form-input text-sm" name="variations[${index}][sale_price]" value="${variation.sale_price || ''}" onchange="productEditor.updateVariationField(${index}, 'sale_price', this.value)">
                        </div>
                        <div class="form-group">
                            <label class="form-label text-sm">Stock Status</label>
                            <select class="form-select text-sm" name="variations[${index}][stock_status]" onchange="productEditor.updateVariationField(${index}, 'stock_status', this.value)">
                                <option value="instock" ${variation.stock_status === 'instock' ? 'selected' : ''}>In stock</option>
                                <option value="outofstock" ${variation.stock_status === 'outofstock' ? 'selected' : ''}>Out of stock</option>
                                <option value="onbackorder" ${variation.stock_status === 'onbackorder' ? 'selected' : ''}>On backorder</option>
                            </select>
                        </div>
                        <div class="form-group col-span-2">
                            <div class="form-checkbox">
                                <input type="checkbox" name="variations[${index}][manage_stock]" ${variation.manage_stock ? 'checked' : ''} onchange="productEditor.updateVariationField(${index}, 'manage_stock', this.checked)">
                                <label class="text-sm">Manage stock for this variation</label>
                            </div>
                            ${variation.manage_stock ? `
                                <input type="number" class="form-input text-sm mt-2" placeholder="Stock quantity" name="variations[${index}][stock_quantity]" value="${variation.stock_quantity || ''}" onchange="productEditor.updateVariationField(${index}, 'stock_quantity', this.value)">
                            ` : ''}
                        </div>
                    </div>
                    <input type="hidden" name="variations[${index}][id]" value="${variation.id || ''}">
                    ${(variation.attributes || []).map((attr, attrIdx) => `
                        <input type="hidden" name="variations[${index}][attributes][${attrIdx}][id]" value="${attr.id || 0}">
                        <input type="hidden" name="variations[${index}][attributes][${attrIdx}][name]" value="${attr.name || ''}">
                        <input type="hidden" name="variations[${index}][attributes][${attrIdx}][option]" value="${attr.option || ''}">
                    `).join('')}
                </div>
            `;
            container.appendChild(div);
        });
    }
    
    formatVariationAttributes(attrs) {
        if (!attrs || !Array.isArray(attrs)) return 'N/A';
        return attrs.map(a => `${a.name || 'Attr'}: ${a.option || 'Opt'}`).join(', ');
    }
    
    toggleVariation(variationId) {
        const content = document.getElementById(`variation-content-${variationId}`);
        content.classList.toggle('active');
    }
    
    updateVariationField(index, field, value) {
        if (this.currentVariations[index]) {
            this.currentVariations[index][field] = value;
        }
    }
    
    generateVariationsFromAttributes() {
        const variationAttributes = this.currentAttributes.filter(attr => attr.variation && attr.options && attr.options.length > 0);
        if (variationAttributes.length === 0) {
            alert('No attributes marked for variations or attributes have no values.');
            return;
        }
        
        this.currentVariations = [];
        const attributeOptions = variationAttributes.map(attr => 
            attr.options.map(opt => ({ id: attr.id, name: attr.name, option: opt }))
        );
        
        const combinations = this.getAllCombinations(attributeOptions);
        
        combinations.forEach((combo, index) => {
            this.currentVariations.push({
                id: null,
                attributes: combo,
                regular_price: '',
                sale_price: '',
                sku: '',
                stock_status: 'instock',
                manage_stock: false,
                stock_quantity: null
            });
        });
        
        this.renderVariationsList();
    }
    
    getAllCombinations(arrays) {
        if (arrays.length === 0) return [[]];
        let result = [];
        let firstArray = arrays[0];
        let remainingArrays = arrays.slice(1);
        let remainingCombinations = this.getAllCombinations(remainingArrays);
        
        for (let i = 0; i < firstArray.length; i++) {
            for (let j = 0; j < remainingCombinations.length; j++) {
                result.push([firstArray[i]].concat(remainingCombinations[j]));
            }
        }
        return result;
    }
    
    addManualVariation() {
        const variationAttrs = this.currentAttributes.filter(attr => attr.variation && attr.options && attr.options.length > 0);
        if (variationAttrs.length === 0) {
            alert("Please define some attributes for variation first.");
            return;
        }
        
        this.currentVariations.push({
            id: null,
            attributes: variationAttrs.map(attr => ({
                id: attr.id,
                name: attr.name,
                option: attr.options[0] || ''
            })),
            regular_price: '',
            sale_price: '',
            sku: '',
            stock_status: 'instock',
            manage_stock: false,
            stock_quantity: null
        });
        
        this.renderVariationsList();
    }
    
    editVariation(index) {
        // For now, just toggle the variation content
        const variation = this.currentVariations[index];
        const variationId = variation.id || `new_${index}`;
        this.toggleVariation(variationId);
    }
    
    removeVariation(index) {
        if (confirm('Are you sure you want to remove this variation?')) {
            this.currentVariations.splice(index, 1);
            this.renderVariationsList();
        }
    }
    
    // Grouped Products Management
    renderGroupedProductsList() {
        const container = document.getElementById('selected_grouped_products_list');
        if (!container) return;
        
        container.innerHTML = '';
        
        this.currentGroupedProducts.forEach(productId => {
            const div = document.createElement('div');
            div.className = 'p-3 bg-gray-100 rounded-lg flex justify-between items-center';
            div.innerHTML = `
                <span class="text-sm">Product ID: ${productId}</span>
                <button type="button" class="btn btn-xs btn-danger" onclick="productEditor.removeGroupedProduct('${productId}')">Remove</button>
                <input type="hidden" name="grouped_products[]" value="${productId}">
            `;
            container.appendChild(div);
        });
    }
    
    removeGroupedProduct(productId) {
        this.currentGroupedProducts = this.currentGroupedProducts.filter(id => id !== productId);
        this.renderGroupedProductsList();
    }
    
    // Downloadable Files Management
    renderDownloadableFilesList() {
        const container = document.getElementById('downloadable_files_list');
        if (!container) return;
        
        container.innerHTML = '';
        
        this.currentDownloads.forEach((download, index) => {
            const div = document.createElement('div');
            div.className = 'p-3 border rounded-lg bg-gray-50';
            div.innerHTML = `
                <div class="grid grid-cols-2 gap-4">
                    <div class="form-group">
                        <label class="form-label text-sm">File Name</label>
                        <input type="text" class="form-input text-sm" value="${download.name || ''}" onchange="productEditor.updateDownloadField(${index}, 'name', this.value)">
                    </div>
                    <div class="form-group">
                        <label class="form-label text-sm">File URL</label>
                        <input type="url" class="form-input text-sm" value="${download.file || ''}" onchange="productEditor.updateDownloadField(${index}, 'file', this.value)">
                    </div>
                </div>
                <div class="flex justify-end mt-2">
                    <button type="button" class="btn btn-xs btn-danger" onclick="productEditor.removeDownloadableFile(${index})">Remove</button>
                </div>
            `;
            container.appendChild(div);
        });
    }
    
    addDownloadableFile() {
        this.currentDownloads.push({
            name: '',
            file: ''
        });
        this.renderDownloadableFilesList();
    }
    
    updateDownloadField(index, field, value) {
        if (this.currentDownloads[index]) {
            this.currentDownloads[index][field] = value;
        }
    }
    
    removeDownloadableFile(index) {
        this.currentDownloads.splice(index, 1);
        this.renderDownloadableFilesList();
    }
    
    // Modal Management
    showModal(modalId) {
        document.getElementById(modalId).classList.add('show');
    }
    
    closeModal(modalId) {
        document.getElementById(modalId).classList.remove('show');
    }
    
    // Term Creation
    showCreateTermModal(termType) {
        const modal = document.getElementById('create-term-modal');
        const form = document.getElementById('create-term-modal-form');
        form.reset();
        document.getElementById('term-type-to-create').value = termType;
        document.getElementById('create-term-modal-title').textContent = `Create New ${termType.charAt(0).toUpperCase() + termType.slice(1)}`;
        
        const parentGroup = document.getElementById('parent-term-group');
        parentGroup.classList.toggle('hidden', termType !== 'category');
        
        this.showModal('create-term-modal');
    }
    
    submitNewTerm() {
        const termType = document.getElementById('term-type-to-create').value;
        const name = document.getElementById('new-term-name').value.trim();
        const parentId = (termType === 'category') ? document.getElementById('parent-term-id').value : 0;
        
        if (!name) {
            alert('Name is required.');
            return;
        }
        
        // This would typically make an AJAX call to create the term
        // For demo purposes, we'll just add it locally
        if (termType === 'category') {
            const newId = Date.now();
            const newCategory = { id: newId, name: name, parent: parentId };
            this.allCategories.push(newCategory);
            
            // Add to category list
            const categoryList = document.getElementById('category-list');
            const div = document.createElement('div');
            div.className = 'form-checkbox text-sm';
            div.innerHTML = `
                <input type="checkbox" name="categories[]" value="${newId}" id="cat_${newId}">
                <label for="cat_${newId}">${name}</label>
            `;
            categoryList.appendChild(div);
        } else if (termType === 'tag') {
            const newId = Date.now();
            const newTag = { id: newId, name: name };
            this.allTags.push(newTag);
            this.currentSelectedTagIds.push(newId);
            this.renderSelectedTags();
        }
        
        this.closeModal('create-term-modal');
    }
    
    // Utility Methods
    filterTermList(listId, query) {
        const list = document.getElementById(listId);
        const items = list.querySelectorAll('.form-checkbox');
        query = query.toLowerCase();
        
        items.forEach(item => {
            const label = item.querySelector('label');
            if (label) {
                item.style.display = label.textContent.toLowerCase().includes(query) ? '' : 'none';
            }
        });
    }
    
    // Form Data Preparation
    prepareFormDataForSubmission(formElement) {
        // Images data
        this.addHiddenInput(formElement, 'images_data', JSON.stringify(
            this.currentImages.map((img, index) => ({
                id: img.id && !String(img.id).startsWith('temp_') ? img.id : null,
                src: !img.id || String(img.id).startsWith('temp_') ? img.src : null,
                name: img.name,
                alt: img.alt || '',
                position: index
            }))
        ));
        
        // Attributes data
        this.addHiddenInput(formElement, 'attributes_data', JSON.stringify(this.currentAttributes));
        
        // Variations data
        const variationsToSubmit = this.currentVariations.map(v => ({
            id: v.id && !String(v.id).startsWith('new_') ? v.id : null,
            regular_price: v.regular_price || '',
            sale_price: v.sale_price || '',
            sku: v.sku || '',
            manage_stock: v.manage_stock || false,
            stock_quantity: (v.manage_stock && v.stock_quantity !== undefined) ? v.stock_quantity : null,
            stock_status: v.stock_status || 'instock',
            weight: v.weight || '',
            length: v.length || '',
            width: v.width || '',
            height: v.height || '',
            image_id: v.image_id || null,
            attributes: (v.attributes || []).map(attr => ({
                id: attr.id || 0,
                name: attr.name || '',
                option: attr.option || ''
            }))
        }));
        this.addHiddenInput(formElement, 'variations_data', JSON.stringify(variationsToSubmit));
        
        // Tags data
        const tagsPayload = this.currentSelectedTagIds.map(id => {
            if (typeof id === 'string' && id.startsWith('new_')) {
                const tag = this.allTags.find(t => t.id === id);
                return tag ? tag.name : id;
            }
            return id;
        });
        this.addHiddenInput(formElement, 'tags_data', JSON.stringify(tagsPayload));
        
        // Downloadable files data
        this.addHiddenInput(formElement, 'downloads_data', JSON.stringify(this.currentDownloads));
    }
    
    addHiddenInput(form, name, value) {
        let input = form.querySelector(`input[name="${name}"]`);
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            form.appendChild(input);
        }
        input.value = value;
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Setup tab navigation
    setupTabNavigation();
    
    // Setup image drag & drop
    setupImageDragDrop();
    
    // Initialize stock fields state
    toggleStockFields();
    
    // Initialize product editor if API is connected
    if (<?php echo $connected ? 'true' : 'false'; ?>) {
        productEditor = new ProductEditor(
            <?php echo json_encode($product); ?>,
            <?php echo json_encode($all_categories); ?>,
            <?php echo json_encode($all_tags); ?>,
            <?php echo json_encode($all_attributes); ?>,
            <?php echo json_encode($all_shipping_classes); ?>
        );
    } else {
        console.warn("Product editor not initialized: API not connected.");
    }
});

// Form submission handler
document.getElementById('product-form').addEventListener('submit', function(event) {
    // Show loading state
    const submitBtn = event.submitter || document.querySelector('.btn-primary');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<div class="spinner"></div> Saving...';
    submitBtn.disabled = true;
    
    if (typeof productEditor !== 'undefined') {
        productEditor.prepareFormDataForSubmission(this);
    }
    
    // Re-enable button after a delay (form will redirect anyway)
    setTimeout(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }, 5000);
});

// Close modals when clicking outside
document.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal') && event.target.classList.contains('show')) {
        event.target.classList.remove('show');
    }
});

// Helper functions
function selected($value, $option_value) {
    return $value == $option_value ? 'selected' : '';
}

function checked($value) {
    return $value ? 'checked' : '';
}
</script>
