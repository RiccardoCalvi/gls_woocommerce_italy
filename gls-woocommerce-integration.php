<?php
/**
 * Plugin Name: GLS Italy WooCommerce Integration
 * Plugin URI: https://github.com/RiccardoCalvi/gls_woocommerce_italy
 * Description: Integrazione API GLS (Etichette) + Calcolo Tariffe di Spedizione e Contrassegno.
 * Version: 1.2.3
 * Author: Dream2Dev
 *
 * Changelog v1.2.3:
 *   - Fix email tracking: sostituito lo shortcode [gls_tracking_number] (non processato da YayMail)
 *     con variabili native YayMail registrate tramite il filtro yaymail_custom_variables.
 *     Nel builder YayMail sono ora disponibili due variabili drag-and-drop:
 *       {{gls_tracking_number}} — codice tracking testuale (es. 661209312)
 *       {{gls_tracking_link}}   — link HTML cliccabile con stile GLS
 *     Le variabili funzionano su tutte le email WooCommerce (ordine completato, cambio stato, ecc.)
 *     Lo shortcode [gls_tracking_number] è mantenuto per compatibilità con altri contesti.
 *   - Aggiunto: salvataggio tracking in meta "visibile" gls_tracking_number (senza prefisso _)
 *     in aggiunta al meta privato _gls_tracking_number, così appare nella metabox
 *     "Campi personalizzati" del backend WooCommerce.
 *
 * Changelog v1.2.2:
 *   - Fix shortcode [gls_tracking_number]: lo shortcode non funzionava nelle email WooCommerce
 *     perché $post globale è null durante il rendering email. Ora l'ordine corrente viene
 *     catturato tramite l'hook woocommerce_email_before_order_table (che riceve l'oggetto
 *     $order direttamente) e salvato in una proprietà statica di classe prima del rendering.
 *   - Nuovo: blocco tracking GLS nella pagina "Visualizza ordine" dell'account cliente
 *     tramite hook woocommerce_order_details_after_order_table — mostra tracking e link
 *     GLS solo se il numero di spedizione è presente nei metadati dell'ordine.
 *
 * Changelog v1.2.1:
 *   - Fix DeleteSped: il nome del parametro POST è ora tentato in sequenza come "XMLInfo" e poi
 *     "XMLInfoSped" (la doc MU162 §5.4 non specifica il nome esatto del parametro HTTP, a differenza
 *     di AddParcel che usa "XMLInfoParcel" e CloseWorkDay che usa "XMLInfo").
 *   - Fix DeleteSped: gestione esplicita HTTP 500 — in ambiente di test GLS il metodo DeleteSped
 *     potrebbe non essere esposto, generando un 500 server-side non dipendente dal codice.
 *     Il log della risposta raw del 500 viene ora scritto in error_log per diagnosi.
 *   - Fix DeleteSped: aggiunto log in error_log dell'XML inviato (con password mascherata)
 *     per facilitare il debug senza esporre dati nelle note ordine.
 *
 * Changelog v1.2.0:
 *   - Nuova funzione: cancellazione spedizione GLS (DeleteSped) al cambio stato ordine → "Annullato"
 *     Ref: MU162 v30, sez. 5.4 - se la spedizione è già "Chiusa" la cancellazione non blocca l'inoltro
 *     nel circuito GLS; l'utente viene avvisato tramite nota ordine.
 *   - Nuova funzione: invio automatico email all'amministratore con etichetta PDF in allegato
 *     al momento della creazione della spedizione, per consentire la stampa immediata.
 *   - Nuova funzione: shortcode [gls_tracking_number] per visualizzare il codice di tracking
 *     nei template email WooCommerce inviati al cliente.
 *   - Rimossi log di debug eccessivi nelle note ordine (XML inviato, risposta raw).
 *     I log di diagnostica restano disponibili solo in error_log di sistema.
 *
 * Changelog v1.1.1:
 *   - Fix critico parsing risposta: NumeroSpedizione viene ora controllato PRIMA di NoteSpedizione
 *   - Fix: gestione root element <InfoLabel xmlns=""> nella risposta (non <Info>)
 *   - Aggiunta gestione etichette GLS CHECK (routing fallito ma spedizione creata)
 *   - Aumentato log debug risposta da 300 a 2000 caratteri
 *   - Aggiunto log specifico di NumeroSpedizione, NoteSpedizione e DescrizioneSedeDestino
 *
 * Changelog v1.1.0:
 *   - Fix critico: corretti nomi tag XML per conformità con documentazione API GLS (MU162 Label Service v30)
 *   - Fix: formato decimale con virgola (es. "10,5") come richiesto dall'API GLS
 *   - Fix: parsing risposta ASMX - gestione wrapper <string xmlns="..."> del webservice
 *   - Fix: rimossa dichiarazione <?xml?> dal parametro form (non prevista dalla doc)
 *   - Aggiunto tag <GeneraPdf>4</GeneraPdf> per ricevere etichetta PDF in formato 10x15
 *   - Aggiunto tag <TipoPorto>F</TipoPorto> (Porto Franco) obbligatorio
 *   - Aggiunto tag <ModalitaIncasso>CONT</ModalitaIncasso> quando contrassegno attivo
 *   - Aggiunto tag <TipoSpedizione>N</TipoSpedizione> per spedizioni nazionali
 *   - Implementato cron CloseWorkDay con scheduling effettivo
 *   - Fix: verifica nonce nel handler manuale CloseWorkDay
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
// chiusura giornaliera (CloseWorkDay), pagina impostazioni e azioni ordine.
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
     * Endpoint API GLS per la cancellazione di una spedizione (DeleteSped).
     * Ref: MU162 Label Service v30, sezione 5.4
     * NOTA: la cancellazione di una spedizione già "Chiusa" (inviata tramite CloseWorkDay)
     * NON blocca l'inoltro nel circuito GLS; contattare la sede GLS per interventi fisici.
     */
    private $api_url_deletesped = 'https://labelservice.gls-italy.com/ilswebservice.asmx/DeleteSped';

    /**
     * Proprietà statica per trasmettere l'ID ordine corrente allo shortcode
     * durante il rendering delle email WooCommerce.
     *
     * Il problema: WooCommerce genera le email fuori dal loop di WordPress,
     * quindi $post è null e lo shortcode non riesce a risalire all'ordine.
     * Soluzione: l'hook woocommerce_email_before_order_table riceve $order
     * come argomento diretto → salviamo l'ID qui prima che l'email venga renderizzata.
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

        // Shortcode per mostrare il tracking number nelle email al cliente
        add_shortcode( 'gls_tracking_number', array( $this, 'tracking_number_shortcode' ) );

        // Hook per catturare l'ordine corrente PRIMA del rendering dell'email.
        // Necessario perché $post è null nelle email WooCommerce.
        // woocommerce_email_before_order_table riceve ($order, $sent_to_admin, $plain_text, $email)
        add_action( 'woocommerce_email_before_order_table', array( $this, 'capture_email_order_id' ), 1, 1 );

        // Mostra il blocco tracking GLS nella pagina "Visualizza ordine" dell'account cliente
        // hook: woocommerce_order_details_after_order_table — riceve $order come argomento
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_tracking_on_order_page' ), 10, 1 );
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

            <hr>
            <h2>Shortcode disponibili</h2>
            <p>
                <strong>[gls_tracking_number]</strong> — Mostra il codice di tracking GLS nell'email all'ordine del cliente.<br>
                <small>Usa questo shortcode nei template email WooCommerce (es. "Ordine completato") per includere automaticamente il codice di tracciamento. Se non è ancora disponibile, lo shortcode non mostra nulla.</small>
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
        if ( ! $force && get_post_meta( $order_id, '_gls_tracking_number', true ) ) {
            return;
        }

        // Costruisce l'XML conforme alla specifica MU162
        $xml_data = $this->build_add_parcel_xml( $order );
        if ( ! $xml_data ) {
            $order->add_order_note( 'GLS Error: Credenziali GLS mancanti nelle impostazioni. Etichetta non generata.' );
            return;
        }

        // Invio richiesta HTTP POST all'endpoint AddParcel
        // Il parametro si chiama "XMLInfoParcel" come da documentazione MU162
        $response = wp_remote_post( $this->api_url_addparcel, array(
            'method'  => 'POST',
            'timeout' => 45,
            'body'    => array( 'XMLInfoParcel' => $xml_data ),
        ) );

        if ( is_wp_error( $response ) ) {
            $order->add_order_note( 'GLS Error di rete: ' . $response->get_error_message() );
            error_log( 'GLS AddParcel network error order #' . $order_id . ': ' . $response->get_error_message() );
            return;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );

        if ( $http_code != 200 ) {
            $order->add_order_note( 'GLS HTTP Error ' . $http_code . ': Il server ha rifiutato la richiesta.' );
            error_log( 'GLS AddParcel HTTP ' . $http_code . ' for order #' . $order_id );
            return;
        }

        // Log della risposta grezza solo in error_log (non più nelle note ordine)
        error_log( 'GLS AddParcel response order #' . $order_id . ': ' . substr( $body, 0, 1000 ) );

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
        $indirizzo = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
        $localita  = $order->get_shipping_city();
        $provincia = $order->get_shipping_state();
        $cap       = $order->get_shipping_postcode();

        // Calcolo contrassegno (COD):
        // Se il metodo di pagamento è "cod" e l'opzione è abilitata, trasmetti il totale ordine
        $is_cod               = ( $order->get_payment_method() === 'cod' && get_option( 'gls_enable_cod', 'no' ) === 'yes' );
        $importo_contrassegno = $is_cod ? (float) $order->get_total() : 0;

        // Peso del pacco: somma dei pesi dei prodotti, default 1 Kg
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

        // AddParcelResult = "S" per ricevere informazioni dettagliate sull'esito.
        // IMPORTANTE: questo tag va DOPO <PasswordClienteGls> e PRIMA di <Parcel>.
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

        // Numero colli: nel metodo AddParcel è SEMPRE considerato 1.
        // Per spedizioni multi-collo servono più tag <Parcel>.
        // Ref: MU162 nota a pag. 10
        $xml .= '<Colli>1</Colli>';

        // Peso reale in Kg (Numerico, 4 interi + 1 decimale).
        // ATTENZIONE: GLS usa la virgola come separatore decimale (formato italiano).
        $xml .= '<PesoReale>' . number_format( $peso, 1, ',', '' ) . '</PesoReale>';

        // Importo contrassegno in Euro (Numerico, max 10 cifre).
        // Formato: virgola come separatore decimale (es. "1234,10").
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

        // Cellulare destinatario (per notifiche SMS/preannuncio).
        // Ref: MU162bis - tag <Cellulare1>
        $phone = $order->get_billing_phone();
        if ( ! empty( $phone ) ) {
            $xml .= '<Cellulare1>' . esc_html( substr( $phone, 0, 20 ) ) . '</Cellulare1>';
        }

        // Email destinatario (per notifiche email).
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
     * Il tag <NoteSpedizione> nella RISPOSTA contiene eventuali avvisi di routing
     * (es. "Dati non accettabili: ...") ma NON è un errore bloccante se NumeroSpedizione
     * è presente.
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
            error_log( 'GLS parse error order #' . $order->get_id() . ': ' . substr( $xml_response, 0, 500 ) );
            return;
        }

        // Fase 3: Controllo errore bloccante a livello globale
        // (es. credenziali errate, sede inesistente)
        if ( isset( $xml->DescrizioneErrore ) && ! empty( (string) $xml->DescrizioneErrore ) ) {
            $order->add_order_note( 'Errore GLS (bloccante): ' . (string) $xml->DescrizioneErrore );
            return;
        }

        // Fase 4: Controllo errore bloccante a livello Parcel
        if ( isset( $xml->Parcel->DescrizioneErrore ) && ! empty( (string) $xml->Parcel->DescrizioneErrore ) ) {
            $order->add_order_note( 'Errore GLS (API): ' . (string) $xml->Parcel->DescrizioneErrore );
            return;
        }

        // Fase 5: Estrazione NumeroSpedizione - IL CHECK PIÙ IMPORTANTE
        // Ref: MU162 v30 sez. 5.1.4 - Il NumeroSpedizione è SEMPRE presente nella
        // risposta InfoLabel, sia per spedizioni correttamente instradate sia per GLS CHECK.
        if ( isset( $xml->Parcel->NumeroSpedizione ) && ! empty( trim( (string) $xml->Parcel->NumeroSpedizione ) ) ) {
            $track = trim( (string) $xml->Parcel->NumeroSpedizione );

            // Salva il tracking number nei metadati dell'ordine.
            // _gls_tracking_number  → meta privato (prefisso _), usato internamente dal plugin.
            // gls_tracking_number   → meta pubblico (senza prefisso _), visibile nella metabox
            //                         "Campi personalizzati" del backend e accessibile da YayMail
            //                         tramite la variabile {{gls_tracking_number}}.
            update_post_meta( $order->get_id(), '_gls_tracking_number', $track );
            update_post_meta( $order->get_id(), 'gls_tracking_number', $track );

            // Determina se è una spedizione GLS CHECK (routing fallito)
            $sede_destino    = isset( $xml->Parcel->DescrizioneSedeDestino ) ? trim( (string) $xml->Parcel->DescrizioneSedeDestino ) : '';
            $note_spedizione = isset( $xml->Parcel->NoteSpedizione ) ? trim( (string) $xml->Parcel->NoteSpedizione ) : '';
            $is_gls_check    = ( stripos( $sede_destino, 'GLS Check' ) !== false )
                            || ( stripos( $note_spedizione, 'Dati non accettabili' ) !== false )
                            || ( stripos( $note_spedizione, 'non conforme a stradario' ) !== false );

            // Costruisce la nota ordine in base all'esito del routing
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
                    update_post_meta( $order->get_id(), '_gls_label_pdf_url', $pdf_url );

                    // Invia l'etichetta PDF all'email dell'amministratore
                    $this->send_label_to_admin( $order, $track, $pdf_path, $pdf_url );
                }
            }

            $order->add_order_note( $note );
            return;
        }

        // Fase 6: Nessun NumeroSpedizione trovato - questo è anomalo, logga per diagnostica
        $note_sped = isset( $xml->Parcel->NoteSpedizione ) ? (string) $xml->Parcel->NoteSpedizione : 'N/A';
        $order->add_order_note(
            'GLS Error: Nessun NumeroSpedizione nella risposta. NoteSpedizione: ' . esc_html( $note_sped )
        );
        error_log( 'GLS no tracking number order #' . $order->get_id() . ' - XML: ' . substr( $inner_xml, 0, 500 ) );
    }

    // ========================================================================
    // EMAIL ETICHETTA ALL'AMMINISTRATORE
    // Invia l'etichetta PDF appena creata all'indirizzo email admin del sito.
    // ========================================================================

    /**
     * Invia l'etichetta PDF GLS all'email dell'amministratore del sito.
     * Il dipendente potrà aprire l'email, scaricare il PDF e stampare l'etichetta.
     *
     * @param WC_Order $order     Oggetto ordine WooCommerce
     * @param string   $tracking  Numero di tracking GLS
     * @param string   $pdf_path  Percorso fisico del file PDF sul server
     * @param string   $pdf_url   URL pubblico del file PDF
     */
    private function send_label_to_admin( $order, $tracking, $pdf_path, $pdf_url ) {
        // Destinatario: email dell'amministratore WordPress
        $admin_email = get_option( 'admin_email' );

        // Soggetto email
        $subject = sprintf(
            '[GLS] Etichetta spedizione Ordine #%s - Tracking: %s',
            $order->get_order_number(),
            $tracking
        );

        // Corpo email con informazioni essenziali per il dipendente
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
        $message .= "3. Consegna il pacco al corriere GLS\n\n";
        $message .= "Link diretto al PDF: " . $pdf_url . "\n\n";
        $message .= "Tracking online: https://gls-group.eu/track/" . $tracking . "\n";

        // Intestazioni email
        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

        // Allegato: il file PDF dell'etichetta (se esiste fisicamente)
        $attachments = array();
        if ( file_exists( $pdf_path ) ) {
            $attachments[] = $pdf_path;
        }

        // Invio email
        $sent = wp_mail( $admin_email, $subject, $message, $headers, $attachments );

        if ( ! $sent ) {
            error_log( 'GLS: Impossibile inviare email etichetta per ordine #' . $order->get_id() );
        }
    }

    // ========================================================================
    // SHORTCODE TRACKING NUMBER + INTEGRAZIONE EMAIL + PAGINA ORDINE CLIENTE
    // ========================================================================

    /**
     * Hook: cattura l'ID ordine corrente prima del rendering dell'email WooCommerce.
     *
     * WooCommerce renderizza le email fuori dal loop di WordPress: $post è null
     * e lo shortcode non può risalire all'ordine. Questo metodo viene chiamato
     * dall'hook woocommerce_email_before_order_table (che riceve $order come primo
     * argomento) e salva l'ID in una proprietà statica accessibile dallo shortcode.
     *
     * @param WC_Order $order Oggetto ordine passato dal sistema email WooCommerce
     */
    public function capture_email_order_id( $order ) {
        if ( $order instanceof WC_Order ) {
            self::$current_email_order_id = $order->get_id();
        }
    }

    /**
     * Shortcode [gls_tracking_number] per i template email WooCommerce.
     *
     * COME USARLO nei template email WooCommerce (es. "Ordine completato"):
     *   Il tuo codice di tracciamento: [gls_tracking_number]
     *   Traccia la tua spedizione: [gls_tracking_number link="yes"]
     *   Stato spedizione: [gls_tracking_number fallback="In preparazione"]
     *
     * Attributi:
     *   order_id  — ID ordine esplicito (opzionale, di solito non serve)
     *   link      — "yes" (default) avvolge il codice in un link GLS, "no" restituisce solo testo
     *   fallback  — testo mostrato se il tracking non è ancora disponibile (default: stringa vuota)
     *
     * Risoluzione dell'ordine (in ordine di priorità):
     *   1. Attributo order_id esplicito
     *   2. self::$current_email_order_id (popolato dall'hook woocommerce_email_before_order_table)
     *   3. $post globale (funziona nella pagina ordine del backend, non nelle email)
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

        // 1. Attributo esplicito
        $order_id = (int) $atts['order_id'];

        // 2. Ordine catturato dall'hook email (risolve il problema del $post null)
        if ( ! $order_id && self::$current_email_order_id ) {
            $order_id = self::$current_email_order_id;
        }

        // 3. Fallback: $post globale (solo per contesti diversi dalle email)
        if ( ! $order_id ) {
            global $post;
            if ( $post && in_array( $post->post_type, array( 'shop_order', 'wc_order' ), true ) ) {
                $order_id = $post->ID;
            }
        }

        if ( ! $order_id ) {
            return esc_html( $atts['fallback'] );
        }

        $tracking = get_post_meta( $order_id, '_gls_tracking_number', true );

        if ( empty( $tracking ) ) {
            return esc_html( $atts['fallback'] );
        }

        if ( $atts['link'] === 'yes' ) {
            $tracking_url = 'https://gls-group.eu/track/' . urlencode( $tracking );
            return '<a href="' . esc_url( $tracking_url ) . '" target="_blank" style="color:#e2001a;font-weight:bold;">'
                . esc_html( $tracking )
                . '</a>';
        }

        return esc_html( $tracking );
    }

    /**
     * Mostra il blocco di tracking GLS nella pagina "Visualizza ordine" dell'account cliente.
     *
     * Agganciato all'hook woocommerce_order_details_after_order_table che viene eseguito
     * nelle pagine: /account/visualizza-ordine/{id}/ e nell'email riepilogo ordine.
     * Mostra il blocco solo se il numero di spedizione GLS è presente nei metadati.
     *
     * @param WC_Order $order Oggetto ordine corrente
     */
    public function display_tracking_on_order_page( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $tracking = get_post_meta( $order->get_id(), '_gls_tracking_number', true );

        if ( empty( $tracking ) ) {
            return;
        }

        $tracking_url = 'https://gls-group.eu/track/' . urlencode( $tracking );
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
    //
    // AVVERTENZA IMPORTANTE dalla documentazione GLS:
    // "Cancellare una spedizione con il metodo 'DeleteSped' se già 'Chiusa'
    // (inviata alla sede tramite CloseWorkDay) non inibisce l'inoltro della
    // spedizione e delle relative informazioni nel circuito GLS."
    // In tal caso è necessario contattare direttamente la sede GLS per
    // bloccare fisicamente la spedizione.
    // ========================================================================

    /**
     * Cancella la spedizione GLS quando un ordine WooCommerce viene annullato.
     * Viene chiamato automaticamente al cambio stato → "cancelled".
     *
     * Struttura XML richiesta (MU162 §5.4):
     *   <DeleteSped>
     *     <SedeGls>YH</SedeGls>
     *     <CodiceClienteGls>74453</CodiceClienteGls>
     *     <PasswordClienteGls>password</PasswordClienteGls>
     *     <NumSpedizione>620000761</NumSpedizione>
     *   </DeleteSped>
     *
     * @param int $order_id ID dell'ordine WooCommerce annullato
     */
    public function cancel_gls_shipment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Verifica se esiste un tracking number per questo ordine
        $tracking = get_post_meta( $order_id, '_gls_tracking_number', true );
        if ( empty( $tracking ) ) {
            // Nessuna spedizione GLS da cancellare
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

        // Costruisce l'XML per DeleteSped (Ref: MU162 §5.4)
        // La struttura root è <DeleteSped>, NON <Info> come per AddParcel/CloseWorkDay.
        // NumSpedizione: il numero di tracking GLS restituito da AddParcel.
        $xml  = '<DeleteSped>';
        $xml .= '<SedeGls>' . esc_html( $sede ) . '</SedeGls>';
        $xml .= '<CodiceClienteGls>' . esc_html( $cliente ) . '</CodiceClienteGls>';
        $xml .= '<PasswordClienteGls>' . esc_html( $password ) . '</PasswordClienteGls>';
        $xml .= '<NumSpedizione>' . esc_html( $tracking ) . '</NumSpedizione>';
        $xml .= '</DeleteSped>';

        // Log di debug in error_log (password mascherata) per facilitare diagnosi
        $xml_log = str_replace( esc_html( $password ), '***', $xml );
        error_log( 'GLS DeleteSped XML sent order #' . $order_id . ': ' . $xml_log );

        // NOTA: la documentazione MU162 §5.4 non specifica esplicitamente il nome del
        // parametro HTTP POST per DeleteSped (a differenza di AddParcel → "XMLInfoParcel"
        // e CloseWorkDay → "XMLInfo"). Proviamo prima con "XMLInfo" (pattern più comune),
        // poi con "XMLInfoSped" come fallback in caso di HTTP 500/errore.
        $param_names = array( 'XMLInfo', 'XMLInfoSped' );
        $response    = null;
        $http_code   = 0;
        $body        = '';

        foreach ( $param_names as $param_name ) {
            $response = wp_remote_post( $this->api_url_deletesped, array(
                'method'  => 'POST',
                'timeout' => 30,
                'body'    => array( $param_name => $xml ),
            ) );

            if ( is_wp_error( $response ) ) {
                $order->add_order_note(
                    'GLS Error di rete durante la cancellazione spedizione ' . $tracking . ': '
                    . $response->get_error_message() . '. Contatta manualmente la sede GLS.'
                );
                error_log( 'GLS DeleteSped network error order #' . $order_id . ': ' . $response->get_error_message() );
                return;
            }

            $http_code = wp_remote_retrieve_response_code( $response );
            $body      = wp_remote_retrieve_body( $response );

            error_log( 'GLS DeleteSped param="' . $param_name . '" HTTP ' . $http_code . ' order #' . $order_id . ': ' . substr( $body, 0, 300 ) );

            // Se la risposta non è un 500 server error, usiamo questa risposta
            if ( $http_code !== 500 ) {
                break;
            }
        }

        // Gestione HTTP 500: può dipendere da:
        // 1) Nome parametro POST errato (il server non trova il metodo)
        // 2) Ambiente di test GLS che non espone DeleteSped
        // 3) Errore lato server GLS (transitorio)
        if ( $http_code === 500 ) {
            $order->add_order_note(
                '⚠️ GLS: Il server ha restituito HTTP 500 durante la cancellazione della spedizione ' . $tracking . '. '
                . 'Possibili cause: (1) la funzione DeleteSped non è abilitata per questo account, '
                . '(2) si sta usando un\'etichetta di test non cancellabile via webservice. '
                . 'Contatta la sede GLS per procedere manualmente. '
                . 'Il tracking è stato rimosso dall\'ordine.'
            );
            // Rimuove comunque il tracking per evitare doppi tentativi
            delete_post_meta( $order->get_id(), '_gls_tracking_number' );
            delete_post_meta( $order->get_id(), '_gls_label_pdf_url' );
            error_log( 'GLS DeleteSped HTTP 500 body order #' . $order_id . ': ' . substr( $body, 0, 500 ) );
            return;
        }

        if ( $http_code !== 200 ) {
            $order->add_order_note(
                'GLS HTTP Error ' . $http_code . ' durante la cancellazione spedizione ' . $tracking . '. Contatta manualmente la sede GLS.'
            );
            return;
        }

        // Parsing della risposta
        // La risposta GLS per DeleteSped contiene messaggi di esito in testo semplice
        // o un tag XML con <DescrizioneErrore>. Esempi dalla doc MU162 §5.4:
        //   - "Eliminazione della spedizione xxxxxxxxx avvenuta." → successo
        //   - "Spedizione xxxxxxxxx non presente."                → già cancellata
        //   - "Funzionalità non abilitata. ..."                   → errore account
        $this->parse_delete_sped_response( $body, $order, $tracking );
    }

    /**
     * Analizza la risposta della chiamata DeleteSped.
     *
     * La documentazione (MU162 §5.4) elenca i possibili messaggi di errore/esito:
     *   - "Eliminazione della spedizione xxxxxxxxx avvenuta."
     *   - "Spedizione xxxxxxxxx non presente."
     *   - "Sigla sede non specificata."
     *   - "Codice cliente Gls non valido."
     *   - "Numero di spedizione Gls non valido."
     *   - "Impossibile connettersi al web server centrale."
     *   - "Funzionalità non abilitata. Contattare la sede di competenza."
     *
     * AVVERTENZA: se la spedizione era già "Chiusa" (CloseWorkDay eseguito),
     * la cancellazione sul webservice non impedisce l'inoltro fisico nel circuito GLS.
     * In quel caso è necessario contattare direttamente la sede GLS.
     *
     * @param string   $response_body Corpo della risposta HTTP grezza
     * @param WC_Order $order         Oggetto ordine WooCommerce
     * @param string   $tracking      Numero di tracking GLS
     */
    private function parse_delete_sped_response( $response_body, $order, $tracking ) {
        // Estrai la risposta dal wrapper ASMX se presente
        $inner = $this->extract_asmx_response( $response_body );

        // Prova a leggere come XML per trovare eventuali tag <DescrizioneErrore>
        $xml = @simplexml_load_string( $inner );

        if ( $xml !== false && isset( $xml->DescrizioneErrore ) ) {
            $desc = trim( (string) $xml->DescrizioneErrore );
        } else {
            // Risposta in testo semplice (tipico di DeleteSped)
            $desc = trim( strip_tags( $inner ) );
        }

        // Normalizza per confronto case-insensitive
        $desc_lower = strtolower( $desc );

        if ( strpos( $desc_lower, 'avvenuta' ) !== false || strpos( $desc_lower, 'eliminazione' ) !== false ) {
            // Cancellazione avvenuta con successo
            $order->add_order_note( '✅ Spedizione GLS ' . $tracking . ' cancellata con successo sul webservice GLS.' );
            // Rimuove il tracking number dai metadati (sia privato che pubblico)
            delete_post_meta( $order->get_id(), '_gls_tracking_number' );
            delete_post_meta( $order->get_id(), 'gls_tracking_number' );
            delete_post_meta( $order->get_id(), '_gls_label_pdf_url' );

        } elseif ( strpos( $desc_lower, 'non presente' ) !== false ) {
            // Spedizione già non presente sul webservice (es. mai inviata o già cancellata)
            $order->add_order_note( 'ℹ️ Spedizione GLS ' . $tracking . ' non trovata sul webservice (potrebbe essere già stata cancellata).' );
            delete_post_meta( $order->get_id(), '_gls_tracking_number' );
            delete_post_meta( $order->get_id(), 'gls_tracking_number' );

        } elseif ( strpos( $desc_lower, 'funzionalità non abilitata' ) !== false ) {
            // La funzione DeleteSped non è abilitata per questo account GLS
            $order->add_order_note( '⛔ GLS: Funzionalità DeleteSped non abilitata per questo account. Contatta la sede GLS per annullare manualmente la spedizione ' . $tracking . '.' );

        } else {
            // Risposta generica o errore non riconosciuto.
            // AVVERTENZA: se la spedizione è già "Chiusa", la cancellazione sul webservice
            // non blocca l'inoltro fisico nel circuito GLS (Ref: MU162 §5.4).
            $note  = '⚠️ GLS: Risposta cancellazione spedizione ' . $tracking . ': ' . esc_html( $desc );
            $note .= ' | ATTENZIONE: se la spedizione è già stata inviata alla sede GLS tramite CloseWorkDay, contatta direttamente la sede GLS per bloccarla fisicamente.';
            $order->add_order_note( $note );
        }
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
        // Rimuove eventuali BOM (Byte Order Mark) UTF-8
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

            // Caso 2: Root è già InfoLabel, Info o simile → risposta diretta
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

        // Costruzione XML per CloseWorkDay
        $xml  = '<Info>';
        $xml .= '<SedeGls>' . esc_html( $sede ) . '</SedeGls>';
        $xml .= '<CodiceClienteGls>' . esc_html( $cliente ) . '</CodiceClienteGls>';
        $xml .= '<PasswordClienteGls>' . esc_html( $password ) . '</PasswordClienteGls>';
        // CloseWorkDayResult = "S" per ricevere lo stato delle spedizioni trasmesse
        $xml .= '<CloseWorkDayResult>S</CloseWorkDayResult>';
        $xml .= '</Info>';

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
        error_log( 'GLS CloseWorkDay Eseguito. Risposta: ' . substr( $body, 0, 500 ) );
    }
}

// ============================================================================
// HANDLER PER CLOSEWORKDAY MANUALE (via admin-post.php)
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

    ( new GLS_WooCommerce_Integration_Advanced() )->execute_close_work_day();

    wp_redirect( admin_url( 'admin.php?page=gls-settings&cwd_success=1' ) );
    exit;
}

// Inizializza il core del plugin
new GLS_WooCommerce_Integration_Advanced();


// ============================================================================
// TARIFFE E METODO DI SPEDIZIONE WooCommerce
// Calcola le tariffe GLS in base a scaglioni di peso e zona geografica.
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
                $weight = WC()->cart->get_cart_contents_weight();
                if ( $weight <= 0 ) {
                    $weight = 2;
                }

                $state    = $package['destination']['state'];
                $postcode = $package['destination']['postcode'];

                // Province di Calabria e Sicilia
                $calabria_sicilia = array(
                    'CZ', 'CS', 'KR', 'RC', 'VV',
                    'AG', 'CL', 'CT', 'EN', 'ME', 'PA', 'RG', 'SR', 'TP',
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
                    // Da 100 a 500 Kg: tariffa base 50-100 + extra per ogni 50 Kg aggiuntivi
                    $base  = (float) $this->get_option( $prefix . '50_100' );
                    $extra = (float) $this->get_option( $prefix . 'extra_50' );
                    $cost  = $base + ( ceil( ( $weight - 100 ) / 50 ) * $extra );
                } else {
                    // Oltre 500 Kg: base + 8 scaglioni da 50 Kg (per 100→500) + extra per ogni 100 Kg sopra i 500
                    $base      = (float) $this->get_option( $prefix . '50_100' );
                    $extra_50  = (float) $this->get_option( $prefix . 'extra_50' );
                    $extra_100 = (float) $this->get_option( $prefix . 'extra_100' );
                    $cost      = $base + ( 8 * $extra_50 ) + ( ceil( ( $weight - 500 ) / 100 ) * $extra_100 );
                }

                // Maggiorazione per isole minori e zone lagunari (Venezia, Capri, Ischia, ecc.)
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

                // Spedizione gratuita se il totale carrello supera la soglia configurata
                $free_threshold           = (float) get_option( 'gls_free_shipping_threshold', '0' );
                $cart_total_for_threshold = WC()->cart->get_cart_contents_total() + WC()->cart->get_cart_contents_tax();

                if ( $free_threshold > 0 && $cart_total_for_threshold >= $free_threshold ) {
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
// Aggiunge un supplemento quando il cliente sceglie il pagamento in contrassegno.
// ============================================================================
add_action( 'woocommerce_cart_calculate_fees', 'gls_add_cod_fee', 20, 1 );

/**
 * Calcola e aggiunge il supplemento contrassegno al carrello.
 * Il supplemento è calcolato come percentuale del totale (carrello + spedizione),
 * con un importo minimo configurabile. L'IVA viene applicata sul netto.
 *
 * @param WC_Cart $cart Oggetto carrello WooCommerce
 */
function gls_add_cod_fee( $cart ) {
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

    if ( 'cod' === $chosen_payment_method ) {
        $percentage = (float) get_option( 'gls_cod_fee_percentage', '2' );
        $min_fee    = (float) get_option( 'gls_cod_min_fee', '5.00' );
        $vat_rate   = (float) get_option( 'gls_vat_rate', '22' );

        // Base di calcolo: contenuto carrello + spedizione (netto)
        $cart_total = $cart->get_cart_contents_total() + $cart->get_shipping_total();
        // Applica la percentuale con importo minimo, poi aggiunge IVA
        $base_fee     = max( $min_fee, $cart_total * ( $percentage / 100 ) );
        $fee_with_vat = $base_fee * ( 1 + ( $vat_rate / 100 ) );

        // Aggiunge come fee non tassabile (IVA già inclusa nel calcolo)
        $cart->add_fee( 'Supplemento Contrassegno GLS', $fee_with_vat, false );
    }
}


// ============================================================================
// AGGIORNAMENTO CHECKOUT AL CAMBIO METODO DI PAGAMENTO
// Forza il ricalcolo dei totali quando il cliente cambia metodo di pagamento.
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
                $( 'form.checkout' ).on( 'change', 'input[name="payment_method"]', function() {
                    $( document.body ).trigger( 'update_checkout' );
                });
            });
        </script>
        <?php
    }
}


// ============================================================================
// INTEGRAZIONE YAYMAIL — Variabili custom per il tracking GLS
//
// YayMail (Email Customizer for WooCommerce) non processa shortcode WordPress
// standard. Espone invece il filtro yaymail_custom_variables che permette di
// registrare variabili drag-and-drop nel suo builder visuale.
//
// Variabili registrate:
//   {{gls_tracking_number}} — codice tracking testuale (es. "661209312")
//   {{gls_tracking_link}}   — link HTML cliccabile con bottone rosso GLS
//
// Come usarle in YayMail:
//   1. Apri YayMail → Email Customizer
//   2. Seleziona il template (es. "Ordine completato" o "Ordine in lavorazione")
//   3. Aggiungi un blocco "Testo" o "HTML" nel template
//   4. Nel pannello variabili (icona {} o "Custom Variables") troverai
//      "GLS Tracking Number" e "GLS Tracking Link" in fondo alla lista
//   5. Clicca sulla variabile per inserirla, oppure scrivi {{gls_tracking_number}}
//      o {{gls_tracking_link}} direttamente nel testo
//
// Le variabili restituiscono stringa vuota se il tracking non è ancora disponibile,
// quindi sono sicure da inserire anche nelle email inviate prima della spedizione.
// ============================================================================

/**
 * Registra le variabili custom GLS nel pannello variabili di YayMail.
 *
 * Il filtro yaymail_custom_variables riceve e restituisce un array associativo:
 *   [ 'chiave_variabile' => [ 'label' => '...', 'description' => '...' ] ]
 * La "chiave" diventa il nome tra {{ }} nel template.
 *
 * @param array $variables Variabili già registrate da YayMail e altri plugin
 * @return array Variabili con quelle GLS aggiunte
 */
add_filter( 'yaymail_custom_variables', 'gls_register_yaymail_variables' );
function gls_register_yaymail_variables( $variables ) {
    $variables['gls_tracking_number'] = array(
        'label'       => 'GLS Tracking Number',
        'description' => 'Codice di tracking GLS (solo testo, es. 661209312).',
    );
    $variables['gls_tracking_link'] = array(
        'label'       => 'GLS Tracking Link',
        'description' => 'Bottone cliccabile con codice tracking e link al sito GLS.',
    );
    return $variables;
}

/**
 * Risolve il valore delle variabili custom GLS quando YayMail renderizza l'email.
 *
 * YayMail chiama questo filtro per ogni variabile {{...}} trovata nel template,
 * passando l'oggetto $order relativo all'email in fase di invio.
 *
 * @param string   $value   Valore corrente (da sovrascrivere)
 * @param string   $var_key Chiave della variabile (es. "gls_tracking_number")
 * @param WC_Order $order   Ordine associato all'email corrente
 * @return string Testo o HTML sostitutivo, stringa vuota se tracking non disponibile
 */
add_filter( 'yaymail_custom_variable_value', 'gls_resolve_yaymail_variable', 10, 3 );
function gls_resolve_yaymail_variable( $value, $var_key, $order ) {
    if ( ! in_array( $var_key, array( 'gls_tracking_number', 'gls_tracking_link' ), true ) ) {
        return $value; // Non è una variabile GLS: lascia invariato
    }

    if ( ! $order instanceof WC_Order ) {
        return '';
    }

    // Legge dal meta privato (fonte canonica del plugin)
    $tracking = get_post_meta( $order->get_id(), '_gls_tracking_number', true );

    if ( empty( $tracking ) ) {
        return ''; // Tracking non ancora disponibile: variabile sparisce dal testo
    }

    $tracking_url = 'https://gls-group.eu/track/' . urlencode( $tracking );

    if ( $var_key === 'gls_tracking_number' ) {
        // Solo codice testuale: il cliente può copiarlo manualmente
        return esc_html( $tracking );
    }

    // gls_tracking_link: bottone stilizzato con il rosso GLS (#e2001a)
    return '<a href="' . esc_url( $tracking_url ) . '" '
        . 'target="_blank" rel="noopener noreferrer" '
        . 'style="display:inline-block;padding:8px 18px;background:#e2001a;color:#ffffff;'
        . 'text-decoration:none;border-radius:4px;font-weight:bold;font-size:14px;">'
        . 'Traccia la tua spedizione GLS &rarr;'
        . '</a>';
}