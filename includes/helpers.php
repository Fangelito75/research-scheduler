<?php
if (!defined('ABSPATH')) { exit; }

function rsdl_now_mysql() {
    return current_time('mysql'); // uses WP timezone settings
}

function rsdl_generate_token($length = 32) {
    // 64 hex chars if length=32 bytes
    try {
        return bin2hex(random_bytes($length));
    } catch (Exception $e) {
        return wp_generate_password(64, false, false);
    }
}

function rsdl_table($name) {
    global $wpdb;
    return $wpdb->prefix . $name;
}

function rsdl_get_meeting_by_token($token) {
    global $wpdb;
    $meetings = rsdl_table('rsdl_meetings');
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $meetings WHERE token = %s", $token));
}

function rsdl_is_deadline_passed($meeting) {
    if (empty($meeting->deadline)) return false;
    $deadline_ts = strtotime($meeting->deadline . ' UTC'); // stored as WP mysql time; approximate
    // Use WP current_time('timestamp') which is WP timezone based; compare in unix seconds (best effort).
    $now = current_time('timestamp');
    $deadline_local = strtotime($meeting->deadline); // interpret in server tz; best effort
    return ($deadline_local !== false) ? ($now >= $deadline_local) : false;
}

function rsdl_close_meeting($meeting_id) {
    global $wpdb;
    $meetings = rsdl_table('rsdl_meetings');
    $slots    = rsdl_table('rsdl_slots');
    $votes    = rsdl_table('rsdl_votes');

    // Determine winner: prioritize YES, then MAYBE, then earliest
    $counts = $wpdb->get_results($wpdb->prepare("
        SELECT s.id as slot_id,
               SUM(CASE WHEN v.vote_value = 'yes' THEN 1 ELSE 0 END) as yes_votes,
               SUM(CASE WHEN v.vote_value = 'maybe' THEN 1 ELSE 0 END) as maybe_votes,
               s.start_dt
        FROM $slots s
        LEFT JOIN $votes v ON v.slot_id = s.id
        WHERE s.meeting_id = %d
        GROUP BY s.id
        ORDER BY yes_votes DESC, maybe_votes DESC, s.start_dt ASC
    ", $meeting_id));

    $winning_slot_id = null;
    if (!empty($counts)) {
        $winning_slot_id = intval($counts[0]->slot_id);
    }

    $wpdb->update(
        $meetings,
        array(
            'status' => 'closed',
            'winning_slot_id' => $winning_slot_id,
            'updated_at' => rsdl_now_mysql(),
        ),
        array('id' => $meeting_id),
        array('%s','%d','%s'),
        array('%d')
    );

    return $winning_slot_id;
}



function rsdl_open_meeting($meeting_id) {
    global $wpdb;
    $meetings = rsdl_table('rsdl_meetings');
    $wpdb->update(
        $meetings,
        array('status' => 'open', 'winning_slot_id' => null, 'updated_at' => rsdl_now_mysql()),
        array('id' => $meeting_id),
        array('%s','%d','%s'),
        array('%d')
    );
}

function rsdl_format_dt_local($mysql_dt, $timezone = 'Europe/Madrid', $format = 'D, d M Y H:i') {
    try {
        $dt = new DateTime($mysql_dt, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($timezone));
        return $dt->format($format);
    } catch (Exception $e) {
        return $mysql_dt;
    }
}

function rsdl_meeting_link($token) {
    // Prefer pretty permalink endpoint /rs-meeting/{token}/ if permalinks enabled
    if (get_option('permalink_structure')) {
        return home_url('/rs-meeting/' . rawurlencode($token) . '/');
    }
    $page = get_option('rsdl_poll_page_id');
    if ($page) {
        return add_query_arg('rs_token', $token, get_permalink(intval($page)));
    }
    return add_query_arg('rs_token', $token, home_url('/'));
}



function rsdl_int_gcd($a, $b){
    $a = abs(intval($a)); $b = abs(intval($b));
    if ($a===0) return $b; if ($b===0) return $a;
    while ($b !== 0) { $t = $b; $b = $a % $b; $a = $t; }
    return $a;
}


/**
 * Notify meeting creator by email when someone submits votes.
 * Safe: errors are swallowed to avoid breaking the site.
 */
function rsdl_notify_creator_vote($meeting, $voter_email, $slot_states = array()) {
    try {
        $enabled = intval(get_option('rsdl_notify_creator', 1));
        if ($enabled !== 1) { return; }

        if (empty($meeting) || empty($meeting->id)) { return; }

        $creator_email = '';
        if (!empty($meeting->creator_user_id)) {
            $user = get_user_by('id', intval($meeting->creator_user_id));
            if ($user && !empty($user->user_email)) {
                $creator_email = $user->user_email;
            }
        }
        if (empty($creator_email) && !empty($meeting->creator_email)) {
            $creator_email = $meeting->creator_email;
        }
        $creator_email = sanitize_email($creator_email);
        if (empty($creator_email) || !is_email($creator_email)) { return; }

        $voter_email = sanitize_email($voter_email);
        if (empty($voter_email) || !is_email($voter_email)) { return; }

        // Rate limit: one email per voter per meeting per 5 minutes
        $key = 'rsdl_last_notify_' . intval($meeting->id) . '_' . md5(strtolower($voter_email));
        $last = intval(get_transient($key));
        $now = time();
        if ($last > 0 && ($now - $last) < 300) {
            return;
        }
        set_transient($key, $now, 300);

        $yes = 0; $maybe = 0;
        $selected_slot_ids = array();
        if (is_array($slot_states)) {
            foreach ($slot_states as $slot_id => $vv) {
                $vv = sanitize_text_field($vv);
                if ($vv === 'yes' || $vv === 'maybe') {
                    if ($vv === 'yes') { $yes++; } else { $maybe++; }
                    $selected_slot_ids[] = intval($slot_id);
                }
            }
        }

        $subject = sprintf('[Research Scheduler] Nuevo voto: %s', wp_strip_all_tags($meeting->title));

        $lines = array();
        $lines[] = 'Nuevo voto recibido.';
        $lines[] = '';
        $lines[] = 'Encuesta: ' . wp_strip_all_tags($meeting->title);
        $lines[] = 'Votante: ' . $voter_email;
        $lines[] = 'Resumen: ' . $yes . ' sí, ' . $maybe . ' quizá';
        $lines[] = '';

        if (!empty($selected_slot_ids)) {
            global $wpdb;
            $slots = rsdl_table('rsdl_slots');
            $placeholders = implode(',', array_fill(0, count($selected_slot_ids), '%d'));
            $query = "SELECT start_dt, end_dt FROM $slots WHERE id IN ($placeholders) ORDER BY start_dt ASC";
            $prepared = $wpdb->prepare($query, $selected_slot_ids);
            $rows = $wpdb->get_results($prepared);

            $tz = !empty($meeting->timezone) ? $meeting->timezone : 'Europe/Madrid';
            $lines[] = 'Franjas seleccionadas (' . $tz . '):';
            foreach ($rows as $r) {
                $lines[] = '- ' . rsdl_format_dt_local($r->start_dt, $tz) . ' – ' . rsdl_format_dt_local($r->end_dt, $tz, 'H:i');
            }
            $lines[] = '';
        }

        $lines[] = 'Abrir encuesta: ' . rsdl_meeting_link($meeting->token);
        $lines[] = 'Estadísticas: ' . admin_url('admin.php?page=rsdl_stats&meeting_id=' . intval($meeting->id));

        $message = implode("\n", $lines);
        $headers = array('Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . $voter_email);

        @wp_mail($creator_email, $subject, $message, $headers);
    } catch (Throwable $e) {
        return;
    }
}
