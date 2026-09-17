<?php
/**
 * Plugin Name: Six40 Booking System
 * Plugin URI:  https://sixcuarenta640.com/
 * Description: Sistema de citas para Sixcuarenta 640 Barbería (Málaga y Torremolinos).
 * Version:     1.22.0
 * Author:      Katibu
 * Author URI:  https://katibu.es/
 * License:     GPL-2.0+
 * Text Domain: six40-booking
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ──────────────────────────────────────────────────────────────────
define( 'SIX40_VERSION',    '1.22.0' );
define( 'SIX40_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIX40_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SIX40_PLUGIN_FILE', __FILE__ );

// ── Locales ────────────────────────────────────────────────────────────────────
/**
 * Datos de cada local: nombre visible, dirección y enlace de Google Maps.
 * Se usan en el correo de confirmación y en el evento de Google Calendar.
 *
 * @return array [ 'malaga' => [ 'label', 'address', 'maps' ], 'torremolinos' => [...] ]
 */
function six40_locations() {
    return [
        'malaga' => [
            'label'   => 'Málaga',
            'address' => 'Av. San Sebastián, 5, Gamarra, 29010 Málaga',
            'maps'    => 'https://maps.app.goo.gl/GCp7C1E4WhPoqbfA8',
        ],
        'torremolinos' => [
            'label'   => 'Torremolinos',
            'address' => 'Av. Marifé de Triana, 4, Playamar, 29620 Torremolinos',
            'maps'    => 'https://maps.app.goo.gl/PomRAWbud5uCjkvw5',
        ],
    ];
}

// ── Autoload ───────────────────────────────────────────────────────────────────
require_once SIX40_PLUGIN_DIR . 'includes/class-booking-api.php';
require_once SIX40_PLUGIN_DIR . 'includes/class-google-calendar.php';
require_once SIX40_PLUGIN_DIR . 'includes/class-email.php';
require_once SIX40_PLUGIN_DIR . 'includes/class-admin-panel.php';
require_once SIX40_PLUGIN_DIR . 'includes/class-manage.php';
require_once SIX40_PLUGIN_DIR . 'assets/shortcode.php';

// Gestión de cita por el cliente (cancelar/modificar por enlace con token).
Six40_Manage::init();

// Carga configuración local si existe (nunca va a git).
if ( file_exists( SIX40_PLUGIN_DIR . 'six40-config.php' ) ) {
    require_once SIX40_PLUGIN_DIR . 'six40-config.php';
}

// ── Activation / Deactivation ──────────────────────────────────────────────────
register_activation_hook( __FILE__, 'six40_activate' );
register_deactivation_hook( __FILE__, 'six40_deactivate' );

function six40_activate() {
    if ( ! get_option( 'six40_settings' ) ) {
        update_option( 'six40_settings', [
            'supabase_url'                 => '',
            'supabase_key'                 => '',
            'google_client_id'             => '',
            'google_client_secret'         => '',
            'google_calendar_malaga'       => '',
            'google_calendar_torremolinos' => '',
            'email_from'                   => '',
            'email_from_name'              => 'Six40 Barbería',
        ] );
    }
    flush_rewrite_rules();
}

function six40_deactivate() {
    wp_clear_scheduled_hook( 'six40_check_cancellations' );
    flush_rewrite_rules();
}

// ── Cron: comprobación de anulaciones en Google Calendar ─────────────────────────
add_filter( 'cron_schedules', 'six40_cron_intervals' );
function six40_cron_intervals( $schedules ) {
    $schedules['six40_ten_minutes'] = [
        'interval' => 600,
        'display'  => __( 'Cada 10 minutos (Six40)', 'six40-booking' ),
    ];
    return $schedules;
}

add_action( 'six40_check_cancellations', 'six40_cron_check_cancellations' );

function six40_cron_check_cancellations() {
    $api   = new Six40_Booking_API();
    $appts = $api->get_appointments_to_check();
    if ( empty( $appts ) ) {
        return;
    }
    $gc = new Six40_Google_Calendar();
    foreach ( $appts as $a ) {
        $status = $gc->get_event_status( $a['google_calendar_id'] ?? '', $a['google_event_id'] ?? '' );
        if ( 'cancelled' === $status || 'deleted' === $status ) {
            $res = $api->update_appointment_status( $a['id'], 'cancelled' );
            if ( ! is_wp_error( $res ) ) {
                // Sincronizar: borrar el resto de eventos (p. ej. el del local).
                $events = json_decode( $a['google_events'] ?? '', true );
                if ( is_array( $events ) ) {
                    foreach ( $events as $ev ) {
                        if ( ! empty( $ev['calendar_id'] ) && ! empty( $ev['event_id'] ) ) {
                            $gc->delete_event( $ev['calendar_id'], $ev['event_id'] );
                        }
                    }
                }
                ( new Six40_Email() )->send_cancellation( $a );
            }
        }
    }
}

// ── Boot ───────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'six40_init' );

function six40_init() {
    if ( is_admin() ) {
        Six40_Admin_Panel::get_instance();
    }
    // Programa la comprobación de anulaciones si no está programada.
    if ( ! wp_next_scheduled( 'six40_check_cancellations' ) ) {
        wp_schedule_event( time() + 300, 'six40_ten_minutes', 'six40_check_cancellations' );
    }
    add_action( 'wp_enqueue_scripts',              'six40_register_public_assets' );
    add_action( 'wp_ajax_six40_get_services',        'six40_ajax_get_services' );
    add_action( 'wp_ajax_nopriv_six40_get_services', 'six40_ajax_get_services' );
    add_action( 'wp_ajax_six40_get_slots',         'six40_ajax_get_slots' );
    add_action( 'wp_ajax_nopriv_six40_get_slots',  'six40_ajax_get_slots' );
    add_action( 'wp_ajax_six40_get_month_days',        'six40_ajax_get_month_days' );
    add_action( 'wp_ajax_nopriv_six40_get_month_days', 'six40_ajax_get_month_days' );
    add_action( 'wp_ajax_six40_submit_booking',        'six40_ajax_submit_booking' );
    add_action( 'wp_ajax_nopriv_six40_submit_booking', 'six40_ajax_submit_booking' );
}

// ── Public assets ──────────────────────────────────────────────────────────────
// Se registran en wp_enqueue_scripts y se encolan desde el shortcode
// (six40_render_booking_form). Así funcionan siempre, aunque el shortcode viva
// dentro de un page builder como Avada/Fusion Builder, donde has_shortcode() sobre
// $post->post_content no lo detecta de forma fiable.
function six40_register_public_assets() {
    wp_register_style( 'six40-booking', SIX40_PLUGIN_URL . 'public/css/booking.css', [], SIX40_VERSION );
    wp_register_script( 'six40-booking', SIX40_PLUGIN_URL . 'public/js/booking.js', [ 'jquery' ], SIX40_VERSION, true );
    wp_localize_script( 'six40-booking', 'six40Ajax', [
        'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
        'nonce'          => wp_create_nonce( 'six40_booking_nonce' ),
        'uploadsUrl'     => rtrim( (string) ( wp_upload_dir()['baseurl'] ?? '' ), '/' ), // sigue al dominio actual
        'holidays'        => array_values( (array) get_option( 'six40_holidays', [] ) ),
        'closedWeekdays'  => [ 0 ], // 0 = domingo (JS getDay). Sábado abierto.
        'barberVacations' => (object) get_option( 'six40_barber_vacations', [] ), // { barber_id: [ {start,end} ] }
        'strings' => [
            'selectDate' => __( 'Selecciona una fecha', 'six40-booking' ),
            'selectTime' => __( 'Selecciona una hora', 'six40-booking' ),
            'noSlots'    => __( 'No hay horas disponibles para este día', 'six40-booking' ),
            'loading'    => __( 'Cargando…', 'six40-booking' ),
            'success'    => __( '¡Cita confirmada! Revisa tu correo.', 'six40-booking' ),
            'error'      => __( 'Ha ocurrido un error. Inténtalo de nuevo.', 'six40-booking' ),
        ],
    ] );
}

// Encola los assets ya registrados. Se llama desde el shortcode.
function six40_enqueue_public_assets() {
    // Si el shortcode se procesa antes/fuera de wp_enqueue_scripts, garantizamos
    // el registro on-demand.
    if ( ! wp_style_is( 'six40-booking', 'registered' ) ) {
        six40_register_public_assets();
    }
    wp_enqueue_style( 'six40-booking' );
    wp_enqueue_script( 'six40-booking' );
}

// ── AJAX: get services (por local, agrupados por categoría) ──────────────────────
function six40_ajax_get_services() {
    check_ajax_referer( 'six40_booking_nonce', 'nonce' );

    $location = sanitize_text_field( $_POST['location'] ?? '' );
    if ( ! in_array( $location, [ 'malaga', 'torremolinos' ], true ) ) {
        wp_send_json_error( [ 'message' => __( 'Local inválido.', 'six40-booking' ) ] );
    }

    $api      = new Six40_Booking_API();
    $services = $api->get_services( $location );

    if ( is_wp_error( $services ) ) {
        wp_send_json_error( [ 'message' => $services->get_error_message() ] );
    }

    // Etiquetas legibles por categoría, en el orden en que deben mostrarse.
    $cat_labels = [
        'corte'       => 'Corte',
        'barba'       => 'Barba',
        'tratamiento' => 'Tratamientos',
    ];

    $grouped = [];
    foreach ( $cat_labels as $key => $label ) {
        $grouped[ $key ] = [ 'label' => $label, 'items' => [] ];
    }

    foreach ( (array) $services as $svc ) {
        $cat = $svc['category'] ?? 'otros';
        if ( ! isset( $grouped[ $cat ] ) ) {
            $grouped[ $cat ] = [ 'label' => ucfirst( $cat ), 'items' => [] ];
        }
        $grouped[ $cat ]['items'][] = [
            'id'         => (int) $svc['id'],
            'name'       => $svc['name'] ?? '',
            'category'   => $cat,
            'duration'   => (int) ( $svc['duration'] ?? 0 ),
            'price'      => isset( $svc['price'] ) ? (float) $svc['price'] : null,
            'price_from' => ! empty( $svc['price_from'] ),
        ];
    }

    // Quitar categorías vacías (por si un local no tiene servicios de alguna).
    $grouped = array_values( array_filter( $grouped, function ( $g ) {
        return ! empty( $g['items'] );
    } ) );

    wp_send_json_success( [ 'categories' => $grouped ] );
}

// ── AJAX: get slots ────────────────────────────────────────────────────────────
function six40_ajax_get_slots() {
    check_ajax_referer( 'six40_booking_nonce', 'nonce' );

    $location    = sanitize_text_field( $_POST['location'] ?? '' );
    $date        = sanitize_text_field( $_POST['date'] ?? '' );
    $service_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $_POST['service_ids'] ?? [] ) ) ) ) );
    $barber_id   = intval( $_POST['barber_id'] ?? 0 );

    if ( ! $location || ! $date || empty( $service_ids ) ) {
        wp_send_json_error( [ 'message' => __( 'Parámetros inválidos.', 'six40-booking' ) ] );
    }
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        wp_send_json_error( [ 'message' => __( 'Fecha inválida.', 'six40-booking' ) ] );
    }

    $api = new Six40_Booking_API();

    // En modo "primera cita disponible" (barbero 0) pedimos también qué barbero
    // atendería cada hueco, para mostrarlo en el resumen antes de confirmar.
    if ( ! $barber_id ) {
        $result = $api->get_available_slots( $location, $date, $service_ids, 0, true );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( [
            'slots'        => $result['slots'] ?? [],
            'slot_barbers' => $result['barbers'] ?? (object) [],
        ] );
    }

    $slots = $api->get_available_slots( $location, $date, $service_ids, $barber_id );

    if ( is_wp_error( $slots ) ) {
        wp_send_json_error( [ 'message' => $slots->get_error_message() ] );
    }

    wp_send_json_success( [ 'slots' => $slots ] );
}

// ── AJAX: días del mes con disponibilidad (para grisar el calendario) ──────────
function six40_ajax_get_month_days() {
    check_ajax_referer( 'six40_booking_nonce', 'nonce' );

    $location    = sanitize_text_field( $_POST['location'] ?? '' );
    $year_month  = sanitize_text_field( $_POST['year_month'] ?? '' );
    $service_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $_POST['service_ids'] ?? [] ) ) ) ) );
    $barber_id   = intval( $_POST['barber_id'] ?? 0 );

    if ( ! $location || ! preg_match( '/^\d{4}-\d{2}$/', $year_month ) || empty( $service_ids ) ) {
        wp_send_json_error( [ 'message' => __( 'Parámetros inválidos.', 'six40-booking' ) ] );
    }

    $api  = new Six40_Booking_API();
    $days = $api->get_month_available_days( $location, $year_month, $service_ids, $barber_id );

    wp_send_json_success( [ 'days' => $days ] );
}

// ── AJAX: submit booking ───────────────────────────────────────────────────────
function six40_ajax_submit_booking() {
    check_ajax_referer( 'six40_booking_nonce', 'nonce' );

    $location       = sanitize_text_field( $_POST['location'] ?? '' );
    $service_ids    = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $_POST['service_ids'] ?? [] ) ) ) ) );
    $date           = sanitize_text_field( $_POST['date'] ?? '' );
    $start_time     = sanitize_text_field( $_POST['start_time'] ?? '' );
    $customer_name  = sanitize_text_field( $_POST['customer_name'] ?? '' );
    $customer_email = sanitize_email( $_POST['customer_email'] ?? '' );
    $customer_phone = sanitize_text_field( $_POST['customer_phone'] ?? '' );
    $barber_id      = intval( $_POST['barber_id'] ?? 0 );

    // Teléfono obligatorio: al menos 9 dígitos.
    $phone_digits = preg_replace( '/\D/', '', $customer_phone );

    if ( ! $location || empty( $service_ids ) || ! $date || ! $start_time || ! $customer_name || ! is_email( $customer_email ) || strlen( $phone_digits ) < 9 ) {
        wp_send_json_error( [ 'message' => __( 'Por favor, completa todos los campos correctamente (incluido el teléfono).', 'six40-booking' ) ] );
    }
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        wp_send_json_error( [ 'message' => __( 'Fecha inválida.', 'six40-booking' ) ] );
    }
    if ( ! preg_match( '/^\d{2}:\d{2}$/', $start_time ) ) {
        wp_send_json_error( [ 'message' => __( 'Hora inválida.', 'six40-booking' ) ] );
    }

    $api = new Six40_Booking_API();
    $result = $api->create_appointment( [
        'location'       => $location,
        'service_ids'    => $service_ids,
        'date'           => $date,
        'start_time'     => $start_time,
        'customer_name'  => $customer_name,
        'customer_email' => $customer_email,
        'customer_phone' => $customer_phone,
        'barber_id'      => $barber_id,
    ] );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    // Fire-and-forget integrations (los errores no bloquean la cita, pero se registran).
    $gc = ( new Six40_Google_Calendar() )->create_event( $result );
    if ( is_wp_error( $gc ) ) {
        error_log( 'Six40 Google Calendar: ' . $gc->get_error_message() );
    } elseif ( is_array( $gc ) && ! empty( $gc['event_id'] ) && ! empty( $result['id'] ) ) {
        // Guardamos el evento (barbero) para detectar anulaciones y todos los
        // eventos (barbero + local) para sincronizar cancelar/mover.
        $api->set_appointment_google_event( $result['id'], $gc['event_id'], $gc['calendar_id'] ?? '', $gc['events'] ?? null );
    }
    $em = ( new Six40_Email() )->send_confirmation( $result );
    if ( is_wp_error( $em ) ) {
        error_log( 'Six40 Email: ' . $em->get_error_message() );
    }

    wp_send_json_success( [
        'message'        => __( '¡Cita confirmada! Recibirás un correo de confirmación.', 'six40-booking' ),
        'appointment_id' => $result['id'] ?? null,
    ] );
}
