<?php
/**
 * Plugin Name: MetaDefender Core FileSecurity Integration Plugin
 * Description: Un plugin per validare i file prima del caricamento.
 * Version: 1.0
 * Author: Emilio Tonelli
 * License: GPL2
 */


// Inserisci questo codice nel file functions.php del tuo tema child
// Oppure in un file PHP per un plugin personalizzato (es. my-uploader/my-uploader.php)

function my_simple_file_upload_form_shortcode() {
    ob_start(); // Inizia il buffering dell'output per catturare l'HTML

    $message = ''; // Variabile per mostrare messaggi all'utente

    // Imposta la directory di destinazione per i file caricati
    $upload_dir_info = wp_upload_dir(); // Ottiene le info sulla directory di upload di WordPress
    $target_dir = $upload_dir_info['basedir'] . '/custom_uploads/'; // Percorso completo per la tua sottocartella

    // Crea la directory se non esiste, con permessi 0755
    if (!file_exists($target_dir)) {
        wp_mkdir_p($target_dir); // Funzione di WordPress per creare directory ricorsivamente
    }

    // Gestione del caricamento del file quando il form viene inviato e conseguenti controlli OPSWAT
    if (isset($_POST['submit_file_upload'])) {
        // Verifica che un file sia stato effettivamente selezionato e che non ci siano errori iniziali
        if (isset($_FILES['fileToUpload']) && $_FILES['fileToUpload']['error'] == UPLOAD_ERR_OK) {

            $file_name = sanitize_file_name($_FILES['fileToUpload']['name']); // Sanitizza il nome del file per sicurezza
            $target_file_path = $target_dir . $file_name;
            $upload_ok = 1;
            $file_type = strtolower(pathinfo($target_file_path, PATHINFO_EXTENSION));

            // --- Controlli di Sicurezza che includono OPSWAT MD-CORE ---

            // 1. Controlla la dimensione del file (es. max 10MB)
            if ($_FILES['fileToUpload']['size'] > 10 * 1024 * 1024) { // 5 MB in bytes
                $message = '<p style="color: red;">NO NO Spiacente, il file è troppo grande. Dimensione massima 10MB.</p>';
                $upload_ok = 0;
            }

            // 2. Permetti solo certi formati di file (IMPORTANTE per la sicurezza!)
            $allowed_file_types = array('jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip');
            if (!in_array($file_type, $allowed_file_types)) {
                $message = '<p style="color: red;">NO NO Spiacente, solo i file IMMAGINI e PDF sono permessi.</p>';
                $upload_ok = 0;
            }

            /* 3. Controlla se il file esiste già (opzionale: potresti volerlo sovrascrivere o rinominare)
            if (file_exists($target_file_path)) {
                $message .= '<p style="color: orange;">EHI Attenzione: Un file con lo stesso nome esiste già. Verrà sovrascritto.</p>';
                $upload_ok = 0; // Se non vuoi sovrascrivere, imposta $upload_ok = 0; qui.
            }*/

            // 4. E ADESSO... OPSWAT MetaDefender Core!
            // URL dell'API che vogliamo interrogare
            $api_url = 'http://cby.webhop.me:8008/file/sync';
            // Inizializza una nuova sessione cURL
            $ch = curl_init();
            // 1. Imposta l'URL a cui inviare la richiesta in modo che coincida con tutti i valori necessari ad MDCORE
            curl_setopt($ch, CURLOPT_URL, $api_url);
            // 2. Imposta l'opzione per restituire il trasferimento come stringa
            // Questo è importante per ottenere il contenuto della risposta in una variabile
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            $file_content = file_get_contents($_FILES['fileToUpload']['tmp_name']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $file_content);
            // Gestiamo gli header necessari alle API di OPSWAT MDCORE
            $headers = [
                'User-Agent: OPSWAT MDCORE Plugin Wordpress',
                'apikey: YOURKEY',
                'rule: YOUR WORKFLOW NAME',
                "filename: {$_FILES['fileToUpload']['name']}"
            ];
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // ESEGUI la richiesta di analisi MetaDefender cURL e ottieni la risposta
            $response = curl_exec($ch);
            // Ottiene informazioni sulla richiesta e la risposta
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Codice di stato HTTP
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $jsonData = json_decode($response, true); // true per array associativo
                $verdetto = $jsonData['process_info']['result'];

                if ($verdetto == "Allowed"){
                    $upload_ok = 1;
                } else {
                    $upload_ok = 0;
                }

            } else {
                $upload_ok = 0;
            }

            // Se tutti i controlli sono passati, tenta di spostare il file
            if ($upload_ok == 1) {
                if (move_uploaded_file($_FILES['fileToUpload']['tmp_name'], $target_file_path)) {
                    $message = '<p style="color: green;">"'. $verdetto . '" Il file "' . esc_html($file_name) . '" è stato caricato con successo!</p>';
                } else {
                    $message = '<p style="color: red;">ERRORE SICUREZZA!<br/>Verdetto OPSWAT "'. $verdetto .'"</p>';
                }
            } else {
                $message = '<p style="color: red;">ERRORE SICUREZZA!<br/>Verdetto OPSWAT "'. $verdetto .'"</p>';
            }


        } else {
            // Gestione degli errori di caricamento PHP (es. limite php.ini superato, nessun file selezionato)
            switch ($_FILES['fileToUpload']['error']) {
                case UPLOAD_ERR_NO_FILE:
                    $message = '<p style="color: red;">Errore: Nessun file selezionato per il caricamento.</p>';
                    break;
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $message = '<p style="color: red;">Errore: Il file supera la dimensione massima consentita dal server.</p>';
                    break;
                default:
                    $message = '<p style="color: red;">Si è verificato un errore sconosciuto durante il caricamento del file.</p>';
                    break;
            }
        }
    }
    ?>

    <div style="max-width: 500px; margin: 20px auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">

        <form action="" method="post" enctype="multipart/form-data">
            Seleziona il file da caricare:
            <input type="file" name="fileToUpload" id="fileToUpload" style="margin-bottom: 10px;"><br>
            <input type="submit" value="Carica File" name="submit_file_upload" style="padding: 10px 15px; background-color: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer;">
            <br/><br/>
        </form>

         <div class="message-box">
             <p><?php echo $message; // Mostra i messaggi di stato ?></p>
         </div>

    </div>

    <?php
    return ob_get_clean(); // Restituisce l'output HTML catturato
}
add_shortcode('my_file_uploader', 'my_simple_file_upload_form_shortcode');
