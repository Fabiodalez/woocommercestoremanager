<?php
// product-add.php (e product-edit.php)
session_start();
require_once 'config.php';
require_once 'WooCommerceAPI.php';

// Estendi API per funzionalità avanzate
class WooCommerceAdvancedAPI extends WooCommerceAPI {
    public function getAttributes($params = []) {
        return $this->makeRequest('products/attributes', $params);
    }
    
    public function createAttribute($data) {
        return $this->makeRequest('products/attributes', $data, 'POST');
    }
    
    public function getAttributeTerms($attribute_id, $params = []) {
        return $this->makeRequest("products/attributes/{$attribute_id}/terms", $params);
    }
    
    public function createAttributeTerm($attribute_id, $data) {
        return $this->makeRequest("products/attributes/{$attribute_id}/terms", $data, 'POST');
    }
    
    public function getShippingClasses($params = []) {
        return $this->makeRequest('products/shipping_classes', $params);
    }
    
    public function createShippingClass($data) {
        return $this->makeRequest('products/shipping_classes', $data, 'POST');
    }
    
    public function getTags($params = []) {
        return $this->makeRequest('products/tags', $params);
    }
    
    public function createTag($data) {
        return $this->makeRequest('products/tags', $data, 'POST');
    }
    
    public function uploadMedia($file_data) {
        // Implementa upload media
        return $this->makeRequest('media', $file_data, 'POST');
    }
    
    public function createProduct($data) {
        return $this->makeRequest('products', $data, 'POST');
    }
    
    public function updateProduct($id, $data) {
        return $this->makeRequest("products/{$id}", $data, 'PUT');
    }
    
    public function getProduct($id) {
        return $this->makeRequest("products/{$id}");
    }
}

$api = new WooCommerceAdvancedAPI();
$connected = Config::get('connected', false);
$error_message = '';
$success_message = '';

// Determina se stiamo modificando o creando
$product_id = isset($_GET['id']) ? intval($_GET['id']) : null;
$is_edit = $product_id !== null;
$page_title = $is_edit ? 'Edit Product' : 'Add New Product';

// Dati del prodotto
$product = [
    'id' => null,
    'name' => '',
    'slug' => '',
    'type' => 'simple',
    'status' => 'draft',
    'featured' => false,
    'description' => '',
    'short_description' => '',
    'sku' => '',
    'price' => '',
    'regular_price' => '',
    'sale_price' => '',
    'date_on_sale_from' => '',
    'date_on_sale_to' => '',
    'virtual' => false,
    'downloadable' => false,
    'manage_stock' => false,
    'stock_quantity' => '',
    'stock_status' => 'instock',
    'backorders' => 'no',
    'sold_individually' => false,
    'weight' => '',
    'dimensions' => ['length' => '', 'width' => '', 'height' => ''],
    'shipping_class_id' => '',
    'shipping_required' => true,
    'categories' => [],
    'tags' => [],
    'images' => [],
    'attributes' => [],
    'variations' => [],
    'meta_data' => []
];

// Dati di supporto
$categories = [];
$tags = [];
$attributes = [];
$shipping_classes = [];
$product_types = [
    'simple' => 'Simple Product',
    'variable' => 'Variable Product',
    'grouped' => 'Grouped Product',
    'external' => 'External/Affiliate Product',
    'bundle' => 'Product Bundle'
];

if ($connected && $api->isConfigured()) {
    try {
        // Carica prodotto esistente se stiamo modificando
        if ($is_edit) {
            $product_response = $api->getProduct($product_id);
            if ($product_response['success']) {
                $product = array_merge($product, $product_response['data']);
            } else {
                $error_message = 'Product not found.';
            }
        }
        
        // Carica dati di supporto
        $categories_response = $api->getCategories(['per_page' => 100]);
        if ($categories_response['success']) {
            $categories = $categories_response['data'];
        }
        
        $tags_response = $api->getTags(['per_page' => 100]);
        if ($tags_response['success']) {
            $tags = $tags_response['data'];
        }
        
        $attributes_response = $api->getAttributes();
        if ($attributes_response['success']) {
            $attributes = $attributes_response['data'];
        }
        
        $shipping_response = $api->getShippingClasses();
        if ($shipping_response['success']) {
            $shipping_classes = $shipping_response['data'];
        }
        
    } catch (Exception $e) {
        $error_message = 'Error loading product data: ' . $e->getMessage();
    }
}

// Gestisci form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $connected) {
    // Processa i dati del form
    $form_data = [
        'name' => $_POST['name'] ?? '',
        'slug' => $_POST['slug'] ?? '',
        'type' => $_POST['type'] ?? 'simple',
        'status' => $_POST['status'] ?? 'draft',
        'featured' => isset($_POST['featured']),
        'description' => $_POST['description'] ?? '',
        'short_description' => $_POST['short_description'] ?? '',
        'sku' => $_POST['sku'] ?? '',
        'regular_price' => $_POST['regular_price'] ?? '',
        'sale_price' => $_POST['sale_price'] ?? '',
        'manage_stock' => isset($_POST['manage_stock']),
        'stock_quantity' => $_POST['stock_quantity'] ?? '',
        'stock_status' => $_POST['stock_status'] ?? 'instock',
        'weight' => $_POST['weight'] ?? '',
        'dimensions' => [
            'length' => $_POST['length'] ?? '',
            'width' => $_POST['width'] ?? '',
            'height' => $_POST['height'] ?? ''
        ],
        'shipping_class_id' => $_POST['shipping_class_id'] ?? '',
        'categories' => isset($_POST['categories']) ? array_map('intval', $_POST['categories']) : [],
        'tags' => isset($_POST['tags']) ? array_map('intval', $_POST['tags']) : [],
        'attributes' => $_POST['attributes'] ?? [],
        'variations' => $_POST['variations'] ?? []
    ];
    
    try {
        if ($is_edit) {
            $response = $api->updateProduct($product_id, $form_data);
        } else {
            $response = $api->createProduct($form_data);
        }
        
        if ($response['success']) {
            $success_message = $is_edit ? 'Product updated successfully!' : 'Product created successfully!';
            if (!$is_edit) {
                $product_id = $response['data']['id'];
                header("Location: product-edit.php?id={$product_id}&success=1");
                exit;
            }
        } else {
            $error_message = 'Error saving product: ' . $response['error'];
        }
    } catch (Exception $e) {
        $error_message = 'Error saving product: ' . $e->getMessage();
    }
}

include 'header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/modular/sortable.esm.js">
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<style>
    /* Advanced Product Editor Styles */
    .product-editor {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 2rem;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .editor-main {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }
    
    .editor-sidebar {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }
    
    .editor-card {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        padding: 1.5rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }
    
    .editor-card h3 {
        font-size: 1.1rem;
        font-weight: 600;
        margin-bottom: 1rem;
        color: #111827;
    }
    
    .form-group {
        margin-bottom: 1.5rem;
    }
    
    .form-label {
        display: block;
        font-weight: 500;
        margin-bottom: 0.5rem;
        color: #374151;
        font-size: 0.875rem;
    }
    
    .form-input, .form-select, .form-textarea {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 0.875rem;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    
    .form-input:focus, .form-select:focus, .form-textarea:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .form-textarea {
        min-height: 120px;
        resize: vertical;
    }
    
    .form-checkbox {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.75rem;
    }
    
    .form-checkbox input {
        width: 1rem;
        height: 1rem;
    }
    
    /* Tab System */
    .tab-container {
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        overflow: hidden;
        background: white;
    }
    
    .tab-nav {
        display: flex;
        background: #f9fafb;
        border-bottom: 1px solid #e5e7eb;
        overflow-x: auto;
    }
    
    .tab-button {
        padding: 1rem 1.5rem;
        border: none;
        background: none;
        cursor: pointer;
        font-weight: 500;
        color: #6b7280;
        border-bottom: 2px solid transparent;
        transition: all 0.2s;
        white-space: nowrap;
    }
    
    .tab-button.active {
        color: #3b82f6;
        border-bottom-color: #3b82f6;
        background: white;
    }
    
    .tab-content {
        padding: 1.5rem;
        display: none;
    }
    
    .tab-content.active {
        display: block;
    }
    
    /* Image Upload */
    .image-upload-area {
        border: 2px dashed #d1d5db;
        border-radius: 12px;
        padding: 2rem;
        text-align: center;
        transition: all 0.2s;
        cursor: pointer;
        background: #f9fafb;
    }
    
    .image-upload-area:hover {
        border-color: #3b82f6;
        background: #eff6ff;
    }
    
    .image-upload-area.dragover {
        border-color: #3b82f6;
        background: #dbeafe;
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
        border-radius: 8px;
        overflow: hidden;
        border: 2px solid #e5e7eb;
        cursor: move;
    }
    
    .image-item img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .image-item .remove-btn {
        position: absolute;
        top: 4px;
        right: 4px;
        background: rgba(239, 68, 68, 0.9);
        color: white;
        border: none;
        border-radius: 50%;
        width: 24px;
        height: 24px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
    }
    
    .image-item .featured-btn {
        position: absolute;
        top: 4px;
        left: 4px;
        background: rgba(59, 130, 246, 0.9);
        color: white;
        border: none;
        border-radius: 4px;
        padding: 2px 6px;
        font-size: 10px;
        cursor: pointer;
    }
    
    /* Attributes */
    .attribute-list {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    
    .attribute-item {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 1rem;
        background: #f9fafb;
        cursor: move;
    }
    
    .attribute-header {
        display: flex;
        align-items: center;
        justify-content: between;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    
    .attribute-drag-handle {
        cursor: move;
        color: #9ca3af;
        padding: 0.25rem;
    }
    
    .attribute-controls {
        display: grid;
        grid-template-columns: 2fr 2fr auto auto auto;
        gap: 1rem;
        align-items: center;
    }
    
    .attribute-terms {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 1rem;
    }
    
    .term-tag {
        background: #e5e7eb;
        padding: 0.25rem 0.75rem;
        border-radius: 16px;
        font-size: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .term-tag .remove {
        cursor: pointer;
        color: #ef4444;
        font-weight: bold;
    }
    
    /* Variations */
    .variations-container {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        margin-top: 1rem;
    }
    
    .variation-item {
        border-bottom: 1px solid #e5e7eb;
        background: white;
    }
    
    .variation-header {
        padding: 1rem;
        background: #f9fafb;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .variation-content {
        padding: 1.5rem;
        display: none;
        background: white;
    }
    
    .variation-content.active {
        display: block;
    }
    
    .variation-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }
    
    /* Autocomplete */
    .autocomplete-container {
        position: relative;
    }
    
    .autocomplete-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #d1d5db;
        border-top: none;
        border-radius: 0 0 8px 8px;
        max-height: 200px;
        overflow-y: auto;
        z-index: 1000;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }
    
    .autocomplete-item {
        padding: 0.75rem;
        cursor: pointer;
        border-bottom: 1px solid #f3f4f6;
    }
    
    .autocomplete-item:hover {
        background: #f3f4f6;
    }
    
    .autocomplete-item.highlighted {
        background: #eff6ff;
        color: #1d4ed8;
    }
    
    /* Buttons */
    .btn {
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        font-weight: 500;
        font-size: 0.875rem;
        border: none;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .btn-primary {
        background: #3b82f6;
        color: white;
    }
    
    .btn-primary:hover {
        background: #2563eb;
    }
    
    .btn-secondary {
        background: #6b7280;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #4b5563;
    }
    
    .btn-outline {
        background: transparent;
        color: #374151;
        border: 1px solid #d1d5db;
    }
    
    .btn-outline:hover {
        background: #f9fafb;
    }
    
    .btn-danger {
        background: #ef4444;
        color: white;
    }
    
    .btn-danger:hover {
        background: #dc2626;
    }
    
    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.75rem;
    }
    
    /* Loading states */
    .loading {
        opacity: 0.6;
        pointer-events: none;
    }
    
    .spinner {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 2px solid #f3f3f3;
        border-top: 2px solid #3b82f6;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    /* Responsive */
    @media (max-width: 1024px) {
        .product-editor {
            grid-template-columns: 1fr;
        }
        
        .variation-grid {
            grid-template-columns: 1fr;
        }
    }
    
    @media (max-width: 640px) {
        .tab-nav {
            flex-wrap: wrap;
        }
        
        .attribute-controls {
            grid-template-columns: 1fr;
        }
        
        .image-gallery {
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
        }
    }
</style>

<div class="px-8 flex flex-1 justify-center py-6">
    <div class="w-full max-w-none">
        
        <!-- Page Header -->
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                <a href="products.php" class="text-[#6b7280] hover:text-[#374151]">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 256 256">
                        <path d="M165.66,202.34a8,8,0,0,1-11.32,11.32l-80-80a8,8,0,0,1,0-11.32l80-80a8,8,0,0,1,11.32,11.32L91.31,128Z"></path>
                    </svg>
                </a>
                <h1 class="text-2xl font-bold text-[#101418]"><?php echo $page_title; ?></h1>
                <?php if ($is_edit): ?>
                    <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm font-medium">
                        ID: <?php echo $product_id; ?>
                    </span>
                <?php endif; ?>
            </div>
            
            <div class="flex gap-3">
                <button type="button" onclick="saveAsDraft()" class="btn btn-outline">
                    Save Draft
                </button>
                <button type="button" onclick="previewProduct()" class="btn btn-secondary">
                    Preview
                </button>
                <button type="submit" form="product-form" class="btn btn-primary">
                    <?php echo $is_edit ? 'Update Product' : 'Create Product'; ?>
                </button>
            </div>
        </div>

        <!-- Messages -->
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error mb-6">
                <strong>Error:</strong> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success mb-6">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <!-- Product Editor Form -->
        <form id="product-form" method="POST" enctype="multipart/form-data">
            <div class="product-editor">
                
                <!-- Main Content -->
                <div class="editor-main">
                    
                    <!-- Basic Information -->
                    <div class="editor-card">
                        <h3>Basic Information</h3>
                        
                        <div class="form-group">
                            <label class="form-label" for="name">Product Name *</label>
                            <input type="text" name="name" id="name" class="form-input" 
                                   value="<?php echo htmlspecialchars($product['name']); ?>" 
                                   required onblur="generateSlug()">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="slug">Product Slug</label>
                            <input type="text" name="slug" id="slug" class="form-input" 
                                   value="<?php echo htmlspecialchars($product['slug']); ?>">
                            <small class="text-gray-600">URL-friendly version of the name</small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="short_description">Short Description</label>
                            <textarea name="short_description" id="short_description" class="form-textarea" 
                                      rows="3"><?php echo htmlspecialchars($product['short_description']); ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="description">Full Description</label>
                            <textarea name="description" id="description" class="form-textarea" 
                                      rows="8"><?php echo htmlspecialchars($product['description']); ?></textarea>
                        </div>
                    </div>
                    
                    <!-- Product Data Tabs -->
                    <div class="tab-container">
                        <div class="tab-nav">
                            <button type="button" class="tab-button active" data-tab="general">General</button>
                            <button type="button" class="tab-button" data-tab="inventory">Inventory</button>
                            <button type="button" class="tab-button" data-tab="shipping">Shipping</button>
                            <button type="button" class="tab-button" data-tab="attributes">Attributes</button>
                            <button type="button" class="tab-button" data-tab="variations" id="variations-tab" style="display: none;">Variations</button>
                            <button type="button" class="tab-button" data-tab="advanced">Advanced</button>
                        </div>
                        
                        <!-- General Tab -->
                        <div class="tab-content active" data-tab="general">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <div class="form-group">
                                        <label class="form-label" for="sku">SKU</label>
                                        <input type="text" name="sku" id="sku" class="form-input" 
                                               value="<?php echo htmlspecialchars($product['sku']); ?>">
                                        <small class="text-gray-600">Unique identifier for this product</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="regular_price">Regular Price ($)</label>
                                        <input type="number" name="regular_price" id="regular_price" class="form-input" 
                                               step="0.01" min="0" value="<?php echo $product['regular_price']; ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="sale_price">Sale Price ($)</label>
                                        <input type="number" name="sale_price" id="sale_price" class="form-input" 
                                               step="0.01" min="0" value="<?php echo $product['sale_price']; ?>">
                                    </div>
                                </div>
                                
                                <div>
                                    <div class="form-group">
                                        <label class="form-label">Sale Date Range</label>
                                        <div class="grid grid-cols-2 gap-2">
                                            <input type="date" name="date_on_sale_from" class="form-input" 
                                                   value="<?php echo $product['date_on_sale_from']; ?>">
                                            <input type="date" name="date_on_sale_to" class="form-input" 
                                                   value="<?php echo $product['date_on_sale_to']; ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="form-checkbox">
                                        <input type="checkbox" name="virtual" id="virtual" 
                                               <?php echo $product['virtual'] ? 'checked' : ''; ?>>
                                        <label for="virtual">Virtual product</label>
                                    </div>
                                    
                                    <div class="form-checkbox">
                                        <input type="checkbox" name="downloadable" id="downloadable" 
                                               <?php echo $product['downloadable'] ? 'checked' : ''; ?>>
                                        <label for="downloadable">Downloadable product</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Inventory Tab -->
                        <div class="tab-content" data-tab="inventory">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <div class="form-checkbox">
                                        <input type="checkbox" name="manage_stock" id="manage_stock" 
                                               onchange="toggleStockFields()" 
                                               <?php echo $product['manage_stock'] ? 'checked' : ''; ?>>
                                        <label for="manage_stock">Manage stock quantity</label>
                                    </div>
                                    
                                    <div class="form-group" id="stock_quantity_group" 
                                         style="display: <?php echo $product['manage_stock'] ? 'block' : 'none'; ?>;">
                                        <label class="form-label" for="stock_quantity">Stock Quantity</label>
                                        <input type="number" name="stock_quantity" id="stock_quantity" class="form-input" 
                                               min="0" value="<?php echo $product['stock_quantity']; ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="stock_status">Stock Status</label>
                                        <select name="stock_status" id="stock_status" class="form-select">
                                            <option value="instock" <?php echo $product['stock_status'] === 'instock' ? 'selected' : ''; ?>>In stock</option>
                                            <option value="outofstock" <?php echo $product['stock_status'] === 'outofstock' ? 'selected' : ''; ?>>Out of stock</option>
                                            <option value="onbackorder" <?php echo $product['stock_status'] === 'onbackorder' ? 'selected' : ''; ?>>On backorder</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div>
                                    <div class="form-group">
                                        <label class="form-label" for="backorders">Allow backorders</label>
                                        <select name="backorders" id="backorders" class="form-select">
                                            <option value="no" <?php echo $product['backorders'] === 'no' ? 'selected' : ''; ?>>Do not allow</option>
                                            <option value="notify" <?php echo $product['backorders'] === 'notify' ? 'selected' : ''; ?>>Allow, but notify customer</option>
                                            <option value="yes" <?php echo $product['backorders'] === 'yes' ? 'selected' : ''; ?>>Allow</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-checkbox">
                                        <input type="checkbox" name="sold_individually" id="sold_individually" 
                                               <?php echo $product['sold_individually'] ? 'checked' : ''; ?>>
                                        <label for="sold_individually">Sold individually (limit to 1 per order)</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Shipping Tab -->
                        <div class="tab-content" data-tab="shipping">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <div class="form-group">
                                        <label class="form-label" for="weight">Weight (kg)</label>
                                        <input type="number" name="weight" id="weight" class="form-input" 
                                               step="0.01" min="0" value="<?php echo $product['weight']; ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Dimensions (cm)</label>
                                        <div class="grid grid-cols-3 gap-2">
                                            <input type="number" name="length" placeholder="Length" class="form-input" 
                                                   step="0.01" min="0" value="<?php echo $product['dimensions']['length']; ?>">
                                            <input type="number" name="width" placeholder="Width" class="form-input" 
                                                   step="0.01" min="0" value="<?php echo $product['dimensions']['width']; ?>">
                                            <input type="number" name="height" placeholder="Height" class="form-input" 
                                                   step="0.01" min="0" value="<?php echo $product['dimensions']['height']; ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div>
                                    <div class="form-group">
                                        <label class="form-label" for="shipping_class_id">Shipping Class</label>
                                        <div class="autocomplete-container">
                                            <select name="shipping_class_id" id="shipping_class_id" class="form-select">
                                                <option value="">No shipping class</option>
                                                <?php foreach ($shipping_classes as $class): ?>
                                                    <option value="<?php echo $class['id']; ?>" 
                                                            <?php echo $product['shipping_class_id'] == $class['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($class['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-sm btn-outline mt-2" 
                                                    onclick="createShippingClass()">
                                                Add New Shipping Class
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Attributes Tab -->
                        <div class="tab-content" data-tab="attributes">
                            <div class="flex justify-between items-center mb-4">
                                <h4 class="font-medium">Product Attributes</h4>
                                <button type="button" class="btn btn-sm btn-primary" onclick="addAttribute()">
                                    Add Attribute
                                </button>
                            </div>
                            
                            <div id="attributes-list" class="attribute-list">
                                <!-- Attributes will be populated by JavaScript -->
                            </div>
                            
                            <div class="mt-4 p-4 bg-blue-50 rounded-lg">
                                <p class="text-sm text-blue-800">
                                    <strong>Tip:</strong> Use attributes to add extra product information like size, color, material. 
                                    Mark attributes as "Used for variations" to create variable products.
                                </p>
                            </div>
                        </div>
                        
                        <!-- Variations Tab -->
                        <div class="tab-content" data-tab="variations">
                            <div class="flex justify-between items-center mb-4">
                                <h4 class="font-medium">Product Variations</h4>
                                <div class="flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline" onclick="generateAllVariations()">
                                        Generate All Variations
                                    </button>
                                    <button type="button" class="btn btn-sm btn-primary" onclick="addVariation()">
                                        Add Variation
                                    </button>
                                </div>
                            </div>
                            
                            <div id="variations-container" class="variations-container">
                                <!-- Variations will be populated by JavaScript -->
                            </div>
                        </div>
                        
                        <!-- Advanced Tab -->
                        <div class="tab-content" data-tab="advanced">
                            <div class="form-group">
                                <label class="form-label" for="purchase_note">Purchase Note</label>
                                <textarea name="purchase_note" id="purchase_note" class="form-textarea" 
                                          rows="3" placeholder="Enter a note that will be sent to the customer after purchase"></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="menu_order">Menu Order</label>
                                <input type="number" name="menu_order" id="menu_order" class="form-input" 
                                       value="0" min="0">
                                <small class="text-gray-600">Custom ordering position for this product</small>
                            </div>
                            
                            <div class="form-checkbox">
                                <input type="checkbox" name="reviews_allowed" id="reviews_allowed" checked>
                                <label for="reviews_allowed">Enable product reviews</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Sidebar -->
                <div class="editor-sidebar">
                    
                    <!-- Publish Status -->
                    <div class="editor-card">
                        <h3>Publish</h3>
                        
                        <div class="form-group">
                            <label class="form-label" for="status">Status</label>
                            <select name="status" id="status" class="form-select">
                                <option value="draft" <?php echo $product['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="publish" <?php echo $product['status'] === 'publish' ? 'selected' : ''; ?>>Published</option>
                                <option value="private" <?php echo $product['status'] === 'private' ? 'selected' : ''; ?>>Private</option>
                                <option value="pending" <?php echo $product['status'] === 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="type">Product Type</label>
                            <select name="type" id="type" class="form-select" onchange="toggleProductType()">
                                <?php foreach ($product_types as $type_key => $type_label): ?>
                                    <option value="<?php echo $type_key; ?>" 
                                            <?php echo $product['type'] === $type_key ? 'selected' : ''; ?>>
                                        <?php echo $type_label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-checkbox">
                            <input type="checkbox" name="featured" id="featured" 
                                   <?php echo $product['featured'] ? 'checked' : ''; ?>>
                            <label for="featured">Featured Product</label>
                        </div>
                    </div>
                    
                    <!-- Product Categories -->
                    <div class="editor-card">
                        <h3>Categories</h3>
                        <div class="autocomplete-container">
                            <input type="text" id="category-search" class="form-input" 
                                   placeholder="Search categories...">
                            <div id="category-autocomplete" class="autocomplete-dropdown" style="display: none;"></div>
                        </div>
                        
                        <div class="max-h-48 overflow-y-auto mt-3 space-y-2">
                            <?php foreach ($categories as $category): ?>
                                <div class="form-checkbox">
                                    <input type="checkbox" name="categories[]" value="<?php echo $category['id']; ?>" 
                                           id="cat_<?php echo $category['id']; ?>"
                                           <?php echo in_array($category['id'], array_column($product['categories'], 'id')) ? 'checked' : ''; ?>>
                                    <label for="cat_<?php echo $category['id']; ?>" class="text-sm">
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <button type="button" class="btn btn-sm btn-outline mt-3" onclick="createCategory()">
                            Add New Category
                        </button>
                    </div>
                    
                    <!-- Product Tags -->
                    <div class="editor-card">
                        <h3>Tags</h3>
                        <div class="autocomplete-container">
                            <input type="text" id="tag-search" class="form-input" 
                                   placeholder="Add tags...">
                            <div id="tag-autocomplete" class="autocomplete-dropdown" style="display: none;"></div>
                        </div>
                        
                        <div id="selected-tags" class="flex flex-wrap gap-2 mt-3">
                            <!-- Selected tags will be populated by JavaScript -->
                        </div>
                        
                        <button type="button" class="btn btn-sm btn-outline mt-3" onclick="createTag()">
                            Add New Tag
                        </button>
                    </div>
                    
                    <!-- Product Images -->
                    <div class="editor-card">
                        <h3>Product Images</h3>
                        
                        <div class="image-upload-area" id="image-upload" onclick="document.getElementById('image-input').click()">
                            <div class="text-center">
                                <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <p class="mt-2 text-sm text-gray-600">
                                    <strong>Drop images here</strong> or click to upload
                                </p>
                                <p class="text-xs text-gray-500">PNG, JPG, WebP up to 10MB</p>
                            </div>
                        </div>
                        
                        <input type="file" id="image-input" multiple accept="image/*" style="display: none;" onchange="handleImageUpload(event)">
                        
                        <div id="image-gallery" class="image-gallery">
                            <!-- Images will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modals -->
<div id="create-category-modal" class="modal">
    <div class="modal-content">
        <h3>Create New Category</h3>
        <form id="category-form">
            <div class="form-group">
                <label class="form-label" for="new-category-name">Category Name</label>
                <input type="text" id="new-category-name" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="new-category-parent">Parent Category</label>
                <select id="new-category-parent" class="form-select">
                    <option value="">None (top level)</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['id']; ?>">
                            <?php echo htmlspecialchars($category['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-3 justify-end">
                <button type="button" class="btn btn-outline" onclick="closeModal('create-category-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Category</button>
            </div>
        </form>
    </div>
</div>

<script>
// Advanced Product Editor JavaScript
class ProductEditor {
    constructor() {
        this.attributes = [];
        this.variations = [];
        this.images = [];
        this.selectedTags = [];
        this.availableAttributes = <?php echo json_encode($attributes); ?>;
        this.availableCategories = <?php echo json_encode($categories); ?>;
        this.availableTags = <?php echo json_encode($tags); ?>;
        
        this.init();
    }
    
    init() {
        this.setupTabs();
        this.setupImageUpload();
        this.setupAutocomplete();
        this.setupSortable();
        this.loadProductData();
        this.setupEventListeners();
    }
    
    setupTabs() {
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                const targetTab = button.dataset.tab;
                
                // Remove active from all tabs
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));
                
                // Add active to clicked tab
                button.classList.add('active');
                document.querySelector(`[data-tab="${targetTab}"]`).classList.add('active');
            });
        });
    }
    
    setupImageUpload() {
        const uploadArea = document.getElementById('image-upload');
        const imageInput = document.getElementById('image-input');
        
        // Drag and drop
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });
        
        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('dragover');
        });
        
        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            this.handleFiles(e.dataTransfer.files);
        });
    }
    
    handleFiles(files) {
        Array.from(files).forEach(file => {
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    this.addImage({
                        id: Date.now() + Math.random(),
                        src: e.target.result,
                        name: file.name,
                        file: file
                    });
                };
                reader.readAsDataURL(file);
            }
        });
    }
    
    addImage(imageData) {
        this.images.push(imageData);
        this.renderImages();
    }
    
    renderImages() {
        const gallery = document.getElementById('image-gallery');
        gallery.innerHTML = '';
        
        this.images.forEach((image, index) => {
            const imageItem = document.createElement('div');
            imageItem.className = 'image-item';
            imageItem.innerHTML = `
                <img src="${image.src}" alt="${image.name}">
                <button type="button" class="remove-btn" onclick="productEditor.removeImage(${index})">×</button>
                <button type="button" class="featured-btn ${index === 0 ? 'active' : ''}" onclick="productEditor.setFeaturedImage(${index})">
                    ${index === 0 ? 'Featured' : 'Set Featured'}
                </button>
            `;
            gallery.appendChild(imageItem);
        });
        
        // Make images sortable
        if (window.Sortable) {
            Sortable.create(gallery, {
                animation: 150,
                onEnd: (evt) => {
                    const oldIndex = evt.oldIndex;
                    const newIndex = evt.newIndex;
                    const movedImage = this.images.splice(oldIndex, 1)[0];
                    this.images.splice(newIndex, 0, movedImage);
                    this.renderImages();
                }
            });
        }
    }
    
    removeImage(index) {
        this.images.splice(index, 1);
        this.renderImages();
    }
    
    setFeaturedImage(index) {
        const movedImage = this.images.splice(index, 1)[0];
        this.images.unshift(movedImage);
        this.renderImages();
    }
    
    setupAutocomplete() {
        // Category autocomplete
        const categorySearch = document.getElementById('category-search');
        const categoryDropdown = document.getElementById('category-autocomplete');
        
        categorySearch.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase();
            if (query.length < 2) {
                categoryDropdown.style.display = 'none';
                return;
            }
            
            const filtered = this.availableCategories.filter(cat => 
                cat.name.toLowerCase().includes(query)
            );
            
            this.renderAutocomplete(categoryDropdown, filtered, (item) => {
                document.getElementById(`cat_${item.id}`).checked = true;
                categorySearch.value = '';
                categoryDropdown.style.display = 'none';
            });
        });
        
        // Tag autocomplete
        const tagSearch = document.getElementById('tag-search');
        const tagDropdown = document.getElementById('tag-autocomplete');
        
        tagSearch.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase();
            if (query.length < 2) {
                tagDropdown.style.display = 'none';
                return;
            }
            
            const filtered = this.availableTags.filter(tag => 
                tag.name.toLowerCase().includes(query) && 
                !this.selectedTags.includes(tag.id)
            );
            
            this.renderAutocomplete(tagDropdown, filtered, (item) => {
                this.addTag(item);
                tagSearch.value = '';
                tagDropdown.style.display = 'none';
            });
        });
        
        tagSearch.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const tagName = e.target.value.trim();
                if (tagName) {
                    this.createNewTag(tagName);
                    e.target.value = '';
                }
            }
        });
    }
    
    renderAutocomplete(dropdown, items, onSelect) {
        dropdown.innerHTML = '';
        
        if (items.length === 0) {
            dropdown.style.display = 'none';
            return;
        }
        
        items.forEach(item => {
            const div = document.createElement('div');
            div.className = 'autocomplete-item';
            div.textContent = item.name;
            div.onclick = () => onSelect(item);
            dropdown.appendChild(div);
        });
        
        dropdown.style.display = 'block';
    }
    
    addTag(tag) {
        if (!this.selectedTags.includes(tag.id)) {
            this.selectedTags.push(tag.id);
            this.renderTags();
        }
    }
    
    renderTags() {
        const container = document.getElementById('selected-tags');
        container.innerHTML = '';
        
        this.selectedTags.forEach(tagId => {
            const tag = this.availableTags.find(t => t.id === tagId);
            if (tag) {
                const tagElement = document.createElement('div');
                tagElement.className = 'term-tag';
                tagElement.innerHTML = `
                    ${tag.name}
                    <span class="remove" onclick="productEditor.removeTag(${tagId})">×</span>
                    <input type="hidden" name="tags[]" value="${tagId}">
                `;
                container.appendChild(tagElement);
            }
        });
    }
    
    removeTag(tagId) {
        this.selectedTags = this.selectedTags.filter(id => id !== tagId);
        this.renderTags();
    }
    
    setupSortable() {
        // Will be implemented for attributes reordering
    }
    
    loadProductData() {
        // Load existing product data if editing
        const product = <?php echo json_encode($product); ?>;
        
        if (product.images) {
            this.images = product.images.map((img, index) => ({
                id: img.id || index,
                src: img.src,
                name: img.name || `Image ${index + 1}`,
                alt: img.alt || ''
            }));
            this.renderImages();
        }
        
        if (product.tags) {
            this.selectedTags = product.tags.map(tag => tag.id);
            this.renderTags();
        }
        
        if (product.attributes) {
            this.attributes = product.attributes;
            this.renderAttributes();
        }
    }
    
    setupEventListeners() {
        // Type change handler
        document.getElementById('type').addEventListener('change', () => {
            this.toggleProductType();
        });
    }
    
    toggleProductType() {
        const type = document.getElementById('type').value;
        const variationsTab = document.getElementById('variations-tab');
        
        if (type === 'variable') {
            variationsTab.style.display = 'block';
        } else {
            variationsTab.style.display = 'none';
        }
    }
    
    // Attribute management
    addAttribute() {
        const modal = document.createElement('div');
        modal.className = 'modal show';
        modal.innerHTML = `
            <div class="modal-content">
                <h3>Add Attribute</h3>
                <form id="attribute-form">
                    <div class="form-group">
                        <label class="form-label">Attribute</label>
                        <select id="attribute-select" class="form-select" required>
                            <option value="">Select an attribute</option>
                            ${this.availableAttributes.map(attr => 
                                `<option value="${attr.id}">${attr.name}</option>`
                            ).join('')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Values</label>
                        <input type="text" id="attribute-values" class="form-input" 
                               placeholder="Enter values separated by | (pipe)" required>
                        <small>Example: Red | Blue | Green</small>
                    </div>
                    <div class="form-checkbox">
                        <input type="checkbox" id="attribute-visible">
                        <label for="attribute-visible">Visible on product page</label>
                    </div>
                    <div class="form-checkbox">
                        <input type="checkbox" id="attribute-variation">
                        <label for="attribute-variation">Used for variations</label>
                    </div>
                    <div class="flex gap-3 justify-end mt-4">
                        <button type="button" class="btn btn-outline" onclick="this.closest('.modal').remove()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Attribute</button>
                    </div>
                </form>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        modal.querySelector('#attribute-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.saveAttribute(modal);
        });
    }
    
    saveAttribute(modal) {
        const form = modal.querySelector('#attribute-form');
        const attributeId = form.querySelector('#attribute-select').value;
        const values = form.querySelector('#attribute-values').value;
        const visible = form.querySelector('#attribute-visible').checked;
        const variation = form.querySelector('#attribute-variation').checked;
        
        const attribute = this.availableAttributes.find(attr => attr.id == attributeId);
        if (!attribute) return;
        
        const newAttribute = {
            id: attributeId,
            name: attribute.name,
            values: values.split('|').map(v => v.trim()).filter(v => v),
            visible: visible,
            variation: variation,
            position: this.attributes.length
        };
        
        this.attributes.push(newAttribute);
        this.renderAttributes();
        modal.remove();
        
        if (variation) {
            this.updateVariationsTab();
        }
    }
    
    renderAttributes() {
        const container = document.getElementById('attributes-list');
        container.innerHTML = '';
        
        this.attributes.forEach((attribute, index) => {
            const attributeElement = document.createElement('div');
            attributeElement.className = 'attribute-item';
            attributeElement.innerHTML = `
                <div class="attribute-header">
                    <div class="attribute-drag-handle">☰</div>
                    <strong>${attribute.name}</strong>
                    <div class="ml-auto">
                        <button type="button" class="btn btn-sm btn-outline" onclick="productEditor.editAttribute(${index})">Edit</button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="productEditor.removeAttribute(${index})">Remove</button>
                    </div>
                </div>
                <div class="attribute-terms">
                    ${attribute.values.map(value => `<span class="term-tag">${value}</span>`).join('')}
                </div>
                <div class="mt-2 text-sm text-gray-600">
                    ${attribute.visible ? '✓ Visible' : '✗ Not visible'} | 
                    ${attribute.variation ? '✓ Used for variations' : '✗ Not used for variations'}
                </div>
            `;
            container.appendChild(attributeElement);
        });
        
        // Make attributes sortable
        if (window.Sortable && container.children.length > 0) {
            Sortable.create(container, {
                handle: '.attribute-drag-handle',
                animation: 150,
                onEnd: (evt) => {
                    const oldIndex = evt.oldIndex;
                    const newIndex = evt.newIndex;
                    const movedAttribute = this.attributes.splice(oldIndex, 1)[0];
                    this.attributes.splice(newIndex, 0, movedAttribute);
                    this.renderAttributes();
                }
            });
        }
    }
    
    removeAttribute(index) {
        if (confirm('Are you sure you want to remove this attribute?')) {
            this.attributes.splice(index, 1);
            this.renderAttributes();
            this.updateVariationsTab();
        }
    }
    
    updateVariationsTab() {
        const hasVariationAttributes = this.attributes.some(attr => attr.variation);
        if (hasVariationAttributes) {
            document.getElementById('type').value = 'variable';
            this.toggleProductType();
        }
    }
    
    // Variation management
    generateAllVariations() {
        const variationAttributes = this.attributes.filter(attr => attr.variation);
        if (variationAttributes.length === 0) {
            alert('Please add attributes that are used for variations first.');
            return;
        }
        
        // Generate all possible combinations
        const combinations = this.generateCombinations(variationAttributes);
        
        this.variations = combinations.map((combination, index) => ({
            id: `new_${index}`,
            attributes: combination,
            regular_price: '',
            sale_price: '',
            sku: '',
            stock_quantity: '',
            manage_stock: false,
            weight: '',
            dimensions: { length: '', width: '', height: '' },
            image: null,
            enabled: true
        }));
        
        this.renderVariations();
    }
    
    generateCombinations(attributes) {
        if (attributes.length === 0) return [{}];
        if (attributes.length === 1) {
            return attributes[0].values.map(value => ({
                [attributes[0].name]: value
            }));
        }
        
        const result = [];
        const firstAttribute = attributes[0];
        const restCombinations = this.generateCombinations(attributes.slice(1));
        
        firstAttribute.values.forEach(value => {
            restCombinations.forEach(combination => {
                result.push({
                    [firstAttribute.name]: value,
                    ...combination
                });
            });
        });
        
        return result;
    }
    
    renderVariations() {
        const container = document.getElementById('variations-container');
        container.innerHTML = '';
        
        this.variations.forEach((variation, index) => {
            const variationElement = document.createElement('div');
            variationElement.className = 'variation-item';
            
            const attributeNames = Object.keys(variation.attributes).map(key => 
                `${key}: ${variation.attributes[key]}`
            ).join(', ');
            
            variationElement.innerHTML = `
                <div class="variation-header" onclick="this.nextElementSibling.classList.toggle('active')">
                    <div>
                        <strong>Variation #${index + 1}</strong>
                        <span class="text-gray-600 ml-2">${attributeNames}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="flex items-center gap-1">
                            <input type="checkbox" ${variation.enabled ? 'checked' : ''} 
                                   onchange="productEditor.toggleVariation(${index}, this.checked)">
                            <span class="text-sm">Enabled</span>
                        </label>
                        <button type="button" class="btn btn-sm btn-danger" onclick="productEditor.removeVariation(${index})">Remove</button>
                    </div>
                </div>
                <div class="variation-content">
                    <div class="variation-grid">
                        <div>
                            <label class="form-label">SKU</label>
                            <input type="text" class="form-input" value="${variation.sku}" 
                                   onchange="productEditor.updateVariation(${index}, 'sku', this.value)">
                        </div>
                        <div>
                            <label class="form-label">Regular Price</label>
                            <input type="number" class="form-input" step="0.01" value="${variation.regular_price}" 
                                   onchange="productEditor.updateVariation(${index}, 'regular_price', this.value)">
                        </div>
                        <div>
                            <label class="form-label">Sale Price</label>
                            <input type="number" class="form-input" step="0.01" value="${variation.sale_price}" 
                                   onchange="productEditor.updateVariation(${index}, 'sale_price', this.value)">
                        </div>
                        <div>
                            <label class="form-label">Stock Quantity</label>
                            <input type="number" class="form-input" value="${variation.stock_quantity}" 
                                   onchange="productEditor.updateVariation(${index}, 'stock_quantity', this.value)">
                        </div>
                        <div>
                            <label class="form-label">Weight</label>
                            <input type="number" class="form-input" step="0.01" value="${variation.weight}" 
                                   onchange="productEditor.updateVariation(${index}, 'weight', this.value)">
                        </div>
                        <div>
                            <label class="form-label">Variation Image</label>
                            <input type="file" class="form-input" accept="image/*" 
                                   onchange="productEditor.updateVariationImage(${index}, this)">
                        </div>
                    </div>
                </div>
            `;
            
            container.appendChild(variationElement);
        });
    }
    
    updateVariation(index, field, value) {
        this.variations[index][field] = value;
    }
    
    toggleVariation(index, enabled) {
        this.variations[index].enabled = enabled;
    }
    
    removeVariation(index) {
        if (confirm('Are you sure you want to remove this variation?')) {
            this.variations.splice(index, 1);
            this.renderVariations();
        }
    }
    
    updateVariationImage(index, input) {
        const file = input.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = (e) => {
                this.variations[index].image = {
                    src: e.target.result,
                    name: file.name,
                    file: file
                };
            };
            reader.readAsDataURL(file);
        }
    }
}

// Initialize the product editor
let productEditor;
document.addEventListener('DOMContentLoaded', () => {
    productEditor = new ProductEditor();
});

// Global functions
function handleImageUpload(event) {
    productEditor.handleFiles(event.target.files);
}

function generateSlug() {
    const name = document.getElementById('name').value;
    const slug = name.toLowerCase()
        .replace(/[^\w\s-]/g, '')
        .replace(/[\s_-]+/g, '-')
        .replace(/^-+|-+$/g, '');
    document.getElementById('slug').value = slug;
}

function toggleStockFields() {
    const manageStock = document.getElementById('manage_stock').checked;
    const stockGroup = document.getElementById('stock_quantity_group');
    stockGroup.style.display = manageStock ? 'block' : 'none';
}

function toggleProductType() {
    productEditor.toggleProductType();
}

function addAttribute() {
    productEditor.addAttribute();
}

function generateAllVariations() {
    productEditor.generateAllVariations();
}

function addVariation() {
    // Implementation for adding single variation
}

function saveAsDraft() {
    document.getElementById('status').value = 'draft';
    document.getElementById('product-form').submit();
}

function previewProduct() {
    // Implementation for product preview
    ShopManager.showNotification('Product preview feature will be implemented soon.', 'info');
}

// Modal functions
function createCategory() {
    document.getElementById('create-category-modal').classList.add('show');
}

function createTag() {
    const tagName = prompt('Enter tag name:');
    if (tagName) {
        productEditor.createNewTag(tagName);
    }
}

function createShippingClass() {
    const className = prompt('Enter shipping class name:');
    if (className) {
        // Implementation for creating shipping class
        ShopManager.showNotification('Shipping class creation will be implemented soon.', 'info');
    }
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

// Form validation and submission
document.getElementById('product-form').addEventListener('submit', function(e) {
    // Add validation if needed
    const name = document.getElementById('name').value.trim();
    if (!name) {
        e.preventDefault();
        alert('Product name is required.');
        return false;
    }
    
    // Add hidden inputs for complex data
    const hiddenContainer = document.createElement('div');
    
    // Add images data
    if (productEditor.images.length > 0) {
        const imagesInput = document.createElement('input');
        imagesInput.type = 'hidden';
        imagesInput.name = 'images_data';
        imagesInput.value = JSON.stringify(productEditor.images);
        hiddenContainer.appendChild(imagesInput);
    }
    
    // Add attributes data
    if (productEditor.attributes.length > 0) {
        const attributesInput = document.createElement('input');
        attributesInput.type = 'hidden';
        attributesInput.name = 'attributes_data';
        attributesInput.value = JSON.stringify(productEditor.attributes);
        hiddenContainer.appendChild(attributesInput);
    }
    
    // Add variations data
    if (productEditor.variations.length > 0) {
        const variationsInput = document.createElement('input');
        variationsInput.type = 'hidden';
        variationsInput.name = 'variations_data';
        variationsInput.value = JSON.stringify(productEditor.variations);
        hiddenContainer.appendChild(variationsInput);
    }
    
    this.appendChild(hiddenContainer);
    
    // Show loading state
    const submitButton = this.querySelector('button[type="submit"]');
    ShopManager.showLoading(submitButton);
});
</script>

</body>
</html>