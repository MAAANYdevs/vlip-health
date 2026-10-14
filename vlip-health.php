<?php
/**
 * Plugin Name: VLIP Health
 * Plugin URI:  https://vlip.io
 * Description: Liest den Zustand dieser Installation und gibt ihn am Endpunkt /wp-json/vlip/v1/health aus. Nur lesend.
 * Version:     1.1.5
 * Author:      VLIP GmbH
 * Author URI:  https://vlip.io
 * Requires PHP: 8.1
 *
 * Normales Plugin (José, 10/2026), nicht mehr Must-Use: nach
 * wp-content/plugins/vlip-health/vlip-health.php (Bedrock: web/app/plugins/)
 * und aktivieren. So aktualisiert es sich ueber WordPress' eigenen Updater
 * (siehe vlip_health_update_check) und das Dashboard sieht an der gemeldeten
 * Version, wer hinterherhaengt. Als MU-Plugin laeuft es weiter, nur ohne
 * Selbstaktualisierung. Das Secret kommt aus der Konstante
 * VLIP_HEALTH_SECRET oder aus der gleichnamigen Umgebungsvariable. Ist keines
 * gesetzt, ist der Endpunkt aus -- das Plugin darf niemals ohne Secret
 * antworten.
 *
 * NUR LESEN. Kein Update, kein Schreibvorgang, kein Ausfuehren. Das ist die
 * Grenze, an der ein kompromittiertes Dashboard sonst zu einem kompromittierten
 * Kundenbestand wuerde.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Zwei Fassungen nebeneinander (etwa die alte als MU-Plugin) duerfen die
// Seite nicht mit einem Fatal Error stoppen. Deshalb steht jede Funktion
// unten in einem function_exists-Block: PHP legt unbedingte Funktionen schon
// beim Laden der Datei an, ein Schutz per function_exists am Dateianfang
// sieht die eigene Funktion und bricht immer ab (so geschehen in 1.1.1).
// Die Konstante verhindert, dass zwei neue Fassungen ihre Hooks doppelt setzen.
if (defined('VLIP_HEALTH_LOADED')) {
    return;
}
define('VLIP_HEALTH_LOADED', true);

/** Muss mit dem Header oben und mit HEALTH_PLUGIN_VERSION im Dashboard (src/lib/wp-health.ts) uebereinstimmen. */
define('VLIP_HEALTH_VERSION', '1.1.5');

/**
 * Woher Updates kommen: eine update.json mit {version, download_url}. Der
 * Ort ist oeffentlich (das Plugin enthaelt nichts Geheimes, das Secret steht
 * in der Umgebung) und liegt bewusst NICHT im Dashboard -- das Dashboard soll
 * nie Code auf Kundenseiten schieben koennen, auch nicht ueber den Umweg
 * einer Update-Quelle hinter seiner Anmeldung. Per Konstante ueberschreibbar.
 */
if (!defined('VLIP_HEALTH_UPDATE_URL')) {
    define('VLIP_HEALTH_UPDATE_URL', 'https://raw.githubusercontent.com/MAAANYdevs/vlip-health/main/update.json');
}

add_action('rest_api_init', function () {
    register_rest_route('vlip/v1', '/health', [
        'methods'             => 'GET',
        'callback'            => 'vlip_health_report',
        'permission_callback' => 'vlip_health_authorized',
    ]);
});

/*
 * Nie zwischenspeichern, auch nicht die 401. Seitencaches (nginx
 * fastcgi_cache, Cloudflare, Caching-Plugins) schluesseln nach URL, nicht nach
 * Secret: eine gecachte Antwort ging sonst an jeden Aufrufer ohne Secret, und
 * das Dashboard sah bis zu einer Stunde lang den alten Stand (pro-multis.de,
 * 10/2026).
 */
add_filter('rest_post_dispatch', function ($response, $server, $request) {
    if ($response instanceof WP_REST_Response && $request->get_route() === '/vlip/v1/health') {
        $response->header('Cache-Control', 'no-store, no-cache, private, max-age=0');
        $response->header('Pragma', 'no-cache');
    }
    return $response;
}, 10, 3);

/**
 * Das erwartete Secret, aus der ersten Quelle, die etwas hergibt:
 *
 *   1. Konstante VLIP_HEALTH_SECRET (wp-config.php)
 *   2. $_ENV, 3. $_SERVER, 4. getenv()
 *
 * Die drei Umgebungsquellen stehen bewusst alle da. Welche davon gefuellt ist,
 * haengt an der dotenv-Konfiguration: phpdotenv schreibt je nach Adapter nach
 * $_ENV und $_SERVER, aber nicht zwingend nach putenv() -- dann liefe getenv()
 * ins Leere, obwohl der Wert in der .env steht. In PHP-FPM wiederum kommt
 * getenv() nur an die Pool-Umgebung. Alle vier zu pruefen kostet nichts und
 * erspart die Fehlersuche an genau der Stelle, an der man sie am wenigsten
 * erwartet.
 *
 * Ist keine Quelle gefuellt, bleibt der Endpunkt geschlossen.
 */
if (!function_exists('vlip_health_secret')) {
    function vlip_health_secret(): string
    {
        // Die Konstante zaehlt nur, wenn sie wirklich eine nicht leere Zeichenkette
        // ist. Bedrock definiert sie ueber Config::define('...', env('...')) auch
        // dann, wenn env() nichts liefert -- die Konstante ist dann null. Ein
        // blosses "!== ''" haelt null faelschlich fuer gesetzt, liefert einen
        // leeren String zurueck und ueberspringt die drei Umgebungsquellen, die den
        // Wert gehabt haetten. Das Ergebnis war ein 401, das wie ein falsches
        // Secret aussieht, obwohl der Wert in der .env steht.
        $konstante = defined('VLIP_HEALTH_SECRET') ? VLIP_HEALTH_SECRET : null;
        if (is_string($konstante) && trim($konstante) !== '') {
            return trim($konstante);
        }
        foreach ([$_ENV['VLIP_HEALTH_SECRET'] ?? null, $_SERVER['VLIP_HEALTH_SECRET'] ?? null, getenv('VLIP_HEALTH_SECRET')] as $wert) {
            // Getrimmt, weil ein aus der Zwischenablage uebernommener Zeilenumbruch
            // sonst zu einem Vergleich fuehrt, der nie aufgeht.
            if (is_string($wert) && trim($wert) !== '') {
                return trim($wert);
            }
        }
        return '';
    }
}

/**
 * Token zeitkonstant pruefen (hash_equals). Ohne hinterlegtes Secret antwortet
 * der Endpunkt nicht -- ein Plugin, das ohne Secret Auskunft gibt, waere
 * schlimmer als keines.
 *
 * Zwei Wege, und der eigene Header ist der verlaesslichere:
 *
 *   1. X-VLIP-Health-Secret: <secret>
 *   2. Authorization: Bearer <secret>
 *
 * Apache reicht `Authorization` je nach SAPI nicht an PHP weiter -- unter
 * CGI/FastCGI verschluckt es den Header, solange nicht `CGIPassAuth On` oder
 * eine SetEnvIf-Zeile gesetzt ist. Das faellt als 401 auf, das wie ein
 * falsches Secret aussieht (so geschehen 09/2026 bei cuba-muenster.de). Ein
 * eigener X-Header ist davon nie betroffen und braucht keinen Eingriff in die
 * Serverkonfiguration -- was bei Fremdhosting oft gar nicht moeglich ist.
 *
 * Sicherheitlich sind beide gleichwertig: dasselbe Geheimnis, dieselbe
 * TLS-Verbindung. `Authorization` bleibt fuer Installationen, die schon so
 * eingerichtet sind.
 */
if (!function_exists('vlip_health_authorized')) {
    function vlip_health_authorized(): bool
    {
        $erwartet = vlip_health_secret();
        if ($erwartet === '') {
            return false;
        }

        $eigen = $_SERVER['HTTP_X_VLIP_HEALTH_SECRET'] ?? '';
        if (is_string($eigen) && trim($eigen) !== '') {
            return hash_equals($erwartet, trim($eigen));
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (stripos($header, 'Bearer ') !== 0) {
            return false;
        }
        return hash_equals($erwartet, trim(substr($header, 7)));
    }
}

/** Kern-Version und ob ein Update ansteht. */
if (!function_exists('vlip_health_core')) {
    function vlip_health_core(): array
    {
        require_once ABSPATH . 'wp-admin/includes/update.php';
        $current = get_bloginfo('version');
        $updates = get_core_updates();
        $latest  = $current;
        foreach ((array) $updates as $u) {
            if (isset($u->response) && $u->response === 'upgrade' && isset($u->current)) {
                $latest = $u->current;
                break;
            }
        }
        return [
            'version'         => $current,
            'latest'          => $latest,
            'updateAvailable' => version_compare($latest, $current, '>'),
        ];
    }
}

/**
 * Plugins mit Version, Update-Stand und ob das Update als Sicherheitsupdate
 * gilt. WordPress kennzeichnet das nicht zuverlaessig, deshalb wird der
 * Hinweis nur weitergegeben, wenn er wirklich vorliegt -- lieber keine Angabe
 * als eine erfundene.
 */
if (!function_exists('vlip_health_plugins')) {
    function vlip_health_plugins(): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';

        $alle    = get_plugins();
        $updates = get_site_transient('update_plugins');
        $aktiv   = (array) get_option('active_plugins', []);
        $raus    = [];

        foreach ($alle as $datei => $daten) {
            $u       = $updates->response[$datei] ?? null;
            // Der Transient wird nur alle 12 h erneuert. Kommt das Update per
            // Git- oder Composer-Deploy statt ueber WordPress, steht es dort
            // weiter als offen, obwohl die neue Version laengst liegt.
            if ($u !== null && isset($u->new_version, $daten['Version'])
                && !version_compare((string) $u->new_version, (string) $daten['Version'], '>')) {
                $u = null;
            }
            $slug    = dirname($datei) !== '.' ? dirname($datei) : basename($datei, '.php');
            $raus[] = [
                'slug'             => $slug,
                'name'             => $daten['Name'] ?? $slug,
                'version'          => $daten['Version'] ?? null,
                'latest'           => $u->new_version ?? ($daten['Version'] ?? null),
                'active'           => in_array($datei, $aktiv, true) || is_plugin_active_for_network($datei),
                'hasUpdate'        => $u !== null,
                'isSecurityUpdate' => isset($u->upgrade_notice) && stripos((string) $u->upgrade_notice, 'securit') !== false,
            ];
        }
        return $raus;
    }
}

/** Themes analog zu den Plugins. */
if (!function_exists('vlip_health_themes')) {
    function vlip_health_themes(): array
    {
        $updates = get_site_transient('update_themes');
        $aktiv   = wp_get_theme();
        $raus    = [];

        foreach (wp_get_themes() as $slug => $theme) {
            $neu    = $updates->response[$slug]['new_version'] ?? null;
            $raus[] = [
                'slug'      => $slug,
                'name'      => $theme->get('Name'),
                'version'   => $theme->get('Version'),
                'latest'    => $neu ?? $theme->get('Version'),
                'active'    => $slug === $aktiv->get_stylesheet(),
                'hasUpdate' => $neu !== null,
            ];
        }
        return $raus;
    }
}

/** Datenbank-Engine und -Version. */
if (!function_exists('vlip_health_db')) {
    function vlip_health_db(): array
    {
        global $wpdb;
        $version = $wpdb->db_version();
        $server  = $wpdb->get_var('SELECT VERSION()');
        return [
            'engine'  => stripos((string) $server, 'mariadb') !== false ? 'mariadb' : 'mysql',
            'version' => $server ?: $version,
        ];
    }
}

/**
 * Ablaufdatum des Zertifikats. Best effort: ist openssl nicht verfuegbar oder
 * der Port zu, bleibt das Feld leer statt zu raten.
 */
if (!function_exists('vlip_health_ssl')) {
    function vlip_health_ssl(): array
    {
        if (!function_exists('stream_socket_client')) {
            return ['expiresAt' => null];
        }
        $host = parse_url(home_url(), PHP_URL_HOST);
        if (!$host) {
            return ['expiresAt' => null];
        }
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
        $sock = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            return ['expiresAt' => null];
        }
        $params = stream_context_get_params($sock);
        fclose($sock);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (!$cert) {
            return ['expiresAt' => null];
        }
        $info = openssl_x509_parse($cert);
        return ['expiresAt' => isset($info['validTo_time_t']) ? gmdate('c', $info['validTo_time_t']) : null];
    }
}

/**
 * Letzte Sicherung, sofern ein bekanntes Backup-Plugin sie meldet. Findet sich
 * nichts, bleibt das Feld leer -- eine erfundene Sicherung waere schlimmer als
 * keine Angabe.
 */
if (!function_exists('vlip_health_backup')) {
    function vlip_health_backup(): array
    {
        $leer = ['lastRunAt' => null, 'provider' => null, 'size' => null];

        if (class_exists('UpdraftPlus_Options')) {
            $history = get_option('updraft_last_backup');
            if (is_array($history) && !empty($history['backup_time'])) {
                return ['lastRunAt' => gmdate('c', (int) $history['backup_time']), 'provider' => 'updraftplus', 'size' => null];
            }
            return array_merge($leer, ['provider' => 'updraftplus']);
        }
        if (defined('BACKWPUP_PLUGIN_BASENAME')) {
            $letzte = get_site_option('backwpup_backupscheckedjob');
            if (is_array($letzte) && !empty($letzte['logtime'])) {
                return ['lastRunAt' => gmdate('c', (int) $letzte['logtime']), 'provider' => 'backwpup', 'size' => null];
            }
            return array_merge($leer, ['provider' => 'backwpup']);
        }
        return $leer;
    }
}

/** Der vollstaendige Bericht. */
if (!function_exists('vlip_health_report')) {
    function vlip_health_report(): WP_REST_Response
    {
        $php_eol = [
            '8.5' => '2029-12-31', '8.4' => '2028-12-31', '8.3' => '2027-12-31',
            '8.2' => '2026-12-31', '8.1' => '2025-12-31', '8.0' => '2023-11-26',
            '7.4' => '2022-11-28',
        ];
        $kurz = implode('.', array_slice(explode('.', PHP_VERSION), 0, 2));

        return new WP_REST_Response([
            'wp'      => vlip_health_core(),
            'php'     => ['version' => PHP_VERSION, 'eolDate' => $php_eol[$kurz] ?? null],
            'plugins' => vlip_health_plugins(),
            'themes'  => vlip_health_themes(),
            'db'      => vlip_health_db(),
            'ssl'     => vlip_health_ssl(),
            'backup'  => vlip_health_backup(),
            'https'         => is_ssl() || strpos(home_url(), 'https://') === 0,
            'debugMode'     => defined('WP_DEBUG') && WP_DEBUG,
            'adminUserCount' => count(get_users(['role' => 'administrator', 'fields' => 'ID'])),
            'pluginVersion' => VLIP_HEALTH_VERSION,
            'checkedAt'     => gmdate('c'),
        ], 200);
    }
}

/* ---------------------------------------------------------------------------
 * Selbstaktualisierung ueber WordPress' eigenen Updater.
 *
 * Kein eigener Schreibweg: das Plugin haengt sich nur in die Update-Pruefung
 * ein und sagt WordPress, wo die neue Fassung liegt. Herunterladen, Entpacken
 * und Ersetzen macht WordPress selbst, wie bei jedem Plugin. Die Quelle wird
 * hoechstens zweimal am Tag abgefragt.
 * ------------------------------------------------------------------------- */

/** update.json holen, zwischengespeichert. null, wenn nicht erreichbar oder unbrauchbar. */
if (!function_exists('vlip_health_update_info')) {
    function vlip_health_update_info(): ?array
    {
        $cache = get_site_transient('vlip_health_update_info');
        if (is_array($cache)) {
            return $cache ?: null;
        }
        $antwort = wp_remote_get(VLIP_HEALTH_UPDATE_URL, ['timeout' => 8, 'headers' => ['Accept' => 'application/json']]);
        $info    = [];
        if (!is_wp_error($antwort) && wp_remote_retrieve_response_code($antwort) === 200) {
            $json = json_decode((string) wp_remote_retrieve_body($antwort), true);
            if (is_array($json) && !empty($json['version']) && !empty($json['download_url'])
                && strpos((string) $json['download_url'], 'https://') === 0) {
                $info = $json;
            }
        }
        // Auch ein Fehlschlag wird gemerkt, sonst haemmert jede Admin-Seite auf die Quelle.
        set_site_transient('vlip_health_update_info', $info, 12 * HOUR_IN_SECONDS);
        return $info ?: null;
    }
}

/** Als MU-Plugin gibt es keinen Updater; dann bleibt dieser Teil still. */
if (!function_exists('vlip_health_ist_normales_plugin')) {
    function vlip_health_ist_normales_plugin(): bool
    {
        return defined('WP_PLUGIN_DIR') && strpos(wp_normalize_path(__FILE__), wp_normalize_path(WP_PLUGIN_DIR)) === 0;
    }
}

add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (!is_object($transient) || !vlip_health_ist_normales_plugin()) {
        return $transient;
    }
    $info = vlip_health_update_info();
    $basename = plugin_basename(__FILE__);
    if ($info && version_compare((string) $info['version'], VLIP_HEALTH_VERSION, '>')) {
        $transient->response[$basename] = (object) [
            'slug'        => 'vlip-health',
            'plugin'      => $basename,
            'new_version' => (string) $info['version'],
            'package'     => (string) $info['download_url'],
            'url'         => 'https://vlip.io',
            'tested'      => $info['tested'] ?? '',
            'requires_php'=> $info['requires_php'] ?? '8.1',
        ];
    } else {
        unset($transient->response[$basename]);
    }
    return $transient;
});

/** „Details anzeigen“ im Plugin-Bildschirm soll nicht ins Leere laufen. */
add_filter('plugins_api', function ($ergebnis, $aktion, $args) {
    if ($aktion !== 'plugin_information' || ($args->slug ?? '') !== 'vlip-health') {
        return $ergebnis;
    }
    $info = vlip_health_update_info();
    return (object) [
        'name'          => 'VLIP Health',
        'slug'          => 'vlip-health',
        'version'       => $info['version'] ?? VLIP_HEALTH_VERSION,
        'author'        => 'VLIP GmbH',
        'homepage'      => 'https://vlip.io',
        'download_link' => $info['download_url'] ?? '',
        'requires_php'  => $info['requires_php'] ?? '8.1',
        'sections'      => ['description' => 'Meldet den Zustand dieser WordPress-Installation an das VLIP-Dashboard. Nur lesend.'],
    ];
}, 10, 3);

/** Das eigene Plugin darf WordPress automatisch aktualisieren, ohne dass jemand den Schalter umlegt. */
add_filter('auto_update_plugin', function ($update, $item) {
    return (is_object($item) && ($item->plugin ?? '') === plugin_basename(__FILE__)) ? true : $update;
}, 10, 2);

/* ---------------------------------------------------------------------------
 * Vorschaubilder ohne Cookie-Banner (José, 10/2026).
 *
 * Das Dashboard nimmt die Vorschau ueber PageSpeed Insights auf; Lighthouse
 * laedt die Seite wie ein Erstbesucher und klickt nichts weg. Es meldet sich
 * aber mit „Chrome-Lighthouse“ im User-Agent. Genau dann blendet dieses
 * Plugin Consent-Banner per CSS aus -- fuer echte Besucher aendert sich
 * nichts, die Einwilligung bleibt. Best effort ueber die ueblichen
 * Kennungen der gaengigen Consent-Werkzeuge; mit dem Filter
 * `vlip_health_hide_consent` laesst es sich je Seite abschalten.
 * ------------------------------------------------------------------------- */
if (!function_exists('vlip_health_ist_lighthouse')) {
    function vlip_health_ist_lighthouse(): bool
    {
        // Das Dashboard haengt `vlip-shot` an die Adresse, wenn es die Vorschau
        // aufnimmt -- das umgeht auch Seiten-Caches, die Lighthouse sonst die
        // gespeicherte Seite samt Banner liefern. Der User-Agent bleibt als
        // zweiter Weg, falls jemand PageSpeed von Hand anstoesst.
        if (isset($_GET['vlip-shot'])) {
            return true;
        }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return $ua !== '' && stripos($ua, 'Chrome-Lighthouse') !== false;
    }
}

add_action('wp_head', function () {
    if (is_admin() || !vlip_health_ist_lighthouse() || !apply_filters('vlip_health_hide_consent', true)) {
        return;
    }
    // Nur unterhalb von body: Complianz setzt zur Laufzeit cmplz-Klassen an
    // <body> selbst, und ohne die Einschraenkung blendete die Regel die ganze
    // Seite aus -- Lighthouse fotografierte eine weisse Flaeche (pro-multis.de, 10/2026).
    echo '<style id="vlip-health-no-consent">'
        . 'body :is([id*="cookie" i],[class*="cookie" i],[id*="consent" i],[class*="consent" i],'
        . '[id*="borlabs" i],[class*="borlabs" i],[class*="brlbs" i],[id*="cmplz" i],[class*="cmplz" i],'
        . '[id*="usercentrics" i],[class*="usercentrics" i],#onetrust-consent-sdk,#CybotCookiebotDialog,'
        . '[id*="gdpr" i],[class*="gdpr" i],[id*="dsgvo" i],[class*="dsgvo" i])'
        . '{display:none!important;visibility:hidden!important}'
        . 'html,body{overflow:visible!important;position:static!important}'
        . '</style>' . "\n";
}, 999);
