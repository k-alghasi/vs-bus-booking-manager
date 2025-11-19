<?php
defined('ABSPATH') || exit;

/**
 * Custom Seat Layout Editor for VS Bus Booking Manager
 * Provides visual editor for creating custom bus seat layouts
 */
class VSBBM_Custom_Layout_Editor {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Admin hooks
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_vsbbm_save_custom_layout', array($this, 'ajax_save_layout'));
        add_action('wp_ajax_vsbbm_load_custom_layout', array($this, 'ajax_load_layout'));
        add_action('wp_ajax_vsbbm_delete_custom_layout', array($this, 'ajax_delete_layout'));

        // Product meta box
        add_action('add_meta_boxes_product', array($this, 'add_layout_meta_box'));
        add_action('save_post_product', array($this, 'save_layout_meta'));

        // Frontend display
        add_filter('vsbbm_get_seat_layout_data', array($this, 'get_custom_layout_data'), 10, 2);
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        // Only load on product pages
        global $post;
        if (!$post || $post->post_type !== 'product') {
            return;
        }

        wp_enqueue_script('vsbbm-layout-editor', VSBBM_PLUGIN_URL . 'assets/js/layout-editor.js', array('jquery', 'jquery-ui-draggable', 'jquery-ui-droppable'), VSBBM_VERSION, true);
        wp_enqueue_style('vsbbm-layout-editor', VSBBM_PLUGIN_URL . 'assets/css/layout-editor.css', array(), VSBBM_VERSION);

        wp_localize_script('vsbbm-layout-editor', 'vsbbm_layout_editor', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('vsbbm_layout_editor'),
            'strings' => array(
                'confirm_delete' => __('آیا از حذف این چیدمان مطمئن هستید؟', 'vs-bus-booking-manager'),
                'save_success' => __('چیدمان ذخیره شد.', 'vs-bus-booking-manager'),
                'save_error' => __('خطا در ذخیره چیدمان.', 'vs-bus-booking-manager'),
                'load_error' => __('خطا در بارگذاری چیدمان.', 'vs-bus-booking-manager'),
            )
        ));
    }

    /**
     * Add custom layout meta box to product edit page
     */
    public function add_layout_meta_box() {
        global $post;
        if (!$post || !VSBBM_Seat_Manager::is_seat_booking_enabled($post->ID)) {
            return;
        }

        add_meta_box(
            'vsbbm_custom_layout',
            __('ویرایشگر چیدمان سفارشی صندلی‌ها', 'vs-bus-booking-manager'),
            array($this, 'render_layout_editor'),
            'product',
            'normal',
            'high'
        );
    }

    /**
     * Render the custom layout editor
     */
    public function render_layout_editor($post) {
        $layout_data = get_post_meta($post->ID, '_vsbbm_custom_layout', true);
        $layout_data = $layout_data ? json_decode($layout_data, true) : $this->get_default_layout();

        ?>
        <div id="vsbbm-layout-editor" class="vsbbm-layout-editor">
            <div class="layout-editor-header">
                <h3><?php _e('ویرایشگر چیدمان اتوبوس', 'vs-bus-booking-manager'); ?></h3>
                <div class="layout-actions">
                    <button type="button" id="vsbbm-load-template" class="button">
                        <?php _e('بارگذاری طرح آماده', 'vs-bus-booking-manager'); ?>
                    </button>
                    <button type="button" id="vsbbm-save-layout" class="button button-primary">
                        <?php _e('ذخیره چیدمان', 'vs-bus-booking-manager'); ?>
                    </button>
                </div>
            </div>

            <div class="layout-editor-toolbar">
                <h4><?php _e('ابزارک‌ها', 'vs-bus-booking-manager'); ?></h4>
                <div class="toolbar-items">
                    <div class="tool-item" data-type="seat" data-class="regular">
                        <span class="tool-icon">🪑</span>
                        <span class="tool-label"><?php _e('صندلی معمولی', 'vs-bus-booking-manager'); ?></span>
                    </div>
                    <div class="tool-item" data-type="seat" data-class="vip">
                        <span class="tool-icon">💺</span>
                        <span class="tool-label"><?php _e('صندلی VIP', 'vs-bus-booking-manager'); ?></span>
                    </div>
                    <div class="tool-item" data-type="aisle">
                        <span class="tool-icon">🚶</span>
                        <span class="tool-label"><?php _e('راهرو', 'vs-bus-booking-manager'); ?></span>
                    </div>
                    <div class="tool-item" data-type="space">
                        <span class="tool-icon">⬜</span>
                        <span class="tool-label"><?php _e('فضای خالی', 'vs-bus-booking-manager'); ?></span>
                    </div>
                    <div class="tool-item" data-type="stairs">
                        <span class="tool-icon">🪜</span>
                        <span class="tool-label"><?php _e('راه پله', 'vs-bus-booking-manager'); ?></span>
                    </div>
                    <div class="tool-item" data-type="driver">
                        <span class="tool-icon">👤</span>
                        <span class="tool-label"><?php _e('فضای راننده', 'vs-bus-booking-manager'); ?></span>
                    </div>
                </div>
            </div>

            <div class="layout-editor-canvas">
                <div class="canvas-header">
                    <div class="canvas-info">
                        <span id="canvas-size"><?php printf(__('اندازه: %dx%d', 'vs-bus-booking-manager'), $layout_data['grid']['rows'], $layout_data['grid']['cols']); ?></span>
                        <span id="total-seats"><?php printf(__('صندلی‌ها: %d', 'vs-bus-booking-manager'), $layout_data['metadata']['total_seats']); ?></span>
                    </div>
                    <div class="canvas-controls">
                        <button type="button" id="vsbbm-clear-canvas" class="button button-small">
                            <?php _e('پاک کردن', 'vs-bus-booking-manager'); ?>
                        </button>
                        <button type="button" id="vsbbm-resize-canvas" class="button button-small">
                            <?php _e('تغییر اندازه', 'vs-bus-booking-manager'); ?>
                        </button>
                    </div>
                </div>

                <div id="vsbbm-layout-canvas" class="layout-canvas" data-rows="<?php echo $layout_data['grid']['rows']; ?>" data-cols="<?php echo $layout_data['grid']['cols']; ?>">
                    <?php $this->render_canvas_grid($layout_data); ?>
                </div>
            </div>

            <div class="layout-editor-settings">
                <h4><?php _e('تنظیمات', 'vs-bus-booking-manager'); ?></h4>
                <div class="settings-grid">
                    <div class="setting-item">
                        <label><?php _e('شروع شماره صندلی‌ها:', 'vs-bus-booking-manager'); ?></label>
                        <input type="number" id="seat-start-number" value="1" min="1">
                    </div>
                    <div class="setting-item">
                        <label><?php _e('جهت شماره‌گذاری:', 'vs-bus-booking-manager'); ?></label>
                        <select id="numbering-direction">
                            <option value="ltr"><?php _e('چپ به راست', 'vs-bus-booking-manager'); ?></option>
                            <option value="rtl"><?php _e('راست به چپ', 'vs-bus-booking-manager'); ?></option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Hidden input to store layout data -->
            <input type="hidden" name="vsbbm_custom_layout" id="vsbbm_custom_layout" value="<?php echo esc_attr(json_encode($layout_data)); ?>">
        </div>

        <!-- Template selector modal -->
        <div id="vsbbm-template-modal" class="vsbbm-modal" style="display: none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><?php _e('انتخاب طرح آماده', 'vs-bus-booking-manager'); ?></h3>
                    <button type="button" class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="template-grid">
                        <?php foreach ($this->get_layout_templates() as $key => $template): ?>
                            <div class="template-item" data-template="<?php echo $key; ?>">
                                <div class="template-preview">
                                    <?php echo $template['preview']; ?>
                                </div>
                                <div class="template-info">
                                    <h4><?php echo $template['name']; ?></h4>
                                    <p><?php echo $template['description']; ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the canvas grid
     */
    private function render_canvas_grid($layout_data) {
        $rows = $layout_data['grid']['rows'];
        $cols = $layout_data['grid']['cols'];
        $layout = $layout_data['layout'];

        // Create lookup array for faster access
        $cell_lookup = array();
        foreach ($layout as $cell) {
            $key = $cell['x'] . '-' . $cell['y'];
            $cell_lookup[$key] = $cell;
        }

        echo '<div class="canvas-grid" style="grid-template-columns: repeat(' . $cols . ', 1fr);">';

        for ($y = 0; $y < $rows; $y++) {
            for ($x = 0; $x < $cols; $x++) {
                $key = $x . '-' . $y;
                $cell = isset($cell_lookup[$key]) ? $cell_lookup[$key] : null;

                $classes = array('canvas-cell');
                $content = '';
                $data_attrs = 'data-x="' . $x . '" data-y="' . $y . '"';

                if ($cell) {
                    $classes[] = 'cell-' . $cell['type'];
                    if (isset($cell['class'])) {
                        $classes[] = 'cell-' . $cell['class'];
                    }

                    $data_attrs .= ' data-type="' . $cell['type'] . '"';
                    if (isset($cell['class'])) {
                        $data_attrs .= ' data-class="' . $cell['class'] . '"';
                    }
                    if (isset($cell['number'])) {
                        $data_attrs .= ' data-number="' . $cell['number'] . '"';
                    }

                    $content = $this->get_cell_content($cell);
                } else {
                    $classes[] = 'cell-empty';
                    $data_attrs .= ' data-type="empty"';
                }

                echo '<div class="' . implode(' ', $classes) . '" ' . $data_attrs . '>' . $content . '</div>';
            }
        }

        echo '</div>';
    }

    /**
     * Get cell content based on type
     */
    private function get_cell_content($cell) {
        switch ($cell['type']) {
            case 'seat':
                $icon = isset($cell['class']) && $cell['class'] === 'vip' ? '💺' : '🪑';
                $number = isset($cell['number']) ? $cell['number'] : '';
                return $icon . '<br><small>' . $number . '</small>';
            case 'aisle':
                return '🚶';
            case 'space':
                return '⬜';
            case 'stairs':
                return '🪜';
            case 'driver':
                return '👤';
            default:
                return '';
        }
    }

    /**
     * Get default layout structure
     */
    private function get_default_layout() {
        return array(
            'grid' => array(
                'rows' => 8,
                'cols' => 5
            ),
            'layout' => array(
                // Driver area
                array('x' => 0, 'y' => 0, 'type' => 'driver'),
                // Aisle
                array('x' => 2, 'y' => 0, 'type' => 'aisle'),
                array('x' => 2, 'y' => 1, 'type' => 'aisle'),
                array('x' => 2, 'y' => 2, 'type' => 'aisle'),
                array('x' => 2, 'y' => 3, 'type' => 'aisle'),
                array('x' => 2, 'y' => 4, 'type' => 'aisle'),
                array('x' => 2, 'y' => 5, 'type' => 'aisle'),
                array('x' => 2, 'y' => 6, 'type' => 'aisle'),
                array('x' => 2, 'y' => 7, 'type' => 'aisle'),
                // Seats
                array('x' => 1, 'y' => 0, 'type' => 'seat', 'number' => 1, 'class' => 'window'),
                array('x' => 3, 'y' => 0, 'type' => 'seat', 'number' => 2, 'class' => 'aisle'),
                array('x' => 4, 'y' => 0, 'type' => 'seat', 'number' => 3, 'class' => 'window'),
                array('x' => 1, 'y' => 1, 'type' => 'seat', 'number' => 4, 'class' => 'window'),
                array('x' => 3, 'y' => 1, 'type' => 'seat', 'number' => 5, 'class' => 'aisle'),
                array('x' => 4, 'y' => 1, 'type' => 'seat', 'number' => 6, 'class' => 'window'),
                // Continue for other rows...
            ),
            'metadata' => array(
                'total_seats' => 32,
                'classes' => array('window', 'aisle', 'middle', 'vip')
            )
        );
    }

    /**
     * Get predefined layout templates
     */
    private function get_layout_templates() {
        return array(
            '2-2-2' => array(
                'name' => __('چیدمان ۲-۲-۲', 'vs-bus-booking-manager'),
                'description' => __('چیدمان استاندارد با ۲ صندلی - راهرو - ۲ صندلی', 'vs-bus-booking-manager'),
                'preview' => '<div class="template-preview-grid">🪑🪑🚶🪑🪑</div>'
            ),
            '2-3-2' => array(
                'name' => __('چیدمان ۲-۳-۲', 'vs-bus-booking-manager'),
                'description' => __('چیدمان گسترده با ۲ صندلی - راهرو - ۳ صندلی - راهرو - ۲ صندلی', 'vs-bus-booking-manager'),
                'preview' => '<div class="template-preview-grid">🪑🪑🚶🪑🪑🪑🚶🪑🪑</div>'
            ),
            'vip-luxury' => array(
                'name' => __('VIP لوکس', 'vs-bus-booking-manager'),
                'description' => __('چیدمان VIP با صندلی‌های لوکس و فضای بیشتر', 'vs-bus-booking-manager'),
                'preview' => '<div class="template-preview-grid">💺⬜💺</div>'
            )
        );
    }

    /**
     * Save layout meta data
     */
    public function save_layout_meta($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['vsbbm_custom_layout'])) return;

        $layout_data = wp_unslash($_POST['vsbbm_custom_layout']);
        update_post_meta($post_id, '_vsbbm_custom_layout', $layout_data);
    }

    /**
     * AJAX handler for saving custom layout
     */
    public function ajax_save_layout() {
        check_ajax_referer('vsbbm_layout_editor', 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(__('دسترسی غیرمجاز', 'vs-bus-booking-manager'));
        }

        $product_id = intval($_POST['product_id']);
        $layout_data = wp_unslash($_POST['layout_data']);

        if (!$product_id || !$layout_data) {
            wp_send_json_error(__('داده‌های نامعتبر', 'vs-bus-booking-manager'));
        }

        $result = update_post_meta($product_id, '_vsbbm_custom_layout', $layout_data);

        if ($result) {
            wp_send_json_success(__('چیدمان ذخیره شد', 'vs-bus-booking-manager'));
        } else {
            wp_send_json_error(__('خطا در ذخیره چیدمان', 'vs-bus-booking-manager'));
        }
    }

    /**
     * AJAX handler for loading custom layout
     */
    public function ajax_load_layout() {
        check_ajax_referer('vsbbm_layout_editor', 'nonce');

        $product_id = intval($_POST['product_id']);
        $layout_data = get_post_meta($product_id, '_vsbbm_custom_layout', true);

        if ($layout_data) {
            wp_send_json_success(json_decode($layout_data, true));
        } else {
            wp_send_json_success($this->get_default_layout());
        }
    }

    /**
     * Get custom layout data for frontend display
     */
    public function get_custom_layout_data($layout_data, $product_id) {
        $custom_layout = get_post_meta($product_id, '_vsbbm_custom_layout', true);

        if ($custom_layout) {
            $layout_data = json_decode($custom_layout, true);
        }

        return $layout_data;
    }

    /**
     * Get seats from custom layout
     */
    public static function get_seats_from_layout($layout_data) {
        $seats = array();

        if (isset($layout_data['layout'])) {
            foreach ($layout_data['layout'] as $cell) {
                if ($cell['type'] === 'seat' && isset($cell['number'])) {
                    $seats[] = $cell['number'];
                }
            }
        }

        return $seats;
    }
}

// Initialize the custom layout editor
VSBBM_Custom_Layout_Editor::get_instance();