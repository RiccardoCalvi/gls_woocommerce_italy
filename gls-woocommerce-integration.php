<?php
/**
 * Plugin Name: GLS Italy WooCommerce Integration
 * Plugin URI: https://github.com/RiccardoCalvi/gls_woocommerce_italy
 * Description: Integrazione API GLS (Etichette) + Calcolo Tariffe di Spedizione e Contrassegno.
 * Version: 1.4.0
 * Author: Dream2Dev
 * Requires at least: 5.8
 * Tested up to: 6.7
 * WC requires at least: 7.0
 * WC tested up to: 9.6
 *
 * Changelog v1.4.0:
 *   - BREAKING: rimosso completamente il sistema cronjob (wp-cron, endpoint server-side,
 *     token segreto, pulsante CWD massivo). La CloseWorkDay (CWDBSN) ora viene eseguita
 *     singolarmente per ogni ordine tramite un pulsante dedicato nel dettaglio ordine.
 *     Motivazione: la CloseWorkDay va lanciata solo per gli ordini effettivamente affidati
 *     al corriere, non in modo automatico/massivo.
 *   - Nuova azione ordine "Affida a GLS (CloseWorkDay)": pulsante nel dropdown azioni
 *     della pagina dettaglio ordine che invia la CWDBSN per quel singolo ordine,
 *     passando il codice di tracciamento. Disponibile solo se l'ordine ha un tracking
 *     GLS e non è già stato confermato.
 *   - Nuova opzione "Abilita Log Dettagliati" nella pagina impostazioni GLS:
 *     quando attiva, il plugin logga nelle note ordine e in error_log il body XML
 *     delle richieste inviate e le risposte ricevute da ogni chiamata API
 *     (AddParcel, CWDBSN, DeleteSped).
 *   - Implementato sistema di logging condizionale: ogni chiamata API logga
 *     request body (con password mascherata) e response body sia nelle note
 *     ordine sia in wp error_log, solo se i log sono abilitati.
 *   - Rimossi: schedule_cron(), clear_cron(), handle_cron_endpoint(),
 *     execute_close_work_day() massivo, mark_orders_as_closed(),
 *     generate_cron_token(), get_or_create_cron_token(), get_cron_endpoint_url(),
 *     gls_manual_cwd_handler(), opzione gls_cron_secret_token, sezione UI cronjob.
 *   - Pulizia: rimossa registrazione deactivation hook per cron (non più necessaria).

*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Impedisce l'accesso diretto al file
}

// ============================================================================
// DICHIARAZIONE COMPATIBILITÀ HPOS (High-Performance Order Storage)
// ============================================================================
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );


// ============================================================================
// CLASSE PER AUTO-UPDATE DA GITHUB
// ============================================================================
class GLS_GitHub_Updater {
    /** @var string Percorso completo del file principale del plugin */
    private $file;
    /** @var string Basename del plugin (es. "cartella/file.php") */
    private $plugin;
    /** @var string Alias di $plugin per compatibilità */
    private $basename;
    /** @var bool Indica se il plugin è attivo */
    private $active;
    /** @var string Username GitHub del repository */
    private $username;
    /** @var string Nome del repository GitHub */
    private $repository;

    /**
     * Costruttore: registra i filtri per l'auto-update.
     *
     * @param string $file Percorso del file principale del plugin (__FILE__)
     */
    public function __construct( $file ) {
        $this->file       = $file;
        $this->username   = 'RiccardoCalvi';
        $this->repository = 'gls_woocommerce_italy';
        $this->add_plugin_hooks();
    }

    /**
     * Registra i filtri WordPress per intercettare il check aggiornamenti
     * e le richieste di informazioni plugin.
     */
    private function add_plugin_hooks() {
        $this->plugin   = plugin_basename( $this->file );
        $this->basename = plugin_basename( $this->file );
        $this->active   = is_plugin_active( $this->basename );

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
    }

    /**
     * Interroga l'API GitHub per ottenere l'ultima release disponibile.
     *
     * @return object|false Oggetto JSON della release o false in caso di errore
     */
    private function get_repository_info() {
        $request_uri = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->username,
            $this->repository
        );

        $response = wp_remote_get( $request_uri, array( 'timeout' => 10 ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) != 200 ) {
            return false;
        }

        return json_decode( wp_remote_retrieve_body( $response ) );
    }

    /**
     * Confronta la versione corrente con l'ultima release GitHub.
     * Se disponibile un aggiornamento, lo aggiunge al transient di WordPress.
     *
     * @param object $transient Transient degli aggiornamenti plugin
     * @return object Transient eventualmente modificato
     */
    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $github_info = $this->get_repository_info();
        if ( ! $github_info ) {
            return $transient;
        }

        $plugin_data     = get_plugin_data( $this->file );
        $current_version = $plugin_data['Version'];
        // Rimuove il prefisso "v" dal tag (es. "v1.1.0" → "1.1.0")
        $remote_version = str_replace( 'v', '', $github_info->tag_name );

        if ( version_compare( $current_version, $remote_version, '<' ) ) {
            $obj              = new stdClass();
            $obj->slug        = $this->basename;
            $obj->new_version = $github_info->tag_name;
            $obj->url         = $plugin_data['PluginURI'];
            $obj->package     = $github_info->zipball_url;

            $transient->response[ $this->basename ] = $obj;
        }

        return $transient;
    }

    /**
     * Fornisce le informazioni del plugin quando WordPress le richiede
     * (es. nella schermata "Dettagli del plugin").
     *
     * @param false|object|array $res    Risultato corrente
     * @param string             $action Tipo di azione (es. "plugin_information")
     * @param object             $args   Argomenti della richiesta
     * @return false|object Informazioni del plugin o $res originale
     */
    public function plugin_info( $res, $action, $args ) {
        if ( $action !== 'plugin_information' || current_filter() !== 'plugins_api' ) {
            return $res;
        }

        if ( isset( $args->slug ) && $args->slug === $this->basename ) {
            $github_info = $this->get_repository_info();
            if ( $github_info ) {
                $plugin_data = get_plugin_data( $this->file );

                $res                = new stdClass();
                $res->name          = $plugin_data['Name'];
                $res->slug          = $this->basename;
                $res->version       = $github_info->tag_name;
                $res->author        = $plugin_data['Author'];
                $res->homepage      = $plugin_data['PluginURI'];
                $res->download_link = $github_info->zipball_url;
                $res->sections      = array(
                    'description' => $plugin_data['Description'],
                    'changelog'   => nl2br( $github_info->body ),
                );
            }
        }

        return $res;
    }
}
// Inizializza l'auto-updater
new GLS_GitHub_Updater( __FILE__ );


// ============================================================================
// CORE DEL PLUGIN GLS
// Gestione spedizioni (AddParcel), cancellazione (DeleteSped),
// chiusura singola per ordine (CWDBSN), pagina impostazioni e azioni ordine.
// ============================================================================
class GLS_WooCommerce_Integration_Advanced {

    /**
     * Endpoint API GLS per la creazione spedizioni (AddParcel).
     * Ref: MU162 Label Service v30, sezione 5.1
     */
    private $api_url_addparcel = 'https://labelservice.gls-italy.com/ilswebservice.asmx/AddParcel';

    /**
     * Endpoint API GLS per la chiusura per numero spedizione (CWDBSN).
     * Ref: MU162 Label Service v30, sezione 5.3
     *
     * Utilizzato per confermare alla sede GLS una singola spedizione
     * già creata tramite AddParcel, passando il numero di spedizione.
     */
    private $api_url_cwdbsn = 'https://labelservice.gls-italy.com/ilswebservice.asmx/CloseWorkDayByShipmentNumber';

    /**
     * Endpoint API GLS per la cancellazione di una spedizione (DeleteSped).
     * Ref: MU162 Label Service v30, sezione 5.4
     */
    private $api_url_deletesped = 'https://labelservice.gls-italy.com/ilswebservice.asmx/DeleteSped';

    /**
     * URL base per il tracking GLS Italy.
     */
    private $tracking_base_url = 'https://www.gls-italy.com/it/servizi/servizi-per-chi-riceve/ricerca-spedizioni?match=';

    /**
     * Proprietà statica per trasmettere l'ID ordine corrente allo shortcode
     * durante il rendering delle email WooCommerce.
     *
     * @var int|null
     */
    private static $current_email_order_id = null;

    /**
     * Costruttore: registra tutti gli hook WordPress/WooCommerce necessari.
     */
    public function __construct() {
        // Generazione automatica etichetta quando l'ordine passa a "In lavorazione"
        add_action( 'woocommerce_order_status_processing', array( $this, 'generate_gls_shipment' ), 10, 1 );

        // Cancellazione spedizione GLS quando l'ordine viene annullato
        add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_gls_shipment' ), 10, 1 );

        // Aggiunge azioni manuali nel dropdown azioni ordine (backend):
        //   - "Genera/Rigenera Etichetta GLS"  → crea la spedizione (AddParcel)
        //   - "Affida a GLS (CloseWorkDay)"    → conferma la spedizione alla sede (CWDBSN)
        add_action( 'woocommerce_order_actions', array( $this, 'add_gls_order_actions' ) );
        add_action( 'woocommerce_order_action_gls_generate_label', array( $this, 'process_gls_order_action' ) );
        add_action( 'woocommerce_order_action_gls_close_work_day', array( $this, 'process_gls_close_work_day_action' ) );

        // Pagina impostazioni nel menu WooCommerce
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Shortcode per mostrare il tracking number nelle email al cliente
        add_shortcode( 'gls_tracking_number', array( $this, 'tracking_number_shortcode' ) );

        // Hook per catturare l'ordine corrente PRIMA del rendering dell'email
        add_action( 'woocommerce_email_before_order_table', array( $this, 'capture_email_order_id' ), 1, 1 );

        // Mostra il blocco tracking GLS nella pagina "Visualizza ordine" dell'account cliente
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_tracking_on_order_page' ), 10, 1 );

        // Inietta il tracking GLS nei metadati ordine delle email WooCommerce native
        add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'add_tracking_to_email_meta' ), 10, 3 );
    }

    // ========================================================================
    // SISTEMA DI LOGGING CONDIZIONALE
    //
    // Il logging dettagliato è controllato dall'opzione gls_enable_logging.
    // Quando attivo, vengono loggati:
    //   - Request body XML (con password mascherata) nelle note ordine e error_log
    //   - Response body (HTTP code + contenuto) nelle note ordine e error_log
    // Quando disattivo, vengono loggati solo gli errori critici in error_log.
    // ========================================================================

    /**
     * Verifica se il logging dettagliato è abilitato nelle impostazioni.
     *
     * @return bool True se i log dettagliati sono attivi
     */
    private function is_logging_enabled() {
        return get_option( 'gls_enable_logging', 'no' ) === 'yes';
    }

    /**
     * Logga un messaggio in error_log con prefisso GLS.
     * Questo log viene SEMPRE scritto, indipendentemente dall'opzione logging.
     *
     * @param string $message Messaggio da loggare
     */
    private function log_error( $message ) {
        error_log( 'GLS: ' . $message );
    }

    /**
     * Logga un messaggio dettagliato in error_log SOLO se il logging è abilitato.
     *
     * @param string $message Messaggio da loggare
     */
    private function log_debug( $message ) {
        if ( $this->is_logging_enabled() ) {
            error_log( 'GLS DEBUG: ' . $message );
        }
    }

    /**
     * Aggiunge una nota di log dettagliato all'ordine SOLO se il logging è abilitato.
     *
     * @param WC_Order $order   Oggetto ordine WooCommerce
     * @param string   $message Messaggio da aggiungere come nota
     */
    private function log_order_note( $order, $message ) {
        if ( $this->is_logging_enabled() ) {
            $order->add_order_note( '🔍 [GLS LOG] ' . $message );
        }
    }

    /**
     * Maschera la password all'interno di una stringa XML per il logging sicuro.
     * Sostituisce il contenuto del tag <PasswordClienteGls> con asterischi.
     *
     * @param string $xml Stringa XML contenente la password
     * @return string XML con password mascherata
     */
    private function mask_password_in_xml( $xml ) {
        return preg_replace(
            '/<PasswordClienteGls>.*?<\/PasswordClienteGls>/',
            '<PasswordClienteGls>***MASKED***</PasswordClienteGls>',
            $xml
        );
    }

    /**
     * Logga il request body e la response di una chiamata API.
     * Scrive sia nelle note ordine (se logging abilitato) sia in error_log.
     *
     * @param WC_Order    $order       Oggetto ordine WooCommerce
     * @param string      $method_name Nome del metodo API (es. "AddParcel", "CWDBSN")
     * @param string      $request_body Body XML della richiesta inviata
     * @param int         $http_code    Codice HTTP della risposta
     * @param string      $response_body Body della risposta ricevuta
     */
    private function log_api_call( $order, $method_name, $request_body, $http_code, $response_body ) {
        // Maschera la password nel request body per sicurezza
        $safe_request = $this->mask_password_in_xml( $request_body );

        // Tronca le risposte lunghe per evitare note ordine enormi
        $truncated_response = mb_substr( $response_body, 0, 1500 );
        if ( mb_strlen( $response_body ) > 1500 ) {
            $truncated_response .= '... [troncato]';
        }

        // Log in error_log (sempre se logging attivo)
        $this->log_debug(
            $method_name . ' order #' . $order->get_id()
            . ' | REQUEST: ' . mb_substr( $safe_request, 0, 800 )
            . ' | HTTP ' . $http_code
            . ' | RESPONSE: ' . mb_substr( $response_body, 0, 800 )
        );

        // Log nelle note ordine (solo se logging abilitato)
        $this->log_order_note( $order,
            $method_name . " — REQUEST:\n" . $safe_request
        );
        $this->log_order_note( $order,
            $method_name . " — RESPONSE (HTTP " . $http_code . "):\n" . $truncated_response
        );
    }

    // ========================================================================
    // HELPER: costruisce l'URL di tracking GLS completo
    // ========================================================================

    /**
     * Costruisce l'URL di tracking GLS Italy per un dato numero di spedizione.
     *
     * @param string $tracking Numero di tracking GLS
     * @return string URL completo per il tracking
     */
    private function get_tracking_url( $tracking ) {
        return $this->tracking_base_url . urlencode( $tracking );
    }

    // ========================================================================
    // HELPER: lettura/scrittura meta ordine compatibili HPOS
    // ========================================================================

    /**
     * Legge un meta dell'ordine in modo compatibile con HPOS.
     *
     * @param int|WC_Order $order_or_id Oggetto ordine o ID ordine
     * @param string       $key         Chiave del meta
     * @return string Valore del meta o stringa vuota
     */
    private function get_order_meta( $order_or_id, $key ) {
        $order = ( $order_or_id instanceof WC_Order ) ? $order_or_id : wc_get_order( $order_or_id );
        if ( ! $order ) {
            return '';
        }
        return $order->get_meta( $key, true );
    }

    /**
     * Scrive uno o più meta dell'ordine e salva, in modo compatibile con HPOS.
     *
     * @param WC_Order $order Oggetto ordine WooCommerce
     * @param array    $metas Array associativo chiave => valore dei meta da scrivere
     */
    private function update_order_meta( $order, $metas ) {
        foreach ( $metas as $key => $value ) {
            $order->update_meta_data( $key, $value );
        }
        $order->save();
    }

    /**
     * Elimina uno o più meta dell'ordine e salva, in modo compatibile con HPOS.
     *
     * @param WC_Order $order Oggetto ordine WooCommerce
     * @param array    $keys  Array di chiavi meta da eliminare
     */
    private function delete_order_meta( $order, $keys ) {
        foreach ( $keys as $key ) {
            $order->delete_meta_data( $key );
        }
        $order->save();
    }

    // ========================================================================
    // AZIONI ORDINE (dropdown nella pagina dettaglio ordine backend)
    // ========================================================================

    /**
     * Aggiunge le azioni GLS nel dropdown azioni della pagina dettaglio ordine.
     *
     * Azioni disponibili:
     *   - "Genera/Rigenera Etichetta GLS"   → sempre visibile
     *   - "Affida a GLS (CloseWorkDay)"     → visibile solo se tracking presente
     *                                          e ordine non ancora confermato
     *
     * @param array $actions Azioni disponibili
     * @return array Azioni modificate
     */
    public function add_gls_order_actions( $actions ) {
        global $theorder;

        // Azione: genera/rigenera etichetta (sempre disponibile)
        $actions['gls_generate_label'] = 'Genera/Rigenera Etichetta GLS';

        // Azione: CloseWorkDay singolo (solo se tracking presente e non ancora chiuso)
        if ( $theorder instanceof WC_Order ) {
            $tracking   = $theorder->get_meta( '_gls_tracking_number', true );
            $cwd_closed = $theorder->get_meta( '_gls_cwd_closed', true );

            if ( ! empty( $tracking ) && empty( $cwd_closed ) ) {
                $actions['gls_close_work_day'] = 'Affida a GLS (CloseWorkDay)';
            }
        }

        return $actions;
    }

    /**
     * Callback: rigenerazione etichetta GLS dall'azione ordine.
     * Forza la rigenerazione anche se esiste già un tracking.
     *
     * @param WC_Order $order Oggetto ordine WooCommerce
     */
    public function process_gls_order_action( $order ) {
        $this->generate_gls_shipment( $order->get_id(), true );
    }

    /**
     * Callback: esegue il CloseWorkDay (CWDBSN) per il singolo ordine.
     *
     * Questa azione viene invocata quando l'operatore preme
     * "Affida a GLS (CloseWorkDay)" nel dropdown azioni dell'ordine.
     * Invia la CWDBSN con il solo tracking number di questo ordine.
     *
     * @param WC_Order $order Oggetto ordine WooCommerce
     */
    public function process_gls_close_work_day_action( $order ) {
        $this->execute_close_work_day_single( $order );
    }

    /**
     * Registra la pagina di impostazioni come sottomenu di WooCommerce.
     */
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

    /**
     * Registra tutte le opzioni del plugin nel database WordPress.
     */
    public function register_settings() {
        // Credenziali API GLS
        register_setting( 'gls_settings_group', 'gls_sede' );
        register_setting( 'gls_settings_group', 'gls_codice_cliente' );
        register_setting( 'gls_settings_group', 'gls_password' );
        register_setting( 'gls_settings_group', 'gls_codice_contratto' );

        // Costi e tasse
        register_setting( 'gls_settings_group', 'gls_vat_rate' );
        register_setting( 'gls_settings_group', 'gls_free_shipping_threshold' );

        // Contrassegno (COD)
        register_setting( 'gls_settings_group', 'gls_enable_cod' );
        register_setting( 'gls_settings_group', 'gls_cod_fee_percentage' );
        register_setting( 'gls_settings_group', 'gls_cod_min_fee' );

        // Logging dettagliato
        register_setting( 'gls_settings_group', 'gls_enable_logging' );
    }

    /**
     * Renderizza l'HTML della pagina impostazioni GLS.
     * Include campi per credenziali, costi/tasse, contrassegno e logging.
     */
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
                    <!-- ============ CREDENZIALI API ============ -->
                    <tr><th colspan="2"><h3>Credenziali API</h3></th></tr>
                    <tr>
                        <th scope="row">Sede GLS (Sigla)</th>
                        <td>
                            <input type="text" name="gls_sede" value="<?php echo esc_attr( get_option( 'gls_sede' ) ); ?>" maxlength="2" placeholder="Es. MI" />
                            <br><small>Sigla di 2 caratteri della sede GLS (es. R1, MI, YH).</small>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Codice Cliente</th>
                        <td>
                            <input type="text" name="gls_codice_cliente" value="<?php echo esc_attr( get_option( 'gls_codice_cliente' ) ); ?>" maxlength="6" />
                            <br><small>Codice numerico di max 6 cifre fornito da GLS.</small>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Password</th>
                        <td><input type="password" name="gls_password" value="<?php echo esc_attr( get_option( 'gls_password' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Codice Contratto</th>
                        <td>
                            <input type="text" name="gls_codice_contratto" value="<?php echo esc_attr( get_option( 'gls_codice_contratto' ) ); ?>" maxlength="4" />
                            <br><small>Codice numerico di max 4 cifre. Inserisci il valore esatto fornito da GLS (es. 2734).</small>
                        </td>
                    </tr>

                    <!-- ============ COSTI E TASSE ============ -->
                    <tr><th colspan="2"><hr><h3>Impostazioni Costi e Tasse</h3></th></tr>
                    <tr>
                        <th scope="row">Aliquota IVA Spedizioni (%)</th>
                        <td><input type="number" step="1" name="gls_vat_rate" value="<?php echo esc_attr( get_option( 'gls_vat_rate', '22' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Soglia Spedizione Gratuita (€)</th>
                        <td>
                            <input type="number" step="0.01" name="gls_free_shipping_threshold" value="<?php echo esc_attr( get_option( 'gls_free_shipping_threshold', '0' ) ); ?>" />
                            <br><small>Imposta 0 per disabilitare la spedizione gratuita.</small>
                        </td>
                    </tr>

                    <!-- ============ CONTRASSEGNO (COD) ============ -->
                    <tr><th colspan="2"><hr><h3>Impostazioni Contrassegno (COD)</h3></th></tr>
                    <tr>
                        <th scope="row">Abilita Trasmissione Contrassegno</th>
                        <td>
                            <label>
                                <input type="checkbox" name="gls_enable_cod" value="yes" <?php checked( get_option( 'gls_enable_cod' ), 'yes' ); ?> />
                                Trasmetti a GLS l'incasso del contrassegno.
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Percentuale Contrassegno (%)</th>
                        <td><input type="number" step="0.1" name="gls_cod_fee_percentage" value="<?php echo esc_attr( get_option( 'gls_cod_fee_percentage', '2' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Costo Minimo Contrassegno (€ netto)</th>
                        <td><input type="number" step="0.01" name="gls_cod_min_fee" value="<?php echo esc_attr( get_option( 'gls_cod_min_fee', '5.00' ) ); ?>" /></td>
                    </tr>

                    <!-- ============ LOG DETTAGLIATI ============ -->
                    <tr><th colspan="2"><hr><h3>Diagnostica e Log</h3></th></tr>
                    <tr>
                        <th scope="row">Abilita Log Dettagliati</th>
                        <td>
                            <label>
                                <input type="checkbox" name="gls_enable_logging" value="yes" <?php checked( get_option( 'gls_enable_logging', 'no' ), 'yes' ); ?> />
                                Registra nelle note ordine e in <code>error_log</code> il body delle richieste XML e le risposte API.
                            </label>
                            <br><small>
                                Utile per il debug. Le password vengono automaticamente mascherate nei log.<br>
                                <strong>⚠️ Disabilitare in produzione</strong> per evitare un accumulo eccessivo di note ordine.
                            </small>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Salva Impostazioni' ); ?>
            </form>

            <hr>
            <h2>Istruzioni d'uso</h2>
            <h3>Flusso operativo</h3>
            <ol style="line-height:2;">
                <li>Quando un ordine passa in stato <strong>"In lavorazione"</strong>, il plugin genera automaticamente l'etichetta GLS (AddParcel) e salva il tracking number.</li>
                <li>Quando il pacco è stato preparato e affidato al corriere, apri il dettaglio ordine e dal dropdown <strong>"Azioni ordine"</strong> seleziona <strong>"Affida a GLS (CloseWorkDay)"</strong>.</li>
                <li>Il plugin invierà la conferma alla sede GLS (CWDBSN) per quel singolo ordine.</li>
                <li>Se l'ordine viene annullato, il plugin cancella automaticamente la spedizione su GLS (DeleteSped).</li>
            </ol>
            <p style="background:#d4edda; border:1px solid #28a745; padding:10px 14px; border-radius:4px; margin:16px 0;">
                <strong>💡 Nota:</strong> Il pulsante "Affida a GLS" è visibile nel dropdown azioni solo se l'ordine ha un tracking GLS e non è già stato confermato.
            </p>

            <hr>
            <h2>Shortcode disponibili</h2>
            <p>
                <strong>[gls_tracking_number]</strong> — Mostra il codice di tracking GLS nell'email o nei template.<br>
                <small>Usa questo shortcode nei template email WooCommerce nativi (es. "Ordine completato") per includere il codice di tracciamento.</small>
            </p>
            <h3>Per utenti YayMail Pro</h3>
            <p>
                Nel builder YayMail, usa questi shortcode (disponibili nella sezione "Custom Shortcode"):<br>
                <code>[yaymail_custom_shortcode_gls_tracking]</code> — codice tracking testuale (es. 661209312)<br>
                <code>[yaymail_custom_shortcode_gls_tracking_link]</code> — bottone HTML cliccabile con link GLS<br>
                <small>Se il tracking non è ancora disponibile, gli shortcode non mostrano nulla.</small>
            </p>
        </div>
        <?php
    }

    // ========================================================================
    // GENERAZIONE SPEDIZIONE (AddParcel)
    // Ref: MU162 Label Service v30, sezione 5.1
    // ========================================================================

    /**
     * Genera una spedizione GLS per l'ordine indicato.
     * Viene chiamato automaticamente al cambio stato → processing,
     * oppure manualmente dall'azione ordine.
     *
     * @param int  $order_id ID dell'ordine WooCommerce
     * @param bool $force    Se true, rigenera anche se tracking già presente
     */
    public function generate_gls_shipment( $order_id, $force = false ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Se il tracking esiste già e non è forzato, non rigenera
        if ( ! $force && $order->get_meta( '_gls_tracking_number', true ) ) {
            return;
        }

        // Costruisce l'XML conforme alla specifica MU162
        $xml_data = $this->build_add_parcel_xml( $order );
        if ( ! $xml_data ) {
            $order->add_order_note( 'GLS Error: Credenziali GLS mancanti nelle impostazioni. Etichetta non generata.' );
            return;
        }

        // Log del request body (se logging attivo)
        $this->log_debug( 'AddParcel order #' . $order_id . ' — invio richiesta...' );

        // Invio richiesta HTTP POST all'endpoint AddParcel
        // Il parametro si chiama "XMLInfoParcel" come da documentazione MU162
        $response = wp_remote_post( $this->api_url_addparcel, array(
            'method'  => 'POST',
            'timeout' => 45,
            'body'    => array( 'XMLInfoParcel' => $xml_data ),
        ) );

        if ( is_wp_error( $response ) ) {
            $error_msg = $response->get_error_message();
            $order->add_order_note( 'GLS Error di rete: ' . $error_msg );
            $this->log_error( 'AddParcel network error order #' . $order_id . ': ' . $error_msg );
            // Log della richiesta anche in caso di errore di rete
            $this->log_api_call( $order, 'AddParcel', $xml_data, 0, 'NETWORK ERROR: ' . $error_msg );
            return;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );

        // Log completo della chiamata API (request + response)
        $this->log_api_call( $order, 'AddParcel', $xml_data, $http_code, $body );

        if ( $http_code != 200 ) {
            $order->add_order_note( 'GLS HTTP Error ' . $http_code . ': Il server ha rifiutato la richiesta.' );
            $this->log_error( 'AddParcel HTTP ' . $http_code . ' for order #' . $order_id );
            return;
        }

        // Parsing della risposta XML
        $this->parse_gls_response( $body, $order );
    }

    /**
     * Costruisce la stringa XML per il metodo AddParcel conforme alla
     * documentazione MU162 Label Service v30 (AddParcel-CWD).
     *
     * @param WC_Order $order Oggetto ordine WooCommerce
     * @return string|false XML generato o false se credenziali mancanti
     */
    private function build_add_parcel_xml( $order ) {
        // Recupera le credenziali dalle impostazioni
        $sede      = trim( get_option( 'gls_sede' ) );
        $cliente   = trim( get_option( 'gls_codice_cliente' ) );
        $password  = trim( get_option( 'gls_password' ) );
        $contratto = trim( get_option( 'gls_codice_contratto' ) );

        // Validazione credenziali obbligatorie
        if ( empty( $sede ) || empty( $cliente ) || empty( $password ) || empty( $contratto ) ) {
            return false;
        }

        // Dati destinatario dall'ordine
        $ragione_sociale = $order->get_shipping_company()
            ? $order->get_shipping_company()
            : $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name();
        $indirizzo = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
        $localita  = $order->get_shipping_city();
        $provincia = $order->get_shipping_state();
        $cap       = $order->get_shipping_postcode();

        // Calcolo contrassegno (COD)
        $is_cod               = ( $order->get_payment_method() === 'cod' && get_option( 'gls_enable_cod', 'no' ) === 'yes' );
        $importo_contrassegno = $is_cod ? (float) $order->get_total() : 0;

        // Peso del pacco: somma dei pesi dei prodotti, default 1 Kg
        $peso = 0;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && $product->get_weight() ) {
                $peso += (float) $product->get_weight() * $item->get_quantity();
            }
        }
        $peso = round( max( $peso, 1 ), 1 );

        // --- Costruzione XML ---
        $xml = '<Info>';
        $xml .= '<SedeGls>' . esc_html( $sede ) . '</SedeGls>';
        $xml .= '<CodiceClienteGls>' . esc_html( $cliente ) . '</CodiceClienteGls>';
        $xml .= '<PasswordClienteGls>' . esc_html( $password ) . '</PasswordClienteGls>';
        $xml .= '<AddParcelResult>S</AddParcelResult>';

        $xml .= '<Parcel>';
        $xml .= '<CodiceContrattoGls>' . esc_html( $contratto ) . '</CodiceContrattoGls>';
        $xml .= '<RagioneSociale><![CDATA[' . mb_substr( $ragione_sociale, 0, 35 ) . ']]></RagioneSociale>';
        $xml .= '<Indirizzo><![CDATA[' . mb_substr( $indirizzo, 0, 35 ) . ']]></Indirizzo>';
        $xml .= '<Localita><![CDATA[' . mb_substr( $localita, 0, 30 ) . ']]></Localita>';
        $xml .= '<Zipcode>' . substr( $cap, 0, 5 ) . '</Zipcode>';
        $xml .= '<Provincia>' . mb_substr( $provincia, 0, 2 ) . '</Provincia>';
        $xml .= '<Bda>' . $order->get_id() . '</Bda>';
        $xml .= '<Colli>1</Colli>';
        $xml .= '<PesoReale>' . number_format( $peso, 1, ',', '' ) . '</PesoReale>';

        if ( $importo_contrassegno > 0 ) {
            $xml .= '<ImportoContrassegno>' . number_format( $importo_contrassegno, 2, ',', '' ) . '</ImportoContrassegno>';
            $xml .= '<ModalitaIncasso>CONT</ModalitaIncasso>';
        }

        $xml .= '<TipoPorto>F</TipoPorto>';
        $xml .= '<TipoSpedizione>N</TipoSpedizione>';
        $xml .= '<TipoCollo>0</TipoCollo>';
        $xml .= '<RiferimentoCliente>' . $order->get_order_number() . '</RiferimentoCliente>';

        $phone = $order->get_billing_phone();
        if ( ! empty( $phone ) ) {
            $xml .= '<Cellulare1>' . esc_html( substr( $phone, 0, 20 ) ) . '</Cellulare1>';
        }

        $email = $order->get_billing_email();
        if ( ! empty( $email ) ) {
            $xml .= '<Email><![CDATA[' . $email . ']]></Email>';
        }

        $xml .= '<GeneraPdf>4</GeneraPdf>';
        $xml .= '<NoteSpedizione><![CDATA[Ordine #' . $order->get_order_number() . ']]></NoteSpedizione>';

        $xml .= '</Parcel>';
        $xml .= '</Info>';

        return $xml;
    }

    /**
     * Analizza la risposta XML del metodo AddParcel.
     *
     * @param string   $xml_response Corpo della risposta HTTP
     * @param WC_Order $order        Oggetto ordine WooCommerce
     */
    private function parse_gls_response( $xml_response, $order ) {
        // Fase 1: Gestione wrapper ASMX
        $inner_xml = $this->extract_asmx_response( $xml_response );

        // Fase 2: Parsing dell'XML effettivo
        $xml = @simplexml_load_string( $inner_xml );
        if ( $xml === false ) {
            $order->add_order_note( 'GLS Error: Risposta XML non valida dal server.' );
            $this->log_error( 'AddParcel parse error order #' . $order->get_id() . ': ' . substr( $xml_response, 0, 500 ) );
            return;
        }

        // Fase 3: Controllo errore bloccante a livello globale
        if ( isset( $xml->DescrizioneErrore ) && ! empty( (string) $xml->DescrizioneErrore ) ) {
            $order->add_order_note( 'Errore GLS (bloccante): ' . (string) $xml->DescrizioneErrore );
            return;
        }

        // Fase 4: Controllo errore bloccante a livello Parcel
        if ( isset( $xml->Parcel->DescrizioneErrore ) && ! empty( (string) $xml->Parcel->DescrizioneErrore ) ) {
            $order->add_order_note( 'Errore GLS (API): ' . (string) $xml->Parcel->DescrizioneErrore );
            return;
        }

        // Fase 5: Estrazione NumeroSpedizione
        if ( isset( $xml->Parcel->NumeroSpedizione ) && ! empty( trim( (string) $xml->Parcel->NumeroSpedizione ) ) ) {
            $track = trim( (string) $xml->Parcel->NumeroSpedizione );

            // Salva il tracking number nei metadati dell'ordine (HPOS-compatible)
            $this->update_order_meta( $order, array(
                '_gls_tracking_number' => $track,
                'gls_tracking_number'  => $track,
            ) );

            // Determina se è una spedizione GLS CHECK (routing fallito)
            $sede_destino    = isset( $xml->Parcel->DescrizioneSedeDestino ) ? trim( (string) $xml->Parcel->DescrizioneSedeDestino ) : '';
            $note_spedizione = isset( $xml->Parcel->NoteSpedizione ) ? trim( (string) $xml->Parcel->NoteSpedizione ) : '';
            $is_gls_check    = ( stripos( $sede_destino, 'GLS Check' ) !== false )
                            || ( stripos( $note_spedizione, 'Dati non accettabili' ) !== false )
                            || ( stripos( $note_spedizione, 'non conforme a stradario' ) !== false );

            if ( $is_gls_check ) {
                $note  = '⚠️ Spedizione GLS creata come GLS CHECK. Tracking: ' . $track;
                $note .= ' | Avviso GLS: ' . esc_html( $note_spedizione );
                $note .= ' | La sede GLS correggerà automaticamente l\'instradamento.';
            } else {
                $note = '✅ Spedizione GLS creata con successo! Tracking: ' . $track;
                if ( ! empty( $sede_destino ) ) {
                    $note .= ' | Sede destino: ' . esc_html( $sede_destino );
                }
            }

            // Gestione etichetta PDF (codificata in Base64 nel tag <PdfLabel>)
            $pdf_url = '';
            if ( isset( $xml->Parcel->PdfLabel ) && ! empty( (string) $xml->Parcel->PdfLabel ) ) {
                $upload_dir  = wp_upload_dir();
                $pdf_path    = $upload_dir['path'] . '/GLS_Label_' . $track . '.pdf';
                $pdf_url     = $upload_dir['url'] . '/GLS_Label_' . $track . '.pdf';
                $pdf_content = base64_decode( (string) $xml->Parcel->PdfLabel );

                if ( $pdf_content !== false && strlen( $pdf_content ) > 0 ) {
                    file_put_contents( $pdf_path, $pdf_content );
                    $note .= ' | <a href="' . esc_url( $pdf_url ) . '" target="_blank">Scarica Etichetta PDF</a>';

                    $this->update_order_meta( $order, array(
                        '_gls_label_pdf_url' => $pdf_url,
                    ) );

                    // Invia l'etichetta PDF all'email dell'amministratore
                    $this->send_label_to_admin( $order, $track, $pdf_path, $pdf_url );
                }
            }

            $order->add_order_note( $note );
            return;
        }

        // Fase 6: Nessun NumeroSpedizione trovato
        $note_sped = isset( $xml->Parcel->NoteSpedizione ) ? (string) $xml->Parcel->NoteSpedizione : 'N/A';
        $order->add_order_note(
            'GLS Error: Nessun NumeroSpedizione nella risposta. NoteSpedizione: ' . esc_html( $note_sped )
        );
        $this->log_error( 'AddParcel no tracking number order #' . $order->get_id() . ' - XML: ' . substr( $inner_xml, 0, 500 ) );
    }

    // ========================================================================
    // EMAIL ETICHETTA ALL'AMMINISTRATORE
    // ========================================================================

    /**
     * Invia l'etichetta PDF GLS all'email dell'amministratore del sito.
     *
     * @param WC_Order $order     Oggetto ordine WooCommerce
     * @param string   $tracking  Numero di tracking GLS
     * @param string   $pdf_path  Percorso fisico del file PDF sul server
     * @param string   $pdf_url   URL pubblico del file PDF
     */
    private function send_label_to_admin( $order, $tracking, $pdf_path, $pdf_url ) {
        $admin_email = get_option( 'admin_email' );

        $subject = sprintf(
            '[GLS] Etichetta spedizione Ordine #%s - Tracking: %s',
            $order->get_order_number(),
            $tracking
        );

        $customer_name    = $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name();
        $shipping_address = $order->get_shipping_address_1() . ', ' . $order->get_shipping_city() . ' (' . $order->get_shipping_state() . ')';

        $message  = "Nuova etichetta GLS generata automaticamente.\n\n";
        $message .= "=== DATI SPEDIZIONE ===\n";
        $message .= "Ordine: #" . $order->get_order_number() . "\n";
        $message .= "Tracking GLS: " . $tracking . "\n";
        $message .= "Destinatario: " . $customer_name . "\n";
        $message .= "Indirizzo: " . $shipping_address . "\n";
        $message .= "Prodotti: " . $order->get_item_count() . " articolo/i\n\n";
        $message .= "=== ISTRUZIONI ===\n";
        $message .= "1. Stampa l'etichetta allegata (formato 10x15 cm)\n";
        $message .= "2. Applica l'etichetta sul pacco\n";
        $message .= "3. Consegna il pacco al corriere GLS\n";
        $message .= "4. Dal dettaglio ordine, seleziona 'Affida a GLS (CloseWorkDay)' per confermare la spedizione alla sede GLS\n\n";
        $message .= "Link diretto al PDF: " . $pdf_url . "\n\n";
        $message .= "Tracking online: " . $this->get_tracking_url( $tracking ) . "\n";

        $headers     = array( 'Content-Type: text/plain; charset=UTF-8' );
        $attachments = array();
        if ( file_exists( $pdf_path ) ) {
            $attachments[] = $pdf_path;
        }

        $sent = wp_mail( $admin_email, $subject, $message, $headers, $attachments );

        if ( ! $sent ) {
            $this->log_error( 'Impossibile inviare email etichetta per ordine #' . $order->get_id() );
        }
    }

    // ========================================================================
    // SHORTCODE TRACKING NUMBER + INTEGRAZIONE EMAIL + PAGINA ORDINE CLIENTE
    // ========================================================================

    /**
     * Hook: cattura l'ID ordine corrente prima del rendering dell'email WooCommerce.
     *
     * @param WC_Order $order Oggetto ordine passato dal sistema email WooCommerce
     */
    public function capture_email_order_id( $order ) {
        if ( $order instanceof WC_Order ) {
            self::$current_email_order_id = $order->get_id();
        }
    }

    /**
     * Aggiunge il tracking GLS ai campi meta delle email WooCommerce native.
     *
     * @param array    $fields Campi meta già registrati
     * @param bool     $sent_to_admin Se l'email è diretta all'admin
     * @param WC_Order $order Oggetto ordine corrente
     * @return array Campi meta con tracking GLS aggiunto
     */
    public function add_tracking_to_email_meta( $fields, $sent_to_admin, $order ) {
        if ( ! $order instanceof WC_Order ) {
            return $fields;
        }

        $tracking = $order->get_meta( '_gls_tracking_number', true );
        if ( ! empty( $tracking ) ) {
            $tracking_url = $this->get_tracking_url( $tracking );
            $fields['gls_tracking'] = array(
                'label' => __( 'Tracking GLS', 'woocommerce' ),
                'value' => '<a href="' . esc_url( $tracking_url ) . '" target="_blank" style="color:#e2001a;font-weight:bold;">'
                         . esc_html( $tracking ) . '</a>',
            );
        }

        return $fields;
    }

    /**
     * Shortcode [gls_tracking_number] per i template email WooCommerce.
     *
     * @param array $atts Attributi dello shortcode
     * @return string HTML del tracking o stringa fallback
     */
    public function tracking_number_shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'order_id' => 0,
                'link'     => 'yes',
                'fallback' => '',
            ),
            $atts,
            'gls_tracking_number'
        );

        $order_id = (int) $atts['order_id'];

        if ( ! $order_id && self::$current_email_order_id ) {
            $order_id = self::$current_email_order_id;
        }

        if ( ! $order_id ) {
            global $post;
            if ( $post && in_array( $post->post_type, array( 'shop_order', 'wc_order' ), true ) ) {
                $order_id = $post->ID;
            }
        }

        if ( ! $order_id ) {
            return esc_html( $atts['fallback'] );
        }

        $tracking = $this->get_order_meta( $order_id, '_gls_tracking_number' );

        if ( empty( $tracking ) ) {
            return esc_html( $atts['fallback'] );
        }

        if ( $atts['link'] === 'yes' ) {
            $tracking_url = $this->get_tracking_url( $tracking );
            return '<a href="' . esc_url( $tracking_url ) . '" target="_blank" style="color:#e2001a;font-weight:bold;">'
                . esc_html( $tracking )
                . '</a>';
        }

        return esc_html( $tracking );
    }

    /**
     * Mostra il blocco di tracking GLS nella pagina "Visualizza ordine" dell'account cliente.
     *
     * @param WC_Order $order Oggetto ordine corrente
     */
    public function display_tracking_on_order_page( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $tracking = $order->get_meta( '_gls_tracking_number', true );

        if ( empty( $tracking ) ) {
            return;
        }

        $tracking_url = $this->get_tracking_url( $tracking );
        ?>
        <section class="woocommerce-gls-tracking" style="margin:2em 0; padding:1em 1.5em; background:#f8f8f8; border-left:4px solid #f2c200;">
            <h2 style="font-size:1em; margin:0 0 0.5em; color:#333;">
                <?php esc_html_e( 'Informazioni di Spedizione GLS', 'woocommerce' ); ?>
            </h2>
            <p style="margin:0; font-size:0.95em; color:#555;">
                <?php esc_html_e( 'Il tuo pacco è in consegna con GLS. Usa il codice qui sotto per tracciare la spedizione:', 'woocommerce' ); ?>
            </p>
            <p style="margin:0.75em 0 0;">
                <strong><?php esc_html_e( 'Codice di tracking:', 'woocommerce' ); ?></strong>
                &nbsp;
                <a href="<?php echo esc_url( $tracking_url ); ?>" target="_blank" rel="noopener noreferrer"
                   style="color:#f2c200; font-weight:bold; font-size:1.1em;">
                    <?php echo esc_html( $tracking ); ?>
                </a>
                &nbsp;
                <a href="<?php echo esc_url( $tracking_url ); ?>" target="_blank" rel="noopener noreferrer"
                   style="display: inline-block; margin-left: 0.5em; padding: 15px 30px 15px 30px; background: #f2c200; color: #fff;">
                    <?php esc_html_e( 'Traccia spedizione →', 'woocommerce' ); ?>
                </a>
            </p>
        </section>
        <?php
    }

    // ========================================================================
    // CANCELLAZIONE SPEDIZIONE (DeleteSped)
    // Ref: MU162 Label Service v30, sezione 5.4
    // ========================================================================

    /**
     * Cancella la spedizione GLS quando un ordine WooCommerce viene annullato.
     *
     * STRATEGIA DI CHIAMATA (Ref: MU162 §5.4):
     *   Livello 1: HTTP POST form-encoded con parametri individuali
     *   Livello 2: SOAP 1.1 con elementi XML individuali nel Body
     *
     * @param int $order_id ID dell'ordine WooCommerce annullato
     */
    public function cancel_gls_shipment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Verifica se esiste un tracking number per questo ordine (HPOS-compatible)
        $tracking = $order->get_meta( '_gls_tracking_number', true );
        if ( empty( $tracking ) ) {
            return;
        }

        // Recupera le credenziali
        $sede     = trim( get_option( 'gls_sede' ) );
        $cliente  = trim( get_option( 'gls_codice_cliente' ) );
        $password = trim( get_option( 'gls_password' ) );

        if ( empty( $sede ) || empty( $cliente ) || empty( $password ) ) {
            $order->add_order_note( 'GLS Error: Credenziali mancanti. Impossibile cancellare la spedizione ' . $tracking . ' su GLS. Contatta manualmente la sede GLS.' );
            return;
        }

        $this->log_debug( 'DeleteSped order #' . $order_id . ': sede=' . $sede . ' tracking=' . $tracking );

        // --- LIVELLO 1: HTTP POST con parametri individuali ---
        $form_params = array(
            'SedeGls'            => $sede,
            'CodiceClienteGls'   => $cliente,
            'PasswordClienteGls' => $password,
            'NumSpedizione'      => $tracking,
        );

        // Costruisce una rappresentazione loggabile dei parametri (password mascherata)
        $log_params = $form_params;
        $log_params['PasswordClienteGls'] = '***MASKED***';
        $request_log_body = http_build_query( $log_params );

        $response = wp_remote_post( $this->api_url_deletesped, array(
            'method'  => 'POST',
            'timeout' => 30,
            'body'    => $form_params,
        ) );

        $http_post_success = false;
        $http_code         = 0;
        $body              = '';

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'DeleteSped HTTP POST network error order #' . $order_id . ': ' . $response->get_error_message() );
            $this->log_api_call( $order, 'DeleteSped (HTTP POST)', $request_log_body, 0, 'NETWORK ERROR: ' . $response->get_error_message() );
        } else {
            $http_code = wp_remote_retrieve_response_code( $response );
            $body      = wp_remote_retrieve_body( $response );

            // Log della chiamata HTTP POST
            $this->log_api_call( $order, 'DeleteSped (HTTP POST)', $request_log_body, $http_code, $body );

            if ( $http_code === 200 ) {
                $http_post_success = true;
            }
        }

        // --- LIVELLO 2: SOAP 1.1 fallback ---
        if ( ! $http_post_success ) {
            $this->log_debug( 'DeleteSped: HTTP POST fallito (HTTP ' . $http_code . '). Provo SOAP 1.1...' );

            $soap_result = $this->delete_sped_soap_individual( $sede, $cliente, $password, $tracking, $order_id, $order );

            if ( $soap_result !== false ) {
                $http_code = $soap_result['http_code'];
                $body      = $soap_result['body'];

                if ( $http_code === 200 ) {
                    $http_post_success = true;
                }
            }
        }

        // --- Gestione errori di rete ---
        if ( ! $http_post_success && $http_code === 0 ) {
            $order->add_order_note(
                'GLS Error di rete durante la cancellazione spedizione ' . $tracking . '. '
                . 'Nessun tentativo ha avuto successo. Contatta manualmente la sede GLS.'
            );
            return;
        }

        // --- Gestione HTTP 500 persistente ---
        if ( $http_code === 500 ) {
            $order->add_order_note(
                '⚠️ GLS: Il server ha restituito HTTP 500 durante la cancellazione della spedizione ' . $tracking . '. '
                . 'Possibili cause: (1) la funzione DeleteSped non è abilitata per questo account GLS, '
                . '(2) l\'ambiente di test GLS non espone il metodo DeleteSped. '
                . 'Contatta la sede GLS per procedere manualmente alla cancellazione. '
                . 'Il tracking è stato rimosso dall\'ordine.'
            );
            $this->delete_order_meta( $order, array( '_gls_tracking_number', 'gls_tracking_number', '_gls_label_pdf_url' ) );
            return;
        }

        // --- Gestione altri errori HTTP ---
        if ( $http_code !== 200 ) {
            $order->add_order_note(
                'GLS HTTP Error ' . $http_code . ' durante la cancellazione spedizione ' . $tracking . '. Contatta manualmente la sede GLS.'
            );
            return;
        }

        // --- Parsing della risposta ---
        $this->parse_delete_sped_response( $body, $order, $tracking );
    }

    /**
     * Esegue la chiamata DeleteSped tramite SOAP 1.1 con parametri individuali.
     *
     * @param string   $sede     Sigla sede GLS
     * @param string   $cliente  Codice cliente GLS
     * @param string   $password Password cliente GLS
     * @param string   $tracking Numero spedizione da cancellare
     * @param int      $order_id ID ordine per logging
     * @param WC_Order $order    Oggetto ordine per logging nelle note
     * @return array|false Array con 'http_code' e 'body', o false in caso di errore rete
     */
    private function delete_sped_soap_individual( $sede, $cliente, $password, $tracking, $order_id, $order ) {
        $soap_url    = 'https://labelservice.gls-italy.com/ilswebservice.asmx';
        $soap_action = 'https://labelservice.gls-italy.com/DeleteSped';

        $soap_envelope  = '<?xml version="1.0" encoding="utf-8"?>';
        $soap_envelope .= '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ';
        $soap_envelope .= 'xmlns:xsd="http://www.w3.org/2001/XMLSchema" ';
        $soap_envelope .= 'xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">';
        $soap_envelope .= '<soap:Body>';
        $soap_envelope .= '<DeleteSped xmlns="https://labelservice.gls-italy.com/">';
        $soap_envelope .= '<SedeGls>' . esc_html( $sede ) . '</SedeGls>';
        $soap_envelope .= '<CodiceClienteGls>' . esc_html( $cliente ) . '</CodiceClienteGls>';
        $soap_envelope .= '<PasswordClienteGls>' . esc_html( $password ) . '</PasswordClienteGls>';
        $soap_envelope .= '<NumSpedizione>' . esc_html( $tracking ) . '</NumSpedizione>';
        $soap_envelope .= '</DeleteSped>';
        $soap_envelope .= '</soap:Body>';
        $soap_envelope .= '</soap:Envelope>';

        $response = wp_remote_post( $soap_url, array(
            'method'  => 'POST',
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction'   => '"' . $soap_action . '"',
            ),
            'body' => $soap_envelope,
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'DeleteSped SOAP network error order #' . $order_id . ': ' . $response->get_error_message() );
            $this->log_api_call( $order, 'DeleteSped (SOAP)', $soap_envelope, 0, 'NETWORK ERROR: ' . $response->get_error_message() );
            return false;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );

        // Log della chiamata SOAP
        $this->log_api_call( $order, 'DeleteSped (SOAP)', $soap_envelope, $http_code, $body );

        $extracted = $this->extract_soap_response( $body );
        return array(
            'http_code' => $http_code,
            'body'      => $extracted,
        );
    }

    /**
     * Estrae il contenuto utile da una risposta SOAP.
     *
     * @param string $soap_response Risposta SOAP completa
     * @return string Contenuto estratto, o la risposta originale se non parsabile
     */
    private function extract_soap_response( $soap_response ) {
        if ( preg_match( '/<\w*Result[^>]*>(.*?)<\/\w*Result>/s', $soap_response, $matches ) ) {
            $result = $matches[1];
            if ( strpos( $result, '&lt;' ) !== false ) {
                $result = html_entity_decode( $result, ENT_QUOTES, 'UTF-8' );
            }
            return $result;
        }

        if ( preg_match( '/<faultstring[^>]*>(.*?)<\/faultstring>/s', $soap_response, $matches ) ) {
            return 'SOAP Fault: ' . $matches[1];
        }

        return $soap_response;
    }

    /**
     * Analizza la risposta della chiamata DeleteSped.
     *
     * @param string   $response_body Corpo della risposta HTTP grezza
     * @param WC_Order $order         Oggetto ordine WooCommerce
     * @param string   $tracking      Numero di tracking GLS
     */
    private function parse_delete_sped_response( $response_body, $order, $tracking ) {
        $inner = $this->extract_asmx_response( $response_body );

        $xml = @simplexml_load_string( $inner );

        if ( $xml !== false && isset( $xml->DescrizioneErrore ) ) {
            $desc = trim( (string) $xml->DescrizioneErrore );
        } else {
            $desc = trim( strip_tags( $inner ) );
        }

        $desc_lower = strtolower( $desc );

        if ( strpos( $desc_lower, 'avvenuta' ) !== false || strpos( $desc_lower, 'eliminazione' ) !== false ) {
            $order->add_order_note( '✅ Spedizione GLS ' . $tracking . ' cancellata con successo sul webservice GLS.' );
            $this->delete_order_meta( $order, array( '_gls_tracking_number', 'gls_tracking_number', '_gls_label_pdf_url' ) );

        } elseif ( strpos( $desc_lower, 'non presente' ) !== false ) {
            $order->add_order_note( 'ℹ️ Spedizione GLS ' . $tracking . ' non trovata sul webservice (potrebbe essere già stata cancellata).' );
            $this->delete_order_meta( $order, array( '_gls_tracking_number', 'gls_tracking_number' ) );

        } elseif ( strpos( $desc_lower, 'funzionalità non abilitata' ) !== false ) {
            $order->add_order_note( '⛔ GLS: Funzionalità DeleteSped non abilitata per questo account. Contatta la sede GLS per annullare manualmente la spedizione ' . $tracking . '.' );

        } else {
            $note  = '⚠️ GLS: Risposta cancellazione spedizione ' . $tracking . ': ' . esc_html( $desc );
            $note .= ' | ATTENZIONE: se la spedizione è già stata inviata alla sede GLS tramite CloseWorkDay, contatta direttamente la sede GLS per bloccarla fisicamente.';
            $order->add_order_note( $note );
        }
    }

    /**
     * Estrae il contenuto XML reale dalla risposta ASMX.
     *
     * @param string $raw_response Risposta HTTP grezza
     * @return string XML pulito pronto per il parsing
     */
    private function extract_asmx_response( $raw_response ) {
        // Rimuove eventuali BOM UTF-8
        $raw_response = ltrim( $raw_response, "\xEF\xBB\xBF" );

        $wrapper = @simplexml_load_string( $raw_response );

        if ( $wrapper !== false ) {
            $root_name = $wrapper->getName();

            if ( $root_name === 'string' ) {
                $inner = (string) $wrapper;
                if ( ! empty( $inner ) && strpos( $inner, '<' ) !== false ) {
                    return $inner;
                }
            }

            if ( in_array( $root_name, array( 'InfoLabel', 'Info' ), true ) || isset( $wrapper->Parcel ) ) {
                return $raw_response;
            }
        }

        return $raw_response;
    }

    // ========================================================================
    // CLOSEWORKDAY SINGOLO PER ORDINE (CWDBSN)
    // Ref: MU162 Label Service v30, sezione 5.3
    //
    // La CloseWorkDay viene ora eseguita singolarmente per ogni ordine
    // tramite il pulsante "Affida a GLS (CloseWorkDay)" nel dettaglio ordine.
    // Questo garantisce che solo gli ordini effettivamente affidati al corriere
    // vengano confermati alla sede GLS.
    //
    // Struttura XML inviata (singolo Parcel):
    //   <Info>
    //     <SedeGls>XX</SedeGls>
    //     <CodiceClienteGls>XXXXX</CodiceClienteGls>
    //     <PasswordClienteGls>XXXXX</PasswordClienteGls>
    //     <Parcel>
    //       <CodiceContrattoGls>XXXX</CodiceContrattoGls>
    //       <NumeroDiSpedizioneGLSDaConfermare>590000008</NumeroDiSpedizioneGLSDaConfermare>
    //     </Parcel>
    //   </Info>
    // ========================================================================

    /**
     * Esegue la chiamata CloseWorkDayByShipmentNumber per un singolo ordine.
     *
     * Invia alla sede GLS la conferma della spedizione associata all'ordine,
     * passando il numero di tracking come NumeroDiSpedizioneGLSDaConfermare.
     *
     * Ref: MU162 Label Service v30, sezione 5.3
     *
     * @param WC_Order $order Oggetto ordine WooCommerce
     */
    public function execute_close_work_day_single( $order ) {
        $order_id = $order->get_id();

        // --- Validazione tracking ---
        $tracking = $order->get_meta( '_gls_tracking_number', true );
        if ( empty( $tracking ) ) {
            $order->add_order_note( '⚠️ GLS CWD: Impossibile eseguire CloseWorkDay — nessun tracking GLS presente per questo ordine.' );
            return;
        }

        // --- Verifica se già confermato ---
        $cwd_closed = $order->get_meta( '_gls_cwd_closed', true );
        if ( ! empty( $cwd_closed ) ) {
            $order->add_order_note( 'ℹ️ GLS CWD: Questo ordine è già stato confermato alla sede GLS in data ' . esc_html( $cwd_closed ) . '.' );
            return;
        }

        // --- Validazione credenziali ---
        $sede      = trim( get_option( 'gls_sede' ) );
        $cliente   = trim( get_option( 'gls_codice_cliente' ) );
        $password  = trim( get_option( 'gls_password' ) );
        $contratto = trim( get_option( 'gls_codice_contratto' ) );

        if ( empty( $sede ) || empty( $cliente ) || empty( $password ) || empty( $contratto ) ) {
            $order->add_order_note( 'GLS CWD Error: Credenziali mancanti nelle impostazioni. CloseWorkDay non eseguito.' );
            $this->log_error( 'CWD Error order #' . $order_id . ': credenziali mancanti.' );
            return;
        }

        // --- Costruzione XML per CWDBSN (singolo Parcel) ---
        // Ref: MU162 §5.3 — è sufficiente indicare il numero di spedizione
        // per confermare i dati già elaborati durante AddParcel.
        $xml  = '<Info>';
        $xml .= '<SedeGls>' . esc_html( $sede ) . '</SedeGls>';
        $xml .= '<CodiceClienteGls>' . esc_html( $cliente ) . '</CodiceClienteGls>';
        $xml .= '<PasswordClienteGls>' . esc_html( $password ) . '</PasswordClienteGls>';
        $xml .= '<Parcel>';
        $xml .= '<CodiceContrattoGls>' . esc_html( $contratto ) . '</CodiceContrattoGls>';
        $xml .= '<NumeroDiSpedizioneGLSDaConfermare>' . esc_html( $tracking ) . '</NumeroDiSpedizioneGLSDaConfermare>';
        $xml .= '</Parcel>';
        $xml .= '</Info>';

        $this->log_debug( 'CWDBSN order #' . $order_id . ' — invio conferma per tracking ' . $tracking );

        // --- Invio richiesta HTTP POST ---
        // Il parametro HTTP per CWDBSN è "_xmlRequest"
        // (il server lo dichiara nell'errore "Missing parameter: _xmlRequest.")
        $response = wp_remote_post( $this->api_url_cwdbsn, array(
            'method'  => 'POST',
            'timeout' => 60,
            'body'    => array( '_xmlRequest' => $xml ),
        ) );

        if ( is_wp_error( $response ) ) {
            $error_msg = $response->get_error_message();
            $order->add_order_note( 'GLS CWD Error di rete: ' . $error_msg );
            $this->log_error( 'CWDBSN network error order #' . $order_id . ': ' . $error_msg );
            $this->log_api_call( $order, 'CWDBSN', $xml, 0, 'NETWORK ERROR: ' . $error_msg );
            return;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );

        // Log completo della chiamata API (request + response)
        $this->log_api_call( $order, 'CWDBSN', $xml, $http_code, $body );

        if ( $http_code != 200 ) {
            $order->add_order_note( 'GLS CWD HTTP Error ' . $http_code . ': Il server ha rifiutato la richiesta CloseWorkDay.' );
            $this->log_error( 'CWDBSN HTTP ' . $http_code . ' order #' . $order_id );
            return;
        }

        // --- Parsing della risposta CWDBSN ---
        // Risposta attesa:
        //   <CloseWorkDayByShipmentNumberResult xmlns="">
        //     <DescrizioneErrore>OK</DescrizioneErrore>
        //     <Parcel>
        //       <NumeroDiSpedizioneGLSDaConfermare>590000008</NumeroDiSpedizioneGLSDaConfermare>
        //       <esito>OK</esito>
        //     </Parcel>
        //   </CloseWorkDayByShipmentNumberResult>
        $inner    = $this->extract_asmx_response( $body );
        $xml_resp = @simplexml_load_string( $inner );

        if ( $xml_resp === false ) {
            // Risposta non parsabile — trattiamo come successo se HTTP 200,
            // ma lo segnaliamo nelle note ordine
            $order->add_order_note(
                '⚠️ GLS CWD: Risposta HTTP 200 ma XML non parsabile per la spedizione ' . $tracking . '. '
                . 'La spedizione potrebbe essere stata confermata — verifica manualmente sul portale GLS.'
            );
            $this->log_error( 'CWDBSN parse error order #' . $order_id . ': ' . substr( $inner, 0, 500 ) );
            // NON marchiamo come chiuso: l'operatore verificherà manualmente
            return;
        }

        // Controlla errore globale
        $global_error = '';
        if ( isset( $xml_resp->DescrizioneErrore ) ) {
            $global_error = trim( (string) $xml_resp->DescrizioneErrore );
            $this->log_debug( 'CWDBSN order #' . $order_id . ' — esito globale: ' . $global_error );
        }

        // Se l'errore globale NON è "OK", è un errore bloccante
        if ( ! empty( $global_error ) && strtoupper( $global_error ) !== 'OK' ) {
            $order->add_order_note(
                '❌ GLS CWD: Errore nella conferma della spedizione ' . $tracking . ': ' . esc_html( $global_error )
            );
            return;
        }

        // Processa il Parcel nella risposta
        if ( isset( $xml_resp->Parcel ) ) {
            foreach ( $xml_resp->Parcel as $parcel ) {
                $num_sped = isset( $parcel->NumeroDiSpedizioneGLSDaConfermare )
                    ? trim( (string) $parcel->NumeroDiSpedizioneGLSDaConfermare )
                    : '';
                $esito = isset( $parcel->esito )
                    ? trim( (string) $parcel->esito )
                    : 'N/A';

                if ( strtoupper( $esito ) === 'OK' ) {
                    // Successo: marca l'ordine come confermato
                    $this->update_order_meta( $order, array(
                        '_gls_cwd_closed' => gmdate( 'Y-m-d H:i:s' ),
                    ) );
                    $order->add_order_note(
                        '✅ Spedizione GLS ' . $tracking . ' confermata alla sede GLS (CloseWorkDay). '
                        . 'La spedizione è ora in stato CHIUSA e sarà presa in carico dalla sede.'
                    );
                } else {
                    // Esito non OK: logga l'errore specifico
                    $order->add_order_note(
                        '❌ GLS CWD: Spedizione ' . $tracking . ' — esito: ' . esc_html( $esito )
                    );
                }
            }
        } else {
            // Nessun tag <Parcel> nella risposta ma esito globale OK
            // Trattiamo come successo
            if ( strtoupper( $global_error ) === 'OK' ) {
                $this->update_order_meta( $order, array(
                    '_gls_cwd_closed' => gmdate( 'Y-m-d H:i:s' ),
                ) );
                $order->add_order_note(
                    '✅ GLS CWD: Conferma ricevuta per spedizione ' . $tracking . ' (esito globale OK, nessun dettaglio Parcel nella risposta).'
                );
            }
        }
    }
}

// Inizializza il core del plugin
new GLS_WooCommerce_Integration_Advanced();


// ============================================================================
// INTEGRAZIONE YAYMAIL PRO — Shortcode custom per il tracking GLS
// ============================================================================

add_filter( 'yaymail_customs_shortcode', 'gls_register_yaymail_shortcodes', 10, 3 );

/**
 * Registra gli shortcode GLS nel pannello shortcode di YayMail Pro.
 *
 * @param array $shortcode_list Lista shortcode registrati
 * @param mixed $yaymail_informations Informazioni YayMail
 * @param array $args Argomenti con 'order' se presente un ordine reale
 * @return array Lista shortcode aggiornata
 */
function gls_register_yaymail_shortcodes( $shortcode_list, $yaymail_informations, $args = array() ) {

    $tracking_base_url = 'https://www.gls-italy.com/it/servizi/servizi-per-chi-riceve/ricerca-spedizioni?match=';

    // --- Shortcode 1: codice tracking testuale ---
    $tracking_value = '';
    if ( isset( $args['order'] ) && $args['order'] instanceof WC_Order ) {
        $tracking_value = $args['order']->get_meta( '_gls_tracking_number', true );
    }
    $shortcode_list['[yaymail_custom_shortcode_gls_tracking]'] = ! empty( $tracking_value )
        ? esc_html( $tracking_value )
        : '';

    // --- Shortcode 2: bottone HTML cliccabile con link tracking ---
    if ( ! empty( $tracking_value ) ) {
        $tracking_url = $tracking_base_url . urlencode( $tracking_value );
        $shortcode_list['[yaymail_custom_shortcode_gls_tracking_link]'] =
            '<a href="' . esc_url( $tracking_url ) . '" '
            . 'target="_blank" rel="noopener noreferrer" '
            . 'style="display: inline-block; margin-left: 0.5em; padding: 15px 30px 15px 30px; background: #f2c200; color: #fff;font-size:14px;">'
            . 'Traccia la tua spedizione GLS &rarr;'
            . '</a>';
    } else {
        $shortcode_list['[yaymail_custom_shortcode_gls_tracking_link]'] = '';
    }

    return $shortcode_list;
}


// ============================================================================
// TARIFFE E METODO DI SPEDIZIONE WooCommerce
// ============================================================================
add_action( 'woocommerce_shipping_init', 'gls_custom_shipping_method_init' );
function gls_custom_shipping_method_init() {
    if ( ! class_exists( 'WC_GLS_Contract_Shipping_Method' ) ) {

        /**
         * Metodo di spedizione WooCommerce per GLS.
         * Calcola le tariffe in base a peso, zona geografica e maggiorazioni
         * per isole minori. L'IVA viene applicata in automatico.
         */
        class WC_GLS_Contract_Shipping_Method extends WC_Shipping_Method {

            public function __construct() {
                $this->id                 = 'gls_contract_shipping';
                $this->method_title       = 'Corriere GLS (Contratto)';
                $this->method_description = 'Calcola le tariffe in base agli scaglioni netti. L\'IVA verrà aggiunta in automatico.';
                $this->availability       = 'including';
                $this->countries          = array( 'IT' );

                $this->init();
                $this->enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'yes';
                $this->title   = 'Corriere Espresso GLS';
            }

            public function init() {
                $this->init_form_fields();
                $this->init_settings();
                add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
            }

            public function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array(
                        'title'   => 'Abilita',
                        'type'    => 'checkbox',
                        'default' => 'yes',
                    ),

                    // --- Tariffe Base (Italia continentale) ---
                    'title_it'     => array( 'title' => 'Tariffe Base (Italia)', 'type' => 'title' ),
                    'it_0_3'       => array( 'title' => '0 - 3 Kg (€)', 'type' => 'number', 'default' => '4.90', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'it_3_5'       => array( 'title' => '3 - 5 Kg (€)', 'type' => 'number', 'default' => '5.50', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'it_5_10'      => array( 'title' => '5 - 10 Kg (€)', 'type' => 'number', 'default' => '9.00', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'it_10_20'     => array( 'title' => '10 - 20 Kg (€)', 'type' => 'number', 'default' => '10.50', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'it_20_50'     => array( 'title' => '20 - 50 Kg (€)', 'type' => 'number', 'default' => '16.50', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'it_50_100'    => array( 'title' => '50 - 100 Kg (€)', 'type' => 'number', 'default' => '25.00', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'it_extra_50'  => array( 'title' => 'Extra ogni 50Kg (fino a 500Kg) (€)', 'type' => 'number', 'default' => '16.50', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'it_extra_100' => array( 'title' => 'Extra ogni 100Kg (oltre 500Kg) (€)', 'type' => 'number', 'default' => '40.00', 'custom_attributes' => array( 'step' => '0.01' ) ),

                    // --- Tariffe Calabria e Sicilia ---
                    'title_cs'     => array( 'title' => 'Tariffe Calabria e Sicilia', 'type' => 'title' ),
                    'cs_0_3'       => array( 'title' => '0 - 3 Kg (€)', 'type' => 'number', 'default' => '5.20', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'cs_3_5'       => array( 'title' => '3 - 5 Kg (€)', 'type' => 'number', 'default' => '6.50', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'cs_5_10'      => array( 'title' => '5 - 10 Kg (€)', 'type' => 'number', 'default' => '13.00', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'cs_10_20'     => array( 'title' => '10 - 20 Kg (€)', 'type' => 'number', 'default' => '16.00', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'cs_20_50'     => array( 'title' => '20 - 50 Kg (€)', 'type' => 'number', 'default' => '22.00', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'cs_50_100'    => array( 'title' => '50 - 100 Kg (€)', 'type' => 'number', 'default' => '34.00', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'cs_extra_50'  => array( 'title' => 'Extra ogni 50Kg (fino a 500Kg) (€)', 'type' => 'number', 'default' => '22.00', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'cs_extra_100' => array( 'title' => 'Extra ogni 100Kg (oltre 500Kg) (€)', 'type' => 'number', 'default' => '40.00', 'custom_attributes' => array( 'step' => '0.01' ) ),

                    // --- Tariffe Sardegna ---
                    'title_sa'     => array( 'title' => 'Tariffe Sardegna', 'type' => 'title' ),
                    'sa_0_3'       => array( 'title' => '0 - 3 Kg (€)', 'type' => 'number', 'default' => '5.50', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'sa_3_5'       => array( 'title' => '3 - 5 Kg (€)', 'type' => 'number', 'default' => '7.00', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'sa_5_10'      => array( 'title' => '5 - 10 Kg (€)', 'type' => 'number', 'default' => '13.00', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'sa_10_20'     => array( 'title' => '10 - 20 Kg (€)', 'type' => 'number', 'default' => '16.00', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'sa_20_50'     => array( 'title' => '20 - 50 Kg (€)', 'type' => 'number', 'default' => '22.00', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'sa_50_100'    => array( 'title' => '50 - 100 Kg (€)', 'type' => 'number', 'default' => '34.00', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'sa_extra_50'  => array( 'title' => 'Extra ogni 50Kg (fino a 500Kg) (€)', 'type' => 'number', 'default' => '22.00', 'custom_attributes' => array( 'step' => '0.01' ) ),
                    'sa_extra_100' => array( 'title' => 'Extra ogni 100Kg (oltre 500Kg) (€)', 'type' => 'number', 'default' => '40.00', 'custom_attributes' => array( 'step' => '0.01' ) ),

                    // --- Maggiorazioni speciali ---
                    'title_other'   => array( 'title' => 'Altre Maggiorazioni', 'type' => 'title' ),
                    'minor_islands' => array(
                        'title'             => 'Maggiorazione Isole Minori/Laguna (ogni 100Kg) (€)',
                        'type'              => 'number',
                        'default'           => '18.50',
                        'custom_attributes' => array( 'step' => '0.01' ),
                    ),
                );
            }

            /**
             * Calcola il costo di spedizione in base al peso del carrello
             * e alla zona di destinazione.
             *
             * @param array $package Pacchetto di spedizione WooCommerce
             */
            public function calculate_shipping( $package = array() ) {
                $weight = WC()->cart->get_cart_contents_weight();
                if ( $weight <= 0 ) {
                    $weight = 2;
                }

                $state    = $package['destination']['state'];
                $postcode = $package['destination']['postcode'];

                $calabria_sicilia = array(
                    'CZ', 'CS', 'KR', 'RC', 'VV',
                    'AG', 'CL', 'CT', 'EN', 'ME', 'PA', 'RG', 'SR', 'TP',
                );
                $sardegna = array( 'CA', 'NU', 'OR', 'SS', 'SU' );

                if ( in_array( $state, $calabria_sicilia ) ) {
                    $prefix = 'cs_';
                } elseif ( in_array( $state, $sardegna ) ) {
                    $prefix = 'sa_';
                } else {
                    $prefix = 'it_';
                }

                $cost = 0;
                if ( $weight <= 3 ) {
                    $cost = (float) $this->get_option( $prefix . '0_3' );
                } elseif ( $weight <= 5 ) {
                    $cost = (float) $this->get_option( $prefix . '3_5' );
                } elseif ( $weight <= 10 ) {
                    $cost = (float) $this->get_option( $prefix . '5_10' );
                } elseif ( $weight <= 20 ) {
                    $cost = (float) $this->get_option( $prefix . '10_20' );
                } elseif ( $weight <= 50 ) {
                    $cost = (float) $this->get_option( $prefix . '20_50' );
                } elseif ( $weight <= 100 ) {
                    $cost = (float) $this->get_option( $prefix . '50_100' );
                } elseif ( $weight <= 500 ) {
                    $base  = (float) $this->get_option( $prefix . '50_100' );
                    $extra = (float) $this->get_option( $prefix . 'extra_50' );
                    $cost  = $base + ( ceil( ( $weight - 100 ) / 50 ) * $extra );
                } else {
                    $base      = (float) $this->get_option( $prefix . '50_100' );
                    $extra_50  = (float) $this->get_option( $prefix . 'extra_50' );
                    $extra_100 = (float) $this->get_option( $prefix . 'extra_100' );
                    $cost      = $base + ( 8 * $extra_50 ) + ( ceil( ( $weight - 500 ) / 100 ) * $extra_100 );
                }

                // Maggiorazione per isole minori e zone lagunari
                $isole_minori_cap = array(
                    '30121', '30122', '30123', '30124', '30125', '30126', '30132', '30133', '30141',
                    '80073', '80071',
                    '80074', '80075', '80076', '80077',
                );
                if ( in_array( $postcode, $isole_minori_cap ) ) {
                    $minor_rate = (float) $this->get_option( 'minor_islands' );
                    $cost      += ( ceil( $weight / 100 ) * $minor_rate );
                }

                // Applicazione IVA
                $vat_rate      = (float) get_option( 'gls_vat_rate', '22' );
                $cost_with_vat = $cost * ( 1 + ( $vat_rate / 100 ) );

                // Spedizione gratuita sopra soglia
                $free_threshold           = (float) get_option( 'gls_free_shipping_threshold', '0' );
                $cart_total_for_threshold = WC()->cart->get_cart_contents_total() + WC()->cart->get_cart_contents_tax();

                // Controllo coupon spedizione gratuita
                $has_free_shipping_coupon = false;
                if ( WC()->cart && ! empty( WC()->cart->get_applied_coupons() ) ) {
                    foreach ( WC()->cart->get_applied_coupons() as $coupon_code ) {
                        $coupon = new WC_Coupon( $coupon_code );
                        if ( $coupon->get_free_shipping() ) {
                            $has_free_shipping_coupon = true;
                            break;
                        }
                    }
                }

                if ( ( $free_threshold > 0 && $cart_total_for_threshold >= $free_threshold ) || $has_free_shipping_coupon ) {
                    $cost_with_vat = 0;
                }

                $this->add_rate( array(
                    'id'    => $this->id,
                    'label' => $this->title,
                    'cost'  => $cost_with_vat,
                ) );
            }
        }
    }
}

// Registra il metodo di spedizione GLS in WooCommerce
add_filter( 'woocommerce_shipping_methods', 'add_gls_custom_shipping_method' );
function add_gls_custom_shipping_method( $methods ) {
    $methods['gls_contract_shipping'] = 'WC_GLS_Contract_Shipping_Method';
    return $methods;
}


// ============================================================================
// CALCOLO SOVRATASSA CONTRASSEGNO NEL CARRELLO
// ============================================================================
add_action( 'woocommerce_cart_calculate_fees', 'gls_add_cod_fee', 20, 1 );

/**
 * Calcola e aggiunge il supplemento contrassegno al carrello.
 *
 * @param WC_Cart $cart Oggetto carrello WooCommerce
 */
function gls_add_cod_fee( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

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
        $min_fee    = (float) get_option( 'gls_cod_min_fee', '5.00' );
        $vat_rate   = (float) get_option( 'gls_vat_rate', '22' );

        $cart_total = $cart->get_cart_contents_total() + $cart->get_shipping_total();
        $base_fee     = max( $min_fee, $cart_total * ( $percentage / 100 ) );
        $fee_with_vat = $base_fee * ( 1 + ( $vat_rate / 100 ) );

        $cart->add_fee( 'Supplemento Contrassegno GLS', $fee_with_vat, false );
    }
}


// ============================================================================
// AGGIORNAMENTO CHECKOUT AL CAMBIO METODO DI PAGAMENTO
// ============================================================================
add_action( 'wp_footer', 'gls_force_checkout_update' );

/**
 * Inietta lo script jQuery per ricalcolare i totali al cambio metodo di pagamento.
 */
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