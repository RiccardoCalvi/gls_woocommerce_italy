<?php
/**
 * Plugin Name: GLS Italy WooCommerce Integration (Avanzato)
 * Description: Integrazione con API GLS (Label Service MU162). Include Pannello Impostazioni, Gestione Contrassegno e Cronjob (CloseWorkDay).
 * Version: 1.0.1
 * Author: Dream2Dev
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GLS_WooCommerce_Integration_Advanced {

    private $api_url_addparcel = 'https://labelservice.gls-italy.com/ilswebservice.asmx/AddParcel';
    private $api_url_closeworkday = 'https://labelservice.gls-italy.com/ilswebservice.asmx/CloseWorkDay';

    public function __construct() {
        // Generazione etichetta su cambio stato ordine
        add_action( 'woocommerce_order_status_processing', array( $this, 'generate_gls_shipment' ), 10, 1 );

        // Menu e impostazioni di amministrazione
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Cronjob per la chiusura giornata
        add_action( 'init', array( $this, 'schedule_cron' ) );
        add_action( 'gls_daily_close_work_day', array( $this, 'execute_close_work_day' ) );

        // Pulisci il cron alla disattivazione
        register_deactivation_hook( __FILE__, array( $this, 'clear_cron' ) );
    }

    // --- SEZIONE 1: INTERFACCIA GRAFICA E IMPOSTAZIONI ---

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Impostazioni GLS',
            'Impostazioni GLS',
            'manage_woocommerce',
            'gls-settings',
            array( $this, 'settings_page_html' )
        );
    }

    public function register_settings() {
        register_setting( 'gls_settings_group', 'gls_sede' );
        register_setting( 'gls_settings_group', 'gls_codice_cliente' );
        register_setting( 'gls_settings_group', 'gls_password' );
        register_setting( 'gls_settings_group', 'gls_codice_contratto' );
        register_setting( 'gls_settings_group', 'gls_enable_cod' );
    }

    public function settings_page_html() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Impostazioni Integrazione GLS Italy</h1>
            <form action="options.php" method="post">
                <?php settings_fields( 'gls_settings_group' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Sede GLS (Sigla)</th>
                        <td><input type="text" name="gls_sede" value="<?php echo esc_attr( get_option( 'gls_sede' ) ); ?>" maxlength="2" placeholder="Es. MI" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Codice Cliente</th>
                        <td><input type="text" name="gls_codice_cliente" value="<?php echo esc_attr( get_option( 'gls_codice_cliente' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Password</th>
                        <td><input type="password" name="gls_password" value="<?php echo esc_attr( get_option( 'gls_password' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Codice Contratto (Opzionale)</th>
                        <td><input type="text" name="gls_codice_contratto" value="<?php echo esc_attr( get_option( 'gls_codice_contratto' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Abilita Contrassegno (COD)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="gls_enable_cod" value="yes" <?php checked( get_option( 'gls_enable_cod' ), 'yes' ); ?> />
                                Trasmetti l'importo del contrassegno a GLS se il cliente sceglie il pagamento alla consegna.
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Salva Impostazioni' ); ?>
            </form>
            <hr>
            <h2>Azioni Manuali</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="gls_manual_close_work_day">
                <?php wp_nonce_field( 'gls_manual_cwd', 'gls_cwd_nonce' ); ?>
                <p>La chiusura di fine giornata avviene in automatico alle 19:00. Se necessario, puoi forzarla ora:</p>
                <?php submit_button( 'Esegui CloseWorkDay Manualmente', 'secondary' ); ?>
            </form>
        </div>
        <?php
    }

    // --- SEZIONE 2: GENERAZIONE SPEDIZIONE E CONTRASSEGNO ---

    public function generate_gls_shipment( $order_id ) {
        if ( get_post_meta( $order_id, '_gls_tracking_number', true ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        $xml_data = $this->build_add_parcel_xml( $order );

        if ( ! $xml_data ) return; // Interrompi se mancano credenziali

        $response = wp_remote_post( $this->api_url_addparcel, array(
            'method'      => 'POST',
            'timeout'     => 45,
            'body'        => array( 'XMLInfoParcel' => $xml_data )
        ) );

        if ( is_wp_error( $response ) ) {
            $order->add_order_note( 'Errore di connessione a GLS: ' . $response->get_error_message() );
            return;
        }

        $this->parse_gls_response( wp_remote_retrieve_body( $response ), $order );
    }

    private function build_add_parcel_xml( $order ) {
        $sede = get_option( 'gls_sede' );
        $cliente = get_option( 'gls_codice_cliente' );
        $password = get_option( 'gls_password' );
        $contratto = get_option( 'gls_codice_contratto' );

        if ( empty( $sede ) || empty( $cliente ) || empty( $password ) ) {
            $order->add_order_note( 'Spedizione GLS non generata: credenziali mancanti nelle impostazioni.' );
            return false;
        }

        // Recupero Dati
        $first_name = $order->get_shipping_first_name() ?: $order->get_billing_first_name();
        $last_name  = $order->get_shipping_last_name() ?: $order->get_billing_last_name();
        $company    = $order->get_shipping_company() ?: $order->get_billing_company();
        
        $ragione_sociale = $company ?: $first_name . ' ' . $last_name;
        $indirizzo       = $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2();
        $localita        = $order->get_shipping_city();
        $provincia       = $order->get_shipping_state();
        $cap             = $order->get_shipping_postcode();
        $telefono        = $order->get_billing_phone();
        $email           = $order->get_billing_email();

        // Gestione Contrassegno
        $importo_contrassegno = 0;
        $metodo_pagamento = $order->get_payment_method(); // Restituisce l'ID del metodo, es. 'cod' per Cash on Delivery
        $abilitazione_cod = get_option( 'gls_enable_cod', 'no' );

        if ( $metodo_pagamento === 'cod' && $abilitazione_cod === 'yes' ) {
            $importo_contrassegno = $order->get_total();
        }

        // Costruzione XML
        $xml = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= '<Info>';
        $xml .= '<SedeGls>' . esc_html( $sede ) . '</SedeGls>';
        $xml .= '<CodiceClienteGls>' . esc_html( $cliente ) . '</CodiceClienteGls>';
        $xml .= '<PasswordClienteGls>' . esc_html( $password ) . '</PasswordClienteGls>';
        $xml .= '<AddParcelResult>S</AddParcelResult>'; 
        
        $xml .= '<Parcel>';
        if ( ! empty( $contratto ) ) {
            $xml .= '<CodiceContrattoGls>' . esc_html( $contratto ) . '</CodiceContrattoGls>';
        }
        $xml .= '<RagioneSociale><![CDATA[' . substr( $ragione_sociale, 0, 35 ) . ']]></RagioneSociale>';
        $xml .= '<Indirizzo><![CDATA[' . substr( $indirizzo, 0, 35 ) . ']]></Indirizzo>';
        $xml .= '<Localita><![CDATA[' . substr( $localita, 0, 30 ) . ']]></Localita>';
        $xml .= '<Provincia>' . substr( $provincia, 0, 2 ) . '</Provincia>';
        $xml .= '<Zipcode>' . substr( $cap, 0, 5 ) . '</Zipcode>';
        // Formattazione importo COD: usare la virgola come richiesto da GLS, o il punto, controlla se richiesto formato specifico. Il default PHP è punto.
        $xml .= '<Contrassegno>' . number_format( $importo_contrassegno, 2, '.', '' ) . '</Contrassegno>'; 
        $xml .= '<Colli>1</Colli>';
        $xml .= '<Peso>1</Peso>';
        $xml .= '<Telefono>' . esc_html( $telefono ) . '</Telefono>';
        $xml .= '<IndirizzoEmail><![CDATA[' . esc_html( $email ) . ']]></IndirizzoEmail>';
        $xml .= '</Parcel>';
        $xml .= '</Info>';

        return $xml;
    }

    private function parse_gls_response( $xml_response, $order ) {
        $xml = @simplexml_load_string( $xml_response );
        if ( $xml === false ) {
            $order->add_order_note( 'GLS Error: Impossibile leggere la risposta XML.' );
            return;
        }

        if ( isset( $xml->Parcel->DescrizioneErrore ) && !empty( (string) $xml->Parcel->DescrizioneErrore ) ) {
            $order->add_order_note( 'Errore GLS: ' . (string) $xml->Parcel->DescrizioneErrore );
            return;
        }

        if ( isset( $xml->Parcel->NumeroSpedizione ) ) {
            $tracking_number = (string) $xml->Parcel->NumeroSpedizione;
            update_post_meta( $order->get_id(), '_gls_tracking_number', $tracking_number );
            
            $note = 'Spedizione GLS generata con successo. Tracking: ' . $tracking_number;

            if ( isset( $xml->Parcel->PdfLabel ) && !empty( (string) $xml->Parcel->PdfLabel ) ) {
                $pdf_decoded = base64_decode( (string) $xml->Parcel->PdfLabel );
                $upload_dir = wp_upload_dir();
                $pdf_filename = 'GLS_Label_' . $tracking_number . '.pdf';
                $pdf_path = $upload_dir['path'] . '/' . $pdf_filename;
                $pdf_url = $upload_dir['url'] . '/' . $pdf_filename;
                
                file_put_contents( $pdf_path, $pdf_decoded );
                update_post_meta( $order->get_id(), '_gls_label_url', $pdf_url );
                $note .= ' | <a href="' . $pdf_url . '" target="_blank">Scarica Etichetta</a>';
            }

            $order->add_order_note( $note );
        }
    }

    // --- SEZIONE 3: CRONJOB E CLOSE WORK DAY ---

    public function schedule_cron() {
        if ( ! wp_next_scheduled( 'gls_daily_close_work_day' ) ) {
            // Imposta l'orario alle 19:00 ora locale del server
            $time_to_run = strtotime( '19:00:00' );
            
            // Se le 19:00 sono già passate oggi, programma per domani
            if ( $time_to_run <= current_time( 'timestamp' ) ) {
                $time_to_run += DAY_IN_SECONDS;
            }
            
            wp_schedule_event( $time_to_run, 'daily', 'gls_daily_close_work_day' );
        }
    }

    public function clear_cron() {
        $timestamp = wp_next_scheduled( 'gls_daily_close_work_day' );
        wp_unschedule_event( $timestamp, 'gls_daily_close_work_day' );
    }

    public function execute_close_work_day() {
        $sede = get_option( 'gls_sede' );
        $cliente = get_option( 'gls_codice_cliente' );
        $password = get_option( 'gls_password' );

        if ( empty( $sede ) || empty( $cliente ) || empty( $password ) ) {
            error_log( 'GLS Cron Error: Credenziali mancanti per la chiusura giornata.' );
            return;
        }

        $xml = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= '<Info>';
        $xml .= '<SedeGls>' . esc_html( $sede ) . '</SedeGls>';
        $xml .= '<CodiceClienteGls>' . esc_html( $cliente ) . '</CodiceClienteGls>';
        $xml .= '<PasswordClienteGls>' . esc_html( $password ) . '</PasswordClienteGls>';
        // Richiediamo il file PDF di riepilogo a fine giornata (se previsto dal manuale)
        $xml .= '<CloseWorkDayResult>S</CloseWorkDayResult>'; 
        $xml .= '</Info>';

        $response = wp_remote_post( $this->api_url_closeworkday, array(
            'method'      => 'POST',
            'timeout'     => 60, // Aumentato per l'elaborazione di fine giornata
            'body'        => array( 'XMLInfo' => $xml ) // Attenzione: CloseWorkDay si aspetta 'XMLInfo', non 'XMLInfoParcel'
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'GLS CloseWorkDay Error: ' . $response->get_error_message() );
            return;
        }

        // Il corpo della risposta conterrà l'esito della chiusura.
        $body = wp_remote_retrieve_body( $response );
        error_log( 'GLS CloseWorkDay Eseguito: ' . substr( $body, 0, 200 ) ); // Log di base per controllo
    }
}

// Azione per gestire la chiusura manuale dal form nelle impostazioni
add_action( 'admin_post_gls_manual_close_work_day', 'gls_manual_cwd_handler' );
function gls_manual_cwd_handler() {
    if ( isset( $_POST['gls_cwd_nonce'] ) && wp_verify_nonce( $_POST['gls_cwd_nonce'], 'gls_manual_cwd' ) && current_user_can( 'manage_woocommerce' ) ) {
        $gls = new GLS_WooCommerce_Integration_Advanced();
        $gls->execute_close_work_day();
        wp_redirect( admin_url( 'admin.php?page=gls-settings&cwd_success=1' ) );
        exit;
    }
}

// --- SEZIONE 4: METODO DI SPEDIZIONE WOOCOMMERCE CON TARIFFE GLS ---

add_action( 'woocommerce_shipping_init', 'gls_custom_shipping_method_init' );

function gls_custom_shipping_method_init() {
    if ( ! class_exists( 'WC_GLS_Contract_Shipping_Method' ) ) {
        class WC_GLS_Contract_Shipping_Method extends WC_Shipping_Method {

            public function __construct() {
                $this->id                 = 'gls_contract_shipping';
                $this->method_title       = 'Corriere GLS (Contratto)';
                $this->method_description = 'Calcola le tariffe di spedizione in base agli scaglioni di peso e alle zone del tuo contratto GLS.';

                $this->availability = 'including';
                $this->countries = array( 'IT' );

                $this->init();
                $this->enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'yes';
                $this->title   = isset( $this->settings['title'] ) ? $this->settings['title'] : 'Corriere Espresso GLS';
            }

            public function init() {
                $this->init_form_fields();
                $this->init_settings();

                add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
            }

            public function init_form_fields() {
                // Qui creiamo i campi dove potrai inserire i prezzi del tuo PDF
                $this->form_fields = array(
                    'enabled' => array(
                        'title'   => 'Abilita/Disabilita',
                        'type'    => 'checkbox',
                        'label'   => 'Abilita questo metodo di spedizione',
                        'default' => 'yes',
                    ),
                    'title' => array(
                        'title'       => 'Titolo al Checkout',
                        'type'        => 'text',
                        'description' => 'Il nome che vedrà il cliente nel carrello.',
                        'default'     => 'Corriere Espresso GLS',
                    ),
                    'rate_0_3' => array(
                        'title'       => 'Tariffa da 0 a 3 Kg (€)',
                        'type'        => 'number',
                        'custom_attributes' => array( 'step' => '0.01' ),
                        'default'     => '6.00',
                    ),
                    'rate_3_5' => array(
                        'title'       => 'Tariffa da 3 a 5 Kg (€)',
                        'type'        => 'number',
                        'custom_attributes' => array( 'step' => '0.01' ),
                        'default'     => '7.50',
                    ),
                    'rate_5_10' => array(
                        'title'       => 'Tariffa da 5 a 10 Kg (€)',
                        'type'        => 'number',
                        'custom_attributes' => array( 'step' => '0.01' ),
                        'default'     => '9.00',
                    ),
                    'rate_10_20' => array(
                        'title'       => 'Tariffa da 10 a 20 Kg (€)',
                        'type'        => 'number',
                        'custom_attributes' => array( 'step' => '0.01' ),
                        'default'     => '12.00',
                    ),
                    'rate_extra_kg' => array(
                        'title'       => 'Costo per ogni Kg oltre i 20 Kg (€)',
                        'type'        => 'number',
                        'custom_attributes' => array( 'step' => '0.01' ),
                        'default'     => '0.50',
                    ),
                    'surcharge_islands' => array(
                        'title'       => 'Maggiorazione Calabria, Sicilia e Sardegna (€)',
                        'type'        => 'number',
                        'description' => 'Costo FISSO da sommare alla tariffa base per queste regioni.',
                        'custom_attributes' => array( 'step' => '0.01' ),
                        'default'     => '2.00',
                    ),
                );
            }

            public function calculate_shipping( $package = array() ) {
                $weight = WC()->cart->get_cart_contents_weight();
                $destination_state = $package['destination']['state'];
                
                // Province di Sicilia, Sardegna e Calabria
                $islands_and_calabria = array( 'AG', 'CL', 'CT', 'EN', 'ME', 'PA', 'RG', 'SR', 'TP', 'CA', 'NU', 'OR', 'SS', 'SU', 'CZ', 'CS', 'KR', 'RC', 'VV' );

                $cost = 0;

                // Calcolo in base agli scaglioni di peso
                if ( $weight <= 3 ) {
                    $cost = $this->get_option( 'rate_0_3' );
                } elseif ( $weight <= 5 ) {
                    $cost = $this->get_option( 'rate_3_5' );
                } elseif ( $weight <= 10 ) {
                    $cost = $this->get_option( 'rate_5_10' );
                } elseif ( $weight <= 20 ) {
                    $cost = $this->get_option( 'rate_10_20' );
                } else {
                    $base_cost = $this->get_option( 'rate_10_20' );
                    $extra_weight = ceil( $weight - 20 ); // Arrotonda per eccesso i kg extra
                    $extra_cost = $extra_weight * $this->get_option( 'rate_extra_kg' );
                    $cost = $base_cost + $extra_cost;
                }

                // Aggiungi maggiorazione Isole e Calabria
                if ( in_array( $destination_state, $islands_and_calabria ) ) {
                    $cost += $this->get_option( 'surcharge_islands' );
                }

                $rate = array(
                    'id'    => $this->id,
                    'label' => $this->title,
                    'cost'  => $cost,
                );

                $this->add_rate( $rate );
            }
        }
    }
}

add_filter( 'woocommerce_shipping_methods', 'add_gls_custom_shipping_method' );
function add_gls_custom_shipping_method( $methods ) {
    $methods['gls_contract_shipping'] = 'WC_GLS_Contract_Shipping_Method';
    return $methods;
}


new GLS_WooCommerce_Integration_Advanced();

