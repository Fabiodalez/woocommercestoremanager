<?php
// WooCommerceAPI.php - Complete WooCommerce API Integration Class

if (!class_exists('Config')) {
    require_once __DIR__ . '/config.php';
}
if (!class_exists('Database')) {
    require_once __DIR__ . '/database.php';
}

class WooCommerceAPI {
    protected $store_config_id;
    protected $store_config;
    protected $user_id;
    protected $api_base_url;
    protected $consumer_key;
    protected $consumer_secret;
    protected $api_version;
    protected $timeout;
    protected $rate_limit_requests;
    protected $rate_limit_window;
    protected $max_retries;
    protected $retry_delay;

    protected $last_response_headers = [];
    protected $last_error = null;
    protected $last_status_code = null;
    protected $last_request_info = [];

    public function __construct($user_id = null, $store_config_id = null) {
        $this->user_id = $user_id ?: Config::getCurrentUserId();

        if (!$this->user_id) {
            throw new Exception('WooCommerceAPI: User authentication required.');
        }

        if ($store_config_id) {
            $this->store_config_id = $store_config_id;
            $this->loadStoreConfigById($store_config_id);
        } else {
            $this->store_config = Config::getCurrentStore();
            if ($this->store_config && isset($this->store_config['id'])) {
                $this->store_config_id = $this->store_config['id'];
            }
        }

        if (!$this->store_config || empty($this->store_config['id'])) {
            throw new Exception('WooCommerceAPI: Store configuration not found.');
        }

        $this->initializeConfiguration();
    }

    private function initializeConfiguration() {
        $this->consumer_key = $this->store_config['consumer_key'] ?? null;
        $this->consumer_secret = $this->store_config['consumer_secret'] ?? null;
        $this->api_version = $this->store_config['api_version'] ?? Config::getSetting('api_version', 'v3');
        $this->timeout = (int)($this->store_config['timeout'] ?? Config::getSetting('api_timeout', 30));
        $this->rate_limit_requests = (int)($this->store_config['rate_limit'] ?? Config::getSystemSetting('api_rate_limit_requests', 100));
        $this->rate_limit_window = (int)Config::getSystemSetting('api_rate_limit_window', 3600);
        $this->max_retries = (int)Config::getSystemSetting('api_max_retries', 3);
        $this->retry_delay = (int)Config::getSystemSetting('api_retry_delay', 1);

        if (empty($this->store_config['store_url'])) {
            throw new Exception('WooCommerceAPI: Store URL is not configured for store ID ' . $this->store_config_id);
        }
        $this->api_base_url = rtrim($this->store_config['store_url'], '/') . '/wp-json/wc/' . $this->api_version . '/';
    }

    private function loadStoreConfigById($store_config_id) {
        $db = Database::getInstance();
        $this->store_config = $db->fetch('
            SELECT uc.*, 
                   CASE WHEN uc.user_id = :user_id THEN "owner" ELSE sc.role END as effective_role,
                   sc.permissions, sc.status as collaborator_status
            FROM user_configs uc
            LEFT JOIN store_collaborators sc ON uc.id = sc.store_config_id AND sc.user_id = :user_id_collab
            WHERE uc.id = :store_config_id 
              AND (uc.user_id = :user_id_owner OR (sc.status = "accepted" AND sc.user_id = :user_id_final))
        ', [
            'user_id' => $this->user_id, 'user_id_collab' => $this->user_id,
            'store_config_id' => $store_config_id,
            'user_id_owner' => $this->user_id, 'user_id_final' => $this->user_id
        ]);
        
        if (!$this->store_config) {
            throw new Exception('WooCommerceAPI: Access to store config ID ' . $store_config_id . ' denied or not found.');
        }
    }

    public function isConfigured() {
        return !empty($this->api_base_url) && !empty($this->consumer_key) && !empty($this->consumer_secret);
    }

    protected function checkRateLimit($endpoint_group = 'general') {
        $specific_endpoint = 'wc_api_' . ($this->store_config_id ?: 'unknown_store') . '_' . $endpoint_group;
        return Config::checkRateLimit($specific_endpoint, $this->rate_limit_requests, $this->rate_limit_window);
    }
    
    public function hasPermission($action) {
        if (!$this->store_config) return false;
        
        $is_owner = ($this->store_config['user_id'] == $this->user_id) || (($this->store_config['effective_role'] ?? '') === 'owner');
        if ($is_owner) return true;
        
        $role = $this->store_config['effective_role'] ?? 'viewer';
        $custom_permissions = json_decode($this->store_config['permissions'] ?? '[]', true) ?: [];
        
        $role_based_permissions = [
            'admin' => ['read', 'write', 'delete', 'manage_settings', 'sync_data'],
            'editor' => ['read', 'write', 'sync_data'], 
            'viewer' => ['read']
        ];
        
        $allowed_actions = array_unique(array_merge($role_based_permissions[$role] ?? [], $custom_permissions));
        return in_array($action, $allowed_actions);
    }

    protected function makeRequest($endpoint, $params = [], $method = 'GET', $check_permissions = true) {
        $this->last_error = null;
        $this->last_status_code = null;
        $this->last_response_headers = [];
        $this->last_request_info = [
            'endpoint' => $endpoint,
            'method' => $method,
            'params_count' => count($params),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        if (!$this->isConfigured()) {
            return $this->formatError('API credentials are not configured.');
        }

        if ($check_permissions && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $action = (strtoupper($method) === 'DELETE') ? 'delete' : 'write';
            if (!$this->hasPermission($action)) {
                return $this->formatError('Insufficient permissions for this API action.', 'permission_denied');
            }
        }

        $endpoint_group = explode('/', $endpoint)[0]; // e.g., 'products', 'orders'
        if (!$this->checkRateLimit($endpoint_group)) {
            return $this->formatError('API rate limit exceeded.', 'rate_limit_exceeded');
        }

        $url = $this->api_base_url . ltrim($endpoint, '/');
        $auth_string = base64_encode($this->consumer_key . ':' . $this->consumer_secret);
        
        $attempt = 0;
        $max_attempts = $this->max_retries + 1;
        
        do {
            $ch = curl_init();
            $curl_options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . $auth_string,
                    'Content-Type: application/json',
                    'User-Agent: ' . Config::getSystemSetting('app_name', 'WooSM') . '/2.0', // Consider making version dynamic
                    'Accept: application/json',
                    'Cache-Control: no-cache'
                ],
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_SSL_VERIFYPEER => true, // Set to false for local dev if issues, but true for prod
                CURLOPT_SSL_VERIFYHOST => 2,   // Same as above
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3
            ];

            if (strtoupper($method) === 'GET' && !empty($params)) {
                $url .= '?' . http_build_query($params);
            } elseif (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH']) && !empty($params)) {
                $curl_options[CURLOPT_POSTFIELDS] = json_encode($params);
            } elseif (strtoupper($method) === 'DELETE' && !empty($params)) { // Some DELETE requests might have body/query params
                 $url .= '?' . http_build_query($params);
            }


            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt_array($ch, $curl_options);

            $response_content = curl_exec($ch);
            $this->last_status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            $curl_errno = curl_errno($ch);
            
            if ($curl_errno) {
                curl_close($ch);
                
                if (in_array($curl_errno, [CURLE_OPERATION_TIMEOUTED, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST]) && $attempt < $max_attempts - 1) {
                    $attempt++;
                    sleep($this->retry_delay * $attempt);
                    continue;
                }
                
                return $this->formatError('cURL Error (' . $curl_errno . '): ' . $curl_error, 'curl_error');
            }

            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            
            $header_string = substr($response_content, 0, $header_size);
            $body_string = substr($response_content, $header_size);
            $this->last_response_headers = $this->parseHeaders($header_string);
            
            if ($this->last_status_code >= 500 && $attempt < $max_attempts - 1) {
                $attempt++;
                sleep($this->retry_delay * $attempt);
                continue;
            }
            
            break; // Exit loop if successful or non-retryable error
            
        } while ($attempt < $max_attempts);

        $data = json_decode($body_string, true);

        // Check for JSON errors only if the response is expected to be JSON (e.g., status < 300)
        if (json_last_error() !== JSON_ERROR_NONE && !empty($body_string) && $this->last_status_code < 300 && $this->last_status_code !== 204 /* No Content */) {
            return $this->formatError('Invalid JSON response. Error: ' . json_last_error_msg(), 'json_error', null, $body_string);
        }

        if ($this->last_status_code >= 400) {
            $error_message = "API Error (HTTP {$this->last_status_code})";
            if (isset($data['message'])) {
                $error_message = $data['message'];
            } elseif (!empty($body_string) && is_null($data)) { // If body is not JSON but there's content
                $error_message .= ". Response: " . substr(strip_tags($body_string), 0, 250);
            }
            return $this->formatError($error_message, $data['code'] ?? ('http_' . $this->last_status_code), $data);
        }

        $this->logActivity('api_request_success', "API request: {$method} {$endpoint}", 'api', [
            'endpoint' => $endpoint,
            'method' => $method,
            'status_code' => $this->last_status_code,
            'response_size' => strlen($body_string)
        ]);

        return ['success' => true, 'data' => $data, 'headers' => $this->last_response_headers, 'status_code' => $this->last_status_code];
    }

    private function formatError($message, $code = 'generic_error', $data = null, $raw_body = null) {
        $this->last_error = $message;
        $error_response = [
            'success' => false, 
            'error' => $message, 
            'code' => $code, 
            'status_code' => $this->last_status_code, 
            'headers' => $this->last_response_headers
        ];
        
        if ($data !== null) $error_response['data'] = $data;
        if ($raw_body !== null) $error_response['raw_body'] = $raw_body; // Add raw body if provided
        
        $this->logActivity('api_error', "{$message} (Code: {$code}, HTTP Status: {$this->last_status_code})", 'error', [
            'store_id' => $this->store_config_id, 
            'error_data' => $data,
            'last_request_info' => $this->last_request_info
        ]);
        
        return $error_response;
    }
    
    private function parseHeaders($header_string) {
        $headers = [];
        $lines = explode("\r\n", $header_string);
        
        // Handle multiple header blocks (e.g., from redirects or 100 Continue)
        $actual_headers_started = false;
        for ($i = count($lines) -1; $i >=0; $i--) {
            $line = $lines[$i];
            if (preg_match('#^HTTP/([1-9]\.[0-9])\s+(\d{3})#', $line)) {
                 $actual_headers_started = true; // Mark the start of the last header block
            }
            if ($actual_headers_started) {
                if (strpos($line, ':') !== false) {
                    list($key, $value) = explode(':', $line, 2);
                    $headers[trim(strtolower($key))] = trim($value);
                } elseif (preg_match('#^HTTP/([1-9]\.[0-9])\s+(\d{3})\s+(.+)#', $line, $matches)) {
                    if (!isset($headers['http_version'])) { // Take the last (actual) status line
                        $headers['http_version'] = $matches[1];
                        $headers['status_code'] = (int)$matches[2];
                        $headers['status_message'] = $matches[3];
                    }
                }
            }
             if ($actual_headers_started && trim($line) === "") break; // Stop if an empty line is encountered after headers started
        }
        return array_reverse($headers); // Ensure original order if needed, though keys are case-insensitive
    }

    protected function logActivity($action, $description = '', $category = 'api', $metadata = []) {
        Config::logActivity($action, $description, $category, array_merge(['store_id' => $this->store_config_id], $metadata));
    }

    // === PRODUCT METHODS ===

    /**
     * Retrieves a list of products with comprehensive filtering options.
     * 
     * @param array $params Parameters for filtering and pagination.
     *                      - context (string): Scope under which the request is made. Options: view, edit. Default: view.
     *                      - page (integer): Current page of the collection. Default: 1.
     *                      - per_page (integer): Maximum number of items to be returned in result set. Default: 10 (Max: 100).
     *                      - search (string): Limit results to those matching a string.
     *                      - after (string): Limit response to resources published after a given ISO8601 compliant date.
     *                      - before (string): Limit response to resources published before a given ISO8601 compliant date.
     *                      - modified_after (string): Limit response to resources modified after a given ISO8601 compliant date.
     *                      - modified_before (string): Limit response to resources modified before a given ISO8601 compliant date.
     *                      - dates_are_gmt (boolean): Whether to consider GMT post dates when limiting response by published or modified date.
     *                      - exclude (array): Ensure result set excludes specific IDs.
     *                      - include (array): Limit result set to specific IDs.
     *                      - offset (integer): Offset the result set by a specific number of items.
     *                      - order (string): Order sort attribute ascending or descending. Options: asc, desc. Default: desc.
     *                      - orderby (string): Sort collection by object attribute. Options: date, id, include, title, slug, modified, menu_order, price, popularity, rating. Default: date.
     *                      - parent (array): Limit result set to those of particular parent IDs.
     *                      - parent_exclude (array): Limit result set to all items except those of a particular parent ID.
     *                      - slug (string): Limit result set to products with a specific slug.
     *                      - status (string): Limit result set to products assigned a specific status. Options: any, draft, pending, private, publish. Default: any.
     *                      - include_status (string): Limit result set to products with any of the specified statuses (comma-separated). Takes precedence over status. Options: any, future, trash, draft, pending, private, publish.
     *                      - exclude_status (string): Exclude products from result set with any of the specified statuses (comma-separated). Takes precedence over include_status. Options: future, trash, draft, pending, private, publish.
     *                      - type (string): Limit result set to products assigned a specific type. Options: simple, grouped, external, variable.
     *                      - include_types (string): Limit result set to products with any of the types (comma-separated). Takes precedence over type. Options: simple, grouped, external, variable.
     *                      - exclude_types (string): Exclude products from result set with any of the specified types (comma-separated). Takes precedence over include_types. Options: simple, grouped, external, variable.
     *                      - sku (string): Limit result set to products with a specific SKU.
     *                      - featured (boolean): Limit result set to featured products.
     *                      - category (string): Limit result set to products assigned a specific category ID.
     *                      - tag (string): Limit result set to products assigned a specific tag ID.
     *                      - shipping_class (string): Limit result set to products assigned a specific shipping class ID.
     *                      - attribute (string): Limit result set to products with a specific attribute (e.g., pa_color).
     *                      - attribute_term (string): Limit result set to products with a specific attribute term ID (requires assigned attribute).
     *                      - tax_class (string): Limit result set to products with a specific tax class. Default options: standard, reduced-rate, zero-rate.
     *                      - on_sale (boolean): Limit result set to products on sale.
     *                      - min_price (string): Limit result set to products based on a minimum price.
     *                      - max_price (string): Limit result set to products based on a maximum price.
     *                      - stock_status (string): Limit result set to products with specified stock status. Options: instock, outofstock, onbackorder.
     *                      - virtual (boolean): Limit result set to virtual products.
     *                      - downloadable (boolean): Limit result set to downloadable products.
     * @return array API response with products data
     */
    public function getProducts($params = []) {
        return $this->makeRequest('products', $params);
    }

    /**
     * Retrieves a single product by ID.
     * 
     * @param int $product_id Product ID
     * @return array API response with product data
     */
    public function getProduct($product_id) {
        if (empty($product_id) || !is_numeric($product_id)) {
            return $this->formatError('Invalid Product ID provided.', 'invalid_product_id');
        }
        return $this->makeRequest("products/{$product_id}");
    }

    /**
     * Creates a new product.
     * See documentation for all available product properties.
     * 
     * @param array $data Product data. 'name' is typically required. 'type' defaults to 'simple'.
     * @return array API response
     */
    public function createProduct($data) {
        if (empty($data['name'])) {
            return $this->formatError('Product name is required for creation.', 'missing_product_data');
        }
        // 'type' defaults to 'simple' as per docs, so not strictly required here.
        return $this->makeRequest('products', $data, 'POST');
    }

    /**
     * Updates an existing product.
     * 
     * @param int $product_id Product ID
     * @param array $data Product data to update
     * @return array API response
     */
    public function updateProduct($product_id, $data) {
        if (empty($product_id) || !is_numeric($product_id)) {
            return $this->formatError('Invalid Product ID provided for update.', 'invalid_product_id');
        }
        if (empty($data)) {
            return $this->formatError('No data provided for product update.', 'empty_update_data');
        }
        return $this->makeRequest("products/{$product_id}", $data, 'PUT');
    }

    /**
     * Deletes a product.
     * 
     * @param int $product_id Product ID
     * @param bool $force Whether to permanently delete (true) or move to trash (false). Default is false.
     * @return array API response
     */
    public function deleteProduct($product_id, $force = false) {
        if (empty($product_id) || !is_numeric($product_id)) {
            return $this->formatError('Invalid Product ID provided for deletion.', 'invalid_product_id');
        }
        return $this->makeRequest("products/{$product_id}", ['force' => (bool)$force], 'DELETE');
    }

    /**
     * Performs batch operations on products (create, update, delete).
     * Each operation (create, update, delete) is an array of respective product data or IDs.
     * 
     * @param array $data Batch data, e.g., ['create' => [...], 'update' => [...], 'delete' => [...]]
     * @return array API response
     */
    public function batchProducts($data) {
        if (empty($data) || (!isset($data['create']) && !isset($data['update']) && !isset($data['delete']))) {
            return $this->formatError('Batch data must contain at least one of "create", "update", or "delete" arrays.', 'invalid_batch_data');
        }
        return $this->makeRequest('products/batch', $data, 'POST');
    }

    /**
     * Duplicates a product. The new product will be in 'draft' status.
     *
     * @param int $product_id The ID of the product to duplicate.
     * @return array API response with the duplicated product data.
     */
    public function duplicateProduct($product_id) {
        if (empty($product_id) || !is_numeric($product_id)) {
            return $this->formatError('Invalid Product ID provided for duplication.', 'invalid_product_id');
        }
        return $this->makeRequest("products/{$product_id}/duplicate", [], 'POST');
    }

    // === PRODUCT VARIATIONS ===

    /**
     * Retrieves all variations for a variable product.
     * 
     * @param int $product_id Parent product ID
     * @param array $params Parameters for filtering and pagination.
     *                      - context (string): Scope. Options: view, edit. Default: view.
     *                      - page (integer): Current page. Default: 1.
     *                      - per_page (integer): Items per page. Default: 10.
     *                      - search (string): Search term.
     *                      - after (string): ISO8601 date - created after.
     *                      - before (string): ISO8601 date - created before.
     *                      - exclude (array): Variation IDs to exclude.
     *                      - include (array): Variation IDs to include.
     *                      - offset (integer): Offset for pagination.
     *                      - order (string): asc/desc. Default: desc.
     *                      - orderby (string): Sort by. Options: date, id, include, title, slug, modified. Default: date.
     *                      - parent (array): Parent product IDs (though usually just one for this context).
     *                      - parent_exclude (array): Parent IDs to exclude.
     *                      - slug (string): Variation slug.
     *                      - status (string): Variation status. Options: any, draft, pending, private, publish. Default: any.
     *                      - include_status (string): Comma-separated statuses to include.
     *                      - exclude_status (string): Comma-separated statuses to exclude.
     *                      - sku (string): Variation SKU.
     *                      - tax_class (string): Tax class.
     *                      - on_sale (boolean): On sale filter.
     *                      - min_price (string): Minimum price.
     *                      - max_price (string): Maximum price.
     *                      - stock_status (string): Stock status. Options: instock, outofstock, onbackorder.
     *                      - virtual (boolean): Limit to virtual variations.
     *                      - downloadable (boolean): Limit to downloadable variations.
     * @return array API response
     */
    public function getProductVariations($product_id, $params = []) {
        if (empty($product_id) || !is_numeric($product_id)) {
            return $this->formatError('Invalid Parent Product ID for variations.', 'invalid_product_id');
        }
        return $this->makeRequest("products/{$product_id}/variations", $params);
    }

    /**
     * Retrieves a single product variation.
     * 
     * @param int $product_id Parent product ID
     * @param int $variation_id Variation ID
     * @return array API response
     */
    public function getProductVariation($product_id, $variation_id) {
        if (empty($product_id) || !is_numeric($product_id) || empty($variation_id) || !is_numeric($variation_id)) {
            return $this->formatError('Invalid Product or Variation ID.', 'invalid_id');
        }
        return $this->makeRequest("products/{$product_id}/variations/{$variation_id}");
    }

    /**
     * Creates a new product variation.
     * See documentation for available product variation properties.
     * 
     * @param int $product_id Parent product ID
     * @param array $data Variation data. 'attributes' are typically required.
     * @return array API response
     */
    public function createProductVariation($product_id, $data) {
        if (empty($product_id) || !is_numeric($product_id)) {
            return $this->formatError('Invalid Parent Product ID for variation creation.', 'invalid_product_id');
        }
        // Attributes are key for variations.
        if (empty($data['attributes']) || !is_array($data['attributes'])) {
            return $this->formatError('Attributes array is required for creating a variation.', 'missing_variation_attributes');
        }
        return $this->makeRequest("products/{$product_id}/variations", $data, 'POST');
    }

    /**
     * Updates an existing product variation.
     * 
     * @param int $product_id Parent product ID
     * @param int $variation_id Variation ID
     * @param array $data Variation data to update
     * @return array API response
     */
    public function updateProductVariation($product_id, $variation_id, $data) {
        if (empty($product_id) || !is_numeric($product_id) || empty($variation_id) || !is_numeric($variation_id)) {
            return $this->formatError('Invalid Product or Variation ID for update.', 'invalid_id');
        }
        if (empty($data)) {
            return $this->formatError('No data provided for variation update.', 'empty_update_data');
        }
        return $this->makeRequest("products/{$product_id}/variations/{$variation_id}", $data, 'PUT');
    }

    /**
     * Deletes a product variation.
     * Note: 'force' is required to be true as resource does not support trashing.
     * 
     * @param int $product_id Parent product ID
     * @param int $variation_id Variation ID
     * @return array API response
     */
    public function deleteProductVariation($product_id, $variation_id) {
        if (empty($product_id) || !is_numeric($product_id) || empty($variation_id) || !is_numeric($variation_id)) {
            return $this->formatError('Invalid Product or Variation ID for deletion.', 'invalid_id');
        }
        return $this->makeRequest("products/{$product_id}/variations/{$variation_id}", ['force' => true], 'DELETE');
    }

    /**
     * Batch updates product variations.
     * 
     * @param int $product_id Parent product ID
     * @param array $data Batch data for variations (create, update, delete arrays)
     * @return array API response
     */
    public function batchProductVariations($product_id, $data) {
        if (empty($product_id) || !is_numeric($product_id)) {
            return $this->formatError('Invalid Parent Product ID for batch variation update.', 'invalid_product_id');
        }
         if (empty($data) || (!isset($data['create']) && !isset($data['update']) && !isset($data['delete']))) {
            return $this->formatError('Batch variation data must contain at least one of "create", "update", or "delete" arrays.', 'invalid_batch_data');
        }
        return $this->makeRequest("products/{$product_id}/variations/batch", $data, 'POST');
    }

    // === PRODUCT CATEGORIES ===

    /**
     * Retrieves product categories.
     * 
     * @param array $params Parameters for filtering.
     *                      - context (string): Scope. Options: view, edit. Default: view.
     *                      - page (integer): Current page. Default: 1.
     *                      - per_page (integer): Items per page. Default: 10.
     *                      - search (string): Search term.
     *                      - exclude (array): Category IDs to exclude.
     *                      - include (array): Category IDs to include.
     *                      - order (string): asc/desc. Default: asc.
     *                      - orderby (string): Sort by. Options: id, include, name, slug, term_group, description, count. Default: name.
     *                      - hide_empty (boolean): Hide categories with no products. Default: false.
     *                      - parent (integer): Parent category ID.
     *                      - product (integer): Limit to categories associated with a specific product ID.
     *                      - slug (string): Category slug.
     * @return array API response
     */
    public function getProductCategories($params = []) {
        return $this->makeRequest('products/categories', $params);
    }

    /**
     * Retrieves a single product category.
     * 
     * @param int $category_id Category ID
     * @return array API response
     */
    public function getProductCategory($category_id) {
        if (empty($category_id) || !is_numeric($category_id)) {
            return $this->formatError('Invalid Category ID.', 'invalid_id');
        }
        return $this->makeRequest("products/categories/{$category_id}");
    }

    /**
     * Creates a new product category.
     * See documentation for available product category properties.
     * 
     * @param array $data Category data ('name' is mandatory).
     * @return array API response
     */
    public function createProductCategory($data) {
        if (empty($data['name'])) {
            return $this->formatError('Category name is required.', 'missing_category_name');
        }
        return $this->makeRequest('products/categories', $data, 'POST');
    }

    /**
     * Updates a product category.
     * 
     * @param int $category_id Category ID
     * @param array $data Category data to update
     * @return array API response
     */
    public function updateProductCategory($category_id, $data) {
        if (empty($category_id) || !is_numeric($category_id)) {
            return $this->formatError('Invalid Category ID for update.', 'invalid_id');
        }
        if (empty($data)) {
            return $this->formatError('No data provided for category update.', 'empty_update_data');
        }
        return $this->makeRequest("products/categories/{$category_id}", $data, 'PUT');
    }

    /**
     * Deletes a product category.
     * Note: 'force' is required to be true as resource does not support trashing.
     * 
     * @param int $category_id Category ID
     * @return array API response
     */
    public function deleteProductCategory($category_id) {
        if (empty($category_id) || !is_numeric($category_id)) {
            return $this->formatError('Invalid Category ID for deletion.', 'invalid_id');
        }
        return $this->makeRequest("products/categories/{$category_id}", ['force' => true], 'DELETE');
    }

    /**
     * Batch updates product categories.
     * 
     * @param array $data Batch data for categories (create, update, delete arrays)
     * @return array API response
     */
    public function batchProductCategories($data) {
        if (empty($data) || (!isset($data['create']) && !isset($data['update']) && !isset($data['delete']))) {
            return $this->formatError('Batch category data must contain at least one of "create", "update", or "delete" arrays.', 'invalid_batch_data');
        }
        return $this->makeRequest('products/categories/batch', $data, 'POST');
    }

    // === PRODUCT TAGS ===

    /**
     * Retrieves product tags.
     * 
     * @param array $params Parameters for filtering.
     *                      - context (string): Scope. Options: view, edit. Default: view.
     *                      - page (integer): Current page. Default: 1.
     *                      - per_page (integer): Items per page. Default: 10.
     *                      - search (string): Search term.
     *                      - exclude (array): Tag IDs to exclude.
     *                      - include (array): Tag IDs to include.
     *                      - offset (integer): Offset for pagination.
     *                      - order (string): asc/desc. Default: asc.
     *                      - orderby (string): Sort by. Options: id, include, name, slug, term_group, description, count. Default: name.
     *                      - hide_empty (boolean): Hide tags with no products. Default: false.
     *                      - product (integer): Limit to tags associated with a specific product ID.
     *                      - slug (string): Tag slug.
     * @return array API response
     */
    public function getProductTags($params = []) {
        return $this->makeRequest('products/tags', $params);
    }

    /**
     * Retrieves a single product tag.
     * 
     * @param int $tag_id Tag ID
     * @return array API response
     */
    public function getProductTag($tag_id) {
        if (empty($tag_id) || !is_numeric($tag_id)) {
            return $this->formatError('Invalid Tag ID.', 'invalid_id');
        }
        return $this->makeRequest("products/tags/{$tag_id}");
    }

    /**
     * Creates a new product tag.
     * See documentation for available product tag properties.
     * 
     * @param array $data Tag data ('name' is mandatory).
     * @return array API response
     */
    public function createProductTag($data) {
        if (empty($data['name'])) {
            return $this->formatError('Tag name is required.', 'missing_tag_name');
        }
        return $this->makeRequest('products/tags', $data, 'POST');
    }

    /**
     * Updates a product tag.
     * 
     * @param int $tag_id Tag ID
     * @param array $data Tag data to update
     * @return array API response
     */
    public function updateProductTag($tag_id, $data) {
        if (empty($tag_id) || !is_numeric($tag_id)) {
            return $this->formatError('Invalid Tag ID for update.', 'invalid_id');
        }
        if (empty($data)) {
            return $this->formatError('No data provided for tag update.', 'empty_update_data');
        }
        return $this->makeRequest("products/tags/{$tag_id}", $data, 'PUT');
    }

    /**
     * Deletes a product tag.
     * Note: 'force' is required to be true as resource does not support trashing.
     * 
     * @param int $tag_id Tag ID
     * @return array API response
     */
    public function deleteProductTag($tag_id) {
        if (empty($tag_id) || !is_numeric($tag_id)) {
            return $this->formatError('Invalid Tag ID for deletion.', 'invalid_id');
        }
        return $this->makeRequest("products/tags/{$tag_id}", ['force' => true], 'DELETE');
    }

    /**
     * Batch updates product tags.
     * 
     * @param array $data Batch data for tags (create, update, delete arrays)
     * @return array API response
     */
    public function batchProductTags($data) {
         if (empty($data) || (!isset($data['create']) && !isset($data['update']) && !isset($data['delete']))) {
            return $this->formatError('Batch tag data must contain at least one of "create", "update", or "delete" arrays.', 'invalid_batch_data');
        }
        return $this->makeRequest('products/tags/batch', $data, 'POST');
    }

    // === PRODUCT ATTRIBUTES (Global Attributes) ===

    /**
     * Retrieves product attributes (global attributes).
     * 
     * @param array $params Parameters for filtering.
     *                      - context (string): Scope. Options: view, edit. Default: view.
     * @return array API response
     */
    public function getProductAttributes($params = []) {
        // Note: Docs only list 'context' as a parameter for listing attributes.
        return $this->makeRequest('products/attributes', $params);
    }

    /**
     * Retrieves a single product attribute (global attribute).
     * 
     * @param int $attribute_id Attribute ID
     * @return array API response
     */
    public function getProductAttribute($attribute_id) {
        if (empty($attribute_id) || !is_numeric($attribute_id)) {
            return $this->formatError('Invalid Attribute ID.', 'invalid_id');
        }
        return $this->makeRequest("products/attributes/{$attribute_id}");
    }

    /**
     * Creates a new product attribute (global attribute).
     * See documentation for product attribute properties (name, slug, type, order_by, has_archives).
     * 
     * @param array $data Attribute data ('name' is mandatory).
     * @return array API response
     */
    public function createProductAttribute($data) {
        if (empty($data['name'])) {
            return $this->formatError('Attribute name is required.', 'missing_attribute_name');
        }
        return $this->makeRequest('products/attributes', $data, 'POST');
    }

    /**
     * Updates a product attribute (global attribute).
     * 
     * @param int $attribute_id Attribute ID
     * @param array $data Attribute data to update
     * @return array API response
     */
    public function updateProductAttribute($attribute_id, $data) {
        if (empty($attribute_id) || !is_numeric($attribute_id)) {
            return $this->formatError('Invalid Attribute ID for update.', 'invalid_id');
        }
         if (empty($data)) {
            return $this->formatError('No data provided for attribute update.', 'empty_update_data');
        }
        return $this->makeRequest("products/attributes/{$attribute_id}", $data, 'PUT');
    }

    /**
     * Deletes a product attribute (global attribute). This also deletes all terms from the attribute.
     * Note: 'force' is required to be true as resource does not support trashing.
     * 
     * @param int $attribute_id Attribute ID
     * @return array API response
     */
    public function deleteProductAttribute($attribute_id) {
        if (empty($attribute_id) || !is_numeric($attribute_id)) {
            return $this->formatError('Invalid Attribute ID for deletion.', 'invalid_id');
        }
        return $this->makeRequest("products/attributes/{$attribute_id}", ['force' => true], 'DELETE');
    }

    /**
     * Batch updates product attributes (global attributes).
     * 
     * @param array $data Batch data for attributes (create, update, delete arrays)
     * @return array API response
     */
    public function batchProductAttributes($data) {
        if (empty($data) || (!isset($data['create']) && !isset($data['update']) && !isset($data['delete']))) {
            return $this->formatError('Batch attribute data must contain at least one of "create", "update", or "delete" arrays.', 'invalid_batch_data');
        }
        return $this->makeRequest('products/attributes/batch', $data, 'POST');
    }

    // === PRODUCT ATTRIBUTE TERMS ===

    /**
     * Retrieves product attribute terms for a given attribute.
     * 
     * @param int $attribute_id Attribute ID
     * @param array $params Parameters for filtering.
     *                      - context (string): Scope. Options: view, edit. Default: view.
     *                      - page (integer): Current page. Default: 1.
     *                      - per_page (integer): Items per page. Default: 10.
     *                      - search (string): Search term.
     *                      - exclude (array): Term IDs to exclude.
     *                      - include (array): Term IDs to include.
     *                      - order (string): asc/desc. Default: asc.
     *                      - orderby (string): Sort by. Options: id, include, name, slug, term_group, description, count. Default: name.
     *                      - hide_empty (boolean): Hide terms with no products. Default: false.
     *                      - parent (integer): Parent term ID (for hierarchical taxonomies, though not typical for product attributes).
     *                      - product (integer): Limit to terms associated with a specific product ID.
     *                      - slug (string): Term slug.
     * @return array API response
     */
    public function getProductAttributeTerms($attribute_id, $params = []) {
        if (empty($attribute_id) || !is_numeric($attribute_id)) {
            return $this->formatError('Invalid Attribute ID for terms.', 'invalid_id');
        }
        return $this->makeRequest("products/attributes/{$attribute_id}/terms", $params);
    }

    /**
     * Retrieves a single product attribute term.
     * 
     * @param int $attribute_id Attribute ID
     * @param int $term_id Term ID
     * @return array API response
     */
    public function getProductAttributeTerm($attribute_id, $term_id) {
        if (empty($attribute_id) || !is_numeric($attribute_id) || empty($term_id) || !is_numeric($term_id)) {
            return $this->formatError('Invalid Attribute or Term ID.', 'invalid_id');
        }
        return $this->makeRequest("products/attributes/{$attribute_id}/terms/{$term_id}");
    }

    /**
     * Creates a new product attribute term.
     * See documentation for product attribute term properties (name, slug, description, menu_order).
     * 
     * @param int $attribute_id Attribute ID
     * @param array $data Term data ('name' is mandatory).
     * @return array API response
     */
    public function createProductAttributeTerm($attribute_id, $data) {
        if (empty($attribute_id) || !is_numeric($attribute_id)) {
            return $this->formatError('Invalid Attribute ID for term creation.', 'invalid_id');
        }
        if (empty($data['name'])) {
            return $this->formatError('Attribute term name is required.', 'missing_term_name');
        }
        return $this->makeRequest("products/attributes/{$attribute_id}/terms", $data, 'POST');
    }

    /**
     * Updates a product attribute term.
     * 
     * @param int $attribute_id Attribute ID
     * @param int $term_id Term ID
     * @param array $data Term data to update
     * @return array API response
     */
    public function updateProductAttributeTerm($attribute_id, $term_id, $data) {
        if (empty($attribute_id) || !is_numeric($attribute_id) || empty($term_id) || !is_numeric($term_id)) {
            return $this->formatError('Invalid Attribute or Term ID for update.', 'invalid_id');
        }
        if (empty($data)) {
            return $this->formatError('No data provided for term update.', 'empty_update_data');
        }
        return $this->makeRequest("products/attributes/{$attribute_id}/terms/{$term_id}", $data, 'PUT');
    }

    /**
     * Deletes a product attribute term.
     * Note: 'force' is required to be true as resource does not support trashing.
     * 
     * @param int $attribute_id Attribute ID
     * @param int $term_id Term ID
     * @return array API response
     */
    public function deleteProductAttributeTerm($attribute_id, $term_id) {
        if (empty($attribute_id) || !is_numeric($attribute_id) || empty($term_id) || !is_numeric($term_id)) {
            return $this->formatError('Invalid Attribute or Term ID for deletion.', 'invalid_id');
        }
        return $this->makeRequest("products/attributes/{$attribute_id}/terms/{$term_id}", ['force' => true], 'DELETE');
    }

    /**
     * Batch updates product attribute terms.
     * 
     * @param int $attribute_id Attribute ID
     * @param array $data Batch data for terms (create, update, delete arrays)
     * @return array API response
     */
    public function batchProductAttributeTerms($attribute_id, $data) {
        if (empty($attribute_id) || !is_numeric($attribute_id)) {
            return $this->formatError('Invalid Attribute ID for batch term update.', 'invalid_id');
        }
        if (empty($data) || (!isset($data['create']) && !isset($data['update']) && !isset($data['delete']))) {
            return $this->formatError('Batch attribute term data must contain at least one of "create", "update", or "delete" arrays.', 'invalid_batch_data');
        }
        return $this->makeRequest("products/attributes/{$attribute_id}/terms/batch", $data, 'POST');
    }

    // === PRODUCT SHIPPING CLASSES ===

    /**
     * Retrieves product shipping classes.
     * 
     * @param array $params Parameters for filtering.
     *                      - context (string): Scope. Options: view, edit. Default: view.
     *                      - page (integer): Current page. Default: 1.
     *                      - per_page (integer): Items per page. Default: 10.
     *                      - search (string): Search term.
     *                      - exclude (array): Shipping class IDs to exclude.
     *                      - include (array): Shipping class IDs to include.
     *                      - offset (integer): Offset for pagination.
     *                      - order (string): asc/desc. Default: asc.
     *                      - orderby (string): Sort by. Options: id, include, name, slug, term_group, description, count. Default: name.
     *                      - hide_empty (boolean): Hide shipping classes not assigned to any products. Default: false.
     *                      - product (integer): Limit to shipping classes associated with a specific product ID.
     *                      - slug (string): Shipping class slug.
     * @return array API response
     */
    public function getProductShippingClasses($params = []) {
        return $this->makeRequest('products/shipping_classes', $params);
    }

    /**
     * Retrieves a single product shipping class.
     * 
     * @param int $shipping_class_id Shipping class ID
     * @return array API response
     */
    public function getProductShippingClass($shipping_class_id) {
        if (empty($shipping_class_id) || !is_numeric($shipping_class_id)) {
            return $this->formatError('Invalid Shipping Class ID.', 'invalid_id');
        }
        return $this->makeRequest("products/shipping_classes/{$shipping_class_id}");
    }

    /**
     * Creates a new product shipping class.
     * See documentation for product shipping class properties (name, slug, description).
     * 
     * @param array $data Shipping class data ('name' is mandatory).
     * @return array API response
     */
    public function createProductShippingClass($data) {
        if (empty($data['name'])) {
            return $this->formatError('Shipping class name is required.', 'missing_shipping_class_name');
        }
        return $this->makeRequest('products/shipping_classes', $data, 'POST');
    }

    /**
     * Updates a product shipping class.
     * 
     * @param int $shipping_class_id Shipping class ID
     * @param array $data Shipping class data to update
     * @return array API response
     */
    public function updateProductShippingClass($shipping_class_id, $data) {
        if (empty($shipping_class_id) || !is_numeric($shipping_class_id)) {
            return $this->formatError('Invalid Shipping Class ID for update.', 'invalid_id');
        }
        if (empty($data)) {
            return $this->formatError('No data provided for shipping class update.', 'empty_update_data');
        }
        return $this->makeRequest("products/shipping_classes/{$shipping_class_id}", $data, 'PUT');
    }

    /**
     * Deletes a product shipping class.
     * Note: 'force' is required to be true as resource does not support trashing.
     * 
     * @param int $shipping_class_id Shipping class ID
     * @return array API response
     */
    public function deleteProductShippingClass($shipping_class_id) {
        if (empty($shipping_class_id) || !is_numeric($shipping_class_id)) {
            return $this->formatError('Invalid Shipping Class ID for deletion.', 'invalid_id');
        }
        return $this->makeRequest("products/shipping_classes/{$shipping_class_id}", ['force' => true], 'DELETE');
    }

    /**
     * Batch updates product shipping classes.
     * 
     * @param array $data Batch data for shipping classes (create, update, delete arrays)
     * @return array API response
     */
    public function batchProductShippingClasses($data) {
        if (empty($data) || (!isset($data['create']) && !isset($data['update']) && !isset($data['delete']))) {
            return $this->formatError('Batch shipping class data must contain at least one of "create", "update", or "delete" arrays.', 'invalid_batch_data');
        }
        return $this->makeRequest('products/shipping_classes/batch', $data, 'POST');
    }

    // === PRODUCT CUSTOM FIELDS ===

    /**
     * Retrieves a list of custom field names that have been recorded for products.
     *
     * @param array $params Parameters for filtering.
     *                      - context (string): Scope under which the request is made. Options: view, edit. Default: view.
     *                      - page (integer): Current page of the collection. Default: 1.
     *                      - per_page (integer): Maximum number of items to be returned in result set. Default: 10.
     *                      - search (string): Limit results to those matching a string.
     *                      - order (string): Order sort attribute ascending or descending. Options: asc, desc. Default: desc.
     * @return array API response containing an array of custom field name strings.
     */
    public function getProductCustomFieldNames($params = []) {
        return $this->makeRequest('products/custom-fields/names', $params);
    }

    // === ORDERS ===

    /**
     * Retrieves orders.
     * 
     * @param array $params Parameters for filtering
     * @return array API response
     */
    public function getOrders($params = []) {
        return $this->makeRequest('orders', $params);
    }

    /**
     * Retrieves a single order.
     * 
     * @param int $id Order ID
     * @return array API response
     */
    public function getOrder($id) {
        return $this->makeRequest("orders/{$id}");
    }

    /**
     * Creates a new order.
     * 
     * @param array $data Order data
     * @return array API response
     */
    public function createOrder($data) {
        return $this->makeRequest('orders', $data, 'POST');
    }

    /**
     * Updates an order.
     * 
     * @param int $id Order ID
     * @param array $data Order data to update
     * @return array API response
     */
    public function updateOrder($id, $data) {
        return $this->makeRequest("orders/{$id}", $data, 'PUT');
    }

    /**
     * Deletes an order.
     * 
     * @param int $id Order ID
     * @param bool $force Whether to permanently delete
     * @return array API response
     */
    public function deleteOrder($id, $force = false) {
        return $this->makeRequest("orders/{$id}", ['force' => (bool)$force], 'DELETE');
    }

    /**
     * Batch updates orders.
     * 
     * @param array $data Batch data
     * @return array API response
     */
    public function batchOrders($data) {
        return $this->makeRequest('orders/batch', $data, 'POST');
    }

    // === ORDER NOTES ===

    /**
     * Retrieves order notes.
     * 
     * @param int $order_id Order ID
     * @param array $params Parameters for filtering
     * @return array API response
     */
    public function getOrderNotes($order_id, $params = []) {
        return $this->makeRequest("orders/{$order_id}/notes", $params);
    }

    /**
     * Retrieves a single order note.
     * 
     * @param int $order_id Order ID
     * @param int $note_id Note ID
     * @return array API response
     */
    public function getOrderNote($order_id, $note_id) {
        return $this->makeRequest("orders/{$order_id}/notes/{$note_id}");
    }

    /**
     * Creates a new order note.
     * 
     * @param int $order_id Order ID
     * @param array $data Note data
     * @return array API response
     */
    public function createOrderNote($order_id, $data) {
        return $this->makeRequest("orders/{$order_id}/notes", $data, 'POST');
    }

    /**
     * Deletes an order note.
     * 
     * @param int $order_id Order ID
     * @param int $note_id Note ID
     * @param bool $force Whether to permanently delete
     * @return array API response
     */
    public function deleteOrderNote($order_id, $note_id, $force = false) {
        return $this->makeRequest("orders/{$order_id}/notes/{$note_id}", ['force' => (bool)$force], 'DELETE');
    }

    // === CUSTOMERS ===

    /**
     * Retrieves customers.
     * 
     * @param array $params Parameters for filtering
     * @return array API response
     */
    public function getCustomers($params = []) {
        return $this->makeRequest('customers', $params);
    }

    /**
     * Retrieves a single customer.
     * 
     * @param int $id Customer ID
     * @return array API response
     */
    public function getCustomer($id) {
        return $this->makeRequest("customers/{$id}");
    }

    /**
     * Creates a new customer.
     * 
     * @param array $data Customer data
     * @return array API response
     */
    public function createCustomer($data) {
        return $this->makeRequest('customers', $data, 'POST');
    }

    /**
     * Updates a customer.
     * 
     * @param int $id Customer ID
     * @param array $data Customer data to update
     * @return array API response
     */
    public function updateCustomer($id, $data) {
        return $this->makeRequest("customers/{$id}", $data, 'PUT');
    }

    /**
     * Deletes a customer.
     * 
     * @param int $id Customer ID
     * @param bool $force Whether to permanently delete
     * @return array API response
     */
    public function deleteCustomer($id, $force = false) {
        return $this->makeRequest("customers/{$id}", ['force' => (bool)$force], 'DELETE');
    }

    /**
     * Batch updates customers.
     * 
     * @param array $data Batch data
     * @return array API response
     */
    public function batchCustomers($data) {
        return $this->makeRequest('customers/batch', $data, 'POST');
    }

    // === COUPONS ===

    /**
     * Retrieves coupons.
     * 
     * @param array $params Parameters for filtering
     * @return array API response
     */
    public function getCoupons($params = []) {
        return $this->makeRequest('coupons', $params);
    }

    /**
     * Retrieves a single coupon.
     * 
     * @param int $id Coupon ID
     * @return array API response
     */
    public function getCoupon($id) {
        return $this->makeRequest("coupons/{$id}");
    }

    /**
     * Creates a new coupon.
     * 
     * @param array $data Coupon data
     * @return array API response
     */
    public function createCoupon($data) {
        return $this->makeRequest('coupons', $data, 'POST');
    }

    /**
     * Updates a coupon.
     * 
     * @param int $id Coupon ID
     * @param array $data Coupon data to update
     * @return array API response
     */
    public function updateCoupon($id, $data) {
        return $this->makeRequest("coupons/{$id}", $data, 'PUT');
    }

    /**
     * Deletes a coupon.
     * 
     * @param int $id Coupon ID
     * @param bool $force Whether to permanently delete
     * @return array API response
     */
    public function deleteCoupon($id, $force = false) {
        return $this->makeRequest("coupons/{$id}", ['force' => (bool)$force], 'DELETE');
    }

    /**
     * Batch updates coupons.
     * 
     * @param array $data Batch data
     * @return array API response
     */
    public function batchCoupons($data) {
        return $this->makeRequest('coupons/batch', $data, 'POST');
    }

    // === REPORTS ===

    /**
     * Retrieves sales reports.
     * 
     * @param array $params Parameters for filtering
     * @return array API response
     */
    public function getSalesReport($params = []) {
        return $this->makeRequest('reports/sales', $params);
    }

    /**
     * Retrieves top sellers report.
     * 
     * @param array $params Parameters for filtering
     * @return array API response
     */
    public function getTopSellersReport($params = []) {
        return $this->makeRequest('reports/top_sellers', $params);
    }

    /**
     * Retrieves totals for different report types (e.g., orders, products, customers, coupons, reviews).
     * 
     * @param string $reportType Type of report totals (e.g., 'orders', 'products', 'customers', 'coupons', 'reviews')
     * @return array API response
     */
    public function getReportTotals($reportType = 'orders') {
        // The documentation seems to show `reports/reviews/totals` specifically.
        // For broader report totals, the WooCommerce REST API documentation might have changed.
        // For now, sticking to a generic pattern if possible, or a specific one if only that is documented.
        // The documentation provided in the prompt specifically mentions `reports/reviews/totals`.
        // For other reports like sales, top_sellers, they are separate endpoints.
        // If `reports/{type}/totals` is a general pattern, it would be:
        // return $this->makeRequest("reports/{$reportType}/totals");
        // Given the prompt data, only reviews/totals is explicitly mentioned.
        if ($reportType === 'reviews') {
             return $this->makeRequest('reports/reviews/totals');
        }
        return $this->formatError("Report type '{$reportType}/totals' not explicitly supported or documented in this client version based on provided info.", 'unsupported_report_type');
    }


    // === SYSTEM & SETTINGS ===

    /**
     * Retrieves system status.
     * 
     * @return array API response
     */
    public function getSystemStatus() {
        return $this->makeRequest('system_status');
    }

    /**
     * Retrieves settings.
     * 
     * @param string $group_id Settings group ID (e.g., 'general', 'products', 'tax', 'shipping', 'accounts', 'email', 'advanced').
     *                       If empty, retrieves all settings groups.
     * @return array API response
     */
    public function getSettings($group_id = '') {
        $endpoint = $group_id ? "settings/{$group_id}" : 'settings';
        return $this->makeRequest($endpoint);
    }
    
    /**
     * Retrieves a specific setting from a group.
     *
     * @param string $group_id The ID of the settings group.
     * @param string $setting_id The ID of the specific setting.
     * @return array API response
     */
    public function getSettingOption($group_id, $setting_id) {
        if (empty($group_id) || empty($setting_id)) {
            return $this->formatError('Group ID and Setting ID are required.', 'missing_setting_params');
        }
        return $this->makeRequest("settings/{$group_id}/{$setting_id}");
    }

    /**
     * Updates one or more settings in a group.
     *
     * @param string $group_id The ID of the settings group.
     * @param array $data An array of setting objects to update. Each object should have an 'id' and 'value'.
     * @return array API response
     */
    public function updateSettingsGroup($group_id, $data) {
         if (empty($group_id)) {
            return $this->formatError('Group ID is required.', 'missing_setting_group_id');
        }
        if (empty($data) || !isset($data['settings']) || !is_array($data['settings'])) {
             // The API expects a payload like: { "settings": [ { "id": "wc_shop_page_id", "value": "5" } ] }
             // Or for batch update of single options: POST /wp-json/wc/v3/settings/<group_id>/batch
             // This function seems to aim for updating multiple options in one go for a group.
             // The typical way is PUT settings/<group_id>/<setting_id> or POST settings/<group_id>/batch
             // Let's assume this is for batch update of settings within a group.
            return $this->formatError('Settings data array is required for update.', 'empty_update_data');
        }
        // The batch update for settings is POST /wp-json/wc/v3/settings/<group_id>/batch
        // with a body like { "update": [ { "id": "setting_id", "value": "new_value" } ] }
        // The current method signature suggests sending settings directly. This might need adjustment based on actual API.
        // For now, I'll assume it uses the batch endpoint structure for updates within a group.
        return $this->makeRequest("settings/{$group_id}/batch", ['update' => $data], 'POST');
    }

    /**
     * Updates a single setting option.
     *
     * @param string $group_id The ID of the settings group.
     * @param string $setting_id The ID of the setting to update.
     * @param array $data The data for the setting, usually ['value' => 'new_value'].
     * @return array API response
     */
    public function updateSettingOption($group_id, $setting_id, $data) {
        if (empty($group_id) || empty($setting_id)) {
            return $this->formatError('Group ID and Setting ID are required.', 'missing_setting_params');
        }
         if (empty($data) || !isset($data['value'])) {
            return $this->formatError('Setting value is required for update.', 'missing_setting_value');
        }
        return $this->makeRequest("settings/{$group_id}/{$setting_id}", $data, 'PUT');
    }


    /**
     * Tests the API connection.
     * 
     * @return array Connection test result
     */
    public function testConnection() {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'API credentials not configured'];
        }

        if (!$this->checkRateLimit('test')) { // Use a specific group for test connection
            return ['success' => false, 'error' => 'Rate limit exceeded for connection test'];
        }

        try {
            // Using system_status as a lightweight endpoint for testing
            $response = $this->makeRequest('system_status', [], 'GET', false); // `false` to bypass specific permissions for this test
            
            if ($response['success']) {
                if (class_exists('Database') && $this->store_config_id) {
                    $db = Database::getInstance();
                    $db->execute('
                        UPDATE user_configs SET connected = 1, last_test = ?, connection_errors = NULL 
                        WHERE id = ?
                    ', [date('Y-m-d H:i:s'), $this->store_config_id]);
                }
                $this->logActivity('api_test_success', 'WooCommerce API connection test successful');
                return ['success' => true, 'message' => 'Connection successful', 'data' => $response['data'] ?? null];
            } else {
                if (class_exists('Database') && $this->store_config_id) {
                    $error_data = json_encode(['error' => $response['error'], 'code' => $response['code'] ?? null, 'status_code' => $response['status_code'] ?? null, 'timestamp' => date('Y-m-d H:i:s')]);
                    $db = Database::getInstance();
                    $db->execute('
                        UPDATE user_configs SET connected = 0, connection_errors = ? 
                        WHERE id = ?
                    ', [$error_data, $this->store_config_id]);
                }
                $this->logActivity('api_test_failed', 'WooCommerce API connection test failed: ' . $response['error']);
                return $response;
            }
        } catch (Exception $e) {
            if (class_exists('Database') && $this->store_config_id) {
                $error_data = json_encode(['error' => $e->getMessage(), 'timestamp' => date('Y-m-d H:i:s')]);
                $db = Database::getInstance();
                $db->execute('
                    UPDATE user_configs SET connected = 0, connection_errors = ? 
                    WHERE id = ?
                ', [$error_data, $this->store_config_id]);
            }
            $this->logActivity('api_test_error', 'WooCommerce API connection error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // === HELPER METHODS ===

    /**
     * Extracts total count from response headers.
     * 
     * @return int|null Total count or null if not available
     */
    public function getTotalCountFromHeaders() {
        return isset($this->last_response_headers['x-wp-total']) ? (int)$this->last_response_headers['x-wp-total'] : null;
    }

    /**
     * Extracts total pages from response headers.
     * 
     * @return int|null Total pages or null if not available
     */
    public function getTotalPagesFromHeaders() {
        return isset($this->last_response_headers['x-wp-totalpages']) ? (int)$this->last_response_headers['x-wp-totalpages'] : null;
    }

    /**
     * Gets the last error message.
     * 
     * @return string|null Last error or null if no error
     */
    public function getLastError() {
        return $this->last_error;
    }

    /**
     * Gets the last HTTP status code.
     * 
     * @return int|null Status code or null if no request made
     */
    public function getLastStatusCode() {
        return $this->last_status_code;
    }

    /**
     * Gets the last response headers.
     * 
     * @return array Response headers
     */
    public function getLastResponseHeaders() {
        return $this->last_response_headers;
    }

    /**
     * Gets the last request information.
     * 
     * @return array Request information
     */
    public function getLastRequestInfo() {
        return $this->last_request_info;
    }

    /**
     * Gets API information.
     * 
     * @return array API configuration info
     */
    public function getApiInfo() {
        return [
            'base_url' => $this->api_base_url,
            'api_version' => $this->api_version,
            'configured' => $this->isConfigured(),
            'store_id' => $this->store_config_id,
            'store_name' => $this->store_config['store_name'] ?? '',
            'user_role' => $this->store_config['effective_role'] ?? ($this->user_id == ($this->store_config['user_id'] ?? null) ? 'owner' : 'unknown'),
            'timeout' => $this->timeout,
            'rate_limit_requests' => $this->rate_limit_requests,
            'rate_limit_window_seconds' => $this->rate_limit_window,
            'max_retries' => $this->max_retries,
            'retry_delay_seconds' => $this->retry_delay
        ];
    }

    /**
     * Gets diagnostic information.
     * 
     * @return array Diagnostic data
     */
    public function getDiagnostics() {
        return [
            'api_info' => $this->getApiInfo(),
            'last_request' => $this->last_request_info,
            'last_error' => $this->last_error,
            'last_status_code' => $this->last_status_code,
            'last_response_headers' => $this->last_response_headers,
            'permissions_check' => [
                'can_read' => $this->hasPermission('read'),
                'can_write' => $this->hasPermission('write'),
                'can_delete' => $this->hasPermission('delete'),
                'can_manage_settings' => $this->hasPermission('manage_settings'),
            ]
        ];
    }
}
?>