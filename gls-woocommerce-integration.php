<?php
/**
 * Plugin Name: GLS Italy WooCommerce Integration
 * Plugin URI: https://github.com/RiccardoCalvi/gls_woocommerce_italy
 * Description: Integrazione API GLS (Etichette) + Calcolo Tariffe di Spedizione e Contrassegno.
 * Version: 1.0.9
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

        if ( version_compare( $current_version, str_replace('v', '', $github_info->tag_name), '<' ) ) {
            $obj = new stdClass();
            $obj->slug = $this->basename;
            $obj->new_version = $github_info->tag_name;
            $obj->url = $plugin_data['PluginURI'];
            $obj->package = $github_info->zipball_url; 
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
        $this->generate_gls_shipment( $order->get_id(), true ); 
    }

    public function add_admin_menu() {
        add_submenu_page( 'woocommerce', 'Impostazioni GLS', 'Impostazioni GLS', 'manage_woocommerce', 'gls-settings', array( $this, 'settings_page_html' ) );
    }

    public function register_settings() {
        register_setting( 'gls_settings_group', 'gls_sede' );
        register_setting( 'gls_settings_group', 'gls_codice_cliente' );
        register_setting( 'gls_settings_group', 'gls_password' );
        register_setting( 'gls_settings_group', 'gls_codice_contratto' );
        
        register_setting( 'gls_settings_group', 'gls_vat_rate' );
        register_setting( 'gls_settings_group', 'gls_free_shipping_threshold' );
        
        register_setting( 'gls_settings_group', 'gls_enable_cod' );
        register_setting( 'gls_settings_group', 'gls_cod_fee_percentage' ); 
        register_setting( 'gls_settings_group', 'gls_cod_min_fee' ); 
    }

    public function settings_page_html() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        ?>
        <div class="wrap">
            <h1>Impostazioni Integrazione GLS Italy</h1>
            <form action="options.php" method="post">
                <?php settings_fields( 'gls_settings_group' ); ?>
                <table class="form-table">
                    <tr><th colspan="2"><h3>Credenziali API</h3></th></tr>
                    <tr><th scope="row">Sede GLS (Sigla)</th><td><input type="text" name="gls_sede" value="<?php echo esc_attr( get_option( 'gls_sede' ) ); ?>" maxlength="2" placeholder="Es. MI" /></td></tr>
                    <tr><th scope="row">Codice Cliente</th><td><input type="text" name="gls_codice_cliente" value="<?php echo esc_attr( get_option( 'gls_codice_cliente' ) ); ?>" /></td></tr>
                    <tr><th scope="row">Password</th><td><input type="password" name="gls_password" value="<?php echo esc_attr( get_option( 'gls_password' ) ); ?>" /></td></tr>
                    <tr><th scope="row">Codice Contratto (Obbligatorio)</th><td><input type="text" name="gls_codice_contratto" value="<?php echo esc_attr( get_option( 'gls_codice_contratto' ) ); ?>" /> <br><small>Inserisci il codice esatto fornito da GLS, senza zeri iniziali se non previsti.</small></td></tr>
                    
                    <tr><th colspan="2"><hr><h3>Impostazioni Costi e Tasse</h3></th></tr>
                    <tr>
                        <th scope="row">Aliquota IVA Spedizioni (%)</th>
                        <td><input type="number" step="1" name="gls_vat_rate" value="<?php echo esc_attr( get_option( 'gls_vat_rate', '22' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Soglia Spedizione Gratuita (€)</th>
                        <td><input type="number" step="0.01" name="gls_free_shipping_threshold" value="<?php echo esc_attr( get_option( 'gls_free_shipping_threshold', '0' ) ); ?>" /></td>
                    </tr>

                    <tr><th colspan="2"><hr><h3>Impostazioni Contrassegno (COD)</h3></th></tr>
                    <tr>
                        <th scope="row">Abilita Trasmissione Contrassegno</th>
                        <td><label><input type="checkbox" name="gls_enable_cod" value="yes" <?php checked( get_option( 'gls_enable_cod' ), 'yes' ); ?> /> Trasmetti a GLS l'incasso del contrassegno.</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Percentuale Contrassegno (%)</th>
                        <td><input type="number" step="0.1" name="gls_cod_fee_percentage" value="<?php echo esc_attr( get_option( 'gls_cod_fee_percentage', '2' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Costo Minimo Contrassegno (€ netto)</th>
                        <td><input type="number" step="0.01" name="gls_cod_min_fee" value="<?php echo esc_attr( get_option( 'gls_cod_min_fee', '5.00' ) ); ?>" /></td>
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
        $sede = get_option( 'gls_sede' ); 
        $cliente = get_option( 'gls_codice_cliente' ); 
        $password = get_option( 'gls_password' );
        $contratto = get_option( 'gls_codice_contratto' );

        if ( empty( $sede ) || empty( $cliente ) || empty( $password ) ) return false;

        $ragione_sociale = $order->get_shipping_company() ?: $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name();
        $indirizzo = $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2();
        $cap = $order->get_shipping_postcode();
        $importo_contrassegno = ( $order->get_payment_method() === 'cod' && get_option( 'gls_enable_cod', 'no' ) === 'yes' ) ? $order->get_total() : 0;

        $xml = '<?xml version="1.0" encoding="utf-8"?><Info>';
        $xml .= '<SedeGls>' . esc_html( $sede ) . '</SedeGls><CodiceClienteGls>' . esc_html( $cliente ) . '</CodiceClienteGls><PasswordClienteGls>' . esc_html( $password ) . '</PasswordClienteGls><AddParcelResult>S</AddParcelResult><Parcel>';
        
        if ( ! empty( $contratto ) || $contratto === '0' ) {
            $xml .= '<CodiceContrattoGls>' . esc_html( trim( $contratto ) ) . '</CodiceContrattoGls>';
        }
        
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
            $order->add_order_note( 'GLS Error: Risposta XML dal server incomprensibile.' );
            return;
        }

        if ( isset( $xml->Parcel->DescrizioneErrore ) && !empty( (string) $xml->Parcel->DescrizioneErrore ) ) {
            $order->add_order_note( 'Errore GLS (API): ' . (string) $xml->Parcel->DescrizioneErrore ); 
            return;
        }
        
        if ( isset( $xml->Parcel->NoteSpedizione ) && strpos( (string) $xml->Parcel->NoteSpedizione, 'Dati non accettabili' ) !== false ) {
            $order->add_order_note( 'Errore GLS (Dati non validi): ' . (string) $xml->Parcel->NoteSpedizione ); 
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
            $order->add_order_note( 'GLS Info: Nessun tracking trovato. Struttura: ' . print_r($xml, true) );
        }
    }

    public function schedule_cron() { /* Cron setup */ }
    public function clear_cron() { /* Cron clear */ }
    
    public function execute_close_work_day() {
        $sede = get_option( 'gls_sede' );
        $cliente = get_option( 'gls_codice_cliente' );
        $password = get_option( 'gls_password' );

        if ( empty( $sede ) || empty( $cliente ) || empty( $password ) ) {
            error_log( 'GLS Cron Error: Credenziali mancanti.' );
            return;
        }

        $xml = '<?xml version="1.0" encoding="utf-8"?><Info><SedeGls>' . esc_html( $sede ) . '</SedeGls><CodiceClienteGls>' . esc_html( $cliente ) . '</CodiceClienteGls><PasswordClienteGls>' . esc_html( $password ) . '</PasswordClienteGls><CloseWorkDayResult>S</CloseWorkDayResult></Info>';

        $response = wp_remote_post( $this->api_url_closeworkday, array(
            'method'  => 'POST',
            'timeout' => 60,
            'body'    => array( 'XMLInfo' => $xml )
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'GLS CloseWorkDay Error: ' . $response->get_error_message() );
            return;
        }
        $body = wp_remote_retrieve_body( $response );
        error_log( 'GLS CloseWorkDay Eseguito: ' . substr( $body, 0, 200 ) );
    }
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
                $this->method_description = 'Calcola le tariffe in base agli scaglioni netti. L\'IVA verrà aggiunta in automatico.';
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
                    
                    'title_it' => array( 'title' => 'Tariffe Base (Italia)', 'type' => 'title' ),
                    'it_0_3' => array( 'title' => '0 - 3 Kg (€)', 'type' => 'number', 'default' => '4.90', 'custom_attributes' => array('step' => '0.01') ),
                    'it_3_5' => array( 'title' => '3 - 5 Kg (€)', 'type' => 'number', 'default' => '5.50', 'custom_attributes' => array('step' => '0.01') ),
                    'it_5_10' => array( 'title' => '5 - 10 Kg (€)', 'type' => 'number', 'default' => '9.00', 'custom_attributes' => array('step' => '0.01') ),
                    'it_10_20' => array( 'title' => '10 - 20 Kg (€)', 'type' => 'number', 'default' => '10.50', 'custom_attributes' => array('step' => '0.01') ),
                    'it_20_50' => array( 'title' => '20 - 50 Kg (€)', 'type' => 'number', 'default' => '16.50', 'custom_attributes' => array('step' => '0.01') ),
                    'it_50_100' => array( 'title' => '50 - 100 Kg (€)', 'type' => 'number', 'default' => '25.00', 'custom_attributes' => array('step' => '0.01') ),
                    'it_extra_50' => array( 'title' => 'Extra ogni 50Kg (fino a 500Kg) (€)', 'type' => 'number', 'default' => '16.50', 'custom_attributes' => array('step' => '0.01') ),
                    'it_extra_100' => array( 'title' => 'Extra ogni 100Kg (oltre 500Kg) (€)', 'type' => 'number', 'default' => '40.00', 'custom_attributes' => array('step' => '0.01') ),

                    'title_cs' => array( 'title' => 'Tariffe Calabria e Sicilia', 'type' => 'title' ),
                    'cs_0_3' => array( 'title' => '0 - 3 Kg (€)', 'type' => 'number', 'default' => '5.20', 'custom_attributes' => array('step' => '0.01') ),
                    'cs_3_5' => array( 'title' => '3 - 5 Kg (€)', 'type' => 'number', 'default' => '6.50', 'custom_attributes' => array('step' => '0.01') ),
                    'cs_5_10' => array( 'title' => '5 - 10 Kg (€)', 'type' => 'number', 'default' => '13.00', 'custom_attributes' => array('step' => '0.01') ),
                    'cs_10_20' => array( 'title' => '10 - 20 Kg (€)', 'type' => 'number', 'default' => '16.00', 'custom_attributes' => array('step' => '0.01') ),
                    'cs_20_50' => array( 'title' => '20 - 50 Kg (€)', 'type' => 'number', 'default' => '22.00', 'custom_attributes' => array('step' => '0.01') ),
                    'cs_50_100' => array( 'title' => '50 - 100 Kg (€)', 'type' => 'number', 'default' => '34.00', 'custom_attributes' => array('step' => '0.01') ),
                    'cs_extra_50' => array( 'title' => 'Extra ogni 50Kg (fino a 500Kg) (€)', 'type' => 'number', 'default' => '22.00', 'custom_attributes' => array('step' => '0.01') ),
                    'cs_extra_100' => array( 'title' => 'Extra ogni 100Kg (oltre 500Kg) (€)', 'type' => 'number', 'default' => '40.00', 'custom_attributes' => array('step' => '0.01') ),

                    'title_sa' => array( 'title' => 'Tariffe Sardegna', 'type' => 'title' ),
                    'sa_0_3' => array( 'title' => '0 - 3 Kg (€)', 'type' => 'number', 'default' => '5.50', 'custom_attributes' => array('step' => '0.01') ),
                    'sa_3_5' => array( 'title' => '3 - 5 Kg (€)', 'type' => 'number', 'default' => '7.00', 'custom_attributes' => array('step' => '0.01') ),
                    'sa_5_10' => array( 'title' => '5 - 10 Kg (€)', 'type' => 'number', 'default' => '13.00', 'custom_attributes' => array('step' => '0.01') ),
                    'sa_10_20' => array( 'title' => '10 - 20 Kg (€)', 'type' => 'number', 'default' => '16.00', 'custom_attributes' => array('step' => '0.01') ),
                    'sa_20_50' => array( 'title' => '20 - 50 Kg (€)', 'type' => 'number', 'default' => '22.00', 'custom_attributes' => array('step' => '0.01') ),
                    'sa_50_100' => array( 'title' => '50 - 100 Kg (€)', 'type' => 'number', 'default' => '34.00', 'custom_attributes' => array('step' => '0.01') ),
                    'sa_extra_50' => array( 'title' => 'Extra ogni 50Kg (fino a 500Kg) (€)', 'type' => 'number', 'default' => '22.00', 'custom_attributes' => array('step' => '0.01') ),
                    'sa_extra_100' => array( 'title' => 'Extra ogni 100Kg (oltre 500Kg) (€)', 'type' => 'number', 'default' => '40.00', 'custom_attributes' => array('step' => '0.01') ),

                    'title_other' => array( 'title' => 'Altre Maggiorazioni', 'type' => 'title' ),
                    'minor_islands' => array( 'title' => 'Maggiorazione Isole Minori/Laguna (ogni 100Kg) (€)', 'type' => 'number', 'default' => '18.50', 'custom_attributes' => array('step' => '0.01') ),
                );
            }

            public function calculate_shipping( $package = array() ) {
                $weight = WC()->cart->get_cart_contents_weight();
                if ( $weight <= 0 ) $weight = 1; 

                $state = $package['destination']['state'];
                $postcode = $package['destination']['postcode'];
                
                $calabria_sicilia = array( 'CZ', 'CS', 'KR', 'RC', 'VV', 'AG', 'CL', 'CT', 'EN', 'ME', 'PA', 'RG', 'SR', 'TP' );
                $sardegna = array( 'CA', 'NU', 'OR', 'SS', 'SU' );

                if ( in_array( $state, $calabria_sicilia ) ) {
                    $prefix = 'cs_';
                } elseif ( in_array( $state, $sardegna ) ) {
                    $prefix = 'sa_';
                } else {
                    $prefix = 'it_';
                }

                $cost = 0;
                
                if ( $weight <= 3 ) $cost = (float) $this->get_option( $prefix . '0_3' );
                elseif ( $weight <= 5 ) $cost = (float) $this->get_option( $prefix . '3_5' );
                elseif ( $weight <= 10 ) $cost = (float) $this->get_option( $prefix . '5_10' );
                elseif ( $weight <= 20 ) $cost = (float) $this->get_option( $prefix . '10_20' );
                elseif ( $weight <= 50 ) $cost = (float) $this->get_option( $prefix . '20_50' );
                elseif ( $weight <= 100 ) $cost = (float) $this->get_option( $prefix . '50_100' );
                elseif ( $weight <= 500 ) {
                    $base = (float) $this->get_option( $prefix . '50_100' );
                    $extra = (float) $this->get_option( $prefix . 'extra_50' );
                    $cost = $base + ( ceil( ($weight - 100) / 50 ) * $extra );
                } else {
                    $base = (float) $this->get_option( $prefix . '50_100' );
                    $extra_50 = (float) $this->get_option( $prefix . 'extra_50' );
                    $extra_100 = (float) $this->get_option( $prefix . 'extra_100' );
                    $cost = $base + ( 8 * $extra_50 ) + ( ceil( ($weight - 500) / 100 ) * $extra_100 );
                }

                $venice_islands = array( '30121','30122','30123','30124','30125','30126','30132','30133','30141','80073','80074','80075','80076','80077' ); 
                if ( in_array( $postcode, $venice_islands ) ) {
                    $minor_rate = (float) $this->get_option( 'minor_islands' );
                    $cost += ( ceil( $weight / 100 ) * $minor_rate );
                }

                $vat_rate = (float) get_option( 'gls_vat_rate', '22' );
                $cost_with_vat = $cost * ( 1 + ( $vat_rate / 100 ) );

                $free_threshold = (float) get_option( 'gls_free_shipping_threshold', '0' );
                $cart_total_for_threshold = WC()->cart->get_cart_contents_total() + WC()->cart->get_cart_contents_tax();

                if ( $free_threshold > 0 && $cart_total_for_threshold >= $free_threshold ) {
                    $cost_with_vat = 0; 
                }

                $this->add_rate( array( 'id' => $this->id, 'label' => $this->title, 'cost' => $cost_with_vat ) );
            }
        }
    }
}
add_filter( 'woocommerce_shipping_methods', 'add_gls_custom_shipping_method' );
function add_gls_custom_shipping_method( $methods ) {
    $methods['gls_contract_shipping'] = 'WC_GLS_Contract_Shipping_Method'; return $methods;
}

// --- CALCOLO SOVRATASSA CONTRASSEGNO NEL CARRELLO ---
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
        $percentage = (float) get_option( 'gls_cod_fee_percentage', '2' );
        $min_fee = (float) get_option( 'gls_cod_min_fee', '5.00' );
        $vat_rate = (float) get_option( 'gls_vat_rate', '22' );
        
        $cart_total = $cart->get_cart_contents_total() + $cart->get_shipping_total();
        $base_fee = max( $min_fee, $cart_total * ($percentage / 100) );
        $fee_with_vat = $base_fee * ( 1 + ( $vat_rate / 100 ) );
        
        $cart->add_fee( 'Supplemento Contrassegno GLS', $fee_with_vat, false ); 
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