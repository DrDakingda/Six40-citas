<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google Calendar integration via OAuth2 web flow.
 * Uses the Calendar REST API v3 directly (no PHP client library required).
 */
class Six40_Google_Calendar {

    private function settings() {
        return (array) get_option( 'six40_settings', [] );
    }

    private function calendar_id( $location ) {
        $cfg = $this->settings();
        return $location === 'malaga'
            ? ( $cfg['google_calendar_malaga'] ?? '' )
            : ( $cfg['google_calendar_torremolinos'] ?? '' );
    }

    /**
     * ID del Google Calendar de un barbero concreto (option six40_barber_calendars).
     *
     * @param int $barber_id
     * @return string  '' si no está configurado
     */
    public function barber_calendar_id( $barber_id ) {
        $map = (array) get_option( 'six40_barber_calendars', [] );
        return trim( (string) ( $map[ (int) $barber_id ] ?? '' ) );
    }

    /**
     * Creates a Calendar event for a confirmed appointment.
     * Se crea en el calendario del barbero Y en el del local (los que estén
     * configurados): agenda individual por barbero + vista conjunta por barbería.
     */
    public function create_event( $appointment ) {
        $barber_id = (int) ( $appointment['barber_id'] ?? 0 );

        $targets = [];
        $bcal = $this->barber_calendar_id( $barber_id );
        if ( $bcal ) { $targets[] = $bcal; }
        $lcal = $this->calendar_id( $appointment['location'] ?? '' );
        if ( $lcal ) { $targets[] = $lcal; }
        $targets = array_values( array_unique( array_filter( $targets ) ) );

        if ( empty( $targets ) ) {
            return new WP_Error( 'no_calendar', 'Google Calendar ID not configured.' );
        }

        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) return $token;

        // Build service label from services array
        $service_names = [];
        if ( ! empty( $appointment['services'] ) && is_array( $appointment['services'] ) ) {
            foreach ( $appointment['services'] as $svc ) {
                if ( is_array( $svc ) ) {
                    $service_names[] = $svc['name'] ?? '';
                } elseif ( is_string( $svc ) ) {
                    $service_names[] = $svc;
                }
            }
        }
        $service_label = ! empty( $service_names ) ? implode( ' + ', array_filter( $service_names ) ) : '—';

        $date       = $appointment['date'] ?? '';
        // Supabase devuelve TIME como 'HH:MM:SS'; normalizamos a 'HH:MM' para
        // construir un dateTime RFC3339 válido ("...THH:MM:00").
        $time_start = substr( (string) ( $appointment['start_time'] ?? '' ), 0, 5 );
        $time_end   = substr( (string) ( $appointment['end_time'] ?? '' ), 0, 5 );

        if ( ! $date || ! $time_start || ! $time_end ) {
            return new WP_Error( 'invalid_data', 'Appointment data incomplete.' );
        }

        $barber_name = is_array( $appointment['barber'] ?? null ) ? $appointment['barber']['name'] ?? '' : ( $appointment['barber_name'] ?? '' );

        $description = sprintf(
            "Cliente: %s\nTeléfono: %s\nEmail: %s\nServicios: %s\nDuración: %d min\nBarbero: %s",
            $appointment['customer_name'] ?? '',
            $appointment['customer_phone'] ?? '',
            $appointment['customer_email'] ?? '',
            $service_label,
            $appointment['duration'] ?? 0,
            $barber_name
        );
        if ( ! empty( $appointment['manage_token'] ) && class_exists( 'Six40_Manage' ) ) {
            $description .= "\n\nCancelar o cambiar la cita:\n" . Six40_Manage::url( $appointment['manage_token'] );
        }

        $event = [
            'summary'     => sprintf( '%s — %s', $service_label, $appointment['customer_name'] ?? '' ),
            'location'    => six40_locations()[ $appointment['location'] ?? '' ]['address'] ?? '',
            'description' => $description,
            'start' => [ 'dateTime' => "{$date}T{$time_start}:00", 'timeZone' => 'Europe/Madrid' ],
            'end'   => [ 'dateTime' => "{$date}T{$time_end}:00",   'timeZone' => 'Europe/Madrid' ],
            'reminders' => [
                'useDefault' => false,
                'overrides'  => [
                    [ 'method' => 'email', 'minutes' => 60 ],
                    [ 'method' => 'popup', 'minutes' => 30 ],
                ],
            ],
        ];

        $ok         = false;
        $last_error = null;
        $all        = []; // todos los eventos creados: [ { event_id, calendar_id }, ... ]
        foreach ( $targets as $idx => $cid ) {
            // El cliente se añade como invitado SOLO en el primer calendario
            // (el del barbero), para que reciba una única invitación.
            $body = $event;
            if ( $idx === 0 && ! empty( $appointment['customer_email'] ) ) {
                $body['attendees'] = [ [ 'email' => $appointment['customer_email'] ] ];
            }

            $response = wp_remote_post(
                'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $cid ) . '/events',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                    ],
                    'body'    => wp_json_encode( $body ),
                    'timeout' => 15,
                ]
            );

            if ( is_wp_error( $response ) ) {
                $last_error = $response;
                continue;
            }
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code >= 400 ) {
                $err = json_decode( wp_remote_retrieve_body( $response ), true );
                $last_error = new WP_Error( 'calendar_error', $err['error']['message'] ?? "HTTP $code" );
                continue;
            }
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            $all[] = [ 'event_id' => $data['id'] ?? '', 'calendar_id' => $cid ];
            $ok = true;
        }

        if ( ! $ok && $last_error ) {
            return $last_error;
        }
        // El primero (idx 0) es el del barbero: se usa para detectar anulaciones.
        // 'events' lleva todos (barbero + local) para sincronizar cancelar/mover.
        $primary = $all[0] ?? [ 'event_id' => '', 'calendar_id' => '' ];
        return [
            'event_id'    => $primary['event_id'],
            'calendar_id' => $primary['calendar_id'],
            'events'      => $all,
        ];
    }

    /**
     * Intervalos ocupados de varios calendarios en un rango de días.
     * Una sola llamada a freeBusy para todo el rango.
     *
     * @return array [ calendar_id => [ 'YYYY-MM-DD' => [ ['HH:MM','HH:MM'], ... ] ] ]
     */
    public function get_busy_range( $calendar_ids, $date_from, $date_to ) {
        $calendar_ids = array_values( array_filter( array_unique( (array) $calendar_ids ) ) );
        if ( empty( $calendar_ids ) ) {
            return [];
        }
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        if ( ! preg_match( $re, (string) $date_from ) || ! preg_match( $re, (string) $date_to ) ) {
            return [];
        }
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return [];
        }

        $tz = new \DateTimeZone( 'Europe/Madrid' );
        try {
            $min = new \DateTime( $date_from . ' 00:00:00', $tz );
            $max = new \DateTime( $date_to . ' 23:59:59', $tz );
        } catch ( \Exception $e ) {
            return [];
        }

        $response = wp_remote_post( 'https://www.googleapis.com/calendar/v3/freeBusy', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'timeMin'  => $min->format( \DateTime::RFC3339 ),
                'timeMax'  => $max->format( \DateTime::RFC3339 ),
                'timeZone' => 'Europe/Madrid',
                'items'    => array_map( function ( $id ) { return [ 'id' => $id ]; }, $calendar_ids ),
            ] ),
            'timeout' => 20,
        ] );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
            return [];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $out  = [];
        foreach ( (array) ( $data['calendars'] ?? [] ) as $cid => $info ) {
            foreach ( (array) ( $info['busy'] ?? [] ) as $b ) {
                try {
                    $s = ( new \DateTime( $b['start'] ) )->setTimezone( $tz );
                    $e = ( new \DateTime( $b['end'] ) )->setTimezone( $tz );
                } catch ( \Exception $ex ) {
                    continue;
                }
                // Repartir el intervalo por días (eventos de varios días / todo el día).
                $cur   = clone $s;
                $guard = 0;
                while ( $cur->format( 'Y-m-d' ) <= $e->format( 'Y-m-d' ) && $guard++ < 40 ) {
                    $d     = $cur->format( 'Y-m-d' );
                    $start = ( $d === $s->format( 'Y-m-d' ) ) ? $s->format( 'H:i' ) : '00:00';
                    $end   = ( $d === $e->format( 'Y-m-d' ) ) ? $e->format( 'H:i' ) : '23:59';
                    if ( $start < $end ) {
                        $out[ $cid ][ $d ][] = [ $start, $end ];
                    }
                    $cur->modify( '+1 day' )->setTime( 0, 0 );
                }
            }
        }
        return $out;
    }

    /**
     * Eventos de varios calendarios en un rango (con título e id), para mostrarlos
     * en el calendario del panel de administración.
     *
     * @param array  $calendar_ids
     * @param string $time_min  fecha/hora ISO
     * @param string $time_max  fecha/hora ISO
     * @return array [ ['calendar_id','id','summary','start','end','all_day'], ... ]
     */
    public function get_events_range( $calendar_ids, $time_min, $time_max ) {
        $calendar_ids = array_values( array_filter( array_unique( (array) $calendar_ids ) ) );
        if ( empty( $calendar_ids ) || ! $time_min || ! $time_max ) {
            return [];
        }
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return [];
        }

        $tz = new \DateTimeZone( 'Europe/Madrid' );
        try {
            $min = new \DateTime( $time_min, $tz );
            $max = new \DateTime( $time_max, $tz );
        } catch ( \Exception $e ) {
            return [];
        }

        $out = [];
        foreach ( $calendar_ids as $cid ) {
            $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $cid ) . '/events?' . http_build_query( [
                'timeMin'      => $min->format( \DateTime::RFC3339 ),
                'timeMax'      => $max->format( \DateTime::RFC3339 ),
                'singleEvents' => 'true',
                'orderBy'      => 'startTime',
                'maxResults'   => 250,
            ] );
            $response = wp_remote_get( $url, [
                'headers' => [ 'Authorization' => 'Bearer ' . $token ],
                'timeout' => 20,
            ] );
            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
                continue;
            }
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            foreach ( (array) ( $data['items'] ?? [] ) as $it ) {
                if ( ( $it['status'] ?? '' ) === 'cancelled' ) {
                    continue;
                }
                $start = $it['start']['dateTime'] ?? ( $it['start']['date'] ?? '' );
                $end   = $it['end']['dateTime']   ?? ( $it['end']['date']   ?? '' );
                if ( ! $start ) {
                    continue;
                }
                $out[] = [
                    'calendar_id' => $cid,
                    'id'          => $it['id'] ?? '',
                    'summary'     => $it['summary'] ?? '',
                    'start'       => $start,
                    'end'         => $end,
                    'all_day'     => empty( $it['start']['dateTime'] ),
                ];
            }
        }
        return $out;
    }

    /**
     * Estado de un evento en Google Calendar: 'active' | 'cancelled' | 'deleted' | null (error/skip).
     *
     * @param string $calendar_id
     * @param string $event_id
     * @return string|null
     */
    public function get_event_status( $calendar_id, $event_id ) {
        if ( ! $calendar_id || ! $event_id ) {
            return null;
        }
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return null;
        }
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $calendar_id )
             . '/events/' . rawurlencode( $event_id );
        $response = wp_remote_get( $url, [
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
            'timeout' => 15,
        ] );
        if ( is_wp_error( $response ) ) {
            return null;
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( 404 === $code || 410 === $code ) {
            return 'deleted';
        }
        if ( $code >= 400 ) {
            return null; // error transitorio: no tocar la cita
        }
        $data   = json_decode( wp_remote_retrieve_body( $response ), true );
        $status = $data['status'] ?? '';
        return ( 'cancelled' === $status ) ? 'cancelled' : 'active';
    }

    /**
     * Borra un evento del calendario.
     *
     * @return bool|WP_Error true si se borró (o ya no existía).
     */
    public function delete_event( $calendar_id, $event_id ) {
        if ( ! $calendar_id || ! $event_id ) {
            return false;
        }
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $calendar_id )
             . '/events/' . rawurlencode( $event_id );
        $response = wp_remote_request( $url, [
            'method'  => 'DELETE',
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
            'timeout' => 15,
        ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        return ( $code < 400 || 404 === $code || 410 === $code );
    }

    /**
     * Cambia la fecha/hora de un evento.
     *
     * @return bool|WP_Error
     */
    public function update_event_time( $calendar_id, $event_id, $date, $start, $end ) {
        if ( ! $calendar_id || ! $event_id ) {
            return false;
        }
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }
        $start = substr( (string) $start, 0, 5 );
        $end   = substr( (string) $end, 0, 5 );
        $body  = [
            'start' => [ 'dateTime' => "{$date}T{$start}:00", 'timeZone' => 'Europe/Madrid' ],
            'end'   => [ 'dateTime' => "{$date}T{$end}:00",   'timeZone' => 'Europe/Madrid' ],
        ];
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $calendar_id )
             . '/events/' . rawurlencode( $event_id );
        $response = wp_remote_request( $url, [
            'method'  => 'PATCH',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
        ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        return wp_remote_retrieve_response_code( $response ) < 400;
    }

    /**
     * Devuelve los intervalos ocupados (busy) de varios calendarios para un día,
     * vía la API freeBusy de Google. Horas en zona Europe/Madrid.
     *
     * @param array  $calendar_ids  IDs de calendario
     * @param string $date          'YYYY-MM-DD'
     * @return array  [ calendar_id => [ [ 'HH:MM', 'HH:MM' ], ... ] ]  (vacío si no conectado o error)
     */
    public function get_busy( $calendar_ids, $date ) {
        $calendar_ids = array_values( array_filter( array_unique( (array) $calendar_ids ) ) );
        if ( empty( $calendar_ids ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
            return [];
        }

        // Si no hay conexión con Google, no bloqueamos nada (fail-safe).
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return [];
        }

        $tz = new \DateTimeZone( 'Europe/Madrid' );
        try {
            $min = new \DateTime( $date . ' 00:00:00', $tz );
            $max = new \DateTime( $date . ' 23:59:59', $tz );
        } catch ( \Exception $e ) {
            return [];
        }

        $body = [
            'timeMin'  => $min->format( \DateTime::RFC3339 ),
            'timeMax'  => $max->format( \DateTime::RFC3339 ),
            'timeZone' => 'Europe/Madrid',
            'items'    => array_map( function ( $id ) { return [ 'id' => $id ]; }, $calendar_ids ),
        ];

        $response = wp_remote_post( 'https://www.googleapis.com/calendar/v3/freeBusy', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
            return [];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $out  = [];
        foreach ( (array) ( $data['calendars'] ?? [] ) as $cid => $info ) {
            $intervals = [];
            foreach ( (array) ( $info['busy'] ?? [] ) as $b ) {
                try {
                    $s = ( new \DateTime( $b['start'] ) )->setTimezone( $tz );
                    $e = ( new \DateTime( $b['end'] ) )->setTimezone( $tz );
                    $intervals[] = [ $s->format( 'H:i' ), $e->format( 'H:i' ) ];
                } catch ( \Exception $e ) {
                    continue;
                }
            }
            $out[ $cid ] = $intervals;
        }
        return $out;
    }

    // ── OAuth2 ────────────────────────────────────────────────────────────────

    private function get_access_token() {
        $cached = get_transient( 'six40_google_access_token' );
        if ( $cached ) return $cached;

        $cfg           = $this->settings();
        $refresh_token = $cfg['google_refresh_token'] ?? '';

        if ( ! $refresh_token ) {
            return new WP_Error( 'no_refresh_token', 'Google refresh token not configured. See plugin settings → Google Calendar.' );
        }

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id'     => $cfg['google_client_id'] ?? '',
                'client_secret' => $cfg['google_client_secret'] ?? '',
                'refresh_token' => $refresh_token,
                'grant_type'    => 'refresh_token',
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['access_token'] ) ) {
            return new WP_Error( 'token_error', $data['error_description'] ?? 'Could not refresh Google token.' );
        }

        $expires = (int) ( $data['expires_in'] ?? 3600 );
        set_transient( 'six40_google_access_token', $data['access_token'], $expires - 60 );

        return $data['access_token'];
    }

    public function get_auth_url() {
        $cfg          = $this->settings();
        $redirect_uri = admin_url( 'admin.php?page=six40-settings&oauth=google' );
        return 'https://accounts.google.com/o/oauth2/auth?' . http_build_query( [
            'client_id'     => $cfg['google_client_id'] ?? '',
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/calendar',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ] );
    }

    public function exchange_code( $code ) {
        $cfg          = $this->settings();
        $redirect_uri = admin_url( 'admin.php?page=six40-settings&oauth=google' );

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $code,
                'client_id'     => $cfg['google_client_id'] ?? '',
                'client_secret' => $cfg['google_client_secret'] ?? '',
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['refresh_token'] ) ) {
            return new WP_Error( 'no_refresh', $data['error_description'] ?? 'No refresh token returned.' );
        }

        $cfg['google_refresh_token'] = $data['refresh_token'];
        update_option( 'six40_settings', $cfg );
        set_transient( 'six40_google_access_token', $data['access_token'], (int) ( $data['expires_in'] ?? 3600 ) - 60 );

        return true;
    }
}
