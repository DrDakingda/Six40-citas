<?php
defined( 'ABSPATH' ) || exit;

/**
 * Six40 Booking API — Handles all appointment logic with Supabase.
 * Supports flexible service combinations and per-barber schedules.
 */
class Six40_Booking_API {

	const SLOT_MINS = 30; // Reservation slot size in minutes

	private function settings() {
		return (array) get_option( 'six40_settings', [] );
	}

	// ── Supabase REST API ──────────────────────────────────────────────────────

	/**
	 * Make a REST request to Supabase.
	 *
	 * @return array|WP_Error
	 */
	private function supabase_request( $method, $endpoint, $body = [], $query = [] ) {
		$cfg = $this->settings();
		$url = rtrim( $cfg['supabase_url'] ?? '', '/' ) . '/rest/v1/' . ltrim( $endpoint, '/' );

		if ( $query ) {
			$url .= '?' . http_build_query( $query );
		}

		$args = [
			'method'  => strtoupper( $method ),
			'headers' => [
				'apikey'        => $cfg['supabase_key'] ?? '',
				'Authorization' => 'Bearer ' . ( $cfg['supabase_key'] ?? '' ),
				'Content-Type'  => 'application/json',
				'Prefer'        => 'return=representation',
			],
			'timeout' => 15,
		];

		if ( $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			return new WP_Error(
				'supabase_error',
				$data['message'] ?? $data['error'] ?? "HTTP $code",
				[ 'status' => $code, 'pg_code' => $data['code'] ?? '' ]
			);
		}

		return $data ?? [];
	}

	// ── Public: Services ──────────────────────────────────────────────────────

	/**
	 * Get all active services for a location (grouped selection = "menú libre").
	 * Includes services with location = X and location = NULL (comunes a ambos).
	 *
	 * @param string|null $location 'malaga' | 'torremolinos' | null (todos)
	 * @return array|WP_Error
	 */
	public function get_services( $location = null ) {
		$query = [ 'select' => '*', 'active' => 'eq.true', 'order' => 'display_order.asc' ];
		if ( $location ) {
			// location = X  OR  location IS NULL
			$query['or'] = '(location.eq.' . $location . ',location.is.null)';
		}
		return $this->supabase_request( 'GET', 'services', [], $query );
	}

	/**
	 * Get a single service by ID.
	 *
	 * @return array|WP_Error|null
	 */
	public function get_service( $id ) {
		$id = intval( $id );

		// Cache por petición: calculate_service_duration() pide los mismos
		// servicios varias veces y cada get_service() es una llamada HTTP.
		static $cache = [];
		if ( array_key_exists( $id, $cache ) ) {
			return $cache[ $id ];
		}

		$result = $this->supabase_request( 'GET', 'services', [], [ 'id' => 'eq.' . $id ] );
		if ( is_wp_error( $result ) || empty( $result ) ) {
			return null; // los fallos no se cachean
		}

		$cache[ $id ] = isset( $result[0] ) ? $result[0] : $result;
		return $cache[ $id ];
	}

	// ── Public: Barbers ───────────────────────────────────────────────────────

	/**
	 * Get all barbers for a location.
	 *
	 * @return array|WP_Error
	 */
	public function get_barbers( $location = null ) {
		$query = [ 'select' => '*', 'active' => 'eq.true', 'order' => 'id.asc' ];
		if ( $location ) {
			$query['location'] = 'eq.' . $location;
		}
		return $this->supabase_request( 'GET', 'barbers', [], $query );
	}

	/**
	 * Get a barber by ID.
	 *
	 * @return array|WP_Error|null
	 */
	public function get_barber( $id ) {
		$result = $this->supabase_request( 'GET', 'barbers', [], [ 'id' => 'eq.' . intval( $id ) ] );
		if ( is_wp_error( $result ) || empty( $result ) ) {
			return null;
		}
		return isset( $result[0] ) ? $result[0] : $result;
	}

	/**
	 * Get schedule for a barber on a specific day (day_of_week: 0=Mon, 6=Sun).
	 *
	 * @return array Sorted array of [start_time, end_time] pairs
	 */
	public function get_barber_schedule( $barber_id, $day_of_week ) {
		$result = $this->supabase_request( 'GET', 'barber_schedules', [], [
			'barber_id'    => 'eq.' . intval( $barber_id ),
			'day_of_week'  => 'eq.' . intval( $day_of_week ),
			'select'       => 'start_time,end_time',
			'order'        => 'start_time.asc',
		] );

		if ( is_wp_error( $result ) ) {
			return [];
		}

		return is_array( $result ) ? $result : [];
	}

	/**
	 * Todos los tramos de horario (todos los barberos y días). Para el editor admin.
	 *
	 * @return array filas [id, barber_id, day_of_week, start_time, end_time]
	 */
	public function get_all_barber_schedules() {
		$r = $this->supabase_request( 'GET', 'barber_schedules', [], [
			'select' => 'id,barber_id,day_of_week,start_time,end_time',
			'order'  => 'barber_id.asc,day_of_week.asc,start_time.asc',
		] );
		return is_wp_error( $r ) ? [] : (array) $r;
	}

	/**
	 * Añade un tramo de horario a un barbero.
	 *
	 * @return array|WP_Error
	 */
	public function add_barber_schedule( $barber_id, $day_of_week, $start, $end ) {
		return $this->supabase_request( 'POST', 'barber_schedules', [
			'barber_id'   => intval( $barber_id ),
			'day_of_week' => intval( $day_of_week ),
			'start_time'  => $start,
			'end_time'    => $end,
		] );
	}

	/**
	 * Elimina un tramo de horario por id.
	 *
	 * @return array|WP_Error
	 */
	public function delete_barber_schedule( $id ) {
		return $this->supabase_request( 'DELETE', 'barber_schedules', [], [ 'id' => 'eq.' . intval( $id ) ] );
	}

	// ── Public: Availability ──────────────────────────────────────────────────

	/**
	 * Get available slots for a location, date, and service combination.
	 *
	 * @param string   $location           'malaga' or 'torremolinos'
	 * @param string   $date               'YYYY-MM-DD'
	 * @param int|array $base_service_id   Base service ID (or array of service IDs for duration calc)
	 * @param int      $only_barber_id     0 = cualquiera del local
	 * @param bool     $with_barbers       true → devuelve también qué barbero atendería
	 *                                     cada hueco (el primero libre, mismo orden que
	 *                                     find_free_barber). Formato:
	 *                                     [ 'slots' => [...], 'barbers' => [ 'HH:MM' => ['id','name'] ] ]
	 * @return array|WP_Error Array of available times ['10:00', '10:30', ...]
	 */
	public function get_available_slots( $location, $date, $base_service_id, $only_barber_id = 0, $with_barbers = false ) {
		// Validate date
		if ( $date < wp_date( 'Y-m-d' ) ) {
			return [];
		}
		// Domingos y festivos: cerrado.
		if ( $this->is_closed_date( $date ) ) {
			return [];
		}

		// Get service(s) and calculate total duration
		$service_ids = is_array( $base_service_id ) ? $base_service_id : [ $base_service_id ];
		$total_duration = $this->calculate_service_duration( $service_ids );

		if ( $total_duration <= 0 ) {
			return new WP_Error( 'invalid_service', 'Invalid service(s).' );
		}

		// Get barbers for location
		$barbers = $this->get_barbers( $location );
		if ( is_wp_error( $barbers ) || empty( $barbers ) ) {
			return [];
		}

		// Get day of week for the date
		$dt_date = \DateTime::createFromFormat( 'Y-m-d', $date );
		$day_of_week = ( (int) $dt_date->format( 'N' ) ) - 1; // 0=Lun … 6=Dom (coincide con barber_schedules)

		// Get barbers off on this date
		$barbers_off = $this->get_barber_ids_off_on_date( $date );

		// Estados (available / vacation / sick)
		$statuses = $this->get_barber_statuses( $location );

		// Get appointments on this date
		$appointments = $this->supabase_request( 'GET', 'appointments', [], [
			'location' => 'eq.' . $location,
			'date'     => 'eq.' . $date,
			'status'   => 'neq.cancelled',
			'select'   => 'id,barber_id,start_time,end_time',
		] );

		if ( is_wp_error( $appointments ) ) {
			return $appointments;
		}

		$busy_map = $this->build_busy_intervals( $appointments ?? [] );
		$this->merge_google_busy_intervals( $busy_map, $barbers, $date );
		$available_slots = [];
		$slot_barbers    = []; // 'HH:MM' => primer barbero libre (orden id asc)

		// For each barber, try to find free slots
		foreach ( $barbers as $barber ) {
			// Si se pide un barbero concreto, ignorar los demás.
			if ( $only_barber_id && (int) $barber['id'] !== (int) $only_barber_id ) {
				continue;
			}
			// Día libre, vacaciones o baja: solo puede trabajar si tiene un
			// cambio de horario temporal para esa fecha.
			$is_off = in_array( $barber['id'], $barbers_off, true )
				|| ( $statuses[ $barber['id'] ] ?? 'available' ) !== 'available';

			// Horario de ese día (considerando cambios temporales)
			$regular_schedule = $this->get_barber_schedule( $barber['id'], $day_of_week );
			$schedule = $this->get_schedule_for_date( $barber['id'], $date, $regular_schedule, $is_off );

			if ( empty( $schedule ) ) {
				continue; // No trabaja ese día
			}

			$slots = $this->slots_for_barber( $schedule, $busy_map[ (int) $barber['id'] ] ?? [], $total_duration );
			foreach ( $slots as $slot ) {
				$available_slots[] = $slot;
				if ( ! isset( $slot_barbers[ $slot ] ) ) {
					$slot_barbers[ $slot ] = [
						'id'   => (int) $barber['id'],
						'name' => $barber['name'] ?? '',
					];
				}
			}
		}

		// Remove duplicates and sort
		$available_slots = array_unique( $available_slots );
		sort( $available_slots );

		// Filter out times in the past (for today)
		$today = wp_date( 'Y-m-d' );
		if ( $date === $today ) {
			$cutoff = ( new \DateTime( 'now', wp_timezone() ) )->modify( '+30 minutes' )->format( 'H:i' );
			$available_slots = array_values( array_filter( $available_slots, function( $s ) use ( $cutoff ) {
				return $s >= $cutoff;
			} ) );
		}

		$available_slots = array_values( $available_slots );

		if ( $with_barbers ) {
			$barbers_out = [];
			foreach ( $available_slots as $s ) {
				if ( isset( $slot_barbers[ $s ] ) ) {
					$barbers_out[ $s ] = $slot_barbers[ $s ];
				}
			}
			return [ 'slots' => $available_slots, 'barbers' => $barbers_out ];
		}

		return $available_slots;
	}

	/**
	 * Días de un mes que tienen AL MENOS un hueco libre. Calcula el mes entero
	 * con pocas consultas (horarios, citas y freeBusy de una sola vez) para poder
	 * grisar en el calendario los días sin disponibilidad.
	 *
	 * @param string $location
	 * @param string $year_month  'YYYY-MM'
	 * @param array  $service_ids
	 * @param int    $only_barber_id  0 = cualquiera del local
	 * @return array  ['YYYY-MM-DD', ...] días con disponibilidad
	 */
	public function get_month_available_days( $location, $year_month, $service_ids, $only_barber_id = 0 ) {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', (string) $year_month ) ) {
			return [];
		}
		$duration = $this->calculate_service_duration( $service_ids );
		if ( $duration <= 0 ) {
			return [];
		}

		$first = $year_month . '-01';
		$last  = $year_month . '-' . date( 't', strtotime( $first ) );
		$today = wp_date( 'Y-m-d' );
		$maxd  = wp_date( 'Y-m-d', strtotime( '+60 days' ) );

		// Barberos del local, filtrados por barbero concreto
		$barbers = $this->get_barbers( $location );
		if ( is_wp_error( $barbers ) || empty( $barbers ) ) {
			return [];
		}
		$statuses = $this->get_barber_statuses( $location );
		$barbers  = array_values( array_filter( (array) $barbers, function ( $b ) use ( $only_barber_id ) {
			if ( $only_barber_id && (int) $b['id'] !== (int) $only_barber_id ) {
				return false;
			}
			return true; // Incluir todos (chequearemos estado por fecha después)
		} ) );
		if ( empty( $barbers ) ) {
			return [];
		}
		$barber_ids = array_map( function ( $b ) { return (int) $b['id']; }, $barbers );

		// Horarios de la semana (1 consulta) → [bid][dow] = tramos
		$sched = [];
		$rows  = $this->supabase_request( 'GET', 'barber_schedules', [], [
			'select'    => 'barber_id,day_of_week,start_time,end_time',
			'barber_id' => 'in.(' . implode( ',', $barber_ids ) . ')',
			'order'     => 'start_time.asc',
		] );
		if ( ! is_wp_error( $rows ) ) {
			foreach ( (array) $rows as $r ) {
				$sched[ (int) $r['barber_id'] ][ (int) $r['day_of_week'] ][] = $r;
			}
		}
		if ( empty( $sched ) ) {
			return [];
		}

		// Días libres puntuales del mes (1 consulta) → [date][bid]
		$days_off = [];
		$rows = $this->supabase_request( 'GET', 'barber_days_off', [], [
			'select' => 'barber_id,date',
			'and'    => '(date.gte.' . $first . ',date.lte.' . $last . ')',
		] );
		if ( ! is_wp_error( $rows ) ) {
			foreach ( (array) $rows as $r ) {
				$days_off[ $r['date'] ][ (int) $r['barber_id'] ] = true;
			}
		}

		// Citas del mes (1 consulta) → ocupación por día (misma lógica que el día suelto)
		$busy_by_day = [];
		$rows = $this->supabase_request( 'GET', 'appointments', [], [
			'select'   => 'barber_id,date,start_time,end_time',
			'location' => 'eq.' . $location,
			'status'   => 'neq.cancelled',
			'and'      => '(date.gte.' . $first . ',date.lte.' . $last . ')',
		] );
		if ( ! is_wp_error( $rows ) ) {
			$by_day = [];
			foreach ( (array) $rows as $r ) {
				if ( ! empty( $r['date'] ) ) {
					$by_day[ $r['date'] ][] = $r;
				}
			}
			foreach ( $by_day as $d => $list ) {
				$busy_by_day[ $d ] = $this->build_busy_intervals( $list );
			}
		}

		// Ocupación de Google de todo el mes (1 llamada)
		$cal_map = (array) get_option( 'six40_barber_calendars', [] );
		$cal_ids = [];
		$cal_to_barbers = [];
		foreach ( $barbers as $b ) {
			$cid = trim( (string) ( $cal_map[ (int) $b['id'] ] ?? '' ) );
			if ( $cid !== '' ) {
				$cal_ids[] = $cid;
				$cal_to_barbers[ $cid ][] = (int) $b['id'];
			}
		}
		if ( ! empty( $cal_ids ) ) {
			$busy = ( new Six40_Google_Calendar() )->get_busy_range( $cal_ids, $first, $last );
			foreach ( (array) $busy as $cid => $days ) {
				foreach ( (array) $days as $d => $intervals ) {
					foreach ( (array) ( $cal_to_barbers[ $cid ] ?? [] ) as $bid ) {
						foreach ( (array) $intervals as $iv ) {
							$s = $this->time_to_mins( $iv[0] ?? '' );
							$e = $this->time_to_mins( $iv[1] ?? '' );
							if ( $e > $s ) {
								$busy_by_day[ $d ][ $bid ][] = [ $s, $e ];
							}
						}
					}
				}
			}
		}

		// Vacaciones programadas (opción WP)
		$vac = (array) get_option( 'six40_barber_vacations', [] );

		// Recorrer los días del mes
		$cutoff    = ( new \DateTime( 'now', wp_timezone() ) )->modify( '+30 minutes' )->format( 'H:i' );
		$available = [];
		$total     = (int) date( 't', strtotime( $first ) );

		for ( $i = 1; $i <= $total; $i++ ) {
			$d = sprintf( '%s-%02d', $year_month, $i );
			if ( $d < $today || $d > $maxd || $this->is_closed_date( $d ) ) {
				continue;
			}
			$dt  = \DateTime::createFromFormat( 'Y-m-d', $d );
			$dow = ( (int) $dt->format( 'N' ) ) - 1;
			$found = false;

			foreach ( $barbers as $b ) {
				$bid = (int) $b['id'];
				// Mismas restricciones que get_available_slots(): día libre,
				// vacaciones o baja solo se salvan con un cambio temporal.
				$is_off = ! empty( $days_off[ $d ][ $bid ] )
					|| $this->barber_on_vacation( $vac, $bid, $d )
					|| ( $statuses[ $bid ] ?? 'available' ) !== 'available';

				$regular_windows = (array) ( $sched[ $bid ][ $dow ] ?? [] );
				$windows = $this->get_schedule_for_date( $bid, $d, $regular_windows, $is_off );

				if ( empty( $windows ) ) {
					continue;
				}
				$slots = $this->slots_for_barber( $windows, $busy_by_day[ $d ][ $bid ] ?? [], $duration );
				foreach ( $slots as $slot ) {
					if ( $d === $today && $slot < $cutoff ) {
						continue;
					}
					$found = true;
					break 2;
				}
			}
			if ( $found ) {
				$available[] = $d;
			}
		}

		return $available;
	}

	/** ¿El barbero está de vacaciones programadas ese día? */
	private function barber_on_vacation( $vac, $barber_id, $date ) {
		foreach ( (array) ( $vac[ $barber_id ] ?? [] ) as $r ) {
			$s = $r['start'] ?? '';
			$e = $r['end'] ?? '';
			if ( $s && $e && $date >= $s && $date <= $e ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Cambios de horario temporales que aplican a un barbero en una fecha
	 * (option six40_schedule_exceptions), separados por tipo.
	 *
	 * @return array [ 'add' => [ tramos ], 'remove' => [ tramos ] ]
	 */
	private function schedule_exceptions_for_date( $barber_id, $date ) {
		$add    = [];
		$remove = [];

		$exceptions  = (array) get_option( 'six40_schedule_exceptions', [] );
		$barber_excs = (array) ( $exceptions[ (int) $barber_id ] ?? [] );

		foreach ( $barber_excs as $exc ) {
			$s = $exc['start'] ?? '';
			$e = $exc['end'] ?? '';
			if ( ! $s || ! $e || $date < $s || $date > $e ) {
				continue;
			}
			$start_time = $exc['start_time'] ?? '';
			$end_time   = $exc['end_time'] ?? '';
			if ( ! $start_time || ! $end_time ) {
				continue;
			}
			// Las claves tienen que ser start_time/end_time: es lo que lee
			// slots_for_barber() y lo que devuelve Supabase.
			$window = [ 'start_time' => $start_time, 'end_time' => $end_time ];
			if ( ( $exc['type'] ?? 'available' ) === 'unavailable' ) {
				$remove[] = $window;
			} else {
				$add[] = $window;
			}
		}

		return [ 'add' => $add, 'remove' => $remove ];
	}

	/**
	 * Tramos horarios efectivos de un barbero en una fecha concreta.
	 *
	 * Un cambio temporal "disponible" SUMA al horario regular. Si ese día el
	 * barbero está libre / de vacaciones / de baja, el cambio le deja trabajar
	 * pero SOLO en la franja añadida (su horario regular sigue cerrado). Un
	 * cambio "no disponible" resta su franja del resultado.
	 *
	 * @param int    $barber_id
	 * @param string $date            'YYYY-MM-DD'
	 * @param array  $regular_windows Tramos del horario semanal para ese día
	 * @param bool   $is_off          Día libre, vacaciones o baja
	 * @return array Tramos [ ['start_time','end_time'], ... ]
	 */
	private function get_schedule_for_date( $barber_id, $date, $regular_windows, $is_off = false ) {
		$exc = $this->schedule_exceptions_for_date( $barber_id, $date );

		$windows = $is_off
			? $exc['add']
			: array_merge( (array) $regular_windows, $exc['add'] );

		return $this->subtract_windows( $windows, $exc['remove'] );
	}

	/**
	 * Resta unos tramos de otros (en minutos), partiendo los que se solapan.
	 *
	 * @param array $windows Tramos [ ['start_time','end_time'], ... ]
	 * @param array $remove  Tramos a quitar, mismo formato
	 * @return array Tramos resultantes
	 */
	private function subtract_windows( $windows, $remove ) {
		$cuts = [];
		foreach ( (array) $remove as $r ) {
			$s = $this->time_to_mins( $r['start_time'] ?? '' );
			$e = $this->time_to_mins( $r['end_time'] ?? '' );
			if ( $e > $s ) {
				$cuts[] = [ $s, $e ];
			}
		}
		if ( empty( $cuts ) ) {
			return $windows;
		}

		$out = [];
		foreach ( (array) $windows as $w ) {
			$pieces = [ [ $this->time_to_mins( $w['start_time'] ?? '' ), $this->time_to_mins( $w['end_time'] ?? '' ) ] ];
			foreach ( $cuts as $cut ) {
				$next = [];
				foreach ( $pieces as $p ) {
					if ( $cut[1] <= $p[0] || $cut[0] >= $p[1] ) {
						$next[] = $p; // sin solape
						continue;
					}
					if ( $cut[0] > $p[0] ) {
						$next[] = [ $p[0], $cut[0] ];
					}
					if ( $cut[1] < $p[1] ) {
						$next[] = [ $cut[1], $p[1] ];
					}
				}
				$pieces = $next;
			}
			foreach ( $pieces as $p ) {
				if ( $p[1] > $p[0] ) {
					$out[] = [
						'start_time' => $this->mins_to_time( $p[0] ),
						'end_time'   => $this->mins_to_time( $p[1] ),
					];
				}
			}
		}

		return $out;
	}

	// ── Public: Appointments ──────────────────────────────────────────────────

	/**
	 * Create a new appointment.
	 *
	 * @param array $data {
	 *   'location'             => 'malaga' or 'torremolinos',
	 *   'date'                 => 'YYYY-MM-DD',
	 *   'start_time'           => 'HH:MM',
	 *   'base_service_id'      => int,
	 *   'additional_service_ids' => array of ints (optional),
	 *   'barber_id'            => int (optional, auto-assigned if not provided),
	 *   'customer_name'        => string,
	 *   'customer_email'       => string,
	 *   'customer_phone'       => string (optional),
	 * }
	 * @return array|WP_Error The created appointment with services
	 */
	public function create_appointment( $data ) {
		$location = $data['location'] ?? '';
		$date = $data['date'] ?? '';
		$start_time = $data['start_time'] ?? '';
		$barber_id = (int) ( $data['barber_id'] ?? 0 );

		// Servicios (menú libre): admite 'service_ids' o base+additional (compat).
		if ( ! empty( $data['service_ids'] ) ) {
			$service_ids = array_map( 'intval', (array) $data['service_ids'] );
		} else {
			$base = (int) ( $data['base_service_id'] ?? 0 );
			$add  = array_map( 'intval', (array) ( $data['additional_service_ids'] ?? [] ) );
			$service_ids = array_merge( [ $base ], $add );
		}
		$service_ids = array_values( array_unique( array_filter( $service_ids ) ) );

		// Validate location
		if ( ! in_array( $location, [ 'malaga', 'torremolinos' ], true ) ) {
			return new WP_Error( 'invalid_location', 'Invalid location.' );
		}

		// Nunca en el pasado.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) || $date < wp_date( 'Y-m-d' ) ) {
			return new WP_Error( 'past_date', 'Esa fecha ya no está disponible.' );
		}

		// Domingos y festivos: cerrado.
		if ( $this->is_closed_date( $date ) ) {
			return new WP_Error( 'closed_date', 'Ese día no está disponible para reservas.' );
		}

		// Validate: al menos un servicio existente
		if ( empty( $service_ids ) ) {
			return new WP_Error( 'invalid_service', 'Selecciona al menos un servicio.' );
		}
		$valid_services = [];
		foreach ( $service_ids as $sid ) {
			$svc = $this->get_service( $sid );
			if ( $svc ) {
				$valid_services[] = $svc;
			}
		}
		if ( empty( $valid_services ) ) {
			return new WP_Error( 'invalid_service', 'Servicios no válidos.' );
		}
		// Solo IDs realmente válidos
		$service_ids = array_map( function ( $s ) { return (int) $s['id']; }, $valid_services );

		// Máximo un servicio de corte y uno de barba por cita (espejo del frontend).
		$per_cat = [];
		foreach ( $valid_services as $s ) {
			$c = $s['category'] ?? '';
			$per_cat[ $c ] = ( $per_cat[ $c ] ?? 0 ) + 1;
		}
		if ( ( $per_cat['corte'] ?? 0 ) > 1 ) {
			return new WP_Error( 'too_many_cortes', 'Solo puedes elegir un tipo de corte por cita.' );
		}
		if ( ( $per_cat['barba'] ?? 0 ) > 1 ) {
			return new WP_Error( 'too_many_barbas', 'Solo puedes elegir un servicio de barba por cita.' );
		}

		// Rapado (cortar al 0) es incompatible con tratamientos de color en el
		// PELO (fantasía, mechas, iluminaciones, reducción de canas). El color de
		// BARBA sí es compatible (va en la barba, no en el pelo).
		$has_rapado = false;
		$has_hair_treat = false;
		foreach ( $valid_services as $s ) {
			$cat  = $s['category'] ?? '';
			$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s['name'] ?? '' ) : strtolower( $s['name'] ?? '' );
			if ( $cat === 'corte' && strpos( $name, 'rapado' ) !== false ) {
				$has_rapado = true;
			}
			if ( $cat === 'tratamiento' && strpos( $name, 'barba' ) === false ) {
				$has_hair_treat = true;
			}
		}
		if ( $has_rapado && $has_hair_treat ) {
			return new WP_Error( 'rapado_incompat', 'El rapado no admite tratamientos de color en el pelo.' );
		}

		// Calculate total duration
		$total_duration = $this->calculate_service_duration( $service_ids );
		$end_time = $this->calculate_end_time( $start_time, $total_duration );

		// Auto-assign barber if not provided
		if ( ! $barber_id ) {
			$barber_id = $this->find_free_barber( $location, $date, $start_time, $service_ids );
			if ( ! $barber_id ) {
				return new WP_Error( 'no_barber', 'No barbers available at this time.' );
			}
		} else {
			// Barbero elegido por el cliente: el hueco tiene que seguir libre.
			// get_available_slots() ya contempla horario, días libres, vacaciones,
			// baja, cambios temporales, citas existentes y Google Calendar.
			$slots = $this->get_available_slots( $location, $date, $service_ids, (int) $barber_id );
			if ( is_wp_error( $slots ) ) {
				return $slots;
			}
			if ( ! in_array( $start_time, (array) $slots, true ) ) {
				return new WP_Error( 'slot_unavailable', 'Esa hora ya no está disponible. Elige otra, por favor.' );
			}
		}

		// Create appointment
		$appt_data = [
			'location'        => $location,
			'date'            => $date,
			'start_time'      => $start_time,
			'end_time'        => $end_time,
			'barber_id'       => $barber_id,
			'customer_name'   => $data['customer_name'] ?? '',
			'customer_email'  => $data['customer_email'] ?? '',
			'customer_phone'  => $data['customer_phone'] ?? '',
			'status'          => 'confirmed',
			'manage_token'    => wp_generate_password( 24, false ), // enlace de gestión
		];

		$result = $this->supabase_request( 'POST', 'appointments', $appt_data );

		if ( is_wp_error( $result ) ) {
			// Choque con la restricción antisolapamiento de Postgres: alguien
			// confirmó ese hueco entre que se pintaron las horas y este submit.
			$err = (array) $result->get_error_data();
			if ( in_array( $err['pg_code'] ?? '', [ '23505', '23P01' ], true ) ) {
				return new WP_Error( 'slot_taken', 'Esa hora acaba de ocuparse. Elige otra, por favor.' );
			}
			return $result;
		}

		$appointment = isset( $result[0] ) ? $result[0] : $result;
		$appt_id = $appointment['id'] ?? null;

		if ( ! $appt_id ) {
			return new WP_Error( 'no_id', 'Failed to create appointment.' );
		}

		// Insert appointment services
		foreach ( $service_ids as $idx => $svc_id ) {
			$svc_row = [
				'appointment_id' => $appt_id,
				'service_id'     => $svc_id,
				'sequence'       => $idx,
			];
			$this->supabase_request( 'POST', 'appointment_services', $svc_row );
		}

		// Enrich response with barber and service info
		$barber = $this->get_barber( $barber_id );
		$appointment['barber'] = $barber ?: [ 'name' => 'Unknown' ];

		$appointment['services'] = $valid_services;
		$appointment['duration'] = $total_duration;

		return $appointment;
	}

	/**
	 * Get appointments with optional filters.
	 *
	 * @param array $filters e.g., ['location' => 'malaga', 'date' => '2026-06-15']
	 * @return array|WP_Error
	 */
	public function get_appointments( $filters = [] ) {
		// Traemos los servicios embebidos (tabla puente appointment_services → services).
		$query = [
			'select' => '*,appointment_services(sequence,services(name))',
			'order'  => 'date.asc,start_time.asc',
		];
		foreach ( $filters as $col => $val ) {
			$query[ $col ] = 'eq.' . $val;
		}
		$rows = $this->supabase_request( 'GET', 'appointments', [], $query );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		foreach ( $rows as &$row ) {
			$row['services_label'] = $this->build_services_label( $row );
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Construye "Servicio1 + Servicio2" a partir de los servicios embebidos.
	 *
	 * @param array $appointment
	 * @return string
	 */
	private function build_services_label( $appointment ) {
		$items = $appointment['appointment_services'] ?? [];
		if ( ! is_array( $items ) || empty( $items ) ) {
			return '';
		}
		usort( $items, function ( $a, $b ) {
			return ( (int) ( $a['sequence'] ?? 0 ) ) - ( (int) ( $b['sequence'] ?? 0 ) );
		} );
		$names = [];
		foreach ( $items as $it ) {
			$name = $it['services']['name'] ?? '';
			if ( $name ) {
				$names[] = $name;
			}
		}
		return implode( ' + ', $names );
	}

	/**
	 * Update appointment status.
	 *
	 * @return array|WP_Error
	 */
	public function update_appointment_status( $id, $status ) {
		$allowed = [ 'confirmed', 'completed', 'cancelled', 'no_show' ];
		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'invalid_status', 'Invalid status.' );
		}
		return $this->supabase_request( 'PATCH', 'appointments?id=eq.' . intval( $id ), [ 'status' => $status ] );
	}

	/**
	 * Guarda el id del evento de Google (y su calendario) en una cita.
	 *
	 * @return array|WP_Error
	 */
	public function set_appointment_google_event( $id, $event_id, $calendar_id, $events = null ) {
		$patch = [
			'google_event_id'    => (string) $event_id,
			'google_calendar_id' => (string) $calendar_id,
		];
		if ( is_array( $events ) ) {
			$patch['google_events'] = wp_json_encode( $events );
		}
		return $this->supabase_request( 'PATCH', 'appointments?id=eq.' . intval( $id ), $patch );
	}

	/**
	 * Citas confirmadas y futuras que tienen evento de Google (para comprobar
	 * si han sido anuladas desde el calendario).
	 *
	 * @return array
	 */
	public function get_appointments_to_check() {
		$r = $this->supabase_request( 'GET', 'appointments', [], [
			'select'          => 'id,customer_name,customer_email,customer_phone,location,date,start_time,end_time,barber_id,google_event_id,google_calendar_id,google_events',
			'status'          => 'eq.confirmed',
			'date'            => 'gte.' . wp_date( 'Y-m-d' ),
			'google_event_id' => 'not.is.null',
			'order'           => 'date.asc',
		] );
		return is_wp_error( $r ) ? [] : (array) $r;
	}

	/**
	 * Busca una cita por su token de gestión (con servicios embebidos).
	 *
	 * @param string $token
	 * @return array|null
	 */
	public function get_appointment_by_token( $token ) {
		$token = trim( (string) $token );
		if ( $token === '' ) {
			return null;
		}
		$r = $this->supabase_request( 'GET', 'appointments', [], [
			'select'       => '*,appointment_services(service_id,services(name))',
			'manage_token' => 'eq.' . $token,
			'limit'        => 1,
		] );
		if ( is_wp_error( $r ) || empty( $r ) ) {
			return null;
		}
		$appt = $r[0];
		$appt['services_label'] = $this->build_services_label( $appt );
		return $appt;
	}

	/**
	 * IDs de servicio de una cita (desde los servicios embebidos).
	 *
	 * @param array $appointment
	 * @return array
	 */
	public function appointment_service_ids( $appointment ) {
		$ids = [];
		foreach ( (array) ( $appointment['appointment_services'] ?? [] ) as $as ) {
			if ( ! empty( $as['service_id'] ) ) {
				$ids[] = (int) $as['service_id'];
			}
		}
		return $ids;
	}

	/**
	 * Reprograma una cita a nueva fecha/hora (mismo barbero/servicios), validando
	 * que el hueco esté libre.
	 *
	 * @return array|WP_Error  [ 'end_time' => 'HH:MM' ] en éxito
	 */
	public function reschedule_appointment( $appointment, $new_date, $new_start ) {
		$service_ids = $this->appointment_service_ids( $appointment );
		if ( empty( $service_ids ) ) {
			return new WP_Error( 'no_services', 'La cita no tiene servicios.' );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new_date ) || ! preg_match( '/^\d{2}:\d{2}$/', $new_start ) ) {
			return new WP_Error( 'bad_input', 'Fecha u hora no válidas.' );
		}

		$location  = $appointment['location'] ?? '';
		$barber_id = (int) ( $appointment['barber_id'] ?? 0 );

		$slots = $this->get_available_slots( $location, $new_date, $service_ids, $barber_id );
		if ( is_wp_error( $slots ) ) {
			return $slots;
		}
		if ( ! in_array( $new_start, (array) $slots, true ) ) {
			return new WP_Error( 'slot_unavailable', 'Esa hora ya no está disponible.' );
		}

		$duration = $this->calculate_service_duration( $service_ids );
		$end      = $this->calculate_end_time( $new_start, $duration );

		$res = $this->supabase_request( 'PATCH', 'appointments?id=eq.' . intval( $appointment['id'] ), [
			'date'       => $new_date,
			'start_time' => $new_start,
			'end_time'   => $end,
		] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return [ 'end_time' => $end, 'duration' => $duration ];
	}

	// ── Public: Barber Days Off ───────────────────────────────────────────────

	/**
	 * Get days off for a barber in a given month.
	 *
	 * @param int    $barber_id Barber ID
	 * @param string $year_month 'YYYY-MM'
	 * @return array|WP_Error
	 */
	public function get_barber_days_off( $barber_id, $year_month ) {
		$from = $year_month . '-01';
		$to = $year_month . '-' . date( 't', strtotime( $from ) );
		return $this->supabase_request( 'GET', 'barber_days_off', [], [
			'barber_id' => 'eq.' . intval( $barber_id ),
			'and'       => '(date.gte.' . $from . ',date.lte.' . $to . ')',
			'select'    => 'id,date,note',
			'order'     => 'date.asc',
		] );
	}

	/**
	 * Toggle a day off for a barber (create or delete).
	 *
	 * @return array|WP_Error
	 */
	public function toggle_barber_day_off( $barber_id, $date, $note = '' ) {
		$existing = $this->supabase_request( 'GET', 'barber_days_off', [], [
			'barber_id' => 'eq.' . intval( $barber_id ),
			'date'      => 'eq.' . $date,
			'select'    => 'id',
		] );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		if ( ! empty( $existing ) ) {
			// Delete
			return $this->supabase_request( 'DELETE', 'barber_days_off', [], [
				'barber_id' => 'eq.' . intval( $barber_id ),
				'date'      => 'eq.' . $date,
			] );
		}

		// Create
		return $this->supabase_request( 'POST', 'barber_days_off', [
			'barber_id' => intval( $barber_id ),
			'date'      => $date,
			'note'      => $note,
		] );
	}

	// ── Public: Barber Status ─────────────────────────────────────────────────

	/**
	 * Get barber status (available, vacation, sick).
	 *
	 * @return array Key: barber_id, Value: status
	 */
	public function get_barber_statuses( $location = '' ) {
		$statuses = (array) get_option( 'six40_barber_statuses', [] );
		$barbers = $this->get_barbers( $location );

		if ( is_wp_error( $barbers ) ) {
			return $statuses;
		}

		$result = [];
		foreach ( $barbers as $b ) {
			$result[ $b['id'] ] = $statuses[ $b['id'] ] ?? 'available';
		}

		return $result;
	}

	/**
	 * Update barber status.
	 *
	 * @param int    $barber_id
	 * @param string $status 'available', 'vacation', or 'sick'
	 * @return bool
	 */
	public function update_barber_status( $barber_id, $status ) {
		$allowed = [ 'available', 'vacation', 'sick' ];
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		$statuses = (array) get_option( 'six40_barber_statuses', [] );
		$statuses[ $barber_id ] = $status;

		return (bool) update_option( 'six40_barber_statuses', $statuses );
	}

	// ── Private: Helpers ──────────────────────────────────────────────────────

	/**
	 * ¿Está cerrado ese día? (domingo o festivo configurado en el admin).
	 *
	 * @param string $date 'YYYY-MM-DD'
	 * @return bool
	 */
	private function is_closed_date( $date ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
			return true;
		}
		$dt = \DateTime::createFromFormat( 'Y-m-d', $date );
		if ( ! $dt ) {
			return true;
		}
		// Domingo cerrado (N: 1=lun … 7=dom).
		if ( (int) $dt->format( 'N' ) === 7 ) {
			return true;
		}
		$holidays = (array) get_option( 'six40_holidays', [] );
		return in_array( $date, $holidays, true );
	}

	/**
	 * Calcula la duración total según reglas por combinación:
	 *  - Barbas: 20 min c/u.
	 *  - Corte solo: su duración base (Corte/Niño 30, Rapado 20).
	 *  - Corte + cualquier barba → 40 min.
	 *  - Corte + fantasía → 60 min.
	 *  - Corte + reducción/iluminaciones → 30 min (corte base).
	 *  - Sin corte: suma de duraciones base de los servicios elegidos.
	 *
	 * @param array $service_ids Service IDs
	 * @return int Duración en minutos
	 */
	private function calculate_service_duration( $service_ids ) {
		if ( empty( $service_ids ) ) {
			return 0;
		}

		$has_corte = false;
		$corte_time = 0;
		$beards = 0;
		$has_fantasia = false;
		$sum_all = 0;

		foreach ( $service_ids as $svc_id ) {
			$s = $this->get_service( $svc_id );
			if ( ! $s ) {
				continue;
			}
			$cat  = $s['category'] ?? '';
			$name = strtolower( $s['name'] ?? '' );
			$dur  = (int) ( $s['duration'] ?? 0 );
			$sum_all += $dur;

			if ( $cat === 'corte' ) {
				$has_corte = true;
				$corte_time += $dur;
			} elseif ( $cat === 'barba' ) {
				$beards++;
			}
			if ( strpos( $name, 'fantasía' ) !== false ) {
				$has_fantasia = true;
			}
		}

		// Sin corte: suma simple de duraciones base.
		if ( ! $has_corte ) {
			return $sum_all;
		}

		// Con corte: reglas por combinación.
		$block = $corte_time;
		if ( $beards > 0 ) {
			$block = 40;                    // corte + cualquier barba
		} elseif ( $has_fantasia ) {
			$block = 60;                    // corte + fantasía
		}

		return $block;
	}

	/**
	 * Calculate end time given start time and duration.
	 *
	 * @param string $start_time 'HH:MM'
	 * @param int    $duration_mins Minutes
	 * @return string End time as 'HH:MM'
	 */
	private function calculate_end_time( $start_time, $duration_mins ) {
		$dt = \DateTime::createFromFormat( 'H:i', $start_time );
		if ( ! $dt ) {
			return $start_time;
		}
		$dt->modify( "+{$duration_mins} minutes" );
		return $dt->format( 'H:i' );
	}

	/**
	 * Get barber IDs that are off on a specific date.
	 *
	 * @param string $date 'YYYY-MM-DD'
	 * @return array Barber IDs
	 */
	private function get_barber_ids_off_on_date( $date ) {
		$ids = [];

		// Días libres puntuales (tabla barber_days_off).
		$result = $this->supabase_request( 'GET', 'barber_days_off', [], [
			'date'   => 'eq.' . $date,
			'select' => 'barber_id',
		] );
		if ( ! is_wp_error( $result ) ) {
			$ids = array_map( 'intval', array_column( (array) $result, 'barber_id' ) );
		}

		// Vacaciones programadas (opción WP six40_barber_vacations): rangos de fecha.
		$vac = (array) get_option( 'six40_barber_vacations', [] );
		foreach ( $vac as $bid => $ranges ) {
			foreach ( (array) $ranges as $r ) {
				$s = $r['start'] ?? '';
				$e = $r['end'] ?? '';
				if ( $s && $e && $date >= $s && $date <= $e ) {
					$ids[] = (int) $bid;
					break;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/** 'HH:MM' (o 'HH:MM:SS' de Supabase) → minutos desde medianoche. */
	private function time_to_mins( $t ) {
		$p = explode( ':', substr( (string) $t, 0, 5 ) );
		return ( (int) ( $p[0] ?? 0 ) ) * 60 + (int) ( $p[1] ?? 0 );
	}

	/** Minutos desde medianoche → 'HH:MM'. */
	private function mins_to_time( $m ) {
		return sprintf( '%02d:%02d', intdiv( (int) $m, 60 ), ( (int) $m ) % 60 );
	}

	/**
	 * Huecos de inicio válidos para un barbero, con precisión de minuto.
	 *
	 * Candidatos: la rejilla de 30 min desde el inicio de cada tramo MÁS el final
	 * de cada ocupación (para encadenar la siguiente cita justo cuando termina la
	 * anterior, p. ej. un corte+barba de 10:00–10:40 ofrece 10:40 al siguiente).
	 * Un candidato vale si la cita completa cabe en el tramo sin solapar nada.
	 *
	 * @param array $windows       Tramos [ ['start_time','end_time'], ... ]
	 * @param array $busy          Ocupación en minutos [ [ini, fin], ... ]
	 * @param int   $duration_mins Duración de la cita
	 * @return array Horas 'HH:MM' ordenadas
	 */
	private function slots_for_barber( $windows, $busy, $duration_mins ) {
		if ( $duration_mins <= 0 ) {
			return [];
		}
		$slots = [];

		foreach ( (array) $windows as $w ) {
			$ws = $this->time_to_mins( $w['start_time'] ?? '' );
			$we = $this->time_to_mins( $w['end_time'] ?? '' );
			if ( $we <= $ws ) {
				continue;
			}

			// Candidatos: rejilla + finales de ocupaciones dentro del tramo.
			$cands = [];
			for ( $t = $ws; $t + $duration_mins <= $we; $t += self::SLOT_MINS ) {
				$cands[] = $t;
			}
			foreach ( (array) $busy as $iv ) {
				$e = (int) ( $iv[1] ?? 0 );
				if ( $e >= $ws && $e + $duration_mins <= $we ) {
					$cands[] = $e;
				}
			}
			$cands = array_unique( $cands );
			sort( $cands );

			foreach ( $cands as $c ) {
				$free = true;
				foreach ( (array) $busy as $iv ) {
					// Solape de [c, c+dur) con [ini, fin)
					if ( $c < (int) $iv[1] && (int) $iv[0] < $c + $duration_mins ) {
						$free = false;
						break;
					}
				}
				if ( $free ) {
					$slots[] = $this->mins_to_time( $c );
				}
			}
		}

		$slots = array_values( array_unique( $slots ) );
		sort( $slots );
		return $slots;
	}

	/**
	 * Intervalos ocupados por citas: [barber_id => [ [ini_min, fin_min], ... ]].
	 *
	 * @param array $appointments
	 * @return array
	 */
	private function build_busy_intervals( $appointments ) {
		$busy = [];
		foreach ( (array) $appointments as $appt ) {
			$bid = (int) ( $appt['barber_id'] ?? 0 );
			$s   = $this->time_to_mins( $appt['start_time'] ?? '' );
			$e   = $this->time_to_mins( $appt['end_time'] ?? '' );
			if ( $bid && $e > $s ) {
				$busy[ $bid ][] = [ $s, $e ];
			}
		}
		return $busy;
	}

	/**
	 * Fusiona en el mapa de ocupación los eventos "busy" del Google Calendar de
	 * cada barbero (si están configurados y hay conexión con Google). Fail-safe:
	 * si no hay calendarios o Google no responde, no cambia nada.
	 *
	 * @param array  &$busy_map  Mapa [barber_id => [ [ini_min, fin_min], ... ]] (por referencia)
	 * @param array   $barbers   Barberos considerados
	 * @param string  $date      'YYYY-MM-DD'
	 */
	private function merge_google_busy_intervals( &$busy_map, $barbers, $date ) {
		$map = (array) get_option( 'six40_barber_calendars', [] );
		if ( empty( $map ) ) {
			return;
		}

		$calendar_ids  = [];
		$cal_to_barber = [];
		foreach ( (array) $barbers as $b ) {
			$bid = (int) ( $b['id'] ?? 0 );
			$cid = trim( (string) ( $map[ $bid ] ?? '' ) );
			if ( $bid && $cid !== '' ) {
				$calendar_ids[]        = $cid;
				$cal_to_barber[ $cid ] = $bid;
			}
		}
		if ( empty( $calendar_ids ) ) {
			return;
		}

		$busy = ( new Six40_Google_Calendar() )->get_busy( $calendar_ids, $date );
		if ( ! is_array( $busy ) || empty( $busy ) ) {
			return;
		}

		foreach ( $busy as $cid => $intervals ) {
			$bid = $cal_to_barber[ $cid ] ?? 0;
			if ( ! $bid ) {
				continue;
			}
			foreach ( (array) $intervals as $iv ) {
				$s = $this->time_to_mins( $iv[0] ?? '' );
				$e = $this->time_to_mins( $iv[1] ?? '' );
				if ( $e > $s ) {
					$busy_map[ $bid ][] = [ $s, $e ];
				}
			}
		}
	}

	/**
	 * Find an available barber for a time slot.
	 *
	 * @param string $location
	 * @param string $date 'YYYY-MM-DD'
	 * @param string $start_time 'HH:MM'
	 * @param array  $service_ids Service IDs to calculate duration
	 * @return int|null Barber ID or null
	 */
	private function find_free_barber( $location, $date, $start_time, $service_ids ) {
		$total_duration = $this->calculate_service_duration( $service_ids );

		// Get barbers
		$barbers = $this->get_barbers( $location );
		if ( is_wp_error( $barbers ) || empty( $barbers ) ) {
			return null;
		}

		// Get day of week
		$dt_date = \DateTime::createFromFormat( 'Y-m-d', $date );
		$day_of_week = ( (int) $dt_date->format( 'N' ) ) - 1; // 0=Lun … 6=Dom (coincide con barber_schedules)

		// Get barbers off
		$barbers_off = $this->get_barber_ids_off_on_date( $date );

		// Get statuses
		$statuses = $this->get_barber_statuses( $location );

		// Get appointments
		$appointments = $this->supabase_request( 'GET', 'appointments', [], [
			'location' => 'eq.' . $location,
			'date'     => 'eq.' . $date,
			'status'   => 'neq.cancelled',
			'select'   => 'id,barber_id,start_time,end_time',
		] );

		if ( is_wp_error( $appointments ) ) {
			return null;
		}

		$busy_map = $this->build_busy_intervals( $appointments ?? [] );
		$this->merge_google_busy_intervals( $busy_map, $barbers, $date );

		// Try each barber: se asigna al primero cuya lista de huecos (misma lógica
		// que get_available_slots) contiene la hora pedida. Así el barbero mostrado
		// en el resumen y el asignado coinciden siempre.
		foreach ( $barbers as $barber ) {
			// Mismas reglas que get_available_slots(): un cambio de horario
			// temporal permite trabajar aunque esté libre o de baja.
			$is_off = in_array( $barber['id'], $barbers_off, true )
				|| ( $statuses[ $barber['id'] ] ?? 'available' ) !== 'available';

			$regular_schedule = $this->get_barber_schedule( $barber['id'], $day_of_week );
			$schedule = $this->get_schedule_for_date( $barber['id'], $date, $regular_schedule, $is_off );
			if ( empty( $schedule ) ) {
				continue;
			}

			$slots = $this->slots_for_barber( $schedule, $busy_map[ (int) $barber['id'] ] ?? [], $total_duration );
			if ( in_array( $start_time, $slots, true ) ) {
				return $barber['id'];
			}
		}

		return null;
	}
}
