<?php
if (!defined('ABSPATH')) { exit; }

add_action('admin_menu', function() {
    add_menu_page(
        'Research Scheduler',
        'Research Scheduler',
        'manage_options',
        'rsdl',
        'rsdl_admin_page',
        'dashicons-calendar-alt',
        56
    );

    add_submenu_page(
        'rsdl',
        'Statistics',
        'Statistics',
        'manage_options',
        'rsdl_stats',
        'rsdl_admin_stats_page'
    );


    add_submenu_page(
        'rsdl',
        'Help',
        'Help',
        'manage_options',
        'rsdl_help',
        'rsdl_admin_help_page'
    );

    add_submenu_page(
        'rsdl',
        'Settings',
        'Settings',
        'manage_options',
        'rsdl_settings',
        'rsdl_admin_settings_page'
    );
});

function rsdl_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('No autorizado.');
    }

    global $wpdb;
    $meetings = rsdl_table('rsdl_meetings');
    $slots    = rsdl_table('rsdl_slots');
    $votes    = rsdl_table('rsdl_votes');

    // Handle actions
    if (!empty($_GET['rsdl_action']) && !empty($_GET['meeting_id'])) {
        $action = sanitize_text_field(wp_unslash($_GET['rsdl_action']));
        $meeting_id = intval($_GET['meeting_id']);
        check_admin_referer('rsdl_admin_action_' . $meeting_id);

        if ($action === 'close') {
            rsdl_close_meeting($meeting_id);
            echo '<div class="notice notice-success"><p>Encuesta cerrada.</p></div>';
        } elseif ($action === 'open') {
            rsdl_open_meeting($meeting_id);
            echo '<div class="notice notice-success"><p>Encuesta reabierta.</p></div>';
        } elseif ($action === 'delete') {
            $wpdb->query($wpdb->prepare("DELETE FROM $votes WHERE meeting_id = %d", $meeting_id));
            $wpdb->query($wpdb->prepare("DELETE FROM $slots WHERE meeting_id = %d", $meeting_id));
            $wpdb->query($wpdb->prepare("DELETE FROM $meetings WHERE id = %d", $meeting_id));
            echo '<div class="notice notice-success"><p>Encuesta eliminada.</p></div>';
        }
    }

    $rows = $wpdb->get_results("SELECT * FROM $meetings ORDER BY created_at DESC LIMIT 200");

    echo '<div class="wrap"><h1>Research Scheduler</h1>';
    echo '<p>Shortcodes: <code>[rs_create_meeting]</code> (crear) y <code>[rs_meeting_poll]</code> (votación).</p>';

    echo '<table class="widefat fixed striped"><thead><tr>
        <th>ID</th><th>Título</th><th>Estado</th><th>Creado</th><th>Deadline</th><th>Votos</th><th>Enlace</th><th>Acciones</th>
    </tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="8">No hay encuestas.</td></tr>';
    } else {
        foreach ($rows as $m) {
            $vote_count = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $votes WHERE meeting_id = %d", intval($m->id))));
            $link = esc_url(rsdl_meeting_link($m->token));
            $created = esc_html($m->created_at);
            $deadline = !empty($m->deadline) ? esc_html($m->deadline) : '—';

            $actions = array();
            if ($m->status === 'open') {
                $url = wp_nonce_url(admin_url('admin.php?page=rsdl&rsdl_action=close&meeting_id=' . intval($m->id)), 'rsdl_admin_action_' . intval($m->id));
                $actions[] = '<a href="' . esc_url($url) . '">Cerrar</a>';
            } else {
                $url = wp_nonce_url(admin_url('admin.php?page=rsdl&rsdl_action=open&meeting_id=' . intval($m->id)), 'rsdl_admin_action_' . intval($m->id));
                $actions[] = '<a href="' . esc_url($url) . '">Reabrir</a>';
            }
            $url_del = wp_nonce_url(admin_url('admin.php?page=rsdl&rsdl_action=delete&meeting_id=' . intval($m->id)), 'rsdl_admin_action_' . intval($m->id));
            $actions[] = '<a href="' . esc_url($url_del) . '" onclick="return confirm(\'¿Eliminar esta encuesta?\')">Eliminar</a>';

            echo '<tr>
                <td>' . intval($m->id) . '</td>
                <td>' . esc_html($m->title) . '</td>
                <td>' . esc_html($m->status) . '</td>
                <td>' . $created . '</td>
                <td>' . $deadline . '</td>
                <td>' . $vote_count . '</td>
                <td><a href="' . $link . '" target="_blank" rel="noopener">Abrir</a></td>
                <td>' . implode(' | ', $actions) . '</td>
            </tr>';
        }
    }

    echo '</tbody></table></div>';
}

function rsdl_admin_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('No autorizado.');
    }

    if (!empty($_POST['rsdl_save_settings'])) {
        check_admin_referer('rsdl_save_settings');
        $poll_page_id = intval($_POST['rsdl_poll_page_id'] ?? 0);
        $notify_creator = !empty($_POST['rsdl_notify_creator']) ? 1 : 0;
        update_option('rsdl_poll_page_id', $poll_page_id);
        update_option('rsdl_notify_creator', $notify_creator);
        echo '<div class="notice notice-success"><p>Settings guardados.</p></div>';
    }

    $poll_page_id = intval(get_option('rsdl_poll_page_id', 0));
    $notify_creator = intval(get_option('rsdl_notify_creator', 1));
    $pages = get_pages(array('sort_column' => 'post_title', 'sort_order' => 'asc'));

    echo '<div class="wrap"><h1>Settings</h1>';
    echo '<form method="post">';
    wp_nonce_field('rsdl_save_settings');

    echo '<table class="form-table"><tr>
        <th scope="row"><label for="rsdl_poll_page_id">Página de votación</label></th>
        <td><select name="rsdl_poll_page_id" id="rsdl_poll_page_id">
            <option value="0">— (usar /meeting-poll/ por defecto)</option>';

    foreach ($pages as $p) {
        echo '<option value="' . intval($p->ID) . '"' . selected($poll_page_id, $p->ID, false) . '>' . esc_html($p->post_title) . '</option>';
    }

    echo '</select>
        <p class="description">Crea una página que contenga <code>[rs_meeting_poll]</code> y selecciónala aquí para generar enlaces correctos.</p>
        </td></tr>
        <tr>
          <th scope="row">Notificaciones</th>
          <td>
            <label><input type="checkbox" name="rsdl_notify_creator" value="1"' . checked($notify_creator, 1, false) . '> Enviar email al creador cuando alguien vote</label>
            <p class="description">Resumen del voto al email del creador (máx. 1 correo cada 5 min por votante).</p>
          </td>
        </tr>
      </table>';

    echo '<p><button class="button button-primary" type="submit" name="rsdl_save_settings" value="1">Guardar</button></p>';
    echo '</form></div>';
}



function rsdl_admin_stats_page() {
    if (!current_user_can('manage_options')) {
        wp_die('No autorizado.');
    }

    global $wpdb;
    $meetings = rsdl_table('rsdl_meetings');
    $slots    = rsdl_table('rsdl_slots');
    $votes    = rsdl_table('rsdl_votes');

    $meeting_id = intval($_GET['meeting_id'] ?? 0);

    $all = $wpdb->get_results("SELECT id, title, status, created_at FROM $meetings ORDER BY created_at DESC LIMIT 300");

    echo '<div class="wrap"><h1>Statistics</h1>';
    echo '<form method="get" style="margin:12px 0 18px 0;">';
    echo '<input type="hidden" name="page" value="rsdl_stats"/>';
    echo '<label for="meeting_id"><strong>Encuesta:</strong></label> ';
    echo '<select name="meeting_id" id="meeting_id" style="min-width:380px;max-width:100%;">';
    echo '<option value="0">— Selecciona una encuesta —</option>';
    foreach ($all as $m) {
        $label = '#' . intval($m->id) . ' · ' . wp_strip_all_tags($m->title) . ' (' . esc_html($m->status) . ')';
        $sel = selected($meeting_id, intval($m->id), false);
        echo '<option value="' . intval($m->id) . '"' . $sel . '>' . esc_html($label) . '</option>';
    }
    echo '</select> ';
    echo '<button class="button button-primary" type="submit">Ver</button>';
    echo '</form>';

    if ($meeting_id <= 0) {
        echo '<p>Selecciona una encuesta para ver los gráficos.</p></div>';
        return;
    }

    $meeting = $wpdb->get_row($wpdb->prepare("SELECT * FROM $meetings WHERE id = %d", $meeting_id));
    if (!$meeting) {
        echo '<div class="notice notice-error"><p>Encuesta no encontrada.</p></div></div>';
        return;
    }
    // CSV export
    if (!empty($_GET['rsdl_export']) && intval($_GET['rsdl_export']) === 1) {
        check_admin_referer('rsdl_export_' . intval($meeting_id));
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="rsdl-meeting-' . intval($meeting_id) . '-votes.csv"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM

        $out = fopen('php://output', 'w');
        fputcsv($out, array('voter_email','vote_value','start','end','timezone'));

        $export = $wpdb->get_results($wpdb->prepare("
            SELECT v.voter_email, v.vote_value, s.start_dt, s.end_dt
            FROM $votes v
            INNER JOIN $slots s ON s.id = v.slot_id
            WHERE v.meeting_id = %d
            ORDER BY v.voter_email ASC, s.start_dt ASC
        ", $meeting_id));

        foreach ($export as $row) {
            $start = rsdl_format_dt_local($row->start_dt, $timezone);
            $end = rsdl_format_dt_local($row->end_dt, $timezone, 'H:i');
            fputcsv($out, array($row->voter_email, $row->vote_value, $start, $end, $timezone));
        }
        fclose($out);
        exit;
    }


    $timezone = $meeting->timezone ?: 'Europe/Madrid';

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT s.id as slot_id, s.start_dt, s.end_dt, SUM(CASE WHEN v.vote_value='yes' THEN 1 ELSE 0 END) as yes_votes,
               SUM(CASE WHEN v.vote_value='maybe' THEN 1 ELSE 0 END) as maybe_votes
        FROM $slots s
        LEFT JOIN $votes v ON v.slot_id = s.id
        WHERE s.meeting_id = %d
        GROUP BY s.id
        ORDER BY s.start_dt ASC
    ", $meeting_id));

    $total_votes = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $votes WHERE meeting_id = %d", $meeting_id)));
    $unique_voters = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT voter_email) FROM $votes WHERE meeting_id = %d", $meeting_id)));

    $maxScore = 0;
    foreach ($rows as $r) { $maxScore = max($maxScore, (intval($r->yes_votes)*2)+intval($r->maybe_votes)); }
    if ($maxScore <= 0) { $maxScore = 1; }

    echo '<h2 style="margin-top:6px;">' . esc_html($meeting->title) . '</h2>';
    echo '<p><strong>Estado:</strong> ' . esc_html($meeting->status) . ' · <strong>Votos:</strong> ' . esc_html($total_votes) . ' · <strong>Votantes únicos:</strong> ' . esc_html($unique_voters) . ' · <strong>Zona horaria:</strong> ' . esc_html($timezone) . '</p>';

    echo '<h3>Gráfico de votos por franja</h3>';
    echo '<div style="max-width:1000px;">';
    echo '<div style="border:1px solid #e5e5e5;border-radius:12px;background:#fff;padding:12px;">';

    foreach ($rows as $r) {
        $label = rsdl_format_dt_local($r->start_dt, $timezone) . ' – ' . rsdl_format_dt_local($r->end_dt, $timezone, 'H:i');
        $yes_n = intval($r->yes_votes);
        $maybe_n = intval($r->maybe_votes);
        $score = ($yes_n*2) + $maybe_n;
        $pct = round(($score / $maxScore) * 100);
        echo '<div style="margin:10px 0;">';
        echo '<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">';
        echo '<div style="min-width:360px;max-width:100%;font-weight:600;">' . esc_html($label) . '</div>';
        echo '<div style="flex:1;min-width:220px;">';
        echo '<div style="height:18px;border-radius:999px;background:#f3f3f3;overflow:hidden;border:1px solid #e7e7e7;">';
        echo '<div style="height:18px;width:' . intval($pct) . '%;background:#2c7be5;"></div>';
        echo '</div></div>';
        echo '<div style="min-width:60px;text-align:right;font-weight:700;">' . esc_html($votes_n) . '</div>';
        echo '</div>';
        echo '</div>';
    }

    echo '</div></div>';

    echo '<h3 style="margin-top:20px;">Tabla</h3>';
    echo '<table class="widefat fixed striped"><thead><tr><th>Franja</th><th>Sí</th><th>Quizá</th><th>Puntuación</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $label = rsdl_format_dt_local($r->start_dt, $timezone) . ' – ' . rsdl_format_dt_local($r->end_dt, $timezone, 'H:i');
        echo '<tr><td>' . esc_html($label) . '</td><td>' . intval($r->yes_votes) . '</td><td>' . intval($r->maybe_votes) . '</td><td>' . ((intval($r->yes_votes)*2)+intval($r->maybe_votes)) . '</td></tr>';
    }
    echo '</tbody></table>';

    // Voters and selections
    echo '<h3 style="margin-top:22px;">Voters and selections</h3>';
    $voters = $wpdb->get_results($wpdb->prepare("
        SELECT DISTINCT voter_email
        FROM $votes
        WHERE meeting_id = %d
        ORDER BY voter_email ASC
    ", $meeting_id));

    if (empty($voters)) {
        echo '<p>No voters yet.</p>';
    } else {
        echo '<div style="max-width:1100px;background:#fff;border:1px solid #e5e5e5;border-radius:12px;padding:12px;">';
        foreach ($voters as $v) {
            $email = sanitize_email($v->voter_email);
            $sel = $wpdb->get_results($wpdb->prepare("
                SELECT s.start_dt, s.end_dt, v.vote_value
                FROM $votes v
                INNER JOIN $slots s ON s.id = v.slot_id
                WHERE v.meeting_id = %d AND v.voter_email = %s
                ORDER BY s.start_dt ASC
            ", $meeting_id, $email));

            $yes_n = 0; $maybe_n = 0;
            foreach ($sel as $row) { if ($row->vote_value==='yes') $yes_n++; elseif ($row->vote_value==='maybe') $maybe_n++; }

            echo '<details style="margin:10px 0;border-top:1px solid #f0f0f0;padding-top:10px;">';
            echo '<summary style="cursor:pointer;font-weight:700;">' . esc_html($email) . ' <span style="font-weight:600;color:#555;">(' . intval($yes_n) . ' yes, ' . intval($maybe_n) . ' maybe)</span></summary>';
            if (empty($sel)) {
                echo '<div style="padding:8px 0;color:#666;">No selections.</div>';
            } else {
                echo '<ul style="margin:10px 0 0 18px;line-height:1.5;">';
                foreach ($sel as $row) {
                    $label = rsdl_format_dt_local($row->start_dt, $timezone) . ' – ' . rsdl_format_dt_local($row->end_dt, $timezone, 'H:i');
                    $vv = ($row->vote_value === 'yes') ? 'Yes' : (($row->vote_value === 'maybe') ? 'Maybe' : $row->vote_value);
                    echo '<li><strong>' . esc_html($vv) . ':</strong> ' . esc_html($label) . '</li>';
                }
                echo '</ul>';
            }
            echo '</details>';
        }
        echo '</div>';
    }

    // Heatmap (score per slot)
    echo '<h3 style="margin-top:22px;">Heatmap</h3>';
    echo '<p style="max-width:980px;color:#555;">Darker cells indicate higher availability (Yes=2 points, Maybe=1 point).</p>';

    $tzObj = new DateTimeZone($timezone);
    $dates = array();
    $times = array();
    $score = array();

    foreach ($rows as $r) {
        try {
            $s = new DateTime($r->start_dt, new DateTimeZone('UTC'));
            $s->setTimezone($tzObj);
            $date_key = $s->format('Y-m-d');
            $time_key = $s->format('H:i');
            if (!in_array($date_key, $dates, true)) { $dates[] = $date_key; }
            if (!in_array($time_key, $times, true)) { $times[] = $time_key; }
            if (!isset($score[$date_key])) { $score[$date_key] = array(); }
            $score[$date_key][$time_key] = (intval($r->yes_votes)*2) + intval($r->maybe_votes);
        } catch (Exception $e) {}
    }
    sort($dates); sort($times);

    echo '<div style="overflow:auto;border:1px solid #e5e5e5;border-radius:12px;background:#fff;max-width:1100px;">';
    echo '<table class="widefat fixed" style="border-collapse:collapse;margin:0;">';
    echo '<thead><tr><th style="position:sticky;left:0;background:#fafafa;z-index:2;">Time</th>';
    foreach ($dates as $d) {
        echo '<th style="white-space:nowrap;background:#fafafa;">' . esc_html(date_i18n('D d/m', strtotime($d))) . '</th>';
    }
    echo '</tr></thead><tbody>';

    $maxScore = 0;
    foreach ($dates as $d) { foreach ($times as $t) { $maxScore = max($maxScore, intval($score[$d][$t] ?? 0)); } }
    if ($maxScore <= 0) { $maxScore = 1; }

    foreach ($times as $t) {
        echo '<tr><th style="position:sticky;left:0;background:#fff;z-index:1;white-space:nowrap;">' . esc_html($t) . '</th>';
        foreach ($dates as $d) {
            $val = intval($score[$d][$t] ?? 0);
            $alpha = $val / $maxScore;
            $bg = 'rgba(44,123,229,' . max(0.05, min(0.85, $alpha)) . ')';
            $fg = ($alpha > 0.45) ? '#fff' : '#111';
            echo '<td style="text-align:center;padding:10px;border:1px solid #f0f0f0;background:' . esc_attr($val>0 ? $bg : '#fcfcfc') . ';color:' . esc_attr($val>0 ? $fg : '#999') . ';">' . ($val>0 ? esc_html($val) : '—') . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></div>';

    $export_url = wp_nonce_url(admin_url('admin.php?page=rsdl_stats&meeting_id=' . intval($meeting_id) . '&rsdl_export=1'), 'rsdl_export_' . intval($meeting_id));
    echo '<p style="margin-top:14px;"><a class="button" href="' . esc_url($export_url) . '">Export CSV</a></p>';


    echo '</div>';
}


function rsdl_admin_help_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized.');
    }

    echo '<div class="wrap"><h1>Research Scheduler – Help</h1>';

    echo '<h2>Quick setup</h2>';
    echo '<ol style="max-width:980px;line-height:1.6;">';
    echo '<li>Install and activate the plugin.</li>';
    echo '<li>Create a page called <strong>Create Poll</strong> and add the shortcode: <code>[rs_create_meeting]</code></li>';
    echo '<li>(Optional) Create a page called <strong>Vote</strong> and add: <code>[rs_meeting_poll]</code></li>';
    echo '<li>Go to <strong>Research Scheduler → Settings</strong> and pick the Vote page if you created it.</li>';
    echo '<li>Create a poll and share the generated link.</li>';
    echo '</ol>';

    echo '<h2>Voting</h2>';
    echo '<p style="max-width:980px;">Participants click the link, enter their email, and select time slots. Click toggles <strong>Yes → Maybe → Empty</strong>. Drag selects <strong>Yes</strong>.</p>';

    echo '<h2>Notifications</h2>';
    echo '<p style="max-width:980px;">If enabled in Settings, the poll creator receives an email every time someone votes (rate-limited).</p>';

    echo '<h2>Troubleshooting</h2>';
    echo '<ul style="max-width:980px;line-height:1.6;">';
    echo '<li>If links like <code>/rs-meeting/TOKEN/</code> return 404, go to <strong>Settings → Permalinks</strong> and click <em>Save Changes</em>.</li>';
    echo '<li>If emails are not delivered, check your server mail configuration or install an SMTP plugin.</li>';
    echo '</ul>';

    echo '</div>';
}
