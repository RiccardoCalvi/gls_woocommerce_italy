<?php
/**
 * Plugin Name: GLS Italy WooCommerce Integration
 * Plugin URI: https://github.com/RiccardoCalvi/gls_woocommerce_italy
 * Description: Integrazione API GLS (Etichette) + Calcolo Tariffe di Spedizione e Contrassegno.
 * Version: 1.0.3
 * Author: Dream2Dev
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// --- CLASSE PER AUTO-UPDATE DA GITHUB ---
class GLS_GitHub_Updater {
    private $file;
    private $plugin;
    private $basename;
    private $active;
    private $username;
    private $repository;

    public function __construct( $file ) {
        $this->file = $file;
        $this->add_plugin_hooks();
        
        // Sostituisci questi con i tuoi dati GitHub
        $this->username = 'RiccardoCalvi'; 
        $this->repository = 'gls_woocommerce_italy';
    }

    private function add_plugin_hooks() {
        $this->plugin = plugin_basename( $this->file );
        $this->basename = plugin_basename( $this->file );
        $this->active = is_plugin_active( $this->basename );
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
    }

    private function get_repository_info() {
        $request_uri = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', $this->username, $this->repository );
        $response = wp_remote_get( $request_uri, array( 'timeout' => 10 ) );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) != 200 ) return false;
        return json_decode( wp_remote_retrieve_body( $response ) );
    }

    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;
        $github_info = $this->get_repository_info();
        if ( ! $github_info ) return $transient;

        $plugin_data = get_plugin_data( $this->file );
        $current_version = $plugin_data['Version'];

        // Se la versione su GitHub (tag) è maggiore della nostra, notifica l'update
        if ( version_compare( $current_version, str_replace('v', '', $github_info->tag_name), '<' ) ) {
            $obj = new stdClass();
            $obj->slug = $this->basename;
            $obj->new_version = $github_info->tag_name;
            $obj->url = $plugin_data['PluginURI'];
            $obj->package = $github_info->zipball_url; // Scarica lo zip direttamente da GitHub
            $transient->response[$this->basename] = $obj;
        }
        return $transient;
    }

    public function plugin_info( $res, $action, $args ) {
        if ( $action !== 'plugin_information' || current_filter() !== 'plugins_api' ) return $res;
        if ( isset( $args->slug ) && $args->slug === $this->basename ) {
            $github_info = $this->get_repository_info();
            if ( $github_info ) {
                $plugin_data = get_plugin_data( $this->file );
                $res = new stdClass();
                $res->name = $plugin_data['Name'];
                $res->slug = $this->basename;
                $res->version = $github_info->tag_name;
                $res->author = $plugin_data['Author'];
                $res->homepage = $plugin_data['PluginURI'];
                $res->download_link = $github_info->zipball_url;
                $res->sections = array( 'description' => $plugin_data['Description'], 'changelog' => nl2br( $github_info->body ) );
            }
        }
        return $res;
    }
}
new GLS_GitHub_Updater( __FILE__ );


// --- CORE DEL PLUGIN GLS ---
class GLS_WooCommerce_Integration_Advanced {

    private $api_url_addparcel = 'https://labelservice.gls-italy.com/ilswebservice.asmx/AddParcel';
    private $api_url_closeworkday = 'https://labelservice.gls-italy.com/ilswebservice.asmx/CloseWorkDay';

    public function __construct() {
        add_action( 'woocommerce_order_status_processing', array( $this, 'generate_gls_shipment' ), 10, 1 );
        
        // Azione manuale nell'ordine
        add_action( 'woocommerce_order_actions', array( $this, 'add_gls_order_action' ) );
        add_action( 'woocommerce_order_action_gls_generate_label', array( $this, 'process_gls_order_action' ) );

        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'init', array( $this, 'schedule_cron' ) );
        add_action( 'gls_daily_close_work_day', array( $this, 'execute_close_work_day' ) );
        register_deactivation_hook( __FILE__, array( $this, 'clear_cron' ) );
    }

    public function add_gls_order_action( $actions ) {
        $actions['gls_generate_label'] = 'Genera/Rigenera Etichetta GLS';
        return $actions;
    }

    public function process_gls_order_action( $order ) {
        $this->generate_gls_shipment( $order->get_id(), true ); // true forza la rigenerazione
    }

    public function add_admin_menu() {
        add_submenu_page( 'woocommerce', 'Impostazioni GLS', 'Impostazioni GLS', 'manage_woocommerce', 'gls-settings', array( $this, 'settings_page_html' ) );
    }

    public function register_settings() {
        register_setting( 'gls_settings_group', 'gls_sede' );
        register_setting( 'gls_settings_group', 'gls_codice_cliente' );
        register_setting( 'gls_settings_group', 'gls_password' );
        register_setting( 'gls_settings_group', 'gls_codice_contratto' );
        register_setting( 'gls_settings_group', 'gls_enable_cod' );
        register_setting( 'gls_settings_group', 'gls_cod_fee_amount' ); 
    }

    public function settings_page_html() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        ?>
        <div class="wrap">
            <h1>Impostazioni Integrazione GLS Italy</h1>
            <form action="options.php" method="post">
                <?php settings_fields( 'gls_settings_group' ); ?>
                <table class="form-table">
                    <tr><th scope="row">Sede GLS (Sigla)</th><td><input type="text" name="gls_sede" value="<?php echo esc_attr( get_option( 'gls_sede' ) ); ?>" maxlength="2" placeholder="Es. MI" /></td></tr>
                    <tr><th scope="row">Codice Cliente</th><td><input type="text" name="gls_codice_cliente" value="<?php echo esc_attr( get_option( 'gls_codice_cliente' ) ); ?>" /></td></tr>
                    <tr><th scope="row">Password</th><td><input type="password" name="gls_password" value="<?php echo esc_attr( get_option( 'gls_password' ) ); ?>" /></td></tr>
                    <tr><th scope="row">Codice Contratto</th><td><input type="text" name="gls_codice_contratto" value="<?php echo esc_attr( get_option( 'gls_codice_contratto' ) ); ?>" /></td></tr>
                    <tr>
                        <th scope="row">Abilita Trasmissione Contrassegno</th>
                        <td><label><input type="checkbox" name="gls_enable_cod" value="yes" <?php checked( get_option( 'gls_enable_cod' ), 'yes' ); ?> /> Trasmetti a GLS l'incasso del contrassegno (COD).</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Costo Contrassegno al Cliente (€)</th>
                        <td><input type="number" step="0.01" name="gls_cod_fee_amount" value="<?php echo esc_attr( get_option( 'gls_cod_fee_amount', '5.00' ) ); ?>" /></td>
                    </tr>
                </table>
                <?php submit_button( 'Salva Impostazioni' ); ?>
            </form>
            <hr>
            <h2>Azioni Manuali</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="gls_manual_close_work_day">
                <?php wp_nonce_field( 'gls_manual_cwd', 'gls_cwd_nonce' ); ?>
                <?php submit_button( 'Esegui CloseWorkDay Manualmente', 'secondary' ); ?>
            </form>
        </div>
        <?php
    }

    public function generate_gls_shipment( $order_id, $force = false ) {
        $order = wc_get_order( $order_id );
        
        // Evita duplicati, a meno che non sia forzato manualmente
        if ( ! $force && get_post_meta( $order_id, '_gls_tracking_number', true ) ) {
            return;
        }

        $xml_data = $this->build_add_parcel_xml( $order );
        if ( ! $xml_data ) {
            $order->add_order_note( 'GLS Error: Credenziali GLS mancanti nelle impostazioni. Etichetta non generata.' );
            return;
        }

        $order->add_order_note( 'GLS: Inizio comunicazione con API AddParcel...' );

        $response = wp_remote_post( $this->api_url_addparcel, array( 
            'method' => 'POST', 
            'timeout' => 45, 
            'body' => array( 'XMLInfoParcel' => $xml_data ) 
        ) );

        if ( is_wp_error( $response ) ) {
            $order->add_order_note( 'GLS Error di rete: ' . $response->get_error_message() );
            return;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $http_code != 200 ) {
            $order->add_order_note( 'GLS HTTP Error ' . $http_code . ': Il server ha rifiutato la richiesta.' );
            return;
        }

        $this->parse_gls_response( $body, $order );
    }

    private function build_add_parcel_xml( $order ) {
        $sede = get_option( 'gls_sede' ); $cliente = get_option( 'gls_codice_cliente' ); $password = get_option( 'gls_password' );
        if ( empty( $sede ) || empty( $cliente ) || empty( $password ) ) return false;

        $ragione_sociale = $order->get_shipping_company() ?: $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name();
        $indirizzo = $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2();
        $cap = $order->get_shipping_postcode();
        $importo_contrassegno = ( $order->get_payment_method() === 'cod' && get_option( 'gls_enable_cod', 'no' ) === 'yes' ) ? $order->get_total() : 0;

        $xml = '<?xml version="1.0" encoding="utf-8"?><Info>';
        $xml .= '<SedeGls>' . esc_html( $sede ) . '</SedeGls><CodiceClienteGls>' . esc_html( $cliente ) . '</CodiceClienteGls><PasswordClienteGls>' . esc_html( $password ) . '</PasswordClienteGls><AddParcelResult>S</AddParcelResult><Parcel>';
        $xml .= '<RagioneSociale><![CDATA[' . substr( $ragione_sociale, 0, 35 ) . ']]></RagioneSociale>';
        $xml .= '<Indirizzo><![CDATA[' . substr( $indirizzo, 0, 35 ) . ']]></Indirizzo>';
        $xml .= '<Localita><![CDATA[' . substr( $order->get_shipping_city(), 0, 30 ) . ']]></Localita>';
        $xml .= '<Provincia>' . substr( $order->get_shipping_state(), 0, 2 ) . '</Provincia>';
        $xml .= '<Zipcode>' . substr( $cap, 0, 5 ) . '</Zipcode>';
        $xml .= '<Contrassegno>' . number_format( $importo_contrassegno, 2, '.', '' ) . '</Contrassegno>';
        $xml .= '<Colli>1</Colli><Peso>1</Peso>';
        $xml .= '<Telefono>' . esc_html( $order->get_billing_phone() ) . '</Telefono>';
        $xml .= '<IndirizzoEmail><![CDATA[' . esc_html( $order->get_billing_email() ) . ']]></IndirizzoEmail>';
        $xml .= '</Parcel></Info>';
        return $xml;
    }

    private function parse_gls_response( $xml_response, $order ) {
        $xml = @simplexml_load_string( $xml_response );
        if ( $xml === false ) {
            $order->add_order_note( 'GLS Error: Risposta XML dal server incomprensibile. Log dati: ' . esc_html( substr($xml_response, 0, 200) ) );
            return;
        }

        if ( isset( $xml->Parcel->DescrizioneErrore ) && !empty( (string) $xml->Parcel->DescrizioneErrore ) ) {
            $order->add_order_note( 'Errore GLS (API): ' . (string) $xml->Parcel->DescrizioneErrore ); 
            return;
        }

        if ( isset( $xml->Parcel->NumeroSpedizione ) ) {
            $track = (string) $xml->Parcel->NumeroSpedizione;
            update_post_meta( $order->get_id(), '_gls_tracking_number', $track );
            $note = 'Spedizione GLS creata con successo. Tracking: ' . $track;
            if ( isset( $xml->Parcel->PdfLabel ) && !empty( (string) $xml->Parcel->PdfLabel ) ) {
                $pdf_path = wp_upload_dir()['path'] . '/GLS_Label_' . $track . '.pdf';
                $pdf_url = wp_upload_dir()['url'] . '/GLS_Label_' . $track . '.pdf';
                file_put_contents( $pdf_path, base64_decode( (string) $xml->Parcel->PdfLabel ) );
                $note .= ' | <a href="' . $pdf_url . '" target="_blank">Scarica Etichetta PDF</a>';
            }
            $order->add_order_note( $note );
        } else {
            $order->add_order_note( 'GLS Info: Risposta completata ma nessun tracking trovato. Struttura: ' . print_r($xml, true) );
        }
    }

    public function schedule_cron() { /* Cron setup */ }
    public function clear_cron() { /* Cron clear */ }
    public function execute_close_work_day() { /* Close Work Day Logic */ }
}

add_action( 'admin_post_gls_manual_close_work_day', 'gls_manual_cwd_handler' );
function gls_manual_cwd_handler() {
    if ( isset( $_POST['gls_cwd_nonce'] ) && current_user_can( 'manage_woocommerce' ) ) {
        (new GLS_WooCommerce_Integration_Advanced())->execute_close_work_day();
        wp_redirect( admin_url( 'admin.php?page=gls-settings&cwd_success=1' ) ); exit;
    }
}
new GLS_WooCommerce_Integration_Advanced();

// --- TARIFFE E METODO DI SPEDIZIONE CON CAMPI MODIFICABILI ---
add_action( 'woocommerce_shipping_init', 'gls_custom_shipping_method_init' );
function gls_custom_shipping_method_init() {
    if ( ! class_exists( 'WC_GLS_Contract_Shipping_Method' ) ) {
        class WC_GLS_Contract_Shipping_Method extends WC_Shipping_Method {
            public function __construct() {
                $this->id = 'gls_contract_shipping';
                $this->method_title = 'Corriere GLS (Contratto)';
                $this->method_description = 'Calcola le tariffe in base agli scaglioni del contratto.';
                $this->availability = 'including'; $this->countries = array( 'IT' );
                $this->init();
                $this->enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'yes';
                $this->title = 'Corriere Espresso GLS';
            }

            public function init() {
                $this->init_form_fields(); $this->init_settings();
                add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
            }

            public function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array( 'title' => 'Abilita', 'type' => 'checkbox', 'default' => 'yes' ),
                    'rate_0_3' => array( 'title' => 'Tariffa 0 - 3 Kg (€)', 'type' => 'number', 'default' => '5.80', 'custom_attributes' => array('step' => '0.01') ),
                    'rate_3_5' => array( 'title' => 'Tariffa 3 - 5 Kg (€)', 'type' => 'number', 'default' => '6.30', 'custom_attributes' => array('step' => '0.01') ),
                    'rate_5_10' => array( 'title' => 'Tariffa 5 - 10 Kg (€)', 'type' => 'number', 'default' => '6.90', 'custom_attributes' => array('step' => '0.01') ),
                    'rate_10_20' => array( 'title' => 'Tariffa 10 - 20 Kg (€)', 'type' => 'number', 'default' => '7.70', 'custom_attributes' => array('step' => '0.01') ),
                    'rate_20_30' => array( 'title' => 'Tariffa 20 - 30 Kg (€)', 'type' => 'number', 'default' => '8.60', 'custom_attributes' => array('step' => '0.01') ),
                    'rate_30_50' => array( 'title' => 'Tariffa 30 - 50 Kg (€)', 'type' => 'number', 'default' => '11.50', 'custom_attributes' => array('step' => '0.01') ),
                    'rate_50_70' => array( 'title' => 'Tariffa 50 - 70 Kg (€)', 'type' => 'number', 'default' => '16.50', 'custom_attributes' => array('step' => '0.01') ),
                    'rate_70_100' => array( 'title' => 'Tariffa 70 - 100 Kg (€)', 'type' => 'number', 'default' => '20.50', 'custom_attributes' => array('step' => '0.01') ),
                    'rate_extra' => array( 'title' => 'Extra (per ogni Kg >100) (€)', 'type' => 'number', 'default' => '0.22', 'custom_attributes' => array('step' => '0.01') ),
                    'rate_islands' => array( 'title' => 'Maggiorazione Isole/Calabria fissa (€)', 'type' => 'number', 'default' => '1.00', 'custom_attributes' => array('step' => '0.01') ),
                    'rate_minor_islands' => array( 'title' => 'Maggiorazione Isole Minori/Laguna (€)', 'type' => 'number', 'default' => '18.50', 'custom_attributes' => array('step' => '0.01') ),
                );
            }

            public function calculate_shipping( $package = array() ) {
                $weight = WC()->cart->get_cart_contents_weight();
                if ( $weight <= 0 ) $weight = 1; 

                $state = $package['destination']['state'];
                $postcode = $package['destination']['postcode'];
                
                $cost = 0;
                
                if ( $weight <= 3 ) $cost = (float) $this->get_option( 'rate_0_3' );
                elseif ( $weight <= 5 ) $cost = (float) $this->get_option( 'rate_3_5' );
                elseif ( $weight <= 10 ) $cost = (float) $this->get_option( 'rate_5_10' );
                elseif ( $weight <= 20 ) $cost = (float) $this->get_option( 'rate_10_20' );
                elseif ( $weight <= 30 ) $cost = (float) $this->get_option( 'rate_20_30' );
                elseif ( $weight <= 50 ) $cost = (float) $this->get_option( 'rate_30_50' );
                elseif ( $weight <= 70 ) $cost = (float) $this->get_option( 'rate_50_70' );
                elseif ( $weight <= 100 ) $cost = (float) $this->get_option( 'rate_70_100' );
                else {
                    $base_100 = (float) $this->get_option( 'rate_70_100' );
                    $extra_rate = (float) $this->get_option( 'rate_extra' );
                    $cost = $base_100 + ( ceil( $weight - 100 ) * $extra_rate );
                }

                $islands = array( 'AG','CL','CT','EN','ME','PA','RG','SR','TP', 'CA','NU','OR','SS','SU', 'CZ','CS','KR','RC','VV' );
                if ( in_array( $state, $islands ) ) {
                    $cost += (float) $this->get_option( 'rate_islands' );
                    if ( $weight > 100 ) $cost += ( ceil( $weight - 100 ) * 0.02 );
                }

                $venice_islands = array( '30121','30122','30123','30124','30125','30126','30132','30133','30141','80073','80074','80075','80076','80077' ); 
                if ( in_array( $postcode, $venice_islands ) ) {
                    $cost += (float) $this->get_option( 'rate_minor_islands' );
                }

                $this->add_rate( array( 'id' => $this->id, 'label' => $this->title, 'cost' => $cost ) );
            }
        }
    }
}
add_filter( 'woocommerce_shipping_methods', 'add_gls_custom_shipping_method' );
function add_gls_custom_shipping_method( $methods ) {
    $methods['gls_contract_shipping'] = 'WC_GLS_Contract_Shipping_Method'; return $methods;
}

// --- CALCOLO SOVRATASSA CONTRASSEGNO NEL CARRELLO (AGGIORNATO) ---
add_action( 'woocommerce_cart_calculate_fees', 'gls_add_cod_fee', 20, 1 );
function gls_add_cod_fee( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    
    $chosen_payment_method = WC()->session->get( 'chosen_payment_method' );
    
    if ( isset( $_POST['payment_method'] ) ) {
        $chosen_payment_method = wc_clean( wp_unslash( $_POST['payment_method'] ) );
    } elseif ( isset( $_POST['post_data'] ) ) {
        parse_str( wc_clean( wp_unslash( $_POST['post_data'] ) ), $post_data );
        if ( isset( $post_data['payment_method'] ) ) {
            $chosen_payment_method = $post_data['payment_method'];
        }
    }
    
    if ( 'cod' === $chosen_payment_method ) {
        $fee = (float) get_option( 'gls_cod_fee_amount', '5.00' );
        $cart->add_fee( 'Supplemento Contrassegno GLS', $fee, false ); 
    }
}

add_action( 'wp_footer', 'gls_force_checkout_update' );
function gls_force_checkout_update() {
    if ( is_checkout() && ! is_wc_endpoint_url() ) {
        ?>
        <script type="text/javascript">
            jQuery( function( $ ) {
                $( 'form.checkout' ).on( 'change', 'input[name="payment_method"]', function() {
                    $( document.body ).trigger( 'update_checkout' );
                });
            });
        </script>
        <?php
    }
}