<?php
defined( 'ABSPATH' ) || exit;

/**
 * Emails transaccionales (confirmación y cancelación) vía wp_mail de WordPress.
 */
class Six40_Email {

    private function settings() {
        return (array) get_option( 'six40_settings', [] );
    }

    public function send_confirmation( $appointment ) {
        $email = $appointment['customer_email'] ?? '';
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', 'Invalid customer email.' );
        }

        $loc = six40_locations()[ $appointment['location'] ?? '' ] ?? [];

        // Build service string from services array
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

        $data = [
            'customer_name'  => $appointment['customer_name'] ?? '',
            'location_label'   => $loc['label'] ?? '',
            'location_address' => $loc['address'] ?? '',
            'location_maps'    => $loc['maps'] ?? '',
            'service_label'  => $service_label,
            'date_fmt'       => $this->format_date( $appointment['date'] ?? '' ),
            'time_fmt'       => substr( $appointment['start_time'] ?? '', 0, 5 ),
            'barber_name'    => is_array( $appointment['barber'] ?? null ) ? $appointment['barber']['name'] ?? '' : ( $appointment['barber_name'] ?? '' ),
            'duration'       => $appointment['duration'] ?? 0,
            'manage_url'     => ( ! empty( $appointment['manage_token'] ) && class_exists( 'Six40_Manage' ) ) ? Six40_Manage::url( $appointment['manage_token'] ) : '',
        ];

        return $this->send(
            $email,
            $data['customer_name'],
            'Confirmación de cita — Six40 Barbería',
            $this->tpl_confirmation( $data )
        );
    }

    public function send_cancellation( $appointment ) {
        $email = $appointment['customer_email'] ?? '';
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', 'Invalid customer email.' );
        }
        return $this->send(
            $email,
            $appointment['customer_name'] ?? '',
            'Tu cita ha sido cancelada — Six40 Barbería',
            $this->tpl_cancellation( $appointment )
        );
    }

    // ── Envío vía WordPress (wp_mail) ────────────────────────────────────────────

    private function send( $to, $name, $subject, $html ) {
        $cfg = $this->settings();

        $headers    = [ 'Content-Type: text/html; charset=UTF-8' ];
        $from_email = trim( (string) ( $cfg['email_from'] ?? '' ) );
        if ( $from_email && is_email( $from_email ) ) {
            $from_name = $cfg['email_from_name'] ?? 'Six40 Barbería';
            $headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
        }

        $sent = wp_mail( $to, $subject, $html, $headers );

        return $sent ? true : new WP_Error( 'mail_failed', 'wp_mail no pudo enviar el correo.' );
    }

    // ── Templates ─────────────────────────────────────────────────────────────

    private function tpl_confirmation( $d ) {
        ob_start(); ?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:'Helvetica Neue',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">
      <tr><td style="background:#000000;padding:32px 40px;text-align:center;">
        <h1 style="color:#b11a2d;margin:0;font-size:28px;letter-spacing:3px;">SIX40</h1>
        <p style="color:#888;margin:4px 0 0;font-size:12px;letter-spacing:1px;">BARBERÍA · MÁLAGA &amp; TORREMOLINOS</p>
      </td></tr>
      <tr><td style="padding:40px;">
        <h2 style="color:#000000;font-size:22px;margin:0 0 8px;">¡Cita confirmada! ✂️</h2>
        <p style="color:#555;font-size:15px;line-height:1.6;margin:0 0 28px;">
          Hola <strong><?= esc_html( $d['customer_name'] ) ?></strong>, tu reserva está confirmada.
        </p>
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9f9f9;border-radius:6px;border-left:4px solid #b11a2d;">
          <tr><td style="padding:24px 28px;">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td valign="top" style="padding:7px 0;color:#888;font-size:13px;width:130px;">📍 Local</td>
                <td valign="top" style="padding:7px 0;color:#000000;font-size:15px;font-weight:700;">
                  <?= esc_html( $d['location_label'] ) ?>
                  <?php if ( $d['location_address'] && $d['location_maps'] ) : ?>
                  <br><a href="<?= esc_url( $d['location_maps'] ) ?>" style="color:#555555;font-size:13px;font-weight:400;text-decoration:underline;"><?= esc_html( $d['location_address'] ) ?></a>
                  <?php elseif ( $d['location_address'] ) : ?>
                  <br><span style="color:#555555;font-size:13px;font-weight:400;"><?= esc_html( $d['location_address'] ) ?></span>
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <td style="padding:7px 0;color:#888;font-size:13px;">✂️ Servicio(s)</td>
                <td style="padding:7px 0;color:#000000;font-size:15px;font-weight:700;"><?= esc_html( $d['service_label'] ) ?></td>
              </tr>
              <tr>
                <td style="padding:7px 0;color:#888;font-size:13px;">⏱️ Duración</td>
                <td style="padding:7px 0;color:#000000;font-size:15px;font-weight:700;"><?= esc_html( $d['duration'] . ' min' ) ?></td>
              </tr>
              <tr>
                <td style="padding:7px 0;color:#888;font-size:13px;">📅 Fecha</td>
                <td style="padding:7px 0;color:#000000;font-size:15px;font-weight:700;"><?= esc_html( $d['date_fmt'] ) ?></td>
              </tr>
              <tr>
                <td style="padding:7px 0;color:#888;font-size:13px;">🕐 Hora</td>
                <td style="padding:7px 0;color:#000000;font-size:15px;font-weight:700;"><?= esc_html( $d['time_fmt'] ) ?></td>
              </tr>
              <?php if ( $d['barber_name'] ) : ?>
              <tr>
                <td style="padding:7px 0;color:#888;font-size:13px;">💈 Barbero/a</td>
                <td style="padding:7px 0;color:#000000;font-size:15px;font-weight:700;"><?= esc_html( $d['barber_name'] ) ?></td>
              </tr>
              <?php endif; ?>
            </table>
          </td></tr>
        </table>
        <?php if ( ! empty( $d['manage_url'] ) ) : ?>
        <table width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0 0;"><tr><td align="center">
          <a href="<?= esc_url( $d['manage_url'] ) ?>" style="display:inline-block;background:#b11a2d;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 24px;border-radius:8px;">Cancelar o cambiar tu cita</a>
        </td></tr></table>
        <?php else : ?>
        <p style="color:#aaa;font-size:13px;margin:28px 0 0;line-height:1.6;">
          ¿Necesitas cancelar o cambiar tu cita? Responde a este email y te ayudamos.
        </p>
        <?php endif; ?>
      </td></tr>
      <tr><td style="background:#000000;padding:20px 40px;text-align:center;">
        <p style="color:#555;font-size:12px;margin:0;">© <?= date('Y') ?> Six40 Barbería · Málaga &amp; Torremolinos</p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
        <?php return ob_get_clean();
    }

    private function tpl_cancellation( $a ) {
        $name = $a['customer_name'] ?? 'Cliente';
        ob_start(); ?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:40px;background:#f5f5f5;font-family:Arial,sans-serif;">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;">
  <div style="background:#000000;padding:32px;text-align:center;">
    <h1 style="color:#b11a2d;margin:0;font-size:24px;letter-spacing:3px;">SIX40</h1>
  </div>
  <div style="padding:40px;">
    <h2 style="color:#000000;">Tu cita ha sido cancelada</h2>
    <p style="color:#555;line-height:1.6;">
      Hola <strong><?= esc_html( $name ) ?></strong>, confirmamos que tu cita ha sido cancelada.
    </p>
    <p style="color:#555;line-height:1.6;">
      Puedes reservar una nueva cita en
      <a href="<?= esc_url( home_url( '/reservar/' ) ) ?>" style="color:#b11a2d;"><?= esc_html( wp_parse_url( home_url( '/reservar/' ), PHP_URL_HOST ) ) ?></a>.
    </p>
  </div>
  <div style="background:#000000;padding:20px;text-align:center;">
    <p style="color:#555;font-size:12px;margin:0;">© <?= date('Y') ?> Six40 Barbería</p>
  </div>
</div>
</body></html>
        <?php return ob_get_clean();
    }

    private function format_date( $date ) {
        if ( ! $date ) return '';
        $months = [ '01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril','05'=>'mayo','06'=>'junio',
                    '07'=>'julio','08'=>'agosto','09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre' ];
        $days   = [ 'Monday'=>'lunes','Tuesday'=>'martes','Wednesday'=>'miércoles',
                    'Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sábado','Sunday'=>'domingo' ];
        $dt = \DateTime::createFromFormat( 'Y-m-d', $date );
        if ( ! $dt ) return $date;
        return ucfirst( ( $days[ $dt->format('l') ] ?? '' ) . ', ' . $dt->format('j') . ' de ' . ( $months[ $dt->format('m') ] ?? '' ) . ' de ' . $dt->format('Y') );
    }
}
