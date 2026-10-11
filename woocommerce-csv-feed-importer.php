<?php
/**
 * Plugin Name: WooCommerce CSV Product Feed Importer
 * Description: Preview-first WooCommerce CSV importer with automatic mapping, saved presets, dry-run summaries and batched imports.
 * Version: 1.5.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */
if (!defined('ABSPATH')) { exit; }

final class WC_CSV_Product_Feed_Importer {
    private static $instance;
    private $batch_size = 10; // Reduced from 20 to prevent timeout
    private $fields = ['name'=>'ProductName','sku'=>'ProductCode','category'=>'Category','description'=>'ProductSummary','price'=>'Price','stock'=>'AvailableQty','image'=>'Image'];

    public static function instance() { 
        return self::$instance ?: (self::$instance = new self()); 
    }

    private function __construct() {
        add_action('admin_menu', [$this,'admin_menu']);
        add_action('admin_post_wc_csv_product_feed_import', [$this,'handle_import']);
        add_action('admin_post_wc_csv_product_feed_confirm', [$this,'confirm_import']);
        add_action('wp_ajax_wc_csv_feed_batch', [$this,'ajax_batch']);
    }

    public function admin_menu() { 
        add_submenu_page('woocommerce','CSV Feed Importer','CSV Feed Importer','manage_woocommerce','wc-csv-feed-importer',[$this,'page']); 
    }

    public function page() {
        if (!current_user_can('manage_woocommerce')) return;
        $preview = sanitize_key(wp_unslash($_GET['preview'] ?? ''));
        $job = sanitize_key(wp_unslash($_GET['job'] ?? ''));
        echo '<div class="wrap"><h1>WooCommerce CSV Product Feed Importer</h1>';
        $notice = sanitize_key(wp_unslash($_GET['notice'] ?? ''));
        if ('error' === $notice) echo '<div class="notice notice-error"><p>Could not read the CSV feed or the preview expired.</p></div>';
        if ('success' === $notice) echo '<div class="notice notice-success"><p>' . esc_html(sprintf('%d product(s) imported.',absint($_GET['imported'] ?? 0))) . '</p></div>';
        if ('partial' === $notice) echo '<div class="notice notice-warning"><p>' . esc_html(sprintf('%d product(s) imported; some rows failed. Check the PHP error log.',absint($_GET['imported'] ?? 0))) . '</p></div>';
        if ($job) $this->render_progress($job); 
        elseif ($preview) $this->render_preview($preview); 
        else $this->render_form();
        echo '</div>';
    }

    private function render_form() {
        $saved = get_user_meta(get_current_user_id(),'wc_csv_feed_mapping',true);
        $mapping = is_array($saved) ? wp_parse_args($saved,$this->fields) : $this->fields;
        echo '<p>Upload the feed, review the automatic mapping and dry-run summary, then import in batches of ' . absint($this->batch_size) . '.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data">';
        wp_nonce_field('wc_csv_product_feed_import'); 
        echo '<input type="hidden" name="action" value="wc_csv_product_feed_import">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>CSV Feed URL</th><td><input type="url" class="regular-text" name="csv_feed_url" placeholder="https://example.com/products.csv"><p class="description">Use this or upload a file below.</p></td></tr>';
        echo '<tr><th>CSV File</th><td><input type="file" name="csv_file" accept=".csv"></td></tr>';
        echo '<tr><th colspan="2">Field Mapping</th></tr>';
        foreach ($this->fields as $field=>$default) {
            echo '<tr><th>'.esc_html(ucwords(str_replace('_',' ',$field))).'</th><td>';
            echo '<input type="text" class="regular-text" name="mapping['.esc_attr($field).']" placeholder="'.esc_attr($default).'" value="'.esc_attr($mapping[$field] ?? $default).'">';
            echo '</td></tr>';
        }
        echo '<tr><td colspan="2"><label><input type="checkbox" name="save_mapping" value="1"> Save this mapping as my default</label></td></tr>';
        echo '<tr><th>Default category</th><td><input class="regular-text" name="default_category" placeholder="Optional"></td></tr>';
        echo '<tr><th>Product status</th><td><select name="product_status">';
        foreach(['publish'=>'Published','draft'=>'Draft','pending'=>'Pending','private'=>'Private'] as $v=>$label) {
            echo '<option value="'.esc_attr($v).'">'.esc_html($label).'</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th>Preview row limit</th><td><input type="number" name="preview_limit" min="1" max="1000" value="100" class="small-text"></td></tr>';
        echo '</tbody></table>'; 
        submit_button('Preview Import');
        echo '</form>';
    }

    private function render_preview($token) {
        $data = get_transient($this->key($token));
        if (!is_array($data) || empty($data['rows'])) { 
            echo '<div class="notice notice-error"><p>This preview expired. Please start again.</p></div>'; 
            return; 
        }
        $rows = $data['rows'];
        $s = $data['summary'];
        $limit = max(1, min(1000, absint($data['limit'])));
        echo '<h2>Import Preview</h2>';
        echo '<p>Showing '.esc_html(min(count($rows), $limit)).' of '.esc_html(count($rows)).' rows. No products have been saved.</p>';
        echo '<p><strong>Detected mapping:</strong> '.esc_html(json_encode($data['mapping'])).'</p>';
        echo '<div class="postbox"><h2 class="hndle">Summary</h2><div class="inside"><ul>';
        foreach(['Rows skipped'=>'skipped','Duplicate SKUs'=>'duplicates','Invalid prices'=>'invalid_prices','Missing image URLs'=>'missing_images','Would create'=>'create','Would update'=>'update'] as $label=>$key) {
            echo '<li>'.esc_html($label.': '.absint($s[$key])).'</li>';
        }
        echo '</ul></div></div>';
        echo '<table class="widefat striped"><thead><tr><th>#</th><th>Product</th><th>SKU</th><th>Category</th><th>Price</th><th>Quantity</th><th>Image</th></tr></thead><tbody>';
        foreach(array_slice($rows, 0, $limit) as $i => $row) {
            echo '<tr><td>'.esc_html($i+1).'</td><td>'.esc_html($row['name']).'</td><td>'.esc_html($row['sku']).'</td><td>'.esc_html($row['category']).'</td>';
            echo '<td>'.esc_html(number_format((float)($row['price']??0), 2)).'</td><td>'.esc_html($row['stock']).'</td>';
            echo '<td>'.($row['image'] ? '<a href="'.esc_url($row['image']).'" target="_blank">View</a>' : '—').'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><strong>Status:</strong> '.esc_html($data['status']).' &nbsp; <strong>Default category:</strong> '.esc_html($data['category'] ?: 'None').'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('wc_csv_feed_confirm_'.$token); 
        echo '<input type="hidden" name="action" value="wc_csv_product_feed_confirm">';
        echo '<input type="hidden" name="preview_token" value="'.esc_attr($token).'">';
        submit_button('Begin Import');
        echo '</form>';
    }

    private function render_progress($job) {
        $data = get_transient($this->job_key($job));
        if (!is_array($data)) { 
            echo '<div class="notice notice-error"><p>This import job expired.</p></div>'; 
            return; 
        }
        echo '<h2>Importing products</h2>';
        echo '<div style="max-width:760px;background:#dcdcde;height:28px;border-radius:4px;overflow:hidden">';
        echo '<div id="wc-csv-progress-bar" style="height:28px;width:0;background:#0073aa"></div></div>';
        echo '<p id="wc-csv-progress-text">Initializing...</p>';
        echo '<script>
let batch = 0, total = '.absint($data['total']).', job = "'.esc_attr($job).'", batchSize = '.absint($this->batch_size).';
function next(){
    if(batch >= Math.ceil(total / batchSize)){
        document.location = document.location.href;
        return;
    }
    let formData = new FormData();
    formData.append("action", "wc_csv_feed_batch");
    formData.append("job", job);
    formData.append("batch", batch);
    formData.append("_ajax_nonce", "'.esc_attr(wp_create_nonce('wc_csv_batch')).'");
    
    fetch(ajaxurl, {
        method: "POST",
        body: formData
    }).then(r => r.json()).then(r => {
        let pct = Math.round(batch / Math.ceil(total / batchSize) * 100);
        document.getElementById("wc-csv-progress-bar").style.width = pct + "%";
        document.getElementById("wc-csv-progress-text").textContent = "Batch " + (batch + 1) + " of " + Math.ceil(total / batchSize) + "...";
        if(r.success) {
            batch++;
            setTimeout(next, 1000);
        } else {
            console.error("Batch error:", r.data);
            document.getElementById("wc-csv-progress-text").textContent = "Error: " + (r.data || "Unknown error");
        }
    }).catch(e => {
        console.error("Fetch error:", e);
        document.location.href = "?page=wc-csv-feed-importer&notice=error";
    });
}
next();
</script>';
    }

    public function handle_import() {
        if(!current_user_can('manage_woocommerce'))wp_die('Permission denied.'); 
        check_admin_referer('wc_csv_product_feed_import'); 
        $mapping = $this->mapping($_POST['mapping'] ?? []);
        $rows = [];
        $file = $_FILES['csv_file'] ?? [];
        
        if(!empty($file['error']) && UPLOAD_ERR_NO_FILE !== (int)$file['error'])
            wp_die('CSV upload failed.');
        
        if(!empty($file['tmp_name']) && is_uploaded_file($file['tmp_name']))
            $rows = $this->read_csv($file['tmp_name'], $mapping);
        else {
            $url = esc_url_raw(wp_unslash($_POST['csv_feed_url'] ?? ''));
            if($url){
                $response = wp_remote_get($url);
                if(!is_wp_error($response) && '200' == wp_remote_retrieve_response_code($response)){
                    $body = wp_remote_retrieve_body($response);
                    $f = tmpfile();
                    fwrite($f, $body);
                    rewind($f);
                    $path = stream_get_meta_data($f)['uri'];
                    $rows = $this->read_csv($path, $mapping);
                    fclose($f);
                }
            }
        }
        
        if(!$rows)$this->go(['notice'=>'error']); 
        if(!empty($_POST['save_mapping']))
            update_user_meta(get_current_user_id(), 'wc_csv_feed_mapping', $mapping);
        
        $status = sanitize_key($_POST['product_status'] ?? 'publish');
        $category = sanitize_text_field($_POST['default_category'] ?? '');
        $limit = absint($_POST['preview_limit'] ?? 100);
        $token = bin2hex(random_bytes(16));
        $summary = $this->summary($rows, $category);
        
        set_transient($this->key($token), [
            'rows' => $rows,
            'mapping' => $mapping,
            'summary' => $summary,
            'status' => $status,
            'category' => $category,
            'limit' => $limit
        ], 3600);
        
        $this->go(['preview' => $token]);
    }

    public function confirm_import() {
        if(!current_user_can('manage_woocommerce'))wp_die('Permission denied.');
        $token = sanitize_key(wp_unslash($_POST['preview_token'] ?? ''));
        check_admin_referer('wc_csv_feed_confirm_'.$token);
        $key = $this->key($token);
        $data = get_transient($key);
        if(!is_array($data))$this->go(['notice'=>'error']);
        
        $job = bin2hex(random_bytes(8));
        $job_data = [
            'rows' => $data['rows'],
            'status' => $data['status'],
            'category' => $data['category'],
            'total' => count($data['rows']),
            'imported' => 0,
            'failed' => 0
        ];
        set_transient($this->job_key($job), $job_data, 3600);
        delete_transient($key);
        $this->go(['job' => $job]);
    }

    public function ajax_batch() {
        if(!current_user_can('manage_woocommerce'))wp_send_json_error('Permission denied.', 403);
        
        $job = sanitize_key(wp_unslash($_POST['job'] ?? ''));
        if(!$job || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'wc_csv_batch'))
            wp_send_json_error('Invalid request.', 403);
        
        $key = $this->job_key($job);
        $data = get_transient($key);
        if(!is_array($data))
            wp_send_json_error('Job expired.');
        
        $batch = absint($_POST['batch'] ?? 0);
        $start = $batch * $this->batch_size;
        $end = $start + $this->batch_size;
        $rows = array_slice($data['rows'], $start, $this->batch_size);
        
        // Increase time limit for batch processing
        set_time_limit(120);
        
        foreach($rows as $row){
            if($this->save_row($row, $data['status'], $data['category']))
                $data['imported']++;
            else
                $data['failed']++;
        }
        
        set_transient($key, $data, 3600);
        wp_send_json_success(['batch' => $batch, 'imported' => $data['imported'], 'failed' => $data['failed']]);
    }

    private function read_csv($path, $mapping){
        if(!is_readable($path) || false === ($h = fopen($path, 'rb')))
            return [];
        
        $headers = fgetcsv($h, 0, ',', '"');
        if(!$headers){
            fclose($h);
            return [];
        }
        
        $normalized = array_map([$this,'norm'], $headers);
        $detected = $this->detect_mapping($normalized, $mapping);
        $rows = [];
        
        while(false !== ($row = fgetcsv($h, 0, ',', '"'))){
            if(count($row) < count($headers))
                $row = array_pad($row, count($headers), '');
            
            $data = [];
            foreach($detected as $field => $col){
                $idx = array_search($this->norm($col), $normalized);
                $data[$field] = $idx !== false ? trim($row[$idx]) : '';
            }
            $rows[] = $data;
        }
        fclose($h);
        return $rows;
    }

    private function detect_mapping($headers, $mapping){
        $aliases = [
            'name' => ['productname','name','title','producttitle'],
            'sku' => ['productcode','sku','code','itemcode'],
            'category' => ['category','categories','productcategory'],
            'description' => ['description','summary','productsummary','details'],
            'price' => ['price','cost','msrp','regularprice'],
            'stock' => ['stock','quantity','availableqty','qty'],
            'image' => ['image','url','imageurl','productimage']
        ];
        
        foreach($aliases as $field => $options){
            foreach($options as $alias){
                if(in_array($this->norm($alias), $headers))
                    $mapping[$field] = $alias;
            }
        }
        return $mapping;
    }

    private function summary($rows){
        $s = ['skipped'=>0,'duplicates'=>0,'invalid_prices'=>0,'missing_images'=>0,'create'=>0,'update'=>0];
        $seen = [];
        
        foreach($rows as $r){
            $name = trim($r['name']);
            $sku = strtoupper(trim($r['sku']));
            
            if(!$name || !$sku){
                $s['skipped']++;
                continue;
            }
            
            if(isset($seen[$sku])){
                $s['duplicates']++;
                continue;
            }
            $seen[$sku] = 1;
            
            if($r['price'] !== '' && !$this->decimal($r['price'])){
                $s['invalid_prices']++;
                continue;
            }
            
            if($r['image'] !== '' && !$this->valid_url($r['image'])){
                $s['missing_images']++;
                continue;
            }
            
            $id = $sku && function_exists('wc_get_product_id_by_sku') ? wc_get_product_id_by_sku($sku) : 0;
            $s[$id ? 'update' : 'create']++;
        }
        return $s;
    }

    private function save_row($r, $status, $default_category){
        if(!$r['name'] || ($r['price'] !== '' && !$this->decimal($r['price'])))
            return false;
        
        $sku = trim($r['sku']);
        $id = $sku && function_exists('wc_get_product_id_by_sku') ? wc_get_product_id_by_sku($sku) : 0;
        
        if($id){
            $product = wc_get_product($id);
        } else {
            $product = new WC_Product();
        }
        
        if(!$product)
            return false;
        
        $product->set_name(trim($r['name']));
        $product->set_sku($sku);
        
        if($r['price'] !== '')
            $product->set_regular_price($this->decimal($r['price']));
        
        if($r['category']){
            $cat = $r['category'];
            $term = get_term_by('name', $cat, 'product_cat');
            if(!$term){
                $term = wp_insert_term($cat, 'product_cat');
            }
            if(!is_wp_error($term) && isset($term['term_id']))
                $product->set_category_ids([$term['term_id']]);
        } elseif($default_category){
            $term = get_term_by('name', $default_category, 'product_cat');
            if(!$term)
                $term = wp_insert_term($default_category, 'product_cat');
            if(!is_wp_error($term) && isset($term['term_id']))
                $product->set_category_ids([$term['term_id']]);
        }
        
        if($r['description'])
            $product->set_description($r['description']);
        
        if($r['stock'] !== ''){
            $stock = absint($r['stock']);
            $product->set_stock_quantity($stock);
            $product->set_manage_stock(true);
        }
        
        if($r['image'] && $this->valid_url($r['image'])){
            $img_id = $this->image($r['image'], $product->get_id());
            if($img_id)
                $product->set_image_id($img_id);
        }
        
        $product->set_status($status);
        return (bool)$product->save();
    }

    private function mapping($input){
        $m = $this->fields;
        foreach($m as $k => $v)
            if(isset($input[$k]))
                $m[$k] = sanitize_text_field(wp_unslash($input[$k]));
        return $m;
    }

    private function norm($v){
        return strtolower(preg_replace('/[^a-z0-9]+/', '', (string)$v));
    }

    private function decimal($v){
        $v = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string)$v));
        return (!$v || $v === '-' || $v === '.') ? '' : number_format((float)$v, 2, '.', '');
    }

    private function valid_url($v){
        return is_string($v) && $v !== '' && (bool)filter_var($v, FILTER_VALIDATE_URL);
    }

    private function image($url, $id){
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        $a = media_sideload_image($url, $id, '', array('return'=>'id'));
        return !is_wp_error($a) ? $a : 0;
    }

    private function key($token){
        return 'wc_csv_preview_'.get_current_user_id().'_'.sanitize_key($token);
    }

    private function job_key($token){
        return 'wc_csv_job_'.get_current_user_id().'_'.sanitize_key($token);
    }

    private function go($args){
        wp_safe_redirect(add_query_arg(array_merge(['page'=>'wc-csv-feed-importer'], $args), admin_url('admin.php')));
        exit;
    }
}

add_action('plugins_loaded', static function(){
    if(class_exists('WooCommerce'))
        WC_CSV_Product_Feed_Importer::instance();
});
