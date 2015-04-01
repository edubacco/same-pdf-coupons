<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

if ( ! class_exists( 'WPI_Invoice' ) ) {

    /**
     * Makes the invoice.
     * Class WPI_Invoice
     */
    class WPI_Invoice extends WPI_Document{

        /*
         * WooCommerce order
         */
        public $order;

        /**
         * Invoice number
         * @var
         */
        protected $number;

        /**
         * Formatted invoice number with prefix and/or suffix
         * @var
         */
        protected $formatted_number;

        /**
         * Invoice number database meta key
         * @var string
         */
        protected $invoice_number_meta_key = '_bewpi_invoice_number';

        /**
         * Creation date.
         * @var
         */
        protected $date;



        /**
         * Initialize invoice with WooCommerce order and plugin textdomain.
         * @param string $order
         * @param $textdomain
         */
        public function __construct( $order ) {

            parent::__construct( $order );

            // Init if the invoice already exists.
            if( get_post_meta( $this->order->id, '_bewpi_invoice_date', true ) === '' )
                return;

            $this->init();
        }

        /**
         * Gets all the existing invoice data from database or creates new invoice number.
         */
        private function init() {
            ( $this->template_settings['invoice_number_type'] === 'sequential_number' )
                ? $this->number = get_post_meta($this->order->id, '_bewpi_invoice_number', true)
                : $this->number = $this->order->id;
            $this->formatted_number = get_post_meta($this->order->id, '_bewpi_formatted_invoice_number', true);
            $this->date = get_post_meta( $this->order->id, '_bewpi_invoice_date', true );
        }

        /**
         * Gets next invoice number based on the user input.
         * @param $order_id
         */
        protected function get_next_invoice_number( $last_invoice_number ) {
            // Check if it has been the first of january.
            if ($this->template_settings['reset_invoice_number']) {
                $last_year = $this->template_settings['last_invoiced_year'];

                if ( !empty( $last_year ) && is_numeric($last_year)) {
                    $date = getdate();
                    $current_year = $date['year'];
                    if ($last_year < $current_year) {
                        // Set new year as last invoiced year and reset invoice number
                        return 1;
                    }
                }
            }

            // Check if the next invoice number should be used.
            $next_invoice_number = $this->template_settings['next_invoice_number'];
            if ( !empty( $next_invoice_number )
                && empty( $last_invoice_number )
                || $next_invoice_number > $last_invoice_number) {
                return $next_invoice_number;
            }

            return $last_invoice_number;
        }

        /**
         * Create invoice date
         * @return bool|string
         */
        protected function create_formatted_date() {
            $date_format = $this->template_settings['invoice_date_format'];
            //$date = DateTime::createFromFormat('Y-m-d H:i:s', $this->order->order_date);
            //$date = date( $date_format );

            if ($date_format != "") {
                //$formatted_date = $date->format($date_format);
                $formatted_date = date($date_format);
            } else {
                //$formatted_date = $date->format($date, "d-m-Y");
                $formatted_date = date('d-m-Y');
            }

            add_post_meta($this->order->id, '_bewpi_invoice_date', $formatted_date);

            return $formatted_date;
        }

        /**
         * Creates new invoice number with SQL MAX CAST.
         * @param $order_id
         * @param $number
         */
        protected function create_invoice_number($next_number) {
            global $wpdb;

            // attempt the query up to 3 times for a much higher success rate if it fails (due to Deadlock)
            $success = false;
            for ($i = 0; $i < 3 && !$success; $i++) {
                // this seems to me like the safest way to avoid order number clashes
                $query = $wpdb->prepare(
                    "
                    INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                    SELECT %d, %s, IF( MAX( CAST( meta_value as UNSIGNED ) ) IS NULL, %d, MAX( CAST( meta_value as UNSIGNED ) ) + 1 )
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = %s
                ",
                    $this->order->id, $this->invoice_number_meta_key, $next_number, $this->invoice_number_meta_key
                );
                $success = $wpdb->query($query);
            }

            return $success;
        }

        /**
         * Format the invoice number with prefix and/or suffix.
         * @return mixed
         */
        protected function format_invoice_number() {
            $invoice_number_format = $this->template_settings['invoice_format'];
            $digit_str = "%0" . $this->template_settings['invoice_number_digits'] . "s";
            $this->number = sprintf($digit_str, $this->number);

            $invoice_number_format = str_replace(
                array('[prefix]', '[suffix]', '[number]'),
                array($this->template_settings['invoice_prefix'], $this->template_settings['invoice_suffix'], $this->number),
                $invoice_number_format);

            add_post_meta($this->order->id, '_bewpi_formatted_invoice_number', $invoice_number_format);

            return $invoice_number_format;
        }

        /**
         * When an invoice gets generated again then the post meta needs to get deleted.
         */
        protected function delete_all_post_meta() {
            delete_post_meta( $this->order->id, '_bewpi_invoice_number' );
            delete_post_meta( $this->order->id, '_bewpi_formatted_invoice_number' );
            delete_post_meta( $this->order->id, '_bewpi_invoice_date' );
        }

        /**
         * Returns MPDF footer.
         * @return string
         */
        protected function get_footer() {
            ob_start(); ?>

            <table class="foot">
                <tbody>
                <tr>
                    <td class="border" colspan="2">
                        <?php echo $this->template_settings['terms']; ?>
                        <br/>
                        <?php
                        $customer_order_notes = $this->order->get_customer_order_notes();
                        if ( count( $customer_order_notes ) > 0 ) { ?>
                            <p>
                                <strong><?php _e('Customer note', $this->textdomain); ?> </strong><?php echo $customer_order_notes[0]->comment_content; ?>
                            </p>
                        <?php } ?>
                    </td>
                </tr>
                <tr>
                    <td class="company-details">
                        <p>
                            <?php echo nl2br($this->template_settings['company_details']); ?>
                        </p>
                    </td>
                    <td class="payment">
                        <p>
                            <?php printf( __( '%sPayment%s via', $this->textdomain ), '<b>', '</b>' ); ?>  <?php echo $this->order->payment_method_title; ?>
                        </p>
                    </td>
                </tr>
                </tbody>
            </table>

            <?php $html = ob_get_contents();

            ob_end_clean();

            return $html;
        }

        /**
         * Prints the company logo
         */
        public function get_company_logo() {
            $company_logo_url = $this->template_settings['company_logo'];
            echo '<img id="company-logo" src="' . $company_logo_url . '" />';
        }

        /**
         * Prints the company name
         */
        public function get_company_name() {
            $company_name = $this->template_settings['company_name'];
            echo '<h1 id="company-name">' . $company_name . '</h1>';
        }

        /**
         * Get's the invoice number from db.
         * @param $order_id
         * @return mixed
         */
        public function get_invoice_number() {
            global $wpdb;

            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "
                    SELECT meta_value
                    FROM $wpdb->postmeta
                    WHERE post_id = %d
                    AND meta_key = %s
                    ", $this->order->id, $this->invoice_number_meta_key
                )
            );

            if (count($results) == 1) {
                return $results[0]->meta_value;
            }
        }

        /**
         * Getter for formatted invoice number.
         * @return mixed
         */
        public function get_formatted_number() {
            return $this->formatted_number;
        }

        /**
         * Getter for formatted date.
         * @return mixed
         */
        public function get_formatted_date() {
            return $this->date;
        }

        public function get_item_table_headers() {
            return array(
                'item' => array(
                    'title' => __( 'Item', $this->textdomain ),
                    'show' => true
                ),
                'sku' => array(
                    'title' => __( 'SKU', $this->textdomain ),
                    'show' => (bool)$this->template_settings['show_sku']
                ),
                'price' => array(
                    'title' => __( 'Price', $this->textdomain ),
                    'show' => true
                ),
                'qty' => array(
                    'title' => __( 'Quantity', $this->textdomain ),
                    'show' => true
                ),
                'tax' => array(
                    'title' => __( 'Tax', $this->textdomain ),
                    'show' => (bool)$this->template_settings['show_tax']
                ),
                'total' => array(
                    'title' => __( 'Total', $this->textdomain ),
                    'show' => true
                )
            );
        }

        public function get_item_table_footers() {
            return array(
                'discount' => array(
                    'title' => __( 'Discount', $this->textdomain ),
                    'show' => (bool)$this->template_settings['show_discount'],
                    'amount' => $this->order->get_total_discount()
                ),
                'shipping' => array(
                    'title' => __( 'Shipping', $this->textdomain ),
                    'show' => (bool)$this->template_settings['show_shipping'],
                    'amount' => $this->order->get_total_shipping()
                ),
                'subtotal' => array(
                    'title' => __( 'Subtotal', $this->textdomain ),
                    'show' => (bool)$this->template_settings['show_subtotal'],
                    'amount' => $this->order->get_subtotal()
                ),
                'tax' => array(
                    'title' => __( 'Tax', $this->textdomain ),
                    'show' => (bool)$this->template_settings['show_tax'],
                    'amount' => $this->order->get_total_tax()
                ),
                'total' => array(
                    'title' => __( 'Total', $this->textdomain ),
                    'show' => true,
                    'amount' => ( $this->order->get_total_refunded() > 0 )
                        ? '<del>' . strip_tags( $this->order->get_formatted_order_total() ) . '</del> <ins>' . wc_price( $this->order->get_total() - $this->order->get_total_refunded(), array( 'currency' => $this->order->get_order_currency() ) ) . '</ins>'
                        : $this->order->get_formatted_order_total()
                ),
                'refund' => array(
                    'title' => __( 'Refunded', $this->textdomain ),
                    'show' => ( $this->order->get_total_refunded() > 0 ),
                    'amount' => '-' . $this->order->get_total_refunded()
                )
            );
        }

        public function get_colspan() {
            $item_table_headers = $this->get_item_table_headers();
            $total_showed_header_cells = 1; // One empty header cell for the thumb.
            $tfoot_cells = 2;

            foreach( $item_table_headers as $th ) {
                if( $th['show'] ) {
                    $total_showed_header_cells++;
                }
            }

            return ( $total_showed_header_cells - $tfoot_cells );
        }

        /**
         * Gets the year from the WooCommerce order date.
         * @return bool|string
         */
        public function get_formatted_order_year() {
            return date("Y", strtotime($this->order->order_date));
        }

        /**
         * Get total with or without refunds
         */
        public function get_formatted_total() {
            if( $this->order->get_total_refunded() > 0 ) {
                $total = wc_price( $this->order->get_total() - $this->order->get_total_refunded() );
            }
        }
    }
}