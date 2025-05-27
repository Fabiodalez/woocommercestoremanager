<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/config.php'; // Assuming config.php is in the same directory
require_once __DIR__ . '/WooCommerceAPI.php'; // Assuming WooCommerceAPI.php is in the same directory

// --- WooCommerceBrandsAPI Definition ---
// Extend the base WooCommerceAPI class
class WooCommerceBrandsAPI extends WooCommerceAPI {
    // Method to get brands (assuming a 'products/brands' endpoint if provided by a plugin)
    // Or, this could be adapted to list terms of a known 'brand' taxonomy
    public function getProductBrands($params = []) {
        // Try a common endpoint, or this might need to be an actual taxonomy term listing
        // e.g., products/attributes/X/terms if brands are an attribute, or a custom endpoint
        // For now, we'll assume a hypothetical 'products/brands' or similar if a plugin provides it.
        // If this is a custom taxonomy, the API might not have a direct "list all brands" endpoint.
        // You might need to fetch all terms of a specific taxonomy (e.g., 'product_brand').
        // Let's try to use a general product attributes endpoint if `products/brands` is not standard.
        // This is a placeholder; actual implementation depends on how "brands" are set up.
        
        // A more robust way if brands are a product attribute taxonomy:
        // 1. Find the attribute ID for "Brand"
        // $attributes = $this->getProductAttributes(['search' => 'Brand']);
        // if ($attributes['success'] && !empty($attributes['data'])) {
        // $brand_attribute_id = $attributes['data'][0]['id'];
        // return $this->getProductAttributeTerms($brand_attribute_id, $params);
        // }
        // return ['success' => false, 'error' => 'Brand attribute not found or could not be fetched.', 'data' => []];

        // Simpler approach: if a plugin exposes a 'products/brands' endpoint:
         return $this->makeRequest('products/brands', $params); // This is highly plugin-dependent
    }

    // The getProductsByBrand is not strictly necessary as filtering can be done via parameters to getProducts
    // e.g. $api->getProducts(['brand_taxonomy_slug' => $brand_id_or_slug]);
}
// --- End WooCommerceBrandsAPI Definition ---


$user_id = Config::getCurrentUserId(); // Get current user ID
$store_config = Config::getCurrentStore(); // Get current store configuration for the user
$store_config_id = $store_config['id'] ?? null;

$api = null;
$connected = false;
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);


if ($store_config_id && $user_id) {
    try {
        $api = new WooCommerceBrandsAPI($user_id, $store_config_id);
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


// Parametri per la ricerca, filtri e ordinamento
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : ''; // Default to empty, API handles 'any'
$stock_filter = isset($_GET['stock']) ? sanitize_text_field($_GET['stock']) : '';  // Default to empty
$category_filter = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : ''; // Default to empty
$brand_filter_id = isset($_GET['brand']) ? sanitize_text_field($_GET['brand']) : ''; // Default to empty, assuming brand ID
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;
if (!in_array($per_page, [20, 50, 100])) $per_page = 20;


// Parametri di ordinamento
$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'date';
$order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'desc';

// Validazione parametri ordinamento
$valid_orderby = ['date', 'modified', 'id', 'include', 'title', 'slug', 'price', 'popularity', 'rating', 'menu_order']; // Added 'sku' if supported by your API version for orderby
$valid_order = ['asc', 'desc'];

if (!in_array($orderby, $valid_orderby)) {
    $orderby = 'date';
}
if (!in_array($order, $valid_order)) {
    $order = 'desc';
}

// Dati prodotti
$products = [];
$total_products = 0;
$total_pages = 1;
$categories_list = []; // Renamed to avoid conflict
$brands_list = []; // Renamed to avoid conflict
$brand_taxonomy_slug = Config::getSetting('brand_taxonomy_slug', 'product_brand'); // Example: 'product_brand' or 'pwb-brand'

// Funzioni helper (assuming these are defined or moved to a helper file)
function sanitize_text_field($text) {
    return htmlspecialchars(strip_tags(trim($text)), ENT_QUOTES, 'UTF-8');
}

function formatPrice($price) {
    // Consider currency symbol from WooCommerce settings if available
    return '$' . number_format((float)$price, 2);
}

function getProductImage($product) {
    if (!empty($product['images']) && isset($product['images'][0]['src'])) {
        return $product['images'][0]['src'];
    }
    return 'https://via.placeholder.com/80x80?text=No+Image'; // Placeholder image
}

function getStatusClass($status) {
    $map = [
        'publish' => 'bg-green-100 text-green-700',
        'draft' => 'bg-yellow-100 text-yellow-700',
        'pending' => 'bg-orange-100 text-orange-700',
        'private' => 'bg-purple-100 text-purple-700',
    ];
    return $map[$status] ?? 'bg-gray-100 text-gray-700';
}

function getStatusLabel($status) {
    return ucfirst($status);
}

function getStockClass($stock_status) {
     $map = [
        'instock' => 'text-green-600',
        'outofstock' => 'text-red-600',
        'onbackorder' => 'text-orange-500',
    ];
    return $map[$stock_status] ?? 'text-gray-600';
}

function getStockLabel($stock_status, $stock_quantity = null, $manage_stock = false) {
    if ($manage_stock === false && $stock_status === 'instock') return 'In stock'; // For non-managed stock
    if ($manage_stock === false && $stock_status === 'outofstock') return 'Out of stock';
    
    switch ($stock_status) {
        case 'instock':
            return $stock_quantity !== null ? "In stock ({$stock_quantity})" : 'In stock';
        case 'outofstock':
            return 'Out of stock';
        case 'onbackorder':
            return 'On backorder';
        default:
            return ucfirst($stock_status);
    }
}


function getBrandName($product, $brand_taxonomy_slug_from_config) {
    // WooCommerce Product Brands (by WooCommerce) uses 'product_brand' taxonomy.
    // Perfect Brands for WooCommerce (by QuadLayers) uses 'pwb-brand'.
    // Check 'terms' or 'meta_data' if brands are custom taxonomies not directly in a 'brands' key.
    // This is a generic attempt; might need adjustment for your specific brand plugin.
    if (!empty($product['meta_data'])) {
        foreach ($product['meta_data'] as $meta) {
            // Some plugins store brand term IDs or names in meta.
            // This is highly specific to the plugin.
        }
    }
    // A more reliable way if the API returns terms for registered taxonomies:
    if (!empty($product['categories'])) { // Assuming brands might be mixed with categories if not handled separately
        foreach($product['categories'] as $term) {
            // This logic needs to be more specific if brands are a separate taxonomy returned by the API
        }
    }
    if (!empty($product[$brand_taxonomy_slug_from_config])) { // If API returns brands under their taxonomy slug key
         $brand_names = array_map(function($brand) { return $brand['name']; }, $product[$brand_taxonomy_slug_from_config]);
         return implode(', ', $brand_names);
    }
    if (!empty($product['brands'])) { // Fallback to a direct 'brands' key if present
        $brand_names = array_map(function($brand) {
            return $brand['name'];
        }, $product['brands']);
        return implode(', ', $brand_names);
    }
    return 'N/A';
}

function getSortLink($column, $current_orderby = 'date', $current_order = 'desc') {
    $params = $_GET;
    $new_order = ($current_orderby === $column && $current_order === 'asc') ? 'desc' : 'asc';
    $params['orderby'] = $column;
    $params['order'] = $new_order;
    return '?' . http_build_query($params);
}

function getSortIcon($column, $current_orderby = 'date', $current_order = 'desc') {
    if ($current_orderby !== $column) {
        return '<svg class="inline-block w-4 h-4 ml-1 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path></svg>';
    }
    if ($current_order === 'asc') {
        return '<svg class="inline-block w-4 h-4 ml-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"></path></svg>';
    }
    return '<svg class="inline-block w-4 h-4 ml-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h9m5-4v12m0 0l-4-4m4 4l4-4"></path></svg>';
}


if ($connected && $api) {
    try {
        $api_params = [
            'per_page' => $per_page,
            'page' => $page,
            'orderby' => $orderby,
            'order' => $order,
            'context' => 'view' // Important for getting all fields
        ];
        
        if (!empty($search)) $api_params['search'] = $search;
        if (!empty($status_filter)) $api_params['status'] = $status_filter;
        if (!empty($stock_filter)) $api_params['stock_status'] = $stock_filter;
        if (!empty($category_filter)) $api_params['category'] = $category_filter; // Assumes category ID
        
        // Brand filter: This depends on how your "brands" are implemented (taxonomy slug)
        if (!empty($brand_filter_id) && !empty($brand_taxonomy_slug)) {
            $api_params[$brand_taxonomy_slug] = $brand_filter_id; // e.g., 'product_brand' => 'brand_id_or_slug'
        }
        
        $products_response = $api->getProducts($api_params);
        
        if ($products_response['success']) {
            $products = $products_response['data'];
            $total_products_from_header = $api->getTotalCountFromHeaders();
            $total_pages_from_header = $api->getTotalPagesFromHeaders();

            if ($total_products_from_header !== null) {
                $total_products = $total_products_from_header;
                $total_pages = ($total_pages_from_header !== null) ? $total_pages_from_header : ceil($total_products / $per_page);
            } else { // Fallback if headers are not present/reliable
                $total_products = count($products); // This is only for the current page
                // To get actual total, another call or different logic might be needed if headers fail
                $total_pages = 1; // Default if total cannot be determined
                // A more accurate fallback would be to make a count-only request if possible or assume this is the only page
                if ($total_products < $per_page && $page === 1) {
                     $total_pages = 1;
                } else {
                    // We can't accurately determine total_pages without the header or a separate count query
                    // For now, if headers are missing, pagination might be inaccurate for subsequent pages
                    // A common pattern is to fetch one extra item to see if there's a next page.
                }
            }

        } else {
            $error_message = 'Error loading products: ' . ($products_response['error'] ?? 'Unknown API error.');
        }
        
        $categories_response = $api->getProductCategories(['per_page' => 100, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'asc']);
        if ($categories_response['success']) {
            $categories_list = $categories_response['data'];
        } else {
             $error_message .= ' Could not load categories: ' . ($categories_response['error'] ?? 'Unknown error.');
        }
        
        // Attempt to load brands using the custom method (if it works for your setup)
        // This part is speculative as 'products/brands' is not a standard WC endpoint
        if (method_exists($api, 'getProductBrands')) {
            $brands_response = $api->getProductBrands(['per_page' => 100, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'asc']);
            if ($brands_response['success'] && !empty($brands_response['data'])) {
                $brands_list = $brands_response['data'];
            } elseif (!$brands_response['success']) {
                 // Don't show a fatal error, just note that brands might not be configured
                 // $error_message .= ' Brands feature may not be enabled or endpoint `products/brands` not found. Error: ' . ($brands_response['error'] ?? '');
            }
        } else {
            // If getProductBrands doesn't exist or you want to fetch terms of a known taxonomy:
            // Example: if 'product_brand' is the taxonomy for brands
            // $brand_terms_response = $api->getProductAttributeTerms($brand_attribute_id_if_brand_is_attribute, ['per_page' => 100]);
            // Or if a plugin exposes terms directly: $api->getTerms('product_brand', [...]);
        }
        
    } catch (Exception $e) {
        $error_message = 'Error processing request: ' . $e->getMessage();
        $products = []; // Ensure products is an empty array on error
    }
}

// Gestisci azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $connected && $api) {
    $action = $_POST['action'] ?? null;
    $selected_ids = isset($_POST['selected_products']) && is_array($_POST['selected_products']) 
                    ? array_map('intval', $_POST['selected_products']) 
                    : [];

    if (!empty($selected_ids) || $action === 'duplicate_product_single') { // Allow duplicate even if no selected_ids for single action
        try {
            if ($action === 'bulk_delete') {
                $results = ['success' => 0, 'failed' => 0];
                $batch_data_delete = [];
                foreach ($selected_ids as $product_id) {
                    $batch_data_delete[] = $product_id; // For batch, just send IDs
                }
                if (!empty($batch_data_delete)) {
                    $response = $api->batchProducts(['delete' => $batch_data_delete]);
                    if ($response['success']) {
                        $deleted_items = $response['data']['delete'] ?? [];
                        $results['success'] = count(array_filter($deleted_items, fn($item) => !isset($item['error'])));
                        $results['failed'] = count(array_filter($deleted_items, fn($item) => isset($item['error'])));
                        $_SESSION['success_message'] = "Successfully deleted {$results['success']} products. Failed: {$results['failed']}.";
                    } else {
                        $_SESSION['error_message'] = "Bulk delete failed: " . ($response['error'] ?? 'Unknown error');
                    }
                }
            } elseif ($action === 'bulk_status_change') {
                $new_status = sanitize_text_field($_POST['new_status'] ?? 'draft');
                if (in_array($new_status, ['publish', 'draft', 'pending', 'private'])) {
                    $batch_data_update = [];
                    foreach ($selected_ids as $product_id) {
                        $batch_data_update[] = ['id' => $product_id, 'status' => $new_status];
                    }
                    if(!empty($batch_data_update)) {
                        $response = $api->batchProducts(['update' => $batch_data_update]);
                         if ($response['success']) {
                            $updated_items = $response['data']['update'] ?? [];
                            $s_count = count(array_filter($updated_items, fn($item) => !isset($item['error'])));
                            $f_count = count(array_filter($updated_items, fn($item) => isset($item['error'])));
                            $_SESSION['success_message'] = "Successfully updated status for {$s_count} products. Failed: {$f_count}.";
                        } else {
                            $_SESSION['error_message'] = "Bulk status update failed: " . ($response['error'] ?? 'Unknown error');
                        }
                    }
                } else {
                    $_SESSION['error_message'] = "Invalid status provided for bulk update.";
                }
            } elseif ($action === 'duplicate_product_single' && isset($_POST['product_id'])) {
                $product_to_duplicate = intval($_POST['product_id']);
                $response = $api->duplicateProduct($product_to_duplicate);
                if ($response['success']) {
                    $_SESSION['success_message'] = "Product '{$response['data']['name']}' (ID: {$response['data']['id']}) duplicated successfully as draft.";
                } else {
                    $_SESSION['error_message'] = "Failed to duplicate product: " . ($response['error'] ?? 'Unknown error');
                }
            } elseif ($action === 'delete_product_single' && isset($_POST['product_id'])) {
                 $product_to_delete = intval($_POST['product_id']);
                 $response = $api->deleteProduct($product_to_delete, true); // Force delete
                 if ($response['success']) {
                    $_SESSION['success_message'] = "Product '{$response['data']['name']}' (ID: {$response['data']['id']}) deleted successfully.";
                } else {
                    $_SESSION['error_message'] = "Failed to delete product: " . ($response['error'] ?? 'Unknown error');
                }
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = 'An exception occurred: ' . $e->getMessage();
        }
        // Redirect to clear POST data and show messages
        header("Location: products.php?" . http_build_query($_GET));
        exit;
    } elseif (empty($selected_ids) && strpos($action, 'bulk_') === 0) {
         $_SESSION['error_message'] = 'No products selected for bulk action.';
         header("Location: products.php?" . http_build_query($_GET));
         exit;
    }
}


include 'header.php';
?>

            <div class="px-4 sm:px-6 lg:px-8 flex flex-1 justify-center py-5">
                <div class="layout-content-container flex flex-col w-full max-w-7xl flex-1">
                    
                    <?php if (!empty($error_message)): ?>
                        <div role="alert" class="mb-4 rounded border-s-4 border-red-500 bg-red-50 p-4">
                            <strong class="block font-medium text-red-800"> Error: </strong>
                            <p class="mt-2 text-sm text-red-700"><?php echo htmlspecialchars($error_message); ?>
                            <?php if (!$connected && !$api): ?>
                                <a href="settings.php" class="underline ml-2 font-semibold">Configure API Settings</a>
                            <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($success_message)): ?>
                         <div role="alert" class="mb-4 rounded border-s-4 border-green-500 bg-green-50 p-4">
                            <strong class="block font-medium text-green-800"> Success: </strong>
                            <p class="mt-2 text-sm text-green-700"><?php echo htmlspecialchars($success_message); ?></p>
                        </div>
                    <?php endif; ?>

                    <div class="flex flex-wrap justify-between items-center gap-3 p-4">
                        <div class="flex items-center gap-4">
                            <h1 class="text-[#101418] tracking-tight text-3xl font-bold leading-tight">Products</h1>
                            <?php if ($connected): ?>
                                <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm font-medium">
                                    <?php echo number_format($total_products); ?> total
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($connected): ?>
                        <a href="product-edit.php" 
                                class="flex min-w-[84px] cursor-pointer items-center justify-center overflow-hidden rounded-full h-10 px-4 bg-blue-600 text-white text-sm font-medium leading-normal hover:bg-blue-700 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="mr-2" viewBox="0 0 16 16">
                                <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/>
                            </svg>
                            Add New Product
                        </a>
                        <?php endif; ?>
                    </div>

                    <?php if ($connected): ?>
                    <div class="px-4 py-3">
                        <form method="GET" class="flex w-full">
                            <div class="relative w-full">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 256 256">
                                        <path d="M229.66,218.34l-50.07-50.06a88.11,88.11,0,1,0-11.31,11.31l50.06,50.07a8,8,0,0,0,11.32-11.32ZM40,112a72,72,0,1,1,72,72A72.08,72.08,0,0,1,40,112Z"></path>
                                    </svg>
                                </div>
                                <input name="search" placeholder="Search products by name, SKU..." 
                                    class="form-input block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                    value="<?php echo htmlspecialchars($search); ?>" />
                            </div>
                            <button type="submit" class="ml-3 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Search
                            </button>
                             <!-- Preserve other filters -->
                            <?php foreach (['status', 'stock', 'category', 'brand', 'orderby', 'order', 'per_page'] as $hidden_field): ?>
                                <?php $val = ${$hidden_field . '_filter'} ?? $_GET[$hidden_field] ?? null; if($hidden_field === 'per_page') $val = $per_page; ?>
                                <?php if (!empty($val) && $val !== ($hidden_field === 'orderby' ? 'date' : ($hidden_field === 'order' ? 'desc' : ($hidden_field === 'per_page' ? 20 : '')))): ?>
                                    <input type="hidden" name="<?php echo $hidden_field; ?>" value="<?php echo htmlspecialchars($val); ?>">
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </form>
                    </div>

                    <div class="flex flex-wrap gap-3 p-3 items-center">
                        <form id="filterForm" method="GET" class="flex flex-wrap gap-3 items-center">
                             <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                             <input type="hidden" name="orderby" value="<?php echo htmlspecialchars($orderby); ?>">
                             <input type="hidden" name="order" value="<?php echo htmlspecialchars($order); ?>">
                             <input type="hidden" name="per_page" value="<?php echo htmlspecialchars($per_page); ?>">

                            <div class="relative">
                                <select name="status" onchange="document.getElementById('filterForm').submit()" 
                                        class="form-select block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                                    <option value="">All Statuses</option>
                                    <option value="publish" <?php echo $status_filter === 'publish' ? 'selected' : ''; ?>>Published</option>
                                    <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="private" <?php echo $status_filter === 'private' ? 'selected' : ''; ?>>Private</option>
                                </select>
                            </div>
                            <div class="relative">
                                <select name="stock" onchange="document.getElementById('filterForm').submit()"
                                        class="form-select block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                                    <option value="">All Stock</option>
                                    <option value="instock" <?php echo $stock_filter === 'instock' ? 'selected' : ''; ?>>In stock</option>
                                    <option value="outofstock" <?php echo $stock_filter === 'outofstock' ? 'selected' : ''; ?>>Out of stock</option>
                                    <option value="onbackorder" <?php echo $stock_filter === 'onbackorder' ? 'selected' : ''; ?>>On backorder</option>
                                </select>
                            </div>
                            <?php if(!empty($categories_list)): ?>
                            <div class="relative">
                                <select name="category" onchange="document.getElementById('filterForm').submit()"
                                        class="form-select block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories_list as $category_item): ?>
                                        <option value="<?php echo $category_item['id']; ?>" <?php echo $category_filter == $category_item['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category_item['name']); ?> (<?php echo $category_item['count'] ?? 0; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($brands_list)): // Only show if brands were loaded ?>
                            <div class="relative">
                                <select name="brand" onchange="document.getElementById('filterForm').submit()"
                                        class="form-select block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                                    <option value="">All Brands</option>
                                    <?php foreach ($brands_list as $brand_item): ?>
                                        <option value="<?php echo $brand_item['id']; ?>" <?php echo $brand_filter_id == $brand_item['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($brand_item['name']); ?> (<?php echo $brand_item['count'] ?? 0; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                             <div class="relative">
                                <select name="per_page" onchange="document.getElementById('filterForm').submit()"
                                        class="form-select block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                                    <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20 per page</option>
                                    <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50 per page</option>
                                    <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100 per page</option>
                                </select>
                            </div>
                            <?php if ($search || $status_filter || $stock_filter || $category_filter || $brand_filter_id): ?>
                                <a href="products.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Clear Filters
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                    
                    <?php if (!empty($products)): ?>
                    <div class="px-4 py-2 border-t border-b border-gray-200 bg-gray-50">
                        <form id="bulk-form" method="POST" class="flex flex-wrap gap-3 items-center">
                            <input type="hidden" name="action" id="bulk-action-input" value="">
                            <input type="hidden" name="new_status" id="bulk-new-status-input" value="">
                            
                            <label for="bulk-actions-select" class="sr-only">Bulk Actions</label>
                            <select id="bulk-actions-select" class="form-select py-2 pl-3 pr-10 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                                <option value="">Bulk Actions</option>
                                <option value="bulk_delete">Delete Selected</option>
                                <option value="bulk_status_publish">Set status to: Published</option>
                                <option value="bulk_status_draft">Set status to: Draft</option>
                                <option value="bulk_status_pending">Set status to: Pending</option>
                                <option value="bulk_status_private">Set status to: Private</option>
                            </select>
                            
                            <button type="button" onclick="executeBulkAction()" 
                                    class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                Apply
                            </button>
                            
                            <span id="selected-count" class="text-sm text-gray-500 ml-auto">0 selected</span>
                        </form>
                    </div>
                    <?php endif; ?>

                    <div class="px-4 py-3">
                        <div class="overflow-x-auto shadow border-b border-gray-200 sm:rounded-lg">
                            <?php if (!empty($products)): ?>
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="p-4 text-left">
                                                <input type="checkbox" id="select-all" onchange="toggleAllProducts()" class="h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-16">Image</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                <a href="<?php echo getSortLink('title', $orderby, $order); ?>" class="hover:text-gray-900 flex items-center">
                                                    Name <?php echo getSortIcon('title', $orderby, $order); ?>
                                                </a>
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                <a href="<?php echo getSortLink('sku', $orderby, $order); ?>" class="hover:text-gray-900 flex items-center">
                                                    SKU <?php echo getSortIcon('sku', $orderby, $order); ?>
                                                </a>
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                <a href="<?php echo getSortLink('price', $orderby, $order); ?>" class="hover:text-gray-900 flex items-center">
                                                    Price <?php echo getSortIcon('price', $orderby, $order); ?>
                                                </a>
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stock</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Categories</th>
                                            <?php if (!empty($brands_list)): ?>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Brands</th>
                                            <?php endif; ?>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                <a href="<?php echo getSortLink('date', $orderby, $order); ?>" class="hover:text-gray-900 flex items-center">
                                                    Date <?php echo getSortIcon('date', $orderby, $order); ?>
                                                </a>
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($products as $product): ?>
                                        <tr class="product-row hover:bg-gray-50">
                                            <td class="p-4">
                                                <input type="checkbox" name="selected_products[]" value="<?php echo $product['id']; ?>" 
                                                       class="product-checkbox h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500" onchange="updateSelectedCount()">
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <img src="<?php echo htmlspecialchars(getProductImage($product)); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="w-10 h-10 rounded object-cover">
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm font-medium text-gray-900 hover:text-indigo-600">
                                                    <a href="product-edit.php?id=<?php echo $product['id']; ?>">
                                                        <?php echo htmlspecialchars($product['name']); ?>
                                                    </a>
                                                </div>
                                                <?php if (!empty($product['short_description'])): ?>
                                                    <div class="text-xs text-gray-500 truncate w-64" title="<?php echo htmlspecialchars(strip_tags($product['short_description'])); ?>">
                                                        <?php echo htmlspecialchars(mb_substr(strip_tags($product['short_description']), 0, 60)) . (mb_strlen(strip_tags($product['short_description'])) > 60 ? '...' : ''); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($product['sku'] ?: 'N/A'); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 capitalize"><?php echo htmlspecialchars($product['type']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo getStatusClass($product['status']); ?>">
                                                    <?php echo getStatusLabel($product['status']); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php if ($product['price'] !== ''): echo formatPrice($product['price']); else: echo 'N/A'; endif; ?>
                                                <?php if ($product['on_sale'] && $product['regular_price'] !== $product['price']): ?>
                                                    <span class="block text-xs line-through text-gray-400"><?php echo formatPrice($product['regular_price']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm <?php echo getStockClass($product['stock_status']); ?>">
                                                <?php echo getStockLabel($product['stock_status'], $product['stock_quantity'] ?? null, $product['manage_stock'] ?? false); ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500">
                                                <?php
                                                if (!empty($product['categories'])) {
                                                    echo htmlspecialchars(implode(', ', array_map(fn($c) => $c['name'], array_slice($product['categories'], 0, 2)))) . (count($product['categories']) > 2 ? '...' : '');
                                                } else { echo 'N/A'; }
                                                ?>
                                            </td>
                                            <?php if (!empty($brands_list)): /* Only show column if brands are available */ ?>
                                            <td class="px-6 py-4 text-sm text-gray-500">
                                                <?php echo htmlspecialchars(getBrandName($product, $brand_taxonomy_slug)); ?>
                                            </td>
                                            <?php endif; ?>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('M j, Y', strtotime($product['date_created'])); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <a href="product-edit.php?id=<?php echo $product['id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                                <button onclick="handleDuplicateProduct(<?php echo $product['id']; ?>)" class="text-green-600 hover:text-green-900 mr-3">Copy</button>
                                                <button onclick="handleDeleteProduct(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars(addslashes($product['name'])); ?>')" class="text-red-600 hover:text-red-900">Delete</button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php elseif ($connected): ?>
                                <div class="p-8 text-center text-gray-500">
                                    <svg class="mx-auto h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2M4 13h2m13-4V6a2 2 0 00-2-2H6a2 2 0 00-2 2v3m13 0h-2M4 9h2"></path></svg>
                                    <h3 class="mt-2 text-sm font-medium text-gray-900">No products found</h3>
                                    <p class="mt-1 text-sm text-gray-500">
                                        <?php if ($search || $status_filter || $stock_filter || $category_filter || $brand_filter_id): ?>
                                            Try adjusting your search or filter criteria. <a href="products.php" class="text-indigo-600 hover:text-indigo-500 font-medium">Clear filters</a>
                                        <?php else: ?>
                                            Get started by creating a new product.
                                        <?php endif; ?>
                                    </p>
                                    <?php if (!($search || $status_filter || $stock_filter || $category_filter || $brand_filter_id)): ?>
                                    <div class="mt-6">
                                        <a href="product-edit.php" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                            Add New Product
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: // Not connected ?>
                                <div class="p-8 text-center text-gray-500">
                                     <svg class="mx-auto h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                                    <h3 class="mt-2 text-sm font-medium text-gray-900">Connection Required</h3>
                                    <p class="mt-1 text-sm text-gray-500">Please select a store and configure API access in settings.</p>
                                    <div class="mt-6">
                                        <a href="settings.php" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                            Go to Settings
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($connected && !empty($products) && $total_pages > 1): ?>
                    <nav class="mt-6 px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6" aria-label="Pagination">
                        <div class="hidden sm:block">
                            <p class="text-sm text-gray-700">
                                Showing
                                <span class="font-medium"><?php echo (($page - 1) * $per_page) + 1; ?></span>
                                to
                                <span class="font-medium"><?php echo min($page * $per_page, $total_products); ?></span>
                                of
                                <span class="font-medium"><?php echo number_format($total_products); ?></span>
                                results
                            </p>
                        </div>
                        <div class="flex-1 flex justify-between sm:justify-end">
                            <?php
                            $base_query = $_GET;
                            unset($base_query['page']);
                            $base_url = 'products.php?' . http_build_query($base_query);
                            ?>
                            <?php if ($page > 1): ?>
                                <a href="<?php echo $base_url . '&page=' . ($page - 1); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Previous
                                </a>
                            <?php endif; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="<?php echo $base_url . '&page=' . ($page + 1); ?>" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Next
                                </a>
                            <?php endif; ?>
                        </div>
                    </nav>
                    <?php endif; ?>
                    <?php endif; // End if connected ?>

                </div>
            </div>
        </div> <!-- Closes main div from header.php -->
    </div> <!-- Closes flex container from header.php -->

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('select-all');
            const productCheckboxes = document.querySelectorAll('.product-checkbox');
            const selectedCountSpan = document.getElementById('selected-count');

            function updateSelectedCount() {
                const checkedCount = document.querySelectorAll('.product-checkbox:checked').length;
                if (selectedCountSpan) selectedCountSpan.textContent = checkedCount + ' selected';

                productCheckboxes.forEach(checkbox => {
                    const row = checkbox.closest('.product-row');
                    if (row) {
                        if (checkbox.checked) row.classList.add('bg-indigo-50');
                        else row.classList.remove('bg-indigo-50');
                    }
                });

                 if(selectAllCheckbox){
                    if (checkedCount === 0) {
                        selectAllCheckbox.indeterminate = false;
                        selectAllCheckbox.checked = false;
                    } else if (checkedCount === productCheckboxes.length && productCheckboxes.length > 0) {
                        selectAllCheckbox.indeterminate = false;
                        selectAllCheckbox.checked = true;
                    } else if (productCheckboxes.length > 0) {
                        selectAllCheckbox.indeterminate = true;
                    }
                }
            }

            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    productCheckboxes.forEach(checkbox => {
                        checkbox.checked = selectAllCheckbox.checked;
                    });
                    updateSelectedCount();
                });
            }

            productCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateSelectedCount);
            });

            updateSelectedCount(); // Initial count
        });

        function executeBulkAction() {
            const bulkSelect = document.getElementById('bulk-actions-select');
            const action = bulkSelect.value;
            const selectedCheckboxes = document.querySelectorAll('.product-checkbox:checked');
            
            if (!action) {
                alert('Please select a bulk action.');
                return;
            }
            if (selectedCheckboxes.length === 0) {
                alert('Please select at least one product.');
                return;
            }

            let confirmMessage = '';
            let formActionValue = '';
            let newStatusValue = '';

            if (action.startsWith('bulk_status_')) {
                formActionValue = 'bulk_status_change';
                newStatusValue = action.replace('bulk_status_', '');
                confirmMessage = `Are you sure you want to change status to "${newStatusValue}" for ${selectedCheckboxes.length} products?`;
            } else if (action === 'bulk_delete') {
                formActionValue = 'bulk_delete';
                confirmMessage = `Are you sure you want to delete ${selectedCheckboxes.length} selected products? This action cannot be undone.`;
            } else {
                alert('Invalid action.');
                return;
            }

            if (confirm(confirmMessage)) {
                const bulkForm = document.getElementById('bulk-form');
                document.getElementById('bulk-action-input').value = formActionValue;
                if (newStatusValue) {
                    document.getElementById('bulk-new-status-input').value = newStatusValue;
                }
                // Selected products are already part of the form if checkboxes have name="selected_products[]"
                // No need to add them dynamically here if the table is inside the form,
                // or ensure the main form #bulk-form wraps the table or checkboxes are collected on submit.
                // For safety, let's ensure the values are added if the table is not inside this specific form.
                
                // Clear previous hidden inputs if any
                const existingProductInputs = bulkForm.querySelectorAll('input[name="selected_products[]"]');
                existingProductInputs.forEach(input => input.remove());

                selectedCheckboxes.forEach(cb => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'selected_products[]';
                    input.value = cb.value;
                    bulkForm.appendChild(input);
                });
                bulkForm.submit();
            }
        }

        function createAndSubmitForm(action, productId, productName) {
            let confirmMsg = '';
            if (action === 'delete_product_single') {
                confirmMsg = `Are you sure you want to delete product "${productName}" (ID: ${productId})? This cannot be undone.`;
            } else if (action === 'duplicate_product_single') {
                confirmMsg = `Are you sure you want to duplicate product "${productName}" (ID: ${productId})? A new draft product will be created.`;
            }

            if (confirmMsg && !confirm(confirmMsg)) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            // Append current query string to action URL to maintain filters after redirect
            const currentQuery = window.location.search;
            form.action = 'products.php' + currentQuery; 
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = action;
            form.appendChild(actionInput);

            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'product_id';
            idInput.value = productId;
            form.appendChild(idInput);

            document.body.appendChild(form);
            form.submit();
        }

        function handleDeleteProduct(productId, productName) {
            createAndSubmitForm('delete_product_single', productId, productName);
        }

        function handleDuplicateProduct(productId) {
            // For duplication, product name isn't strictly needed for confirm but can be nice
            const productName = document.querySelector(`.product-row input[value="${productId}"]`)
                               ?.closest('.product-row').querySelector('td:nth-child(3) a')?.textContent.trim() || `Product ID ${productId}`;
            createAndSubmitForm('duplicate_product_single', productId, productName);
        }

    </script>

<?php include 'footer.php'; ?>