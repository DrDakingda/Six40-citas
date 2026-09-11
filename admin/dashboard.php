<?php
defined( 'ABSPATH' ) || exit;
$page = sanitize_text_field( $_GET['page'] ?? 'six40-citas' );
?>
<div class="wrap six40-admin">
  <div class="six40-admin-header">
    <div class="six40-logo">
      <span class="six40-logo-text">SIX40</span>
      <span class="six40-logo-sub">BARBERÍA · Sistema de citas</span>
    </div>
    <nav class="six40-nav">
      <a href="<?= esc_url( admin_url('admin.php?page=six40-citas') ) ?>" class="<?= $page==='six40-citas'?'active':'' ?>">
        <span class="dashicons dashicons-calendar-alt"></span> Citas
      </a>
      <a href="<?= esc_url( admin_url('admin.php?page=six40-barberos') ) ?>" class="<?= $page==='six40-barberos'?'active':'' ?>">
        <span class="dashicons dashicons-groups"></span> Barberos
      </a>
      <a href="<?= esc_url( admin_url('admin.php?page=six40-settings') ) ?>" class="<?= $page==='six40-settings'?'active':'' ?>">
        <span class="dashicons dashicons-admin-settings"></span> Configuración
      </a>
    </nav>
  </div>

  <div class="six40-admin-content">
  <?php switch ( $page ) :

    // ── CITAS ────────────────────────────────────────────────────────────────
    case 'six40-citas': default:
      $appointments_list = $appointments ?? [];
      $api = new Six40_Booking_API();
      $all_barbers = $api->get_barbers();
      $barbers_map = [];
      if ( !is_wp_error( $all_barbers ) ) {
        foreach ( $all_barbers as $b ) {
          $barbers_map[ $b['id'] ] = $b['name'];
        }
      }
      $statusLabels = ['confirmed'=>'Confirmada','completed'=>'Completada','cancelled'=>'Cancelada','no_show'=>'No presentó'];
    ?>
    <h1 class="six40-page-title">Historial de Citas</h1>
    <div class="six40-filters-bar">
      <form method="GET">
        <input type="hidden" name="page" value="six40-citas">
        <select name="location">
          <option value="">Todos los locales</option>
          <option value="malaga"       <?= selected($location??'','malaga',false) ?>>Málaga</option>
          <option value="torremolinos" <?= selected($location??'','torremolinos',false) ?>>Torremolinos</option>
        </select>
        <input type="date" name="date_from" value="<?= esc_attr($date_from??'') ?>">
        <select name="status">
          <option value="">Todos los estados</option>
          <?php foreach ($statusLabels as $k=>$v): ?>
          <option value="<?= $k ?>" <?= selected($status_f??'',$k,false) ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="six40-btn">Filtrar</button>
        <a href="<?= esc_url(admin_url('admin.php?page=six40-citas')) ?>" class="six40-btn six40-btn-outline">Limpiar</a>
      </form>
    </div>
    <div class="six40-panel">
      <?php if ( empty($appointments_list) ) : ?>
        <div class="six40-empty">No se encontraron citas.</div>
      <?php else : ?>
        <table class="six40-table">
          <thead><tr><th>#</th><th>Fecha</th><th>Hora</th><th>Cliente</th><th>Servicio</th><th>Local</th><th>Barbero/a</th><th>Estado</th><th>Acción</th></tr></thead>
          <tbody>
          <?php foreach ( $appointments_list as $appt ) :
            $status = $appt['status'] ?? 'confirmed';
          ?>
          <tr>
            <td><?= esc_html($appt['id']??'') ?></td>
            <td><?= esc_html(date_i18n('d/m/Y',strtotime($appt['date']??''))) ?></td>
            <td><?= esc_html(substr($appt['start_time']??'',0,5)) ?></td>
            <td>
              <?= esc_html($appt['customer_name']??'—') ?>
              <small><?= esc_html($appt['customer_email']??'') ?></small>
            </td>
            <td><?= ($appt['services_label']??'') !== '' ? esc_html($appt['services_label']) : '—' ?></td>
            <td><?= $appt['location']==='malaga'?'Málaga':'Torremolinos' ?></td>
            <td><?= esc_html($barbers_map[(int)($appt['barber_id']??0)]??'—') ?></td>
            <td><span class="six40-status six40-status--<?= esc_attr($status) ?>"><?= esc_html($statusLabels[$status]??ucfirst($status)) ?></span></td>
            <td>
              <select class="six40-status-select" data-id="<?= esc_attr($appt['id']??'') ?>">
                <?php foreach ($statusLabels as $k=>$v): ?>
                <option value="<?= $k ?>" <?= selected($status,$k,false) ?>><?= $v ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php break;

    // ── BARBEROS ─────────────────────────────────────────────────────────────
    case 'six40-barberos':
      $api_barbers = new Six40_Booking_API();
      $statuses_all = $statuses ?? $api_barbers->get_barber_statuses();
      $barbers_list_raw = $api_barbers->get_barbers();
      $barbers_list = is_wp_error($barbers_list_raw) ? [] : $barbers_list_raw;
      $status_labels = ['available'=>'Disponible','vacation'=>'Vacaciones','sick'=>'Baja'];
      $btn_labels    = ['available'=>'✅ Disponible','vacation'=>'🏖️ Vacaciones','sick'=>'🤒 Baja'];
      $locations     = ['malaga'=>'Málaga','torremolinos'=>'Torremolinos'];
      $vacations_all = (array) get_option('six40_barber_vacations', []);
      $schedule_exceptions_all = (array) get_option('six40_schedule_exceptions', []);
      // Horarios: mapa [barber_id][day_of_week] = [ [id,start,end], ... ]
      $schedules_raw = $api_barbers->get_all_barber_schedules();
      $sched_map = [];
      foreach ($schedules_raw as $row) {
        $sbid = (int)($row['barber_id'] ?? 0);
        $sdow = (int)($row['day_of_week'] ?? 0);
        $sched_map[$sbid][$sdow][] = [
          'id'    => $row['id'] ?? '',
          'start' => substr($row['start_time'] ?? '', 0, 5),
          'end'   => substr($row['end_time'] ?? '', 0, 5),
        ];
      }
      $day_names = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo']; // 0..6
    ?>
    <h1 class="six40-page-title">Gestión de Barberos</h1>
    <p class="six40-page-subtitle">Los cambios se aplican al instante. Los clientes no podrán reservar con un barbero en Vacaciones, Baja, ni en las fechas de vacaciones programadas.</p>
    <?php foreach ( $locations as $loc_key => $loc_name ) : ?>
    <div class="six40-panel">
      <div class="six40-panel-header"><h2>📍 <?= esc_html($loc_name) ?></h2></div>
      <div class="six40-barbers-grid">
        <?php foreach ( $barbers_list as $b ) :
          if ( $b['location'] !== $loc_key ) continue;
          $s = $statuses_all[$b['id']] ?? 'available';
        ?>
        <div class="six40-barber-card six40-barber--<?= esc_attr($s) ?>">
          <div class="six40-barber-avatar"><?= strtoupper(substr($b['name'],0,1)) ?></div>
          <div class="six40-barber-info">
            <strong><?= esc_html($b['name']) ?></strong>
            <span class="six40-status six40-status--<?= esc_attr($s) ?> six40-barber-status-label">
              <?= esc_html($status_labels[$s]??ucfirst($s)) ?>
            </span>
          </div>
          <div class="six40-barber-controls">
            <?php foreach ($btn_labels as $k=>$v) : ?>
            <button class="six40-barber-btn <?= $s===$k?'active':'' ?>"
                    data-id="<?= esc_attr($b['id']) ?>" data-status="<?= $k ?>">
              <?= $v ?>
            </button>
            <?php endforeach; ?>
          </div>
          <div class="six40-vac">
            <div class="six40-vac-title">🏖️ Vacaciones programadas</div>
            <div class="six40-vac-list">
              <?php $branges = $vacations_all[$b['id']] ?? []; ?>
              <?php if (empty($branges)): ?>
                <span class="six40-vac-empty">Ninguna</span>
              <?php else: foreach ($branges as $i=>$r):
                $fs = date_i18n('d/m/Y', strtotime($r['start']??''));
                $fe = date_i18n('d/m/Y', strtotime($r['end']??''));
              ?>
                <span class="six40-vac-item"><?= esc_html("$fs → $fe") ?>
                  <button class="six40-vac-del" data-id="<?= esc_attr($b['id']) ?>" data-index="<?= (int)$i ?>" title="Eliminar">✕</button>
                </span>
              <?php endforeach; endif; ?>
            </div>
            <div class="six40-vac-add">
              <input type="date" class="six40-vac-from" aria-label="Desde">
              <input type="date" class="six40-vac-to" aria-label="Hasta">
              <button class="six40-vac-add-btn" data-id="<?= esc_attr($b['id']) ?>">Añadir</button>
            </div>
          </div>
          <div class="six40-exc">
            <div class="six40-exc-title">📅 Cambios de horario temporales</div>
            <div class="six40-exc-list">
              <?php $excs = $schedule_exceptions_all[$b['id']] ?? []; ?>
              <?php if (empty($excs)): ?>
                <span class="six40-exc-empty">Ninguno</span>
              <?php else: foreach ($excs as $i=>$e):
                $fs = date_i18n('d/m/Y', strtotime($e['start']??''));
                $fe = date_i18n('d/m/Y', strtotime($e['end']??''));
                $hs = substr($e['start_time']??'',0,5);
                $he = substr($e['end_time']??'',0,5);
                $type = $e['type'] ?? 'available';
                $type_label = $type === 'unavailable' ? '❌ No disponible' : '✅ Disponible';
              ?>
                <span class="six40-exc-item"><?= esc_html("$type_label: $fs → $fe: $hs–$he") ?>
                  <button class="six40-exc-del" data-id="<?= esc_attr($b['id']) ?>" data-index="<?= (int)$i ?>" title="Eliminar">✕</button>
                </span>
              <?php endforeach; endif; ?>
            </div>
            <div class="six40-exc-add">
              <select class="six40-exc-type" aria-label="Tipo" title="Tipo de cambio">
                <option value="available">✅ Disponible</option>
                <option value="unavailable">❌ No disponible</option>
              </select>
              <input type="date" class="six40-exc-from" aria-label="Desde" title="Desde">
              <input type="date" class="six40-exc-to" aria-label="Hasta" title="Hasta">
              <input type="text" class="six40-exc-start-time" aria-label="Hora inicio" placeholder="16:00" pattern="\d{2}:\d{2}" title="HH:MM">
              <input type="text" class="six40-exc-end-time" aria-label="Hora fin" placeholder="20:00" pattern="\d{2}:\d{2}" title="HH:MM">
              <button class="six40-exc-add-btn" data-id="<?= esc_attr($b['id']) ?>">Añadir</button>
            </div>
          </div>
          <button type="button" class="six40-sched-toggle" data-id="<?= esc_attr($b['id']) ?>">🕐 Editar horario</button>
        </div>
        <?php endforeach; ?>
      </div>

      <?php foreach ( $barbers_list as $b ) :
        if ( $b['location'] !== $loc_key ) continue;
        $bid = (int) $b['id'];
      ?>
      <div class="six40-sched-panel" id="sched-<?= $bid ?>" style="display:none;">
        <div class="six40-sched-head">
          <strong>🕐 Horario · <?= esc_html($b['name']) ?></strong>
          <button class="six40-sched-close" data-id="<?= $bid ?>">✕ Cerrar</button>
        </div>
        <div class="six40-sched-days">
          <?php foreach ($day_names as $dow => $dname): $tramos = $sched_map[$bid][$dow] ?? []; ?>
          <div class="six40-sched-day">
            <span class="six40-sched-day-name"><?= esc_html($dname) ?></span>
            <span class="six40-sched-tramos">
              <?php if (empty($tramos)): ?><span class="six40-sched-empty">Cerrado</span>
              <?php else: foreach ($tramos as $t): ?>
                <span class="six40-sched-chip"><?= esc_html($t['start'].'–'.$t['end']) ?>
                  <button class="six40-sched-del" data-id="<?= esc_attr($t['id']) ?>" title="Eliminar">✕</button>
                </span>
              <?php endforeach; endif; ?>
            </span>
            <span class="six40-sched-add">
              <input type="time" class="six40-sched-from" aria-label="Desde">
              <input type="time" class="six40-sched-to" aria-label="Hasta">
              <button class="six40-sched-add-btn" data-id="<?= $bid ?>" data-day="<?= $dow ?>">+ Añadir</button>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; break;

    // ── CONFIGURACIÓN ────────────────────────────────────────────────────────
    case 'six40-settings':
      $cfg_data = (array) get_option('six40_settings',[]);
    ?>
    <h1 class="six40-page-title">Configuración</h1>
    <?php if (isset($_GET['saved'])): ?><div class="notice notice-success is-dismissible"><p>✅ Configuración guardada.</p></div><?php endif; ?>
    <?php if (isset($_GET['oauth_success'])): ?><div class="notice notice-success is-dismissible"><p>✅ Google Calendar conectado.</p></div><?php endif; ?>
    <?php if (isset($_GET['oauth_error'])): ?><div class="notice notice-error is-dismissible"><p>❌ Error al conectar Google Calendar. Revisa las credenciales.</p></div><?php endif; ?>

    <form method="POST" action="<?= esc_url(admin_url('admin-post.php')) ?>">
      <?php wp_nonce_field('six40_save_settings'); ?>
      <input type="hidden" name="action" value="six40_save_settings">

      <div class="six40-panel">
        <div class="six40-panel-header"><h2>🗄️ Supabase</h2></div>
        <table class="form-table">
          <tr><th><label for="supabase_url">Project URL</label></th>
            <td><input type="url" id="supabase_url" name="supabase_url" class="regular-text"
                value="<?= esc_attr($cfg_data['supabase_url']??'') ?>" placeholder="https://xxxx.supabase.co">
              <p class="description">URL de tu proyecto Supabase (Settings → API).</p></td></tr>
          <tr><th><label for="supabase_key">Service Role Key</label></th>
            <td><input type="password" id="supabase_key" name="supabase_key" class="regular-text"
                value="<?= esc_attr($cfg_data['supabase_key']??'') ?>">
              <p class="description">Clave <em>service_role</em> — nunca la clave <em>anon</em>.</p></td></tr>
        </table>
      </div>

      <div class="six40-panel">
        <div class="six40-panel-header"><h2>📅 Google Calendar</h2></div>
        <table class="form-table">
          <tr><th><label for="google_client_id">Client ID</label></th>
            <td><input type="text" id="google_client_id" name="google_client_id" class="regular-text"
                value="<?= esc_attr($cfg_data['google_client_id']??'') ?>"></td></tr>
          <tr><th><label for="google_client_secret">Client Secret</label></th>
            <td><input type="password" id="google_client_secret" name="google_client_secret" class="regular-text"
                value="<?= esc_attr($cfg_data['google_client_secret']??'') ?>"></td></tr>
          <tr><th><label for="google_calendar_malaga">Calendar ID · Málaga</label></th>
            <td><input type="text" id="google_calendar_malaga" name="google_calendar_malaga" class="regular-text"
                value="<?= esc_attr($cfg_data['google_calendar_malaga']??'') ?>" placeholder="xxxx@group.calendar.google.com"></td></tr>
          <tr><th><label for="google_calendar_torremolinos">Calendar ID · Torremolinos</label></th>
            <td><input type="text" id="google_calendar_torremolinos" name="google_calendar_torremolinos" class="regular-text"
                value="<?= esc_attr($cfg_data['google_calendar_torremolinos']??'') ?>" placeholder="xxxx@group.calendar.google.com"></td></tr>
          <tr><th>Autorización OAuth2</th>
            <td>
              <?php if ($has_token??false): ?>
                <span class="six40-status six40-status--confirmed">✅ Conectado</span>
                <a href="<?= esc_url($auth_url??'#') ?>" class="six40-btn six40-btn-sm six40-btn-outline" style="margin-left:12px;">Reconectar</a>
              <?php else: ?>
                <a href="<?= esc_url($auth_url??'#') ?>" class="six40-btn six40-btn-sm">Conectar con Google</a>
                <p class="description">Guarda primero Client ID y Secret, luego conecta.</p>
              <?php endif; ?>
            </td></tr>
        </table>
      </div>

      <div class="six40-panel">
        <div class="six40-panel-header"><h2>💈 Calendarios por barbero</h2></div>
        <table class="form-table">
          <?php
            $bc_map     = (array) get_option('six40_barber_calendars', []);
            $bc_api     = new Six40_Booking_API();
            $bc_barbers = $bc_api->get_barbers();
            if (is_wp_error($bc_barbers)) $bc_barbers = [];
            $bc_loc = ['malaga'=>'Málaga','torremolinos'=>'Torremolinos'];
            foreach ($bc_barbers as $bb):
              $bid = (int)($bb['id'] ?? 0);
          ?>
          <tr>
            <th><label for="bc_<?= $bid ?>"><?= esc_html($bb['name'] ?? ('#'.$bid)) ?>
              <small style="color:#888;">· <?= esc_html($bc_loc[$bb['location']??'']??'') ?></small></label></th>
            <td><input type="text" id="bc_<?= $bid ?>" name="barber_calendar[<?= $bid ?>]" class="regular-text"
                value="<?= esc_attr($bc_map[$bid] ?? '') ?>" placeholder="xxxx@group.calendar.google.com"></td></tr>
          <?php endforeach; ?>
        </table>
        <p class="description" style="padding:0 12px 12px;">ID del Google Calendar de cada barbero. Con esto, el sistema lee su disponibilidad (no ofrece horas ya ocupadas en su agenda) y crea las citas en su propio calendario.</p>
      </div>

      <div class="six40-panel">
        <div class="six40-panel-header"><h2>📧 Email</h2></div>
        <table class="form-table">
          <tr><th><label for="email_from_name">Nombre remitente</label></th>
            <td><input type="text" id="email_from_name" name="email_from_name" class="regular-text"
                value="<?= esc_attr($cfg_data['email_from_name']??'Six40 Barbería') ?>"></td></tr>
          <tr><th><label for="email_from">Email remitente</label></th>
            <td><input type="email" id="email_from" name="email_from" class="regular-text"
                value="<?= esc_attr($cfg_data['email_from']??'') ?>" placeholder="noreply@tudominio.com">
              <p class="description">Los correos se envían con el email nativo de WordPress (wp_mail). Si el remitente está vacío, se usa el de WordPress por defecto.</p></td></tr>
        </table>
      </div>

      <div class="six40-panel">
        <div class="six40-panel-header"><h2>🚫 Días cerrados (festivos)</h2></div>
        <table class="form-table">
          <tr><th><label for="holidays">Festivos</label></th>
            <td>
              <?php $holidays = (array) get_option('six40_holidays', []); ?>
              <textarea id="holidays" name="holidays" rows="6" class="large-text code"
                        placeholder="2026-01-01&#10;2026-08-15&#10;2026-12-25"><?= esc_textarea( implode( "\n", $holidays ) ) ?></textarea>
              <p class="description">Una fecha por línea, formato <code>AAAA-MM-DD</code>. Esos días no se podrán reservar (los domingos ya se cierran automáticamente).</p>
            </td></tr>
        </table>
      </div>

      <?php submit_button('Guardar configuración','primary large'); ?>
    </form>
    <?php break; endswitch; ?>
  </div>
</div>
