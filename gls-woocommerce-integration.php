<?php
/**
 * Plugin Name: GLS Italy WooCommerce Integration
 * Plugin URI: https://github.com/RiccardoCalvi/gls_woocommerce_italy
 * Description: Integrazione API GLS (Etichette) + Calcolo Tariffe di Spedizione e Contrassegno.
 * Version: 1.3.4
 * Author: Dream2Dev
 * Requires at least: 5.8
 * Tested up to: 6.7
 * WC requires at least: 7.0
 * WC tested up to: 9.6
 *
 * Changelog v1.3.4:
 *   - Fix errore critico endpoint cronjob: la meta_query con NOT EXISTS in
 *     combinazione AND causa un fatal error con HPOS attivo. WooCommerce HPOS
 *     usa OrdersTableQuery che non supporta pienamente il comparatore NOT EXISTS
 *     in compound meta_query. Soluzione: query semplice con meta_key per trovare
 *     gli ordini con tracking, poi filtro in PHP per escludere quelli già confermati.
 *   - Fix CloseWorkDay manuale "senza effetto": il pulsante manuale restituiva
 *     successo ma non confermava nessun ordine. La causa era la stessa meta_query
 *     rotta che restituiva 0 risultati. Ora il filtro PHP funziona correttamente.
 *   - Fix init priority endpoint cron: cambiato da priority 1 (troppo presto,
 *     WooCommerce potrebbe non essere caricato) a priority 20.
 *   - Aggiunto try/catch nel cron endpoint per catturare eccezioni e Throwable
 *     — evita il "white screen" WordPress e logga l'errore effettivo in error_log.
 *   - Aggiunto check disponibilità WooCommerce nel cron endpoint: se wc_get_orders()
 *     non è disponibile, restituisce HTTP 503 con messaggio diagnostico.
 *   - Migliorato logging CWD: logga il conteggio ordini con tracking vs ordini
 *     in attesa di conferma, per diagnostica immediata.
 *
 * Changelog v1.3.3:
 *   - Cronjob server-side: aggiunto endpoint URL dedicato con token segreto per
 *     consentire l'invocazione della CloseWorkDay da cronjob di sistema (Hostinger,
 *     cPanel, ecc.) senza dipendere dal wp-cron basato sulle visite utente.
 *     L'URL ha la forma: https://tuodominio.com/?gls_cron_action=close_work_day&token=XXX
 *     Il token viene auto-generato alla prima attivazione e mostrato nella pagina
 *     impostazioni GLS con le istruzioni di configurazione.
 *   - Fix CloseWorkDay: il metodo ora utilizza CloseWorkDayByShipmentNumber (CWDBSN)
 *     al posto di CloseWorkDay generico. Il vecchio codice inviava un blocco <Info>
 *     VUOTO (senza tag <Parcel>), che secondo la documentazione MU162 §5.2 richiede
 *     obbligatoriamente la lista dei colli da convalidare. CWDBSN (MU162 §5.3) accetta
 *     direttamente i numeri di spedizione, evitando di dover ritrasmettere tutti i dati
 *     destinatario. Il plugin ora cerca automaticamente gli ordini WooCommerce con
 *     tracking GLS (_gls_tracking_number) non ancora confermati (_gls_cwd_closed)
 *     e li include nella chiamata CWDBSN.
 *   - Nuovo meta _gls_cwd_closed: traccia quali ordini sono stati confermati alla
 *     sede GLS tramite CloseWorkDay. Evita invii duplicati nelle esecuzioni successive.
 *   - Aggiornato orario cron wp-cron da 18:00 a 19:00 (coerente con il cronjob server).
 *   - Pagina impostazioni: aggiunta sezione "Configurazione Cronjob" con URL pronto
 *     da copiare, istruzioni step-by-step per Hostinger e campo token rigenerabile.
 *   - Migliorato logging: execute_close_work_day() logga il numero di spedizioni
 *     trovate, i tracking inclusi, e l'esito per ogni singola spedizione dalla risposta.
 *
 * Changelog v1.3.2:
 *   - Fix DeleteSped "Sigla sede non specificata": la causa era il root element
 *     dell'XML. La doc MU162 §5.4 mostra <DeleteSped> come root, ma l'endpoint
 *     .asmx/DeleteSped via HTTP POST wrappa già il parametro nella struttura
 *     del metodo. Con <DeleteSped> come root si otteneva doppio nesting:
 *       <DeleteSped><DeleteSped><SedeGls>...</SedeGls>...</DeleteSped></DeleteSped>
 *     e il server cercava <SedeGls> al primo livello, trovando <DeleteSped>.
 *     Per AddParcel e CloseWorkDay il root è <Info> (diverso dal nome metodo),
 *     quindi non c'è conflitto.
 *   - Implementata strategia a 2 varianti XML: il plugin prova automaticamente
 *     sia root <Info> (coerente con AddParcel/CloseWorkDay) sia root <DeleteSped>
 *     (come da documentazione). Se la prima variante restituisce "sede non
 *     specificata", passa alla seconda. Per ogni variante prova i parametri HTTP.
 *   - Smart retry: se una combinazione parametro+variante restituisce HTTP 200
 *     con errore "sede non specificata", il plugin riconosce che il parametro è
 *     corretto ma l'XML root è sbagliato → prova la variante successiva con lo
 *     stesso parametro, senza ripetere tentativi inutili.
 *
 * Changelog v1.3.1:
 *   - Fix DeleteSped HTTP 500: riscritto completamente il metodo cancel_gls_shipment
 *     con strategia di chiamata a 2 livelli di fallback.
 *     Livello 1: HTTP POST form-encoded con 3 nomi parametro tentati in sequenza
 *       (XMLInfoSped → XMLInfo → XMLInfoDelete). La doc MU162 §5.4 non specifica
 *       il nome del parametro HTTP per DeleteSped, a differenza di AddParcel (XMLInfoParcel)
 *       e CloseWorkDay (XMLInfo). Il vecchio codice provava solo XMLInfo e XMLInfoSped.
 *     Livello 2: SOAP 1.1 fallback — se tutti i tentativi HTTP POST restituiscono 500,
 *       il plugin invia una richiesta SOAP 1.1 all'endpoint base ilswebservice.asmx
 *       con SOAPAction "https://labelservice.gls-italy.com/DeleteSped".
 *       SOAP bypassa completamente il problema del nome parametro HTTP perché la
 *       struttura è definita dal WSDL del servizio.
 *   - Nuovo metodo delete_sped_soap(): implementa la chiamata SOAP 1.1 con envelope
 *     standard e parsing della risposta <DeleteSpedResult> dall'envelope di ritorno.
 *   - Nuovo metodo extract_soap_response(): estrae il contenuto dal wrapper SOAP,
 *     gestendo sia risposte normali (<*Result>) sia SOAP Fault (<faultstring>).
 *   - Fix: errore di rete su un tentativo non blocca più i tentativi successivi
 *     (il vecchio codice faceva "return" immediato al primo errore di rete).
 *   - Migliorato logging: ogni tentativo (HTTP POST e SOAP) logga separatamente
 *     parametro usato, HTTP code e snippet della risposta per facilitare la diagnosi.
 *
 * Changelog v1.3.0:
 *   - HPOS (High-Performance Order Storage): piena compatibilità con il nuovo storage ordini
 *     di WooCommerce. Tutte le chiamate get_post_meta/update_post_meta/delete_post_meta sono
 *     state sostituite con i metodi CRUD di WC_Order ($order->get_meta(), ->update_meta_data(),
 *     ->delete_meta_data(), ->save()). Questo risolve il problema dei metadati non visibili
 *     nella metabox "Campi personalizzati" e nei campi meta in fondo alla pagina ordine.
 *     Dichiarata compatibilità HPOS tramite before_woocommerce_init.
 *   - Fix YayMail Pro: riscritto il sistema di integrazione con YayMail. I vecchi filtri
 *     yaymail_custom_variables / yaymail_custom_variable_value NON esistono in YayMail.
 *     Ora vengono registrati shortcode custom tramite il filtro corretto
 *     yaymail_customs_shortcode (con la "s"), usando il prefisso obbligatorio
 *     [yaymail_custom_shortcode_*]. Shortcode disponibili nel builder YayMail:
 *       [yaymail_custom_shortcode_gls_tracking]     — codice tracking testuale
 *       [yaymail_custom_shortcode_gls_tracking_link] — bottone HTML cliccabile stile GLS
 *   - Fix: aggiunto hook woocommerce_email_order_meta_fields per iniettare il tracking
 *     direttamente nei metadati ordine delle email WooCommerce native, come fallback
 *     indipendente da YayMail.
 *   - Fix: il link di tracking GLS ora punta all'URL ufficiale GLS Italy per il tracciamento
 *     (https://www.gls-italy.com/it/servizi/servizi-per-chi-riceve/ricerca-spedizioni).
 *   - Fix: lo shortcode [gls_tracking_number] ora funziona anche con HPOS attivo,
 *     utilizzando wc_get_order() + get_meta() al posto di get_post_meta().
 *   - Fix: la rigenerazione etichetta da azione ordine ora salva correttamente i meta
 *     e questi appaiono immediatamente nella pagina dettaglio ordine.
 *   - Rimossi i filtri YayMail errati (yaymail_custom_variables, yaymail_custom_variable_value).
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
 *     "XMLInfoSped" (la doc MU162 §5.4 non specifica il nome esatto del parametro HTTP).
 *   - Fix DeleteSped: gestione esplicita HTTP 500.
 *   - Fix DeleteSped: aggiunto log in error_log dell'XML inviato (con password mascherata).
 *
 * Changelog v1.2.0:
 *   - Nuova funzione: cancellazione spedizione GLS (DeleteSped) al cambio stato → "Annullato"
 *   - Nuova funzione: invio automatico email all'amministratore con etichetta PDF in allegato
 *   - Nuova funzione: shortcode [gls_tracking_number] per visualizzare il tracking nelle email
 *   - Rimossi log di debug eccessivi nelle note ordine
 *
 * Changelog v1.1.1:
 *   - Fix critico parsing risposta: NumeroSpedizione viene ora controllato PRIMA di NoteSpedizione
 *   - Fix: gestione root element <InfoLabel xmlns=""> nella risposta
 *   - Aggiunta gestione etichette GLS CHECK (routing fallito ma spedizione creata)
 *
 * Changelog v1.1.0:
 *   - Fix critico: corretti nomi tag XML per conformità con documentazione API GLS (MU162 v30)
 *   - Fix: formato decimale con virgola come richiesto dall'API GLS
 *   - Fix: parsing risposta ASMX
 *   - Aggiunto tag <GeneraPdf>4</GeneraPdf> per etichetta PDF 10x15
 *   - Aggiunto tag <TipoPorto>F</TipoPorto> obbligatorio
 *   - Aggiunto tag <ModalitaIncasso>CONT</ModalitaIncasso> quando contrassegno attivo
 *   - Aggiunto tag <TipoSpedizione>N</TipoSpedizione> per spedizioni nazionali
 *   - Implementato cron CloseWorkDay con scheduling effettivo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Impedisce l'accesso diretto al file
}

// ============================================================================
// DICHIARAZIONE COMPATIBILITÀ HPOS (High-Performance Order Storage)
//
// WooCommerce 8.2+ utilizza tabelle custom (wp_wc_orders, wp_wc_orders_meta)
// al posto di wp_posts/wp_postmeta per gli ordini. Senza questa dichiarazione,
// WooCommerce mostra un avviso di incompatibilità nella pagina stato del sistema.
//
// Ref: https://developer.woocommerce.com/docs/hpos-extension-recipe-book/
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
     * Endpoint API GLS per la chiusura giornaliera per numero spedizione (CWDBSN).
     * Ref: MU162 Label Service v30, sezione 5.3
     *
     * NOTA v1.3.3: cambiato da CloseWorkDay a CloseWorkDayByShipmentNumber.
     * CloseWorkDay (§5.2) richiede la ritrasmissione di TUTTI i dati destinatario
     * per ogni collo, rendendo la chiamata complessa e fragile.
     * CWDBSN (§5.3) accetta direttamente i numeri di spedizione GLS, che il plugin
     * ha già salvato nei meta ordine durante AddParcel.
     */
    private $api_url_cwdbsn = 'https://labelservice.gls-italy.com/ilswebservice.asmx/CloseWorkDayByShipmentNumber';

    /**
     * Endpoint API GLS per la cancellazione di una spedizione (DeleteSped).
     * Ref: MU162 Label Service v30, sezione 5.4
     * NOTA: la cancellazione di una spedizione già "Chiusa" (inviata tramite CloseWorkDay)
     * NON blocca l'inoltro nel circuito GLS; contattare la sede GLS per interventi fisici.
     */
    private $api_url_deletesped = 'https://labelservice.gls-italy.com/ilswebservice.asmx/DeleteSped';

    /**
     * URL base per il tracking GLS Italy.
     * Ref: sito ufficiale GLS Italy — pagina ricerca spedizioni
     */
    private $tracking_base_url = 'https://www.gls-italy.com/it/servizi/servizi-per-chi-riceve/ricerca-spedizioni?match=';

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

        // Cron per CloseWorkDay giornaliero automatico (wp-cron come fallback)
        add_action( 'init', array( $this, 'schedule_cron' ) );
        add_action( 'gls_daily_close_work_day', array( $this, 'execute_close_work_day' ) );

        // Endpoint URL dedicato per cronjob server-side (Hostinger, cPanel, ecc.)
        // Intercetta le richieste con ?gls_cron_action=close_work_day&token=XXX
        // prima che WordPress carichi il tema — leggero e veloce.
        add_action( 'init', array( $this, 'handle_cron_endpoint' ), 20 );

        // Pulizia cron alla disattivazione del plugin
        register_deactivation_hook( __FILE__, array( $this, 'clear_cron' ) );

        // Shortcode per mostrare il tracking number nelle email al cliente
        add_shortcode( 'gls_tracking_number', array( $this, 'tracking_number_shortcode' ) );

        // Hook per catturare l'ordine corrente PRIMA del rendering dell'email.
        // Necessario perché $post è null nelle email WooCommerce.
        add_action( 'woocommerce_email_before_order_table', array( $this, 'capture_email_order_id' ), 1, 1 );

        // Mostra il blocco tracking GLS nella pagina "Visualizza ordine" dell'account cliente
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_tracking_on_order_page' ), 10, 1 );

        // Inietta il tracking GLS nei metadati ordine delle email WooCommerce native.
        // Questo hook funziona sia con le email WooCommerce standard sia come fallback
        // quando YayMail processa gli hook nativi. Mostra "Tracking GLS: <link>"
        // nella sezione metadati dell'email sotto la tabella ordine.
        add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'add_tracking_to_email_meta' ), 10, 3 );
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
    //
    // WooCommerce 8.2+ con HPOS attivo usa tabelle custom per gli ordini.
    // Le funzioni get_post_meta/update_post_meta scrivono su wp_postmeta,
    // ma WooCommerce legge da wp_wc_orders_meta → i dati non appaiono.
    //
    // I metodi CRUD di WC_Order ($order->get_meta(), ->update_meta_data(),
    // ->delete_meta_data()) funzionano con ENTRAMBI gli storage backend.
    // IMPORTANTE: dopo update_meta_data/delete_meta_data serve $order->save().
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

        // Token segreto per cronjob server-side
        // Se il token non esiste ancora, lo auto-generiamo alla prima registrazione
        register_setting( 'gls_settings_group', 'gls_cron_secret_token' );
    }

    /**
     * Genera un token casuale sicuro per l'autenticazione del cronjob.
     * Usa wp_generate_password per generare una stringa alfanumerica di 32 caratteri.
     *
     * @return string Token casuale di 32 caratteri
     */
    private function generate_cron_token() {
        return wp_generate_password( 32, false, false );
    }

    /**
     * Restituisce il token corrente, creandolo se non esiste.
     *
     * @return string Token segreto per il cronjob
     */
    private function get_or_create_cron_token() {
        $token = get_option( 'gls_cron_secret_token', '' );
        if ( empty( $token ) ) {
            $token = $this->generate_cron_token();
            update_option( 'gls_cron_secret_token', $token );
        }
        return $token;
    }

    /**
     * Costruisce l'URL completo per il cronjob server-side.
     *
     * @return string URL con parametri gls_cron_action e token
     */
    private function get_cron_endpoint_url() {
        $token = $this->get_or_create_cron_token();
        return site_url( '/?gls_cron_action=close_work_day&token=' . $token );
    }

    /**
     * Renderizza l'HTML della pagina impostazioni GLS.
     * Include campi per credenziali, costi/tasse, contrassegno, cronjob e azione manuale CWD.
     */
    public function settings_page_html() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        // Gestione rigenerazione token tramite POST
        if ( isset( $_POST['gls_regenerate_token'] ) && check_admin_referer( 'gls_regenerate_token_action' ) ) {
            $new_token = $this->generate_cron_token();
            update_option( 'gls_cron_secret_token', $new_token );
            echo '<div class="notice notice-success is-dismissible"><p>Token cronjob rigenerato con successo. Aggiorna il cronjob su Hostinger con il nuovo URL.</p></div>';
        }

        // Mostra messaggio di successo dopo CloseWorkDay manuale
        if ( isset( $_GET['cwd_success'] ) && $_GET['cwd_success'] == '1' ) {
            echo '<div class="notice notice-success is-dismissible"><p>CloseWorkDay eseguito con successo. Controlla i log per i dettagli.</p></div>';
        }

        // Assicura che il token esista
        $cron_url = $this->get_cron_endpoint_url();
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

            <!-- ============ CONFIGURAZIONE CRONJOB SERVER-SIDE ============ -->
            <hr>
            <h2>⏰ Configurazione Cronjob Automatico (ore 19:00)</h2>
            <p>
                Per inviare automaticamente le etichette (CloseWorkDay) ogni giorno alle 19:00
                senza premere il pulsante, configura un <strong>cronjob server-side</strong> su Hostinger.
            </p>

            <h3>URL da usare nel cronjob</h3>
            <div style="background:#f0f0f0; padding:12px 16px; border:1px solid #ccc; border-radius:4px; font-family:monospace; font-size:13px; word-break:break-all; margin:10px 0;">
                <?php echo esc_html( $cron_url ); ?>
            </div>
            <button type="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $cron_url ); ?>').then(function(){alert('URL copiato negli appunti!')})" class="button button-secondary" style="margin-top:4px;">
                📋 Copia URL
            </button>

            <h3>Istruzioni per Hostinger</h3>
            <ol style="line-height:2;">
                <li>Accedi al pannello <strong>hPanel</strong> di Hostinger</li>
                <li>Vai su <strong>Avanzate → Cron Jobs</strong> (o "Processi Cron")</li>
                <li>In "Aggiungi nuovo Cron Job", seleziona la frequenza: <strong>"Una volta al giorno"</strong></li>
                <li>Imposta il campo <strong>minuto</strong> a <code>0</code> e l'<strong>ora</strong> a <code>19</code>
                    <br><small>(Il formato sarà: <code>0 19 * * *</code> — ogni giorno alle 19:00)</small></li>
                <li>Nel campo <strong>Comando</strong>, incolla:
                    <div style="background:#1d2327; color:#50c878; padding:10px 14px; border-radius:4px; font-family:monospace; font-size:12px; margin:6px 0; word-break:break-all;">
                        /usr/bin/curl -s "<?php echo esc_html( $cron_url ); ?>" > /dev/null 2>&1
                    </div>
                </li>
                <li>Clicca <strong>"Crea"</strong> o <strong>"Salva"</strong></li>
            </ol>

            <p style="background:#fff3cd; border:1px solid #ffc107; padding:10px 14px; border-radius:4px; margin:16px 0;">
                <strong>⚠️ Importante:</strong> Il token nell'URL è la chiave di sicurezza. Non condividerlo.
                Se sospetti che sia compromesso, rigeneralo con il pulsante qui sotto e aggiorna il cronjob su Hostinger.
            </p>

            <form method="post">
                <?php wp_nonce_field( 'gls_regenerate_token_action' ); ?>
                <input type="hidden" name="gls_regenerate_token" value="1" />
                <?php submit_button( '🔄 Rigenera Token Cronjob', 'secondary', 'submit', false ); ?>
            </form>

            <hr>
            <h2>Azioni Manuali</h2>
            <p>Esegui la chiusura giornaliera (CloseWorkDay) manualmente per confermare le spedizioni create oggi alla sede GLS.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="gls_manual_close_work_day">
                <?php wp_nonce_field( 'gls_manual_cwd', 'gls_cwd_nonce' ); ?>
                <?php submit_button( 'Esegui CloseWorkDay Manualmente', 'secondary' ); ?>
            </form>

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

        // Se il tracking esiste già e non è forzato, non rigenera.
        // Usa il metodo CRUD di WC_Order per compatibilità HPOS.
        if ( ! $force && $order->get_meta( '_gls_tracking_number', true ) ) {
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
        $peso = 0;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && $product->get_weight() ) {
                $peso += (float) $product->get_weight() * $item->get_quantity();
            }
        }
        // Minimo 1 Kg, arrotondamento a 1 decimale come da specifica
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
        if ( isset( $xml->Parcel->NumeroSpedizione ) && ! empty( trim( (string) $xml->Parcel->NumeroSpedizione ) ) ) {
            $track = trim( (string) $xml->Parcel->NumeroSpedizione );

            // Salva il tracking number nei metadati dell'ordine.
            // Usa il metodo CRUD di WC_Order per compatibilità HPOS.
            //   _gls_tracking_number  → meta privato (prefisso _), usato internamente dal plugin
            //   gls_tracking_number   → meta pubblico (senza prefisso _), visibile nella metabox
            //                           "Campi personalizzati" del backend e accessibile da YayMail
            //   _gls_cwd_closed       → NON impostato qui: verrà settato dopo CloseWorkDay
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

                    // Salva URL del PDF nei meta ordine (HPOS-compatible)
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

        // Fase 6: Nessun NumeroSpedizione trovato - anomalo, logga per diagnostica
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
        $message .= "3. Consegna il pacco al corriere GLS\n\n";
        $message .= "Link diretto al PDF: " . $pdf_url . "\n\n";
        $message .= "Tracking online: " . $this->get_tracking_url( $tracking ) . "\n";

        $headers     = array( 'Content-Type: text/plain; charset=UTF-8' );
        $attachments = array();
        if ( file_exists( $pdf_path ) ) {
            $attachments[] = $pdf_path;
        }

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
     * Questo hook (woocommerce_email_order_meta_fields) inietta automaticamente
     * il tracking nella sezione "Order Meta" delle email. Funziona sia con le email
     * WooCommerce standard sia come fallback quando YayMail processa gli hook nativi.
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
     * Attributi:
     *   order_id  — ID ordine esplicito (opzionale)
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

        // Usa il metodo CRUD per compatibilità HPOS
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

        // Usa il metodo CRUD per compatibilità HPOS
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
     * La doc non specifica il nome del parametro HTTP POST per DeleteSped.
     * L'endpoint .asmx supporta sia HTTP POST form-encoded sia SOAP 1.1/1.2.
     * Per massimizzare la compatibilità usiamo 3 livelli di fallback:
     *
     *   Livello 1: HTTP POST form-encoded con nomi parametro probabili
     *              (XMLInfoSped, XMLInfo, XMLInfoDelete) — veloce e leggero
     *   Livello 2: SOAP 1.1 — chiamata all'endpoint base .asmx con envelope SOAP
     *              Questo bypassa completamente il problema del nome parametro HTTP
     *              perché il parametro è definito nel WSDL del servizio.
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

        // Tag XML interni (credenziali + numero spedizione) — comuni a tutte le varianti
        $xml_inner  = '<SedeGls>' . esc_html( $sede ) . '</SedeGls>';
        $xml_inner .= '<CodiceClienteGls>' . esc_html( $cliente ) . '</CodiceClienteGls>';
        $xml_inner .= '<PasswordClienteGls>' . esc_html( $password ) . '</PasswordClienteGls>';
        $xml_inner .= '<NumSpedizione>' . esc_html( $tracking ) . '</NumSpedizione>';

        // Log di debug in error_log (password mascherata)
        $xml_inner_log = str_replace( esc_html( $password ), '***', $xml_inner );
        error_log( 'GLS DeleteSped order #' . $order_id . ' inner XML: ' . $xml_inner_log );

        // =================================================================
        // STRATEGIA DI CHIAMATA (Ref: MU162 §5.4)
        //
        // PROBLEMA PRINCIPALE RISOLTO IN v1.3.2:
        // La documentazione mostra <DeleteSped> come root element dell'XML,
        // ma l'endpoint .asmx/DeleteSped via HTTP POST wrappa GIÀ il
        // parametro nella struttura del metodo. Se anche il valore XML
        // contiene <DeleteSped> come root, il server vede:
        //   <DeleteSped><DeleteSped><SedeGls>...</DeleteSped></DeleteSped>
        // e cerca <SedeGls> al primo livello, trovando <DeleteSped>.
        // Risultato: "Sigla sede non specificata."
        //
        // Per AddParcel e CloseWorkDay il root element è <Info> (diverso
        // dal nome del metodo), quindi non c'è conflitto. Per DeleteSped
        // il root MOSTRATO nella doc è uguale al nome metodo → conflitto.
        //
        // Proviamo 2 varianti di root element:
        //   Variante A: <Info>...</Info>   (come AddParcel/CloseWorkDay)
        //   Variante B: <DeleteSped>...</DeleteSped> (come da doc MU162 §5.4)
        //
        // Per ogni variante, proviamo i nomi parametro HTTP POST probabili.
        // =================================================================

        $xml_variants = array(
            // Variante A: root <Info> — coerente con AddParcel/CloseWorkDay
            'Info'       => '<Info>' . $xml_inner . '</Info>',
            // Variante B: root <DeleteSped> — come mostrato nella doc MU162 §5.4
            'DeleteSped' => '<DeleteSped>' . $xml_inner . '</DeleteSped>',
        );

        // Nomi parametro da tentare per ogni variante XML
        $param_names = array( 'XMLInfoSped', 'XMLInfo' );

        $http_post_success = false;
        $http_code         = 0;
        $body              = '';
        $found_sede_error  = false;

        foreach ( $xml_variants as $variant_name => $xml ) {
            foreach ( $param_names as $param_name ) {
                $response = wp_remote_post( $this->api_url_deletesped, array(
                    'method'  => 'POST',
                    'timeout' => 30,
                    'body'    => array( $param_name => $xml ),
                ) );

                if ( is_wp_error( $response ) ) {
                    error_log( 'GLS DeleteSped network error variant="' . $variant_name . '" param="' . $param_name . '" order #' . $order_id . ': ' . $response->get_error_message() );
                    continue;
                }

                $http_code = wp_remote_retrieve_response_code( $response );
                $body      = wp_remote_retrieve_body( $response );

                error_log( 'GLS DeleteSped variant="' . $variant_name . '" param="' . $param_name . '" HTTP ' . $http_code . ' order #' . $order_id . ': ' . substr( $body, 0, 400 ) );

                // HTTP 500 = parametro POST errato, proviamo il successivo
                if ( $http_code === 500 ) {
                    continue;
                }

                // HTTP 200 ma con errore "Sigla sede non specificata" = XML root errato.
                // Il server ha ricevuto la richiesta ma non trova SedeGls al livello atteso.
                // Proviamo la variante XML successiva con lo STESSO parametro funzionante.
                if ( $http_code === 200 ) {
                    $body_text = $this->extract_asmx_response( $body );
                    $body_lower = strtolower( $body_text );

                    if ( strpos( $body_lower, 'sede non specificata' ) !== false
                      || strpos( $body_lower, 'sede non valida' ) !== false ) {
                        error_log( 'GLS DeleteSped: variante "' . $variant_name . '" respinta per XML root errato. Provo la prossima variante.' );
                        $found_sede_error = true;
                        // Interrompi il loop parametri, passa alla prossima variante XML
                        break;
                    }

                    // Risposta OK o altro errore GLS gestibile → successo della chiamata
                    $http_post_success = true;
                    break 2; // Esce da entrambi i loop
                }

                // Qualsiasi altro HTTP code (4xx, ecc.) → usciamo con questo risultato
                $http_post_success = true;
                break 2;
            }
        }

        // --- LIVELLO 2: SOAP 1.1 fallback ---
        // Se nessuna combinazione HTTP POST ha funzionato, proviamo SOAP.
        if ( ! $http_post_success ) {
            error_log( 'GLS DeleteSped: tutti i tentativi HTTP POST falliti. Provo SOAP 1.1...' );

            // Prova SOAP con entrambe le varianti XML
            foreach ( $xml_variants as $variant_name => $xml ) {
                $soap_result = $this->delete_sped_soap( $xml, $order_id );

                if ( $soap_result !== false ) {
                    $http_code = $soap_result['http_code'];
                    $body      = $soap_result['body'];

                    error_log( 'GLS DeleteSped SOAP variant="' . $variant_name . '" HTTP ' . $http_code . ' order #' . $order_id . ': ' . substr( $body, 0, 300 ) );

                    if ( $http_code === 200 ) {
                        $body_lower = strtolower( $body );
                        if ( strpos( $body_lower, 'sede non specificata' ) === false ) {
                            $http_post_success = true;
                            break;
                        }
                    }
                }
            }
        }

        // --- Gestione errori di rete (nessuna risposta utilizzabile) ---
        if ( ! $http_post_success && $http_code === 0 ) {
            $order->add_order_note(
                'GLS Error di rete durante la cancellazione spedizione ' . $tracking . '. '
                . 'Nessun tentativo ha avuto successo. Contatta manualmente la sede GLS.'
            );
            return;
        }

        // --- Gestione HTTP 500 persistente (anche SOAP fallito) ---
        if ( $http_code === 500 ) {
            $order->add_order_note(
                '⚠️ GLS: Il server ha restituito HTTP 500 durante la cancellazione della spedizione ' . $tracking . '. '
                . 'Tutti i metodi di chiamata (HTTP POST e SOAP) hanno fallito. '
                . 'Possibili cause: (1) la funzione DeleteSped non è abilitata per questo account GLS, '
                . '(2) l\'ambiente di test GLS non espone il metodo DeleteSped. '
                . 'Contatta la sede GLS per procedere manualmente alla cancellazione. '
                . 'Il tracking è stato rimosso dall\'ordine.'
            );
            $this->delete_order_meta( $order, array( '_gls_tracking_number', 'gls_tracking_number', '_gls_label_pdf_url' ) );
            error_log( 'GLS DeleteSped HTTP 500 persistente order #' . $order_id . ': ' . substr( $body, 0, 500 ) );
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
     * Esegue la chiamata DeleteSped tramite SOAP 1.1.
     *
     * Fallback quando HTTP POST form-encoded restituisce 500 per tutti
     * i nomi parametro tentati. SOAP non dipende dal nome parametro HTTP
     * perché la struttura è definita dal WSDL del servizio.
     *
     * @param string $xml      XML DeleteSped da inviare
     * @param int    $order_id ID ordine per logging
     * @return array|false Array con 'http_code' e 'body', o false in caso di errore rete
     */
    private function delete_sped_soap( $xml, $order_id ) {
        $soap_url    = 'https://labelservice.gls-italy.com/ilswebservice.asmx';
        $soap_action = 'https://labelservice.gls-italy.com/DeleteSped';

        // Nomi parametro da provare nella busta SOAP
        $soap_param_names = array( 'XMLInfoSped', 'XMLInfo' );

        foreach ( $soap_param_names as $soap_param ) {
            // L'XML va inserito come stringa testo (entità HTML escaped) dentro il parametro SOAP.
            $xml_escaped = htmlspecialchars( $xml, ENT_XML1, 'UTF-8' );

            $soap_envelope  = '<?xml version="1.0" encoding="utf-8"?>';
            $soap_envelope .= '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ';
            $soap_envelope .= 'xmlns:xsd="http://www.w3.org/2001/XMLSchema" ';
            $soap_envelope .= 'xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">';
            $soap_envelope .= '<soap:Body>';
            $soap_envelope .= '<DeleteSped xmlns="https://labelservice.gls-italy.com/">';
            $soap_envelope .= '<' . $soap_param . '>' . $xml_escaped . '</' . $soap_param . '>';
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
                error_log( 'GLS DeleteSped SOAP network error param="' . $soap_param . '" order #' . $order_id . ': ' . $response->get_error_message() );
                continue;
            }

            $http_code = wp_remote_retrieve_response_code( $response );
            $body      = wp_remote_retrieve_body( $response );

            error_log( 'GLS DeleteSped SOAP param="' . $soap_param . '" HTTP ' . $http_code . ' order #' . $order_id . ': ' . substr( $body, 0, 400 ) );

            if ( $http_code !== 500 ) {
                $extracted = $this->extract_soap_response( $body );
                return array(
                    'http_code' => $http_code,
                    'body'      => $extracted,
                );
            }
        }

        return false;
    }

    /**
     * Estrae il contenuto utile da una risposta SOAP.
     *
     * @param string $soap_response Risposta SOAP completa
     * @return string Contenuto estratto, o la risposta originale se non parsabile
     */
    private function extract_soap_response( $soap_response ) {
        // Prova a trovare il tag *Result dentro la risposta
        if ( preg_match( '/<\w*Result[^>]*>(.*?)<\/\w*Result>/s', $soap_response, $matches ) ) {
            $result = $matches[1];
            if ( strpos( $result, '&lt;' ) !== false ) {
                $result = html_entity_decode( $result, ENT_QUOTES, 'UTF-8' );
            }
            return $result;
        }

        // Prova a trovare un <soap:Fault> per errori SOAP
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

            // Caso 1: Wrappato in <string xmlns="http://tempuri.org/">
            if ( $root_name === 'string' ) {
                $inner = (string) $wrapper;
                if ( ! empty( $inner ) && strpos( $inner, '<' ) !== false ) {
                    return $inner;
                }
            }

            // Caso 2: Root è già InfoLabel, Info o simile
            if ( in_array( $root_name, array( 'InfoLabel', 'Info' ), true ) || isset( $wrapper->Parcel ) ) {
                return $raw_response;
            }
        }

        return $raw_response;
    }

    // ========================================================================
    // CLOSEWORKDAY — Chiusura giornaliera tramite CloseWorkDayByShipmentNumber
    // Ref: MU162 Label Service v30, sezione 5.3
    //
    // NOTA v1.3.3: Cambiato da CloseWorkDay (§5.2) a CWDBSN (§5.3).
    // CloseWorkDay richiede di ritrasmettere TUTTI i dati destinatario per ogni
    // collo (RagioneSociale, Indirizzo, Localita, Zipcode, Provincia, ecc.),
    // rendendo la chiamata complessa e soggetta a errori di sincronizzazione.
    //
    // CWDBSN accetta direttamente i numeri di spedizione GLS già assegnati
    // durante AddParcel. Il plugin li ha già salvati nei meta ordine.
    //
    // Flusso:
    //   1. Query degli ordini WC con _gls_tracking_number valorizzato
    //      e _gls_cwd_closed NON presente (= non ancora confermati)
    //   2. Costruzione XML con tag <NumeroDiSpedizioneGLSDaConfermare>
    //      per ogni spedizione trovata
    //   3. Invio a CloseWorkDayByShipmentNumber
    //   4. Parsing risposta: per ogni <Parcel>/<esito>, logga e marca come chiuso
    // ========================================================================

    /**
     * Schedula il cron WordPress per eseguire la CloseWorkDay ogni giorno alle 19:00.
     * Questo serve come fallback nel caso in cui il cronjob server-side non sia configurato.
     * Con un vero cronjob server-side (es. Hostinger), wp-cron funge da rete di sicurezza.
     */
    public function schedule_cron() {
        if ( ! wp_next_scheduled( 'gls_daily_close_work_day' ) ) {
            // Calcola il prossimo orario 19:00 nel fuso orario del server
            $timestamp = strtotime( 'today 19:00' );
            if ( $timestamp < time() ) {
                $timestamp = strtotime( 'tomorrow 19:00' );
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
     * Gestisce le richieste in arrivo all'endpoint cronjob server-side.
     *
     * Intercetta le richieste GET con ?gls_cron_action=close_work_day&token=XXX
     * e, dopo aver verificato il token, esegue la CloseWorkDay.
     *
     * Questo endpoint è progettato per essere chiamato da un cronjob di sistema
     * (Hostinger, cPanel, ecc.) tramite curl o wget, senza dipendere dal wp-cron.
     *
     * Sicurezza:
     *   - Il token segreto (32 caratteri alfanumerici) impedisce chiamate non autorizzate
     *   - Il confronto usa hash_equals per prevenire timing attacks
     *   - La risposta è solo testo plain (nessun HTML) per minimizzare il carico
     */
    public function handle_cron_endpoint() {
        // Verifica se la richiesta è diretta all'endpoint cronjob GLS
        if ( ! isset( $_GET['gls_cron_action'] ) || $_GET['gls_cron_action'] !== 'close_work_day' ) {
            return; // Non è una richiesta per noi, lascia proseguire WordPress normalmente
        }

        // Verifica il token di sicurezza
        $expected_token = get_option( 'gls_cron_secret_token', '' );
        $provided_token = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';

        if ( empty( $expected_token ) || ! hash_equals( $expected_token, $provided_token ) ) {
            // Token mancante o non valido — risposta 403 senza rivelare dettagli
            status_header( 403 );
            header( 'Content-Type: text/plain; charset=utf-8' );
            echo 'Forbidden';
            exit;
        }

        // Verifica che WooCommerce sia completamente caricato
        if ( ! function_exists( 'wc_get_orders' ) || ! class_exists( 'WC_Order' ) ) {
            status_header( 503 );
            header( 'Content-Type: text/plain; charset=utf-8' );
            echo 'ERROR - WooCommerce not loaded';
            error_log( 'GLS Cron Endpoint: WooCommerce non ancora caricato.' );
            exit;
        }

        // Token valido — esegui CloseWorkDay con protezione errori
        error_log( 'GLS Cron Endpoint: richiesta ricevuta, esecuzione CloseWorkDay...' );

        try {
            $this->execute_close_work_day();
            $message = 'OK - CloseWorkDay executed at ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
            error_log( 'GLS Cron Endpoint: ' . $message );
        } catch ( \Exception $e ) {
            $message = 'ERROR - ' . $e->getMessage();
            error_log( 'GLS Cron Endpoint EXCEPTION: ' . $e->getMessage() );
        } catch ( \Throwable $t ) {
            $message = 'FATAL - ' . $t->getMessage();
            error_log( 'GLS Cron Endpoint FATAL: ' . $t->getMessage() . ' in ' . $t->getFile() . ':' . $t->getLine() );
        }

        // Risposta al cronjob
        status_header( 200 );
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo $message;
        exit;
    }

    /**
     * Esegue la chiamata CloseWorkDayByShipmentNumber all'API GLS.
     *
     * Cerca tutti gli ordini WooCommerce con tracking GLS non ancora confermati
     * (meta _gls_tracking_number presente, meta _gls_cwd_closed assente)
     * e li invia a GLS per la convalida.
     *
     * Ref: MU162 Label Service v30, sezione 5.3 (CloseWorkDayByShipmentNumber)
     *
     * La struttura XML inviata è:
     *   <Info>
     *     <SedeGls>XX</SedeGls>
     *     <CodiceClienteGls>XXXXX</CodiceClienteGls>
     *     <PasswordClienteGls>XXXXX</PasswordClienteGls>
     *     <CloseWorkDayResult>S</CloseWorkDayResult>
     *     <Parcel>
     *       <CodiceContrattoGls>XXXX</CodiceContrattoGls>
     *       <NumeroDiSpedizioneGLSDaConfermare>590000008</NumeroDiSpedizioneGLSDaConfermare>
     *     </Parcel>
     *     <Parcel>
     *       <CodiceContrattoGls>XXXX</CodiceContrattoGls>
     *       <NumeroDiSpedizioneGLSDaConfermare>590000011</NumeroDiSpedizioneGLSDaConfermare>
     *     </Parcel>
     *   </Info>
     */
    public function execute_close_work_day() {
        // --- Validazione credenziali ---
        $sede      = trim( get_option( 'gls_sede' ) );
        $cliente   = trim( get_option( 'gls_codice_cliente' ) );
        $password  = trim( get_option( 'gls_password' ) );
        $contratto = trim( get_option( 'gls_codice_contratto' ) );

        if ( empty( $sede ) || empty( $cliente ) || empty( $password ) || empty( $contratto ) ) {
            error_log( 'GLS CWD Error: Credenziali mancanti per CloseWorkDay.' );
            return;
        }

        // --- Cerca gli ordini con tracking GLS non ancora confermati ---
        // NOTA v1.3.3-fix: la meta_query con NOT EXISTS + AND relation causa un
        // errore fatale con HPOS attivo (OrdersTableQuery non supporta pienamente
        // NOT EXISTS in compound queries). Soluzione: query semplice con meta_key
        // per trovare ordini con tracking, poi filtro in PHP per escludere i chiusi.
        $all_orders_with_tracking = wc_get_orders( array(
            'status'   => array( 'processing', 'completed' ),
            'limit'    => 200, // Limite di sicurezza — normalmente le spedizioni giornaliere sono molte meno
            'meta_key' => '_gls_tracking_number',
        ) );

        // Filtra in PHP: mantieni solo gli ordini NON ancora confermati via CWD
        $orders = array();
        foreach ( $all_orders_with_tracking as $order ) {
            $cwd_closed = $order->get_meta( '_gls_cwd_closed', true );
            if ( empty( $cwd_closed ) ) {
                $orders[] = $order;
            }
        }

        error_log( 'GLS CWD: Trovati ' . count( $all_orders_with_tracking ) . ' ordini con tracking, di cui ' . count( $orders ) . ' in attesa di conferma.' );

        // --- Nessun ordine da confermare ---
        if ( empty( $orders ) ) {
            error_log( 'GLS CWD: Nessuna spedizione in attesa di conferma. CloseWorkDay non necessario.' );
            return;
        }

        // --- Costruzione XML per CWDBSN ---
        // Mappa order_id => tracking_number per il parsing della risposta
        $shipments_map = array();

        $xml  = '<Info>';
        $xml .= '<SedeGls>' . esc_html( $sede ) . '</SedeGls>';
        $xml .= '<CodiceClienteGls>' . esc_html( $cliente ) . '</CodiceClienteGls>';
        $xml .= '<PasswordClienteGls>' . esc_html( $password ) . '</PasswordClienteGls>';

        // CloseWorkDayResult = "S" per ricevere lo stato delle spedizioni nella risposta
        // Ref: MU162bis - "if tag value = 'S' an XML with shipment information is returned"
        $xml .= '<CloseWorkDayResult>S</CloseWorkDayResult>';

        foreach ( $orders as $order ) {
            $tracking = $order->get_meta( '_gls_tracking_number', true );
            if ( empty( $tracking ) ) {
                continue; // Sicurezza: non dovrebbe succedere dato il meta_query, ma verifichiamo
            }

            $xml .= '<Parcel>';
            // CodiceContrattoGls è OBBLIGATORIO anche in CWDBSN (Ref: MU162bis Data Mapping)
            $xml .= '<CodiceContrattoGls>' . esc_html( $contratto ) . '</CodiceContrattoGls>';
            // Il numero di spedizione GLS da confermare (Ref: MU162 §5.3)
            $xml .= '<NumeroDiSpedizioneGLSDaConfermare>' . esc_html( $tracking ) . '</NumeroDiSpedizioneGLSDaConfermare>';
            $xml .= '</Parcel>';

            $shipments_map[ $tracking ] = $order->get_id();
        }

        $xml .= '</Info>';

        $tracking_count = count( $shipments_map );
        $tracking_list  = implode( ', ', array_keys( $shipments_map ) );
        error_log( 'GLS CWD: Invio CWDBSN con ' . $tracking_count . ' spedizioni: ' . $tracking_list );

        // --- Invio richiesta HTTP POST ---
        // Il parametro HTTP per CWDBSN è "XMLInfo" (come CloseWorkDay standard)
        $response = wp_remote_post( $this->api_url_cwdbsn, array(
            'method'  => 'POST',
            'timeout' => 60,
            'body'    => array( 'XMLInfo' => $xml ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'GLS CWD Error di rete: ' . $response->get_error_message() );
            return;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );

        error_log( 'GLS CWD HTTP ' . $http_code . ' - Risposta: ' . substr( $body, 0, 1000 ) );

        if ( $http_code != 200 ) {
            error_log( 'GLS CWD Error: HTTP ' . $http_code );
            return;
        }

        // --- Parsing della risposta CWDBSN ---
        // La risposta è:
        //   <CloseWorkDayByShipmentNumberResult>
        //     <DescrizioneErrore>OK</DescrizioneErrore>
        //     <Parcel>
        //       <NumeroDiSpedizioneGLSDaConfermare>590000008</NumeroDiSpedizioneGLSDaConfermare>
        //       <esito>OK</esito>
        //     </Parcel>
        //     ...
        //   </CloseWorkDayByShipmentNumberResult>
        $inner = $this->extract_asmx_response( $body );
        $xml_resp = @simplexml_load_string( $inner );

        if ( $xml_resp === false ) {
            error_log( 'GLS CWD: Risposta XML non parsabile: ' . substr( $inner, 0, 500 ) );
            // Anche se non riusciamo a parsare la risposta, marchiamo come chiusi
            // per evitare invii duplicati. L'operatore può verificare manualmente.
            $this->mark_orders_as_closed( $shipments_map );
            return;
        }

        // Controlla errore globale
        if ( isset( $xml_resp->DescrizioneErrore ) ) {
            $global_error = trim( (string) $xml_resp->DescrizioneErrore );
            error_log( 'GLS CWD esito globale: ' . $global_error );

            // Se l'errore globale non è "OK", logga ma NON interrompe
            // perché i singoli Parcel possono avere esiti diversi
        }

        // Processa ogni Parcel nella risposta
        $success_count = 0;
        $error_count   = 0;

        if ( isset( $xml_resp->Parcel ) ) {
            foreach ( $xml_resp->Parcel as $parcel ) {
                $num_sped = isset( $parcel->NumeroDiSpedizioneGLSDaConfermare )
                    ? trim( (string) $parcel->NumeroDiSpedizioneGLSDaConfermare )
                    : '';
                $esito = isset( $parcel->esito )
                    ? trim( (string) $parcel->esito )
                    : 'N/A';

                if ( strtoupper( $esito ) === 'OK' ) {
                    $success_count++;
                    error_log( 'GLS CWD: Spedizione ' . $num_sped . ' confermata con successo.' );

                    // Marca l'ordine come chiuso
                    if ( ! empty( $num_sped ) && isset( $shipments_map[ $num_sped ] ) ) {
                        $order = wc_get_order( $shipments_map[ $num_sped ] );
                        if ( $order ) {
                            $this->update_order_meta( $order, array(
                                '_gls_cwd_closed' => gmdate( 'Y-m-d H:i:s' ),
                            ) );
                            $order->add_order_note( '✅ Spedizione GLS ' . $num_sped . ' confermata alla sede GLS (CloseWorkDay).' );
                        }
                        unset( $shipments_map[ $num_sped ] );
                    }
                } else {
                    $error_count++;
                    error_log( 'GLS CWD: Spedizione ' . $num_sped . ' — esito: ' . $esito );

                    // Anche se l'esito non è OK, annotiamo l'errore sull'ordine
                    if ( ! empty( $num_sped ) && isset( $shipments_map[ $num_sped ] ) ) {
                        $order = wc_get_order( $shipments_map[ $num_sped ] );
                        if ( $order ) {
                            $order->add_order_note( '⚠️ GLS CWD: Spedizione ' . $num_sped . ' — esito: ' . esc_html( $esito ) );
                        }
                    }
                }
            }
        }

        // Marca come chiusi gli ordini rimasti nella mappa (non presenti nella risposta)
        // Questo può succedere se GLS ha già processato la spedizione in precedenza.
        if ( ! empty( $shipments_map ) ) {
            $this->mark_orders_as_closed( $shipments_map );
        }

        error_log( 'GLS CWD completato: ' . $success_count . ' confermati, ' . $error_count . ' con errore.' );
    }

    /**
     * Marca un gruppo di ordini come confermati (CWD closed) nei metadati.
     * Usato come fallback quando la risposta non è parsabile ma la chiamata
     * è andata a buon fine (HTTP 200).
     *
     * @param array $shipments_map Array tracking_number => order_id
     */
    private function mark_orders_as_closed( $shipments_map ) {
        foreach ( $shipments_map as $tracking => $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $this->update_order_meta( $order, array(
                    '_gls_cwd_closed' => gmdate( 'Y-m-d H:i:s' ),
                ) );
            }
        }
    }
}

// ============================================================================
// HANDLER PER CLOSEWORKDAY MANUALE (via admin-post.php)
// ============================================================================
add_action( 'admin_post_gls_manual_close_work_day', 'gls_manual_cwd_handler' );
function gls_manual_cwd_handler() {
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
// INTEGRAZIONE YAYMAIL PRO — Shortcode custom per il tracking GLS
//
// YayMail Pro NON processa shortcode WordPress standard né i filtri
// yaymail_custom_variables / yaymail_custom_variable_value (che non esistono).
//
// Il filtro corretto è: yaymail_customs_shortcode (con la "s")
// Gli shortcode DEVONO avere il prefisso: [yaymail_custom_shortcode_*]
//
// Ref: https://docs.yaycommerce.com/yaymail/drag-and-drop-email-builder/
//      upper-area-of-the-editor-screen/custom-shortcode
//
// Shortcode registrati:
//   [yaymail_custom_shortcode_gls_tracking]      — codice tracking testuale
//   [yaymail_custom_shortcode_gls_tracking_link]  — bottone HTML cliccabile GLS
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

                // Province di Calabria e Sicilia
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