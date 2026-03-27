<?php
/**
 * Plugin Name: GLS Italy WooCommerce Integration
 * Plugin URI: https://github.com/RiccardoCalvi/gls_woocommerce_italy
 * Description: Integrazione API GLS (Etichette) + Calcolo Tariffe di Spedizione e Contrassegno.
 * Version: 1.1.1
 * Author: Dream2Dev
 *
 * Changelog v1.1.1:
 *   - Fix critico parsing risposta: NumeroSpedizione viene ora controllato PRIMA di NoteSpedizione
 *     L'API GLS restituisce SEMPRE un tracking (anche per GLS CHECK), ma il codice precedente
 *     faceva return al controllo NoteSpedizione senza mai leggere il tracking.
 *     Ref: MU162 v30, sez. 5.1.4 - "il numero ricevuto è già un numero di tracking ufficiale GLS"
 *   - Fix: gestione root element <InfoLabel xmlns=""> nella risposta (non <Info>)
 *   - Aggiunta gestione etichette GLS CHECK (routing fallito ma spedizione creata)
 *   - Aumentato log debug risposta da 300 a 2000 caratteri
 *   - Aggiunto log specifico di NumeroSpedizione, NoteSpedizione e DescrizioneSedeDestino
 *
 * Changelog v1.1.0:
 *   - Fix critico: corretti nomi tag XML per conformità con documentazione API GLS (MU162 Label Service v30)
 *     * <Contrassegno> → <ImportoContrassegno>
 *     * <Peso> → <PesoReale>
 *     * <Telefono> → <Cellulare1>
 *     * <IndirizzoEmail> → <Email>
 *   - Fix: formato decimale con virgola (es. "10,5") come richiesto dall'API GLS
 *   - Fix: parsing risposta ASMX - gestione wrapper <string xmlns="..."> del webservice
 *   - Fix: rimossa dichiarazione <?xml?> dal parametro form (non prevista dalla doc)
 *   - Aggiunto tag <GeneraPdf>4</GeneraPdf> per ricevere etichetta PDF in formato 10x15
 *   - Aggiunto tag <TipoPorto>F</TipoPorto> (Porto Franco) obbligatorio
 *   - Aggiunto tag <ModalitaIncasso>CONT</ModalitaIncasso> quando contrassegno attivo
 *   - Aggiunto tag <TipoSpedizione>N</TipoSpedizione> per spedizioni nazionali
 *   - Aggiunto log XML di debug nella nota ordine per facilitare troubleshooting
 *   - Implementato cron CloseWorkDay con scheduling effettivo
 *   - Fix: verifica nonce nel handler manuale CloseWorkDay
 *   - Aggiunti commenti esaustivi in tutto il codice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Impedisce l'accesso diretto al file
}

// ============================================================================
// CLASSE PER AUTO-UPDATE DA GITHUB
// Verifica la presenza di nuove release su GitHub e notifica WordPress.
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
        $this->file = $file;
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
        $remote_version  = str_replace( 'v', '', $github_info->tag_name );

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
     * @param false|object|array $res   Risultato corrente
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
// Gestione spedizioni (AddParcel), chiusura giornaliera (CloseWorkDay),
// pagina impostazioni e azioni ordine.
// ============================================================================
class GLS_WooCommerce_Integration_Advanced {

    /**
     * Endpoint API GLS per la creazione spedizioni (AddParcel).
     * Ref: MU162 Label Service v30, sezione 5.1
     */
    private $api_url_addparcel = 'https://labelservice.gls-italy.com/ilswebservice.asmx/AddParcel';

    /**
     * Endpoint API GLS per la chiusura giornaliera (CloseWorkDay).
     * Ref: MU162 Label Service v30, sezione 5.2
     */
    private $api_url_closeworkday = 'https://labelservice.gls-italy.com/ilswebservice.asmx/CloseWorkDay';

    /**
     * Costruttore: registra tutti gli hook WordPress/WooCommerce necessari.
     */
    public function __construct() {
        // Generazione automatica etichetta quando l'ordine passa a "In lavorazione"
        add_action( 'woocommerce_order_status_processing', array( $this, 'generate_gls_shipment' ), 10, 1 );

        // Aggiunge azione manuale nel dropdown azioni ordine (backend)
        add_action( 'woocommerce_order_actions', array( $this, 'add_gls_order_action' ) );
        add_action( 'woocommerce_order_action_gls_generate_label', array( $this, 'process_gls_order_action' ) );

        // Pagina impostazioni nel menu WooCommerce
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Cron per CloseWorkDay giornaliero automatico
        add_action( 'init', array( $this, 'schedule_cron' ) );
        add_action( 'gls_daily_close_work_day', array( $this, 'execute_close_work_day' ) );

        // Pulizia cron alla disattivazione del plugin
        register_deactivation_hook( __FILE__, array( $this, 'clear_cron' ) );
    }

    /**
     * Aggiunge l'opzione "Genera/Rigenera Etichetta GLS" nel dropdown
     * delle azioni disponibili nella pagina dettaglio ordine.
     *
     * @param array $actions Azioni disponibili
     * @return array Azioni modificate
     */
    public function add_gls_order_action( $actions ) {
        $actions['gls_generate_label'] = 'Genera/Rigenera Etichetta GLS';
        return $actions;
    }

    /**
     * Callback eseguito quando l'utente seleziona "Genera/Rigenera Etichetta GLS".
     * Forza la rigenerazione anche se esiste già un tracking.
     *
     * @param WC_Order $order Oggetto ordine WooCommerce
     */
    public function process_gls_order_action( $order ) {
        $this->generate_gls_shipment( $order->get_id(), true );
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
     * Ogni opzione corrisponde a un campo nella pagina impostazioni.
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
    }

    /**
     * Renderizza l'HTML della pagina impostazioni GLS.
     * Include campi per credenziali, costi/tasse, contrassegno e azione manuale CWD.
     */
    public function settings_page_html() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        // Mostra messaggio di successo dopo CloseWorkDay manuale
        if ( isset( $_GET['cwd_success'] ) && $_GET['cwd_success'] == '1' ) {
            echo '<div class="notice notice-success is-dismissible"><p>CloseWorkDay eseguito con successo. Controlla i log per i dettagli.</p></div>';
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
                </table>
                <?php submit_button( 'Salva Impostazioni' ); ?>
            </form>

            <hr>
            <h2>Azioni Manuali</h2>
            <p>Esegui la chiusura giornaliera (CloseWorkDay) per confermare le spedizioni create oggi alla sede GLS.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="gls_manual_close_work_day">
                <?php wp_nonce_field( 'gls_manual_cwd', 'gls_cwd_nonce' ); ?>
                <?php submit_button( 'Esegui CloseWorkDay Manualmente', 'secondary' ); ?>
            </form>
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
        if ( ! $force && get_post_meta( $order_id, '_gls_tracking_number', true ) ) {
            return;
        }

        // Costruisce l'XML conforme alla specifica MU162
        $xml_data = $this->build_add_parcel_xml( $order );
        if ( ! $xml_data ) {
            $order->add_order_note( 'GLS Error: Credenziali GLS mancanti nelle impostazioni. Etichetta non generata.' );
            return;
        }

        $order->add_order_note( 'GLS: Inizio comunicazione con API AddParcel...' );

        // Log dell'XML inviato per debug (password mascherata)
        $xml_log = preg_replace( '/<PasswordClienteGls>.*?<\/PasswordClienteGls>/', '<PasswordClienteGls>***</PasswordClienteGls>', $xml_data );
        $order->add_order_note( 'GLS Debug XML inviato: ' . esc_html( $xml_log ) );

        // Invio richiesta HTTP POST all'endpoint AddParcel
        // Il parametro si chiama "XMLInfoParcel" come da documentazione MU162
        $response = wp_remote_post( $this->api_url_addparcel, array(
            'method'  => 'POST',
            'timeout' => 45,
            'body'    => array( 'XMLInfoParcel' => $xml_data ),
        ) );

        if ( is_wp_error( $response ) ) {
            $order->add_order_note( 'GLS Error di rete: ' . $response->get_error_message() );
            return;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );

        if ( $http_code != 200 ) {
            $order->add_order_note( 'GLS HTTP Error ' . $http_code . ': Il server ha rifiutato la richiesta.' );
            return;
        }

        // Log risposta grezza per debug (esteso per diagnostica)
        $order->add_order_note( 'GLS Debug risposta (primi 2000 char): ' . esc_html( substr( $body, 0, 2000 ) ) );

        // Parsing della risposta XML
        $this->parse_gls_response( $body, $order );
    }

    /**
     * Costruisce la stringa XML per il metodo AddParcel conforme alla
     * documentazione MU162 Label Service v30 (AddParcel-CWD).
     *
     * Struttura XML richiesta:
     *   <Info>
     *     <SedeGls>...</SedeGls>
     *     <CodiceClienteGls>...</CodiceClienteGls>
     *     <PasswordClienteGls>...</PasswordClienteGls>
     *     <AddParcelResult>S</AddParcelResult>
     *     <Parcel>
     *       <CodiceContrattoGls>...</CodiceContrattoGls>
     *       ... altri tag ...
     *     </Parcel>
     *   </Info>
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
        $indirizzo       = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
        $localita        = $order->get_shipping_city();
        $provincia       = $order->get_shipping_state();
        $cap             = $order->get_shipping_postcode();

        // Calcolo contrassegno (COD):
        // Se il metodo di pagamento è "cod" e l'opzione è abilitata, trasmetti il totale ordine
        $is_cod               = ( $order->get_payment_method() === 'cod' && get_option( 'gls_enable_cod', 'no' ) === 'yes' );
        $importo_contrassegno = $is_cod ? (float) $order->get_total() : 0;

        // Peso del pacco: cerca dai metadati ordine, default 1 Kg
        // Il tag <PesoReale> accetta max 4 interi + 1 decimale (es. "12,5")
        $peso = 1;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && $product->get_weight() ) {
                $peso += (float) $product->get_weight() * $item->get_quantity();
            }
        }
        // Arrotondamento a 1 decimale come da specifica
        $peso = round( max( $peso, 1 ), 1 );

        // --- Costruzione XML ---
        // NOTA: NON includere la dichiarazione <?xml perché l'XML viene inviato
        // come valore di un parametro POST, non come corpo XML della richiesta.
        // Ref: MU162 sezione 5.1.1 - gli esempi non mostrano dichiarazione XML.
        $xml = '<Info>';

        // Tag di autenticazione (obbligatori, fuori da <Parcel>)
        $xml .= '<SedeGls>' . esc_html( $sede ) . '</SedeGls>';
        $xml .= '<CodiceClienteGls>' . esc_html( $cliente ) . '</CodiceClienteGls>';
        $xml .= '<PasswordClienteGls>' . esc_html( $password ) . '</PasswordClienteGls>';

        // AddParcelResult = "S" per ricevere informazioni dettagliate sull'esito
        // IMPORTANTE: questo tag va DOPO <PasswordClienteGls> e PRIMA di <Parcel>
        // Ref: MU162bis Data Mapping - "The tag must NOT be inserted inside the <Parcel> tag"
        $xml .= '<AddParcelResult>S</AddParcelResult>';

        // --- Inizio blocco <Parcel> ---
        $xml .= '<Parcel>';

        // Codice Contratto GLS (Numerico, max 4 cifre) - OBBLIGATORIO
        $xml .= '<CodiceContrattoGls>' . esc_html( $contratto ) . '</CodiceContrattoGls>';

        // Ragione Sociale destinatario (String, max 35 caratteri) - OBBLIGATORIO
        $xml .= '<RagioneSociale><![CDATA[' . mb_substr( $ragione_sociale, 0, 35 ) . ']]></RagioneSociale>';

        // Indirizzo destinatario (String, max 35 caratteri) - OBBLIGATORIO
        $xml .= '<Indirizzo><![CDATA[' . mb_substr( $indirizzo, 0, 35 ) . ']]></Indirizzo>';

        // Località destinatario (String, max 30 caratteri) - OBBLIGATORIO
        $xml .= '<Localita><![CDATA[' . mb_substr( $localita, 0, 30 ) . ']]></Localita>';

        // CAP destinatario (Numerico, 5 cifre per nazionale) - OBBLIGATORIO
        $xml .= '<Zipcode>' . substr( $cap, 0, 5 ) . '</Zipcode>';

        // Provincia destinatario (String, 2 caratteri) - OBBLIGATORIO
        $xml .= '<Provincia>' . mb_substr( $provincia, 0, 2 ) . '</Provincia>';

        // BDA - Numero documento (opzionale, usiamo l'ID ordine come riferimento)
        $xml .= '<Bda>' . $order->get_id() . '</Bda>';

        // Numero colli: nel metodo AddParcel è SEMPRE considerato 1
        // Per spedizioni multi-collo servono più tag <Parcel>
        // Ref: MU162 nota a pag. 10
        $xml .= '<Colli>1</Colli>';

        // Peso reale in Kg (Numerico, 4 interi + 1 decimale)
        // ATTENZIONE: GLS usa la virgola come separatore decimale (formato italiano)
        $xml .= '<PesoReale>' . number_format( $peso, 1, ',', '' ) . '</PesoReale>';

        // Importo contrassegno in Euro (Numerico, max 10 cifre)
        // Formato: virgola come separatore decimale (es. "1234,10")
        // Ref: MU162bis - tag <ImportoContrassegno>
        if ( $importo_contrassegno > 0 ) {
            $xml .= '<ImportoContrassegno>' . number_format( $importo_contrassegno, 2, ',', '' ) . '</ImportoContrassegno>';
            // Modalità incasso: CONT = contanti, ASSBANC = assegno bancario
            $xml .= '<ModalitaIncasso>CONT</ModalitaIncasso>';
        }

        // Tipo Porto: F = Franco (mittente paga), A = Assegnato (destinatario paga)
        $xml .= '<TipoPorto>F</TipoPorto>';

        // Tipo Spedizione: N = Nazionale
        $xml .= '<TipoSpedizione>N</TipoSpedizione>';

        // Tipo Collo: 0 = Normale, 4 = PLUS
        $xml .= '<TipoCollo>0</TipoCollo>';

        // Riferimento cliente: l'ID ordine WooCommerce (opzionale, max 600 char)
        $xml .= '<RiferimentoCliente>' . $order->get_order_number() . '</RiferimentoCliente>';

        // Cellulare destinatario (per notifiche SMS/preannuncio)
        // Ref: MU162bis - tag <Cellulare1>
        $phone = $order->get_billing_phone();
        if ( ! empty( $phone ) ) {
            $xml .= '<Cellulare1>' . esc_html( substr( $phone, 0, 20 ) ) . '</Cellulare1>';
        }

        // Email destinatario (per notifiche email)
        // Ref: MU162bis - tag <Email>
        $email = $order->get_billing_email();
        if ( ! empty( $email ) ) {
            $xml .= '<Email><![CDATA[' . $email . ']]></Email>';
        }

        // Genera PDF etichetta:
        // 3 = formato A4 (2 etichette per pagina)
        // 4 = formato 10x15 (etichetta singola, consigliato per stampanti termiche)
        $xml .= '<GeneraPdf>4</GeneraPdf>';

        // Note spedizione (String, max 40 char, visualizzate sull'etichetta)
        $xml .= '<NoteSpedizione><![CDATA[Ordine #' . $order->get_order_number() . ']]></NoteSpedizione>';

        $xml .= '</Parcel>';
        $xml .= '</Info>';

        return $xml;
    }

    /**
     * Analizza la risposta XML del metodo AddParcel.
     *
     * La risposta si chiama <InfoLabel> (Ref: MU162 v30, sez. 5.1.4) e contiene
     * SEMPRE un <NumeroSpedizione>, anche per spedizioni GLS CHECK (routing fallito).
     *
     * Dalla documentazione: "il numero ricevuto è già un numero di tracking ufficiale GLS.
     * Le spedizioni verranno comunque inoltrate nel circuito in modo corretto."
     *
     * Il tag <NoteSpedizione> nella RISPOSTA contiene eventuali note/errori di routing
     * (es. "Dati non accettabili: ...") ma NON è un errore bloccante se NumeroSpedizione
     * è presente.
     *
     * IMPORTANTE: Non confondere <NoteSpedizione> della risposta (tag di output in InfoLabel)
     * con <NoteSpedizione> della richiesta (tag di input nel Parcel).
     *
     * L'endpoint .asmx può wrappare la risposta in <string xmlns="http://tempuri.org/">.
     *
     * @param string   $xml_response Corpo della risposta HTTP
     * @param WC_Order $order        Oggetto ordine WooCommerce
     */
    private function parse_gls_response( $xml_response, $order ) {
        // Fase 1: Gestione wrapper ASMX
        $inner_xml = $this->extract_asmx_response( $xml_response );

        // Fase 2: Parsing dell'XML effettivo
        // La risposta ha root <InfoLabel xmlns=""> con uno o più <Parcel> figli
        $xml = @simplexml_load_string( $inner_xml );
        if ( $xml === false ) {
            $order->add_order_note( 'GLS Error: Risposta XML dal server incomprensibile. Risposta raw: ' . esc_html( substr( $xml_response, 0, 500 ) ) );
            return;
        }

        // Fase 3: Controllo errore bloccante a livello globale
        // Ref: MU162 - <DescrizioneErrore> fuori da <Parcel> = errore che blocca TUTTA l'operazione
        // (es. credenziali errate, sede inesistente)
        if ( isset( $xml->DescrizioneErrore ) && ! empty( (string) $xml->DescrizioneErrore ) ) {
            $order->add_order_note( 'Errore GLS (bloccante): ' . (string) $xml->DescrizioneErrore );
            return;
        }

        // Fase 4: Controllo errore bloccante a livello Parcel
        // <DescrizioneErrore> dentro <Parcel> = errore specifico del collo
        if ( isset( $xml->Parcel->DescrizioneErrore ) && ! empty( (string) $xml->Parcel->DescrizioneErrore ) ) {
            $order->add_order_note( 'Errore GLS (API): ' . (string) $xml->Parcel->DescrizioneErrore );
            return;
        }

        // Fase 5: Estrazione NumeroSpedizione - IL CHECK PIÙ IMPORTANTE
        // Ref: MU162 v30 sez. 5.1.4 - Il NumeroSpedizione è SEMPRE presente nella
        // risposta InfoLabel, sia per spedizioni correttamente instradate sia per GLS CHECK.
        // Anche in caso di GLS CHECK, è già un numero di tracking ufficiale GLS.
        if ( isset( $xml->Parcel->NumeroSpedizione ) && ! empty( trim( (string) $xml->Parcel->NumeroSpedizione ) ) ) {
            $track = trim( (string) $xml->Parcel->NumeroSpedizione );

            // Salva il tracking number nei metadati dell'ordine
            update_post_meta( $order->get_id(), '_gls_tracking_number', $track );

            // Determina se è una spedizione GLS CHECK (routing fallito)
            // Ref: MU162 v30 sez. 5.1.4 - DescrizioneSedeDestino = "GLS Check" per routing fallito
            $sede_destino     = isset( $xml->Parcel->DescrizioneSedeDestino ) ? trim( (string) $xml->Parcel->DescrizioneSedeDestino ) : '';
            $note_spedizione  = isset( $xml->Parcel->NoteSpedizione ) ? trim( (string) $xml->Parcel->NoteSpedizione ) : '';
            $is_gls_check     = ( stripos( $sede_destino, 'GLS Check' ) !== false )
                             || ( stripos( $note_spedizione, 'Dati non accettabili' ) !== false )
                             || ( stripos( $note_spedizione, 'non conforme a stradario' ) !== false );

            // Costruisci la nota ordine
            if ( $is_gls_check ) {
                // GLS CHECK: spedizione creata ma con routing da verificare alla sede
                $note = '⚠️ Spedizione GLS creata come GLS CHECK. Tracking: ' . $track;
                $note .= ' | Avviso GLS: ' . esc_html( $note_spedizione );
                $note .= ' | La sede GLS correggerà automaticamente l\'instradamento.';
            } else {
                // Spedizione correttamente instradata
                $note = '✅ Spedizione GLS creata con successo! Tracking: ' . $track;
                if ( ! empty( $sede_destino ) ) {
                    $note .= ' | Sede destino: ' . esc_html( $sede_destino );
                }
            }

            // Se presente, salva l'etichetta PDF (codificata in Base64)
            if ( isset( $xml->Parcel->PdfLabel ) && ! empty( (string) $xml->Parcel->PdfLabel ) ) {
                $upload_dir = wp_upload_dir();
                $pdf_path   = $upload_dir['path'] . '/GLS_Label_' . $track . '.pdf';
                $pdf_url    = $upload_dir['url'] . '/GLS_Label_' . $track . '.pdf';

                $pdf_content = base64_decode( (string) $xml->Parcel->PdfLabel );
                if ( $pdf_content !== false && strlen( $pdf_content ) > 0 ) {
                    file_put_contents( $pdf_path, $pdf_content );
                    $note .= ' | <a href="' . esc_url( $pdf_url ) . '" target="_blank">Scarica Etichetta PDF</a>';
                    update_post_meta( $order->get_id(), '_gls_label_pdf_url', $pdf_url );
                }
            }

            $order->add_order_note( $note );
            return;
        }

        // Fase 6: Nessun NumeroSpedizione trovato - questo è anomalo
        // Logga il contenuto della risposta per diagnostica
        $note_sped = isset( $xml->Parcel->NoteSpedizione ) ? (string) $xml->Parcel->NoteSpedizione : 'N/A';
        $order->add_order_note(
            'GLS Error: Nessun NumeroSpedizione nella risposta. '
            . 'NoteSpedizione: ' . esc_html( $note_sped )
            . ' | Struttura XML: ' . esc_html( substr( $inner_xml, 0, 800 ) )
        );
    }

    /**
     * Estrae il contenuto XML reale dalla risposta ASMX.
     *
     * L'endpoint .asmx può restituire la risposta in due formati:
     *
     * 1) Wrappata in <string> (tipico di HTTP POST via form-urlencoded):
     *    <string xmlns="http://tempuri.org/">&lt;InfoLabel&gt;...&lt;/InfoLabel&gt;</string>
     *
     * 2) Diretta (già XML puro):
     *    <InfoLabel xmlns="">...</InfoLabel>
     *
     * Ref: MU162 v30 sez. 5.1.4 - La risposta AddParcel ha root element <InfoLabel>.
     *
     * @param string $raw_response Risposta HTTP grezza
     * @return string XML pulito pronto per il parsing
     */
    private function extract_asmx_response( $raw_response ) {
        // Rimuove eventuali BOM (Byte Order Mark) UTF-8 che possono precedere l'XML
        $raw_response = ltrim( $raw_response, "\xEF\xBB\xBF" );

        // Prova a caricare come XML per verificare se è wrappato in <string>
        $wrapper = @simplexml_load_string( $raw_response );

        if ( $wrapper !== false ) {
            $root_name = $wrapper->getName();

            // Caso 1: Wrappato in <string xmlns="http://tempuri.org/">
            if ( $root_name === 'string' ) {
                $inner = (string) $wrapper;
                if ( ! empty( $inner ) && strpos( $inner, '<' ) !== false ) {
                    return $inner;
                }
            }

            // Caso 2: Root è già InfoLabel o Info → risposta diretta, usala così com'è
            if ( in_array( $root_name, array( 'InfoLabel', 'Info' ), true ) || isset( $wrapper->Parcel ) ) {
                return $raw_response;
            }
        }

        // Fallback: restituisce la risposta così com'è
        return $raw_response;
    }

    // ========================================================================
    // CLOSEWORKDAY - Chiusura giornaliera
    // Ref: MU162 Label Service v30, sezione 5.2
    //
    // La CloseWorkDay conferma alla sede GLS le spedizioni create durante la giornata.
    // Senza questa operazione le spedizioni restano in stato "Aperto" e non vengono
    // elaborate per il ritiro.
    // ========================================================================

    /**
     * Schedula il cron WordPress per eseguire la CloseWorkDay ogni giorno.
     * L'evento è pianificato per le 18:00 (orario server).
     */
    public function schedule_cron() {
        if ( ! wp_next_scheduled( 'gls_daily_close_work_day' ) ) {
            // Calcola il prossimo orario delle 18:00
            $timestamp = strtotime( 'today 18:00' );
            if ( $timestamp < time() ) {
                $timestamp = strtotime( 'tomorrow 18:00' );
            }
            wp_schedule_event( $timestamp, 'daily', 'gls_daily_close_work_day' );
        }
    }

    /**
     * Rimuove l'evento cron alla disattivazione del plugin.
     */
    public function clear_cron() {
        $timestamp = wp_next_scheduled( 'gls_daily_close_work_day' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'gls_daily_close_work_day' );
        }
    }

    /**
     * Esegue la chiamata CloseWorkDay all'API GLS.
     *
     * Struttura XML richiesta:
     *   <Info>
     *     <SedeGls>...</SedeGls>
     *     <CodiceClienteGls>...</CodiceClienteGls>
     *     <PasswordClienteGls>...</PasswordClienteGls>
     *     <CloseWorkDayResult>S</CloseWorkDayResult>
     *   </Info>
     *
     * Il parametro HTTP si chiama "XMLInfo" (diverso da AddParcel che usa "XMLInfoParcel").
     */
    public function execute_close_work_day() {
        $sede     = trim( get_option( 'gls_sede' ) );
        $cliente  = trim( get_option( 'gls_codice_cliente' ) );
        $password = trim( get_option( 'gls_password' ) );

        if ( empty( $sede ) || empty( $cliente ) || empty( $password ) ) {
            error_log( 'GLS Cron Error: Credenziali mancanti per CloseWorkDay.' );
            return;
        }

        // Costruzione XML per CloseWorkDay (senza dichiarazione XML)
        $xml  = '<Info>';
        $xml .= '<SedeGls>' . esc_html( $sede ) . '</SedeGls>';
        $xml .= '<CodiceClienteGls>' . esc_html( $cliente ) . '</CodiceClienteGls>';
        $xml .= '<PasswordClienteGls>' . esc_html( $password ) . '</PasswordClienteGls>';
        // CloseWorkDayResult = "S" per ricevere lo stato delle spedizioni trasmesse
        $xml .= '<CloseWorkDayResult>S</CloseWorkDayResult>';
        $xml .= '</Info>';

        // Invio richiesta
        $response = wp_remote_post( $this->api_url_closeworkday, array(
            'method'  => 'POST',
            'timeout' => 60,
            'body'    => array( 'XMLInfo' => $xml ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'GLS CloseWorkDay Error: ' . $response->get_error_message() );
            return;
        }

        $body = wp_remote_retrieve_body( $response );
        error_log( 'GLS CloseWorkDay Eseguito con successo. Risposta: ' . substr( $body, 0, 500 ) );
    }
}

// ============================================================================
// HANDLER PER CLOSEWORKDAY MANUALE (via admin-post.php)
// Richiamato dal form nella pagina impostazioni GLS.
// ============================================================================
add_action( 'admin_post_gls_manual_close_work_day', 'gls_manual_cwd_handler' );
function gls_manual_cwd_handler() {
    // Verifica nonce di sicurezza e permessi utente
    if (
        ! isset( $_POST['gls_cwd_nonce'] )
        || ! wp_verify_nonce( $_POST['gls_cwd_nonce'], 'gls_manual_cwd' )
        || ! current_user_can( 'manage_woocommerce' )
    ) {
        wp_die( 'Accesso non autorizzato.' );
    }

    // Esegue la CloseWorkDay
    ( new GLS_WooCommerce_Integration_Advanced() )->execute_close_work_day();

    // Redirect alla pagina impostazioni con messaggio di successo
    wp_redirect( admin_url( 'admin.php?page=gls-settings&cwd_success=1' ) );
    exit;
}

// Inizializza il core del plugin
new GLS_WooCommerce_Integration_Advanced();


// ============================================================================
// TARIFFE E METODO DI SPEDIZIONE WooCommerce
// Calcola le tariffe GLS in base a scaglioni di peso e zona geografica.
// L'IVA viene aggiunta in automatico al netto delle tariffe.
// ============================================================================
add_action( 'woocommerce_shipping_init', 'gls_custom_shipping_method_init' );
function gls_custom_shipping_method_init() {
    if ( ! class_exists( 'WC_GLS_Contract_Shipping_Method' ) ) {

        /**
         * Metodo di spedizione WooCommerce per GLS.
         * Calcola le tariffe in base a peso, zona geografica e eventuali maggiorazioni
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

            /**
             * Inizializza campi del form e impostazioni.
             */
            public function init() {
                $this->init_form_fields();
                $this->init_settings();
                add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
            }

            /**
             * Definisce i campi configurabili per le tariffe.
             * Le tariffe sono suddivise per zona: Italia base, Calabria/Sicilia, Sardegna.
             * Ogni zona ha scaglioni di peso da 0 a oltre 500 Kg.
             */
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
                    'title_other'    => array( 'title' => 'Altre Maggiorazioni', 'type' => 'title' ),
                    'minor_islands'  => array(
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
             * Logica:
             * 1. Determina la zona (IT base, Calabria/Sicilia, Sardegna)
             * 2. Applica la tariffa corrispondente allo scaglione di peso
             * 3. Aggiunge maggiorazione per isole minori/laguna se applicabile
             * 4. Applica IVA
             * 5. Azzera il costo se raggiunta la soglia di spedizione gratuita
             *
             * @param array $package Pacchetto di spedizione WooCommerce
             */
            public function calculate_shipping( $package = array() ) {
                // Peso totale del carrello (default 2 Kg se vuoto)
                $weight = WC()->cart->get_cart_contents_weight();
                if ( $weight <= 0 ) {
                    $weight = 2;
                }

                $state    = $package['destination']['state'];
                $postcode = $package['destination']['postcode'];

                // Province di Calabria e Sicilia
                $calabria_sicilia = array(
                    'CZ', 'CS', 'KR', 'RC', 'VV',                           // Calabria
                    'AG', 'CL', 'CT', 'EN', 'ME', 'PA', 'RG', 'SR', 'TP',  // Sicilia
                );
                // Province della Sardegna
                $sardegna = array( 'CA', 'NU', 'OR', 'SS', 'SU' );

                // Determina il prefisso tariffario in base alla zona
                if ( in_array( $state, $calabria_sicilia ) ) {
                    $prefix = 'cs_';
                } elseif ( in_array( $state, $sardegna ) ) {
                    $prefix = 'sa_';
                } else {
                    $prefix = 'it_';
                }

                // Calcolo tariffa base per scaglione di peso
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
                    // Da 100 a 500 Kg: base + extra per ogni 50 Kg
                    $base  = (float) $this->get_option( $prefix . '50_100' );
                    $extra = (float) $this->get_option( $prefix . 'extra_50' );
                    $cost  = $base + ( ceil( ( $weight - 100 ) / 50 ) * $extra );
                } else {
                    // Oltre 500 Kg: base + extra 50Kg per 100-500 + extra 100Kg per il resto
                    $base      = (float) $this->get_option( $prefix . '50_100' );
                    $extra_50  = (float) $this->get_option( $prefix . 'extra_50' );
                    $extra_100 = (float) $this->get_option( $prefix . 'extra_100' );
                    // 8 scaglioni da 50 Kg per coprire 100-500 Kg (400 Kg / 50 = 8)
                    $cost = $base + ( 8 * $extra_50 ) + ( ceil( ( $weight - 500 ) / 100 ) * $extra_100 );
                }

                // Maggiorazione per isole minori e zone lagunari (Venezia, Capri, Ischia, ecc.)
                // Identificate tramite CAP specifici
                $isole_minori_cap = array(
                    // Venezia centro storico e isole (Murano, Burano, Lido, ecc.)
                    '30121', '30122', '30123', '30124', '30125', '30126', '30132', '30133', '30141',
                    // Capri e Anacapri
                    '80073', '80071',
                    // Ischia e comuni
                    '80074', '80075', '80076', '80077',
                );
                if ( in_array( $postcode, $isole_minori_cap ) ) {
                    $minor_rate = (float) $this->get_option( 'minor_islands' );
                    $cost      += ( ceil( $weight / 100 ) * $minor_rate );
                }

                // Applicazione IVA
                $vat_rate       = (float) get_option( 'gls_vat_rate', '22' );
                $cost_with_vat  = $cost * ( 1 + ( $vat_rate / 100 ) );

                // Spedizione gratuita se il totale carrello supera la soglia
                $free_threshold         = (float) get_option( 'gls_free_shipping_threshold', '0' );
                $cart_total_for_threshold = WC()->cart->get_cart_contents_total() + WC()->cart->get_cart_contents_tax();

                if ( $free_threshold > 0 && $cart_total_for_threshold >= $free_threshold ) {
                    $cost_with_vat = 0;
                }

                // Aggiunge la tariffa calcolata come opzione di spedizione
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
// Aggiunge un supplemento quando il cliente sceglie il pagamento in contrassegno.
// ============================================================================
add_action( 'woocommerce_cart_calculate_fees', 'gls_add_cod_fee', 20, 1 );

/**
 * Calcola e aggiunge il supplemento contrassegno al carrello.
 *
 * Il supplemento è calcolato come percentuale del totale (carrello + spedizione),
 * con un importo minimo configurabile. L'IVA viene applicata sul netto.
 *
 * @param WC_Cart $cart Oggetto carrello WooCommerce
 */
function gls_add_cod_fee( $cart ) {
    // Non eseguire nel backend se non è una richiesta AJAX
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

    // Determina il metodo di pagamento scelto dal cliente
    $chosen_payment_method = WC()->session->get( 'chosen_payment_method' );

    // Override dal POST (durante il cambio metodo di pagamento al checkout)
    if ( isset( $_POST['payment_method'] ) ) {
        $chosen_payment_method = wc_clean( wp_unslash( $_POST['payment_method'] ) );
    } elseif ( isset( $_POST['post_data'] ) ) {
        parse_str( wc_clean( wp_unslash( $_POST['post_data'] ) ), $post_data );
        if ( isset( $post_data['payment_method'] ) ) {
            $chosen_payment_method = $post_data['payment_method'];
        }
    }

    // Applica il supplemento solo se il metodo è "cod" (contrassegno)
    if ( 'cod' === $chosen_payment_method ) {
        $percentage = (float) get_option( 'gls_cod_fee_percentage', '2' );
        $min_fee    = (float) get_option( 'gls_cod_min_fee', '5.00' );
        $vat_rate   = (float) get_option( 'gls_vat_rate', '22' );

        // Base di calcolo: contenuto carrello + spedizione (netto)
        $cart_total = $cart->get_cart_contents_total() + $cart->get_shipping_total();
        // Applica la percentuale con importo minimo
        $base_fee     = max( $min_fee, $cart_total * ( $percentage / 100 ) );
        // Aggiunge l'IVA
        $fee_with_vat = $base_fee * ( 1 + ( $vat_rate / 100 ) );

        // Aggiunge come fee non tassabile (IVA già inclusa nel calcolo)
        $cart->add_fee( 'Supplemento Contrassegno GLS', $fee_with_vat, false );
    }
}


// ============================================================================
// AGGIORNAMENTO CHECKOUT AL CAMBIO METODO DI PAGAMENTO
// Forza il ricalcolo dei totali quando il cliente cambia metodo di pagamento,
// così il supplemento contrassegno appare/scompare in tempo reale.
// ============================================================================
add_action( 'wp_footer', 'gls_force_checkout_update' );

/**
 * Inietta lo script jQuery nel footer della pagina checkout.
 * Al cambio del metodo di pagamento, triggera l'evento WooCommerce
 * "update_checkout" che ricalcola totali e fee.
 */
function gls_force_checkout_update() {
    if ( is_checkout() && ! is_wc_endpoint_url() ) {
        ?>
        <script type="text/javascript">
            jQuery( function( $ ) {
                // Ascolta il cambio di radio button del metodo di pagamento
                $( 'form.checkout' ).on( 'change', 'input[name="payment_method"]', function() {
                    // Triggera il ricalcolo completo del checkout
                    $( document.body ).trigger( 'update_checkout' );
                });
            });
        </script>
        <?php
    }
}