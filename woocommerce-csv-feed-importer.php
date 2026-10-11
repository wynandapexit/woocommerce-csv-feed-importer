<?php
/**
 * Plugin Name: WooCommerce CSV Product Feed Importer
 * Description: Preview-first WooCommerce CSV importer with automatic mapping, saved presets, dry-run summaries and batched imports.
 * Version: 1.5.4
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */
if (!defined('ABSPATH')) { exit; }

final class WC_CSV_Product_Feed_Importer {
    private static $instance;
    private $batch_size = 5;
    private $batch_timeout = 30;
    private $max_retries = 3;
    private $fields = ['name'=>'ProductName','sku'=>'ProductCode','category'=>'Category','description'=>'ProductSummary','price'=>'Price','stock'=>'AvailableQty','image'=>'Image'];

    public static function instance() {
        return self::$instance ?: (self::$instance = new self());
    }

    private function __construct() {
        add_action('admin_menu', [$this,'admin_menu']);
        add_action('admin_post_wc_csv_product_feed_import', [$this,'handle_import']);
        add_action('admin_post_wc_csv_product_feed_confirm', [$this,'confirm_import']);
        add_action('wp_ajax_wc_csv_feed_batch', [$this,'ajax_batch']);
        add_action('wp_ajax_wc_csv_feed_watchdog', [$this,'watchdog']);
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
        $validation = $data['validation'] ?? [];
        $limit = max(1, min(1000, absint($data['limit'])));

        echo '<h2>Import Preview</h2>';
        echo '<p>Showing '.esc_html(min(count($rows), $limit)).' of '.esc_html(count($rows)).' rows. No products have been saved.</p>';
        echo '<p><strong>Detected mapping:</strong> '.esc_html(json_encode($data['mapping'])).'</p>';

        if(!empty($validation['errors']) || !empty($validation['warnings'])){
            if(!empty($validation['errors'])){
                echo '<div class="notice notice-error"><p><strong>Critical Issues Found:</strong></p><ul>';
                foreach($validation['errors'] as $error){
                    echo '<li>'.esc_html($error).'</li>';
                }
                echo '</ul><p style="color:#d63638;font-weight:bold;">Please fix these issues before importing.</p></div>';
            }
            if(!empty($validation['warnings'])){
                echo '<div class="notice notice-warning"><p><strong>Warnings (non-blocking):</strong></p><ul>';
                foreach($validation['warnings'] as $warning){
                    echo '<li>'.esc_html($warning).'</li>';
                }
                echo '</ul></div>';
            }
        } else {
            echo '<div class="notice notice-success"><p>✓ CSV structure and format validated successfully.</p></div>';
        }

        echo '<div class="postbox"><h2 class="hndle">Summary</h2><div class="inside"><ul>';
        foreach(['Rows skipped'=>'skipped','Duplicate SKUs'=>'duplicates','Invalid prices'=>'invalid_prices','Missing image URLs'=>'missing_images','Would create'=>'create','Would update'=>'update'] as $label=>$key) {
            echo '<li>'.esc_html($label.': '.absint($s[$key])).'</li>';
        }
        echo '</ul></div></div>';

        echo '<table class="widefat striped"><thead><tr><th>#</th><th>Product</th><th>SKU</th><th>Category</th><th>Price</th><th>Quantity</th><th>Image</th><th>Status</th></tr></thead><tbody>';
        foreach(array_slice($rows, 0, $limit) as $i => $row) {
            $row_issues = $data['row_issues'][$i] ?? [];
            $status_class = empty($row_issues['errors']) ? 'success' : 'error';
            $status_icon = empty($row_issues['errors']) ? '✓' : '⚠';

            echo '<tr>';
            echo '<td>'.esc_html($i+1).'</td>';
            echo '<td>'.esc_html($row['name']).'</td>';
            echo '<td>'.esc_html($row['sku']).'</td>';
            echo '<td>'.esc_html($row['category']).'</td>';
            echo '<td>'.esc_html(number_format((float)($row['price']??0), 2)).'</td>';
            echo '<td>'.esc_html($row['stock']).'</td>';
            echo '<td>'.($row['image'] ? '<a href="'.esc_url($row['image']).'" target="_blank">View</a>' : '—').'</td>';
            echo '<td style="font-weight:bold;color:'.($status_class === 'success' ? '#28a745' : '#dc3545').'">'.$status_icon;
            if(!empty($row_issues['errors'])){
                echo '<br><small style="display:block;margin-top:5px;color:#666;">'.esc_html(implode(', ', $row_issues['errors'])).'</small>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><strong>Status:</strong> '.esc_html($data['status']).' &nbsp; <strong>Default category:</strong> '.esc_html($data['category'] ?: 'None').'</p>';

        if(empty($validation['errors'])){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('wc_csv_feed_confirm_'.$token);
            echo '<input type="hidden" name="action" value="wc_csv_product_feed_confirm">';
            echo '<input type="hidden" name="preview_token" value="'.esc_attr($token).'">';
            submit_button('Begin Import');
            echo '</form>';
        } else {
            echo '<p style="color:#d63638;font-weight:bold;">Cannot proceed with import due to critical formatting errors. Please review the CSV file and try again.</p>';
        }
    }

    private function render_progress($job) {
        $data = get_transient($this->job_key($job));
        if (!is_array($data)) {
            echo '<div class="notice notice-error"><p>This import job expired.</p></div>';
            return;
        }
        echo '<h2>Importing products</h2>';
        echo '<div style="max-width:760px;background:#dcdcde;height:28px;border-radius:4px;overflow:hidden">';
        echo '<div id="wc-csv-progress-bar" style="height:28px;width:0;background:#0073aa;transition:width 0.3s"></div></div>';
        echo '<p id="wc-csv-progress-text">Starting import...</p>';
        echo '<p id="wc-csv-status-details" style="font-size:12px;color:#666;margin-top:10px;"></p>';
        echo '<div id="wc-csv-error-log" style="background:#f8f8f8;border:1px solid #ddd;padding:10px;margin-top:10px;max-height:200px;overflow-y:auto;display:none;">';
        echo '<strong>Issues detected:</strong><ul id="wc-csv-errors"></ul></div>';
        echo '<script>
let batch = 0, total = '.absint($data['total']).', job = "'.esc_attr($job).'", batchSize = '.absint($this->batch_size).', retryCount = 0, maxRetries = '.absint($this->max_retries).', imported = 0, failed = 0;
let watchdogTimer = null;
let batchTimeout = '.absint($this->batch_timeout * 1000).';

function updateProgress(){
    let pct = Math.round(batch / Math.ceil(total / batchSize) * 100);
    document.getElementById("wc-csv-progress-bar").style.width = pct + "%";
    document.getElementById("wc-csv-progress-text").textContent = "Batch " + (batch + 1) + " of " + Math.ceil(total / batchSize) + " (Imported: " + imported + ", Failed: " + failed + ")";
    document.getElementById("wc-csv-status-details").textContent = "Processing rows " + (batch * batchSize + 1) + " - " + Math.min((batch + 1) * batchSize, total);
}

function watchdogCheck(){
    clearTimeout(watchdogTimer);
    watchdogTimer = setTimeout(() => {
        console.warn("Batch timeout detected at batch " + batch + ", retrying...");
        if(retryCount < maxRetries) {
            retryCount++;
            addError("Batch " + (batch + 1) + " timeout - retry " + retryCount + " of " + maxRetries);
            next();
        } else {
            addError("Batch " + (batch + 1) + " failed after " + maxRetries + " retries");
            document.getElementById("wc-csv-progress-text").textContent = "Import stalled. Watchdog detected timeout. Retrying...";
            next();
        }
    }, batchTimeout);
}

function addError(msg){
    let errorList = document.getElementById("wc-csv-errors");
    document.getElementById("wc-csv-error-log").style.display = "block";
    let li = document.createElement("li");
    li.textContent = msg;
    errorList.appendChild(li);
}

function next(){
    if(batch >= Math.ceil(total / batchSize)){
        clearTimeout(watchdogTimer);
        document.getElementById("wc-csv-progress-text").textContent = "Import complete!";
        setTimeout(() => {
            document.location = document.location.href;
        }, 2000);
        return;
    }

    watchdogCheck();

    let formData = new FormData();
    formData.append("action", "wc_csv_feed_batch");
    formData.append("job", job);
    formData.append("batch", batch);
    formData.append("retry", retryCount);
    formData.append("_ajax_nonce", "'.esc_attr(wp_create_nonce('wc_csv_batch')).'");

    fetch(ajaxurl, {
        method: "POST",
        body: formData
    }).then(r => r.json()).then(r => {
        clearTimeout(watchdogTimer);
        if(r.success) {
            imported = r.data.imported;
            failed = r.data.failed;
            retryCount = 0;
            batch++;
            updateProgress();
            setTimeout(next, 500);
        } else {
            console.error("Batch error:", r.data);
            if(retryCount < maxRetries) {
                retryCount++;
                addError("Batch " + (batch + 1) + " error: " + (r.data || "Unknown") + " - retry " + retryCount);
                setTimeout(next, 1000);
            } else {
                addError("Batch " + (batch + 1) + " failed permanently: " + (r.data || "Unknown"));
                batch++;
                updateProgress();
                setTimeout(next, 2000);
            }
        }
    }).catch(e => {
        clearTimeout(watchdogTimer);
        console.error("Fetch error:", e);
        if(retryCount < maxRetries) {
            retryCount++;
            addError("Network error - retry " + retryCount);
            setTimeout(next, 1000);
        } else {
            document.location.href = "?page=wc-csv-feed-importer&notice=error";
        }
    });
}

updateProgress();
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
        $summary = $this->summary($rows);

        $validation = $this->validate_csv($rows, $mapping);

        $row_issues = [];
        foreach($rows as $idx => $row){
            $row_issues[$idx] = $this->validate_row($row);
        }

        set_transient($this->key($token), [
            'rows' => $rows,
            'mapping' => $mapping,
            'summary' => $summary,
            'status' => $status,
            'category' => $category,
            'limit' => $limit,
            'validation' => $validation,
            'row_issues' => $row_issues
        ], 3600);

        $this->go(['preview' => $token]);
    }

    private function validate_csv($rows, $mapping) {
        $errors = [];
        $warnings = [];

        if(empty($rows)){
            $errors[] = 'CSV file contains no data rows.';
            return ['errors' => $errors, 'warnings' => $warnings];
        }

        if(empty($mapping['name']) || empty($mapping['sku'])){
            $errors[] = 'Required fields "name" and "sku" must be mapped to columns in your CSV.';
        }

        $skus = [];
        $sku_duplicates = [];
        foreach($rows as $idx => $row){
            $sku = strtoupper(trim((string)($row['sku'] ?? '')));
            if($sku && isset($skus[$sku])){
                if(!isset($sku_duplicates[$sku])){
                    $sku_duplicates[$sku] = [$skus[$sku]];
                }
                $sku_duplicates[$sku][] = $idx;
            }
            if($sku) $skus[$sku] = $idx;
        }

        if(!empty($sku_duplicates)){
            foreach($sku_duplicates as $sku => $indices){
                $warnings[] = 'SKU "'.$sku.'" appears '.count($indices).' times (rows: '.implode(', ', array_map(function($i){ return $i + 1; }, $indices)).'). Only the first will be imported.';
            }
        }

        $critical_missing = 0;
        foreach($rows as $idx => $row){
            if(empty(trim((string)($row['name'] ?? ''))) || empty(trim((string)($row['sku'] ?? '')))){
                $critical_missing++;
            }
        }

        if($critical_missing > 0){
            $warnings[] = $critical_missing . ' row(s) have missing product name or SKU and will be skipped.';
        }

        if(function_exists('mb_check_encoding')){
            foreach($rows as $idx => $row){
                foreach($row as $value){
                    if(!mb_check_encoding((string)$value, 'UTF-8')){
                        $errors[] = 'Encoding error detected in row ' . ($idx + 1) . '. Please ensure your CSV is UTF-8 encoded.';
                        break 2;
                    }
                }
            }
        } else {
            $warnings[] = 'UTF-8 validation skipped because the mbstring extension is not installed on this server.';
        }

        $expected_cols = null;
        foreach($rows as $idx => $row){
            $cols = count($row);
            if($expected_cols === null){
                $expected_cols = $cols;
            } elseif($cols !== $expected_cols){
                $warnings[] = 'Row ' . ($idx + 1) . ' has ' . $cols . ' columns, but expected ' . $expected_cols . '. Extra columns will be ignored.';
            }
        }

        if(count($rows) > 1000){
            $warnings[] = 'This CSV contains ' . count($rows) . ' rows. Large imports may take several minutes. Please keep the browser window open.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function validate_row($row) {
        $issues = ['errors' => [], 'warnings' => []];

        $name = trim((string)($row['name'] ?? ''));
        $sku = trim((string)($row['sku'] ?? ''));
        $price = $row['price'] ?? '';
        $image = $row['image'] ?? '';

        if(empty($name)){
            $issues['errors'][] = 'Missing product name';
        }
        if(empty($sku)){
            $issues['errors'][] = 'Missing SKU';
        }
        if($price !== '' && !$this->decimal($price)){
            $issues['errors'][] = 'Invalid price format';
        }
        if($image !== '' && !$this->valid_url($image)){
            $issues['warnings'][] = 'Invalid image URL';
        }
        if(strlen($name) > 200){
            $issues['warnings'][] = 'Product name exceeds 200 characters (will be truncated)';
        }
        if(preg_match('/[<>"%{}|\\^\[\]`]/', $sku)){
            $issues['warnings'][] = 'SKU contains special characters that may cause issues';
        }

        return $issues;
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
            'failed' => 0,
            'last_successful_batch' => -1,
            'errors' => [],
            'started_at' => current_time('timestamp')
        ];
        set_transient($this->job_key($job), $job_data, 7200);
        delete_transient($key);
        $this->go(['job' => $job]);
    }

    public function ajax_batch() {
        if(!current_user_can('manage_woocommerce'))wp_send_json_error('Permission denied.', 403);

        $job = sanitize_key(wp_unslash($_POST['job'] ?? ''));
        $batch = absint($_POST['batch'] ?? 0);
        $retry = absint($_POST['retry'] ?? 0);

        if(!$job || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'wc_csv_batch'))
            wp_send_json_error('Invalid request.', 403);

        $key = $this->job_key($job);
        $data = get_transient($key);
        if(!is_array($data))
            wp_send_json_error('Job expired.');

        if($retry === 0 && isset($data['last_successful_batch']) && $batch <= $data['last_successful_batch']){
            wp_send_json_success(['batch' => $batch, 'imported' => $data['imported'], 'failed' => $data['failed'], 'skipped' => true]);
        }

        $start = $batch * $this->batch_size;
        $rows = array_slice($data['rows'], $start, $this->batch_size);

        if(empty($rows)){
            wp_send_json_error('No rows to process.');
        }

        set_time_limit(120);

        $batch_imported = 0;
        $batch_failed = 0;

        foreach($rows as $idx => $row){
            try {
                if($this->save_row($row, $data['status'], $data['category'])){
                    $batch_imported++;
                    $data['imported']++;
                } else {
                    $batch_failed++;
                    $data['failed']++;
                }
            } catch(\Exception $e){
                $batch_failed++;
                $data['failed']++;
                $data['errors'][] = 'Row ' . ($start + $idx + 1) . ': ' . $e->getMessage();
            }
        }

        $data['last_successful_batch'] = $batch;
        set_transient($key, $data, 7200);

        wp_send_json_success([
            'batch' => $batch,
            'imported' => $data['imported'],
            'failed' => $data['failed'],
            'batch_imported' => $batch_imported,
            'batch_failed' => $batch_failed
        ]);
    }

    public function watchdog() {
        if(!current_user_can('manage_woocommerce'))wp_send_json_error('Permission denied.', 403);

        $job = sanitize_key(wp_unslash($_POST['job'] ?? ''));
        if(!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'wc_csv_watchdog'))
            wp_send_json_error('Invalid request.', 403);

        $key = $this->job_key($job);
        $data = get_transient($key);
        if(!is_array($data))
            wp_send_json_error('Job expired.');

        $elapsed = current_time('timestamp') - ($data['started_at'] ?? time());

        wp_send_json_success([
            'status' => 'running',
            'imported' => $data['imported'],
            'failed' => $data['failed'],
            'elapsed' => $elapsed,
            'errors_count' => count($data['errors'] ?? [])
        ]);
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
                $data[$field] = $idx !== false ? trim((string)$row[$idx]) : '';
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
            $name = trim((string)($r['name'] ?? ''));
            $sku = strtoupper(trim((string)($r['sku'] ?? '')));

            if(!$name || !$sku){
                $s['skipped']++;
                continue;
            }

            if(isset($seen[$sku])){
                $s['duplicates']++;
                continue;
            }
            $seen[$sku] = 1;

            if(($r['price'] ?? '') !== '' && !$this->decimal($r['price'])){
                $s['invalid_prices']++;
                continue;
            }

            if(($r['image'] ?? '') !== '' && !$this->valid_url($r['image'])){
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

        $sku = trim((string)($r['sku'] ?? ''));
        $id = $sku && function_exists('wc_get_product_id_by_sku') ? wc_get_product_id_by_sku($sku) : 0;

        if($id){
            $product = wc_get_product($id);
        } else {
            $product = new WC_Product();
        }

        if(!$product)
            return false;

        $product->set_name(trim((string)($r['name'] ?? '')));
        $product->set_sku($sku);

        if(($r['price'] ?? '') !== '')
            $product->set_regular_price($this->decimal($r['price']));

        if(!empty($r['category'])){
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

        if(!empty($r['description']))
            $product->set_description($r['description']);

        if(isset($r['stock']) && $r['stock'] !== ''){
            $stock = absint($r['stock']);
            $product->set_stock_quantity($stock);
            $product->set_manage_stock(true);
        }

        if(!empty($r['image']) && $this->valid_url($r['image'])){
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
